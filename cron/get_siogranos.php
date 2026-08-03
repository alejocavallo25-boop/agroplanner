<?php
/**
 * get_siogranos.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Sincronización de precios de granos.
 *
 * FUENTE: pizarra de la Cámara Arbitral de Cereales (Bolsa de Comercio de
 *         Rosario) — https://www.cac.bcr.com.ar/es/precios-de-pizarra
 *
 * Por qué no el MAGYP: hasta el 18/06/2026 esto leía el Monitor SIO-Granos.
 * Ese sitio tiene ahora un WAF (BunkerWeb) que responde 403 a los pedidos que
 * salen de este servidor — bloquea las IP de datacenter. Verificado el
 * 03/08/2026 con una sonda desde el propio servidor: MAGYP 403, BCR 200.
 * Cambiar el User-Agent no alcanzó. El scraper viejo, con toda su lógica de
 * fechas, quedó en el historial de git (commit ade5655) por si algún día se
 * recupera el acceso.
 *
 * Diferencia importante con la fuente anterior: la pizarra publica UN precio de
 * referencia por grano, no el promedio/mínimo/máximo de las operaciones del día.
 * Por eso precio_minimo, precio_maximo y precio_modal quedan en NULL — no se
 * inventan valores. El tablero oculta el rango cuando no están.
 *
 * El nombre del archivo se mantiene porque el cron del hPanel apunta acá:
 *   0 0,12 * * *  wget -q -O - "https://agroplanner.online/cron/get_siogranos.php"
 *
 * Uso manual: php cron/get_siogranos.php
 * ─────────────────────────────────────────────────────────────────────────────
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/../config/database.php';

define('URL_PIZARRA', 'https://www.cac.bcr.com.ar/es/precios-de-pizarra');

// Zona fija: la pizarra es de Rosario. Se guarda con un zona_id propio para no
// pisar las filas históricas que vinieron de SIO-Granos (la clave única de la
// tabla es fecha + producto_id + zona_id).
define('ZONA',    'Rosario (Cámara Arbitral)');
define('ZONA_ID', 'CAC');

/**
 * Granos a extraer.
 * La clave es el sufijo de la clase CSS del sitio (<div class="board board-soja">)
 * y 'cultivo' es el nombre exacto que espera el tablero en index.php.
 */
$PRODUCTOS = [
    'soja'    => 'Soja Cámara',
    'maiz'    => 'Maíz',
    'trigo'   => 'Trigo Cámara',
    'girasol' => 'Girasol Cámara',
    'sorgo'   => 'Sorgo',
];

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

/**
 * Descarga la pizarra. Devuelve el HTML o false.
 */
function bajar_pizarra(): string|false
{
    $ch = curl_init(URL_PIZARRA);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language: es-AR,es;q=0.9,en;q=0.8',
        ],
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($html === false || $html === '') {
        log_msg("ERROR de red: " . ($err !== '' ? $err : 'respuesta vacía'));
        return false;
    }
    if ($code !== 200) {
        log_msg("ERROR: la pizarra respondió HTTP $code.");
        return false;
    }
    return $html;
}

/**
 * Fecha de la pizarra: "Precios Pizarra del día 31/07/2026".
 * Se busca dentro del bloque de precios para no agarrar fechas de otras partes
 * de la página (hay una fecha de publicación del sitio en las metaetiquetas).
 */
function fecha_pizarra(string $html): ?string
{
    if (!preg_match('/board-prices.*?(\d{2})\/(\d{2})\/(\d{4})/s', $html, $m)) {
        return null;
    }
    return "$m[3]-$m[2]-$m[1]";   // a formato SQL
}

/**
 * Precio de un grano: <div class="board board-soja"> ... <div class="price"> $500.000,00 </div>
 * El sitio usa el formato argentino: punto de miles y coma decimal.
 */
function precio_grano(string $html, string $slug): ?float
{
    $re = '/board-' . preg_quote($slug, '/') . '\b.*?class="price"[^>]*>\s*\$?\s*([\d.]+),(\d{2})/s';
    if (!preg_match($re, $html, $m)) {
        return null;
    }
    $valor = (float) (str_replace('.', '', $m[1]) . '.' . $m[2]);
    return $valor > 0 ? $valor : null;
}

// ── Descarga ─────────────────────────────────────────────────────────────────
log_msg('=== Iniciando sincronización de precios (pizarra BCR) ===');

$html = bajar_pizarra();
if ($html === false) {
    log_msg('Abortando: no se pudo leer la pizarra.');
    exit(1);
}

$fechaSQL = fecha_pizarra($html);
if ($fechaSQL === null) {
    log_msg('ERROR: no se encontró la fecha en la página. ¿Cambió el HTML del sitio?');
    exit(1);
}
log_msg('Pizarra del ' . date('d/m/Y', strtotime($fechaSQL)));

// ── Guardado ─────────────────────────────────────────────────────────────────
$sql = "
    INSERT INTO cotizaciones_siogranos
        (fecha, cultivo, producto_id, zona, zona_id,
         precio_promedio, precio_minimo, precio_maximo, precio_modal, moneda)
    VALUES
        (:fecha, :cultivo, :producto_id, :zona, :zona_id,
         :promedio, NULL, NULL, NULL, 'ARS')
    ON DUPLICATE KEY UPDATE
        precio_promedio     = VALUES(precio_promedio),
        fecha_actualizacion = CURRENT_TIMESTAMP
";
$stmt = $pdo->prepare($sql);

$exitosos = 0;
$fallidos = 0;

foreach ($PRODUCTOS as $slug => $cultivo) {
    $precio = precio_grano($html, $slug);

    if ($precio === null) {
        log_msg("  ✗ $cultivo — no se pudo leer el precio (¿el sitio dejó de publicarlo?).");
        $fallidos++;
        continue;
    }

    $stmt->execute([
        ':fecha'       => $fechaSQL,
        ':cultivo'     => $cultivo,
        ':producto_id' => $slug,
        ':zona'        => ZONA,
        ':zona_id'     => ZONA_ID,
        ':promedio'    => $precio,
    ]);

    log_msg(sprintf('  ✔ %-16s $%s /ton', $cultivo, number_format($precio, 0, ',', '.')));
    $exitosos++;
}

log_msg("=== Finalizado: $exitosos guardados, $fallidos sin dato ===");
exit($exitosos === 0 ? 1 : 0);
