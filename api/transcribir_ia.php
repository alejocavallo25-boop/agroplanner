<?php
/**
 * api/transcribir_ia.php
 *
 * El audio del botón de dictar del chat, a texto (Whisper en Groq). Devuelve
 * sólo el texto: la pantalla lo deja en el campo para revisarlo, no lo manda
 * solo. Si no anda, la pantalla vuelve al dictado del navegador.
 */

require_once '../config/auth.php';
require_once '../config/database.php';
require_once '../includes/ia.php';

header('Content-Type: application/json; charset=utf-8');

function tri_salir(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tri_salir(['ok' => false, 'motivo' => 'metodo'], 405);
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    tri_salir(['ok' => false, 'motivo' => 'csrf'], 403);
}
if (!ia_voz_disponible($env)) {
    tri_salir(['ok' => false, 'motivo' => 'sin_proveedores']);
}

$usuario_id = (int)$_SESSION['usuario_id'];
session_write_close();   // que esperar la transcripción no trabe otras pantallas

$f = $_FILES['audio'] ?? null;
// Un dictado de 30 segundos en opus son unos 100 KB: 5 MB es un techo holgado.
if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])
    || $f['size'] <= 0 || $f['size'] > 5 * 1024 * 1024) {
    tri_salir(['ok' => false, 'motivo' => 'archivo'], 400);
}

/* El formato depende del navegador: Chrome graba webm, Safari mp4. finfo suele
   decir "video/..." aunque sea sólo audio, porque el contenedor es el mismo. */
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
$extensiones = ['audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg',
                'audio/mp4' => 'mp4', 'video/mp4' => 'mp4', 'audio/x-m4a' => 'm4a',
                'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav'];
if (!isset($extensiones[$mime])) {
    tri_salir(['ok' => false, 'motivo' => 'archivo'], 400);
}

set_time_limit(IA_TIMEOUT_SEGUNDOS + 15);

try {
    $res = ia_transcribir($pdo, $env, $usuario_id, $f['tmp_name'], 'dictado.' . $extensiones[$mime],
                          ia_voz_vocabulario($pdo, $usuario_id));
} catch (Throwable $e) {
    error_log('[transcribir_ia] ' . $e->getMessage());
    $res = ['ok' => false, 'motivo' => 'fallaron'];
}
tri_salir($res);
