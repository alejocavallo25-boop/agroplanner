<?php
require_once 'config/auth.php';
require_once 'config/database.php';
require_tambo();
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Egresos del Tambo';

validate_csrf();

// ─── Estructura jerárquica de categorías para Tambo ─────────────────────
$ESTRUCTURA = [
    'Alimentación' => [
        'Concentrados' => 'items',
        'Forrajes'     => 'items',
        'Minerales'    => 'items',
        'Balanceados'  => 'items',
        'Cereal / Grano' => 'items',
        'Otros'        => 'libre',
    ],
    'Veterinaria' => [
        'Sanidad'          => 'items',
        'Reproducción'     => 'items',
        'Higiene'          => 'items',
        'Rutina de ordeñe' => 'items',
        'Otros'            => 'libre',
    ],
    'Sueldos' => [
        'Ordeñe'                       => 'items',
        'Guachera'                     => 'items',
        'Preparto'                     => 'items',
        'Reproducción'                 => 'items',
        'Alimentación'                 => 'items',
        'Mantenimiento'                => 'items',
        'Administración'               => 'items',
        'Retiros de director'          => 'items',
        'Encargado'                    => 'items',
        'Aportes, seguros y aguinaldo' => 'items',
        'Otros'                        => 'libre',
    ],
    'Mantenimiento' => [
        'Maquinaria'   => 'items',
        'Equipamiento' => 'items',
        'Otros'        => 'libre',
    ],
    'Honorarios' => [
        'Veterinarios'        => 'items',
        'Contables'           => 'items',
        'Jurídicos'           => 'items',
        'Recursos Humanos'    => 'items',
        'Marketing'           => 'items',
        'Seguridad e higiene' => 'items',
        'Agrónomo'            => 'items',
        'Asesoramiento'       => 'items',
        'Otros'               => 'libre',
    ],
    'Lubricantes y combustibles' => [
        'Lubricantes'           => 'items',
        'Combustible agro'      => 'items',
        'Combustible vehículos' => 'items',
        'Otros'                 => 'libre',
    ],
    'Alquileres' => [
        'Vacas'       => 'items',
        'Campo / Lote' => 'items',
        'Desperdicio' => 'items',
        'Otros'       => 'libre',
    ],
    'Luz' => [
        'Unidad de Explotación' => 'items',
        'Otros' => 'libre',
    ],
    'Otros' => [
        'Gastos Varios' => 'items',
        'Otros' => 'libre',
    ],
];

$UNIDADES_SUGERIDAS = [
    'Alimentación'               => 'kg',
    'Veterinaria'                => 'unidad',
    'Sueldos'                    => 'unidad',
    'Mantenimiento'              => 'unidad',
    'Honorarios'                 => 'unidad',
    'Lubricantes y combustibles' => 'lt',
    'Alquileres'                 => 'unidad',
    'Luz'                        => 'unidad',
    'Otros'                      => 'unidad',
];

