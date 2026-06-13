<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
require_once 'includes/cultivos.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Registro de Costos y Labores';

validate_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ─── Shared logic: parse cultivo info ───────────────────────────────────
    $lote_id     = $_POST['lote_id'] ?? null;
    $cultivo_info = !empty($_POST['form_cultivo']) ? $_POST['form_cultivo'] : null;
    $campania = $cultivo = null;
    if ($cultivo_info) {
        $partes = explode(' | ', $cultivo_info);
        if (count($partes) === 2) { $campania = trim($partes[0]); $cultivo = trim($partes[1]); }
        else                      { $cultivo = trim($cultivo_info); }
    }
    $grupo      = $_POST['grupo_gasto'] ?? 'otros';
    $grupo_desc = ($grupo === 'otros' && !empty($_POST['grupo_descripcion'])) ? trim($_POST['grupo_descripcion']) : null;
    $tipo_comp  = $_POST['tipo_componente'] ?? 'labor';
    $fecha      = $_POST['fecha'] ?? date('Y-m-d');

    // Cultivo canónico (find-or-create). El texto se sigue guardando como snapshot.
    $cultivo_id = cultivo_resolve($pdo, $usuario_id, $lote_id, $cultivo, $campania);

    // Superficie del lote
    $stmtSup = $pdo->prepare("SELECT superficie FROM lotes WHERE id = ?");
    $stmtSup->execute([$lote_id]);
    $sup = (float)($stmtSup->fetchColumn() ?: 0);
    if ($sup <= 0) throw new Exception("El lote no tiene superficie válida.");

    $modo_calculo = $_POST['modo_calculo'] ?? 'ha';
    $factor_division = ($modo_calculo === 'total' && $sup > 0) ? $sup : 1;

    $costo_total = 0;
    $op_id = null;
    $proveedor = null;
    $cant_ha = 0;
    $precio_u = 0;
    $cargas = null;
    
    // Si es solo insumo, se procesa como multi_insumo internamente para soportar la tabla operacion_insumos
    $tipo_comp_db = ($tipo_comp === 'insumo') ? 'multi_insumo' : $tipo_comp;

    if ($tipo_comp === 'labor' || $tipo_comp === 'receta_labor') {
        $cant_ha   = (float)($_POST['cantidad_ha'] ?? 0);
        $precio_u  = (float)($_POST['precio_unitario'] ?? 0);
        $proveedor = $_POST['proveedor_servicio'] ?? '';
        $costo_total += $precio_u * $cant_ha;
        
        if ($tipo_comp === 'receta_labor') {
            $cargas = !empty($_POST['cargas']) ? (int)$_POST['cargas'] : null;
        }
    }
    
    if ($tipo_comp === 'insumo' || $tipo_comp === 'receta_labor') {
        if (isset($_POST['insumo_id']) && is_array($_POST['insumo_id'])) {
            for ($i = 0; $i < count($_POST['insumo_id']); $i++) {
                $ins_id = $_POST['insumo_id'][$i];
                $nom_lib= $_POST['nombre_libre_ins'][$i] ?? '';
                if (!$ins_id && trim($nom_lib) === '') continue; // Fila vacía
                
                $c = (float)$_POST['cantidad_ha_ins'][$i];
                $p = (float)$_POST['precio_unitario_ins'][$i];
                $costo_total += ($c * $p * $sup);
            }
        }
    }

    $pdo->beginTransaction();
    try {
        if ($_POST['action'] === 'add') {
            $stmt = $pdo->prepare("INSERT INTO operaciones (usuario_id, lote_id, cultivo_id, grupo_gasto, grupo_descripcion, tipo_componente, insumo_id, proveedor_servicio, cantidad_ha, precio_unitario, costo_total, fecha, campania_operacion, cultivo_operacion, cargas) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $lote_id, $cultivo_id, $grupo, $grupo_desc, $tipo_comp_db, $proveedor, $cant_ha, $precio_u, $costo_total, $fecha, $campania, $cultivo, $cargas]);
            $op_id = $pdo->lastInsertId();

            if (($tipo_comp === 'insumo' || $tipo_comp === 'receta_labor') && isset($_POST['insumo_id']) && is_array($_POST['insumo_id'])) {
                $stmtInsertChild = $pdo->prepare("INSERT INTO operacion_insumos (operacion_id, insumo_id, nombre_libre, cantidad_ha, precio_unitario) VALUES (?, ?, ?, ?, ?)");
                $stmtUpdateStock = $pdo->prepare("UPDATE insumos SET stock_actual = GREATEST(0, stock_actual - ?), estado = IF(stock_actual <= 0, 'inactivo', estado) WHERE id = ? AND usuario_id = ?");
                
                for ($i = 0; $i < count($_POST['insumo_id']); $i++) {
                    $ins_id = $_POST['insumo_id'][$i];
                    $nom_lib= $_POST['nombre_libre_ins'][$i] ?? null;
                    if (!$ins_id && trim($nom_lib) === '') continue;
                    
                    $real_ins_id = ($ins_id && $ins_id !== 'manual') ? $ins_id : null;
                    $real_nom = ($ins_id === 'manual') ? trim($nom_lib) : null;
                    
                    $c = (float)$_POST['cantidad_ha_ins'][$i];
                    $p = (float)$_POST['precio_unitario_ins'][$i];
                    
                    $stmtInsertChild->execute([$op_id, $real_ins_id, $real_nom, $c, $p]);
                    
                    if ($real_ins_id) {
                        $cant_total = $c * $sup;
                        $stmtUpdateStock->execute([$cant_total, $real_ins_id, $usuario_id]);
                    }
                }
            }

        } elseif ($_POST['action'] === 'edit') {
            $id = (int)$_POST['id'];

            // Restaurar stock viejo
            $stmtOld = $pdo->prepare("SELECT tipo_componente, insumo_id, cantidad_ha, lote_id FROM operaciones WHERE id = ? AND usuario_id = ?");
            $stmtOld->execute([$id, $usuario_id]);
            $old = $stmtOld->fetch();
            if ($old) {
                $stmtSupOld = $pdo->prepare("SELECT superficie FROM lotes WHERE id = ?");
                $stmtSupOld->execute([$old['lote_id']]);
                $supOld = (float)($stmtSupOld->fetchColumn() ?: 0);

                if ($old['tipo_componente'] === 'insumo' && $old['insumo_id']) {
                    $cantOld = (float)$old['cantidad_ha'] * $supOld;
                    $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?")
                        ->execute([$cantOld, $old['insumo_id'], $usuario_id]);
                }

                $stmtHijos = $pdo->prepare("SELECT insumo_id, cantidad_ha FROM operacion_insumos WHERE operacion_id = ?");
                $stmtHijos->execute([$id]);
                $stmtRestore = $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?");
                foreach ($stmtHijos->fetchAll() as $h) {
                    if ($h['insumo_id']) {
                        $cantRestore = (float)$h['cantidad_ha'] * $supOld;
                        $stmtRestore->execute([$cantRestore, $h['insumo_id'], $usuario_id]);
                    }
                }
            }

            // Borrar hijos
            $pdo->prepare("DELETE FROM operacion_insumos WHERE operacion_id = ?")->execute([$id]);

            // Actualizar padre
            $stmt = $pdo->prepare("UPDATE operaciones SET lote_id=?, cultivo_id=?, grupo_gasto=?, grupo_descripcion=?, tipo_componente=?, insumo_id=NULL, proveedor_servicio=?, cantidad_ha=?, precio_unitario=?, costo_total=?, fecha=?, campania_operacion=?, cultivo_operacion=?, cargas=? WHERE id=? AND usuario_id=?");
            $stmt->execute([$lote_id, $cultivo_id, $grupo, $grupo_desc, $tipo_comp_db, $proveedor, $cant_ha, $precio_u, $costo_total, $fecha, $campania, $cultivo, $cargas, $id, $usuario_id]);

            // Insertar hijos nuevos
            if (($tipo_comp === 'insumo' || $tipo_comp === 'receta_labor') && isset($_POST['insumo_id']) && is_array($_POST['insumo_id'])) {
                $stmtInsertChild = $pdo->prepare("INSERT INTO operacion_insumos (operacion_id, insumo_id, nombre_libre, cantidad_ha, precio_unitario) VALUES (?, ?, ?, ?, ?)");
                $stmtUpdateStock = $pdo->prepare("UPDATE insumos SET stock_actual = GREATEST(0, stock_actual - ?), estado = IF(stock_actual <= 0, 'inactivo', estado) WHERE id = ? AND usuario_id = ?");
                
                for ($i = 0; $i < count($_POST['insumo_id']); $i++) {
                    $ins_id = $_POST['insumo_id'][$i];
                    $nom_lib= $_POST['nombre_libre_ins'][$i] ?? null;
                    if (!$ins_id && trim($nom_lib) === '') continue;
                    
                    $real_ins_id = ($ins_id && $ins_id !== 'manual') ? $ins_id : null;
                    $real_nom = ($ins_id === 'manual') ? trim($nom_lib) : null;
                    
                    $c = (float)$_POST['cantidad_ha_ins'][$i];
                    $p = (float)$_POST['precio_unitario_ins'][$i];
                    
                    $stmtInsertChild->execute([$id, $real_ins_id, $real_nom, $c, $p]);
                    
                    if ($real_ins_id) {
                        $cant_total = $c * $sup;
                        $stmtUpdateStock->execute([$cant_total, $real_ins_id, $usuario_id]);
                    }
                }
            }

        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            $stmtDel = $pdo->prepare("SELECT tipo_componente, insumo_id, cantidad_ha, lote_id FROM operaciones WHERE id = ? AND usuario_id = ?");
            $stmtDel->execute([$id, $usuario_id]);
            $del = $stmtDel->fetch();
            if ($del) {
                $stmtDelSup = $pdo->prepare("SELECT superficie FROM lotes WHERE id = ?");
                $stmtDelSup->execute([$del['lote_id']]);
                $supDel = (float)($stmtDelSup->fetchColumn() ?: 0);

                if ($del['tipo_componente'] === 'insumo' && $del['insumo_id']) {
                    $cantRestore = (float)$del['cantidad_ha'] * $supDel;
                    $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?")
                        ->execute([$cantRestore, $del['insumo_id'], $usuario_id]);
                }
                $stmtHijos = $pdo->prepare("SELECT insumo_id, cantidad_ha FROM operacion_insumos WHERE operacion_id = ?");
                $stmtHijos->execute([$id]);
                $stmtRestore = $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?");
                foreach ($stmtHijos->fetchAll() as $h) {
                    if ($h['insumo_id']) {
                        $cantRestore = (float)$h['cantidad_ha'] * $supDel;
                        $stmtRestore->execute([$cantRestore, $h['insumo_id'], $usuario_id]);
                    }
                }
            }
            $pdo->prepare("DELETE FROM operaciones WHERE id = ? AND usuario_id = ?")->execute([$id, $usuario_id]);
        }
        $pdo->commit();
        header("Location: operaciones.php"); exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}

// ─── Data para formulario ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, nombre, superficie, campania, cultivo_actual FROM lotes WHERE usuario_id = ? ORDER BY nombre");
$stmt->execute([$usuario_id]);
$lotes = $stmt->fetchAll();
$lotes_json = json_encode($lotes);

$stmt = $pdo->prepare("SELECT lote_id, ciclo as campania, nombre as cultivo FROM cultivos WHERE usuario_id = ? ORDER BY ciclo DESC");
$stmt->execute([$usuario_id]);
$cultivos_adicionales = $stmt->fetchAll(PDO::FETCH_ASSOC);
$cultivos_json = json_encode($cultivos_adicionales);


$stmt = $pdo->prepare("SELECT id, nombre, tipo_insumo, unidad_medida, stock_actual, precio_estimado_usd FROM insumos WHERE usuario_id = ? AND estado = 'activo' ORDER BY nombre");
$stmt->execute([$usuario_id]);
$insumos = $stmt->fetchAll();
$insumos_json = json_encode($insumos);

// ─── Listado de operaciones con Paginación ────────────────────────────────
$limit = 30;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// 2. Obtener campañas únicas registradas en operaciones para el filtro
$stmtCamp = $pdo->prepare("SELECT DISTINCT campania_operacion FROM operaciones WHERE usuario_id = ? AND campania_operacion IS NOT NULL AND campania_operacion != '' ORDER BY campania_operacion DESC");
$stmtCamp->execute([$usuario_id]);
$campanias_disponibles = $stmtCamp->fetchAll(PDO::FETCH_COLUMN);

// Filtros desde GET
$f_grupo = $_GET['grupo'] ?? 'todos';
$f_lote  = $_GET['lote_id'] ?? 'todos';
$f_camp  = $_GET['campania'] ?? 'todos';

$where = "WHERE o.usuario_id = ?";
$params = [$usuario_id];

if ($f_grupo !== 'todos') {
    $where .= " AND o.grupo_gasto = ?";
    $params[] = $f_grupo;
}
if ($f_lote !== 'todos') {
    $where .= " AND o.lote_id = ?";
    $params[] = $f_lote;
}
if ($f_camp !== 'todos') {
    $where .= " AND o.campania_operacion = ?";
    $params[] = $f_camp;
}

// 1. Contar total para paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM operaciones o $where");
$stmtCount->execute($params);
$total_rows = (int)$stmtCount->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 2. Obtener registros paginados
$stmt = $pdo->prepare("
    SELECT o.*, l.nombre as lote_nombre,
           COALESCE(c.nombre, o.cultivo_operacion) as cultivo_nombre,
           o.campania_operacion, o.grupo_descripcion,
           i.nombre as insumo_nombre, i.unidad_medida
    FROM operaciones o
    JOIN lotes l ON o.lote_id = l.id
    LEFT JOIN cultivos c ON o.cultivo_id = c.id
    LEFT JOIN insumos i ON o.insumo_id = i.id
    $where
    ORDER BY o.fecha DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$operaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Totales por grupo (KPIs) - Respetando filtros pero sin paginación
$stmtStats = $pdo->prepare("
    SELECT o.grupo_gasto, SUM(o.costo_total) as total
    FROM operaciones o
    $where
    GROUP BY o.grupo_gasto
");
$stmtStats->execute($params);
$stats_raw = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

$totales_grupo = ['siembra'=>0,'cosecha'=>0,'pulverizacion'=>0,'fertilizacion'=>0,'otros'=>0,'total'=>0];
foreach ($stats_raw as $s) {
    $g = $s['grupo_gasto'];
    $c = (float)$s['total'];
    if (isset($totales_grupo[$g])) $totales_grupo[$g] += $c;
    else $totales_grupo['otros'] += $c;
    $totales_grupo['total'] += $c;
}

$op_ids = array_column($operaciones, 'id');
$hijos_por_op = [];
if (!empty($op_ids)) {
    $in = str_repeat('?,', count($op_ids) - 1) . '?';
    $stmtHijos = $pdo->prepare("
        SELECT oi.*, i.nombre, i.unidad_medida 
        FROM operacion_insumos oi
        LEFT JOIN insumos i ON oi.insumo_id = i.id
        WHERE oi.operacion_id IN ($in)
    ");
    $stmtHijos->execute($op_ids);
    foreach ($stmtHijos->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $hijos_por_op[$h['operacion_id']][] = $h;
    }
}
foreach ($operaciones as &$op) {
    $op['hijos_insumos'] = $hijos_por_op[$op['id']] ?? [];
}
unset($op);

// (Totales ya calculados vía SQL)
require_once 'includes/header.php';

// Helper: label del grupo
function labelGrupo($op) {
    if ($op['grupo_gasto'] === 'otros' && !empty($op['grupo_descripcion']))
        return htmlspecialchars($op['grupo_descripcion']);
    return ucfirst(str_replace('_', ' ', $op['grupo_gasto']));
}
// Helper: color del grupo
function colorGrupo($g) {
    return match($g) {
        'siembra'        => 'rgba(80,200,120,0.15);color:#50c878;border:1px solid rgba(80,200,120,0.3)',
        'cosecha'        => 'rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.3)',
        'pulverizacion'  => 'rgba(59,130,246,0.15);color:#60a5fa;border:1px solid rgba(59,130,246,0.3)',
        'fertilizacion'  => 'rgba(168,85,247,0.15);color:#c084fc;border:1px solid rgba(168,85,247,0.3)',
        default          => 'rgba(255,255,255,0.06);color:var(--text-muted)',
    };
}
?>
<style>
.filter-toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.grupo-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.grupo-tab {
    padding: 7px 16px; border-radius: 20px; font-size: 0.82rem; font-weight: 600;
    cursor: pointer; border: 1px solid var(--border); background: rgba(255,255,255,0.04);
    color: var(--text-muted); transition: all 0.2s; white-space: nowrap;
}
.grupo-tab:hover { border-color: var(--accent); color: var(--text-primary); }
.grupo-tab.active { background: var(--accent); color: #fff; border-color: var(--accent); box-shadow: 0 0 10px var(--accent-glow); }

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
        background: var(--accent);
        color: white !important;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
    }
.filter-select { padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: var(--text-primary); font-size: 0.85rem; cursor: pointer; min-width: 160px; }
.filter-select:focus { outline: none; border-color: var(--accent); }

/* KPI Resumen Gastos */
.gastos-kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 10px; margin-bottom: 20px; }
.gasto-kpi {
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07);
    border-radius: 12px; padding: 12px 14px;
    transition: transform 0.2s;
}
.gasto-kpi:hover { transform: translateY(-2px); }
.gasto-kpi .gk-label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.gasto-kpi .gk-val   { font-size: 1.15rem; font-weight: 700; color: var(--text-primary); }
.gasto-kpi.total-kpi { background: rgba(16,185,129,0.06); border-color: rgba(16,185,129,0.2); }
.gasto-kpi.total-kpi .gk-val { color: var(--accent); }
</style>

<div class="glass-panel" style="margin-bottom: 24px;">
    <div class="panel-header">
        <h2 style="font-size: 1.2rem; font-weight: 500;">
            <i class="fas fa-tractor" style="color: var(--accent); margin-right: 8px;"></i>
            Matriz de Costos y Labores
        </h2>
        <div style="display:flex; gap:8px; flex-wrap: wrap;">
            <a id="excelBtn" href="api/reporte_excel.php?tipo=operaciones"
               class="btn" style="background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.3); color:#10b981; font-size:0.85rem;">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <a id="pdfBtn" href="api/reporte_pdf.php?tipo=operaciones" target="_blank"
               class="btn" style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#ff7b72; font-size:0.85rem;">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Registrar Gasto
            </button>
        </div>
    </div>

    <div style="display:flex; justify-content: flex-end; margin-bottom: 12px;">
        <div class="currency-toggle-container">
            <button type="button" class="btn-currency active" id="btnModeMoney" onclick="setKpiMode('money')" title="Ver en Dinero (USD)">
                <i class="fas fa-dollar-sign"></i>
            </button>
            <button type="button" class="btn-currency" id="btnModePercent" onclick="setKpiMode('percent')" title="Ver en Porcentaje (%)">
                <i class="fas fa-percent"></i>
            </button>
        </div>
    </div>

    <!-- ===== KPIs DE RESUMEN ===== -->
    <div class="gastos-kpi-grid" id="gastosKpiGrid">
        <div class="gasto-kpi" data-grupo="siembra">
            <div class="gk-label"><i class="fas fa-seedling" style="margin-right:4px;"></i> Siembra</div>
            <div class="gk-val" id="kpiSiembra" data-val="<?= $totales_grupo['siembra'] ?>">$<?= number_format($totales_grupo['siembra'], 0, ',', '.') ?></div>
        </div>
        <div class="gasto-kpi" data-grupo="cosecha">
            <div class="gk-label"><i class="fas fa-wheat-awn" style="margin-right:4px;"></i> Cosecha</div>
            <div class="gk-val" id="kpiCosecha" data-val="<?= $totales_grupo['cosecha'] ?>">$<?= number_format($totales_grupo['cosecha'], 0, ',', '.') ?></div>
        </div>
        <div class="gasto-kpi" data-grupo="pulverizacion">
            <div class="gk-label"><i class="fas fa-spray-can" style="margin-right:4px;"></i> Pulverización</div>
            <div class="gk-val" id="kpiPulv" data-val="<?= $totales_grupo['pulverizacion'] ?>">$<?= number_format($totales_grupo['pulverizacion'], 0, ',', '.') ?></div>
        </div>
        <div class="gasto-kpi" data-grupo="fertilizacion">
            <div class="gk-label"><i class="fas fa-fill-drip" style="margin-right:4px;"></i> Fertilización</div>
            <div class="gk-val" id="kpiFert" data-val="<?= $totales_grupo['fertilizacion'] ?>">$<?= number_format($totales_grupo['fertilizacion'], 0, ',', '.') ?></div>
        </div>
        <div class="gasto-kpi" data-grupo="otros">
            <div class="gk-label"><i class="fas fa-ellipsis-h" style="margin-right:4px;"></i> Otros</div>
            <div class="gk-val" id="kpiOtros" data-val="<?= $totales_grupo['otros'] ?>">$<?= number_format($totales_grupo['otros'], 0, ',', '.') ?></div>
        </div>
        <div class="gasto-kpi total-kpi">
            <div class="gk-label">Total General</div>
            <div class="gk-val" id="kpiTotal" data-val="<?= $totales_grupo['total'] ?>">$<?= number_format($totales_grupo['total'], 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- ===== TOOLBAR DE FILTROS ===== -->
    <div style="background:rgba(255,255,255,0.02); border-top:1px solid rgba(255,255,255,0.05); border-bottom:1px solid rgba(255,255,255,0.05); padding:16px 20px; margin: 0 -20px 20px -20px;" class="filter-toolbar">
        <div class="grupo-tabs">
            <button class="grupo-tab <?= $f_grupo === 'todos' ? 'active' : '' ?>" onclick="setFiltroGrupo('todos')"><i class="fas fa-layer-group"></i> Todos</button>
            <button class="grupo-tab <?= $f_grupo === 'siembra' ? 'active' : '' ?>" onclick="setFiltroGrupo('siembra')"><i class="fas fa-seedling"></i> Siembra</button>
            <button class="grupo-tab <?= $f_grupo === 'cosecha' ? 'active' : '' ?>" onclick="setFiltroGrupo('cosecha')"><i class="fas fa-wheat-awn"></i> Cosecha</button>
            <button class="grupo-tab <?= $f_grupo === 'pulverizacion' ? 'active' : '' ?>" onclick="setFiltroGrupo('pulverizacion')"><i class="fas fa-spray-can"></i> Pulv.</button>
            <button class="grupo-tab <?= $f_grupo === 'fertilizacion' ? 'active' : '' ?>" onclick="setFiltroGrupo('fertilizacion')"><i class="fas fa-fill-drip"></i> Fert.</button>
            <button class="grupo-tab <?= $f_grupo === 'otros' ? 'active' : '' ?>" onclick="setFiltroGrupo('otros')"><i class="fas fa-ellipsis-h"></i> Otros</button>
        </div>

        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <!-- Filtro por Campaña -->
            <?php if (!empty($campanias_disponibles)): ?>
            <select class="filter-select" id="campaniaFilter" onchange="aplicarFiltros()">
                <option value="todos">-- Todas las Campañas --</option>
                <?php foreach ($campanias_disponibles as $camp): ?>
                    <option value="<?= htmlspecialchars($camp) ?>" <?= $f_camp === $camp ? 'selected' : '' ?>><?= htmlspecialchars($camp) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <!-- Filtro por Lote -->
            <select class="filter-select" id="loteFilter" onchange="aplicarFiltros()">
                <option value="todos">-- Todos los Lotes --</option>
                <?php foreach($lotes as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= (string)$f_lote === (string)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Operación</th>
                    <th>Detalle (Labor/Insumo)</th>
                    <th>Lote / Cultivo</th>
                    <th>Costo Total ($)</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($operaciones as $op): ?>
                <tr class="op-row" 
                    data-grupo="<?= $op['grupo_gasto'] ?>" 
                    data-lote="<?= $op['lote_id'] ?>"
                    data-campania="<?= htmlspecialchars($op['campania_operacion'] ?? '') ?>"
                    data-costo="<?= (float)$op['costo_total'] ?>">
                    <td data-label="Operación">
                        <div style="font-weight:600; font-size:0.95rem; margin-bottom:6px;"><?= date('d/m/Y', strtotime($op['fecha'])) ?></div>
                        <span class="badge" style="background:<?= colorGrupo($op['grupo_gasto']) ?>;">
                            <?= labelGrupo($op) ?>
                        </span>
                    </td>
                    <td data-label="Detalle" style="position:relative;">
                        <?php if($op['tipo_componente'] === 'labor'): ?>
                            <i class="fas fa-user-cog" title="Labor" style="color:var(--accent);"></i>
                            <b><?= htmlspecialchars($op['proveedor_servicio']) ?></b><br>
                            <small style="color:var(--text-muted);">
                                <?= number_format($op['cantidad_ha'], 1, ',', '.') ?> ha × $<?= number_format($op['precio_unitario'], 2, ',', '.') ?>
                            </small>
                        <?php elseif($op['tipo_componente'] === 'insumo' && !empty($op['insumo_nombre'])): ?>
                            <i class="fas fa-box-open" title="Insumo" style="color:#60a5fa;"></i>
                            <b><?= htmlspecialchars($op['insumo_nombre']) ?></b><br>
                            <small style="color:var(--text-muted);">
                                <?= $op['cantidad_ha'] ?> <?= $op['unidad_medida'] ?>/ha × $<?= number_format($op['precio_unitario'], 2, ',', '.') ?>
                            </small>
                        <?php elseif($op['tipo_componente'] === 'receta_labor'): ?>
                            <i class="fas fa-file-invoice" title="Receta de Aplicación" style="color:#f59e0b;"></i>
                            <b>Receta (Labor + Insumos)</b>
                            <div style="margin-top: 5px;">
                                <a href="api/excel_receta.php?id=<?= $op['id'] ?>" class="btn btn-sm" style="display:inline-flex; align-items:center; gap:4px; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); padding:3px 8px; font-size:0.75rem; border-radius:6px; text-decoration:none;"><i class="fas fa-file-excel"></i> Excel</a>
                                <div style="display:inline-block; position:relative; margin-left:4px;">
                                    <button type="button" onclick="toggleInsumosList(<?= $op['id'] ?>)" class="btn" style="background:transparent; border:1px solid rgba(255,255,255,0.1); padding:3px 8px; font-size:0.75rem; color:var(--text-primary); border-radius:6px;"><i class="fas fa-ellipsis-h"></i> Detalles</button>
                                    <div id="insumos-list-<?= $op['id'] ?>" style="display:none; position:absolute; z-index:99; background:var(--bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px; border-radius:8px; width:max-content; top:100%; left:0; box-shadow:0 4px 15px rgba(0,0,0,0.5); margin-top:5px;">
                                        <div style="font-size:0.8rem; border-bottom:1px solid rgba(255,255,255,0.1); margin-bottom:5px; padding-bottom:5px;">
                                            <strong>Labor:</strong> <?= htmlspecialchars($op['proveedor_servicio']) ?> ($<?= number_format($op['precio_unitario'], 2, ',', '.') ?>/ha)
                                        </div>
                                        <ul style="list-style:none; margin:0; padding:0; font-size:0.8rem; text-align:left;">
                                            <?php foreach($op['hijos_insumos'] as $h): ?>
                                            <li style="margin-bottom:4px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:4px; color:var(--text-primary);">
                                                <strong><?= htmlspecialchars($h['nombre'] ?: $h['nombre_libre']) ?></strong>: <?= number_format($h['cantidad_ha'], 3, ',', '.') ?> <?= htmlspecialchars($h['unidad_medida'] ?: 'un/lts') ?>/ha ($<?= number_format($h['precio_unitario'], 2, ',', '.') ?>)
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php elseif($op['tipo_componente'] === 'multi_insumo' || !empty($op['hijos_insumos'])): ?>
                            <i class="fas fa-box-open" title="Múltiples Insumos" style="color:#60a5fa;"></i>
                            <b>Múltiples Insumos (<?= count($op['hijos_insumos']) ?>)</b>
                            <div style="display:inline-block; position:relative; margin-left:8px;">
                                <button type="button" onclick="toggleInsumosList(<?= $op['id'] ?>)" class="btn" style="background:transparent; border:1px solid rgba(255,255,255,0.1); padding:2px 6px; font-size:0.75rem; color:var(--text-primary);"><i class="fas fa-ellipsis-h"></i></button>
                                <div id="insumos-list-<?= $op['id'] ?>" style="display:none; position:absolute; z-index:99; background:var(--bg-card); border:1px solid rgba(255,255,255,0.1); padding:10px; border-radius:8px; width:max-content; top:100%; left:0; box-shadow:0 4px 15px rgba(0,0,0,0.5); margin-top:5px;">
                                    <ul style="list-style:none; margin:0; padding:0; font-size:0.8rem; text-align:left;">
                                        <?php foreach($op['hijos_insumos'] as $h): ?>
                                        <li style="margin-bottom:4px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:4px; color:var(--text-primary);">
                                            <strong><?= htmlspecialchars($h['nombre'] ?: $h['nombre_libre']) ?></strong>: <?= number_format($h['cantidad_ha'], 3, ',', '.') ?> <?= htmlspecialchars($h['unidad_medida'] ?: 'un/lts') ?>/ha ($<?= number_format($h['precio_unitario'], 2, ',', '.') ?>)
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Lote / Cultivo">
                        <?= htmlspecialchars($op['lote_nombre']) ?>
                        <?php if(!empty($op['campania_operacion']) || !empty($op['cultivo_nombre'])): ?>
                            <br><small style="color: var(--text-muted);">
                                <?= htmlspecialchars($op['campania_operacion'] ?: '') ?>
                                <?= (!empty($op['campania_operacion']) && !empty($op['cultivo_nombre'])) ? ' - ' : '' ?>
                                <?= htmlspecialchars($op['cultivo_nombre'] ?: '') ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td data-label="Costo Total ($)" style="font-weight: 600; color: var(--danger);">
                        -$<?= number_format($op['costo_total'], 2, ',', '.') ?>
                    </td>
                    <td data-label="Acciones">
                        <!-- Editar -->
                        <button type="button" class="btn" style="color: var(--accent); background: transparent; padding: 4px 8px;"
                            onclick='editOp(<?= json_encode($op, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <!-- Eliminar -->
                        <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar registro?');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $op['id'] ?>">
                            <button type="submit" class="btn" style="color: var(--danger); background: transparent; padding: 4px 8px;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($operaciones) === 0): ?>
                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 40px;">
                    <i class="fas fa-clipboard-list" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                    No hay gastos registrados
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <div style="display:flex; justify-content: center; gap:10px; margin-top:20px; padding-bottom:10px;">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&grupo=<?= $f_grupo ?>&lote_id=<?= $f_lote ?>&campania=<?= urlencode($f_camp) ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;"><i class="fas fa-chevron-left"></i> Anterior</a>
        <?php endif; ?>
        
        <span style="color:var(--text-muted); align-self:center; font-size:0.9rem;">Página <?= $page ?> de <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&grupo=<?= $f_grupo ?>&lote_id=<?= $f_lote ?>&campania=<?= urlencode($f_camp) ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;">Siguiente <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===== MODAL: Registrar / Editar Gasto ===== -->
<div id="addOpModal" class="modal-wrapper">
    <div class="glass-panel modal-panel" style="max-width: 520px;">
        <h2 id="opModalTitle" style="margin-bottom: 20px;">Registrar Gasto / Labor</h2>
        <form method="POST" style="display: flex; flex-direction: column; gap: 14px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="opAction" value="add">
            <input type="hidden" name="id"     id="opId"     value="">

            <!-- Grupo de Gasto -->
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Grupo de Gasto</label>
                <select name="grupo_gasto" id="grupoGastoSelect" required
                    onchange="toggleGrupoDesc(); togglePulv();"
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;">
                    <option value="siembra">🌱 Siembra</option>
                    <option value="cosecha">🚜 Cosecha</option>
                    <option value="pulverizacion">🧪 Pulverización</option>
                    <option value="fertilizacion">💧 Fertilización</option>
                    <option value="otros">✏️ Otros Gastos</option>
                </select>
            </div>

            <!-- Campo libre para "Otros" -->
            <div id="grupoDescContainer" style="display: none; flex-direction: column; gap: 5px;">
                <label>Descripción del Gasto <span style="color:var(--text-muted);font-size:0.85em;">(especificá cuál)</span></label>
                <input type="text" name="grupo_descripcion" id="grupoDescInput"
                    placeholder="Ej: Fletes, Análisis de suelo, Asesoramiento..."
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--accent); background: rgba(16,185,129,0.05); color: white;">
            </div>

            <!-- Tipo Componente + Lote (side by side) -->
            <div class="form-grid-2">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label>Tipo Componente</label>
                    <select name="tipo_componente" id="tipoCompSelect" required onchange="toggleFormMode()"
                        style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;">
                        <option value="labor">👷 Mano de Obra (Labor)</option>
                        <option value="insumo">📦 Insumo</option>
                        <option value="receta_labor">🚜 Aplicación / Receta (Labor + Insumos)</option>
                    </select>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label>Lote Afectado</label>
                    <select name="lote_id" id="loteSelect" required onchange="updateCultivos()"
                        style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;">
                        <option value="">-- Seleccionar Lote --</option>
                        <?php foreach($lotes as $l): ?>
                            <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['nombre']) ?> (<?= $l['superficie'] ?> ha)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Campaña / Cultivo -->
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Campaña / Cultivo Actual</label>
                <select name="form_cultivo" id="cultivoSelect"
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;">
                    <option value="">-- Seleccionar primero un lote --</option>
                </select>
            </div>

            <!-- Modo de Ingreso (Insumos) -->
            <div id="modoCalculoContainer" style="display: none; flex-direction: column; gap: 5px; margin-top: 5px;">
                <label>Modo de Ingreso para Insumos</label>
                <select name="modo_calculo" id="modoCalculoSelect" onchange="toggleFormMode(); updateCostoPreview();"
                    style="padding: 10px; border-radius: 6px; border: 1px dashed #60a5fa; background: rgba(96,165,250,0.05); color: white;">
                    <option value="ha">Por Hectárea (Cant/Ha)</option>
                    <option value="total">Total del Lote (Se dividirá automáticamente por las hectáreas)</option>
                </select>
            </div>

            <!-- SECCIÓN LABOR -->
            <div id="sectionLabor" style="display: flex; flex-direction: column; gap: 14px; background:rgba(255,255,255,0.02); padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.05);">
                <div style="font-size:0.9rem; font-weight:600; color:var(--text-muted); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:5px;">Datos de la Labor</div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label>Proveedor del Servicio</label>
                    <input type="text" name="proveedor_servicio" id="proveedorInput"
                        placeholder="Ej: Contratista Juan"
                        style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
                </div>
                <div class="form-grid-2">
                    <div style="display:none; flex-direction: column; gap: 5px;" id="divCargas">
                        <label>Cantidad de Cargas <small>(Opcional)</small></label>
                        <input type="number" step="1" name="cargas" id="cargasInput" placeholder="Ej: 3"
                            style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label>Cantidad de Has.</label>
                        <input type="number" step="0.1" name="cantidad_ha" id="cantHaLabor" placeholder="Ej: 120"
                            style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label>Precio $ / Ha.</label>
                        <input type="number" step="0.01" name="precio_unitario" id="priceHaLabor" placeholder="Ej: 4500"
                            style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
                    </div>
                </div>
            </div>

            <!-- SECCIÓN INSUMO (MULTI-INSUMO) -->
            <div id="sectionInsumo" style="display: none; flex-direction: column; gap: 14px; background:rgba(255,255,255,0.02); padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.05);">
                <div style="font-size:0.9rem; font-weight:600; color:var(--text-muted); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:5px;">Insumos y Productos Utilizados</div>
                <div id="insumosContainer" style="display:flex; flex-direction:column; gap:10px;">
                    <!-- Filas dinámicas irán aquí -->
                </div>
                <button type="button" class="btn" style="background:rgba(255,255,255,0.05); border:1px dashed rgba(255,255,255,0.2); align-self:flex-start;" onclick="addInsumoRow()">
                    <i class="fas fa-plus"></i> Añadir Insumo
                </button>
            </div>

            <!-- Fecha -->
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Fecha</label>
                <input type="date" name="fecha" id="fechaInput" value="<?= date('Y-m-d') ?>" required
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <!-- Preview costo calculado -->
            <div id="costoPreview" style="display:none; background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2); border-radius: 8px; padding: 12px; text-align: center;">
                <span style="font-size:0.85rem; color:var(--text-muted);">Costo Total Estimado</span><br>
                <span id="costoPreviewVal" style="font-size:1.5rem; font-weight:800; color:var(--accent);">$0.00</span>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px;">
                <button type="button" class="btn" onclick="closeOpModal()" style="background: rgba(255,255,255,0.1); color: white;">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
const lotesRaw   = <?= $lotes_json ?>;
const cultivosRaw = <?= $cultivos_json ?>;
const insumosRaw = <?= $insumos_json ?>;
let kpiMode = 'money';

function setKpiMode(mode) {
    kpiMode = mode;
    document.getElementById('btnModeMoney').classList.toggle('active', mode === 'money');
    document.getElementById('btnModePercent').classList.toggle('active', mode === 'percent');
    renderKpis();
}

function renderKpis() {
    const ids = ['kpiSiembra', 'kpiCosecha', 'kpiPulv', 'kpiFert', 'kpiOtros'];
    const total = parseFloat(document.getElementById('kpiTotal').dataset.val);
    
    if (kpiMode === 'money') {
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = '$' + Math.round(parseFloat(el.dataset.val)).toLocaleString('es-AR');
        });
        document.getElementById('kpiTotal').textContent = '$' + Math.round(total).toLocaleString('es-AR');
    } else {
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                const val = parseFloat(el.dataset.val);
                const p = total > 0 ? ((val / total) * 100).toFixed(1) : '0.0';
                el.textContent = p + '%';
            }
        });
        document.getElementById('kpiTotal').textContent = '100%';
    }
}

