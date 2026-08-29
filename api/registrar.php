<?php
/**
 * api/registrar.php
 *
 * Guarda un gasto propuesto por el chat. Es la ÚNICA parte del motor que escribe.
 *
 * Va por POST a propósito, y no por comodidad:
 *
 *   - El bloqueo de cuentas de demostración de config/auth.php es una lista
 *     blanca sobre POST. Al entrar por acá, la demo queda bloqueada sola, sin
 *     que este archivo tenga que acordarse de chequearlo.
 *   - Hereda la validación de token CSRF por el mismo camino que el resto.
 *
 * Recibe CAMPOS YA CONFIRMADOS, nunca la frase original. El motor interpretó,
 * el productor aprobó lo que vio en pantalla, y acá se guarda exactamente eso.
 * Si se volviera a interpretar el texto, podría entenderse distinto de lo que se
 * aprobó — que es justo el riesgo que este diseño evita.
 *
 * Igual no se confía en lo que llega: cada valor se valida contra los enum de la
 * base y el lote se verifica que sea del usuario de la sesión.
 */
require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']);
    exit;
}

// Mismo token que el resto de los formularios.
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Se venció la sesión del formulario. Recargá la página y probá de nuevo.']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

/* Los lotes vienen como una lista de ids separados por coma: un mismo gasto puede
   ir a varios ("fertilicé todo el campo") y se guarda en una sola confirmación.
   En la base sigue siendo una fila por lote —operaciones.lote_id es uno solo, y
   el formulario hace lo mismo—, pero el productor lo carga una vez. */
$lotes_in = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string)($_POST['lotes'] ?? ''))),
    fn($id) => $id > 0
)));
$grupo    = (string)($_POST['grupo_gasto'] ?? '');
$tipo     = (string)($_POST['tipo_componente'] ?? '');
$insumo   = trim((string)($_POST['insumo_nombre'] ?? ''));
$insumo_id  = (int)($_POST['insumo_id'] ?? 0);
$cantidad   = (float)($_POST['insumo_cantidad'] ?? 0);
$monto    = (float)($_POST['costo_total'] ?? 0);
$fecha    = (string)($_POST['fecha'] ?? '');
$campania = (string)($_POST['campania'] ?? '');
/* La moneda en que se pagó. Se guarda tal cual y la conversión se hace al
   calcular, con la cotización del mes: es lo mismo que hace el formulario. */
$moneda   = (($_POST['moneda'] ?? 'ARS') === 'USD') ? 'USD' : 'ARS';

/* El enum de la base acepta ocho tipos, pero la app sólo entiende tres: el
   formulario de operaciones ofrece labor, insumo y receta_labor, y el desglose de
   costos del panel suma únicamente labor/receta_labor como Labores e
   insumo/multi_insumo como Insumos. Los otros cuatro (semilla, fertilizante,
   agroquimico, maquinaria) quedaron de una versión anterior: una fila con esos
   valores se guarda bien pero no la cuenta nadie, así que la plata desaparece del
   desglose aunque siga en el total. Por eso acá el vocabulario es el de la app y
   no el del enum.

   De los tres que la app entiende, el chat carga dos: 'labor' y 'insumo'. Queda
   afuera 'receta_labor', que separa la mano de obra de los insumos y necesita la
   cantidad y el precio de cada uno — eso es el formulario, no una charla.

   'insumo' se guarda como lo guarda el formulario: la operación queda marcada
   como multi_insumo (operaciones.php hace esa misma traducción en su línea 31) y
   el detalle va en un renglón de operacion_insumos. Sin ese renglón la fila
   aparece con la columna Detalle en blanco. */
$GRUPOS = ['siembra','cosecha','pulverizacion','fertilizacion','otros'];
$TIPOS  = ['labor','insumo'];

$errores = [];
if (!in_array($grupo, $GRUPOS, true))            $errores[] = 'la etapa no es válida';
if (!in_array($tipo, $TIPOS, true))              $errores[] = 'el tipo no es válido';
if ($tipo === 'insumo' && $insumo === '')        $errores[] = 'falta el nombre del insumo';
if (mb_strlen($insumo) > 150)                    $errores[] = 'el nombre del insumo es demasiado largo';
/* La cantidad es obligatoria para un insumo, venga o no del catálogo. De ella
   salen el precio unitario y la cantidad por hectárea, que son las dos cifras con
   las que el resto de la app calcula. Sin cantidad habría que inventarla. */
if ($tipo === 'insumo' && $cantidad <= 0)        $errores[] = 'falta la cantidad del insumo';

/* El insumo del catálogo tiene que ser del usuario de la sesión. Si no lo es, no
   se guarda a nombre de otro ni se rechaza en silencio: se corta, porque este id
   decide de qué stock se descuenta. */
