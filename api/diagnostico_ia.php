<?php
/**
 * api/diagnostico_ia.php
 *
 * La foto mandada desde el chat: una planta o un bicho (diagnóstico), o un
 * remito (se lee y se ofrece llevarlo a Insumos). Devuelve una respuesta con la
 * misma forma que el motor ({ok, respuesta, detalle}), así la burbuja la pinta
 * como cualquier otra.
 *
 * No toca ningún dato del productor: ni lee la base de su campo ni escribe
 * nada fuera del registro de uso de la IA. Lo que se arma con la respuesta del
 * modelo está en includes/diagnostico_ia.php, que es donde se filtran dosis y
 * se agrega el aviso de consultar al agrónomo.
 */

require_once '../config/auth.php';
require_once '../config/database.php';
require_once '../includes/ia.php';
require_once '../includes/diagnostico_ia.php';

header('Content-Type: application/json; charset=utf-8');

function dxa_salir(string $respuesta, string $detalle = '', bool $ok = false, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode(['ok' => $ok, 'respuesta' => $respuesta, 'detalle' => $detalle], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dxa_salir('Método no permitido.', '', false, 405);
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    dxa_salir('Se venció la sesión de seguridad.', 'Recargá la página y mandá la foto de nuevo.', false, 403);
}
if (!ia_disponible($env)) {
    dxa_salir('Todavía no puedo mirar fotos.', 'Falta configurar la IA en el servidor.');
}

// Mismo motivo que en leer_remito_ia.php: que esperar a la IA no trabe otras pantallas.
$usuario_id = (int)$_SESSION['usuario_id'];
// Si la foto es un remito, el enlace va a Insumos, que es de Agricultura.
$puedeStock = !empty($_SESSION['modulos']['agricultura']);
/* Una factura de gasto se ofrece cargar por el motor de agricultura: sólo si lo
   tiene y si la mandó desde ese chat. En el del tambo la frase iría al motor del
   tambo, que lleva los egresos de otra forma. */
$puedeGasto = $puedeStock && ($_POST['modulo'] ?? '') !== 'tambo';
session_write_close();

$f = $_FILES['foto'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])
    || $f['size'] <= 0 || $f['size'] > 6 * 1024 * 1024) {
    dxa_salir('No me llegó bien la foto.', 'Probá mandarla de nuevo.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    dxa_salir('Eso no es una foto.', 'Mandame una imagen JPG o PNG.');
}

// Lo que el productor escribió junto con la foto ("en soja, hojas de abajo").
$nota = trim(mb_substr((string)($_POST['nota'] ?? ''), 0, 300, 'UTF-8'));
$pedido = 'Mirá esta foto.' . ($nota !== '' ? ' El productor agrega: "' . $nota . '"' : '');

set_time_limit(IA_PRESUPUESTO_SEGUNDOS + IA_TIMEOUT_SEGUNDOS + 15);

$mensajes = [
    ['role' => 'system', 'content' => dx_instrucciones()],
    ['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => $pedido],
        ['type' => 'image_url', 'image_url' => [
            'url' => 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($f['tmp_name'])),
        ]],
    ]],
];

$validar = function (string $texto) use ($puedeStock, $puedeGasto): ?array {
    $json = ia_extraer_json($texto);
    return $json === null ? null : dx_a_respuesta_foto($json, $puedeStock, $puedeGasto);
};

try {
    $res = ia_cadena($pdo, $env, $usuario_id, 'foto', $mensajes, $validar);
} catch (Throwable $e) {
    error_log('[diagnostico_ia] ' . $e->getMessage());
    $res = ['ok' => false, 'motivo' => 'fallaron'];
}

if (!$res['ok']) {
    if ($res['motivo'] === 'tope') {
        dxa_salir('Ya usaste las consultas con fotos de hoy.', 'Mañana vuelven a estar disponibles.');
    }
    dxa_salir('No pude mirar la foto ahora.', 'Probá de nuevo en unos minutos.');
}

echo json_encode($res['datos'], JSON_UNESCAPED_UNICODE);