/* ── FILTRADO POR SERVIDOR ── */
function setFiltroGrupo(grupo) {
    const url = new URL(window.location);
    url.searchParams.set('grupo', grupo);
    url.searchParams.set('page', 1);
    window.location.href = url.href;
}

function aplicarFiltros() {
    const url = new URL(window.location);
    const lote = document.getElementById('loteFilter').value;
    const campania = document.getElementById('campaniaFilter') ? document.getElementById('campaniaFilter').value : 'todos';
    
    url.searchParams.set('lote_id', lote);
    url.searchParams.set('campania', campania);
    url.searchParams.set('page', 1);
    window.location.href = url.href;
}

function toggleInsumosList(id) {
    const list = document.getElementById('insumos-list-' + id);
    if(list.style.display === 'none') {
        document.querySelectorAll('[id^="insumos-list-"]').forEach(el => el.style.display = 'none');
        list.style.display = 'block';
    } else {
        list.style.display = 'none';
    }
}
window.addEventListener('click', e => {
    if (!e.target.closest('[id^="insumos-list-"]') && !e.target.closest('button[onclick^="toggleInsumosList"]')) {
        document.querySelectorAll('[id^="insumos-list-"]').forEach(el => el.style.display = 'none');
    }
});

function addInsumoRow(ins_id = '', cant = '', price = '', nom_libre = '') {
    const container = document.getElementById('insumosContainer');
    const rowId = Date.now() + Math.random().toString(36).substr(2, 5);
    // Solo poner 'required' si la sección de insumos está visible
    const secInsumo = document.getElementById('sectionInsumo');
    const req = (secInsumo && secInsumo.style.display !== 'none') ? 'required' : '';
    
    let optionsHtml = '<option value="">-- Seleccionar --</option>';
    optionsHtml += '<option value="manual" style="color:var(--accent); font-weight:bold;" ' + (!ins_id && nom_libre ? 'selected' : '') + '>➕ Ingresar Texto Manual (Sin Descontar Stock)</option>';
    
    insumosRaw.forEach(i => {
        const stockLabel = (i.stock_actual !== null) ? ' — Stock: '+parseFloat(i.stock_actual).toFixed(2)+' '+i.unidad_medida : '';
        const selected = (i.id == ins_id) ? 'selected' : '';
        optionsHtml += `<option value="${i.id}" data-precio="${parseFloat(i.precio_estimado_usd)||0}" data-stock="${parseFloat(i.stock_actual)||0}" data-unidad="${i.unidad_medida}" ${selected}>${i.nombre}${stockLabel}</option>`;
    });

    const html = `
    <div class="insumo-row" id="row-${rowId}" style="background:rgba(0,0,0,0.1); border:1px solid var(--border); padding:10px; border-radius:8px; display:flex; flex-direction:column; gap:8px; position:relative;">
        <button type="button" onclick="document.getElementById('row-${rowId}').remove(); updateCostoPreview();" style="position:absolute; top:8px; right:8px; background:transparent; border:none; color:var(--danger); cursor:pointer;"><i class="fas fa-times"></i></button>
        <div style="display:flex; flex-direction:column; gap:4px; padding-right:20px;">
            <label style="font-size:0.8rem;">Insumo</label>
            <select name="insumo_id[]" onchange="onInsumoChangeRow(this)" style="padding:8px; border-radius:6px; border:1px solid var(--border); background:var(--bg-color); color:white;" ${req}>
                ${optionsHtml}
            </select>
            <input type="text" name="nombre_libre_ins[]" class="libre-input" value="${nom_libre}" placeholder="Escribe el nombre del insumo o labor..." style="display:${(!ins_id && nom_libre) ? 'block' : 'none'}; padding:8px; border-radius:6px; border:1px dashed var(--accent); background:rgba(0,0,0,0.3); color:white; margin-top:5px;">
            <div class="stockIndicadorRow" style="display:none; font-size:0.8rem; margin-top:2px;"></div>
        </div>
        <div style="display:flex; gap:10px;">
            <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:0.8rem;" class="lbl-cant-ins">Cant/Ha</label>
                <input type="number" step="0.0001" name="cantidad_ha_ins[]" class="cant-ins-input" value="${cant}" oninput="updateCostoPreview()" placeholder="Ej: 0.15" style="padding:8px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;" ${req}>
            </div>
            <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:0.8rem;">Precio USD</label>
                <input type="number" step="0.0001" name="precio_unitario_ins[]" class="price-ins-input" value="${price}" oninput="updateCostoPreview()" placeholder="Ej: 6.50" style="padding:8px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;" ${req}>
            </div>
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
    if (ins_id) {
        const selectElement = container.lastElementChild.querySelector('select');
        onInsumoChangeRow(selectElement, true);
    }
    toggleFormMode();
}

function onInsumoChangeRow(sel, skipPriceOverride = false) {
    const opt = sel.options[sel.selectedIndex];
    const row = sel.closest('.insumo-row');
    const ind = row.querySelector('.stockIndicadorRow');
    const priceField = row.querySelector('.price-ins-input');
    const libreInput = row.querySelector('.libre-input');

    if (sel.value === 'manual') {
        libreInput.style.display = 'block';
        libreInput.required = true;
        ind.style.display = 'none';
        return;
    } else {
        libreInput.style.display = 'none';
        libreInput.required = false;
        libreInput.value = '';
    }

    if (!sel.value) { ind.style.display = 'none'; return; }

    const stock  = parseFloat(opt.dataset.stock  || 0);
    const precio = parseFloat(opt.dataset.precio || 0);
    const unidad = opt.dataset.unidad || '';

    if (precio > 0 && !skipPriceOverride && !priceField.value) priceField.value = precio;

    ind.style.display = 'block';
    if (stock <= 0) {
        ind.style.color = '#ff7b72';
        ind.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Sin stock (${stock} ${unidad})`;
    } else {
        ind.style.color = 'var(--accent)';
        ind.innerHTML = `<i class="fas fa-check-circle"></i> Stock: ${stock} ${unidad}`;
    }
    updateCostoPreview();
}

