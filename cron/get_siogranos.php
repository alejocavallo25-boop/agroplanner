<?php
/**
 * get_siogranos.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Script de sincronización de precios de granos.
 * Fuente: Monitor SIO-Granos del Ministerio de Agricultura (MAGYP)
 * URL:    https://monitorsiogranos.magyp.gob.ar/
 *
 * Uso:
 *   - Manual:  php cron/get_siogranos.php
 *   - Cron:    0 12 * * 1-5 php /ruta/a/agroplanner/cron/get_siogranos.php
 *
 * Nota: El parámetro fechaDesde = HOY, fechaHasta = hace una semana (así lo
 * hace el JS oficial del sitio). La respuesta contiene arrays de precios
 * diarios; tomamos el ÚLTIMO elemento (el más reciente).
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Configuración de errores ─────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ── Carga de la conexión a la base de datos ──────────────────────────────────
define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/../config/database.php';

// ── Constantes de la API ──────────────────────────────────────────────────────
define('SIOGRANOS_BASE', 'https://monitorsiogranos.magyp.gob.ar/');
define('ENDPOINT_ULTIMA_FECHA',  SIOGRANOS_BASE . 'v5_ajax/funcionUltimaFechaParaMostrar_min.php');
define('ENDPOINT_TIENE_DATOS',   SIOGRANOS_BASE . 'v5_ajax/tieneDatos_min.php');
define('ENDPOINT_COTIZACIONES',  SIOGRANOS_BASE . 'v5_ajax/cuadrosCotizaciones_min.php');

// ── Productos a sincronizar ───────────────────────────────────────────────────
// IDs extraídos del JS oficial + inspección en vivo del HTML
// zonaPrecioPpal por defecto = '23' (Rosario Norte)
// Otras zonas: '24' = Rosario Sur | '20' = Bahía Blanca | '0' = Todas (sin filtro)
$PRODUCTOS = [
    'Soja Cámara'      => ['producto_id' => '18',   'zona_id' => '23', 'zona' => 'Rosario Norte'],
    'Maíz'             => ['producto_id' => '2',    'zona_id' => '23', 'zona' => 'Rosario Norte'],
    'Trigo Cámara'     => ['producto_id' => '1',    'zona_id' => '23', 'zona' => 'Rosario Norte'],
    'Girasol Cámara'   => ['producto_id' => '17',   'zona_id' => '23', 'zona' => 'Rosario Norte'],
    'Sorgo'            => ['producto_id' => '3',    'zona_id' => '23', 'zona' => 'Rosario Norte'],
    'Cebada Forrajera' => ['producto_id' => '7',    'zona_id' => '23', 'zona' => 'Rosario Norte'],
];


// ── Utilidades ────────────────────────────────────────────────────────────────

/**
 * Petición GET con cURL. Devuelve el body o false en caso de error.
 */
function sio_get(string $url): string|false
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'AgroPlanner-SyncBot/1.0',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://monitorsiogranos.magyp.gob.ar/monitorsiogranos.html',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        log_msg("cURL error para $url: $err");
        return false;
    }
    return $body;
}

/**
 * Registra un mensaje con marca de tiempo en stdout.
 */
function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

/**
 * Calcula la fecha de "hace una semana" en formato dd/mm/yyyy.
 * El JS del sitio calcula el rango fechaDesde=HOY, fechaHasta=haceUnaSemana.
 */
function hace_una_semana(string $fechaDMY): string
{
    [$d, $m, $y] = explode('/', $fechaDMY);
    $ts = mktime(0, 0, 0, (int)$m, (int)$d - 7, (int)$y);
    return date('d/m/Y', $ts);
}

/**
 * Extrae el último valor de precio de un array de la respuesta SIO-Granos.
 * Cada elemento del array tiene las claves 'fecha_concertacion' y 'valor'.
 */
function ultimo_precio(array $arr): ?float
{
    if (empty($arr)) return null;
    $ultimo = end($arr);
    if (!isset($ultimo['valor'])) return null;
    $val = str_replace([',', ' '], ['.', ''], (string) $ultimo['valor']);
    return is_numeric($val) ? (float) $val : null;
}

// ── Paso 1: Obtener la última fecha con datos disponibles ─────────────────────
log_msg('=== Iniciando sincronización SIO-Granos ===');

$rawFecha = sio_get(ENDPOINT_ULTIMA_FECHA);
if ($rawFecha === false) {
    log_msg('ERROR: No se pudo conectar al endpoint de fecha. Abortando.');
    exit(1);
}

$dataFecha = json_decode(trim($rawFecha), true);
if (json_last_error() !== JSON_ERROR_NONE || empty($dataFecha['fecha'])) {
    log_msg('ERROR: Respuesta inesperada de fecha: ' . $rawFecha);
    exit(1);
}

$fechaHoy  = $dataFecha['fecha'];          // "29/04/2026"
$hora      = $dataFecha['hora'] ?? '—';
$fechaHace = hace_una_semana($fechaHoy);   // "22/04/2026"

[$d, $m, $y] = explode('/', $fechaHoy);
$fechaSQL = "$y-$m-$d";

log_msg("Última fecha disponible: $fechaHoy  Hora: $hora");
log_msg("Rango de consulta:       $fechaHoy → $fechaHace  (HOY → hace 7 días)");

