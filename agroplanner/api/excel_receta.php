<?php
require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';

$usuario_id = $_SESSION['usuario_id'];
$op_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$op_id) {
    die("ID no especificado");
}

// ─── Fetch Data ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT o.*, l.nombre as lote_nombre, l.superficie as lote_sup,
           COALESCE(c.nombre, o.cultivo_operacion) as cultivo_nombre
    FROM operaciones o
    JOIN lotes l ON o.lote_id = l.id
    LEFT JOIN cultivos c ON o.cultivo_id = c.id
    WHERE o.id = ? AND o.usuario_id = ?
");
$stmt->execute([$op_id, $usuario_id]);
$op = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$op || $op['tipo_componente'] !== 'receta_labor') {
    die("Receta no encontrada o no válida.");
}

$stmtHijos = $pdo->prepare("
    SELECT oi.*, i.nombre as insumo_nombre, i.unidad_medida 
    FROM operacion_insumos oi
    LEFT JOIN insumos i ON oi.insumo_id = i.id
    WHERE oi.operacion_id = ?
");
$stmtHijos->execute([$op_id]);
$insumos = $stmtHijos->fetchAll(PDO::FETCH_ASSOC);

// ─── Calculations ───────────────────────────────────────────────────────────
$fecha = date('d/m/Y', strtotime($op['fecha']));
$lote_nombre = strtoupper($op['lote_nombre']);
$labor_desc = strtoupper($op['grupo_descripcion'] ?: $op['grupo_gasto']);
$cargas = (int)($op['cargas'] ?: 1);
$total_ha = (float)($op['lote_sup']);
$ha_por_carga = $cargas > 0 ? ($total_ha / $cargas) : $total_ha;

$total_insumos_cost = 0;
foreach ($insumos as $ins) {
    $cant_ha = (float)$ins['cantidad_ha'];
    $precio_u = (float)$ins['precio_unitario'];
    $total_insumos_cost += ($cant_ha * $total_ha * $precio_u);
}

$labor_precio = (float)$op['precio_unitario'];
$labor_total = $labor_precio * $total_ha;

$grand_total = $total_insumos_cost + $labor_total;
$grand_total_ha = $total_ha > 0 ? ($grand_total / $total_ha) : 0;

// ─── Headers para forzar descarga Excel ─────────────────────────────────────
$filename = "Receta_Pulverizacion_{$lote_nombre}_" . date('Ymd', strtotime($op['fecha'])) . ".xls";
$filename = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<style>
    table, th, td { border: 1px solid #1a1a2e; border-collapse: collapse; padding: 6px 10px; font-family: Arial, sans-serif; font-size: 12px; }
    .header-yellow { background-color: #ffff00; color: #ff0000; font-size: 16px; font-weight: bold; text-align: center; }
    .lote-box { background-color: #e6e6e6; color: #ff0000; font-size: 14px; font-weight: bold; text-align: center; }
    .col-header { background-color: #0f172a; color: #ffffff; font-weight: bold; text-align: center; }
    .text-left { text-align: left; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .bold { font-weight: bold; }
    .total-row { font-weight: bold; text-align: right; background-color: #f1f5f9; border-top: 2px solid #000; }
    .grand-total { background-color: #ffff00; font-weight: bold; }
</style>
</head>
<body>
    <!-- ===== ENCABEZADO ===== -->
    <table>
        <tr>
            <td colspan="6" class="header-yellow" style="font-size:18px;">PULVERIZACIÓN</td>
        </tr>
        <tr>
            <td colspan="6" class="header-yellow" style="font-size:14px;">INSUMOS UTILIZADOS Y LABOR</td>
        </tr>
    </table>
    <br>

    <!-- ===== INFO LOTE Y FECHA ===== -->
    <table>
        <tr>
            <td colspan="4" class="lote-box"><?= htmlspecialchars($lote_nombre) ?></td>
            <td colspan="2" style="font-weight:bold; text-align:right;">FECHA: <?= $fecha ?></td>
        </tr>
    </table>
    <br>

    <!-- ===== TABLA LABOR ===== -->
    <table>
        <tr>
            <th class="col-header" style="width:40%;">DETALLE LABOR</th>
            <th class="col-header" style="width:20%;">CARGAS</th>
            <th class="col-header" style="width:20%;">HA/CARGA</th>
            <th class="col-header" style="width:20%;">TOTAL HA.</th>
        </tr>
        <tr>
            <td class="bold text-center"><?= htmlspecialchars($labor_desc) ?></td>
            <td class="bold text-center"><?= $cargas ?></td>
            <td class="bold text-center"><?= number_format($ha_por_carga, 2, ',', '.') ?></td>
            <td class="bold text-center"><?= number_format($total_ha, 2, ',', '.') ?></td>
        </tr>
    </table>
    <br>

    <!-- ===== TABLA INSUMOS ===== -->
    <table>
        <tr>
            <th class="col-header text-left">PRODUCTO</th>
            <th class="col-header">LTRS./HA</th>
            <th class="col-header">LTRS. POR CARGA</th>
            <th class="col-header">LTRS. TOTALES</th>
            <th class="col-header">PRECIO UNIT (USD)</th>
            <th class="col-header">COSTO TOTAL</th>
        </tr>
        <?php foreach ($insumos as $ins): 
            $nombre = strtoupper($ins['insumo_nombre'] ?: $ins['nombre_libre']);
            $cant_ha = (float)$ins['cantidad_ha'];
            $cant_carga = $cant_ha * $ha_por_carga;
            $cant_total = $cant_ha * $total_ha;
            $precio_u = (float)$ins['precio_unitario'];
            $costo = $cant_total * $precio_u;
        ?>
        <tr>
            <td class="text-left"><?= htmlspecialchars($nombre) ?></td>
            <td class="text-center"><?= number_format($cant_ha, 3, ',', '.') ?></td>
            <td class="text-center"><?= number_format($cant_carga, 2, ',', '.') ?></td>
            <td class="text-center"><?= number_format($cant_total, 2, ',', '.') ?></td>
            <td class="text-center"><?= number_format($precio_u, 2, ',', '.') ?></td>
            <td class="text-center"><?= number_format($costo, 2, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="5" class="total-row">TOTAL INSUMOS</td>
            <td class="total-row text-center"><?= number_format($total_insumos_cost, 2, ',', '.') ?></td>
        </tr>
    </table>
    <br>

    <!-- ===== TABLA LABOR COSTO ===== -->
    <table>
        <tr>
            <th class="col-header text-left" style="width:50%;">LABOR</th>
            <th class="col-header" style="width:25%;">USD/HA</th>
            <th class="col-header" style="width:25%;">TOTAL</th>
        </tr>
        <tr>
            <td class="text-left">PULVERIZACIÓN / APLICACIÓN</td>
            <td class="text-center"><?= number_format($labor_precio, 2, ',', '.') ?></td>
            <td class="text-center"><?= number_format($labor_total, 2, ',', '.') ?></td>
        </tr>
    </table>
    <br>

    <!-- ===== RESUMEN FINAL ===== -->
    <table>
        <tr>
            <td class="grand-total" style="width:40%;">COSTO TOTAL (USD)</td>
            <td class="grand-total text-center" style="width:30%;"><?= number_format($grand_total, 2, ',', '.') ?></td>
            <td class="grand-total text-center" style="width:15%;">USD/HA</td>
            <td class="grand-total text-center" style="width:15%;"><?= number_format($grand_total_ha, 2, ',', '.') ?></td>
        </tr>
    </table>
</body>
</html>