/* ───── Cultivos dinámicos ───── */
function updateCultivos(preselect) {
    const loteId = document.getElementById('loteSelect').value;
    const sel    = document.getElementById('cultivoSelect');
    sel.innerHTML = '<option value="">-- General / Sin Cultivo Específico --</option>';
    if (loteId) {
        let options = [];
        const info = lotesRaw.find(l => l.id == loteId);
        if (info && info.campania && info.cultivo_actual) {
            options.push({ c: info.campania, cult: info.cultivo_actual });
        }
        cultivosRaw.forEach(c => {
            if (c.lote_id == loteId && c.campania && c.cultivo) {
                if (!options.some(o => o.c === c.campania && o.cult === c.cultivo)) {
                    options.push({ c: c.campania, cult: c.cultivo });
                }
            }
        });
        
        // Si hay una preselección que no está en la lista (ej: un cultivo viejo), la agregamos
        if (preselect && !options.some(o => (o.c + ' | ' + o.cult) === preselect)) {
            const parts = preselect.split(' | ');
            if (parts.length === 2) options.push({ c: parts[0], cult: parts[1] });
        }

        options.forEach(opt => {
            const val = opt.c + ' | ' + opt.cult;
            const isSel = (preselect === val) ? 'selected' : '';
            sel.innerHTML += `<option value="${val}" ${isSel}>${val}</option>`;
        });
        
        // Si editOp no pasa un preselect y es un nuevo registro, intentamos preseleccionar el primero
        if (!preselect && options.length === 1 && !window.isEditingMode) {
            sel.value = options[0].c + ' | ' + options[0].cult;
        }
    }
}

