<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
require_once 'includes/cultivos.php';
require_once 'includes/exportar.php';
// Muestra los importes en pesos o en dólares; no cambia nada de lo guardado.
require_once 'includes/moneda.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Registro de Costos y Labores';

validate_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ─── Shared logic: parse cultivo info (solo para add/edit) ───────────────
    $lote_id     = $_POST['lote_id'] ?? null;
    $cultivo_info = !empty($_POST['form_cultivo']) ? $_POST['form_cultivo'] : null;
    $campania = $cultivo = null;
    $grupo      = $_POST['grupo_gasto'] ?? 'otros';
    $grupo_desc = null;
    $tipo_comp  = $_POST['tipo_componente'] ?? 'labor';
    $fecha      = $_POST['fecha'] ?? date('Y-m-d');
    $cultivo_id = null;
    $sup = 0;
    $factor_division = 1;
    $costo_total = 0;
    $op_id = null;
    $proveedor = null;
    $cant_ha = 0;
    $precio_u = 0;
    $cargas = null;
    $tipo_comp_db = ($tipo_comp === 'insumo') ? 'multi_insumo' : $tipo_comp;
    // La moneda en que se pagó. No se convierte al guardar: se convierte al mirar.
    $moneda = (($_POST['moneda'] ?? 'ARS') === 'USD') ? 'USD' : 'ARS';

    if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
        if ($cultivo_info) {
            $partes = explode(' | ', $cultivo_info);
            if (count($partes) === 2) { $campania = trim($partes[0]); $cultivo = trim($partes[1]); }
            else                      { $cultivo = trim($cultivo_info); }
        }
        $grupo_desc = ($grupo === 'otros' && !empty($_POST['grupo_descripcion'])) ? trim($_POST['grupo_descripcion']) : null;

        // Cultivo canónico (find-or-create). El texto se sigue guardando como snapshot.
        $cultivo_id = cultivo_resolve($pdo, $usuario_id, $lote_id, $cultivo, $campania);

        // Superficie del lote
        $stmtSup = $pdo->prepare("SELECT superficie FROM lotes WHERE id = ?");
        $stmtSup->execute([$lote_id]);
        $sup = (float)($stmtSup->fetchColumn() ?: 0);
        if ($sup <= 0) throw new Exception("El lote no tiene superficie válida.");

        $modo_calculo = $_POST['modo_calculo'] ?? 'ha';

        // Hectáreas declaradas en este gasto para los insumos (default = superficie del lote).
        $hectareas = (isset($_POST['hectareas_insumo']) && $_POST['hectareas_insumo'] !== '') ? (float)$_POST['hectareas_insumo'] : $sup;
        if ($hectareas <= 0) $hectareas = $sup;
        $hectareas_db = ($tipo_comp === 'insumo' || $tipo_comp === 'receta_labor') ? $hectareas : null;

        // En modo "Cant. Total" la cantidad ingresada es el total del gasto (no por ha):
        // se divide por las hectáreas para pasarla a cantidad/ha, igual que el preview JS.
        // Debe usar las MISMAS hectáreas con las que luego se multiplica, para que se cancelen.
        $factor_division = ($modo_calculo === 'total' && $hectareas > 0) ? $hectareas : 1;

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
                    
                    $c = (float)$_POST['cantidad_ha_ins'][$i] / $factor_division; // modo "total" → pasa a cant/ha
                    $p = (float)$_POST['precio_unitario_ins'][$i];
                    $costo_total += ($c * $p * $hectareas);
                }
            }
        }
    }

    $pdo->beginTransaction();
    try {
        if ($_POST['action'] === 'add') {
            $stmt = $pdo->prepare("INSERT INTO operaciones (usuario_id, lote_id, cultivo_id, grupo_gasto, grupo_descripcion, tipo_componente, insumo_id, proveedor_servicio, cantidad_ha, precio_unitario, costo_total, moneda, fecha, campania_operacion, cultivo_operacion, cargas, hectareas) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $lote_id, $cultivo_id, $grupo, $grupo_desc, $tipo_comp_db, $proveedor, $cant_ha, $precio_u, $costo_total, $moneda, $fecha, $campania, $cultivo, $cargas, $hectareas_db]);
            $op_id = $pdo->lastInsertId();

            if (($tipo_comp === 'insumo' || $tipo_comp === 'receta_labor') && isset($_POST['insumo_id']) && is_array($_POST['insumo_id'])) {
                $stmtInsertChild = $pdo->prepare("INSERT INTO operacion_insumos (operacion_id, insumo_id, nombre_libre, cantidad_ha, precio_unitario) VALUES (?, ?, ?, ?, ?)");
                // Permite stock negativo: no se clampa a 0 ni se desactiva el insumo al llegar a cero.
                $stmtUpdateStock = $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual - ? WHERE id = ? AND usuario_id = ?");

                for ($i = 0; $i < count($_POST['insumo_id']); $i++) {
                    $ins_id = $_POST['insumo_id'][$i];
                    $nom_lib= $_POST['nombre_libre_ins'][$i] ?? null;
                    if (!$ins_id && trim($nom_lib) === '') continue;
                    
                    $real_ins_id = ($ins_id && $ins_id !== 'manual') ? $ins_id : null;
                    $real_nom = ($ins_id === 'manual') ? trim($nom_lib) : null;
                    
                    $c = (float)$_POST['cantidad_ha_ins'][$i] / $factor_division; // modo "total" → pasa a cant/ha
                    $p = (float)$_POST['precio_unitario_ins'][$i];
                    
                    $stmtInsertChild->execute([$op_id, $real_ins_id, $real_nom, $c, $p]);
                    
                    if ($real_ins_id) {
                        $cant_total = $c * $hectareas;
                        $stmtUpdateStock->execute([$cant_total, $real_ins_id, $usuario_id]);
                    }
                }
            }

        } elseif ($_POST['action'] === 'edit') {
            $id = (int)$_POST['id'];

            // Restaurar stock viejo
            $stmtOld = $pdo->prepare("SELECT tipo_componente, insumo_id, cantidad_ha, lote_id, hectareas FROM operaciones WHERE id = ? AND usuario_id = ?");
            $stmtOld->execute([$id, $usuario_id]);
            $old = $stmtOld->fetch();
            if ($old) {
                $stmtSupOld = $pdo->prepare("SELECT superficie FROM lotes WHERE id = ?");
                $stmtSupOld->execute([$old['lote_id']]);
                $supOld = (float)($stmtSupOld->fetchColumn() ?: 0);
                // Restaurar con las hectáreas guardadas; si la operación es vieja (NULL), usar la superficie del lote.
                $haOld = ($old['hectareas'] !== null) ? (float)$old['hectareas'] : $supOld;

                if ($old['tipo_componente'] === 'insumo' && $old['insumo_id']) {
                    $cantOld = (float)$old['cantidad_ha'] * $haOld;
                    $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?")
                        ->execute([$cantOld, $old['insumo_id'], $usuario_id]);
                }

                $stmtHijos = $pdo->prepare("SELECT insumo_id, cantidad_ha FROM operacion_insumos WHERE operacion_id = ?");
                $stmtHijos->execute([$id]);
                $stmtRestore = $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?");
                foreach ($stmtHijos->fetchAll() as $h) {
                    if ($h['insumo_id']) {
                        $cantRestore = (float)$h['cantidad_ha'] * $haOld;
                        $stmtRestore->execute([$cantRestore, $h['insumo_id'], $usuario_id]);
                    }
                }
            }

            // Borrar hijos
            $pdo->prepare("DELETE FROM operacion_insumos WHERE operacion_id = ?")->execute([$id]);

            // Actualizar padre
            $stmt = $pdo->prepare("UPDATE operaciones SET lote_id=?, cultivo_id=?, grupo_gasto=?, grupo_descripcion=?, tipo_componente=?, insumo_id=NULL, proveedor_servicio=?, cantidad_ha=?, precio_unitario=?, costo_total=?, moneda=?, fecha=?, campania_operacion=?, cultivo_operacion=?, cargas=?, hectareas=? WHERE id=? AND usuario_id=?");
            $stmt->execute([$lote_id, $cultivo_id, $grupo, $grupo_desc, $tipo_comp_db, $proveedor, $cant_ha, $precio_u, $costo_total, $moneda, $fecha, $campania, $cultivo, $cargas, $hectareas_db, $id, $usuario_id]);

            // Insertar hijos nuevos
            if (($tipo_comp === 'insumo' || $tipo_comp === 'receta_labor') && isset($_POST['insumo_id']) && is_array($_POST['insumo_id'])) {
                $stmtInsertChild = $pdo->prepare("INSERT INTO operacion_insumos (operacion_id, insumo_id, nombre_libre, cantidad_ha, precio_unitario) VALUES (?, ?, ?, ?, ?)");
                // Permite stock negativo: no se clampa a 0 ni se desactiva el insumo al llegar a cero.
                $stmtUpdateStock = $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual - ? WHERE id = ? AND usuario_id = ?");

                for ($i = 0; $i < count($_POST['insumo_id']); $i++) {
                    $ins_id = $_POST['insumo_id'][$i];
                    $nom_lib= $_POST['nombre_libre_ins'][$i] ?? null;
                    if (!$ins_id && trim($nom_lib) === '') continue;
                    
                    $real_ins_id = ($ins_id && $ins_id !== 'manual') ? $ins_id : null;
                    $real_nom = ($ins_id === 'manual') ? trim($nom_lib) : null;
                    
                    $c = (float)$_POST['cantidad_ha_ins'][$i] / $factor_division; // modo "total" → pasa a cant/ha
                    $p = (float)$_POST['precio_unitario_ins'][$i];
                    
                    $stmtInsertChild->execute([$id, $real_ins_id, $real_nom, $c, $p]);
                    
                    if ($real_ins_id) {
                        $cant_total = $c * $hectareas;
                        $stmtUpdateStock->execute([$cant_total, $real_ins_id, $usuario_id]);
                    }
                }
            }

        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            $stmtDel = $pdo->prepare("SELECT tipo_componente, insumo_id, cantidad_ha, lote_id, hectareas FROM operaciones WHERE id = ? AND usuario_id = ?");
            $stmtDel->execute([$id, $usuario_id]);
            $del = $stmtDel->fetch();
            if ($del) {
                $stmtDelSup = $pdo->prepare("SELECT superficie FROM lotes WHERE id = ?");
                $stmtDelSup->execute([$del['lote_id']]);
                $supDel = (float)($stmtDelSup->fetchColumn() ?: 0);
                // Restaurar con las hectáreas guardadas; si la operación es vieja (NULL), usar la superficie del lote.
                $haDel = ($del['hectareas'] !== null) ? (float)$del['hectareas'] : $supDel;

                if ($del['tipo_componente'] === 'insumo' && $del['insumo_id']) {
                    $cantRestore = (float)$del['cantidad_ha'] * $haDel;
                    $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?")
                        ->execute([$cantRestore, $del['insumo_id'], $usuario_id]);
                }
                $stmtHijos = $pdo->prepare("SELECT insumo_id, cantidad_ha FROM operacion_insumos WHERE operacion_id = ?");
                $stmtHijos->execute([$id]);
                $stmtRestore = $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?");
                foreach ($stmtHijos->fetchAll() as $h) {
                    if ($h['insumo_id']) {
                        $cantRestore = (float)$h['cantidad_ha'] * $haDel;
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

        /* Antes acá había un die() con el mensaje crudo de la excepción: pantalla
           en blanco, un error de SQL que el productor no puede interpretar, y el
           formulario perdido.

           Es especialmente malo en el borrado, que restaura stock de insumos: al
           ver un error sin explicación uno no sabe si el stock volvió o no. Por
           eso el mensaje dice explícitamente que no quedó nada a medias — que es
           verdad, porque el rollBack() de arriba deshace la transacción entera.

           El detalle técnico va al log, donde sirve; a la pantalla va lo que el
           productor necesita saber para decidir qué hacer. */
        error_log('[operaciones] ' . $_POST['action'] . ' falló: ' . $e->getMessage());

        $accion = $_POST['action'] ?? '';
        $que = $accion === 'delete' ? 'eliminar la operación'
             : ($accion === 'edit' ? 'guardar los cambios' : 'registrar el gasto');

        set_flash('error', 'No se pudo ' . $que . '. No quedó nada a medias: '
                         . 'la operación se deshizo completa y el stock quedó como estaba. '
                         . 'Probá de nuevo; si vuelve a pasar, avisanos.');
        header("Location: operaciones.php"); exit;
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
$q       = trim($_GET['q'] ?? '');

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
// Búsqueda universal: matchea cualquier campo de texto/numérico de la operación
// y de las tablas relacionadas (lote, cultivo, insumos) vía subconsultas EXISTS,
// para que funcione también en los COUNT/stats que no tienen JOINs.
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (
        o.proveedor_servicio LIKE ?
        OR o.grupo_descripcion LIKE ?
        OR o.grupo_gasto LIKE ?
        OR o.cultivo_operacion LIKE ?
        OR o.campania_operacion LIKE ?
        OR CAST(o.costo_total AS CHAR) LIKE ?
        OR CAST(o.cantidad_ha AS CHAR) LIKE ?
        OR CAST(o.precio_unitario AS CHAR) LIKE ?
        OR DATE_FORMAT(o.fecha, '%d/%m/%Y') LIKE ?
        OR EXISTS (SELECT 1 FROM lotes l2 WHERE l2.id = o.lote_id AND l2.nombre LIKE ?)
        OR EXISTS (SELECT 1 FROM cultivos c2 WHERE c2.id = o.cultivo_id AND c2.nombre LIKE ?)
        OR EXISTS (SELECT 1 FROM insumos i2 WHERE i2.id = o.insumo_id AND i2.nombre LIKE ?)
        OR EXISTS (SELECT 1 FROM operacion_insumos oi2 LEFT JOIN insumos i3 ON oi2.insumo_id = i3.id
                   WHERE oi2.operacion_id = o.id AND (i3.nombre LIKE ? OR oi2.nombre_libre LIKE ?))
    )";
    for ($k = 0; $k < 14; $k++) $params[] = $like;
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

/* 3. Totales por grupo (KPIs) — respetando filtros pero sin paginación.
   Se agrupa también por mes porque cada mes tiene su propia cotización: sumar
   primero y convertir después aplanaría gastos de meses distintos con un solo
   tipo de cambio, que es justo lo que el botón de moneda viene a evitar. */
$stmtStats = $pdo->prepare("
    SELECT o.grupo_gasto, o.moneda, DATE_FORMAT(o.fecha, '%Y-%m-01') AS mes,
           SUM(o.costo_total) as total
    FROM operaciones o
    $where
    GROUP BY o.grupo_gasto, o.moneda, DATE_FORMAT(o.fecha, '%Y-%m-01')
");
$stmtStats->execute($params);
$stats_raw = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

$totales_grupo = ['siembra'=>0,'cosecha'=>0,'pulverizacion'=>0,'fertilizacion'=>0,'otros'=>0,'total'=>0];
foreach ($stats_raw as $s) {
    $g = $s['grupo_gasto'];
    $c = moneda_convertir($pdo, $usuario_id, $s['total'], $s['moneda'], $s['mes']);
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
        'siembra'        => 'rgba(80,200,120,0.15);color:var(--accent);border:1px solid rgba(80,200,120,0.3)',
        'cosecha'        => 'oklch(0.470 0.120 70 / 0.10);color:var(--se-warning);border:1px solid oklch(0.470 0.120 70 / 0.10)',
        'pulverizacion'  => 'oklch(0.480 0.100 240 / 0.10);color: var(--accent);border:1px solid var(--border)',
        'fertilizacion'  => 'rgba(168,85,247,0.15);color:#c084fc;border:1px solid rgba(168,85,247,0.3)',
        default          => 'var(--border);color:var(--text-muted)',
    };
}
?>
<style>
.filter-toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.grupo-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.grupo-tab {
    padding: 7px 16px; border-radius: 20px; font-size: 0.82rem; font-weight: 600;
    cursor: pointer; border: 1px solid var(--border); background: var(--n-25);
    color: var(--text-muted); transition: all 0.2s; white-space: nowrap;
}
.grupo-tab:hover { border-color: var(--accent); color: var(--text-primary); }
.grupo-tab.active { background: var(--accent); color: var(--on-accent); border-color: var(--accent); box-shadow: 0 0 10px var(--accent-glow); }

    .currency-toggle-container {
        display: inline-flex;
        background: var(--n-100);
        padding: 4px;
        border-radius: 10px;
        border: 1px solid var(--border);
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
        background: var(--n-100);
    }
    .btn-currency.active {
        background: var(--accent);
        color: var(--on-accent) !important;
        box-shadow: 0 2px 8px var(--accent-soft);
    }
.filter-select { padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--n-100); color: var(--text-primary); font-size: 0.85rem; cursor: pointer; min-width: 160px; }
.filter-select:focus { outline: none; border-color: var(--accent); }

/* KPI Resumen Gastos */
.gastos-kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 10px; margin-bottom: 20px; }
.gasto-kpi {
    background: var(--n-25); border: 1px solid var(--border);
    border-radius: 12px; padding: 12px 14px;
    transition: transform 0.2s;
}
.gasto-kpi:hover { transform: translateY(-2px); }
.gasto-kpi .gk-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; margin-bottom: 4px; }
.gasto-kpi .gk-val   { font-size: 1.15rem; font-weight: 700; color: var(--text-primary); }
.gasto-kpi.total-kpi { background: var(--accent-soft); border-color: var(--accent-soft); }
.gasto-kpi.total-kpi .gk-val { color: var(--accent); }
</style>

<div class="glass-panel" style="margin-bottom: 24px;">
    <div class="panel-header">
        <h2 style="font-size: 1.2rem; font-weight: 500;">
            <i class="fas fa-tractor" style="color: var(--accent); margin-right: 8px;"></i>
            Matriz de Costos y Labores
        </h2>
        <div style="display:flex; gap:8px; flex-wrap: wrap;">
            <?php
            boton_exportar([
                ['etiqueta' => 'Excel', 'href' => 'api/reporte_excel.php?tipo=operaciones',
                 'icono' => 'fa-file-excel', 'color' => '#10b981', 'detalle' => 'Planilla editable'],
                ['etiqueta' => 'PDF',   'href' => 'api/reporte_pdf.php?tipo=operaciones',
                 'icono' => 'fa-file-pdf',   'color' => '#ff7b72', 'detalle' => 'Listo para imprimir',
                 'nueva_pestana' => true],
            ]);
            ?>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Registrar Gasto
            </button>
        </div>
    </div>

    <div style="display:flex; justify-content: flex-end; gap:8px; margin-bottom: 12px;">
        <?php moneda_toggle(); ?>
        <?php /* El título decía "Ver en Dinero (USD)" y mostraba pesos. Ahora la
                 moneda la elige el botón de al lado, así que este dice sólo lo que
                 hace: importe o porcentaje. */ ?>
        <div class="currency-toggle-container">
            <button type="button" class="btn-currency active" id="btnModeMoney" onclick="setKpiMode('money')" title="Ver el importe">
                <i class="fas fa-dollar-sign"></i>
            </button>
            <button type="button" class="btn-currency" id="btnModePercent" onclick="setKpiMode('percent')" title="Ver en porcentaje del total">
                <i class="fas fa-percent"></i>
            </button>
        </div>
    </div>

    <!-- ===== KPIs DE RESUMEN ===== -->
    <div class="gastos-kpi-grid" id="gastosKpiGrid">
        <div class="gasto-kpi" data-grupo="siembra">
            <div class="gk-label"><i class="fas fa-seedling" style="margin-right:4px;"></i> Siembra</div>
            <div class="gk-val" id="kpiSiembra" data-val="<?= $totales_grupo['siembra'] ?>"><?= ap_plata($totales_grupo['siembra'], 0) ?></div>
        </div>
        <div class="gasto-kpi" data-grupo="cosecha">
            <div class="gk-label"><i class="fas fa-wheat-awn" style="margin-right:4px;"></i> Cosecha</div>
            <div class="gk-val" id="kpiCosecha" data-val="<?= $totales_grupo['cosecha'] ?>"><?= ap_plata($totales_grupo['cosecha'], 0) ?></div>
        </div>
        <div class="gasto-kpi" data-grupo="pulverizacion">
            <div class="gk-label"><i class="fas fa-spray-can" style="margin-right:4px;"></i> Pulverización</div>
            <div class="gk-val" id="kpiPulv" data-val="<?= $totales_grupo['pulverizacion'] ?>"><?= ap_plata($totales_grupo['pulverizacion'], 0) ?></div>
        </div>
        <div class="gasto-kpi" data-grupo="fertilizacion">
            <div class="gk-label"><i class="fas fa-fill-drip" style="margin-right:4px;"></i> Fertilización</div>
            <div class="gk-val" id="kpiFert" data-val="<?= $totales_grupo['fertilizacion'] ?>"><?= ap_plata($totales_grupo['fertilizacion'], 0) ?></div>
        </div>
        <div class="gasto-kpi" data-grupo="otros">
            <div class="gk-label"><i class="fas fa-ellipsis-h" style="margin-right:4px;"></i> Otros</div>
            <div class="gk-val" id="kpiOtros" data-val="<?= $totales_grupo['otros'] ?>"><?= ap_plata($totales_grupo['otros'], 0) ?></div>
        </div>
        <div class="gasto-kpi total-kpi">
            <div class="gk-label">Total General</div>
            <div class="gk-val" id="kpiTotal" data-val="<?= $totales_grupo['total'] ?>"><?= ap_plata($totales_grupo['total'], 0) ?></div>
        </div>
    </div>

    <!-- ===== TOOLBAR DE FILTROS ===== -->
    <div style="background:var(--n-25); border-top:1px solid var(--border); border-bottom:1px solid var(--border); padding:16px 20px; margin: 0 -20px 20px -20px;" class="filter-toolbar">
        <div class="grupo-tabs">
            <button class="grupo-tab <?= $f_grupo === 'todos' ? 'active' : '' ?>" onclick="setFiltroGrupo('todos')"><i class="fas fa-layer-group"></i> Todos</button>
            <button class="grupo-tab <?= $f_grupo === 'siembra' ? 'active' : '' ?>" onclick="setFiltroGrupo('siembra')"><i class="fas fa-seedling"></i> Siembra</button>
            <button class="grupo-tab <?= $f_grupo === 'cosecha' ? 'active' : '' ?>" onclick="setFiltroGrupo('cosecha')"><i class="fas fa-wheat-awn"></i> Cosecha</button>
            <button class="grupo-tab <?= $f_grupo === 'pulverizacion' ? 'active' : '' ?>" onclick="setFiltroGrupo('pulverizacion')"><i class="fas fa-spray-can"></i> Pulv.</button>
            <button class="grupo-tab <?= $f_grupo === 'fertilizacion' ? 'active' : '' ?>" onclick="setFiltroGrupo('fertilizacion')"><i class="fas fa-fill-drip"></i> Fert.</button>
            <button class="grupo-tab <?= $f_grupo === 'otros' ? 'active' : '' ?>" onclick="setFiltroGrupo('otros')"><i class="fas fa-ellipsis-h"></i> Otros</button>
        </div>

        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <?php $buscador_placeholder = 'Buscar costo, labor, insumo, lote, fecha...'; include 'includes/buscador.php'; ?>
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
            <select class="filter-select" id="loteFilter" aria-label="Filtrar por lote" onchange="aplicarFiltros()">
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
                    <th>Costo Total (<?= moneda_actual() ?>)</th>
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
                    <?php /* El desglose describe la factura original, así que va en
                             LA MONEDA DE ESA FILA y no en la que se está mirando: si
                             se convirtiera, "100 ha × $X" dejaría de multiplicar al
                             monto que dice el papel. Se escribe el símbolo propio
                             para que no se confunda con el del total de al lado. */
                          $op_sim = ($op['moneda'] ?? 'ARS') === 'USD' ? 'US$' : '$'; ?>
                    <td data-label="Detalle" style="position:relative;">
                        <?php if($op['tipo_componente'] === 'labor'): ?>
                            <i class="fas fa-user-cog" title="Labor" style="color:var(--accent);"></i>
                            <b><?= htmlspecialchars($op['proveedor_servicio']) ?></b><br>
                            <small style="color:var(--text-muted);">
                                <?= number_format($op['cantidad_ha'], 1, ',', '.') ?> ha × <?= $op_sim ?><?= number_format($op['precio_unitario'], 2, ',', '.') ?>
                            </small>
                        <?php elseif($op['tipo_componente'] === 'insumo' && !empty($op['insumo_nombre'])): ?>
                            <i class="fas fa-box-open" title="Insumo" style="color: var(--accent);"></i>
                            <b><?= htmlspecialchars($op['insumo_nombre']) ?></b><br>
                            <small style="color:var(--text-muted);">
                                <?= $op['cantidad_ha'] ?> <?= $op['unidad_medida'] ?>/ha × <?= $op_sim ?><?= number_format($op['precio_unitario'], 2, ',', '.') ?>
                            </small>
                        <?php elseif($op['tipo_componente'] === 'receta_labor'): ?>
                            <i class="fas fa-file-invoice" title="Receta de Aplicación" style="color:var(--se-warning);"></i>
                            <b>Receta (Labor + Insumos)</b>
                            <div style="margin-top: 5px;">
                                <?php boton_exportar([
                                    ['etiqueta' => 'Excel', 'href' => 'api/excel_receta.php?id=' . (int)$op['id'],
                                     'icono' => 'fa-file-excel', 'color' => '#10b981', 'detalle' => 'Planilla editable'],
                                    ['etiqueta' => 'PDF',   'href' => 'api/pdf_receta.php?id=' . (int)$op['id'],
                                     'icono' => 'fa-file-pdf',   'color' => '#ff7b72', 'detalle' => 'Listo para imprimir',
                                     'nueva_pestana' => true],
                                ], 'Receta', 'exp-btn-sm'); ?>
                                <div style="display:inline-block; position:relative; margin-left:4px;">
                                    <button type="button" onclick="toggleInsumosList(<?= $op['id'] ?>)" class="btn" style="background:transparent; border:1px solid var(--border); padding:3px 8px; font-size:0.75rem; color:var(--text-primary); border-radius:6px;"><i class="fas fa-ellipsis-h"></i> Detalles</button>
                                    <div id="insumos-list-<?= $op['id'] ?>" style="display:none; position:absolute; z-index:99; background:var(--bg-card); border:1px solid var(--border); padding:10px; border-radius:8px; width:max-content; top:100%; left:0; box-shadow:0 4px 15px rgba(0,0,0,0.5); margin-top:5px;">
                                        <div style="font-size:0.8rem; border-bottom:1px solid var(--border); margin-bottom:5px; padding-bottom:5px;">
                                            <strong>Labor:</strong> <?= htmlspecialchars($op['proveedor_servicio']) ?> (<?= $op_sim ?><?= number_format($op['precio_unitario'], 2, ',', '.') ?>/ha)
                                        </div>
                                        <ul style="list-style:none; margin:0; padding:0; font-size:0.8rem; text-align:left;">
                                            <?php foreach($op['hijos_insumos'] as $h): ?>
                                            <li style="margin-bottom:4px; border-bottom:1px solid var(--border); padding-bottom:4px; color:var(--text-primary);">
                                                <strong><?= htmlspecialchars($h['nombre'] ?: $h['nombre_libre']) ?></strong>: <?= number_format($h['cantidad_ha'], 3, ',', '.') ?> <?= htmlspecialchars($h['unidad_medida'] ?: 'un/lts') ?>/ha (<?= $op_sim ?><?= number_format($h['precio_unitario'], 2, ',', '.') ?>)
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php elseif($op['tipo_componente'] === 'multi_insumo' || !empty($op['hijos_insumos'])): ?>
                            <i class="fas fa-box-open" title="Múltiples Insumos" style="color: var(--accent);"></i>
                            <b>Múltiples Insumos (<?= count($op['hijos_insumos']) ?>)</b>
                            <div style="display:inline-block; position:relative; margin-left:8px;">
                                <button type="button" onclick="toggleInsumosList(<?= $op['id'] ?>)" class="btn" style="background:transparent; border:1px solid var(--border); padding:2px 6px; font-size:0.75rem; color:var(--text-primary);"><i class="fas fa-ellipsis-h"></i></button>
                                <div id="insumos-list-<?= $op['id'] ?>" style="display:none; position:absolute; z-index:99; background:var(--bg-card); border:1px solid var(--border); padding:10px; border-radius:8px; width:max-content; top:100%; left:0; box-shadow:0 4px 15px rgba(0,0,0,0.5); margin-top:5px;">
                                    <ul style="list-style:none; margin:0; padding:0; font-size:0.8rem; text-align:left;">
                                        <?php foreach($op['hijos_insumos'] as $h): ?>
                                        <li style="margin-bottom:4px; border-bottom:1px solid var(--border); padding-bottom:4px; color:var(--text-primary);">
                                            <strong><?= htmlspecialchars($h['nombre'] ?: $h['nombre_libre']) ?></strong>: <?= number_format($h['cantidad_ha'], 3, ',', '.') ?> <?= htmlspecialchars($h['unidad_medida'] ?: 'un/lts') ?>/ha (<?= $op_sim ?><?= number_format($h['precio_unitario'], 2, ',', '.') ?>)
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
                    <?php /* Cada fila se convierte con la cotización del mes de SU
                             fecha. Y cuando se está mirando en la otra moneda se
                             muestra abajo lo que realmente se pagó: el convertido
                             sirve para comparar, el original es el que figura en la
                             factura. */
                          $op_conv = moneda_convertir($pdo, $usuario_id, $op['costo_total'], $op['moneda'] ?? 'ARS', $op['fecha']); ?>
                    <td data-label="Costo Total" style="font-weight: 600; color: var(--danger);">
                        <?= ap_egreso($op_conv) ?>
                        <?php if (($op['moneda'] ?? 'ARS') !== moneda_actual()): ?>
                            <br><small style="color:var(--text-muted); font-weight:400;">
                                pagado <?= ($op['moneda'] ?? 'ARS') === 'USD' ? 'US$' : '$' ?><?= number_format($op['costo_total'], 2, ',', '.') ?>
                            </small>
                        <?php endif; ?>
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
            <a href="?page=<?= $page-1 ?>&grupo=<?= $f_grupo ?>&lote_id=<?= $f_lote ?>&campania=<?= urlencode($f_camp) ?>&q=<?= urlencode($q) ?>" class="btn" style="background:var(--n-100); color:var(--text-primary); padding:8px 16px;"><i class="fas fa-chevron-left"></i> Anterior</a>
        <?php endif; ?>
        
        <span style="color:var(--text-muted); align-self:center; font-size:0.9rem;">Página <?= $page ?> de <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&grupo=<?= $f_grupo ?>&lote_id=<?= $f_lote ?>&campania=<?= urlencode($f_camp) ?>&q=<?= urlencode($q) ?>" class="btn" style="background:var(--n-100); color:var(--text-primary); padding:8px 16px;">Siguiente <i class="fas fa-chevron-right"></i></a>
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
                <label for="grupoGastoSelect">Grupo de Gasto</label>
                <select name="grupo_gasto" id="grupoGastoSelect" required
                    onchange="toggleGrupoDesc(); togglePulv();"
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: var(--text-primary);">
                    <option value="siembra">🌱 Siembra</option>
                    <option value="cosecha">🚜 Cosecha</option>
                    <option value="pulverizacion">🧪 Pulverización</option>
                    <option value="fertilizacion">💧 Fertilización</option>
                    <option value="otros">✏️ Otros Gastos</option>
                </select>
            </div>

            <!-- Campo libre para "Otros" -->
            <div id="grupoDescContainer" style="display: none; flex-direction: column; gap: 5px;">
                <label for="grupoDescInput">Descripción del Gasto <span style="color:var(--text-muted);font-size:0.85em;">(especificá cuál)</span></label>
                <input type="text" name="grupo_descripcion" id="grupoDescInput"
                    placeholder="Ej: Fletes, Análisis de suelo, Asesoramiento..."
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--accent); background: var(--accent-soft); color: var(--text-primary);">
            </div>

            <!-- Tipo Componente + Lote (side by side) -->
            <div class="form-grid-2">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label for="tipoCompSelect">Tipo Componente</label>
                    <select name="tipo_componente" id="tipoCompSelect" required onchange="toggleFormMode()"
                        style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: var(--text-primary);">
                        <option value="labor">👷 Mano de Obra (Labor)</option>
                        <option value="insumo">📦 Insumo</option>
                        <option value="receta_labor">🚜 Aplicación / Receta (Labor + Insumos)</option>
                    </select>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label for="loteSelect">Lote Afectado</label>
                    <select name="lote_id" id="loteSelect" required onchange="updateCultivos(); prefillHectareasFromLote();"
                        style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: var(--text-primary);">
                        <option value="">-- Seleccionar Lote --</option>
                        <?php foreach($lotes as $l): ?>
                            <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['nombre']) ?> (<?= $l['superficie'] ?> ha)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Campaña / Cultivo -->
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label for="cultivoSelect">Campaña / Cultivo Actual</label>
                <select name="form_cultivo" id="cultivoSelect"
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: var(--text-primary);">
                    <option value="">-- Seleccionar primero un lote --</option>
                </select>
            </div>

            <?php /* Moneda del gasto. Se guarda la que se pagó y la conversión se
                     hace al mirar: el panel tiene un botón ARS/USD que pasa todo a
                     la misma moneda usando la cotización del mes de cada
                     movimiento. Por eso no hace falta convertir de cabeza acá, y
                     por eso conviene no hacerlo: el contratista factura en pesos y
                     el fertilizante se compra en dólares, y guardar lo que
                     realmente se pagó es lo único que después se puede reconstruir. */ ?>
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label for="monedaSelect">Moneda del gasto</label>
                <select name="moneda" id="monedaSelect"
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: var(--text-primary);">
                    <option value="ARS" selected>$ — Pesos</option>
                    <option value="USD">US$ — Dólares</option>
                </select>
                <small style="color:var(--text-muted);">
                    <i class="fas fa-info-circle"></i>
                    Guardá lo que pagaste. El panel lo convierte con el dólar del mes.
                </small>
            </div>

            <!-- Modo de Ingreso (Insumos) -->
            <div id="modoCalculoContainer" style="display: none; flex-direction: column; gap: 5px; margin-top: 5px;">
                <label for="modoCalculoSelect">Modo de Ingreso para Insumos</label>
                <select name="modo_calculo" id="modoCalculoSelect" onchange="toggleFormMode(); updateCostoPreview();"
                    style="padding: 10px; border-radius: 6px; border: 1px dashed var(--accent); background: rgba(96,165,250,0.05); color: var(--text-primary);">
                    <option value="ha">Por Hectárea (Cant/Ha)</option>
                    <option value="total">Total del Lote (Se dividirá automáticamente por las hectáreas)</option>
                </select>
            </div>

            <!-- SECCIÓN LABOR -->
            <div id="sectionLabor" style="display: flex; flex-direction: column; gap: 14px; background:var(--n-25); padding:10px; border-radius:8px; border:1px solid var(--border);">
                <div style="font-size:0.9rem; font-weight:600; color:var(--text-muted); border-bottom:1px solid var(--border); padding-bottom:5px;">Datos de la Labor</div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label for="proveedorInput">Proveedor del Servicio</label>
                    <input type="text" name="proveedor_servicio" id="proveedorInput"
                        placeholder="Ej: Contratista Juan"
                        style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--n-0); color: var(--text-primary);">
                </div>
                <div class="form-grid-2">
                    <div style="display:none; flex-direction: column; gap: 5px;" id="divCargas">
                        <label for="cargasInput">Cantidad de Cargas <small>(Opcional)</small></label>
                        <input type="number" step="1" name="cargas" id="cargasInput" placeholder="Ej: 3"
                            style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--n-0); color: var(--text-primary);">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label for="cantHaLabor" id="lblCantHaLabor">Cantidad de Has.</label>
                        <input type="number" step="0.1" name="cantidad_ha" id="cantHaLabor" placeholder="Ej: 120"
                            style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--n-0); color: var(--text-primary);">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <?php /* Este decía pesos y el de los insumos decía dólares, en
                                 el mismo formulario. Ahora los dos siguen al selector
                                 de moneda de más arriba. */ ?>
                        <label for="priceHaLabor" class="lbl-moneda-precio" data-base="Precio / Ha.">Precio / Ha.</label>
                        <input type="number" step="0.0001" name="precio_unitario" id="priceHaLabor" placeholder="Ej: 4500"
                            style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--n-0); color: var(--text-primary);">
                    </div>
                </div>
            </div>

            <!-- SECCIÓN INSUMO (MULTI-INSUMO) -->
            <div id="sectionInsumo" style="display: none; flex-direction: column; gap: 14px; background:var(--n-25); padding:10px; border-radius:8px; border:1px solid var(--border);">
                <div style="font-size:0.9rem; font-weight:600; color:var(--text-muted); border-bottom:1px solid var(--border); padding-bottom:5px;">Insumos y Productos Utilizados</div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label for="hectareasInsumo">Hectáreas a aplicar <small style="color:var(--text-muted);">(se completa con las del lote, podés cambiarlo)</small></label>
                    <input type="number" step="0.01" name="hectareas_insumo" id="hectareasInsumo" placeholder="Ej: 50"
                        oninput="updateCostoPreview()"
                        style="padding: 10px; border-radius: 6px; border: 1px dashed var(--accent); background: rgba(96,165,250,0.05); color: var(--text-primary);">
                </div>
                <div id="insumosContainer" style="display:flex; flex-direction:column; gap:10px;">
                    <!-- Filas dinámicas irán aquí -->
                </div>
                <button type="button" class="btn" style="background:var(--n-100); border:1px dashed var(--border); align-self:flex-start;" onclick="addInsumoRow()">
                    <i class="fas fa-plus"></i> Añadir Insumo
                </button>
            </div>

            <!-- Fecha -->
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label for="fechaInput">Fecha</label>
                <input type="date" name="fecha" id="fechaInput" value="<?= date('Y-m-d') ?>" required
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--n-100); color: var(--text-primary);">
            </div>

            <!-- Preview costo calculado -->
            <div id="costoPreview" style="display:none; background: var(--accent-soft); border: 1px solid var(--accent-soft); border-radius: 8px; padding: 12px; text-align: center;">
                <span style="font-size:0.85rem; color:var(--text-muted);">Costo Total Estimado</span><br>
                <span id="costoPreviewVal" style="font-size:1.5rem; font-weight:800; color:var(--accent);">$0.00</span>
            </div>

            <!-- Vista previa del Excel de la receta (en vivo, solo modo receta) -->
            <div id="recetaPreviewWrap" style="display:none; flex-direction:column; gap:6px;">
                <span style="font-size:0.8rem; color:var(--text-muted); display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-file-excel" style="color:var(--accent);"></i> Vista previa del Excel (se actualiza al escribir)
                </span>
                <div id="recetaPreview" style="overflow-x:auto; background:#ffffff; border-radius:8px; padding:10px;"></div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px;">
                <button type="button" class="btn" onclick="closeOpModal()" style="background: rgba(255,255,255,0.1); color: var(--text-primary);">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
