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
$fecha = date('d-M', strtotime($op['fecha']));
// Translate month to spanish
$meses = ['Jan'=>'Ene','Feb'=>'Feb','Mar'=>'Mar','Apr'=>'Abr','May'=>'May','Jun'=>'Jun','Jul'=>'Jul','Aug'=>'Ago','Sep'=>'Sep','Oct'=>'Oct','Nov'=>'Nov','Dec'=>'Dic'];
$fecha = strtr($fecha, $meses);

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Receta de Aplicación #<?= $op_id ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; color: #1a1a2e; background: #fff; }
        .print-bar { background: #10b981; padding: 10px 36px; display: flex; align-items: center; gap: 16px; }
        .print-bar button { background: white; color: #10b981; border: none; padding: 8px 20px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 13px; }
        .print-bar span { color: white; font-size: 12px; opacity: 0.9; }
        .content { padding: 40px; max-width: 900px; margin: 0 auto; }
        
        .header-title { background: #ffff00; color: #ff0000; font-size: 18px; font-weight: bold; text-align: center; padding: 10px; border: 2px solid #1a1a2e; margin-bottom: 5px; }
        .header-subtitle { background: #ffff00; color: #ff0000; font-size: 16px; font-weight: bold; text-align: center; padding: 8px; border: 2px solid #1a1a2e; margin-bottom: 20px; }
        
        .info-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .lote-box { background: #e6e6e6; color: #ff0000; font-size: 16px; font-weight: bold; padding: 10px 20px; border: 2px solid #1a1a2e; flex: 1; text-align: center; margin-right: 20px; }
        .date-box { font-size: 14px; font-weight: bold; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 2px solid #1a1a2e; }
        th, td { border: 1px solid #1a1a2e; padding: 8px 12px; text-align: center; }
        th { font-weight: bold; background: #fff; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .bg-yellow { background: #ffff00; }
        
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .print-bar { display: none !important; }
            .content { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="print-bar">
        <button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
        <span>Usá "Guardar como PDF" en el diálogo de impresión del navegador</span>
    </div>
    
    <div class="content">
        <div class="header-title">PULVERIZACIÓN</div>
        <div class="header-subtitle">INSUMOS UTILIZADOS Y LABOR</div>
        
        <div class="info-row">
            <div class="lote-box"><?= htmlspecialchars($lote_nombre) ?></div>
            <div class="date-box">FECHA: <i><?= $fecha ?></i></div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 40%;">DETALLE LABOR</th>
                    <th style="width: 20%;">CARGAS</th>
                    <th style="width: 20%;">HA/CARGA</th>
                    <th style="width: 20%;">TOTAL HA.</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="bold"><i><?= htmlspecialchars($labor_desc) ?></i></td>
                    <td class="bold"><i><?= $cargas ?></i></td>
                    <td class="bold"><i><?= number_format($ha_por_carga, 2, ',', '.') ?></i></td>
                    <td class="bold"><i><?= number_format($total_ha, 2, ',', '.') ?></i></td>
                </tr>
            </tbody>
        </table>
        
        <table>
            <thead>
                <tr>
                    <th class="text-left" style="width: 30%;">PRODUCTO</th>
                    <th>LTRS./HA</th>
                    <th>LTRS. POR CARGA</th>
                    <th>LTRS. TOTALES</th>
                    <th>PRECIO UNIT (USD)</th>
                    <th>COSTO TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($insumos as $ins): 
                    $nombre = strtoupper($ins['insumo_nombre'] ?: $ins['nombre_libre']);
                    $cant_ha = (float)$ins['cantidad_ha'];
                    $cant_carga = $cant_ha * $ha_por_carga;
                    $cant_total = $cant_ha * $total_ha;
                    $precio_u = (float)$ins['precio_unitario'];
                    $costo = $cant_total * $precio_u;
                ?>
                <tr>
                    <td class="text-left"><i><?= htmlspecialchars($nombre) ?></i></td>
                    <td><i><?= number_format($cant_ha, 3, ',', '.') ?></i></td>
                    <td><i><?= number_format($cant_carga, 2, ',', '.') ?></i></td>
                    <td><i><?= number_format($cant_total, 2, ',', '.') ?></i></td>
                    <td><i><?= number_format($precio_u, 2, ',', '.') ?></i></td>
                    <td><i><?= number_format($costo, 2, ',', '.') ?></i></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="5" class="text-right bold">TOTAL</td>
                    <td class="bold"><?= number_format($total_insumos_cost, 2, ',', '.') ?></td>
                </tr>
            </tbody>
        </table>
        
        <table style="width: 60%;">
            <thead>
                <tr>
                    <th class="text-left" style="width: 50%;">LABOR</th>
                    <th style="width: 25%;">USD/HA</th>
                    <th style="width: 25%;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-left">PULVERIZACIÓN / APLICACIÓN</td>
                    <td><?= number_format($labor_precio, 2, ',', '.') ?></td>
                    <td><?= number_format($labor_total, 2, ',', '.') ?></td>
                </tr>
            </tbody>
        </table>
        
        <div style="display: flex; justify-content: flex-end;">
            <table style="width: 60%; margin-bottom: 0;">
                <tr>
                    <td class="bold bg-yellow" style="width: 40%;">COSTO TOTAL (USD)</td>
                    <td class="bold bg-yellow" style="width: 30%;"><?= number_format($grand_total, 2, ',', '.') ?></td>
                    <td class="bold bg-yellow" style="width: 15%;">USD/HA</td>
                    <td class="bold bg-yellow" style="width: 15%;"><?= number_format($grand_total_ha, 2, ',', '.') ?></td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