/* ───── Toggle campo libre "Otros" ───── */
function toggleGrupoDesc() {
    const g    = document.getElementById('grupoGastoSelect').value;
    const cont = document.getElementById('grupoDescContainer');
    const inp  = document.getElementById('grupoDescInput');
    if (g === 'otros') {
        cont.style.display = 'flex';
        inp.required = true;
    } else {
        cont.style.display = 'none';
        inp.required = false;
    }
}

/* ───── Toggle requeridos en filas de insumo ───── */
function setInsumoRowsRequired(required) {
    document.querySelectorAll('#insumosContainer select, #insumosContainer .cant-ins-input, #insumosContainer .price-ins-input').forEach(el => {
        el.required = required;
    });
}

/* ───── Toggle Labor / Insumo ───── */
function toggleFormMode() {
    const mode      = document.getElementById('tipoCompSelect').value;
    const secLabor  = document.getElementById('sectionLabor');
    const secInsumo = document.getElementById('sectionInsumo');
    const provInput = document.getElementById('proveedorInput');
    const cantLabor = document.getElementById('cantHaLabor');
    const priceLabor= document.getElementById('priceHaLabor');
    const divCargas = document.getElementById('divCargas');
    const modoCalculoContainer = document.getElementById('modoCalculoContainer');

    if (mode === 'labor') {
        secLabor.style.display  = 'flex';
        secInsumo.style.display = 'none';
        if(modoCalculoContainer) modoCalculoContainer.style.display = 'none';
        provInput.required = cantLabor.required = priceLabor.required = true;
        if(divCargas) divCargas.style.display = 'none';
        setInsumoRowsRequired(false);  // ✔ deshabilitar required en insumos ocultos
    } else if (mode === 'insumo') {
        secLabor.style.display  = 'none';
        secInsumo.style.display = 'flex';
        if(modoCalculoContainer) modoCalculoContainer.style.display = 'flex';
        provInput.required = cantLabor.required = priceLabor.required = false;
        if(divCargas) divCargas.style.display = 'none';
        setInsumoRowsRequired(true);   // ✔ reactivar required en insumos visibles
    } else if (mode === 'receta_labor') {
        secLabor.style.display  = 'flex';
        secInsumo.style.display = 'flex';
        if(modoCalculoContainer) modoCalculoContainer.style.display = 'flex';
        provInput.required = cantLabor.required = priceLabor.required = true;
        if(divCargas) divCargas.style.display = 'flex';
        setInsumoRowsRequired(true);   // ✔ reactivar required en insumos visibles
    }
    
    const modoCalculo = document.getElementById('modoCalculoSelect') ? document.getElementById('modoCalculoSelect').value : 'ha';
    document.querySelectorAll('.lbl-cant-ins').forEach(lbl => {
        lbl.textContent = (modoCalculo === 'total') ? 'Cant. Total' : 'Cant/Ha';
    });

    updateCostoPreview();
}

