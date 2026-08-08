<?php
/**
 * tests/correr.php
 *
 * Corredor de pruebas mínimo, en PHP plano.
 *
 *     php tests/correr.php
 *
 * Sin PHPUnit y sin composer a propósito: el proyecto no tiene dependencias y no
 * vale la pena estrenar un gestor de paquetes para esto. Lo que hace falta es
 * poder correr las cuentas del negocio antes de deployar y que griten si algo
 * cambió.
 *
 * Qué se prueba: funciones puras, sin base de datos. Un 500 se ve y se arregla;
 * un margen mal calculado devuelve un número con toda confianza y el productor
 * decide con eso. Ese es el error que estas pruebas existen para atrapar.
 *
 * Devuelve 0 si pasa todo y 1 si falla algo, así se puede encadenar con el deploy.
 */

$GLOBALS['pruebas'] = ['ok' => 0, 'fallas' => []];
$GLOBALS['grupo_actual'] = '';

function grupo(string $nombre): void
{
    $GLOBALS['grupo_actual'] = $nombre;
    echo "\n── $nombre " . str_repeat('─', max(0, 60 - mb_strlen($nombre))) . "\n";
}

/** Compara valores exactos (se comparan como texto para no pelear con los tipos). */
function es(string $que, $esperado, $real): void
{
    if ((string)$esperado === (string)$real) {
        $GLOBALS['pruebas']['ok']++;
        return;
    }
    $msg = sprintf("%s: esperaba %s y vino %s",
        $que, var_export($esperado, true), var_export($real, true));
    $GLOBALS['pruebas']['fallas'][] = $GLOBALS['grupo_actual'] . ' → ' . $msg;
    echo "  FALLA  $msg\n";
}

/** Compara números con tolerancia, para no romperse por el redondeo del float. */
function esCerca(string $que, float $esperado, float $real, float $tolerancia = 0.0001): void
{
    if (abs($esperado - $real) <= $tolerancia) {
        $GLOBALS['pruebas']['ok']++;
        return;
    }
    $msg = sprintf("%s: esperaba %s y vino %s", $que, $esperado, $real);
    $GLOBALS['pruebas']['fallas'][] = $GLOBALS['grupo_actual'] . ' → ' . $msg;
    echo "  FALLA  $msg\n";
}

/** Para condiciones sueltas. */
function esVerdad(string $que, $condicion): void
{
    es($que, 'true', $condicion ? 'true' : 'false');
}

// ── Correr todo ──────────────────────────────────────────────────────────────
$archivos = glob(__DIR__ . '/test_*.php');
sort($archivos);

foreach ($archivos as $archivo) {
    require $archivo;
}

$ok     = $GLOBALS['pruebas']['ok'];
$fallas = $GLOBALS['pruebas']['fallas'];

echo "\n" . str_repeat('═', 64) . "\n";
if (!$fallas) {
    echo "TODO BIEN — $ok comprobaciones en " . count($archivos) . " archivos.\n";
    exit(0);
}

echo count($fallas) . " FALLA(S) sobre " . ($ok + count($fallas)) . " comprobaciones:\n";
foreach ($fallas as $f) echo "  · $f\n";
exit(1);
