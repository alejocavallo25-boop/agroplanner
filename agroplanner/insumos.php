<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Gestión de Insumos (Stock)';

validate_csrf();

// ─────────────────────────────────────────────────────────────────────────────
// POST ACTIONS
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Depósitos ──────────────────────────────────────────────────────────
    if ($_POST['action'] === 'add_deposito') {
        $stmt = $pdo->prepare("INSERT INTO depositos (usuario_id, nombre, descripcion, ubicacion) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $usuario_id,
            trim($_POST['dep_nombre']),
            !empty($_POST['dep_descripcion']) ? trim($_POST['dep_descripcion']) : null,
            !empty($_POST['dep_ubicacion'])   ? trim($_POST['dep_ubicacion'])   : null,
        ]);
        set_flash('success', 'Depósito creado exitosamente.');
        header("Location: insumos.php"); exit;

    } elseif ($_POST['action'] === 'edit_deposito') {
        $stmt = $pdo->prepare("UPDATE depositos SET nombre=?, descripcion=?, ubicacion=? WHERE id=? AND usuario_id=?");
        $stmt->execute([
            trim($_POST['dep_nombre']),
            !empty($_POST['dep_descripcion']) ? trim($_POST['dep_descripcion']) : null,
            !empty($_POST['dep_ubicacion'])   ? trim($_POST['dep_ubicacion'])   : null,
            (int)$_POST['dep_id'],
            $usuario_id,
        ]);
        set_flash('success', 'Depósito actualizado exitosamente.');
        header("Location: insumos.php"); exit;

    } elseif ($_POST['action'] === 'delete_deposito') {
        // Desasociar insumos primero, luego eliminar depósito
        $stmt = $pdo->prepare("UPDATE insumos SET deposito_id = NULL WHERE deposito_id = ? AND usuario_id = ?");
        $stmt->execute([(int)$_POST['dep_id'], $usuario_id]);
        $stmt = $pdo->prepare("DELETE FROM depositos WHERE id = ? AND usuario_id = ?");
        $stmt->execute([(int)$_POST['dep_id'], $usuario_id]);
        set_flash('success', 'Depósito eliminado exitosamente.');
        header("Location: insumos.php"); exit;

    // ── Insumos ────────────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
        $nombre       = trim($_POST['nombre']);
        $tipo         = $_POST['tipo_insumo'];
        $unidad       = $_POST['unidad_medida'];
        $precio       = (float)$_POST['precio_estimado_usd'];
        $stock        = isset($_POST['stock_actual']) ? (float)$_POST['stock_actual'] : 0;
        $unidad_stock = !empty($_POST['unidad_stock'])      ? trim($_POST['unidad_stock'])      : null;
        $fecha_venc   = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento']        : null;
        $deposito_id  = !empty($_POST['deposito_id'])       ? (int)$_POST['deposito_id']         : null;

        if ($_POST['action'] === 'add') {
            $stmt = $pdo->prepare("INSERT INTO insumos (usuario_id, nombre, tipo_insumo, unidad_medida, precio_estimado_usd, stock_actual, unidad_stock, fecha_vencimiento, deposito_id, stock_minimo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $nombre, $tipo, $unidad, $precio, $stock, $unidad_stock, $fecha_venc, $deposito_id, isset($_POST['stock_minimo']) && $_POST['stock_minimo'] !== '' ? (float)$_POST['stock_minimo'] : null]);
        } else {
            $stmt = $pdo->prepare("UPDATE insumos SET nombre=?, tipo_insumo=?, unidad_medida=?, precio_estimado_usd=?, stock_actual=?, unidad_stock=?, fecha_vencimiento=?, deposito_id=?, stock_minimo=? WHERE id=? AND usuario_id=?");
            $stmt->execute([$nombre, $tipo, $unidad, $precio, $stock, $unidad_stock, $fecha_venc, $deposito_id, isset($_POST['stock_minimo']) && $_POST['stock_minimo'] !== '' ? (float)$_POST['stock_minimo'] : null, (int)$_POST['id'], $usuario_id]);
        }
        set_flash('success', $_POST['action'] === 'add' ? 'Insumo creado exitosamente.' : 'Insumo actualizado exitosamente.');
        header("Location: insumos.php"); exit;

    } elseif ($_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("UPDATE insumos SET estado = 'inactivo' WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$_POST['id'], $usuario_id]);
        set_flash('success', 'Insumo eliminado exitosamente.');
        header("Location: insumos.php"); exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// DATOS
// ─────────────────────────────────────────────────────────────────────────────

// Depósitos del usuario
$stmt = $pdo->prepare("SELECT * FROM depositos WHERE usuario_id = ? ORDER BY nombre");
$stmt->execute([$usuario_id]);
$depositos = $stmt->fetchAll();
$depositos_json = json_encode($depositos);

// ─── Listado de insumos con Paginación ────────────────────────────────────
$limit = 30;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Filtros desde GET
$f_tipo = $_GET['tipo'] ?? 'todos';
$f_dep  = $_GET['deposito_id'] ?? 'todos';

$where = "WHERE i.usuario_id = ? AND i.estado = 'activo'";
$params = [$usuario_id];

if ($f_tipo !== 'todos') {
    $where .= " AND i.tipo_insumo = ?";
    $params[] = $f_tipo;
}
if ($f_dep !== 'todos') {
    if ($f_dep === 'sin') $where .= " AND i.deposito_id IS NULL";
    else                  { $where .= " AND i.deposito_id = ?"; $params[] = $f_dep; }
}

// 1. Obtener todos para resúmenes y alertas (sin paginar)
$stmtAll = $pdo->prepare("
    SELECT i.*, d.nombre AS deposito_nombre
    FROM insumos i
    LEFT JOIN depositos d ON i.deposito_id = d.id
    WHERE i.usuario_id = ? AND i.estado = 'activo'
");
$stmtAll->execute([$usuario_id]);
$insumos_full = $stmtAll->fetchAll();

// 2. Contar total filtrado para paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM insumos i $where");
$stmtCount->execute($params);
$total_rows = (int)$stmtCount->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 3. Obtener registros paginados
$stmt = $pdo->prepare("
    SELECT i.*, d.nombre AS deposito_nombre
    FROM insumos i
    LEFT JOIN depositos d ON i.deposito_id = d.id
    $where
    ORDER BY i.nombre ASC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$insumos = $stmt->fetchAll();

// Insumos con stock bajo su mínimo personalizado (usando set completo)
$alertas_stock = array_filter($insumos_full, fn($i) =>
    $i['stock_minimo'] !== null && (float)($i['stock_actual'] ?? 0) <= (float)$i['stock_minimo']
);

// Resumen por depósito (para las tarjetas) (usando set completo)
$resumen_dep = [];
foreach ($insumos_full as $ins) {
    $dep = $ins['deposito_nombre'] ?? '📦 Sin depósito';
    $dep_id = $ins['deposito_id'] ?? 'sin';
    if (!isset($resumen_dep[$dep_id])) {
        $resumen_dep[$dep_id] = [
            'nombre'   => $dep,
            'items'    => 0,
            'valor_usd'=> 0,
        ];
    }
    $resumen_dep[$dep_id]['items']++;
    $resumen_dep[$dep_id]['valor_usd'] += (float)($ins['stock_actual'] ?? 0) * (float)$ins['precio_estimado_usd'];
}

$hoy   = date('Y-m-d');
$en30d = date('Y-m-d', strtotime('+30 days'));

$conVenc = array_values(array_filter($insumos_full, fn($i) => !empty($i['fecha_vencimiento'])));
usort($conVenc, fn($a, $b) => strcmp($a['fecha_vencimiento'], $b['fecha_vencimiento']));

require_once 'includes/header.php';

function badgeVenc($fv, $hoy, $en30d) {
    if (!$fv) return '<span style="color:var(--text-muted);font-size:0.8em;">—</span>';
    if ($fv < $hoy)   return '<span class="badge" style="background:rgba(239,68,68,0.15);color:#ff7b72;border:1px solid rgba(239,68,68,0.3);">⚠ Vencido</span>';
    if ($fv <= $en30d) return '<span class="badge" style="background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);">⏰ Próximo</span>';
    $dias = (new DateTime($fv))->diff(new DateTime($hoy))->days;
    return '<span style="color:var(--accent);font-size:0.82em;">✓ '.$dias.'d</span>';
}

function tipoBadge($tipo) {
    $map = [
        'semilla'      => ['color'=>'#50c878','bg'=>'rgba(80,200,120,0.15)', 'border'=>'rgba(80,200,120,0.3)',  'icon'=>'fa-seedling'],
        'fertilizante' => ['color'=>'#60a5fa','bg'=>'rgba(59,130,246,0.15)', 'border'=>'rgba(59,130,246,0.3)',  'icon'=>'fa-flask'],
        'agroquimico'  => ['color'=>'#f59e0b','bg'=>'rgba(245,158,11,0.15)', 'border'=>'rgba(245,158,11,0.3)',  'icon'=>'fa-spray-can'],
        'inoculante'   => ['color'=>'#c084fc','bg'=>'rgba(168,85,247,0.15)', 'border'=>'rgba(168,85,247,0.3)',  'icon'=>'fa-vial'],
    ];
    $s = $map[$tipo] ?? ['color'=>'var(--text-muted)','bg'=>'rgba(255,255,255,0.05)','border'=>'rgba(255,255,255,0.1)','icon'=>'fa-box'];
    return '<span class="badge" style="background:'.$s['bg'].';color:'.$s['color'].';border:1px solid '.$s['border'].';"><i class="fas '.$s['icon'].'" style="margin-right:4px;font-size:0.75em;"></i>'.ucfirst($tipo).'</span>';
}
?>

<style>
/* ── TIPO TABS ── */
.tipo-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.tipo-tab {
    padding: 7px 16px; border-radius: 20px; font-size: 0.82rem; font-weight: 600;
    cursor: pointer; border: 1px solid var(--border); background: rgba(255,255,255,0.04);
    color: var(--text-muted); transition: all 0.2s; white-space: nowrap;
}
.tipo-tab:hover { border-color: var(--accent); color: var(--text-primary); }
.tipo-tab.active { background: var(--accent); color: #fff; border-color: var(--accent); box-shadow: 0 0 10px var(--accent-glow); }

/* ── DEPÓSITO CHIPS (filtro) ── */
.dep-filter-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px; font-size: 0.82rem; font-weight: 600;
    cursor: pointer; border: 1px solid var(--border); background: rgba(255,255,255,0.04);
    color: var(--text-muted); transition: all 0.2s; white-space: nowrap;
}
.dep-filter-btn:hover { border-color: #818cf8; color: #c7d2fe; }
.dep-filter-btn.active { background: rgba(99,102,241,0.2); color: #c7d2fe; border-color: rgba(99,102,241,0.5); }

/* ── TARJETAS DEPÓSITO ── */
.dep-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-bottom: 24px; }
.dep-card {
    background: rgba(99,102,241,0.06); border: 1px solid rgba(99,102,241,0.25);
    border-radius: 14px; padding: 18px; position: relative;
    transition: background 0.2s, transform 0.2s;
}
.dep-card:hover { background: rgba(99,102,241,0.12); transform: translateY(-2px); }
.dep-card-actions { position: absolute; top: 10px; right: 10px; display: flex; gap: 4px; opacity: 0; transition: opacity 0.2s; }
.dep-card:hover .dep-card-actions { opacity: 1; }

/* ── STOCK DISPLAY ── */
.stock-display { display: flex; flex-direction: column; gap: 4px; min-width: 90px; }
.stock-val { font-weight: 700; font-size: 1rem; }
.stock-unit { font-size: 0.78rem; color: var(--text-muted); }

/* ── VENC ── */
.venc-item { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-radius: 10px; border: 1px solid var(--border); background: rgba(255,255,255,0.02); transition: background 0.2s; }
.venc-item:hover { background: rgba(255,255,255,0.04); }
.venc-date { font-size: 0.8rem; font-weight: 700; min-width: 72px; text-align: center; padding: 6px 10px; border-radius: 8px; }
.venc-date.vencido { background: rgba(239,68,68,0.15); color: #ff7b72; }
.venc-date.proximo { background: rgba(245,158,11,0.15); color: #f59e0b; }
.venc-date.ok      { background: rgba(16,185,129,0.1);  color: var(--accent); }

/* ── SORT ── */
.sort-select { padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: var(--text-primary); font-size: 0.85rem; cursor: pointer; }
.sort-select:focus { outline: none; border-color: var(--accent); }
</style>

<!-- ===== ALERTAS DE STOCK ===== -->
<?php if (!empty($alertas_stock)): ?>
<div class="glass-panel" style="margin-bottom: 20px; border-color: rgba(245,158,11,0.3);">
    <h2 style="font-size:1rem; font-weight:600; margin-bottom:14px; color:#f59e0b;">
        <i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i>
        Alertas de Stock Bajo (<?= count($alertas_stock) ?> insumo<?= count($alertas_stock) > 1 ? 's' : '' ?>)
    </h2>
    <div style="display:flex; flex-wrap:wrap; gap:10px;">
        <?php foreach ($alertas_stock as $al): ?>
        <div style="display:flex; align-items:center; gap:10px; background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25); border-radius:10px; padding:10px 14px;">
            <i class="fas fa-box-open" style="color:#f59e0b;"></i>
            <div>
                <div style="font-weight:600; font-size:0.9rem;"><?= htmlspecialchars($al['nombre']) ?></div>
                <div style="font-size:0.78rem; color:var(--text-muted);">
                    Stock actual: <strong style="color:#f59e0b"><?= number_format((float)$al['stock_actual'],2,',','.') ?></strong>
                    &nbsp;/&nbsp; Mínimo: <strong><?= number_format((float)$al['stock_minimo'],2,',','.') ?></strong>
                    <?= $al['unidad_stock'] ? htmlspecialchars($al['unidad_stock']) : '' ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ===== TARJETAS DE DEPÓSITOS ===== -->
<div class="glass-panel" style="margin-bottom: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
        <h2 style="font-size:1.1rem; font-weight:600; margin:0;">
            <i class="fas fa-warehouse" style="color:#818cf8; margin-right:8px;"></i>
            Mis Depósitos / Almacenes
        </h2>
        <button class="btn" onclick="openDepositoModal()"
            style="background:rgba(99,102,241,0.2); border:1px solid rgba(99,102,241,0.4); color:#c7d2fe; font-size:0.85rem;">
            <i class="fas fa-plus"></i> Nuevo Depósito
        </button>
    </div>

    <?php if (empty($depositos)): ?>
    <div style="text-align:center; padding:24px; color:var(--text-muted); font-size:0.9rem;">
        <i class="fas fa-warehouse" style="font-size:2rem; opacity:0.2; display:block; margin-bottom:10px;"></i>
        No tenés depósitos cargados. Creá uno para organizar tus insumos por ubicación.
    </div>
    <?php else: ?>
    <div class="dep-grid">
        <?php foreach ($depositos as $dep):
            $dep_resumen = $resumen_dep[$dep['id']] ?? ['items'=>0,'valor_usd'=>0];
        ?>
        <div class="dep-card">
            <div class="dep-card-actions">
                <button type="button" class="btn"
                    style="padding:3px 7px; font-size:0.75rem; color:var(--accent); background:rgba(0,0,0,0.4); border-radius:6px;"
                    onclick='editDeposito(<?= json_encode($dep, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                    <i class="fas fa-edit"></i>
                </button>
                <form method="POST" style="display:inline;" onsubmit="if(!confirm('¿Eliminar este depósito? Los insumos asociados quedarán sin depósito.')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_deposito">
                    <input type="hidden" name="dep_id" value="<?= $dep['id'] ?>">
                    <button type="submit" class="btn"
                        style="padding:3px 7px; font-size:0.75rem; color:var(--danger); background:rgba(0,0,0,0.4); border-radius:6px;">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
            <div style="font-size:1.5rem; margin-bottom:8px;">🏚</div>
            <div style="font-weight:700; font-size:1rem; margin-bottom:4px;"><?= htmlspecialchars($dep['nombre']) ?></div>
            <?php if ($dep['ubicacion']): ?>
            <div style="font-size:0.78rem; color:var(--text-muted); margin-bottom:8px;">
                <i class="fas fa-map-pin" style="opacity:0.5;"></i> <?= htmlspecialchars($dep['ubicacion']) ?>
            </div>
            <?php endif; ?>
            <div style="display:flex; gap:14px; margin-top:10px;">
                <div>
                    <div style="font-size:1.3rem; font-weight:800; color:#a5b4fc;"><?= $dep_resumen['items'] ?></div>
                    <div style="font-size:0.72rem; color:var(--text-muted);">ítems</div>
                </div>
                <div>
                    <div style="font-size:1.1rem; font-weight:700; color:var(--accent);">$<?= number_format($dep_resumen['valor_usd'], 0, ',', '.') ?></div>
                    <div style="font-size:0.72rem; color:var(--text-muted);">valor est. USD</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Tarjeta: Sin depósito -->
        <?php if (isset($resumen_dep['sin']) && $resumen_dep['sin']['items'] > 0): ?>
        <div class="dep-card" style="background:rgba(255,255,255,0.02); border-color:rgba(255,255,255,0.1);">
            <div style="font-size:1.5rem; margin-bottom:8px; opacity:0.4;">📦</div>
            <div style="font-weight:600; font-size:0.95rem; color:var(--text-muted); margin-bottom:10px;">Sin depósito asignado</div>
            <div style="font-size:1.3rem; font-weight:800; color:var(--text-muted);"><?= $resumen_dep['sin']['items'] ?> <span style="font-size:0.7rem;">ítems</span></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===== TOOLBAR ===== -->
<div class="glass-panel" style="padding: 16px 20px; margin-bottom: 8px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">

        <!-- Filtro por tipo -->
        <div class="tipo-tabs">
            <button class="tipo-tab <?= $f_tipo === 'todos' ? 'active' : '' ?>" onclick="setFiltroTipo('todos')">🗂 Todos</button>
            <button class="tipo-tab <?= $f_tipo === 'semilla' ? 'active' : '' ?>" onclick="setFiltroTipo('semilla')">🌱 Semillas</button>
            <button class="tipo-tab <?= $f_tipo === 'fertilizante' ? 'active' : '' ?>" onclick="setFiltroTipo('fertilizante')">💧 Fertilizantes</button>
            <button class="tipo-tab <?= $f_tipo === 'agroquimico' ? 'active' : '' ?>" onclick="setFiltroTipo('agroquimico')">🧪 Agroquímicos</button>
            <button class="tipo-tab <?= $f_tipo === 'inoculante' ? 'active' : '' ?>" onclick="setFiltroTipo('inoculante')">🔬 Inoculantes</button>
            <button class="tipo-tab <?= $f_tipo === 'otro' ? 'active' : '' ?>" onclick="setFiltroTipo('otro')">📦 Otros</button>
        </div>

        <div style="display:flex; align-items:center; gap:8px;">
            <i class="fas fa-sort" style="color: var(--text-muted); font-size: 0.9rem;"></i>
            <select class="sort-select" id="sortSelect" onchange="setOrden(this.value)">
                <?php $f_order = $_GET['order'] ?? 'nombre-az'; ?>
                <option value="nombre-az" <?= $f_order === 'nombre-az' ? 'selected' : '' ?>>Nombre A → Z</option>
                <option value="nombre-za" <?= $f_order === 'nombre-za' ? 'selected' : '' ?>>Nombre Z → A</option>
                <option value="precio-asc" <?= $f_order === 'precio-asc' ? 'selected' : '' ?>>Precio ↑</option>
                <option value="precio-desc" <?= $f_order === 'precio-desc' ? 'selected' : '' ?>>Precio ↓</option>
                <option value="stock-asc" <?= $f_order === 'stock-asc' ? 'selected' : '' ?>>Stock ↑</option>
                <option value="stock-desc" <?= $f_order === 'stock-desc' ? 'selected' : '' ?>>Stock ↓</option>
                <option value="vencimiento" <?= $f_order === 'vencimiento' ? 'selected' : '' ?>>Vencimiento próximo</option>
            </select>
        </div>
    </div>

    <!-- Filtro por depósito -->
    <?php if (!empty($depositos)): ?>
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.05);">
        <button class="dep-filter-btn <?= $f_dep === 'todos' ? 'active' : '' ?>" onclick="setDeposito('todos')">🏚 Todos los depósitos</button>
        <?php foreach ($depositos as $dep): ?>
        <button class="dep-filter-btn <?= (string)$f_dep === (string)$dep['id'] ? 'active' : '' ?>" onclick="setDeposito('<?= $dep['id'] ?>')">
            <?= htmlspecialchars($dep['nombre']) ?>
        </button>
        <?php endforeach; ?>
        <button class="dep-filter-btn <?= $f_dep === 'sin' ? 'active' : '' ?>" onclick="setDeposito('sin')">📦 Sin depósito</button>
    </div>
    <?php endif; ?>
</div>

<!-- ===== TABLA DE INSUMOS ===== -->
<div class="glass-panel" style="margin-bottom: 24px;">
    <div class="panel-header">
        <h2 style="font-size: 1.2rem; font-weight: 500;">
            <i class="fas fa-boxes" style="color: var(--accent); margin-right: 8px;"></i>
            Inventario de Insumos
        </h2>
        <div style="display:flex; gap:8px;">
            <a id="pdfBtnInsumos" href="api/reporte_pdf.php?tipo=insumos" target="_blank"
               class="btn" style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#ff7b72; font-size:0.85rem;">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <button class="btn btn-primary" onclick="openNewInsumoModal()">
                <i class="fas fa-plus"></i> Nuevo Insumo
            </button>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Insumo</th>
                    <th>Precio Est. (USD)</th>
                    <th>Stock Actual</th>
                    <th>Vencimiento</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="insumosTbody">
                <?php foreach($insumos as $ins): ?>
                <tr data-tipo="<?= $ins['tipo_insumo'] ?>"
                    data-nombre="<?= strtolower(htmlspecialchars($ins['nombre'])) ?>"
                    data-precio="<?= (float)$ins['precio_estimado_usd'] ?>"
                    data-stock="<?= (float)($ins['stock_actual'] ?? 0) ?>"
                    data-venc="<?= $ins['fecha_vencimiento'] ?? '' ?>"
                    data-deposito="<?= $ins['deposito_id'] ?? 'sin' ?>">
                    <td data-label="Insumo">
                        <div style="font-size: 1.05rem; font-weight: 600; color: white; margin-bottom: 6px;">
                            <?= htmlspecialchars($ins['nombre']) ?>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                            <?= tipoBadge($ins['tipo_insumo']) ?>
                            <?php if ($ins['deposito_nombre']): ?>
                                <span style="display:inline-flex; align-items:center; gap:4px; background:rgba(99,102,241,0.12); color:#a5b4fc; border:1px solid rgba(99,102,241,0.25); border-radius:6px; padding:2px 6px; font-size:0.75rem; font-weight:600;">
                                    <i class="fas fa-warehouse" style="font-size:0.65rem;"></i>
                                    <?= htmlspecialchars($ins['deposito_nombre']) ?>
                                </span>
                            <?php else: ?>
                                <span style="display:inline-flex; align-items:center; gap:4px; background:rgba(255,255,255,0.05); color:var(--text-muted); border:1px solid rgba(255,255,255,0.1); border-radius:6px; padding:2px 6px; font-size:0.75rem;">
                                    <i class="fas fa-box" style="font-size:0.65rem;"></i> Sin depósito
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Precio Est.">
                        <span style="font-weight: 600;">$<?= number_format($ins['precio_estimado_usd'], 2, ',', '.') ?></span>
                    </td>
                    <td data-label="Stock Actual">
                        <?php $st = (float)($ins['stock_actual'] ?? 0); ?>
                        <div class="stock-display">
                            <span class="stock-val" style="color: <?= $st <= 0 ? 'var(--danger)' : ($st < 10 ? '#f59e0b' : 'var(--accent)') ?>;">
                                <?= number_format($st, 2, ',', '.') ?>
                            </span>
                            <?php if(!empty($ins['unidad_stock'])): ?>
                                <span class="stock-unit"><?= htmlspecialchars($ins['unidad_stock']) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Vencimiento">
                        <?= badgeVenc($ins['fecha_vencimiento'], $hoy, $en30d) ?>
                        <?php if(!empty($ins['fecha_vencimiento'])): ?>
                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                                <?= date('d/m/Y', strtotime($ins['fecha_vencimiento'])) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Acciones">
                        <button type="button" class="btn" style="color: var(--accent); background: transparent; padding: 4px 8px;"
                            onclick='editInsumo(<?= json_encode($ins, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="if(!confirm('¿Eliminar insumo?')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $ins['id'] ?>">
                            <button type="submit" class="btn" style="color: var(--danger); background: transparent; padding: 4px 8px;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($insumos) === 0): ?>
                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 40px;">
                    <i class="fas fa-box-open" style="font-size: 2rem; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                    No hay insumos registrados
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <div style="display:flex; justify-content: center; gap:10px; margin-top:20px; padding-bottom:10px;">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&tipo=<?= $f_tipo ?>&deposito_id=<?= $f_dep ?>&order=<?= $f_order ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;"><i class="fas fa-chevron-left"></i> Anterior</a>
        <?php endif; ?>
        
        <span style="color:var(--text-muted); align-self:center; font-size:0.9rem;">Página <?= $page ?> de <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&tipo=<?= $f_tipo ?>&deposito_id=<?= $f_dep ?>&order=<?= $f_order ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;">Siguiente <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===== CALENDARIO DE VENCIMIENTOS ===== -->
<?php if (count($conVenc) > 0): ?>
<div class="glass-panel" style="margin-bottom: 24px;">
    <h2 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 16px;">
        <i class="fas fa-calendar-alt" style="color: var(--warning); margin-right: 8px;"></i>
        Calendario de Vencimientos
    </h2>
    <div style="display: flex; flex-direction: column; gap: 10px;">
        <?php foreach($conVenc as $ins):
            $fv = $ins['fecha_vencimiento'];
            if($fv < $hoy) $cls = 'vencido';
            elseif($fv <= $en30d) $cls = 'proximo';
            else $cls = 'ok';
            $dias = (new DateTime($fv))->diff(new DateTime($hoy));
            $diasNum = (int)$dias->format('%r%a');
        ?>
        <div class="venc-item">
            <div class="venc-date <?= $cls ?>">
                <?= date('d/m', strtotime($fv)) ?><br>
                <span style="font-size:0.7em;opacity:0.8;"><?= date('Y', strtotime($fv)) ?></span>
            </div>
            <div style="flex: 1; min-width: 0;">
                <div style="font-weight: 600; font-size: 0.95rem;"><?= htmlspecialchars($ins['nombre']) ?></div>
                <div style="font-size: 0.8rem; color: var(--text-muted); display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:3px;">
                    <?= tipoBadge($ins['tipo_insumo']) ?>
                    &nbsp;Stock: <strong><?= number_format((float)($ins['stock_actual'] ?? 0), 2, ',', '.') ?> <?= htmlspecialchars($ins['unidad_stock'] ?? $ins['unidad_medida']) ?></strong>
                    <?php if ($ins['deposito_nombre']): ?>
                    &bull; <span style="color:#a5b4fc;"><i class="fas fa-warehouse" style="font-size:0.7rem;"></i> <?= htmlspecialchars($ins['deposito_nombre']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align: right; font-size: 0.82rem; white-space: nowrap;">
                <?php if($diasNum < 0): ?>
                    <span style="color: #ff7b72; font-weight: 700;">Venció hace <?= abs($diasNum) ?> días</span>
                <?php elseif($diasNum === 0): ?>
                    <span style="color: #f59e0b; font-weight: 700;">⚠ Vence hoy</span>
                <?php else: ?>
                    <span style="color: <?= $cls === 'proximo' ? '#f59e0b' : 'var(--accent)' ?>; font-weight: 600;">
                        En <?= $diasNum ?> días
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ===== MODAL: Nuevo/Editar Depósito ===== -->
<div id="depositoModal" class="modal-wrapper">
    <div class="glass-panel modal-panel">
        <h2 id="depModalTitle" style="margin-bottom: 20px;">Nuevo Depósito</h2>
        <form method="POST" style="display:flex; flex-direction:column; gap:14px;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action"  id="depAction" value="add_deposito">
            <input type="hidden" name="dep_id"  id="depId"     value="">

            <div style="display:flex; flex-direction:column; gap:5px;">
                <label>Nombre del Depósito <span style="color:var(--danger);">*</span></label>
                <input type="text" name="dep_nombre" id="depNombre" required
                    placeholder="Ej: Galpón Norte, Silo 1, Depósito Campo Sur"
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label>Descripción <small style="color:var(--text-muted);">(Opcional)</small></label>
                <input type="text" name="dep_descripcion" id="depDesc"
                    placeholder="Ej: Para herbicidas, semillas de soja..."
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label>Ubicación <small style="color:var(--text-muted);">(Opcional)</small></label>
                <input type="text" name="dep_ubicacion" id="depUbic"
                    placeholder="Ej: Ruta 9 Km 45, Campo El Ombú"
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:4px;">
                <button type="button" class="btn" onclick="closeDepModal()" style="background:rgba(255,255,255,0.1);color:white;">Cancelar</button>
                <button type="submit" class="btn" style="background:rgba(99,102,241,0.3); border:1px solid rgba(99,102,241,0.5); color:#c7d2fe;">
                    <i class="fas fa-save"></i> Guardar Depósito
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL: Nuevo/Editar Insumo ===== -->
<div id="insumoModal" class="modal-wrapper">
    <div class="glass-panel modal-panel">
        <h2 id="modalTitle" style="margin-bottom: 20px;">Nuevo Insumo</h2>
        <form id="insumoForm" method="POST" style="display: flex; flex-direction: column; gap: 14px;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="actionInput"   value="add">
            <input type="hidden" name="id"     id="insumoIdInput" value="">

            <!-- Nombre -->
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label>Nombre del Insumo</label>
                <input type="text" name="nombre" id="nombreInput" required
                    placeholder="Ej: Glifosato 64%, Urea, Soja Don Mario"
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;">
            </div>

            <!-- Tipo + Unidad + Depósito -->
            <div class="form-grid-2">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label>Tipo de Insumo</label>
                    <select name="tipo_insumo" id="tipoInput" required
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-color); color:white;">
                        <option value="semilla">🌱 Semilla</option>
                        <option value="fertilizante">💧 Fertilizante</option>
                        <option value="agroquimico">🧪 Agroquímico</option>
                        <option value="inoculante">🔬 Inoculante</option>
                        <option value="otro">📦 Otro</option>
                    </select>
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label>Unidad de Medida</label>
                    <select name="unidad_medida" id="unidadInput" required
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-color); color:white;">
                        <option value="kg">Kilogramos (kg)</option>
                        <option value="lt">Litros (lt)</option>
                        <option value="dosis">Dosis</option>
                        <option value="bolsa">Bolsa</option>
                    </select>
                </div>
            </div>

            <!-- Depósito -->
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label>
                    <i class="fas fa-warehouse" style="color:#818cf8; margin-right:5px;"></i>
                    Depósito / Almacén
                    <small style="color:var(--text-muted);">(Opcional)</small>
                </label>
                <select name="deposito_id" id="depositoInput"
                    style="padding:10px; border-radius:6px; border:1px solid rgba(99,102,241,0.4); background:var(--bg-color); color:white;">
                    <option value="">— Sin depósito asignado —</option>
                    <?php foreach($depositos as $dep): ?>
                    <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['nombre']) ?><?= $dep['ubicacion'] ? ' · '.$dep['ubicacion'] : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($depositos)): ?>
                <small style="color:var(--text-muted);">
                    <i class="fas fa-info-circle"></i>
                    Podés crear depósitos en la sección de arriba para organizar tus insumos.
                </small>
                <?php endif; ?>
            </div>

            <div style="display:flex; flex-direction:column; gap:5px;">
                <label>Stock Mínimo (Punto de Reorden) <small style="color:var(--text-muted);">(Opcional)</small></label>
                <input type="number" step="0.01" name="stock_minimo" id="stockMinInput"
                    placeholder="Ej: 50 — se alertará cuando llegue a este valor"
                    style="padding:10px; border-radius:6px; border:1px solid rgba(245,158,11,0.4); background:rgba(245,158,11,0.05); color:white;">
            </div>

            <!-- Precio + Stock -->
            <div class="form-grid-2">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label>Precio Est. (USD / Unidad)</label>
                    <input type="number" step="0.01" name="precio_estimado_usd" id="precioInput" required
                        placeholder="Ej: 6.50"
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;">
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label>Stock Actual</label>
                    <input type="number" step="0.01" name="stock_actual" id="stockInput"
                        placeholder="Ej: 200"
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;">
                </div>
            </div>

            <!-- Unidad Stock + Vencimiento -->
            <div class="form-grid-2">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label>Unidad de Stock <small style="color:var(--text-muted);">(Opcional)</small></label>
                    <input type="text" name="unidad_stock" id="unidadStockInput"
                        placeholder="Ej: litros, bolsas, kg"
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;">
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label>Fecha Vencimiento <small style="color:var(--text-muted);">(Opcional)</small></label>
                    <input type="date" name="fecha_vencimiento" id="vencInput"
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:white;">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:6px;">
                <button type="button" class="btn" onclick="closeModal()" style="background: rgba(255,255,255,0.1); color: white;">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
// ─── MODAL DEPÓSITO ─────────────────────────────────────────────────────────
function openDepositoModal() {
    document.getElementById('depModalTitle').innerText = '🏚 Nuevo Depósito';
    document.getElementById('depAction').value = 'add_deposito';
    document.getElementById('depId').value     = '';
    document.getElementById('depNombre').value = '';
    document.getElementById('depDesc').value   = '';
    document.getElementById('depUbic').value   = '';
    document.getElementById('depositoModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function editDeposito(dep) {
    document.getElementById('depModalTitle').innerText = '✏️ Editar Depósito';
    document.getElementById('depAction').value = 'edit_deposito';
    document.getElementById('depId').value     = dep.id;
    document.getElementById('depNombre').value = dep.nombre;
    document.getElementById('depDesc').value   = dep.descripcion || '';
    document.getElementById('depUbic').value   = dep.ubicacion   || '';
    document.getElementById('depositoModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeDepModal() { 
    document.getElementById('depositoModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

// ─── MODAL INSUMO ───────────────────────────────────────────────────────────
const modal       = document.getElementById('insumoModal');
const form        = document.getElementById('insumoForm');
const title       = document.getElementById('modalTitle');
const actionInput = document.getElementById('actionInput');
const idInput     = document.getElementById('insumoIdInput');

function openNewInsumoModal() {
    title.innerText   = '➕ Nuevo Insumo';
    actionInput.value = 'add';
    idInput.value     = '';
    form.reset();
    modal.style.display = 'block';
    document.body.classList.add('modal-open');
}

function editInsumo(ins) {
    title.innerText   = '✏️ Editar Insumo';
    actionInput.value = 'edit';
    idInput.value     = ins.id;

    document.getElementById('nombreInput').value      = ins.nombre;
    document.getElementById('tipoInput').value        = ins.tipo_insumo;
    document.getElementById('unidadInput').value      = ins.unidad_medida;
    document.getElementById('precioInput').value      = ins.precio_estimado_usd;
    document.getElementById('stockInput').value       = ins.stock_actual ?? '';
    document.getElementById('stockMinInput').value    = ins.stock_minimo ?? '';
    document.getElementById('unidadStockInput').value = ins.unidad_stock ?? '';
    document.getElementById('vencInput').value        = ins.fecha_vencimiento ?? '';
    document.getElementById('depositoInput').value    = ins.deposito_id ?? '';

    modal.style.display = 'block';
    document.body.classList.add('modal-open');
}

function closeModal() { 
    modal.style.display = 'none';
    document.body.classList.remove('modal-open');
}

window.addEventListener('click', e => {
    if (e.target === modal) closeModal();
    if (e.target === document.getElementById('depositoModal')) closeDepModal();
});

// ─── FILTER + SORT POR SERVIDOR ───────────────────────────────────────────
function setFiltroTipo(tipo) {
    const url = new URL(window.location);
    url.searchParams.set('tipo', tipo);
    url.searchParams.set('page', 1);
    window.location.href = url.href;
}

function setDeposito(dep) {
    const url = new URL(window.location);
    url.searchParams.set('deposito_id', dep);
    url.searchParams.set('page', 1);
    window.location.href = url.href;
}

function setOrden(val) {
    const url = new URL(window.location);
    url.searchParams.set('order', val);
    url.searchParams.set('page', 1);
    window.location.href = url.href;
}
</script>

<?php require_once 'includes/footer.php'; ?>