/* ───── Preview costo calculado ───── */
function updateCostoPreview() {
    const mode   = document.getElementById('tipoCompSelect').value;
    const loteId = document.getElementById('loteSelect').value;
    const lote   = lotesRaw.find(l => l.id == loteId);
    const sup    = lote ? parseFloat(lote.superficie) : 0;
    let costo = 0;
    if (mode === 'labor' || mode === 'receta_labor') {
        const cant  = parseFloat(document.getElementById('cantHaLabor').value) || 0;
        const price = parseFloat(document.getElementById('priceHaLabor').value) || 0;
        costo += (cant * price);
    } 
    if (mode === 'insumo' || mode === 'receta_labor') {
        const modoCalculo = document.getElementById('modoCalculoSelect') ? document.getElementById('modoCalculoSelect').value : 'ha';
        const factor = (modoCalculo === 'total' && sup > 0) ? sup : 1;

        const rows = document.querySelectorAll('.insumo-row');
        rows.forEach(r => {
            const cant = (parseFloat(r.querySelector('.cant-ins-input').value) || 0) / factor;
            const price = parseFloat(r.querySelector('.price-ins-input').value) || 0;
            costo += (cant * price * sup);
        });
    }
    const prev = document.getElementById('costoPreview');
    const val  = document.getElementById('costoPreviewVal');
    if (costo > 0) {
        prev.style.display = 'block';
        val.textContent    = '$' + costo.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
    } else {
        prev.style.display = 'none';
    }
}