$insumoCat = null;
if ($insumo_id > 0) {
    $st = $pdo->prepare("SELECT id, nombre FROM insumos WHERE id = ? AND usuario_id = ? AND estado = 'activo'");
    $st->execute([$insumo_id, $usuario_id]);
    $insumoCat = $st->fetch() ?: null;
    if (!$insumoCat) $errores[] = 'ese insumo no existe o no es tuyo';
}
if ($monto <= 0)                                 $errores[] = 'el monto tiene que ser mayor a cero';
/* El formato solo no alcanza: "2026-02-31" lo cumple y no es una fecha. Según cómo
   esté configurado MySQL, una así se guarda como 0000-00-00 o hace fallar el
   INSERT; las dos cosas son peores que rechazarla acá. Y una fecha futura tampoco
   corresponde: un gasto es plata que ya salió. */
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $mf) || !checkdate((int)$mf[2], (int)$mf[3], (int)$mf[1])) {
    $errores[] = 'la fecha no es válida';
} elseif ($fecha > date('Y-m-d')) {
    $errores[] = 'la fecha es posterior a hoy';
}

/* Cada lote tiene que existir Y ser del usuario de la sesión: sin esto, alguien
   podría cargarle un gasto al campo de otro cambiando un número. Se pide la lista
   entera en una consulta y después se compara la cantidad: si volvieron menos de
   los que mandó, alguno no era suyo y no se guarda nada. Con varios lotes esto
   importa más que con uno, porque un id colado entre cinco pasa desapercibido. */
