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
require_once __DIR__ . '/../includes/carga_lotes.php';
$lote_nombre = mb_strtoupper($op['lote_nombre'], 'UTF-8');
$titulo     = receta_titulo($op);   // decía PULVERIZACIÓN fijo
$labor_desc = mb_strtoupper($op['grupo_descripcion'] ?: $titulo, 'UTF-8');
$total_ha = (float)$op['lote_sup'];
// Con lo guardado: las hectáreas aplicadas y las de la labor (receta_numeros).
$n = receta_numeros($op, $insumos, $total_ha);
$cargas        = $n['cargas'];
$ha_aplicacion = $n['ha_aplic'];
$ha_por_carga  = $n['ha_carga'];
$total_insumos_cost = $n['insumos'];
$labor_precio = (float)$op['precio_unitario'];
$labor_ha     = $n['ha_labor'];
$labor_total  = $n['labor'];
$grand_total    = $n['total'];
$grand_total_ha = $n['total_ha'];
$es_sectorizada = ($ha_aplicacion < $total_ha - 0.001);
$sim = ($op['moneda'] ?? 'ARS') === 'USD' ? 'USD' : '$';

// Si la saca un asesor, va con su nombre (el Excel no muestra el logo). Ver includes/asesor.php.
require_once __DIR__ . '/../includes/asesor.php';
$firma = firma_informe($pdo);

// ─── Headers para forzar descarga Excel ─────────────────────────────────────
$filename = "Receta_" . ucfirst(strtolower(strtr($titulo, ['Ó' => 'O', 'É' => 'E']))) . "_{$lote_nombre}_" . date('Ymd', strtotime($op['fecha'])) . ".xls";
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
            <td colspan="6" class="header-yellow" style="font-size:18px;"><?= htmlspecialchars($titulo) ?></td>
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
        <tr>
            <td colspan="3" style="text-align:left;">Superficie del lote: <strong><?= number_format($total_ha, 2, ',', '.') ?> ha</strong></td>
            <td colspan="3" style="text-align:right;">
                Hectáreas aplicadas (insumos): <strong><?= number_format($ha_aplicacion, 2, ',', '.') ?> ha</strong>
                <?php if ($es_sectorizada): ?><span style="color:#ff0000;"> · APLICACIÓN SECTORIZADA</span><?php endif; ?>
            </td>
        </tr>
    </table>
    <br>

    <!-- ===== TABLA LABOR ===== -->
    <table>
        <tr>
            <th class="col-header" style="width:32%;">DETALLE LABOR</th>
            <th class="col-header" style="width:17%;">CARGAS</th>
            <th class="col-header" style="width:17%;">HA/CARGA</th>
            <th class="col-header" style="width:17%;">HA APLICADAS</th>
            <th class="col-header" style="width:17%;">TOTAL HA LOTE</th>
        </tr>
        <tr>
            <td class="bold text-center"><?= htmlspecialchars($labor_desc) ?></td>
            <td class="bold text-center"><?= $cargas ?></td>
            <td class="bold text-center"><?= number_format($ha_por_carga, 2, ',', '.') ?></td>
            <td class="bold text-center"><?= number_format($ha_aplicacion, 2, ',', '.') ?></td>
            <td class="bold text-center"><?= number_format($total_ha, 2, ',', '.') ?></td>
        </tr>
    </table>
    <br>

    <!-- ===== TABLA INSUMOS ===== -->
    <table>
        <tr>
            <th class="col-header text-left">PRODUCTO</th>
            <th class="col-header">DOSIS/HA</th>
            <th class="col-header">POR CARGA</th>
            <th class="col-header">CANT. TOTAL</th>
            <th class="col-header">PRECIO UNIT (<?= $sim ?>)</th>
            <th class="col-header">COSTO TOTAL</th>
        </tr>
        <?php foreach ($insumos as $ins): 
            $nombre = strtoupper($ins['insumo_nombre'] ?: $ins['nombre_libre']);
            $cant_ha = (float)$ins['cantidad_ha'];
            $cant_carga = $cant_ha * $ha_por_carga;
            $cant_total = $cant_ha * $ha_aplicacion;   // sobre ha aplicadas (sectorizado)
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
            <th class="col-header" style="width:25%;"><?= $sim ?>/HA</th>
            <th class="col-header" style="width:25%;">TOTAL</th>
        </tr>
        <tr>
            <td class="text-left"><?= htmlspecialchars($labor_desc) ?> (<?= number_format($labor_ha, 2, ',', '.') ?> ha)</td>
            <td class="text-center"><?= number_format($labor_precio, 2, ',', '.') ?></td>
            <td class="text-center"><?= number_format($labor_total, 2, ',', '.') ?></td>
        </tr>
    </table>
    <br>

    <!-- ===== RESUMEN FINAL ===== -->
    <table>
        <tr>
            <td class="grand-total" style="width:40%;">COSTO TOTAL (<?= $sim ?>)</td>
            <td class="grand-total text-center" style="width:30%;"><?= number_format($grand_total, 2, ',', '.') ?></td>
            <td class="grand-total text-center" style="width:15%;"><?= $sim ?>/HA</td>
            <td class="grand-total text-center" style="width:15%;"><?= number_format($grand_total_ha, 2, ',', '.') ?></td>
        </tr>
    </table>
    <?php if ($firma): ?>
    <br>
    <table>
        <tr><td colspan="4" style="border:none; font-weight:bold;"><?= htmlspecialchars(firma_texto($firma)) ?></td></tr>
    </table>
    <?php endif; ?>
</body>
</html>
