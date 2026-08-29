<?php
/**
 * includes/dolar.php
 *
 * Tipo de cambio mayorista: única puerta para leerlo y guardarlo.
 *
 * Por qué existe este archivo
 * ───────────────────────────
 * El dólar no es un dato del tambo, es un dato de la empresa: con él se
 * convierten a USD los alquileres pagados en pesos, y de ahí sale el margen neto
 * que muestra el panel de Agricultura. Pero hasta ahora sólo se cargaba desde
 * tambo.php, que consultaba la API en cada carga de página y guardaba el valor
 * de paso. Un productor con sólo Agricultura habilitada nunca podía llegar ahí:
 * require_tambo() lo rebota. Su tabla quedaba vacía, y el cálculo caía en un
 * valor fijo de 1000 escrito en el código. Con el mayorista cerca de 1500, eso
 * infla el costo de alquiler un 50% y se come el margen, en silencio.
 *
 * La tabla sigue llamándose tambo_dolar_mes por compatibilidad con los datos que
 * ya están cargados. El nombre quedó mal desde el principio, pero renombrarla
 * hoy obliga a una migración con tiempo de baja para arreglar una etiqueta.
 *
 * Un valor por usuario y por mes, a propósito: el valor de la API es el mismo
 * para todos, pero el productor tiene que poder escribir el tipo de cambio al
 * que él realmente liquidó, que casi nunca es el de pizarra. Por eso 'manual'
 * le gana siempre a 'api' y el cron nunca lo pisa.
 */

/** Último recurso cuando el usuario no tiene ninguna cotización cargada. */
const DOLAR_ULTIMO_RECURSO = 1000.0;

/**
 * Crea la tabla si falta.
 *
 * Existe sólo para instalaciones viejas que nunca corrieron la migración
 * migrations/create_dolar_mes.sql. No debería llamarse desde una pantalla: hacer
 * DDL en el camino de lectura obliga a que el usuario de la base tenga permiso
 * de CREATE en producción, que es más de lo que una app necesita.
 */