// Attach preview listeners
['cantHaLabor','priceHaLabor','loteSelect'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateCostoPreview);
});

/* ───── Abrir modal vacío ───── */
function openAddModal() {
    document.getElementById('opModalTitle').innerText = '➕ Registrar Gasto / Labor';
    document.getElementById('opAction').value = 'add';
    document.getElementById('opId').value     = '';
    document.getElementById('fechaInput').value = '<?= date('Y-m-d') ?>';
    // Reset selects
    document.getElementById('grupoGastoSelect').value = 'siembra';
    document.getElementById('tipoCompSelect').value   = 'labor';
    document.getElementById('loteSelect').value       = '';
    document.getElementById('grupoDescInput').value   = '';
    document.getElementById('proveedorInput').value   = '';
    document.getElementById('cantHaLabor').value      = '';
    document.getElementById('priceHaLabor').value     = '';
    document.getElementById('insumosContainer').innerHTML = '';
    addInsumoRow();
    updateCultivos();
    toggleGrupoDesc();
    toggleFormMode();
    document.getElementById('addOpModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

/* ───── Abrir modal en modo edición ───── */
function editOp(op) {
    document.getElementById('opModalTitle').innerText = '✏️ Editar Gasto / Labor';
    document.getElementById('opAction').value = 'edit';
    document.getElementById('opId').value     = op.id;

    document.getElementById('grupoGastoSelect').value = op.grupo_gasto;
    document.getElementById('grupoDescInput').value   = op.grupo_descripcion || '';
    document.getElementById('tipoCompSelect').value   = (op.tipo_componente === 'multi_insumo') ? 'insumo' : op.tipo_componente;
    document.getElementById('loteSelect').value       = op.lote_id;
    document.getElementById('fechaInput').value       = op.fecha;

    // Cultivo
    const cultStr = [op.campania_operacion, op.cultivo_operacion].filter(Boolean).join(' | ');
    updateCultivos(cultStr);

    if (op.tipo_componente === 'labor' || op.tipo_componente === 'receta_labor') {
        document.getElementById('proveedorInput').value = op.proveedor_servicio || '';
        document.getElementById('cantHaLabor').value   = op.cantidad_ha;
        document.getElementById('priceHaLabor').value  = op.precio_unitario;
        if(document.getElementById('cargasInput')) {
            document.getElementById('cargasInput').value = op.cargas || '';
        }
    }
    if (op.tipo_componente !== 'labor') {
        document.getElementById('insumosContainer').innerHTML = '';
        if (op.hijos_insumos && op.hijos_insumos.length > 0) {
            op.hijos_insumos.forEach(h => {
                addInsumoRow(h.insumo_id, h.cantidad_ha, h.precio_unitario, h.nombre_libre);
            });
        } else if (op.insumo_id) { // Legacy single insumo
            addInsumoRow(op.insumo_id, op.cantidad_ha, op.precio_unitario);
        } else {
            addInsumoRow();
        }
    }

    toggleGrupoDesc();
    toggleFormMode();
    document.getElementById('addOpModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeOpModal() {
    document.getElementById('addOpModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

window.addEventListener('click', e => {
    const modal = document.getElementById('addOpModal');
    if (e.target === modal) closeOpModal();
});

/* ───── Init ───── */
document.addEventListener('DOMContentLoaded', () => {
    toggleGrupoDesc();
    toggleFormMode();
});
</script>

<?php require_once 'includes/footer.php'; ?>
