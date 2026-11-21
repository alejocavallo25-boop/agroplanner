<?php
/**
 * api/registrar_nota.php
 *
 * Guarda una nota del lote que propuso el chat ("quiero guardar una nota").
 * La misma libreta que lotes.php, por la misma función: lote_nota_agregar()
 * (includes/lote_notas.php), que revisa que el lote sea de la cuenta y le pone
 * la campaña que tiene el lote ahora.
 *
 * POST con token CSRF, como las otras puertas del chat: así la cuenta demo
 * queda bloqueada sola por la lista blanca de config/auth.php.
 */
require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';
require_once '../includes/lote_notas.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']);
    exit;
}
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Se venció la sesión del formulario. Recargá la página y probá de nuevo.'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
if (!lote_notas_lista($pdo)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => 'Las notas del lote todavía no están habilitadas.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$lote_id    = (int)($_POST['lote_id'] ?? 0);

try {
    $err = lote_nota_agregar($pdo, $usuario_id, $lote_id, (string)($_POST['fecha'] ?? ''), (string)($_POST['texto'] ?? ''));
    if ($err !== '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'msg' => 'No pude guardarla: ' . $err], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare("SELECT nombre FROM lotes WHERE id = ? AND usuario_id = ?");
    $st->execute([$lote_id, $usuario_id]);
    echo json_encode(['ok' => true, 'msg' => 'Listo, guardé la nota en ' . $st->fetchColumn() . '.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[registrar_nota] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar la nota; probá de nuevo.'], JSON_UNESCAPED_UNICODE);
}
