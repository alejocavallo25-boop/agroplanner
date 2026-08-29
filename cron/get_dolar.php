<?php
/**
 * get_dolar.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Guarda el dólar mayorista del mes en curso para todos los usuarios activos.
 *
 * FUENTE: https://dolarapi.com/v1/dolares/mayorista
 *
 * Por qué existe
 * ──────────────
 * Hasta ahora el tipo de cambio se cargaba como efecto secundario de abrir
 * tambo.php: esa pantalla consultaba la API en cada visita y guardaba el valor
 * de paso. Dos problemas.
 *
 * El primero, que el productor que sólo usa Agricultura nunca puede llegar ahí
 * —require_tambo() lo rebota— y se quedaba sin ninguna cotización. El panel
 * entonces convertía los alquileres pagados en pesos con un valor fijo de 1000
 * escrito en el código. Con el mayorista cerca de 1500 eso infla el costo de
 * alquiler un 50%, y el margen neto sale mal sin que nada lo indique.
 *
 * El segundo, que una pantalla que escribe en la base cada vez que se abre paga
 * una llamada HTTP con timeout antes de renderizar. El dato es el mismo para
 * todos y cambia una vez por día: es trabajo de un cron, no de una pantalla.
 *
 * Nunca pisa un valor cargado a mano: si el productor escribió el tipo de cambio
 * al que realmente liquidó, ese es el que vale (ver dolar_guardar()).
 *
 * Cron sugerido en el hPanel — el servidor corre en UTC, así que esto es a las
 * 18:00 de Argentina, con el mercado ya cerrado:
 *   0 21 * * *  wget -q -O - "https://agroplanner.online/cron/get_dolar.php"
 *
 * Uso manual: php cron/get_dolar.php
 * ─────────────────────────────────────────────────────────────────────────────
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// El servidor corre en UTC. Se fija la zona local para que el mes que se guarda
// sea el mes argentino: el último día de cada mes, a partir de las 21 hora local,
// en UTC ya es el mes siguiente y la cotización quedaría archivada en el mes que
// no es.
date_default_timezone_set('America/Argentina/Cordoba');

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dolar.php';

define('URL_DOLAR', 'https://dolarapi.com/v1/dolares/mayorista');
define('ARCHIVO_LOG', __DIR__ . '/dolar.log');
define('LOG_MAX_BYTES', 512 * 1024);

function log_msg(string $msg): void
{
    $linea = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    echo $linea;

    if (@filesize(ARCHIVO_LOG) > LOG_MAX_BYTES) {
        @rename(ARCHIVO_LOG, ARCHIVO_LOG . '.1');
    }
    @file_put_contents(ARCHIVO_LOG, $linea, FILE_APPEND | LOCK_EX);
}

/** Baja la cotización mayorista. Devuelve el valor de venta o null. */
function bajar_dolar(): ?float
{
    $ch = curl_init(URL_DOLAR);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'AgroPlanner/1.0 (+https://agroplanner.online)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $cuerpo = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($cuerpo === false || $cuerpo === '') {
        log_msg('ERROR de red: ' . ($error !== '' ? $error : 'respuesta vacía'));
        return null;
    }
    if ($codigo !== 200) {
        log_msg("ERROR: la API respondió HTTP $codigo.");
        return null;
    }

    $datos = json_decode($cuerpo, true);
    if (!is_array($datos) || !isset($datos['venta'])) {
        log_msg('ERROR: la respuesta no trae el campo "venta". ¿Cambió la API?');
        return null;
    }

    $valor = (float)$datos['venta'];
    if ($valor <= 0) {
        log_msg('ERROR: la API devolvió una venta de ' . $valor . '.');
        return null;
    }
    return $valor;
}

// ── Descarga ─────────────────────────────────────────────────────────────────
log_msg('=== Sincronización del dólar mayorista ===');

$valor = bajar_dolar();
if ($valor === null) {
    log_msg('Abortando: no se pudo obtener la cotización.');
    exit(1);
}

$mes = date('Y-m');
log_msg(sprintf('Mayorista de %s: $%s', $mes, number_format($valor, 2, ',', '.')));

// ── Guardado para cada usuario activo ────────────────────────────────────────
//
// La cotización es la misma para todos, pero la tabla guarda una fila por
// usuario para que cada uno pueda sobrescribirla con el tipo de cambio al que
// liquidó. Por eso se recorren los usuarios en vez de guardar una fila global.
dolar_asegurar_tabla($pdo);

$usuarios = $pdo->query("SELECT id FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
if (!$usuarios) {
    log_msg('No hay usuarios activos. Nada que guardar.');
    exit(0);
}

$guardados = 0;
$respetados = 0;

foreach ($usuarios as $uid) {
    if (dolar_guardar($pdo, (int)$uid, $mes, $valor, 'api')) {
        $guardados++;
    } else {
        // Tenía un valor cargado a mano para este mes: no se toca.
        $respetados++;
    }
}

log_msg("=== Finalizado: $guardados actualizados, $respetados con valor manual respetado ===");
exit(0);