$destinos = [];
if (!$lotes_in) {
    $errores[] = 'falta el lote';
} else {
    $ph = implode(',', array_fill(0, count($lotes_in), '?'));
    $st = $pdo->prepare("SELECT id, nombre, superficie FROM lotes
                          WHERE id IN ($ph) AND usuario_id = ?");
    $st->execute(array_merge($lotes_in, [$usuario_id]));
    foreach ($st->fetchAll() as $row) {
        $destinos[] = ['id' => (int)$row['id'], 'nombre' => $row['nombre'],
                       'sup' => (float)$row['superficie']];
    }
    if (count($destinos) !== count($lotes_in)) {
        $errores[] = count($lotes_in) === 1 ? 'el lote no existe o no es tuyo'
                                            : 'alguno de los lotes no existe o no es tuyo';
    }
}

if ($errores) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'msg' => 'No pude guardarlo: ' . implode(', ', $errores) . '.'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

/* Un monto dicho al pasar es un gasto total. El formulario tiene ese mismo modo
   —"Total del Lote (se dividirá automáticamente por las hectáreas)"— así que acá
   se hace la misma cuenta: no se inventa una cantidad, se reparte el monto sobre
   la superficie. Con eso la lista de operaciones muestra la fila completa en vez
   de "0,0 ha × $0,00", que se lee como un gasto de cero.

   Con varios lotes el reparto se calcula ACÁ, con las superficies leídas de la
   base, y no se toma el que vino del cliente: es plata, y el navegador puede
   mandar cualquier cosa. El chat muestra el mismo reparto porque aplica la misma
   regla sobre los mismos datos, no porque el servidor le crea. */
$etiquetas = ['siembra' => 'Siembra', 'cosecha' => 'Cosecha', 'pulverizacion' => 'Pulverización',
              'fertilizacion' => 'Fertilización', 'otros' => 'Otros gastos'];
$detalle   = $etiquetas[$grupo] ?? ucfirst($grupo);

$supTotal = array_sum(array_column($destinos, 'sup'));
$n = count($destinos);

/* El resto de la división va al último. Repartir $100.000 entre tres lotes
   iguales da $33.333,33 y suma $99.999,99: el productor cargó cien mil y el panel
   mostraría un centavo menos. En una cuenta de plata eso es un error, no un
   redondeo. Sin superficies cargadas se parte en partes iguales, que es lo único
   que no inventa un criterio. */
$acum = 0.0;
foreach ($destinos as $i => &$d) {
    $d['monto'] = $i === $n - 1
        ? round($monto - $acum, 2)
        : round($supTotal > 0 ? $monto * ($d['sup'] / $supTotal) : $monto / $n, 2);
    $acum += $d['monto'];
    $d['sup_calc']  = $d['sup'] > 0 ? $d['sup'] : 1.0;
    $d['precio_ha'] = $d['monto'] / $d['sup_calc'];
}
unset($d);

/* ─── Las tres cifras del renglón de insumo ───────────────────────────────────
 * El formulario guarda cantidad_ha y precio_unitario, y de ahí sale todo lo demás:
 *
 *     costo del renglón = cantidad_ha × precio_unitario × hectáreas
 *     stock descontado  = cantidad_ha × hectáreas
 *
 * Y al editar o borrar la operación devuelve exactamente ese mismo producto. Por
 * eso la cantidad no se puede inventar: si cantidad_ha × hectáreas no es lo que de
 * verdad se usó, borrar la operación devuelve al stock una cantidad que nunca se
 * sacó, y el número queda mal sin que nada lo avise.
 *
 * Acá el productor da el costo total y la cantidad total, así que las dos salen
 * exactas y en la misma unidad que usa el formulario:
 *
 *     cantidad_ha     = cantidad_total ÷ superficie_total
 *     precio_unitario = costo_total    ÷ cantidad_total
 *
 * Con varios lotes, cantidad_ha y precio_unitario son iguales en todos —lo que
 * cambia es la superficie de cada uno—, y por eso la cuenta de cada lote da su
 * parte del costo y del stock sin necesidad de repartir nada aparte.
 */
$cant_ha_ins = 0.0;
$precio_ins  = 0.0;
if ($tipo === 'insumo') {
    /* Redondeado a 4 decimales ANTES de usarlo, que es la precisión de la columna.
       El descuento de stock y la devolución tienen que salir del MISMO número: si
       se descontara con el valor exacto y la app devolviera con el guardado, cada
       edición dejaría una diferencia. Con 2.000 kg sobre 238 ha son 9 miligramos
       por vuelta —invisible una vez, y un stock que se va corriendo solo después
       de un año—. Es también lo que hace el formulario: guarda cantidad_ha y
       calcula las dos cosas a partir de ahí. */
    $cant_ha_ins = round($supTotal > 0 ? $cantidad / $supTotal : $cantidad, 4);
    $precio_ins  = $cantidad > 0 ? $monto / $cantidad : 0.0;
}

// El formulario traduce "Insumo" a multi_insumo antes de guardar; se hace igual.
$tipo_db = $tipo === 'insumo' ? 'multi_insumo' : 'labor';

try {
    /* Transacción: son N filas (más sus renglones) que representan UNA carga. Si
       falla la tercera de cinco, el productor se queda con un gasto a medias que
       no sabe que está a medias. Entran todas o no entra ninguna. */
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO operaciones
            (usuario_id, lote_id, grupo_gasto, tipo_componente, costo_total, moneda, fecha,
             campania_operacion, grupo_descripcion, proveedor_servicio,
             cantidad_ha, precio_unitario, hectareas)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmtIns = $pdo->prepare(
        "INSERT INTO operacion_insumos (operacion_id, insumo_id, nombre_libre, cantidad_ha, precio_unitario)
         VALUES (?, ?, ?, ?, ?)"
    );
    /* Se permite stock negativo, igual que el formulario: no se clampa a cero ni se
       desactiva el insumo. Si el productor usó más de lo que tenía anotado, el
       número en rojo es información, y taparlo sería esconderle un descuadre. */
    $stmtStock = $pdo->prepare(
        "UPDATE insumos SET stock_actual = stock_actual - ? WHERE id = ? AND usuario_id = ?"
    );

    $ids = [];
    foreach ($destinos as $d) {
        /* La labor se muestra como "proveedor · X ha × $Y/ha", y ahí van la
           superficie y el precio por hectárea. En el insumo esos dos campos son
           del renglón, no de la operación, así que la operación va en cero como la
           deja el formulario.
           Se deja marcado el origen: si mañana algo no cierra, se sabe qué entró
           por el chat y qué por el formulario. */
        $stmt->execute([$usuario_id, $d['id'], $grupo, $tipo_db, $d['monto'], $moneda, $fecha,
                        $campania ?: null, 'Cargado desde el chat',
                        $tipo === 'insumo' ? null : $detalle,
                        $tipo === 'insumo' ? 0 : $d['sup_calc'],
                        $tipo === 'insumo' ? 0 : $d['precio_ha'],
                        $d['sup_calc']]);
        $op_id = (int)$pdo->lastInsertId();
        $ids[] = $op_id;

        if ($tipo === 'insumo') {
            /* Dos formas, las mismas dos del formulario:
                 · del catálogo → insumo_id apuntando al insumo, y se descuenta
                   stock por la cantidad que le toca a este lote;
                 · escrito a mano → insumo_id en NULL y el nombre en nombre_libre,
                   sin tocar stock. Es la opción "Ingresar Texto Manual (Sin
                   Descontar Stock)", con esas mismas consecuencias.
               La cantidad de este lote es cantidad_ha × su superficie: la misma
               cuenta que hace operaciones.php al devolver el stock, así que
               descontar y devolver son simétricos. */
            $stmtIns->execute([
                $op_id,
                $insumoCat ? (int)$insumoCat['id'] : null,
                $insumoCat ? null : $insumo,
                $cant_ha_ins,
                $precio_ins,
            ]);

            if ($insumoCat) {
                $stmtStock->execute([$cant_ha_ins * $d['sup_calc'], (int)$insumoCat['id'], $usuario_id]);
            }
        }
    }

    $pdo->commit();

    $qué = $tipo === 'insumo' ? $insumo : $grupo;
    $msg = $n === 1
        ? 'Listo, quedó cargado: ' . number_format($monto, 2, ',', '.')
          . ' de ' . $qué . ' en ' . $destinos[0]['nombre'] . '.'
        : 'Listo, quedaron cargados ' . number_format($monto, 2, ',', '.')
          . ' de ' . $qué . ' repartidos en ' . $n . ' lotes.';

    echo json_encode([
        'ok' => true,
        'id' => $ids[0],
        'ids' => $ids,
        'msg' => $msg,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[motor/registrar] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar el gasto. No quedó nada a medias; probá de nuevo.'],
                     JSON_UNESCAPED_UNICODE);
}
