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
 * OJO: el servidor corre en UTC, así que ese horario es 21:00 y 09:00 de
 * Argentina, no medianoche y mediodía.
 *
 * Códigos de salida:
 *   0  hay precios nuevos
 *   1  falló de verdad (no se pudo bajar o parsear la pizarra)
 *   2  el script anduvo bien pero la BCR no publicó tablero nuevo
 *
 * Deja rastro en cron/precios.log, porque el cron descarta la salida estándar.
 *
 * Uso manual: php cron/get_siogranos.php
 * ─────────────────────────────────────────────────────────────────────────────
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// El servidor de Hostinger corre en UTC (verificado el 08/08/2026: el log marcaba
// 16:17 con las 13:17 de Argentina). Se fija la zona local para que las horas del
// log se puedan comparar de un vistazo con la fecha de la pizarra, que es de acá.
// Ojo con el cron del hPanel: la hora que se configura ahí SIGUE siendo UTC, así
// que "0 0,12" son las 21:00 y las 09:00 de Argentina.
date_default_timezone_set('America/Argentina/Cordoba');

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/../config/database.php';

define('URL_PIZARRA', 'https://www.cac.bcr.com.ar/es/precios-de-pizarra');

// Rastro en disco. El cron corre con `wget -q -O -`, así que todo lo que el script
// imprime se descarta: cuando una corrida falla no queda ninguna evidencia y el
// problema recién se nota cuando alguien mira el tablero. Con esto queda registro.
define('ARCHIVO_LOG', __DIR__ . '/precios.log');
define('LOG_MAX_BYTES', 512 * 1024);

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
    $linea = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    echo $linea;

    // Rotación simple: cuando pasa el tope se conserva una generación anterior.
    // Sin esto, un error que se repite cada 12 horas llena el disco con el tiempo.
    if (@filesize(ARCHIVO_LOG) > LOG_MAX_BYTES) {
        @rename(ARCHIVO_LOG, ARCHIVO_LOG . '.1');
    }
    @file_put_contents(ARCHIVO_LOG, $linea, FILE_APPEND | LOCK_EX);
}

/**
 * Días hábiles entre dos fechas, sin contar sábados ni domingos.
 *
 * La pizarra sólo se publica en días hábiles: el viernes a la tarde y el lunes a
 * la mañana el último tablero es el mismo, y eso es normal. Contando en días
 * corridos, todos los lunes darían falsa alarma.
 */
function dias_habiles(string $desdeSQL, string $hastaSQL): int
{
    $d = new DateTime($desdeSQL);
    $h = new DateTime($hastaSQL);
    if ($d >= $h) return 0;

    $n = 0;
    while ($d < $h) {
        $d->modify('+1 day');
        if ((int)$d->format('N') <= 5) $n++;
    }
    return $n;
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

// ── ¿La fuente está al día? ──────────────────────────────────────────────────
//
// Sin este control, una pizarra congelada se ve idéntica a una corrida exitosa:
// el script vuelve a guardar el mismo tablero viejo y reporta "5 guardados". Fue
// exactamente lo que pasó el 06 y 07/08/2026 y nadie se enteró hasta mirar el
// tablero. Se tolera hasta 2 días hábiles de atraso porque el tablero del día
// suele publicarse a la tarde.
$atraso = dias_habiles($fechaSQL, date('Y-m-d'));
$fuenteVieja = $atraso > 2;
if ($fuenteVieja) {
    log_msg("AVISO: la pizarra tiene $atraso dias habiles de atraso. La BCR no publica "
          . 'un tablero nuevo o cambio la pagina. Los precios del tablero van a seguir '
          . 'mostrando el ' . date('d/m/Y', strtotime($fechaSQL)) . '.');
}

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

$exitosos  = 0;
$fallidos  = 0;
$nuevos    = 0;

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

    // Con ON DUPLICATE KEY UPDATE, MySQL devuelve 1 si insertó y 2 si actualizó
    // una fila que ya existía. Distinguirlo es lo que separa "hay dato nuevo" de
    // "volví a escribir lo mismo", que hasta ahora se veían igual en el log.
    $esNuevo = ($stmt->rowCount() === 1);
    if ($esNuevo) $nuevos++;

    log_msg(sprintf('  ✔ %-16s $%s /ton%s',
        $cultivo,
        number_format($precio, 0, ',', '.'),
        $esNuevo ? '  (nuevo)' : '  (ya estaba)'
    ));
    $exitosos++;
}

log_msg("=== Finalizado: $exitosos leidos, $nuevos nuevos, $fallidos sin dato ===");

// Códigos de salida distintos para que el cron y quien mire el log sepan qué pasó:
//   0 = hay datos nuevos          1 = falló de verdad
//   2 = la fuente no se actualizó (el script anduvo bien, la BCR no publicó)
if ($exitosos === 0)  exit(1);
if ($fuenteVieja)     exit(2);
exit(0);