function dolar_asegurar_tabla(PDO $pdo): void
{
    static $lista = false;
    if ($lista) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS `tambo_dolar_mes` (
        `id`              INT(11)       NOT NULL AUTO_INCREMENT,
        `usuario_id`      INT(11)       NOT NULL,
        `mes`             VARCHAR(7)    NOT NULL,
        `dolar_mayorista` DECIMAL(12,4) NOT NULL,
        `fuente`          ENUM('api','manual') NOT NULL DEFAULT 'api',
        `creado_en`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `actualizado_en`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_usuario_mes` (`usuario_id`, `mes`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $lista = true;
}

/**
 * Cotización de un mes puntual.
 *
 * @return array{valor:float, fuente:string}|null  null si ese mes no tiene dato.
 */
function dolar_del_mes(PDO $pdo, int $usuario_id, string $mes): ?array
{
    $stmt = $pdo->prepare("SELECT dolar_mayorista, fuente FROM tambo_dolar_mes WHERE usuario_id = ? AND mes = ?");
    $stmt->execute([$usuario_id, $mes]);
    $fila = $stmt->fetch();
    if (!$fila) return null;

    return ['valor' => (float)$fila['dolar_mayorista'], 'fuente' => (string)$fila['fuente']];
}

/**
 * Tipo de cambio de referencia del usuario y, sobre todo, qué tan confiable es.
 *
 * El campo `estimado` es el importante: dice que NO hay ninguna cotización
 * cargada y que el número que se devuelve es el valor fijo del código. Quien
 * llame tiene que mostrarlo como lo que es —una suposición— en vez de pintarlo
 * igual que un dato real. Ese fue exactamente el problema que originó todo esto.
 *
 * @return array{valor:float, fuente:string, estimado:bool, mes:?string}
 */
function dolar_referencia(PDO $pdo, int $usuario_id): array
{
    $stmt = $pdo->prepare("
        SELECT mes, dolar_mayorista, fuente
        FROM tambo_dolar_mes
        WHERE usuario_id = ?
        ORDER BY mes DESC
        LIMIT 1
    ");
    $stmt->execute([$usuario_id]);
    $fila = $stmt->fetch();

    if ($fila && (float)$fila['dolar_mayorista'] > 0) {
        return [
            'valor'    => (float)$fila['dolar_mayorista'],
            'fuente'   => (string)$fila['fuente'],
            'estimado' => false,
            'mes'      => (string)$fila['mes'],
        ];
    }

    return [
        'valor'    => DOLAR_ULTIMO_RECURSO,
        'fuente'   => 'ninguna',
        'estimado' => true,
        'mes'      => null,
    ];
}

/**
 * Guarda la cotización de un mes.
 *
 * Un valor cargado a mano nunca lo pisa uno de la API: si el productor se tomó
 * el trabajo de escribir el tipo de cambio al que liquidó, ese es el que vale.
 * Antes tambo.php lo sobrescribía en la siguiente carga de página.
 *
 * @return bool true si se escribió, false si se respetó un valor manual previo.
 */
function dolar_guardar(PDO $pdo, int $usuario_id, string $mes, float $valor, string $fuente = 'manual'): bool
{
    if ($valor <= 0) return false;
    $fuente = ($fuente === 'api') ? 'api' : 'manual';

    if ($fuente === 'api') {
        $existente = dolar_del_mes($pdo, $usuario_id, $mes);
        if ($existente && $existente['fuente'] === 'manual') {
            return false;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO tambo_dolar_mes (usuario_id, mes, dolar_mayorista, fuente)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE dolar_mayorista = VALUES(dolar_mayorista), fuente = VALUES(fuente)
    ");
    $stmt->execute([$usuario_id, $mes, $valor, $fuente]);
    return true;
}

/**
 * El JOIN que trae la cotización del mes de cada fila.
 *
 * Se convierte con el dólar DEL MES DEL MOVIMIENTO y no con uno solo para todo:
 * un alquiler pagado en marzo a 1.200 no vale lo mismo que uno de agosto a 1.500,
 * y aplanarlos con una sola cotización borra justo lo que el productor quiere ver
 * cuando mira en dólares. Cada fila se convierte con el tipo de cambio que tenía
 * encima el día que pasó.
 *
 * @param string $tabla  alias de la tabla del movimiento (o, u, a, pv…)
 * @param string $fecha  columna de fecha de esa tabla
 * @param string $alias  alias para esta cotización; distinto por cada JOIN
 */
function dolar_sql_join(string $tabla, string $fecha, string $alias = 'dm'): string
{
    return " LEFT JOIN tambo_dolar_mes $alias"
         . " ON $alias.usuario_id = $tabla.usuario_id"
         . " AND $alias.mes = DATE_FORMAT($tabla.$fecha, '%Y-%m')";
}

/**
 * El FACTOR por el que hay que multiplicar cualquier importe de esa fila para
 * llevarlo a la moneda pedida.
 *
 * Se devuelve el factor y no la conversión entera porque una misma consulta suele
 * tener varios importes —el costo total, la parte de labor, la de insumos— y todos
 * se convierten igual. Multiplicar cada uno por el mismo factor es menos frágil
 * que repetir el CASE en cada término.
 *
 * El tipo de cambio de respaldo se escribe COMO NÚMERO en la consulta, no como
 * parámetro: sale de getDolarInfo(), que lo lee de la base o usa la constante del
 * código, y pasa por (float) antes de entrar. No hay texto de usuario en el
 * camino. Va inline porque estas expresiones se repiten muchas veces en una misma
 * consulta y llevar la cuenta de los `?` en el orden correcto era la parte donde
 * de verdad se rompía.
 *
 * @param string $moneda   columna con la moneda de la fila
 * @param string $destino  'ARS' o 'USD'
 * @param float  $respaldo cotización a usar si ese mes no tiene la suya
 * @param string $alias    alias del JOIN de dolar_sql_join()
 */
function dolar_sql_factor(string $moneda, string $destino, float $respaldo, string $alias = 'dm'): string
{
    $r = sprintf('%.6F', $respaldo > 0 ? $respaldo : DOLAR_ULTIMO_RECURSO);
    if ($destino === 'USD') {
        return "(CASE WHEN $moneda = 'USD' THEN 1"
             . " ELSE 1 / COALESCE($alias.dolar_mayorista, $r) END)";
    }
    return "(CASE WHEN $moneda = 'ARS' THEN 1"
         . " ELSE COALESCE($alias.dolar_mayorista, $r) END)";
}

/**
 * La expresión que pasa UN importe a la moneda pedida.
 *
 * Azúcar sobre dolar_sql_factor() para el caso de un solo monto.
 */
function dolar_sql_convertir(string $monto, string $moneda, string $destino, float $respaldo, string $alias = 'dm'): string
{
    return '(' . $monto . ' * ' . dolar_sql_factor($moneda, $destino, $respaldo, $alias) . ')';
}

/** Cuenta las filas que hubo que convertir sin la cotización de su propio mes. */
function dolar_sql_sin_cotizacion(string $moneda, string $destino, string $alias = 'dm'): string
{
    return "(CASE WHEN $moneda <> '$destino' AND $alias.dolar_mayorista IS NULL THEN 1 ELSE 0 END)";
}

/**
 * Interpreta un tipo de cambio escrito a mano.
 *
 * Se escribe en un campo de texto y no en un <input type="number"> por lo mismo
 * que en el importador: con lang="es" el navegador espera coma decimal y, si no
 * le gusta lo tipeado, devuelve una cadena vacía y el número se pierde sin
 * aviso. Acá la coma se interpreta y punto.
 */
function dolar_parsear(string $texto): ?float
{
    $s = preg_replace('/[^\d.,\-]/', '', trim($texto));
    if ($s === '' || $s === '-') return null;

    $coma  = strrpos($s, ',');
    $punto = strrpos($s, '.');

    if ($coma !== false && $punto !== false) {
        $s = ($coma > $punto) ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
    } elseif ($coma !== false) {
        $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
        // 1.500 es mil quinientos, no uno con medio.
        $s = str_replace('.', '', $s);
    }

    if (!is_numeric($s)) return null;
    $n = (float)$s;

    // Un mayorista fuera de este rango es un error de tipeo, no una cotización.
    return ($n > 0 && $n < 1000000) ? $n : null;
}