const lotesRaw   = <?= $lotes_json ?>;
const cultivosRaw = <?= $cultivos_json ?>;
const insumosRaw = <?= $insumos_json ?>;
// El símbolo de la moneda que se está mirando, para que los KPI que redibuja el
// JavaScript no digan otra cosa que los que pintó el PHP.
const AP_SIMBOLO = <?= json_encode(ap_simbolo()) ?>;
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
        // El mismo símbolo que el PHP: los data-val ya vienen convertidos.
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = AP_SIMBOLO + Math.round(parseFloat(el.dataset.val)).toLocaleString('es-AR');
        });
        document.getElementById('kpiTotal').textContent = AP_SIMBOLO + Math.round(total).toLocaleString('es-AR');
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
            <select name="insumo_id[]" onchange="onInsumoChangeRow(this)" style="padding:8px; border-radius:6px; border:1px solid var(--border); background:var(--bg-color); color:var(--text-primary);" ${req}>
                ${optionsHtml}
            </select>
            <input type="text" name="nombre_libre_ins[]" class="libre-input" value="${nom_libre}" oninput="updateCostoPreview()" placeholder="Escribe el nombre del insumo o labor..." style="display:${(!ins_id && nom_libre) ? 'block' : 'none'}; padding:8px; border-radius:6px; border:1px dashed var(--accent); background:var(--n-0); color:var(--text-primary); margin-top:5px;">
            <div class="stockIndicadorRow" style="display:none; font-size:0.8rem; margin-top:2px;"></div>
        </div>
        <div style="display:flex; gap:10px;">
            <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:0.8rem;" class="lbl-cant-ins">Cant/Ha</label>
                <input type="number" step="0.0001" name="cantidad_ha_ins[]" class="cant-ins-input" value="${cant}" oninput="updateCostoPreview()" placeholder="Ej: 0.15" style="padding:8px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);" ${req}>
            </div>
            <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:0.8rem;" class="lbl-moneda-precio" data-base="Precio unit.">Precio unit.</label>
                <input type="number" step="0.0001" name="precio_unitario_ins[]" class="price-ins-input" value="${price}" oninput="updateCostoPreview()" placeholder="Ej: 6.50" style="padding:8px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);" ${req}>
            </div>
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
    if (ins_id) {
        const selectElement = container.lastElementChild.querySelector('select');
        onInsumoChangeRow(selectElement, true);
    }
    toggleFormMode();
    sincronizarLabelsMoneda();
}

