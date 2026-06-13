<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
$usuario_id = $_SESSION['usuario_id'];

$tipo = $_GET['tipo'] ?? 'operaciones';
$campania = !empty($_GET['campania']) ? trim($_GET['campania']) : null;
$grupo    = !empty($_GET['grupo'])    ? trim($_GET['grupo'])    : null;
$lote_id  = !empty($_GET['lote_id'])  ? (int)$_GET['lote_id']   : null;

// Configurar cabeceras nativas para forzar la descarga en Excel (.xls HTML table pattern)
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=Reporte_{$tipo}_" . date('Ymd_His') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

$titulo = '';
$rows = [];

if ($tipo === 'operaciones') {
    $titulo = 'Reporte de Costos y Labores' . ($campania ? " - Campaña $campania" : '');
    $query = "SELECT o.fecha, o.grupo_gasto, o.grupo_descripcion, o.tipo_componente,
                     l.nombre as lote, o.campania_operacion, o.cultivo_operacion,
                     i.nombre as insumo, o.proveedor_servicio,
                     o.cantidad_ha, o.precio_unitario, o.costo_total
              FROM operaciones o JOIN lotes l ON o.lote_id = l.id LEFT JOIN insumos i ON o.insumo_id = i.id
              WHERE o.usuario_id = ?";
    $params = [$usuario_id];
    if ($campania) { $query .= " AND o.campania_operacion = ?"; $params[] = $campania; }
    if ($grupo && $grupo !== 'todos') { $query .= " AND o.grupo_gasto = ?"; $params[] = $grupo; }
    if ($lote_id)  { $query .= " AND o.lote_id = ?"; $params[] = $lote_id; }
    $query .= " ORDER BY o.fecha DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} elseif ($tipo === 'tambo_egresos') {
    $titulo = 'Reporte de Egresos del Tambo';
    $query = "SELECT fecha, categoria, subcategoria, concepto, cantidad, unidad, precio_unitario, monto, moneda, notas FROM tambo_egresos WHERE usuario_id = ?";
    $params = [$usuario_id];
    if ($grupo && $grupo !== 'todos') { $query .= " AND categoria = ?"; $params[] = $grupo; }
    $query .= " ORDER BY fecha DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$fechaGen = date('d/m/Y H:i');

// --- RENDER HTML PARA EXCEL ---
// Al usar HTML estructurado, Excel interpreta estilos (colores de fondo, bordes, negritas).
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<style>
    .header-table { font-family: Arial; font-weight: bold; background-color: #0f172a; color: #ffffff; text-align: center; }
    .title-main { font-family: Arial; font-size: 24px; font-weight: bold; color: #10b981; }
    .title-sub { font-family: Arial; font-size: 14px; font-style: italic; color: #64748b; }
    .cell-date { font-family: Arial; text-align: center; }
    .cell-money { font-family: Arial; text-align: right; }
    .cell-total { font-family: Arial; font-weight: bold; background-color: #f1f5f9; text-align: right; border-top: 2px solid #000; }
    table, th, td { border: 1px solid #cbd5e1; border-collapse: collapse; padding: 5px; font-family: Arial, sans-serif; font-size:12px; }
</style>
</head>
<body>
    <table>
        <tr>
            <td colspan="4" class="title-main">AGROPLANNER</td>
        </tr>
        <tr>
            <td colspan="4" class="title-sub"><?= htmlspecialchars($titulo) ?></td>
        </tr>
        <tr>
            <td colspan="4" class="title-sub">Generado el: <?= $fechaGen ?></td>
        </tr>
        <tr><td colspan="4"></td></tr>
        
        <?php if ($tipo === 'operaciones'): ?>
            <tr class="header-table">
                <th style="background-color:#0f172a; color:#fff;">Fecha</th>
                <th style="background-color:#0f172a; color:#fff;">Grupo</th>
                <th style="background-color:#0f172a; color:#fff;">Tipo</th>
                <th style="background-color:#0f172a; color:#fff;">Detalle</th>
                <th style="background-color:#0f172a; color:#fff;">Lote</th>
                <th style="background-color:#0f172a; color:#fff;">Campaña</th>
                <th style="background-color:#0f172a; color:#fff;">Cant/Ha</th>
                <th style="background-color:#0f172a; color:#fff;">Precio Unit.</th>
                <th style="background-color:#0f172a; color:#fff;">Costo Total ($)</th>
            </tr>
            <?php 
            $total = 0;
            foreach ($rows as $r): 
                $total += $r['costo_total'];
            ?>
            <tr>
                <td class="cell-date"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
                <td><?= htmlspecialchars($r['grupo_descripcion'] ?: $r['grupo_gasto']) ?></td>
                <td><?= $r['tipo_componente'] === 'labor' ? 'Labor' : 'Insumo' ?></td>
                <td><?= htmlspecialchars($r['tipo_componente'] === 'labor' ? ($r['proveedor_servicio'] ?? '') : ($r['insumo'] ?? '')) ?></td>
                <td><?= htmlspecialchars($r['lote']) ?></td>
                <td><?= htmlspecialchars($r['campania_operacion'] ?? '') ?></td>
                <td class="cell-money"><?= number_format((float) $r['cantidad_ha'], 4, ',', '') ?></td>
                <td class="cell-money"><?= number_format((float) $r['precio_unitario'], 2, ',', '') ?></td>
                <td class="cell-money" style="color:#b91c1c;">-<?= number_format((float) $r['costo_total'], 2, ',', '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php $end_row = 5 + count($rows); ?>
            <tr>
                <td colspan="8" style="text-align:right; font-weight:bold; background-color:#f1f5f9; border-top:2px solid #000;">TOTAL DE COSTOS:</td>
                <td class="cell-total" style="color:#b91c1c;"><?= count($rows) > 0 ? "=SUMA(I6:I{$end_row})" : "0" ?></td>
            </tr>
        <?php elseif ($tipo === 'tambo_egresos'): ?>
            <tr class="header-table">
                <th style="background-color:#0f172a; color:#fff;">Fecha</th>
                <th style="background-color:#0f172a; color:#fff;">Categoría</th>
                <th style="background-color:#0f172a; color:#fff;">Subcategoría</th>
                <th style="background-color:#0f172a; color:#fff;">Concepto</th>
                <th style="background-color:#0f172a; color:#fff;">Cantidad</th>
                <th style="background-color:#0f172a; color:#fff;">Precio Unit.</th>
                <th style="background-color:#0f172a; color:#fff;">Monto</th>
                <th style="background-color:#0f172a; color:#fff;">Moneda</th>
            </tr>
            <?php 
            $totalARS = 0; $totalUSD = 0;
            foreach ($rows as $r): 
                if ($r['moneda'] === 'USD') $totalUSD += $r['monto'];
                else                         $totalARS += $r['monto'];
            ?>
            <tr>
                <td class="cell-date"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
                <td><?= htmlspecialchars($r['categoria']) ?></td>
                <td><?= htmlspecialchars($r['subcategoria'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['concepto'] ?? '—') ?></td>
                <td class="cell-money"><?= $r['cantidad'] ? number_format((float) $r['cantidad'], 2, ',', '') . ' ' . $r['unidad'] : '—' ?></td>
                <td class="cell-money"><?= $r['precio_unitario'] ? number_format((float) $r['precio_unitario'], 2, ',', '') : '—' ?></td>
                <td class="cell-money" style="color:#b91c1c;">-<?= number_format((float) $r['monto'], 2, ',', '') ?></td>
                <td style="text-align:center;"><?= $r['moneda'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php $end_row = 5 + count($rows); ?>
            <tr>
                <td colspan="6" style="text-align:right; font-weight:bold; background-color:#f1f5f9; border-top:2px solid #000;">TOTAL ARS:</td>
                <td class="cell-total" style="color:#b91c1c;"><?= count($rows) > 0 ? "=SUMAR.SI(H6:H{$end_row};\"ARS\";G6:G{$end_row})" : "0" ?></td>
                <td style="background-color:#f1f5f9; border-top:2px solid #000;">ARS</td>
            </tr>
            <?php if ($totalUSD > 0): ?>
            <tr>
                <td colspan="6" style="text-align:right; font-weight:bold; background-color:#f1f5f9;">TOTAL USD:</td>
                <td class="cell-total" style="color:#b91c1c;"><?= count($rows) > 0 ? "=SUMAR.SI(H6:H{$end_row};\"USD\";G6:G{$end_row})" : "0" ?></td>
                <td style="background-color:#f1f5f9;">USD</td>
            </tr>
            <?php endif; ?>
        <?php endif; ?>
    </table>
</body>
</html>
