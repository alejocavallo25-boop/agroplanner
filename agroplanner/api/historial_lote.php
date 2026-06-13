<?php
require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';
$usuario_id = $_SESSION['usuario_id'];
$lote_id = (int)($_GET['lote_id'] ?? 0);

header('Content-Type: application/json');

if (!$lote_id) { echo json_encode([]); exit; }

$stmt = $pdo->prepare("
    SELECT h.campania, h.cultivo, h.fecha_cierre, h.kg_total, h.ingreso_total,
           l.nombre as lote_nombre, l.superficie
    FROM lote_historial_campanas h
    JOIN lotes l ON h.lote_id = l.id
    WHERE h.lote_id = ? AND h.usuario_id = ?
    ORDER BY h.fecha_cierre DESC
");
$stmt->execute([$lote_id, $usuario_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
