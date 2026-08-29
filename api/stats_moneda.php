<?php
/**
 * api/stats_moneda.php
 *
 * Los totales de la campaña en la moneda que se pida. Es lo que alimenta el
 * cambio de ARS a USD del panel sin recargar la página.
 *
 * Es GET y de sólo lectura, así que no lleva token CSRF: no muta nada. Todo se
 * calcula contra el usuario de la sesión, que nunca llega desde el cliente.
 */
require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';
require_once '../controllers/DashboardController.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new DashboardController($pdo, (int)$_SESSION['usuario_id']);

$moneda  = (($_GET['moneda'] ?? 'ARS') === 'USD') ? 'USD' : 'ARS';
$ciclo   = $_GET['ciclo']   ?? null;
$lote    = ($_GET['lote']    ?? '') !== '' ? (int)$_GET['lote'] : null;
$cultivo = ($_GET['cultivo'] ?? '') !== '' ? (string)$_GET['cultivo'] : null;

// Sin campaña se toma la más reciente, que es la que mira el panel.
if (!$ciclo) {
    $ciclos = $controller->getCiclos();
    $ciclo  = $ciclos[0] ?? null;
}

try {
    $stats = $controller->getGlobalStats($ciclo, $lote, $cultivo, null, $moneda);
    $stats['ciclo'] = $ciclo;
    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[stats_moneda] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'No pude calcular los totales.'], JSON_UNESCAPED_UNICODE);
}
