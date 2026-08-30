<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rentabilidad.php';
$usuario_id = $_SESSION['usuario_id'];

$tipo = $_GET['tipo'] ?? 'operaciones';
$campania = !empty($_GET['campania']) ? trim($_GET['campania']) : null;
$grupo    = !empty($_GET['grupo'])    ? trim($_GET['grupo'])    : null;
$lote_id  = !empty($_GET['lote_id'])  ? (int)$_GET['lote_id']   : null;
$cultivo  = !empty($_GET['cultivo'])  ? trim($_GET['cultivo'])  : null;
$dep_id   = !empty($_GET['dep_id'])   ? (int)$_GET['dep_id']    : null;
$t_insumo = !empty($_GET['t_insumo']) ? trim($_GET['t_insumo']) : null;

// ─── Datos según tipo de reporte ─────────────────────────────────────────────
$titulo = '';
$rows = [];

if ($tipo === 'operaciones') {
    $titulo = 'Reporte de Costos y Labores' . ($campania ? " — Campaña $campania" : '');
    
    $query = "SELECT o.fecha, o.grupo_gasto, o.grupo_descripcion, o.tipo_componente,
                     l.nombre as lote, o.campania_operacion, o.cultivo_operacion,
                     i.nombre as insumo, o.proveedor_servicio,
                     o.cantidad_ha, o.precio_unitario, o.costo_total
              FROM operaciones o
              JOIN lotes l ON o.lote_id = l.id
              LEFT JOIN insumos i ON o.insumo_id = i.id
              LEFT JOIN cultivos c ON o.cultivo_id = c.id
              WHERE o.usuario_id = ?";
    $params = [$usuario_id];

    if ($campania) { $query .= " AND o.campania_operacion = ?"; $params[] = $campania; }
    if ($grupo && $grupo !== 'todos') { $query .= " AND o.grupo_gasto = ?"; $params[] = $grupo; }
    if ($lote_id)  { $query .= " AND o.lote_id = ?"; $params[] = $lote_id; }
    /* Calcado de getGlobalStats(): el recorte tiene que ser idéntico al del
       panel o el reporte trae filas que el tablero no cuenta. */
    if ($cultivo) {
        $query .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar')"
                . " COLLATE utf8mb4_unicode_ci = ?";
        $params[] = $cultivo;
    }

    $query .= " ORDER BY o.fecha DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

} elseif ($tipo === 'alquileres') {
    $titulo = 'Reporte de Alquileres Pagados' . ($campania ? " — Campaña $campania" : '');
    if ($campania) {
        $stmt = $pdo->prepare("
            SELECT a.fecha_pago, a.nivel_imputacion, a.campania,
                   l.nombre as lote_nombre, c.nombre as cultivo_nombre,
                   a.monto_pagado, a.moneda, a.notas
            FROM alquileres a
            LEFT JOIN lotes l    ON a.lote_id    = l.id
            LEFT JOIN cultivos c ON a.cultivo_id = c.id
            WHERE a.usuario_id = ? AND a.campania = ?
            ORDER BY a.fecha_pago DESC
        ");
        $stmt->execute([$usuario_id, $campania]);
    } else {
        $stmt = $pdo->prepare("
            SELECT a.fecha_pago, a.nivel_imputacion, a.campania,
                   l.nombre as lote_nombre, c.nombre as cultivo_nombre,
                   a.monto_pagado, a.moneda, a.notas
            FROM alquileres a
            LEFT JOIN lotes l    ON a.lote_id    = l.id
            LEFT JOIN cultivos c ON a.cultivo_id = c.id
            WHERE a.usuario_id = ?
            ORDER BY a.fecha_pago DESC
        ");
        $stmt->execute([$usuario_id]);
    }
    $rows = $stmt->fetchAll();

} elseif ($tipo === 'insumos') {
    $titulo = 'Inventario Actual de Insumos';
    $query = "SELECT i.nombre, i.tipo_insumo, i.unidad_medida, i.stock_actual,
                     i.unidad_stock, i.precio_estimado_usd, i.fecha_vencimiento,
                     d.nombre as deposito
              FROM insumos i
              LEFT JOIN depositos d ON i.deposito_id = d.id
              WHERE i.usuario_id = ? AND i.estado = 'activo'";
    $params = [$usuario_id];

    if ($t_insumo && $t_insumo !== 'todos') { $query .= " AND i.tipo_insumo = ?"; $params[] = $t_insumo; }
    if ($dep_id) { $query .= " AND i.deposito_id = ?"; $params[] = $dep_id; }
    if ($dep_id === -1) { $query .= " AND i.deposito_id IS NULL"; } // Para filtrar "Sin depósito"

    $query .= " ORDER BY i.tipo_insumo, i.nombre";
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

} elseif ($tipo === 'dashboard') {
    // Reporte del Panel General Agrícola: reusa la misma data y filtros del dashboard.
    require_agricultura();
    require_once __DIR__ . '/../controllers/DashboardController.php';

    $ciclo_sel   = !empty($_GET['ciclo'])   ? trim($_GET['ciclo'])   : null;
    $lote_sel    = !empty($_GET['lote'])    ? (int)$_GET['lote']     : null;
    $cultivo_sel = !empty($_GET['cultivo']) ? trim($_GET['cultivo']) : null;

    $controller = new DashboardController($pdo, $usuario_id);

    // Si no se pasó campaña, usar la más reciente (igual que el dashboard).
    if (!$ciclo_sel) {
        $ciclos_disp = $controller->getCiclos();
        $ciclo_sel = $ciclos_disp[0] ?? null;
    }

    $dash_stats    = $controller->getGlobalStats($ciclo_sel, $lote_sel, $cultivo_sel);
    $dash_cultivos = $controller->getCultivosData($ciclo_sel, $lote_sel, $cultivo_sel);

    // Desglose de costos directos (labores vs insumos) sumando todos los lotes.
    $dash_labores = 0; $dash_insumos = 0; $dash_total_lotes = 0;
    foreach ($dash_cultivos as $d) {
        foreach ($d['lotes'] as $l) {
            $dash_labores += (float)$l['labores'];
            $dash_insumos += (float)$l['insumos'];
            $dash_total_lotes++;
        }
    }
    $reg_count = $dash_total_lotes;

    $titulo = 'Reporte General Agrícola' . ($ciclo_sel ? " — Campaña $ciclo_sel" : '');
}

// ─── Nombre del usuario ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$usuario_id]);
$user = $stmt->fetch();
$userName = $user['username'] ?? 'Usuario';
$fechaGen = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 13px;
            color: #1a1a2e;
            background: #fff;
        }

        .header-pdf {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            padding: 28px 36px 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 4px solid #10b981;
        }

        .header-pdf h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .header-pdf .meta {
            font-size: 11px;
            opacity: 0.65;
        }

        .logo-text {
            font-size: 13px;
            font-weight: 800;
            color: #10b981;
            letter-spacing: 1px;
            text-align: right;
        }

        .cafra-tag {
            display: block;
            margin-top: 4px;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.3px;
            color: #cbd5e1;
            text-decoration: none;
            opacity: 0.85;
        }

        .content {
            padding: 24px 36px;
        }

        .summary-row {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .summary-box {
            flex: 1;
            min-width: 140px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            background: #f8fafc;
        }

        .summary-box .label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }

        .summary-box .value {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2px;
        }

        .summary-box .value.danger {
            color: #dc2626;
        }

        .summary-box .value.success {
            color: #10b981;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            font-size: 12px;
        }

        thead th {
            background: #0f172a;
            color: white;
            padding: 9px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: 0.3px;
        }

        tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        tbody td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-green {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-blue {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-yellow {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-purple {
            background: #ede9fe;
            color: #5b21b6;
        }

        .footer-pdf {
            margin-top: 30px;
            padding: 14px 36px;
            border-top: 1px solid #e2e8f0;
            font-size: 10px;
            color: #94a3b8;
            display: flex;
            justify-content: space-between;
        }

        .cafra-bar {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-top: 3px solid #10b981;
            padding: 14px 36px;
            text-align: center;
        }

        .cafra-bar a {
            display: block;
            color: #10b981;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .cafra-bar a strong {
            color: #ffffff;
        }

        .cafra-bar span {
            display: block;
            margin-top: 3px;
            font-size: 10px;
            color: #94a3b8;
        }

        .print-bar {
            background: #10b981;
            padding: 10px 36px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .print-bar button {
            background: white;
            color: #10b981;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            font-size: 13px;
        }

        .print-bar span {
            color: white;
            font-size: 12px;
            opacity: 0.9;
        }

        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .print-bar {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <div class="print-bar">
        <button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
        <span>Usá "Guardar como PDF" en el diálogo de impresión del navegador</span>
    </div>

    <div class="header-pdf">
        <div>
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <div class="meta">Generado el <?= $fechaGen ?> &bull; Usuario: <?= htmlspecialchars($userName) ?></div>
        </div>
        <div class="logo-text">
            AGROPLANNER
            <a href="https://cafra.site" target="_blank" class="cafra-tag">Reporte generado por Cafra</a>
        </div>
    </div>

    <div class="content">

        <?php if ($tipo === 'operaciones'): ?>
            <?php
            $total_costo = array_sum(array_column($rows, 'costo_total'));
            // Mano de obra = labor pura + la porción de labor de las recetas (precio_unitario * cantidad_ha).
            // El resto (insumo, multi_insumo y la porción de insumos de las recetas) es insumos.
            $total_labor = array_sum(array_map(function ($r) {
                if ($r['tipo_componente'] === 'labor')        return (float) $r['costo_total'];
                if ($r['tipo_componente'] === 'receta_labor') return (float) $r['precio_unitario'] * (float) $r['cantidad_ha'];
                return 0;
            }, $rows));
            $total_ins = $total_costo - $total_labor;
            ?>
            <div class="summary-row">
                <div class="summary-box">
                    <div class="label">Total Registros</div>
                    <div class="value"><?= count($rows) ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Costo Total</div>
                    <div class="value danger">$<?= number_format($total_costo, 2) ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Labores</div>
                    <div class="value">$<?= number_format($total_labor, 2) ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Insumos</div>
                    <div class="value">$<?= number_format($total_ins, 2) ?></div>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Grupo</th>
                        <th>Tipo</th>
                        <th>Detalle</th>
                        <th>Lote</th>
                        <th>Campaña</th>
                        <th>Cant/Ha</th>
                        <th>Precio Unit.</th>
                        <th>Costo Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
                            <td><span
                                    class="badge badge-blue"><?= htmlspecialchars($r['grupo_descripcion'] ?: $r['grupo_gasto']) ?></span>
                            </td>
                            <td><?= $r['tipo_componente'] === 'labor' ? '👷 Labor' : '📦 Insumo' ?></td>
                            <td><?= htmlspecialchars($r['tipo_componente'] === 'labor' ? ($r['proveedor_servicio'] ?? '—') : ($r['insumo'] ?? '—')) ?>
                            </td>
                            <td><?= htmlspecialchars($r['lote']) ?></td>
                            <td><?= htmlspecialchars($r['campania_operacion'] ?? '—') ?></td>
                            <td><?= number_format((float) $r['cantidad_ha'], 4) ?></td>
                            <td>$<?= number_format((float) $r['precio_unitario'], 2) ?></td>
                            <td style="font-weight:700;color:#dc2626;">-$<?= number_format((float) $r['costo_total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:20px;color:#94a3b8;">Sin registros</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($tipo === 'alquileres'): ?>
            <?php
            $total_usd = array_sum(array_map(fn($r) => $r['moneda'] === 'USD' ? (float) $r['monto_pagado'] : 0, $rows));
            $total_ars = array_sum(array_map(fn($r) => $r['moneda'] === 'ARS' ? (float) $r['monto_pagado'] : 0, $rows));
            ?>
            <div class="summary-row">
                <div class="summary-box">
                    <div class="label">Total Pagos</div>
                    <div class="value"><?= count($rows) ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Total USD</div>
                    <div class="value danger">$<?= number_format($total_usd, 2) ?></div>
                </div>
                <?php if ($total_ars > 0): ?>
                    <div class="summary-box">
                        <div class="label">Total ARS</div>
                        <div class="value danger">$<?= number_format($total_ars, 0, ',', '.') ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Nivel</th>
                        <th>Imputación</th>
                        <th>Campaña</th>
                        <th>Monto</th>
                        <th>Moneda</th>
                        <th>Notas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $niv = $r['nivel_imputacion'] ?? 'lote';
                        $cls = $niv === 'cultivo' ? 'badge-green' : ($niv === 'campania' ? 'badge-yellow' : 'badge-blue');
                        ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($r['fecha_pago'])) ?></td>
                            <td><span class="badge <?= $cls ?>"><?= ucfirst($niv) ?></span></td>
                            <td><?= htmlspecialchars($r['lote_nombre'] ?? ($r['cultivo_nombre'] ?? 'Global')) ?><?= !empty($r['cultivo_nombre']) ? ' › ' . htmlspecialchars($r['cultivo_nombre']) : '' ?>
                            </td>
                            <td><?= htmlspecialchars($r['campania'] ?? '—') ?></td>
                            <td style="font-weight:700;color:#dc2626;">-$<?= number_format((float) $r['monto_pagado'], 2) ?></td>
                            <td><?= htmlspecialchars($r['moneda']) ?></td>
                            <td style="color:#64748b;font-size:11px;"><?= htmlspecialchars($r['notas'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;padding:20px;color:#94a3b8;">Sin registros</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($tipo === 'insumos'): ?>
            <?php
            $valor_total = array_sum(array_map(fn($r) => (float) ($r['stock_actual'] ?? 0) * (float) $r['precio_estimado_usd'], $rows));
            $vencidos = count(array_filter($rows, fn($r) => !empty($r['fecha_vencimiento']) && $r['fecha_vencimiento'] < date('Y-m-d')));
            ?>
            <div class="summary-row">
                <div class="summary-box">
                    <div class="label">Total Productos</div>
                    <div class="value"><?= count($rows) ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Valor Total Est.</div>
                    <div class="value success">$<?= number_format($valor_total, 2) ?> USD</div>
                </div>
                <?php if ($vencidos > 0): ?>
                    <div class="summary-box">
                        <div class="label">Vencidos</div>
                        <div class="value danger"><?= $vencidos ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Tipo</th>
                        <th>Depósito</th>
                        <th>Stock</th>
                        <th>Precio Unit. (USD)</th>
                        <th>Valor Total</th>
                        <th>Vencimiento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $valor = (float) ($r['stock_actual'] ?? 0) * (float) $r['precio_estimado_usd'];
                        $fv = $r['fecha_vencimiento'] ?? null;
                        $vStyle = ($fv && $fv < date('Y-m-d')) ? 'color:#dc2626;font-weight:700;' : '';
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?= htmlspecialchars($r['nombre']) ?></td>
                            <td><span class="badge badge-purple"><?= ucfirst($r['tipo_insumo']) ?></span></td>
                            <td><?= htmlspecialchars($r['deposito'] ?? '—') ?></td>
                            <td><?= number_format((float) ($r['stock_actual'] ?? 0), 2) ?>
                                <?= htmlspecialchars($r['unidad_stock'] ?? $r['unidad_medida']) ?></td>
                            <td>$<?= number_format((float) $r['precio_estimado_usd'], 2) ?></td>
                            <td style="font-weight:600;color:#10b981;">$<?= number_format($valor, 2) ?></td>
                            <td style="<?= $vStyle ?>"><?= $fv ? date('d/m/Y', strtotime($fv)) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;padding:20px;color:#94a3b8;">Sin registros</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($tipo === 'tambo_egresos'): ?>
            <?php
            $totalARS = array_sum(array_map(fn($r) => $r['moneda'] === 'ARS' ? (float) $r['monto'] : 0, $rows));
            $totalUSD = array_sum(array_map(fn($r) => $r['moneda'] === 'USD' ? (float) $r['monto'] : 0, $rows));
            ?>
            <div class="summary-row">
                <div class="summary-box">
                    <div class="label">Total Registros</div>
                    <div class="value"><?= count($rows) ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Total ARS</div>
                    <div class="value danger">$<?= number_format($totalARS, 0, ',', '.') ?></div>
                </div>
                <?php if ($totalUSD > 0): ?>
                <div class="summary-box">
                    <div class="label">Total USD</div>
                    <div class="value danger">U$S <?= number_format($totalUSD, 2) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Categoría</th>
                        <th>Detalle (Sub/Concepto)</th>
                        <th>Cantidad</th>
                        <th>Precio Unit.</th>
                        <th>Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
                            <td><span class="badge badge-blue"><?= htmlspecialchars($r['categoria']) ?></span></td>
                            <td>
                                <b><?= htmlspecialchars($r['subcategoria'] ?? '—') ?></b><br>
                                <small style="color:#64748b;"><?= htmlspecialchars($r['concepto'] ?? '—') ?></small>
                            </td>
                            <td><?= $r['cantidad'] ? number_format((float) $r['cantidad'], 2) . ' ' . $r['unidad'] : '—' ?></td>
                            <td><?= $r['precio_unitario'] ? ($r['moneda']==='USD'?'U$S ':'$').number_format((float) $r['precio_unitario'], 2) : '—' ?></td>
                            <td style="font-weight:700;color:<?= $r['moneda']==='USD' ? '#92400e' : '#dc2626' ?>;">
                                -<?= $r['moneda']==='USD' ? 'U$S ' : '$' ?><?= number_format((float) $r['monto'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">Sin registros</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($tipo === 'dashboard'): ?>
            <?php $margen_pos = ($dash_stats['margen_neto'] ?? 0) >= 0; ?>
            <div class="summary-row">
                <div class="summary-box">
                    <div class="label">Ingresos</div>
                    <div class="value success">$<?= number_format($dash_stats['ingresos'], 2, ',', '.') ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Costos Directos</div>
                    <div class="value danger">$<?= number_format($dash_stats['costos_directos'], 2, ',', '.') ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Alquileres</div>
                    <div class="value danger">$<?= number_format($dash_stats['costos_alquiler'], 2, ',', '.') ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Margen Neto</div>
                    <div class="value <?= $margen_pos ? 'success' : 'danger' ?>">$<?= number_format($dash_stats['margen_neto'], 2, ',', '.') ?></div>
                </div>
            </div>
            <div class="summary-row">
                <div class="summary-box">
                    <div class="label">Hectáreas</div>
                    <div class="value"><?= number_format($dash_stats['hectareas'], 1, ',', '.') ?> ha</div>
                </div>
                <div class="summary-box">
                    <div class="label">Rinde Promedio</div>
                    <div class="value"><?= number_format($dash_stats['rendimiento_ha'], 0, ',', '.') ?> <span style="font-size:11px;">kg/ha</span></div>
                </div>
                <div class="summary-box">
                    <div class="label">Costo / Ha</div>
                    <div class="value danger">$<?= number_format($dash_stats['costo_por_ha'], 0, ',', '.') ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Rinde Indiferencia</div>
                    <div class="value" style="color:#d97706;"><?= number_format($dash_stats['punto_equilibrio_kg_ha'], 0, ',', '.') ?> <span style="font-size:11px;">kg/ha</span></div>
                </div>
            </div>

            <h2 style="font-size:14px; color:#0f172a; margin:18px 0 8px;">Desglose de Costos</h2>
            <div class="summary-row">
                <div class="summary-box">
                    <div class="label">Labores (Mano de Obra)</div>
                    <div class="value danger">$<?= number_format($dash_labores, 2, ',', '.') ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Insumos</div>
                    <div class="value danger">$<?= number_format($dash_insumos, 2, ',', '.') ?></div>
                </div>
                <div class="summary-box">
                    <div class="label">Alquileres</div>
                    <div class="value danger">$<?= number_format($dash_stats['costos_alquiler'], 2, ',', '.') ?></div>
                </div>
            </div>

            <h2 style="font-size:14px; color:#0f172a; margin:18px 0 8px;">Detalle por Cultivo y Lote</h2>
            <table>
                <thead>
                    <tr>
                        <th>Cultivo</th>
                        <th>Lote</th>
                        <th>Sup. (ha)</th>
                        <th>Ingreso/ha</th>
                        <th>Costo Dir./ha</th>
                        <th>Alquiler/ha</th>
                        <th>Margen/ha</th>
                        <th>Rinde Indif.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $hay_filas = false; foreach ($dash_cultivos as $especie => $d): ?>
                        <?php foreach ($d['lotes'] as $l):
                            $hay_filas = true;
                            // Las cuentas viven en includes/rentabilidad.php: las comparte
                            // con el reporte Excel para que los dos den siempre lo mismo.
                            $calc = rentabilidad_lote($l);
                            $sup         = $calc['sup'];
                            $ingreso_ha  = $calc['ingreso_ha'];
                            $costo_ha    = $calc['costo_ha'];
                            $alquiler_ha = $calc['alquiler_ha'];
                            $margen_ha   = $calc['margen_ha'];
                            $pe_kg_ha    = $calc['rinde_indiferencia_ha'];
                        ?>
                        <tr>
                            <td><span class="badge badge-green"><?= htmlspecialchars($especie) ?></span></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($l['nombre']) ?></td>
                            <td><?= number_format($sup, 1, ',', '.') ?></td>
                            <td style="color:#10b981; font-weight:600;">$<?= number_format($ingreso_ha, 0, ',', '.') ?></td>
                            <td style="color:#dc2626;">-$<?= number_format($costo_ha, 0, ',', '.') ?></td>
                            <td style="color:#dc2626;">-$<?= number_format($alquiler_ha, 0, ',', '.') ?></td>
                            <td style="font-weight:700; color:<?= $margen_ha >= 0 ? '#10b981' : '#dc2626' ?>;">$<?= number_format($margen_ha, 0, ',', '.') ?></td>
                            <td style="color:#d97706;"><?= number_format($pe_kg_ha, 0, ',', '.') ?> kg/ha</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php if (!$hay_filas): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:20px;color:#94a3b8;">Sin datos para esta campaña</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php else: ?>
            <p style="color:#dc2626; padding:20px;">Tipo de reporte no reconocido.</p>
        <?php endif; ?>

    </div>

    <div class="footer-pdf">
        <span>AgroPlanner &bull; Generado automáticamente el <?= $fechaGen ?></span>
        <span><?= $tipo === 'dashboard' ? 'Lotes incluidos' : 'Total de registros' ?>: <?= $reg_count ?? count($rows) ?></span>
    </div>

    <div class="cafra-bar">
        <a href="https://cafra.site" target="_blank">
            Reporte generado por <strong>Cafra</strong> &bull; cafra.site
        </a>
        <span>Visitá cafra.site para más información</span>
    </div>

</body>

</html>