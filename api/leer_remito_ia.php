<?php
/**
 * api/leer_remito_ia.php
 *
 * Recibe la foto de un remito y devuelve sus renglones como TEXTO, para el
 * cuadro de la pantalla de insumos. No escribe nada de insumos: el productor
 * revisa ese texto y lo analiza con el importador de siempre.
 *
 * Si no hay IA configurada, se acabó el cupo o fallan todos los proveedores,
 * contesta ok:false con `motivo`, y la pantalla sigue con el lector del
 * teléfono (tesseract), que es lo que había antes. Nunca deja al productor sin
 * camino.
 */

require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';
require_once '../includes/importador_insumos.php';
require_once '../includes/ia.php';

header('Content-Type: application/json; charset=utf-8');

function lri_salir(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lri_salir(['ok' => false, 'error' => 'Método no permitido.'], 405);
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    lri_salir(['ok' => false, 'error' => 'Token de seguridad vencido. Recargá la página e intentá de nuevo.'], 403);
}
if (!ia_disponible($env)) {
    lri_salir(['ok' => false, 'motivo' => 'sin_proveedores']);
}

/* Se suelta la sesión antes de esperar a la IA. PHP la tiene bloqueada mientras
   el pedido corre, y leer una foto tarda de 3 a 20 segundos: el productor que
   abría otra pantalla en ese rato se quedaba esperando a que terminara esto.
   De acá en adelante sólo se LEE la sesión, nunca se escribe. */
$usuario_id = (int)$_SESSION['usuario_id'];
session_write_close();

$f = $_FILES['foto'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    lri_salir(['ok' => false, 'error' => 'No llegó la foto.'], 400);
}
// La pantalla la manda ya achicada (unos cientos de KB): más que esto no es eso.
if ($f['size'] <= 0 || $f['size'] > 6 * 1024 * 1024) {
    lri_salir(['ok' => false, 'error' => 'La foto pesa demasiado.'], 400);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    lri_salir(['ok' => false, 'error' => 'El archivo no es una foto.'], 400);
}

// Margen sobre IA_PRESUPUESTO_SEGUNDOS + el último pedido, que puede empezar justo antes.
set_time_limit(IA_PRESUPUESTO_SEGUNDOS + IA_TIMEOUT_SEGUNDOS + 15);

$mensajes = [
    ['role' => 'system', 'content' => imp_ia_instrucciones()],
    ['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => 'Transcribí este comprobante.'],
        ['type' => 'image_url', 'image_url' => [
            'url' => 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($f['tmp_name'])),
        ]],
    ]],
];

$validar = function (string $texto): ?array {
    $json = ia_extraer_json($texto);
    if ($json === null) return null;
    $r = imp_ia_a_texto($json);
    // Dice que es un remito pero no sacó ni un renglón: que pruebe el siguiente.
    if ($r === null || ($r['es_remito'] && $r['renglones'] === 0)) return null;
    return $r;
};

try {
    $res = ia_cadena($pdo, $env, $usuario_id, 'remito', $mensajes, $validar);
} catch (Throwable $e) {
    error_log('[leer_remito_ia] ' . $e->getMessage());
    lri_salir(['ok' => false, 'motivo' => 'fallaron']);
}

if (!$res['ok']) {
    lri_salir(['ok' => false, 'motivo' => $res['motivo']]);
}

lri_salir([
    'ok'        => true,
    'es_remito' => $res['datos']['es_remito'],
    'texto'     => $res['datos']['texto'],
    'renglones' => $res['datos']['renglones'],
    'moneda'    => $res['datos']['moneda'],
    'proveedor' => $res['proveedor'],
]);