// ── Paso 2: Verificar si hay datos para esa fecha ────────────────────────────
$rawTiene = sio_get(ENDPOINT_TIENE_DATOS . '?cosas=' . urlencode(json_encode(['fecha' => $fechaHoy])));
$tiene    = json_decode(trim($rawTiene ?? ''), true);
if (empty($tiene)) {
    log_msg("Sin datos operables para $fechaHoy. El mercado puede estar cerrado. Abortando.");
    exit(0);
}
log_msg("Confirmado: hay datos para $fechaHoy.");

// ── Paso 3: Preparar el INSERT/UPDATE ────────────────────────────────────────
$sql = "
    INSERT INTO cotizaciones_siogranos
        (fecha, cultivo, producto_id, zona, zona_id,
         precio_promedio, precio_minimo, precio_maximo, precio_modal, moneda)
    VALUES
        (:fecha, :cultivo, :producto_id, :zona, :zona_id,
         :promedio, :minimo, :maximo, :modal, 'ARS')
    ON DUPLICATE KEY UPDATE
        precio_promedio     = VALUES(precio_promedio),
        precio_minimo       = VALUES(precio_minimo),
        precio_maximo       = VALUES(precio_maximo),
        precio_modal        = VALUES(precio_modal),
        fecha_actualizacion = CURRENT_TIMESTAMP
";
$stmt = $pdo->prepare($sql);

// ── Paso 4: Iterar sobre cada producto ───────────────────────────────────────
$exitosos = 0;
$fallidos = 0;

foreach ($PRODUCTOS as $label => $cfg) {
    // El JS usa: fechaDesde = HOY, fechaHasta = hace una semana
    $params = [
        'fechaDesde' => $fechaHoy,
        'fechaHasta' => $fechaHace,
        'producto'   => $cfg['producto_id'],
        'puerto'     => $cfg['zona_id'],
    ];
    $cosas = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $url   = ENDPOINT_COTIZACIONES . '?cosas=' . urlencode($cosas);
    $raw   = sio_get($url);

    if ($raw === false) {
        log_msg("  ✗ $label — Error de red.");
        $fallidos++;
        continue;
    }

    // Decodificar: la respuesta es un JSON-dentro-de-JSON (el JS hace JSON.parse(data))
    $inner = json_decode(trim($raw), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_msg("  ✗ $label — JSON inválido: " . substr($raw, 0, 100));
        $fallidos++;
        continue;
    }

    // Cuando no hay operaciones devuelve "0" (string) o el int 0
    if ($inner === '0' || $inner === 0 || $inner === null) {
        log_msg("  ⚠ $label — Sin operaciones para $fechaHoy.");
        $fallidos++;
        continue;
    }

    // inner debe ser un array con claves 'minimos', 'promedios', 'maximos', 'modal'
    if (!is_array($inner)) {
        log_msg("  ✗ $label — Respuesta inesperada: " . substr($raw, 0, 200));
        $fallidos++;
        continue;
    }

    $promedio = ultimo_precio($inner['promedios'] ?? []);
    $minimo   = ultimo_precio($inner['minimos']   ?? []);
    $maximo   = ultimo_precio($inner['maximos']   ?? []);
    $modal    = ultimo_precio($inner['modal']     ?? []);

    // Verificar que la fecha del último elemento coincide con fechaHoy
    // (la API puede devolver el último día con datos si el solicitado no tiene)
    $fechaRespuesta = null;
    if (!empty($inner['minimos'])) {
        $ultimoItem     = end($inner['minimos']);
        $rawFechaItem   = $ultimoItem['fecha_concertacion'] ?? null;
        // Convertir de YYYY-MM-DD a DD/MM/YYYY para comparar
        if ($rawFechaItem) {
            [$fy, $fm, $fd] = explode('-', $rawFechaItem);
            $fechaRespuesta = "$fd/$fm/$fy";
        }
    }

    if ($fechaRespuesta && $fechaRespuesta !== $fechaHoy) {
        log_msg("  ⚠ $label — La fecha de la respuesta ($fechaRespuesta) no coincide con $fechaHoy. Sin datos del día.");
        $fallidos++;
        continue;
    }

    if ($promedio === null && $minimo === null && $maximo === null) {
        log_msg("  ⚠ $label — Precios vacíos en la respuesta.");
        $fallidos++;
        continue;
    }

    $stmt->execute([
        ':fecha'       => $fechaSQL,
        ':cultivo'     => $label,
        ':producto_id' => $cfg['producto_id'],
        ':zona'        => $cfg['zona'],
        ':zona_id'     => $cfg['zona_id'],
        ':promedio'    => $promedio,
        ':minimo'      => $minimo,
        ':maximo'      => $maximo,
        ':modal'       => $modal,
    ]);

    log_msg(sprintf(
        '  ✔ %-18s  Prom: %s  Min: %s  Max: %s  Modal: %s  ($/ton)',
        $label,
        $promedio !== null ? number_format($promedio, 0, ',', '.') : '—',
        $minimo   !== null ? number_format($minimo,   0, ',', '.') : '—',
        $maximo   !== null ? number_format($maximo,   0, ',', '.') : '—',
        $modal    !== null ? number_format($modal,    0, ',', '.') : '—'
    ));
    $exitosos++;
}

// ── Resultado final ───────────────────────────────────────────────────────────
log_msg("=== Sincronización finalizada: $exitosos OK, $fallidos sin datos/errores ===");
exit($exitosos === 0 ? 1 : 0);
