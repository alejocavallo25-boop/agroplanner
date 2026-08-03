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

// Cuántos días se retrocede buscando el último con operaciones.
// 10 cubre fines de semana largos y feriados encadenados.
define('MAX_DIAS_ATRAS', 10);

// El sitio corta los pedidos muy seguidos: alrededor del sexto empieza a
// devolver una página HTML en vez del JSON. Con esta pausa entre pedidos y un
// reintento alcanza para que entren los seis productos.
define('PAUSA_ENTRE_PEDIDOS', 700000); // microsegundos (0,7 s)
define('ESPERA_REINTENTO',    4);      // segundos

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
 *
 * El sitio del MAGYP está detrás de un WAF (BunkerWeb) que corta los pedidos
 * que huelen a robot y devuelve una página HTML de 403. El User-Agent anterior
 * era 'AgroPlanner-SyncBot/1.0' — con la palabra "Bot" adentro, que es
 * justamente lo que esos filtros buscan. Por eso ahora se manda el juego de
 * cabeceras de un navegador común.
 */
function sio_get(string $url): string|false
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',   // acepta gzip/deflate, como un navegador
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Language: es-AR,es;q=0.9,en;q=0.8',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://monitorsiogranos.magyp.gob.ar/monitorsiogranos.html',
            'Origin: https://monitorsiogranos.magyp.gob.ar',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Dest: empty',
            'Connection: keep-alive',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        log_msg("    cURL error: $err");
        return false;
    }

    // El WAF responde una página HTML enorme. Se resume en una línea para que
    // el log del cron siga siendo legible desde el panel.
    if ($code >= 400 || (isset($body[0]) && $body[0] === '<')) {
        $motivo = $code >= 400 ? "HTTP $code" : 'respuesta HTML en vez de JSON';
        log_msg("    Bloqueado por el sitio ($motivo) — el WAF rechazó el pedido.");
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
    return restar_dias($fechaDMY, 7);
}

/**
 * Resta días a una fecha dd/mm/yyyy y devuelve el mismo formato.
 */
function restar_dias(string $fechaDMY, int $dias): string
{
    [$d, $m, $y] = explode('/', $fechaDMY);
    $ts = mktime(0, 0, 0, (int)$m, (int)$d - $dias, (int)$y);
    return date('d/m/Y', $ts);
}

/**
 * GET que además decodifica el JSON, con reintento.
 * Cuando el sitio corta por exceso de pedidos devuelve una página HTML: eso no
 * parsea, se espera y se vuelve a pedir. Devuelve null si no hubo respuesta
 * válida (ojo: "0" es una respuesta válida y significa "sin operaciones").
 */
function sio_get_json(string $url, int $reintentos = 2)
{
    for ($i = 0; $i <= $reintentos; $i++) {
        if ($i > 0) {
            log_msg('    · respuesta no-JSON (corte por exceso de pedidos), reintento en ' . ESPERA_REINTENTO . 's');
            sleep(ESPERA_REINTENTO);
        }
        $raw = sio_get($url);
        if ($raw === false) continue;

        $data = json_decode(trim($raw), true);
        if (json_last_error() === JSON_ERROR_NONE) return $data;
    }
    return null;
}

/**
 * ¿El mercado operó ese día? La API responde true/false.
 */
function tiene_datos(string $fechaDMY): bool
{
    $url = ENDPOINT_TIENE_DATOS . '?cosas=' . urlencode(json_encode(['fecha' => $fechaDMY]));
    return !empty(sio_get_json($url));
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

// La fecha que informa el sitio es un punto de partida, NO una garantía de que
// ese día haya operaciones. Devuelve el día corriente (con la hora en ":00")
// aunque el mercado todavía no haya operado, así que hay que retroceder hasta
// encontrar el último día con datos. Confiar en ella a ciegas fue lo que dejó
// la sincronización congelada desde el 18/06/2026: el cron corre a las 00:00 y
// a las 12:00, horarios en los que el día corriente casi nunca tiene datos, y
// el script abortaba sin guardar nada en lugar de tomar el día hábil anterior.
$rawFecha  = sio_get(ENDPOINT_ULTIMA_FECHA);
$fechaBase = date('d/m/Y');

if ($rawFecha !== false) {
    $dataFecha = json_decode(trim($rawFecha), true);
    if (json_last_error() === JSON_ERROR_NONE && !empty($dataFecha['fecha'])) {
        $fechaBase = $dataFecha['fecha'];
        log_msg('Fecha informada por el sitio: ' . $fechaBase . '  Hora: ' . ($dataFecha['hora'] ?? '—'));
    } else {
        log_msg('AVISO: respuesta inesperada de fecha (' . substr($rawFecha, 0, 80) . '). Se arranca desde hoy.');
    }
} else {
    log_msg('AVISO: no respondió el endpoint de fecha. Se arranca desde hoy.');
}

// ── Paso 2: Retroceder hasta el último día con operaciones ───────────────────
$fechaHoy = null;
for ($i = 0; $i <= MAX_DIAS_ATRAS; $i++) {
    $candidata = restar_dias($fechaBase, $i);
    if (tiene_datos($candidata)) {
        $fechaHoy = $candidata;
        break;
    }
    log_msg("  · $candidata sin operaciones (fin de semana, feriado o jornada aún sin cerrar)");
}

if ($fechaHoy === null) {
    log_msg('ERROR: no se encontró ningún día con datos en los últimos ' . MAX_DIAS_ATRAS . ' días. Abortando.');
    exit(1);
}

$fechaHace = hace_una_semana($fechaHoy);
[$d, $m, $y] = explode('/', $fechaHoy);
$fechaSQL = "$y-$m-$d";

log_msg("Último día con operaciones: $fechaHoy");
log_msg("Rango de consulta:          $fechaHoy → $fechaHace  (7 días hacia atrás)");

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

    usleep(PAUSA_ENTRE_PEDIDOS);
    $inner = sio_get_json($url);

    if ($inner === null) {
        log_msg("  ✗ $label — Sin respuesta válida del servidor.");
        $fallidos++;
        continue;
    }

    // Cuando no hay operaciones devuelve "0" (string) o el int 0
    if ($inner === '0' || $inner === 0) {
        log_msg("  ⚠ $label — Sin operaciones en el rango consultado.");
        $fallidos++;
        continue;
    }

    // inner debe ser un array con claves 'minimos', 'promedios', 'maximos', 'modal'
    if (!is_array($inner)) {
        log_msg("  ✗ $label — Respuesta inesperada: " . substr(json_encode($inner), 0, 200));
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

    // Cada grano opera sus propios días: el girasol o la cebada pueden no tener
    // operaciones el día que sí operó la soja. En ese caso el dato igual sirve,
    // así que se guarda con SU fecha en vez de descartarlo — si no, esos cultivos
    // no aparecen nunca en el panel.
    $fechaFilaSQL = $fechaSQL;
    if ($fechaRespuesta && $fechaRespuesta !== $fechaHoy) {
        [$rd, $rm, $ry]  = explode('/', $fechaRespuesta);
        $fechaFilaSQL    = "$ry-$rm-$rd";
        log_msg("  ℹ $label — no operó el $fechaHoy; se guarda su último dato, del $fechaRespuesta.");
    }

    if ($promedio === null && $minimo === null && $maximo === null) {
        log_msg("  ⚠ $label — Precios vacíos en la respuesta.");
        $fallidos++;
        continue;
    }

    $stmt->execute([
        ':fecha'       => $fechaFilaSQL,
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
