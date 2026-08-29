<?php
/**
 * includes/moneda.php
 *
 * En qué moneda se MUESTRA la plata, en toda la sección de Agricultura.
 *
 * No cambia nada de lo guardado. Cada movimiento tiene su propia moneda —el
 * contratista factura en pesos, el alquiler se pacta en dólares— y acá sólo se
 * decide en cuál de las dos se lo lee. La conversión usa la cotización del MES DEL
 * MOVIMIENTO, no una sola para todo: un gasto de marzo a 1.200 y uno de agosto a
 * 1.500 no se pueden aplanar con el mismo número sin borrar justo lo que el
 * productor quiere ver cuando mira en dólares.
 *
 * Existe como archivo aparte porque las seis pantallas de Agricultura muestran
 * plata y todas tienen que decir lo mismo. Cuando esto vivía suelto en index.php,
 * el panel podía estar en dólares y la lista de operaciones en pesos.
 */

require_once __DIR__ . '/dolar.php';

/**
 * La moneda elegida, leída de la URL una sola vez.
 *
 * Se pasa por la URL y no por sesión a propósito: así un enlace o un F5 muestran
 * lo mismo que la pantalla de la que se vino, y no hay un estado invisible que
 * haga que dos personas mirando "la misma" pantalla vean números distintos.
 */
function moneda_actual(): string {
    static $m = null;
    if ($m === null) {
        $m = (($_GET['moneda'] ?? 'ARS') === 'USD') ? 'USD' : 'ARS';
    }
    return $m;
}

/**
 * "US$" y no "$" a secas para los dólares.
 *
 * En una pantalla donde conviven un alquiler en dólares y un contratista en pesos,
 * el símbolo es lo único que distingue un número de otro que vale mil veces más.
 */
function ap_simbolo(): string {
    return moneda_actual() === 'USD' ? 'US$' : '$';
}

/** Plata, con el símbolo de la moneda que se está mostrando. */
function ap_plata($valor, int $dec = 2): string {
    return ap_simbolo() . number_format((float)$valor, $dec, ',', '.');
}

/**
 * Plata que sale, con el signo delante.
 *
 * El menos es una etiqueta —"esto es gasto"—, no una resta, así que cuando el
 * gasto es cero no va: "-$0,00" no significa nada y da la impresión de que la
 * cuenta se hizo mal justo cuando el productor recién empieza y todo está en cero.
 */
function ap_egreso($valor, int $dec = 2): string {
    $v = (float)$valor;
    return ($v > 0 ? '-' : '') . ap_plata($v, $dec);
}

/**
 * Las cotizaciones del productor, mes por mes, en una sola consulta.
 *
 * Se traen todas juntas y se convierte en PHP porque las listas muestran decenas
 * de filas: pedir la cotización de cada una sería una consulta por renglón.
 *
 * @return array{tasas: array<string,float>, respaldo: float}
 */
function moneda_tasas(PDO $pdo, int $usuario_id): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    dolar_asegurar_tabla($pdo);
    $tasas = [];
    try {
        $st = $pdo->prepare("SELECT mes, dolar_mayorista FROM tambo_dolar_mes WHERE usuario_id = ?");
        $st->execute([$usuario_id]);
        foreach ($st->fetchAll() as $r) {
            $tasas[(string)$r['mes']] = (float)$r['dolar_mayorista'];
        }
    } catch (Throwable $e) {
        // Sin tabla se sigue con el de referencia; no es motivo para tirar la página.
    }

    $cache = ['tasas' => $tasas, 'respaldo' => dolar_referencia($pdo, $usuario_id)['valor']];
    return $cache;
}

/**
 * Pasa un importe a la moneda que se está mostrando.
 *
 * @param string|null $fecha  fecha del movimiento; de ahí sale el mes de la cotización.
 *                            Sin fecha —un precio de catálogo, por ejemplo— se usa
 *                            la de referencia, que es lo único disponible.
 */
function moneda_convertir(PDO $pdo, int $usuario_id, $monto, string $monedaFila, ?string $fecha = null): float {
    $destino = moneda_actual();
    $monedaFila = ($monedaFila === 'USD') ? 'USD' : 'ARS';
    if ($monedaFila === $destino) return (float)$monto;

    $t = moneda_tasas($pdo, $usuario_id);
    $mes = $fecha ? substr($fecha, 0, 7) : null;
    $tasa = ($mes !== null && isset($t['tasas'][$mes]) && $t['tasas'][$mes] > 0)
          ? $t['tasas'][$mes]
          : $t['respaldo'];
    if ($tasa <= 0) return (float)$monto;   // sin cotización no se inventa una

    return $destino === 'USD' ? (float)$monto / $tasa : (float)$monto * $tasa;
}

/**
 * El selector de moneda.
 *
 * Navega con un parámetro en la URL en vez de guardar un estado: es la misma
 * página con otra lectura, no otra página. El resto de los filtros que ya estén
 * puestos se conservan, que es lo que uno espera al tocar un control de vista.
 *
 * Lleva la etiqueta "Ver en" adelante porque sin ella eran dos pastillas sueltas
 * en un rincón: en Costos y Labores quedaban al lado de otro conmutador idéntico
 * —importe / porcentaje— y no había forma de saber cuál hacía qué. Y cada opción
 * muestra su símbolo además del código, que es lo que el productor reconoce
 * cuando después lo ve delante de los números.
 *
 * aria-current y no aria-pressed: no son botones que se aprietan, es cuál de las
 * dos vistas se está mirando.
 */
function moneda_toggle(): void {
    $actual = moneda_actual();
    $url = function (string $m): string {
        $q = $_GET;
        if ($m === 'ARS') unset($q['moneda']); else $q['moneda'] = 'USD';
        $qs = http_build_query($q);
        return htmlspecialchars(basename($_SERVER['SCRIPT_NAME']) . ($qs ? '?' . $qs : ''), ENT_QUOTES);
    };
    $ops = ['ARS' => ['$', 'pesos'], 'USD' => ['US$', 'dólares']];
    ?>
    <div class="ap-moneda">
        <span class="ap-moneda__etiqueta" id="ap-moneda-lbl">Ver en</span>
        <div class="ap-moneda__grupo" role="group" aria-labelledby="ap-moneda-lbl">
            <?php foreach ($ops as $cod => [$sim, $nombre]): ?>
                <a href="<?= $url($cod) ?>"
                   class="ap-moneda__op"
                   aria-current="<?= $actual === $cod ? 'true' : 'false' ?>"
                   title="Ver los importes en <?= $nombre ?>">
                    <?= $sim ?> <?= $cod ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