/* Las etiquetas de precio llevan el símbolo de la moneda elegida arriba.
   Antes una decía pesos y la otra dólares en el mismo formulario, y ésa
   es la contradicción que dejó cargas mal etiquetadas. Las filas de
   insumos se agregan por JS, así que hay que re-sincronizar al crearlas. */
function sincronizarLabelsMoneda() {
    const sel = document.getElementById('monedaSelect');
    const esUSD = !!sel && sel.value === 'USD';
    const sim = esUSD ? 'US$' : '$';
    document.querySelectorAll('.lbl-moneda-precio').forEach(l => {
        l.textContent = `${l.dataset.base} (${sim})`;
    });
    avisarPrecioCatalogo(esUSD);
}

/* El precio que copia el catálogo está en dólares. Si la operación quedó
   en pesos, avisamos en la fila misma en vez de corregir por nuestra
   cuenta: quien carga es el único que sabe qué pagó. */
function avisarPrecioCatalogo(esUSD) {
    document.querySelectorAll('.insumo-row[data-precio-catalogo="1"]').forEach(row => {
        const campo = row.querySelector('.price-ins-input');
        if (!campo) return;
        let nota = row.querySelector('.aviso-precio-usd');
        if (!nota) {
            nota = document.createElement('div');
            nota.className = 'aviso-precio-usd';
            nota.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' +
                'El precio del catálogo está en dólares. Cambiá la moneda a US$ ' +
                'o escribí el precio en pesos.';
            campo.parentElement.appendChild(nota);
        }
        nota.style.display = esUSD ? 'none' : 'block';
    });
}
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('monedaSelect');
    if (sel) sel.addEventListener('change', sincronizarLabelsMoneda);
    sincronizarLabelsMoneda();
});

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
        updateCostoPreview();
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

    // Al elegir un insumo, completar el precio con el precio cargado del insumo (editable).
    // skipPriceOverride=true solo al cargar una operación existente para respetar su precio guardado.
    // El catálogo guarda `precio_estimado_usd`: lo que se copia acá son DÓLARES. Si la
    // operación está en pesos, el número y la moneda no coinciden, que es justo como
    // quedaron mal etiquetadas las cargas viejas. No lo cambiamos solos —la regla de la
    // app es guardar lo que se pagó, no reinterpretarlo— pero sí lo avisamos.
    if (precio > 0 && !skipPriceOverride) {
        priceField.value = precio;
        row.dataset.precioCatalogo = '1';
    }

    ind.style.display = 'block';
    if (stock <= 0) {
        ind.style.color = '#ff7b72';
        ind.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Sin stock (${stock} ${unidad})`;
    } else {
        ind.style.color = 'var(--accent)';
        ind.innerHTML = `<i class="fas fa-check-circle"></i> Stock: ${stock} ${unidad}`;
    }
    sincronizarLabelsMoneda();
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

    // En receta, la labor se imputa SIEMPRE al total del lote: campo automático y bloqueado.
    const lblCantHa = document.getElementById('lblCantHaLabor');
    if (mode === 'receta_labor') {
        const lote = lotesRaw.find(l => l.id == document.getElementById('loteSelect').value);
        if (lote) cantLabor.value = parseFloat(lote.superficie);
        cantLabor.readOnly = true;
        cantLabor.style.opacity = '0.7';
        cantLabor.style.cursor = 'not-allowed';
        if (lblCantHa) lblCantHa.innerHTML = 'Cantidad de Has. <small style="color:var(--text-muted);">(automático: total del lote)</small>';
    } else {
        cantLabor.readOnly = false;
        cantLabor.style.opacity = '';
        cantLabor.style.cursor = '';
        if (lblCantHa) lblCantHa.textContent = 'Cantidad de Has.';
    }

    // Vista previa del Excel y ancho del modal (solo para recetas).
    const previewWrap = document.getElementById('recetaPreviewWrap');
    const modalPanel  = document.querySelector('#addOpModal .modal-panel');
    if (previewWrap) previewWrap.style.display = (mode === 'receta_labor') ? 'flex' : 'none';
    if (modalPanel)  modalPanel.style.maxWidth = (mode === 'receta_labor') ? '760px' : '520px';

    updateCostoPreview();
}

/* ───── Autocompletar hectáreas con la superficie del lote ───── */
function prefillHectareasFromLote() {
    const lote = lotesRaw.find(l => l.id == document.getElementById('loteSelect').value);
    const sup  = lote ? parseFloat(lote.superficie) : '';
    const hectField = document.getElementById('hectareasInsumo');
    if (hectField) hectField.value = sup;
    // En receta, la labor se imputa al TOTAL del lote → fijamos sus hectáreas.
    const mode = document.getElementById('tipoCompSelect').value;
    const cantLabor = document.getElementById('cantHaLabor');
    if (mode === 'receta_labor' && cantLabor) cantLabor.value = sup;
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
        // Hectáreas declaradas en el gasto (default = superficie del lote).
        const hectField = document.getElementById('hectareasInsumo');
        const hect = (hectField && parseFloat(hectField.value) > 0) ? parseFloat(hectField.value) : sup;
        const factor = (modoCalculo === 'total' && hect > 0) ? hect : 1;

        const rows = document.querySelectorAll('.insumo-row');
        rows.forEach(r => {
            const cant = (parseFloat(r.querySelector('.cant-ins-input').value) || 0) / factor;
            const price = parseFloat(r.querySelector('.price-ins-input').value) || 0;
            costo += (cant * price * hect);
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

    renderRecetaPreview();
}

/* ───── Vista previa del Excel de la receta (réplica en vivo) ───── */
function renderRecetaPreview() {
    const wrap = document.getElementById('recetaPreviewWrap');
    if (!wrap || document.getElementById('tipoCompSelect').value !== 'receta_labor') return;
    const cont = document.getElementById('recetaPreview');

    const fmt = (n, d = 2) => (parseFloat(n) || 0).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d });

    // Datos base
    const lote  = lotesRaw.find(l => l.id == document.getElementById('loteSelect').value);
    const loteNombre = lote ? lote.nombre.toUpperCase() : '—';
    const totalHa = lote ? parseFloat(lote.superficie) : 0;                 // total lote → labor
    const hectField = document.getElementById('hectareasInsumo');
    const haAplic = (hectField && parseFloat(hectField.value) > 0) ? parseFloat(hectField.value) : totalHa;  // insumos
    const cargas  = parseInt(document.getElementById('cargasInput')?.value) || 1;
    const haPorCarga = cargas > 0 ? (haAplic / cargas) : haAplic;
    const esSectorizada = haAplic < totalHa - 0.001;

    // Labor
    const gSel = document.getElementById('grupoGastoSelect');
    const gDesc = document.getElementById('grupoDescInput').value.trim();
    const titulo = (gDesc || (gSel ? gSel.options[gSel.selectedIndex].text.replace(/[^\wáéíóúñ\s/]/gi, '').trim() : 'RECETA')).toUpperCase();
    const laborPrecio = parseFloat(document.getElementById('priceHaLabor').value) || 0;
    const laborTotal  = laborPrecio * totalHa;

    // Fecha
    const fRaw = document.getElementById('fechaInput').value;
    const fecha = fRaw ? fRaw.split('-').reverse().join('/') : '';

    // Modo de cálculo de insumos (igual que en el costo estimado)
    const modoCalculo = document.getElementById('modoCalculoSelect')?.value || 'ha';
    const factor = (modoCalculo === 'total' && haAplic > 0) ? haAplic : 1;

    // Estilos de celdas (definidos ANTES del bucle: se usan dentro de él)
    const th = 'background:#0f172a;color:#fff;font-weight:bold;text-align:center;padding:5px 7px;border:1px solid #1a1a2e;';
    const td = 'text-align:center;padding:4px 7px;border:1px solid var(--text-muted);color:#0f172a;';

    // Insumos
    let filas = '';
    let totalInsumos = 0;
    document.querySelectorAll('#insumosContainer .insumo-row').forEach(r => {
        const sel = r.querySelector('select');
        const libre = r.querySelector('.libre-input');
        // Si hay texto manual cargado, ese nombre manda (insumo sin descontar stock).
        // Si no, se usa el nombre del insumo de stock seleccionado.
        let nombre = (libre && libre.value) ? libre.value.trim() : '';
        if (!nombre && sel && sel.value && sel.value !== 'manual') {
            nombre = (sel.options[sel.selectedIndex].text.split(' — ')[0] || '').trim();
        }
        const cantHa = (parseFloat(r.querySelector('.cant-ins-input').value) || 0) / factor;
        const precio = parseFloat(r.querySelector('.price-ins-input').value) || 0;
        if (!nombre && cantHa === 0) return; // fila vacía
        const cantCarga = cantHa * haPorCarga;
        const cantTotal = cantHa * haAplic;
        const costo = cantTotal * precio;
        totalInsumos += costo;
        filas += `<tr>
            <td style="${td}text-align:left;">${nombre ? nombre.toUpperCase() : '—'}</td>
            <td style="${td}">${fmt(cantHa, 3)}</td>
            <td style="${td}">${fmt(cantCarga)}</td>
            <td style="${td}">${fmt(cantTotal)}</td>
            <td style="${td}">${fmt(precio)}</td>
            <td style="${td}">${fmt(costo)}</td>
        </tr>`;
    });

    const grandTotal = totalInsumos + laborTotal;
    const grandTotalHa = totalHa > 0 ? (grandTotal / totalHa) : 0;

    cont.innerHTML = `
    <div style="font-family:Arial,sans-serif;font-size:12px;color:#0f172a;min-width:560px;">
        <div style="background:#ffff00;color:#ff0000;font-weight:bold;text-align:center;padding:6px;border:1px solid #1a1a2e;font-size:15px;">${titulo}</div>
        <div style="background:#ffff00;color:#ff0000;font-weight:bold;text-align:center;padding:4px;border:1px solid #1a1a2e;border-top:none;font-size:12px;">INSUMOS UTILIZADOS Y LABOR</div>

        <table style="border-collapse:collapse;width:100%;margin-top:8px;">
            <tr>
                <td colspan="3" style="background:#e6e6e6;color:#ff0000;font-weight:bold;text-align:center;padding:5px;border:1px solid #1a1a2e;">${loteNombre}</td>
                <td colspan="2" style="font-weight:bold;text-align:right;padding:5px;border:1px solid #1a1a2e;">FECHA: ${fecha}</td>
            </tr>
            <tr>
                <td colspan="2" style="${td}text-align:left;">Sup. lote: <b>${fmt(totalHa)} ha</b></td>
                <td colspan="3" style="${td}text-align:right;">Ha aplicadas: <b>${fmt(haAplic)} ha</b>${esSectorizada ? ' <span style="color:#ff0000;">· SECTORIZADA</span>' : ''}</td>
            </tr>
        </table>

        <table style="border-collapse:collapse;width:100%;margin-top:8px;">
            <tr>
                <th style="${th}">DETALLE LABOR</th><th style="${th}">CARGAS</th>
                <th style="${th}">HA/CARGA</th><th style="${th}">HA APLIC.</th><th style="${th}">TOTAL HA LOTE</th>
            </tr>
            <tr>
                <td style="${td}font-weight:bold;">${titulo}</td>
                <td style="${td}">${cargas}</td>
                <td style="${td}">${fmt(haPorCarga)}</td>
                <td style="${td}">${fmt(haAplic)}</td>
                <td style="${td}">${fmt(totalHa)}</td>
            </tr>
        </table>

        <table style="border-collapse:collapse;width:100%;margin-top:8px;">
            <tr>
                <th style="${th}text-align:left;">PRODUCTO</th><th style="${th}">/HA</th>
                <th style="${th}">POR CARGA</th><th style="${th}">TOTALES</th>
                <th style="${th}">PRECIO USD</th><th style="${th}">COSTO TOTAL</th>
            </tr>
            ${filas || `<tr><td colspan="6" style="${td}color:#64748b;">Sin insumos cargados</td></tr>`}
            <tr>
                <td colspan="5" style="text-align:right;font-weight:bold;background:#f1f5f9;border:1px solid var(--text-muted);border-top:2px solid #000;padding:5px 7px;">TOTAL INSUMOS</td>
                <td style="${td}font-weight:bold;background:#f1f5f9;border-top:2px solid #000;">${fmt(totalInsumos)}</td>
            </tr>
        </table>

        <table style="border-collapse:collapse;width:100%;margin-top:8px;">
            <tr><th style="${th}text-align:left;">LABOR</th><th style="${th}">USD/HA</th><th style="${th}">TOTAL</th></tr>
            <tr>
                <td style="${td}text-align:left;">${titulo} (sobre total del lote)</td>
                <td style="${td}">${fmt(laborPrecio)}</td>
                <td style="${td}">${fmt(laborTotal)}</td>
            </tr>
        </table>

        <table style="border-collapse:collapse;width:100%;margin-top:8px;">
            <tr>
                <td style="background:#ffff00;font-weight:bold;padding:6px 7px;border:1px solid #1a1a2e;width:40%;">COSTO TOTAL (USD)</td>
                <td style="background:#ffff00;font-weight:bold;text-align:center;padding:6px 7px;border:1px solid #1a1a2e;width:30%;">${fmt(grandTotal)}</td>
                <td style="background:#ffff00;font-weight:bold;text-align:center;padding:6px 7px;border:1px solid #1a1a2e;width:15%;">USD/HA</td>
                <td style="background:#ffff00;font-weight:bold;text-align:center;padding:6px 7px;border:1px solid #1a1a2e;width:15%;">${fmt(grandTotalHa)}</td>
            </tr>
        </table>
    </div>`;
}

// Attach preview listeners
['cantHaLabor','priceHaLabor','loteSelect','cargasInput','grupoDescInput','fechaInput','proveedorInput'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateCostoPreview);
});
['grupoGastoSelect','cultivoSelect'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', updateCostoPreview);
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
    document.getElementById('hectareasInsumo').value  = '';
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
        // Hectáreas guardadas; si la operación es vieja (NULL), usar la superficie del lote.
        const hectField = document.getElementById('hectareasInsumo');
        if (hectField) {
            const lote = lotesRaw.find(l => l.id == op.lote_id);
            hectField.value = (op.hectareas !== null && op.hectareas !== undefined && op.hectareas !== '')
                ? parseFloat(op.hectareas)
                : (lote ? parseFloat(lote.superficie) : '');
        }
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

<?php /* El chat va en todas las pantallas de Agricultura: el motor contesta sobre
         la campaña, no sobre la pantalla, así que la pregunta "cuánto gasté en
         siembra" vale igual acá que en el panel. */ ?>
<?php require_once 'includes/chat_motor.php'; ?>
<?php require_once 'includes/footer.php'; ?>