// ─── AJAX: crear concepto sin recargar página ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $nombre = trim($_POST['nombre_concepto'] ?? '');
    $cat    = trim($_POST['cat_concepto']    ?? '');
    $sub    = trim($_POST['sub_concepto']    ?? '');
    if (!$nombre || !$cat || !$sub) {
        echo json_encode(['ok' => false, 'msg' => 'Datos incompletos.']); exit;
    }
    try {
        $stmtCheck = $pdo->prepare("SELECT id FROM tambo_egresos_conceptos WHERE usuario_id = ? AND categoria = ? AND subcategoria = ? AND LOWER(nombre) = LOWER(?) LIMIT 1");
        $stmtCheck->execute([$usuario_id, $cat, $sub, $nombre]);
        if ($stmtCheck->fetchColumn()) {
            echo json_encode(['ok' => false, 'msg' => 'Este ítem ya se encuentra creado en esta subcategoría.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO tambo_egresos_conceptos (usuario_id, categoria, subcategoria, nombre)
            VALUES (?,?,?,?)
        ");
        $stmt->execute([$usuario_id, $cat, $sub, $nombre]);
        $id = $pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => (int)$id, 'nombre' => $nombre, 'cat' => $cat, 'sub' => $sub]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'get_egresos_mes') {
    header('Content-Type: application/json; charset=utf-8');
    $mes = $_POST['mes'] ?? '';
    if (!$mes) {
        echo json_encode(['ok' => false, 'msg' => 'Mes no válido.']); exit;
    }
    try {
        $fecha_start = $mes . '-01';
        $fecha_end = date('Y-m-t', strtotime($fecha_start));
        $stmt = $pdo->prepare("SELECT id, categoria, subcategoria, concepto, cantidad, unidad, precio_unitario, monto, moneda, notas FROM tambo_egresos WHERE usuario_id = ? AND fecha >= ? AND fecha <= ? ORDER BY fecha ASC");
        $stmt->execute([$usuario_id, $fecha_start, $fecha_end]);
        $egresos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'data' => $egresos]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ─── POST Actions normales ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_egreso') {
        $cat  = trim($_POST['categoria']    ?? '');
        $sub  = trim($_POST['subcategoria'] ?? '') ?: null;
        $conc = trim($_POST['concepto']     ?? '') ?: null;
        if ($conc === '__otros__') $conc = trim($_POST['concepto_libre'] ?? '') ?: 'Otros';
        if ($sub  === '__otros__') {
            $sub  = 'Otros';
            $conc = trim($_POST['subcat_libre'] ?? '') ?: null;
        }
        $cantidad  = !empty($_POST['cantidad'])        ? (float)str_replace(',', '.', str_replace('.', '', $_POST['cantidad'])) : null;
        $unidad    = $_POST['unidad']                  ?? 'unidad';
        $precio_u  = !empty($_POST['precio_unitario']) ? (float)str_replace(',', '.', str_replace('.', '', $_POST['precio_unitario'])) : null;
        $monto     = (float)($_POST['monto'] ?? 0);
        $moneda    = $_POST['moneda'] === 'USD' ? 'USD' : 'ARS';
        $fecha     = $_POST['fecha'] . '-01';

        $stmt = $pdo->prepare("
            INSERT INTO tambo_egresos
                (usuario_id, fecha, categoria, subcategoria, concepto, cantidad, unidad, precio_unitario, monto, moneda, notas)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $usuario_id, $fecha, $cat, $sub, $conc,
            $cantidad, $unidad, $precio_u, $monto, $moneda,
            trim($_POST['notas'] ?? '') ?: null,
        ]);
        set_flash('success', 'Egreso registrado exitosamente.');
        header('Location: tambo_egresos.php'); exit;

    } elseif ($_POST['action'] === 'edit_egreso') {
        $id_edit = (int)$_POST['id_edit'];
        $cat  = trim($_POST['categoria']    ?? '');
        $sub  = trim($_POST['subcategoria'] ?? '') ?: null;
        $conc = trim($_POST['concepto']     ?? '') ?: null;
        if ($conc === '__otros__') $conc = trim($_POST['concepto_libre'] ?? '') ?: 'Otros';
        if ($sub  === '__otros__') {
            $sub  = 'Otros';
            $conc = trim($_POST['subcat_libre'] ?? '') ?: null;
        }
        $cantidad  = !empty($_POST['cantidad'])        ? (float)str_replace(',', '.', str_replace('.', '', $_POST['cantidad'])) : null;
        $unidad    = $_POST['unidad']                  ?? 'unidad';
        $precio_u  = !empty($_POST['precio_unitario']) ? (float)str_replace(',', '.', str_replace('.', '', $_POST['precio_unitario'])) : null;
        $monto     = (float)($_POST['monto'] ?? 0);
        $moneda    = $_POST['moneda'] === 'USD' ? 'USD' : 'ARS';
        $fecha     = $_POST['fecha'] . '-01';

        $stmt = $pdo->prepare("
            UPDATE tambo_egresos SET 
                fecha=?, categoria=?, subcategoria=?, concepto=?, cantidad=?, unidad=?, precio_unitario=?, monto=?, moneda=?, notas=?
            WHERE id=? AND usuario_id=?
        ");
        $stmt->execute([
            $fecha, $cat, $sub, $conc,
            $cantidad, $unidad, $precio_u, $monto, $moneda,
            trim($_POST['notas'] ?? '') ?: null,
            $id_edit, $usuario_id
        ]);
        set_flash('success', 'Egreso actualizado exitosamente.');
        header('Location: tambo_egresos.php'); exit;

    } elseif ($_POST['action'] === 'delete_egreso') {
        $pdo->prepare("DELETE FROM tambo_egresos WHERE id=? AND usuario_id=?")
            ->execute([$_POST['id'], $usuario_id]);
        header('Location: tambo_egresos.php'); exit;
    } elseif ($_POST['action'] === 'replicar_mes') {
        $mes_destino = $_POST['mes_destino'];
        $destino_start = $mes_destino . '-01';
        $egresos_json = $_POST['egresos_data'] ?? '[]';
        $egresos_replicar = json_decode($egresos_json, true);
        
        if (is_array($egresos_replicar) && count($egresos_replicar) > 0) {
            $stmtInsert = $pdo->prepare("
                INSERT INTO tambo_egresos
                    (usuario_id, fecha, categoria, subcategoria, concepto, cantidad, unidad, precio_unitario, monto, moneda, notas)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            $count = 0;
            foreach ($egresos_replicar as $egr) {
                // Ensure values are properly casted or nullified
                $cant = !empty($egr['cantidad']) ? (float)str_replace(',', '.', str_replace('.', '', $egr['cantidad'])) : null;
                $pu = !empty($egr['precio_unitario']) ? (float)str_replace(',', '.', str_replace('.', '', $egr['precio_unitario'])) : null;
                $monto = (float)($egr['monto'] ?? 0);
                
                $stmtInsert->execute([
                    $usuario_id, $destino_start, $egr['categoria'], $egr['subcategoria'], $egr['concepto'],
                    $cant, $egr['unidad'], $pu, $monto, $egr['moneda'], $egr['notas']
                ]);
                $count++;
            }
            set_flash('success', $count . ' egresos replicados exitosamente a ' . date('M Y', strtotime($destino_start)) . '.');
        } else {
            set_flash('error', 'No se enviaron egresos para replicar.');
        }
        
        header("Location: tambo_egresos.php?mes=" . $mes_destino); exit;
    }
}

// ─── Datos para la vista ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM tambo_egresos_conceptos WHERE usuario_id=? AND activo=1 ORDER BY categoria,subcategoria,nombre");
$stmt->execute([$usuario_id]);
$conceptos_db = $stmt->fetchAll();
$conceptos_json = [];
foreach ($conceptos_db as $c) {
    $key = $c['categoria'] . '||' . $c['subcategoria'];
    $conceptos_json[$key][] = ['nombre' => $c['nombre']];
}

// ─── Listado de egresos con Paginación ────────────────────────────────────
$mes_sel       = $_GET['mes'] ?? date('Y-m');
$mes_start     = $mes_sel . '-01';
$mes_end       = date('Y-m-t', strtotime($mes_start));

$limit = 30;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Filtro por categoría desde GET
$f_cat = $_GET['categoria'] ?? 'todos';

$where = "WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?";
$params = [$usuario_id, $mes_start, $mes_end];

if ($f_cat !== 'todos' && $f_cat !== 'todas') {
    $where .= " AND categoria = ?";
    $params[] = $f_cat;
}

// 1. Contar total filtrado para paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tambo_egresos $where");
$stmtCount->execute($params);
$total_rows = (int)$stmtCount->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 2. Obtener registros paginados
$stmt = $pdo->prepare("SELECT * FROM tambo_egresos $where ORDER BY fecha DESC, id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$egresos = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT categoria, moneda, SUM(monto) as total
    FROM tambo_egresos
    WHERE usuario_id=? AND fecha >= ? AND fecha <= ?
    GROUP BY categoria, moneda ORDER BY total DESC
");
$stmt->execute([$usuario_id, $mes_start, $mes_end]);
$stats_raw = $stmt->fetchAll();

$stats_cat = [];
$total_mes_ars = 0; $total_mes_usd = 0;
foreach ($stats_raw as $r) {
    $stats_cat[$r['categoria']] = ($stats_cat[$r['categoria']] ?? 0) + $r['total'];
    if ($r['moneda'] === 'USD') $total_mes_usd += $r['total'];
    else                         $total_mes_ars += $r['total'];
}
arsort($stats_cat);

require_once 'includes/header.php';
?>

<style>
.filter-toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.grupo-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.grupo-tab {
    padding: 7px 16px; border-radius: 20px; font-size: 0.82rem; font-weight: 600;
    cursor: pointer; border: 1px solid var(--border); background: rgba(255,255,255,0.04);
    color: var(--text-muted); transition: all 0.2s; white-space: nowrap;
}
.grupo-tab:hover { border-color: #38bdf8; color: var(--text-primary); }
.grupo-tab.active { background: #38bdf8; color: #fff; border-color: #38bdf8; box-shadow: 0 0 10px rgba(56,189,248,0.3); }

/* Custom styles for Tambo to match the sky-blue theme */
.gastos-kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 10px; margin-bottom: 20px; }
.gasto-kpi {
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07);
    border-radius: 12px; padding: 12px 14px;
    transition: transform 0.2s;
}
.gasto-kpi:hover { transform: translateY(-2px); }
.gasto-kpi .gk-label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.gasto-kpi .gk-val   { font-size: 1.15rem; font-weight: 700; color: var(--text-primary); }
.gasto-kpi.total-kpi { background: rgba(56,189,248,0.06); border-color: rgba(56,189,248,0.2); }
.gasto-kpi.total-kpi .gk-val { color: #38bdf8; }

.kpi-label { font-size:.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.6px; font-weight:600; }
.kpi-value { font-size:1.5rem; font-weight:800; color:var(--text-primary); line-height:1.15; margin:4px 0 2px; }
.kpi-sub   { font-size:.78rem; color:var(--text-muted); }

.currency-toggle-container {
    display: inline-flex;
    background: rgba(0, 0, 0, 0.2);
    padding: 4px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}
.btn-currency {
    border: none;
    background: transparent;
    color: var(--text-muted);
    padding: 6px 14px;
    border-radius: 7px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}
.btn-currency:hover {
    color: var(--text-primary);
    background: rgba(255, 255, 255, 0.05);
}
.btn-currency.active {
    background: #38bdf8;
    color: white !important;
    box-shadow: 0 2px 8px rgba(56, 189, 248, 0.4);
}

.moneda-toggle { display:flex; gap:6px; margin-top: 4px; }
.moneda-btn {
    flex:1; padding:9px; border-radius:8px; border:1px solid rgba(255,255,255,.1);
    background:rgba(0,0,0,.2); color:var(--text-muted); cursor:pointer;
    font-family:inherit; font-size:.9rem; font-weight:600; transition:all .2s; text-align:center;
}
.moneda-btn.active-ars { background:rgba(16,185,129,.15); color:#34d399; border-color:rgba(16,185,129,.4); }
.moneda-btn.active-usd { background:rgba(245,158,11,.15); color:#fbbf24; border-color:rgba(245,158,11,.4); }

.calc-preview {
    background:rgba(14,165,233,.07); border:1px dashed rgba(14,165,233,.3);
    border-radius:8px; padding:11px 16px; display:none; align-items:center; justify-content:space-between; gap:12px; margin-top: 10px;
}
.calc-preview.show { display:flex; }
.calc-formula { font-size:.82rem; color:var(--text-muted); }
.calc-total   { font-size:1.15rem; font-weight:800; color:#38bdf8; }

.egr-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.7rem; font-weight:700; }
.badge-ars { background:rgba(16,185,129,.1); color:#34d399; }
.badge-usd { background:rgba(245,158,11,.1); color:#fbbf24; }

.level-divider { border:none; border-top:1px dashed rgba(255,255,255,.07); margin:14px 0; }

/* ─── Tabla Fluida Premium ─── */
.egr-table { width:100%; border-collapse:separate; border-spacing: 0; }
.egr-table thead th {
    font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:1px;
    color: #38bdf8; padding:16px; border-bottom:2px solid rgba(56, 189, 248, 0.2);
    background:rgba(56, 189, 248, 0.03); text-align: left;
}
.egr-table tbody td {
    padding:20px 16px; border-bottom:1px solid rgba(255, 255, 255, 0.03);
    font-size:.92rem; vertical-align:middle;
    transition: all 0.2s;
}
.egr-table tbody tr:hover td { background:rgba(255, 255, 255, 0.05); }

.egr-cat-badge {
    display:inline-flex; align-items:center; gap:6px;
    padding:5px 12px; border-radius:10px; font-size:.75rem; font-weight:700;
    background:rgba(56, 189, 248, 0.08); color:#38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.15);
}
.egr-detalle-main { font-weight:600; font-size:.88rem; color:var(--text-primary); line-height:1.3; }
.egr-detalle-sub  { font-size:.75rem; color:var(--text-muted); margin-top:2px; }

.egr-num-block { display:flex; flex-direction:column; gap:6px; }
.egr-num-main  { font-size:.95rem; color:var(--text-primary); font-weight: 600; }
.egr-num-sub   { font-size:.8rem; color:var(--text-muted); }

.egr-total-ars { font-weight:800; font-size:1.1rem; color:#f87171; }
.egr-total-usd { font-weight:800; font-size:1.1rem; color:#fbbf24; }

.egr-btn-action {
    display:inline-flex; align-items:center; justify-content:center;
    width:36px; height:36px; border-radius:12px; border:none;
    cursor:pointer; transition:all .2s; font-size:1rem;
}
.egr-btn-replica { background:rgba(56,189,248,.1); color:#38bdf8; border:1px solid rgba(56,189,248,.2); }
.egr-btn-replica:hover { background:rgba(56,189,248,.25); }
.egr-btn-delete  { background:rgba(239,68,68,.08); color:#f87171; border:1px solid rgba(239,68,68,.15); }
.egr-btn-delete:hover  { background:rgba(239,68,68,.2); }

/* ─── Responsive: cards en mobile ─── */
@media (max-width: 1000px) {
    .egr-table, .egr-table thead, .egr-table tbody,
    .egr-table th, .egr-table td, .egr-table tr { display:block; }
    .egr-table thead { display:none; }
    .egr-table tbody tr {
        margin-bottom:12px;
        background:rgba(255,255,255,.03);
        border:1px solid rgba(255,255,255,.07);
        border-radius:12px; overflow:hidden;
        padding:6px 0;
    }
    .egr-table tbody td {
        display:flex; justify-content:space-between; align-items:center;
        padding:10px 16px; border-bottom:1px solid rgba(255,255,255,.04);
        font-size:.88rem;
    }
    .egr-table tbody td:last-child { border-bottom:none; }
    .egr-table tbody td::before {
        content: attr(data-label);
        font-size:.7rem; font-weight:700; text-transform:uppercase;
        color:var(--text-muted); letter-spacing:.4px; flex-shrink:0; margin-right:12px;
    }
    .egr-table .col-acc { text-align:right; }
}
</style>



<?php 
$iconos_cat = [
    'Alimentación' => 'fa-utensils',
    'Veterinaria'  => 'fa-stethoscope',
    'Sueldos'      => 'fa-users',
    'Mantenimiento' => 'fa-tools',
    'Honorarios'    => 'fa-user-tie',
    'Lubricantes y combustibles' => 'fa-gas-pump',
    'Alquileres'    => 'fa-house-user',
    'Luz'           => 'fa-bolt',
    'Otros'         => 'fa-ellipsis-h'
];
$total_general_mes = $total_mes_ars; // Asumimos ARS para la matriz principal
?>

<!-- KPIs de Modo y Totales -->
<div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap:wrap; gap:10px;">
    <div style="display:flex; gap:15px;">
        <div style="display:flex; flex-direction:column;">
            <span class="kpi-label">Total Mes ARS</span>
            <span style="font-size:1.2rem; font-weight:800; color:white;" id="kpiTotal" data-val="<?= $total_mes_ars ?>">$<?= number_format($total_mes_ars, 2, ',', '.') ?></span>
        </div>
        <?php if($total_mes_usd > 0): ?>
        <div style="display:flex; flex-direction:column;">
            <span class="kpi-label">Total Mes USD</span>
            <span style="font-size:1.2rem; font-weight:800; color:#fbbf24;">U$S <?= number_format($total_mes_usd, 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="month" value="<?= $mes_sel ?>" onchange="location.href='tambo_egresos.php?mes='+this.value" style="padding: 8px 14px; border-radius: 20px; border: 1px solid var(--accent); background: rgba(16,185,129,0.1); color: white; cursor: pointer; font-weight: 500;">
        <div class="currency-toggle-container">
            <button type="button" class="btn-currency active" id="btnModeMoney" onclick="setKpiMode('money')" title="Ver en Dinero (ARS)">
                <i class="fas fa-dollar-sign"></i>
            </button>
            <button type="button" class="btn-currency" id="btnModePercent" onclick="setKpiMode('percent')" title="Ver en Porcentaje (%)">
                <i class="fas fa-percent"></i>
            </button>
        </div>
    </div>
</div>

<!-- Matriz de Categorías -->
<div class="gastos-kpi-grid" id="gastosKpiGrid">
    <?php foreach($iconos_cat as $cat => $icon): 
        $val = $stats_cat[$cat] ?? 0;
    ?>
    <div class="gasto-kpi" data-grupo="<?= htmlspecialchars($cat) ?>">
        <div class="gk-label"><i class="fas <?= $icon ?>" style="margin-right:4px;"></i> <?= htmlspecialchars($cat) ?></div>
        <div class="gk-val kpi-cat-val" data-val="<?= $val ?>">$<?= number_format($val, 2, ',', '.') ?></div>
    </div>
    <?php endforeach; ?>
    
</div>

<div class="glass-panel">
    <div class="panel-header">
        <h2 style="font-size: 1.2rem; font-weight: 500;">
            <i class="fas fa-cow" style="color: #38bdf8; margin-right: 8px;"></i>
            Registro de Egresos del Tambo
        </h2>
        <div style="display:flex; gap:8px; flex-wrap: wrap;">
            <?php
            $qs_export = '?mes=' . $mes_sel;
            if ($f_cat !== 'todos' && $f_cat !== 'todas') {
                $qs_export .= '&grupo=' . urlencode($f_cat);
            }
            ?>
            <a id="excelBtn" href="api/reporte_excel.php<?= $qs_export ?>&tipo=tambo_egresos"
               class="btn" style="background:rgba(56,189,248,0.1); border:1px solid rgba(56,189,248,0.25); color:#38bdf8; font-size:0.85rem;">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <a id="pdfBtn" href="api/reporte_pdf.php<?= $qs_export ?>&tipo=tambo_egresos" target="_blank"
               class="btn" style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#ff7b72; font-size:0.85rem;">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <button class="btn btn-primary" style="background: rgba(16,185,129,0.15); color: #34d399; border:1px solid rgba(16,185,129,0.3);" onclick="openReplicarModal()">
                <i class="fas fa-copy"></i> Replicar Mes
            </button>
            <button class="btn btn-primary" style="background: #38bdf8; box-shadow: 0 4px 12px rgba(56,189,248,0.3); border:none;" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Registrar Egreso
            </button>
        </div>
    </div>

    <!-- ===== TOOLBAR DE FILTROS ===== -->
    <div style="background:rgba(255,255,255,0.02); border-top:1px solid rgba(255,255,255,0.05); border-bottom:1px solid rgba(255,255,255,0.05); padding:16px 20px; margin: 0 -20px 20px -20px;" class="filter-toolbar">
        <div class="grupo-tabs">
            <button class="grupo-tab <?= $f_cat === 'todos' ? 'active' : '' ?>" onclick="setFiltroCat('todos')"><i class="fas fa-layer-group"></i> Todos</button>
            <?php foreach (array_keys($ESTRUCTURA) as $cat): ?>
            <button class="grupo-tab <?= $f_cat === $cat ? 'active' : '' ?>" onclick="setFiltroCat('<?= htmlspecialchars($cat) ?>')"><?= htmlspecialchars($cat) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
        <table class="egr-table">
            <thead>
                <tr>
                    <th class="col-fecha">Fecha</th>
                    <th class="col-cat">Categoría</th>
                    <th class="col-detalle">Detalle</th>
                    <th class="col-nums">Cant. / P.U.</th>
                    <th class="col-total">Total</th>
                    <th class="col-acc"></th>
                </tr>
            </thead>
            <tbody id="egresosTableBody">
            <?php if (empty($egresos)): ?>
            <tr><td colspan="6" style="text-align:center; padding:36px; color:var(--text-muted);">
                <i class="fas fa-receipt" style="font-size:2rem;opacity:.2;display:block;margin-bottom:8px;"></i> No hay registros.
            </td></tr>
            <?php else: ?>
            <?php foreach ($egresos as $e): ?>
            <tr class="egr-row" data-cat="<?= htmlspecialchars($e['categoria']) ?>">
                <td class="col-fecha" data-label="Fecha">
                    <span style="font-weight:700; font-size:.85rem;"><?= date('d/m/Y', strtotime($e['fecha'])) ?></span>
                </td>
                <td class="col-cat" data-label="Categoría">
                    <span class="egr-cat-badge"><?= htmlspecialchars($e['categoria']) ?></span>
                </td>
                <td class="col-detalle" data-label="Detalle">
                    <div class="egr-detalle-main"><?= htmlspecialchars($e['subcategoria'] ?? '—') ?></div>
                    <?php if (!empty($e['concepto'])): ?>
                    <div class="egr-detalle-sub"><?= htmlspecialchars($e['concepto']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="col-nums" data-label="Cant./P.U.">
                    <div class="egr-num-block">
                        <?php if ($e['cantidad']): ?>
                        <span class="egr-num-main"><?= number_format($e['cantidad'],2,',','.') ?> <span style="color:var(--text-muted);font-size:.78rem;"><?= htmlspecialchars($e['unidad']) ?></span></span>
                        <?php endif; ?>
                        <?php if ($e['precio_unitario']): ?>
                        <span class="egr-num-sub"><?= $e['moneda']==='USD'?'U$S ':'$' ?><?= number_format($e['precio_unitario'],2,',','.') ?>/u.</span>
                        <?php endif; ?>
                        <?php if (!$e['cantidad'] && !$e['precio_unitario']): ?>
                        <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="col-total" data-label="Total">
                    <span class="<?= $e['moneda']==='USD' ? 'egr-total-usd' : 'egr-total-ars' ?>">
                        <?= $e['moneda']==='USD' ? 'U$S' : '$' ?> <?= number_format($e['monto'],2,',','.') ?>
                    </span>
                    <span class="egr-badge <?= $e['moneda']==='USD' ? 'badge-usd' : 'badge-ars' ?>" style="display:block;margin-top:3px;width:fit-content;"><?= $e['moneda'] ?></span>
                </td>
                <td class="col-acc" data-label="Acciones" style="text-align:center;">
                    <div style="display:inline-flex; gap:5px; align-items:center; justify-content:center;">
                        <!-- Editar -->
                        <button type="button"
                            class="egr-btn-action egr-btn-replica" style="background:rgba(245,158,11,.1); color:#fbbf24; border:1px solid rgba(245,158,11,.2);"
                            title="Editar egreso"
                            onclick="editarEgreso(
                                '<?= $e['id'] ?>',
                                '<?= htmlspecialchars(addslashes($e['fecha'] ?? ''), ENT_QUOTES) ?>',
                                '<?= htmlspecialchars(addslashes($e['categoria'] ?? ''), ENT_QUOTES) ?>',
                                '<?= htmlspecialchars(addslashes($e['subcategoria'] ?? ''), ENT_QUOTES) ?>',
                                '<?= htmlspecialchars(addslashes($e['concepto'] ?? ''), ENT_QUOTES) ?>',
                                '<?= $e['cantidad'] !== null ? (float)$e['cantidad'] : '' ?>',
                                '<?= htmlspecialchars(addslashes($e['unidad'] ?? 'unidad'), ENT_QUOTES) ?>',
                                '<?= $e['precio_unitario'] !== null ? (float)$e['precio_unitario'] : '' ?>',
                                '<?= (float)$e['monto'] ?>',
                                '<?= htmlspecialchars(addslashes($e['moneda']), ENT_QUOTES) ?>',
                                '<?= htmlspecialchars(addslashes($e['notas'] ?? ''), ENT_QUOTES) ?>'
                            )">
                            <i class="fas fa-edit"></i>
                        </button>

                        <!-- Eliminar -->
                        <form method="POST" style="display:contents;" onsubmit="if(!confirm('¿Eliminar este egreso?')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_egreso">
                            <input type="hidden" name="id"     value="<?= $e['id'] ?>">
                            <button type="submit" class="egr-btn-action egr-btn-delete" title="Eliminar">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <div style="display:flex; justify-content: center; gap:10px; margin-top:20px; padding-bottom:10px;">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&categoria=<?= urlencode($f_cat) ?>&mes=<?= $mes_sel ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;"><i class="fas fa-chevron-left"></i> Anterior</a>
        <?php endif; ?>
        
        <span style="color:var(--text-muted); align-self:center; font-size:0.9rem;">Página <?= $page ?> de <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&categoria=<?= urlencode($f_cat) ?>&mes=<?= $mes_sel ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;">Siguiente <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===== MODAL: Registrar Egreso ===== -->
<div id="addEgresoModal" class="modal-wrapper">
    <div class="glass-panel modal-panel" style="max-width: 500px;">
        <h2 id="modalTitle" style="margin-bottom: 20px;"><i class="fas fa-plus-circle" id="modalTitleIcon" style="color:#38bdf8;"></i> <span id="modalTitleText">Registrar Egreso</span></h2>
        
        <form method="POST" id="formEgreso" style="display:flex; flex-direction:column; gap:14px;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="formAction" value="save_egreso">
            <input type="hidden" name="id_edit" id="formIdEdit" value="">
            <input type="hidden" name="monto"  id="hiddenMonto" required>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Fecha</label>
                    <input type="month" name="fecha" value="<?= date('Y-m') ?>" onclick="this.showPicker && this.showPicker();" required>
                </div>
                <div class="form-group">
                    <label>Categoría</label>
                    <select name="categoria" id="selCat" onchange="onCatChange()" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($ESTRUCTURA as $cat => $subs): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-grid-2">
                <!-- Subcategoría -->
                <div class="form-group" id="wrapSub" style="display:none;">
                    <label id="labelSub">Subcategoría</label>
                    <select name="subcategoria" id="selSub" onchange="onSubChange()">
                        <option value="">— Seleccionar —</option>
                    </select>
                </div>

                <!-- Concepto -->
                <div class="form-group" id="wrapConc" style="display:none;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                        <label style="margin:0;">Concepto / Ítem</label>
                        <button type="button" id="btnNuevoConc" onclick="abrirModalConcepto()" style="font-size:.7rem; color:#38bdf8; background:rgba(14,165,233,.1); border:1px solid rgba(14,165,233,.25); border-radius:6px; padding:2px 6px; cursor:pointer;"><i class="fas fa-plus"></i></button>
                    </div>
                    <select name="concepto" id="selConc" onchange="onConcChange()">
                        <option value="">— Seleccionar —</option>
                    </select>
                </div>
            </div>

            <!-- Inputs libres (condicionales) -->
            <div id="wrapLibres" style="display: contents;">
                <div class="form-group" id="wrapSubLibre" style="display:none;">
                    <label>Especificar subcategoría</label>
                    <input type="text" name="subcat_libre" id="inputSubLibre">
                </div>
                <div class="form-group" id="wrapConcLibre" style="display:none;">
                    <label>Especificá el concepto</label>
                    <input type="text" name="concepto_libre" id="inputConcLibre">
                </div>
            </div>

            <hr class="level-divider">

            <div class="form-grid-3" style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 10px;">
                <div class="form-group">
                    <label>Cantidad</label>
                    <input type="text" inputmode="decimal" class="format-number" name="cantidad" id="inputCantidad" placeholder="0" oninput="calcMonto()">
                </div>
                <div class="form-group">
                    <label>Unidad</label>
                    <select name="unidad" id="selUnidad" onchange="calcMonto()">
                        <option value="kg">kg</option>
                        <option value="lt">lt</option>
                        <option value="unidad" selected>unid.</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>P. Unitario</label>
                    <input type="text" inputmode="decimal" class="format-number" name="precio_unitario" id="inputPrecioU" placeholder="0.00" oninput="calcMonto()">
                </div>
            </div>

            <!-- Preview -->
            <div class="calc-preview" id="calcPreview">
                <span class="calc-formula" id="calcFormula">— × —</span>
                <span class="calc-total"   id="calcTotalDisp">$0</span>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Moneda</label>
                    <div class="moneda-toggle">
                        <button type="button" class="moneda-btn active-ars" id="btnArs" onclick="setMoneda('ARS')">🇦🇷 ARS</button>
                        <button type="button" class="moneda-btn"            id="btnUsd" onclick="setMoneda('USD')">🇺🇸 USD</button>
                    </div>
                    <input type="hidden" name="moneda" id="inputMoneda" value="ARS">
                </div>
                <div class="form-group">
                    <label>Monto total <span id="signoMoneda" style="color:#38bdf8;">(ARS)</span></label>
                    <input type="text" inputmode="decimal" class="format-number" id="inputMontoVisible" placeholder="0.00" oninput="syncMonto(this.value)" required>
                </div>
            </div>

            <div class="form-group">
                <label>Notas <small style="color:var(--text-muted)">(opcional)</small></label>
                <textarea name="notas" rows="2" placeholder="Observaciones..." style="padding: 8px;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                <button type="button" class="btn" onclick="closeAddModal()" style="background: rgba(255,255,255,0.1); color: white;">Cancelar</button>
                <button type="submit" id="btnGuardar" class="btn btn-primary" style="background: #38bdf8; border:none;" onclick="return validarForm()">
                    <i class="fas fa-save"></i> Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Replicar Mes -->
<div id="replicarMesModal" class="modal-wrapper" style="z-index: 9999;">
    <div class="glass-panel modal-panel" id="replicarModalContent" style="max-width: 400px; transition: max-width 0.3s ease;">
        <h2 style="margin-bottom: 20px;"><i class="fas fa-copy" style="color:#34d399;"></i> Replicar Gastos de un Mes</h2>
        
        <form method="POST" id="formReplicarMes" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="replicar_mes">
            <input type="hidden" name="egresos_data" id="egresos_data" value="[]">
            
            <!-- PASO 1: Selección de meses -->
            <div id="paso1_replicar" style="display:flex; flex-direction:column; gap:14px;">
                <div class="form-group">
                    <label>Mes de Origen (a copiar)</label>
                    <input type="month" name="mes_origen" id="mes_origen_rep" value="<?= date('Y-m', strtotime('-1 month', strtotime($mes_start))) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Mes Destino (donde se pegarán)</label>
                    <input type="month" name="mes_destino" value="<?= $mes_sel ?>" required>
                </div>
                
                <div style="background: rgba(245,158,11,0.1); border: 1px dashed rgba(245,158,11,0.3); padding: 12px; border-radius: 8px; margin-top: 10px;">
                    <p style="font-size: 0.8rem; color: #fbbf24; margin: 0;"><i class="fas fa-info-circle"></i> En el siguiente paso podrás ver y editar los gastos antes de guardarlos en el mes destino.</p>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" class="btn" onclick="closeReplicarModal()" style="background: rgba(255,255,255,0.1); color: white;">Cancelar</button>
                    <button type="button" class="btn btn-primary" style="background: #10b981; border:none;" onclick="cargarEgresosAReplicar()">
                        Cargar Gastos <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- PASO 2: Planilla Editable -->
            <div id="paso2_replicar" style="display:none; flex-direction:column; gap:14px;">
                <div style="overflow-x:auto; max-height: 50vh; overflow-y: auto;">
                    <table class="egr-table" style="width: 100%; min-width: 700px;">
                        <thead>
                            <tr>
                                <th style="width: 15%">Categoría</th>
                                <th style="width: 25%">Concepto</th>
                                <th style="width: 12%">Cantidad</th>
                                <th style="width: 15%">Precio U.</th>
                                <th style="width: 18%">Monto Total</th>
                                <th style="width: 15%; text-align:center;">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="planillaReplicarBody">
                            <!-- JS content -->
                        </tbody>
                    </table>
                </div>

                <div id="planillaPagination" style="display:flex; justify-content: space-between; align-items: center; margin-top: 10px; font-size: 0.85rem; color: var(--text-muted);">
                    <div>Total de gastos a replicar: <strong id="planillaTotalItems">0</strong></div>
                    <div style="display:flex; gap: 10px; align-items: center;">
                        <button type="button" class="btn" id="btnPrevPage" onclick="cambiarPaginaReplicar(-1)" style="padding:4px 10px; background:rgba(255,255,255,0.05);"><i class="fas fa-chevron-left"></i></button>
                        <span id="planillaPageInfo">Página 1</span>
                        <button type="button" class="btn" id="btnNextPage" onclick="cambiarPaginaReplicar(1)" style="padding:4px 10px; background:rgba(255,255,255,0.05);"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 15px;">
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn" onclick="volverPaso1Replicar()" style="background: rgba(255,255,255,0.1); color: white;"><i class="fas fa-arrow-left"></i> Volver</button>
                        <button type="button" class="btn" style="background: rgba(16,185,129,0.1); color:#34d399; border: 1px dashed rgba(16,185,129,0.3);" onclick="openAddFromReplicar()"><i class="fas fa-plus"></i> Agregar Gasto</button>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn" onclick="closeReplicarModal()" style="background: rgba(239,68,68,0.1); color: #f87171; border: 1px solid rgba(239,68,68,0.2);">Cancelar</button>
                        <button type="button" class="btn btn-primary" style="background: #10b981; border:none;" onclick="guardarReplicacion()">
                            <i class="fas fa-save"></i> Guardar Replicación
                        </button>
                    </div>
                </div>
            </div>

        </form>
    </div>
</div>

<!-- Modal Concepto Auxiliar -->
<div class="modal-wrapper" id="modalConcepto">
    <div class="glass-panel modal-panel" style="max-width: 380px;">
        <h3 style="margin:0 0 16px; font-size:1.05rem; color:#38bdf8;">Nuevo Concepto</h3>
        <p id="modalDesc" style="font-size:.84rem; color:var(--text-muted); margin-bottom:16px;">—</p>
        <div class="form-group" style="margin-bottom:16px;">
            <label>Nombre</label>
            <input type="text" id="modalNombre" placeholder="Ej: Maíz, Lavandina...">
        </div>
        <div id="modalError" style="display:none; color:#f87171; font-size:.83rem; margin-bottom:12px;"></div>
        <div style="display:flex; gap:10px;">
            <button type="button" onclick="cerrarModalConcepto()" class="btn" style="flex:1;">Cancelar</button>
            <button type="button" onclick="guardarConcepto()" class="btn btn-primary" style="flex:1; background:#0ea5e9; border:none;" id="btnModalGuardar">Guardar</button>
        </div>
    </div>
</div>

<script>
const ESTRUCTURA = <?= json_encode($ESTRUCTURA, JSON_UNESCAPED_UNICODE) ?>;
let   CONCEPTOS  = <?= json_encode($conceptos_json, JSON_UNESCAPED_UNICODE) ?>;
const UNIDADES   = <?= json_encode($UNIDADES_SUGERIDAS, JSON_UNESCAPED_UNICODE) ?>;
const CSRF       = document.querySelector('input[name=csrf_token]')?.value ?? '';

/* ───── FILTRADO POR SERVIDOR ─── */
let kpiMode = 'money';

function setKpiMode(mode) {
    kpiMode = mode;
    document.getElementById('btnModeMoney').classList.toggle('active', mode === 'money');
    document.getElementById('btnModePercent').classList.toggle('active', mode === 'percent');
    renderKpis();
}

function setFiltroCat(cat) {
    const url = new URL(window.location);
    url.searchParams.set('categoria', cat);
    url.searchParams.set('page', 1);
    window.location.href = url.href;
}

function renderKpis() {
    const total = parseFloat(document.getElementById('kpiTotal').dataset.val) || 0;
    document.querySelectorAll('.kpi-cat-val').forEach(el => {
        const val = parseFloat(el.dataset.val) || 0;
        if (kpiMode === 'money') {
            el.textContent = '$' + val.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        } else {
            const p = total > 0 ? ((val / total) * 100).toFixed(1) : '0.0';
            el.textContent = p + '%';
        }
    });
    
    /* 
    if (kpiMode === 'money') {
        document.getElementById('kpiTotal').textContent = '$' + total.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        document.getElementById('kpiTotal').textContent = '100%';
    }
    */
}

// Filtering is fully handled by the first setFiltroCat definition using window.location.href

/* ── MODAL LOGIC ── */
function openAddModal() {
    // Reset title to "new" mode
    document.getElementById('modalTitleText').textContent = 'Registrar Egreso';
    document.getElementById('modalTitleIcon').className = 'fas fa-plus-circle';
    document.getElementById('formAction').value = 'save_egreso';
    document.getElementById('formIdEdit').value = '';
    // Reset form fields
    document.getElementById('formEgreso').reset();
    ['wrapSub','wrapSubLibre','wrapConc','wrapConcLibre'].forEach(reset);
    document.getElementById('calcPreview').classList.remove('show');
    document.getElementById('hiddenMonto').value = '';
    setMoneda('ARS');
    document.getElementById('addEgresoModal').style.display = 'flex';
    document.body.classList.add('modal-open');
}
function closeAddModal() {
    document.getElementById('addEgresoModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

/* ── REPLICAR/EDITAR EGRESO ── */
async function poblarYabrirModal(isEdit, id, fecha, cat, sub, conc, cantidad, unidad, precioU, monto, moneda, notas) {
    let mesStr = fecha ? fecha.substring(0, 7) : '';
    if (isEdit) {
        document.getElementById('modalTitleText').textContent = 'Editar Egreso';
        document.getElementById('modalTitleIcon').className = 'fas fa-edit';
        document.getElementById('formAction').value = 'edit_egreso';
        document.getElementById('formIdEdit').value = id;
    } else {
        document.getElementById('modalTitleText').textContent = 'Replicar Egreso';
        document.getElementById('modalTitleIcon').className = 'fas fa-copy';
        document.getElementById('formAction').value = 'save_egreso';
        document.getElementById('formIdEdit').value = '';
    }

    document.querySelector('#formEgreso input[name="fecha"]').value = mesStr;

    // Reset dependent selects
    ['wrapSub','wrapSubLibre','wrapConc','wrapConcLibre'].forEach(reset);
    document.getElementById('calcPreview').classList.remove('show');

    // Set category
    const selCat = getEl('selCat');
    selCat.value = cat;
    if (cat) {
        // Populate subcategory options
        getEl('selUnidad').value = UNIDADES[cat] || 'unidad';
        const subs = ESTRUCTURA[cat];
        const selSub = getEl('selSub');
        for (const s of Object.keys(subs)) selSub.innerHTML += `<option value="${s}">${s}</option>`;
        getEl('wrapSub').style.display = 'block';

        // Set subcategory
        if (sub) {
            selSub.value = sub;
            if (sub === 'Otros') {
                getEl('wrapSubLibre').style.display = 'block';
            } else if (ESTRUCTURA[cat]?.[sub] === 'items') {
                // Populate concepts
                cargarConceptos(cat, sub);
                // Wait a tick for DOM update then set concept
                await new Promise(r => setTimeout(r, 50));
                if (conc) {
                    const selConc = getEl('selConc');
                    // Try to match an existing option
                    let found = false;
                    for (const opt of selConc.options) {
                        if (opt.value === conc) { found = true; break; }
                    }
                    if (found) {
                        selConc.value = conc;
                    } else {
                        // Concept may be free text, show libre field
                        selConc.value = '__otros__';
                        getEl('wrapConcLibre').style.display = 'block';
                        getEl('inputConcLibre').value = conc;
                    }
                }
            }
        }
    }

    // Set quantity, unit, unit price
    if (cantidad !== '' && cantidad !== null) {
        getEl('inputCantidad').value = formatVal(cantidad);
    }
    if (unidad) getEl('selUnidad').value = unidad;
    if (precioU !== '' && precioU !== null) {
        getEl('inputPrecioU').value = formatVal(precioU);
    }

    // Set currency and amount
    setMoneda(moneda || 'ARS');
    if (monto) {
        getEl('inputMontoVisible').value = formatVal(monto);
        getEl('hiddenMonto').value = monto;
    }

    // Set notes
    const notasEl = document.querySelector('#formEgreso textarea[name="notas"]');
    if (notasEl) notasEl.value = notas || '';

    // Recalculate preview
    calcMonto();

    // Open modal
    document.getElementById('addEgresoModal').style.display = 'flex';
    document.body.classList.add('modal-open');
}

function openReplicarModal() {
    volverPaso1Replicar(); // Asegurar que empiece en el paso 1
    document.getElementById('replicarMesModal').style.display = 'flex';
    document.body.classList.add('modal-open');
}

function closeReplicarModal() {
    document.getElementById('replicarMesModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

/* ── LÓGICA PLANILLA REPLICAR ── */
let egresosAReplicar = [];
let currentPageReplicar = 1;
const itemsPerPageReplicar = 10;

async function cargarEgresosAReplicar() {
    const mesOrigen = getEl('mes_origen_rep').value;
    if (!mesOrigen) return alert('Seleccioná un mes de origen');
    
    // Cambiar estado a cargando
    const btn = document.activeElement;
    const btnHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cargando...';
    btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('ajax', 'get_egresos_mes');
        fd.append('mes', mesOrigen);
        fd.append('csrf_token', CSRF);

        const res = await fetch('tambo_egresos.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.ok) {
            egresosAReplicar = data.data.map(item => ({
                id: item.id,
                categoria: item.categoria,
                subcategoria: item.subcategoria,
                concepto: item.concepto,
                cantidad: item.cantidad !== null ? item.cantidad : '',
                unidad: item.unidad,
                precio_unitario: item.precio_unitario !== null ? item.precio_unitario : '',
                monto: item.monto,
                moneda: item.moneda,
                notas: item.notas
            }));

            if (egresosAReplicar.length === 0) {
                alert('No se encontraron gastos en el mes de origen seleccionado.');
            } else {
                currentPageReplicar = 1;
                mostrarPaso2Replicar();
            }
        } else {
            alert('Error: ' + data.msg);
        }
    } catch (e) {
        console.error(e);
        alert('Error de conexión al cargar los gastos.');
    } finally {
        btn.innerHTML = btnHtml;
        btn.disabled = false;
    }
}

function mostrarPaso2Replicar() {
    getEl('paso1_replicar').style.display = 'none';
    getEl('paso2_replicar').style.display = 'flex';
    getEl('replicarModalContent').style.maxWidth = '900px';
    renderPlanillaPage(currentPageReplicar);
}

function volverPaso1Replicar() {
    getEl('paso2_replicar').style.display = 'none';
    getEl('paso1_replicar').style.display = 'flex';
    getEl('replicarModalContent').style.maxWidth = '400px';
}

function renderPlanillaPage(page) {
    const totalItems = egresosAReplicar.length;
    getEl('planillaTotalItems').textContent = totalItems;
    
    const totalPages = Math.ceil(totalItems / itemsPerPageReplicar) || 1;
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    currentPageReplicar = page;

    getEl('planillaPageInfo').textContent = `Página ${page} de ${totalPages}`;
    getEl('btnPrevPage').disabled = page === 1;
    getEl('btnNextPage').disabled = page === totalPages;

    const start = (page - 1) * itemsPerPageReplicar;
    const end = start + itemsPerPageReplicar;
    const items = egresosAReplicar.slice(start, end);

    const tbody = getEl('planillaReplicarBody');
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding: 20px;">No hay gastos para replicar.</td></tr>';
        return;
    }

    items.forEach((item, index) => {
        const globalIndex = start + index;
        
        let cantStr = formatVal(item.cantidad);
        let puStr = formatVal(item.precio_unitario);
        let montoStr = formatVal(item.monto);

        let catHtml = '';
        if (item.is_new) {
            catHtml = `<input type="text" placeholder="Categoría" class="rep-input" value="${item.categoria || ''}" onchange="updatePlanilla(${globalIndex}, 'categoria', this.value)" style="width:100%; padding:4px; font-size:0.8rem; margin-bottom:4px; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.2); color: white; border-radius: 4px;">`;
        } else {
            catHtml = `
                <strong>${item.categoria}</strong><br>
                <span style="color:var(--text-muted);">${item.subcategoria}</span>
            `;
        }

        let conceptoHtml = '';
        if (item.is_new) {
            conceptoHtml = `<input type="text" placeholder="Concepto" class="rep-input" value="${item.concepto || ''}" onchange="updatePlanilla(${globalIndex}, 'concepto', this.value)" style="width:100%; padding:6px 8px; font-size:0.85rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.2); color: white; border-radius: 4px;">`;
        } else {
            conceptoHtml = `<div style="font-size:0.85rem; font-weight: 500; color: var(--text-primary);">${item.concepto || '—'}</div>`;
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="font-size:0.8rem;" data-label="Categoría">
                ${catHtml}
            </td>
            <td data-label="Concepto">
                ${conceptoHtml}
            </td>
            <td data-label="Cantidad">
                <div style="display:flex; align-items:center; justify-content:flex-end; gap:4px;">
                    <input type="text" inputmode="decimal" class="rep-input format-number-planilla" value="${cantStr}" oninput="formatPlanillaNumber(this); updatePlanillaAndCalc(${globalIndex}, 'cantidad', this.value)" style="width:60px; padding:4px 8px; font-size:0.85rem; text-align:right;">
                    <span style="font-size:0.75rem; color:var(--text-muted);">${item.unidad}</span>
                </div>
            </td>
            <td data-label="Precio U.">
                <div style="display:flex; align-items:center; justify-content:flex-end; gap:4px;">
                    <span style="font-size:0.75rem; color:var(--text-muted);">${item.moneda==='USD'?'U$S':'$'}</span>
                    <input type="text" inputmode="decimal" class="rep-input format-number-planilla" value="${puStr}" oninput="formatPlanillaNumber(this); updatePlanillaAndCalc(${globalIndex}, 'precio_unitario', this.value)" style="width:80px; padding:4px 8px; font-size:0.85rem; text-align:right;">
                </div>
            </td>
            <td data-label="Monto Total">
                <div style="display:flex; align-items:center; justify-content:flex-end; gap:4px;">
                    <span style="font-size:0.75rem; color:var(--text-muted);">${item.moneda==='USD'?'U$S':'$'}</span>
                    <input type="text" inputmode="decimal" class="rep-input format-number-planilla" id="monto_rep_${globalIndex}" value="${montoStr}" oninput="formatPlanillaNumber(this); updatePlanilla(${globalIndex}, 'monto', this.value)" style="width:90px; padding:4px 8px; font-size:0.85rem; font-weight:bold; text-align:right;">
                </div>
            </td>
            <td style="text-align:center;" data-label="Acción">
                <button type="button" class="egr-btn-action egr-btn-delete" title="No replicar" onclick="eliminarFilaReplicar(${globalIndex})" style="width:28px; height:28px; font-size:0.8rem;">
                    <i class="fas fa-times"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

let isAddingFromReplicar = false;

function openAddFromReplicar() {
    isAddingFromReplicar = true;
    
    document.getElementById('formEgreso').reset();
    document.getElementById('formAction').value = 'save_egreso';
    document.getElementById('modalTitleText').innerText = 'Agregar Gasto (Replicación)';
    
    // Auto-completar la fecha con el mes destino elegido
    const mesDestinoInput = document.querySelector('input[name="mes_destino"]');
    if (mesDestinoInput && mesDestinoInput.value) {
        const fechaInput = document.querySelector('#formEgreso input[name="fecha"]');
        if (fechaInput) fechaInput.value = mesDestinoInput.value;
    }
    
    const sLibre = document.getElementById('subcatLibreContainer');
    if (sLibre) sLibre.style.display = 'none';
    const cLibre = document.getElementById('conceptoLibreContainer');
    if (cLibre) cLibre.style.display = 'none';
    
    // Forzar el recálculo visual a 0
    document.getElementById('calcTotalDisp').innerText = '$0';
    document.getElementById('hiddenMonto').value = 0;

    const modal = document.getElementById('addEgresoModal');
    modal.style.display = 'flex';
    modal.style.zIndex = '10000'; // Ensure it's above the replicate modal (9999)
}

function cambiarPaginaReplicar(delta) {
    renderPlanillaPage(currentPageReplicar + delta);
}

function updatePlanilla(index, field, value) {
    if (field === 'monto') {
        egresosAReplicar[index][field] = unformatVal(value);
    } else {
        egresosAReplicar[index][field] = value;
    }
}

function updatePlanillaAndCalc(index, field, value) {
    egresosAReplicar[index][field] = unformatVal(value);
    
    // Recalcular monto si cantidad y precio unitario existen
    const cant = parseFloat(egresosAReplicar[index].cantidad) || 0;
    const pu = parseFloat(egresosAReplicar[index].precio_unitario) || 0;
    
    if (cant > 0 && pu > 0) {
        const nuevoMonto = (cant * pu).toFixed(2);
        egresosAReplicar[index].monto = nuevoMonto;
        
        const inputMonto = document.getElementById(`monto_rep_${index}`);
        if (inputMonto) {
            inputMonto.value = formatVal(nuevoMonto);
        }
    }
}

function eliminarFilaReplicar(index) {
    egresosAReplicar.splice(index, 1);
    
    // Si la página actual queda vacía, retroceder una página
    const totalPages = Math.ceil(egresosAReplicar.length / itemsPerPageReplicar) || 1;
    if (currentPageReplicar > totalPages) {
        currentPageReplicar = totalPages;
    }
    
    renderPlanillaPage(currentPageReplicar);
}

function guardarReplicacion() {
    if (egresosAReplicar.length === 0) {
        alert('No hay gastos para replicar.');
        return;
    }
    
    // Setear json en input hidden
    getEl('egresos_data').value = JSON.stringify(egresosAReplicar);
    
    // Submit form
    getEl('formReplicarMes').submit();
}

// Re-utilizable number formatter for dynamically created inputs
function formatPlanillaNumber(input) {
    let cursor = input.selectionStart || 0;
    let oldLength = input.value.length;
    
    let val = input.value.replace(/[^0-9,]/g, '');
    let parts = val.split(',');
    if (parts.length > 2) parts = [parts[0], parts.slice(1).join('')];
    if (parts[0]) parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    let newVal = parts.join(',');
    
    input.value = newVal;
    
    let newLength = newVal.length;
    cursor += (newLength - oldLength);
    if (cursor < 0) cursor = 0;
    try { input.setSelectionRange(cursor, cursor); } catch(err) {}
}


function editarEgreso(id, fecha, cat, sub, conc, cantidad, unidad, precioU, monto, moneda, notas) {
    poblarYabrirModal(true, id, fecha, cat, sub, conc, cantidad, unidad, precioU, monto, moneda, notas);
}

/* ── FORM LOGIC ── */
let monedaActual = 'ARS';
function setMoneda(m) {
    monedaActual = m;
    document.getElementById('inputMoneda').value = m;
    document.getElementById('signoMoneda').textContent = '('+m+')';
    document.getElementById('btnArs').className = 'moneda-btn' + (m==='ARS' ? ' active-ars' : '');
    document.getElementById('btnUsd').className = 'moneda-btn' + (m==='USD' ? ' active-usd' : '');
    calcMonto();
}

function unformatVal(val) {
    if (typeof val === 'number') return val;
    if (!val) return 0;
    val = val.toString().replace(/\./g, '').replace(/,/g, '.');
    return parseFloat(val) || 0;
}

// Intercept formEgreso submission for Replicar scenario
document.getElementById('formEgreso').addEventListener('submit', function(e) {
    if (isAddingFromReplicar) {
        e.preventDefault();
        
        const fd = new FormData(this);
        let subcat = fd.get('subcategoria');
        if (subcat === '__otros__') subcat = fd.get('subcat_libre');
        let conc = fd.get('concepto');
        if (conc === '__otros__') conc = fd.get('concepto_libre');
        
        let cant = unformatVal(fd.get('cantidad'));
        let pu = unformatVal(fd.get('precio_unitario'));
        let monto = unformatVal(document.getElementById('hiddenMonto').value);
        
        egresosAReplicar.push({
            categoria: fd.get('categoria') || '',
            subcategoria: subcat || '',
            concepto: conc || '',
            cantidad: cant,
            unidad: fd.get('unidad') || 'un',
            precio_unitario: pu,
            monto: monto,
            moneda: fd.get('moneda') || 'ARS',
            is_new: true // keeps the row editable in the spreadsheet if needed
        });
        
        const totalPages = Math.ceil(egresosAReplicar.length / itemsPerPageReplicar) || 1;
        // Se mantiene en la página actual en lugar de ir al fondo
        renderPlanillaPage(currentPageReplicar);
        
        // Mostrar notificación rápida de 1 segundo
        let t = document.createElement('div');
        t.innerHTML = '<i class="fas fa-check-circle"></i> Gasto agregado';
        t.style.position = 'fixed';
        t.style.bottom = '20px';
        t.style.right = '20px';
        t.style.background = '#10b981';
        t.style.color = 'white';
        t.style.padding = '12px 24px';
        t.style.borderRadius = '8px';
        t.style.fontWeight = 'bold';
        t.style.zIndex = '99999';
        t.style.boxShadow = '0 4px 12px rgba(16,185,129,0.4)';
        t.style.transition = 'opacity 0.3s';
        t.style.display = 'flex';
        t.style.alignItems = 'center';
        t.style.gap = '8px';
        document.body.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; }, 1000);
        setTimeout(() => { document.body.removeChild(t); }, 1300);
        
        document.getElementById('addEgresoModal').style.display = 'none';
        isAddingFromReplicar = false;
        
        const b = this.querySelector('button[type=submit]');
        if (b) {
            b.disabled = false;
            b.innerHTML = 'Guardar Egreso';
        }
    }
});

function formatVal(val) {
    if (!val && val !== 0) return '';
    let parts = val.toString().split('.');
    if (parts[0]) parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    return parts.join(',');
}

document.querySelectorAll('.format-number').forEach(input => {
    input.addEventListener('input', function(e) {
        let cursor = this.selectionStart || 0;
        let oldLength = this.value.length;
        
        let val = this.value.replace(/[^0-9,]/g, '');
        let parts = val.split(',');
        if (parts.length > 2) parts = [parts[0], parts.slice(1).join('')];
        if (parts[0]) parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        let newVal = parts.join(',');
        
        this.value = newVal;
        
        let newLength = newVal.length;
        cursor += (newLength - oldLength);
        if (cursor < 0) cursor = 0;
        try { this.setSelectionRange(cursor, cursor); } catch(err) {}
        
        if (this.id === 'inputCantidad' || this.id === 'inputPrecioU') {
            calcMonto();
        } else if (this.id === 'inputMontoVisible') {
            syncMonto(this.value);
        }
    });
});

function calcMonto() {
    const c = unformatVal(document.getElementById('inputCantidad').value) || 0;
    const p = unformatVal(document.getElementById('inputPrecioU').value)  || 0;
    const s = monedaActual === 'USD' ? 'U$S ' : '$';
    if (c > 0 && p > 0) {
        const t = (c * p).toFixed(2);
        document.getElementById('calcFormula').textContent = `${formatVal(c)} × ${s}${formatVal(p)}`;
        document.getElementById('calcTotalDisp').textContent = s + formatVal(t);
        document.getElementById('calcPreview').classList.add('show');
        document.getElementById('inputMontoVisible').value = formatVal(t);
        document.getElementById('hiddenMonto').value = t;
    } else {
        document.getElementById('calcPreview').classList.remove('show');
    }
}
function syncMonto(v) { document.getElementById('hiddenMonto').value = unformatVal(v); }

function getEl(id) { return document.getElementById(id); }
function reset(id) {
    const el = getEl(id); el.style.display = 'none';
    el.querySelectorAll('select,input').forEach(i => { if(i.tagName==='SELECT')i.innerHTML='<option value="">— Seleccionar —</option>'; else i.value=''; });
}

function onCatChange() {
    const cat = getEl('selCat').value;
    ['wrapSub','wrapSubLibre','wrapConc','wrapConcLibre'].forEach(reset);
    if (!cat) return;
    getEl('selUnidad').value = UNIDADES[cat] || 'unidad';
    const subs = ESTRUCTURA[cat];
    const sel  = getEl('selSub');
    for (const s of Object.keys(subs)) sel.innerHTML += `<option value="${s}">${s}</option>`;
    getEl('wrapSub').style.display='block';
}

function onSubChange() {
    const cat = getEl('selCat').value, sub = getEl('selSub').value;
    ['wrapSubLibre','wrapConc','wrapConcLibre'].forEach(reset);
    if (!sub) return;
    if (sub==='Otros') { getEl('wrapSubLibre').style.display='block'; return; }
    if (ESTRUCTURA[cat]?.[sub] === 'items') cargarConceptos(cat,sub);
}

function cargarConceptos(cat,sub) {
    const key = cat+'||'+sub, items = CONCEPTOS[key] || [], sel = getEl('selConc');
    sel.innerHTML = '<option value="">— Seleccionar —</option>';
    items.forEach(i => sel.innerHTML += `<option value="${i.nombre}">${i.nombre}</option>`);
    sel.innerHTML += '<option value="__otros__">Otros / Crear</option>';
    getEl('wrapConc').style.display='block';
}

function onConcChange() {
    getEl('wrapConcLibre').style.display = getEl('selConc').value === '__otros__' ? 'block' : 'none';
}

/* ── MODAL CONCEPTO LOGIC ── */
function abrirModalConcepto() {
    const cat = getEl('selCat').value, sub = getEl('selSub').value;
    if(!cat || !sub) { alert('Seleccioná cat/subcat'); return; }
    getEl('modalDesc').textContent = `Agregando a: ${cat} › ${sub}`;
    getEl('modalNombre').value = ''; getEl('modalError').style.display='none';
    getEl('modalConcepto').classList.add('open');
    getEl('modalConcepto').style.display = 'flex';
    getEl('modalConcepto').style.zIndex = '10005'; // Asegurar que abra por encima de cualquier otro modal
}
function cerrarModalConcepto() { 
    getEl('modalConcepto').classList.remove('open'); 
    getEl('modalConcepto').style.display = 'none';
}

async function guardarConcepto() {
    const n = getEl('modalNombre').value.trim(), c = getEl('selCat').value, s = getEl('selSub').value;
    if(!n) return;
    try {
        const fd = new FormData(); fd.append('ajax','1'); fd.append('nombre_concepto',n); fd.append('cat_concepto',c); fd.append('sub_concepto',s); fd.append('csrf_token',CSRF);
        const res = await fetch('tambo_egresos.php', {method:'POST', body:fd});
        const data = await res.json();
        if(data.ok) {
            const key = c+'||'+s; if(!CONCEPTOS[key]) CONCEPTOS[key]=[]; CONCEPTOS[key].push({nombre:n});
            cargarConceptos(c,s); setTimeout(()=>getEl('selConc').value=n,100); cerrarModalConcepto();
        } else { getEl('modalError').textContent=data.msg; getEl('modalError').style.display='block'; }
    } catch(e) { alert('Error de conexión'); }
}

function validarForm() { if(!getEl('hiddenMonto').value && !getEl('inputMontoVisible').value) { alert('Falta monto'); return false; } return true; }

// Close modals when clicking outside
window.onclick = function(event) {
    const m1 = document.getElementById('addEgresoModal');
    const m2 = document.getElementById('modalConcepto');
    if (event.target == m1) closeAddModal();
    if (event.target == m2) cerrarModalConcepto();
}
</script>

<?php require_once 'includes/footer.php'; ?>
