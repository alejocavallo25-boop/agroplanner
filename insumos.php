<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
require_once 'includes/exportar.php';
// Muestra los importes en pesos o en dólares; no cambia nada de lo guardado.
require_once 'includes/moneda.php';
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

    // ── Importación asistida ───────────────────────────────────────────────
    //
    // Llega el JSON que el usuario revisó y confirmó en pantalla. Nada de lo que
    // viene se da por bueno: el payload lo arma el navegador y podría estar
    // manipulado, así que se revalida campo por campo y los ids ajenos se
    // descartan en silencio.
    } elseif ($_POST['action'] === 'import_guardar') {

        $filas = json_decode($_POST['items'] ?? '', true);
        if (!is_array($filas) || !$filas) {
            set_flash('error', 'No llegó ninguna fila para cargar.');
            header("Location: insumos.php"); exit;
        }

        // Depósitos e insumos que realmente son de este usuario.
        $stmt = $pdo->prepare("SELECT id FROM depositos WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        $depOk = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        $stmt = $pdo->prepare("SELECT id FROM insumos WHERE usuario_id = ? AND estado = 'activo'");
        $stmt->execute([$usuario_id]);
        $insOk = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        $tiposOk    = ['semilla', 'fertilizante', 'agroquimico', 'inoculante', 'otro'];
        $unidadesOk = ['kg', 'lt', 'dosis', 'bolsa'];
        $creados = 0; $sumados = 0; $omitidos = 0;

        /* ── En qué moneda venían los precios del comprobante ──────────────────
         *
         * ESTO ARREGLA UN ERROR DE MIL VECES. La columna del catálogo es
         * precio_estimado_usd y toda la aplicación la trata como dólares: la
         * lista de insumos la muestra con moneda_convertir(..., 'USD') y la
         * valuación del depósito multiplica el stock por ese número. Pero acá se
         * guardaba tal cual lo que decía el remito, que viene en pesos.
         *
         * Una urea a $1.245,50 quedaba guardada como US$1.245,50 y la pantalla
         * la mostraba a más de un millón setecientos mil el kilo, con el
         * depósito entero inflado en la misma proporción.
         *
         * Ahora el comprobante declara su moneda —el productor la elige en la
         * pantalla de revisión, en pesos por defecto— y si viene en pesos se
         * convierte una sola vez, acá, antes de guardar. Se convierte al
         * escribir y no al leer porque la columna es dólares por contrato: no
         * hay dónde anotar que esta fila vino en otra moneda. */
        $moneda_doc = (($_POST['moneda'] ?? 'ARS') === 'USD') ? 'USD' : 'ARS';
        $cotizacion = 0.0;
        if ($moneda_doc === 'ARS') {
            require_once 'includes/dolar.php';
            dolar_asegurar_tabla($pdo);
            $cotizacion = (float)dolar_referencia($pdo, $usuario_id)['valor'];
            if ($cotizacion <= 0) {
                set_flash('error', 'No hay ninguna cotización del dólar cargada, y los precios del '
                                 . 'comprobante están en pesos. Cargá el tipo de cambio y volvé a importar.');
                header("Location: insumos.php"); exit;
            }
        }
        /** El precio como lo guarda el catálogo: siempre en dólares. */
        $aDolares = fn(float $p): float => $moneda_doc === 'USD' ? $p : $p / $cotizacion;

        try {
            $pdo->beginTransaction();

            $insertar = $pdo->prepare(
                "INSERT INTO insumos (usuario_id, nombre, tipo_insumo, unidad_medida, precio_estimado_usd,
                                      stock_actual, unidad_stock, fecha_vencimiento, deposito_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            // Al sumar, el comprobante completa pero no borra: el precio se
            // actualiza sólo si el remito trae uno, y el vencimiento y el depósito
            // sólo si estaban vacíos. Un remito no tiene por qué pisar lo que el
            // productor ya había cargado a mano.
            $sumar = $pdo->prepare(
                "UPDATE insumos
                    SET stock_actual        = COALESCE(stock_actual, 0) + ?,
                        precio_estimado_usd = CASE WHEN ? > 0 THEN ? ELSE precio_estimado_usd END,
                        fecha_vencimiento   = COALESCE(fecha_vencimiento, ?),
                        deposito_id         = COALESCE(deposito_id, ?)
                  WHERE id = ? AND usuario_id = ?"
            );

            foreach ($filas as $f) {
                if (!is_array($f)) { $omitidos++; continue; }

                $cantidad = (float)($f['cantidad'] ?? 0);
                $precio   = (float)($f['precio'] ?? 0);
                if ($cantidad < 0) $cantidad = 0;
                if ($precio   < 0) $precio   = 0;
                // Del papel a la moneda del catálogo, una sola vez y en un solo lugar.
                $precio = $precio > 0 ? round($aDolares($precio), 4) : 0.0;

                $venc = trim((string)($f['vencimiento'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc)
                    || !checkdate((int)substr($venc, 5, 2), (int)substr($venc, 8, 2), (int)substr($venc, 0, 4))) {
                    $venc = null;
                }

                $dep = (isset($f['deposito_id']) && $f['deposito_id'] !== '') ? (int)$f['deposito_id'] : null;
                if ($dep !== null && !isset($depOk[$dep])) $dep = null;

                if (($f['modo'] ?? 'nuevo') === 'sumar') {
                    $id = (int)($f['insumo_id'] ?? 0);
                    if (!isset($insOk[$id])) { $omitidos++; continue; }
                    $sumar->execute([$cantidad, $precio, $precio, $venc, $dep, $id, $usuario_id]);
                    $sumados++;
                    continue;
                }

                $nombre = trim((string)($f['nombre'] ?? ''));
                if ($nombre === '') { $omitidos++; continue; }
                $nombre = mb_substr($nombre, 0, 150, 'UTF-8');

                $tipo   = in_array($f['tipo'] ?? '', $tiposOk, true)             ? $f['tipo']          : 'otro';
                $unidad = in_array($f['unidad_medida'] ?? '', $unidadesOk, true) ? $f['unidad_medida'] : 'kg';
                $unidadStock = trim((string)($f['unidad_stock'] ?? ''));
                $unidadStock = $unidadStock === '' ? null : mb_substr($unidadStock, 0, 50, 'UTF-8');

                $insertar->execute([$usuario_id, $nombre, $tipo, $unidad, $precio, $cantidad, $unidadStock, $venc, $dep]);
                $creados++;
            }

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[import_guardar] ' . $e->getMessage());
            set_flash('error', 'No se pudo completar la importación: no se guardó ninguna fila.');
            header("Location: insumos.php"); exit;
        }

        $partes = [];
        if ($creados)  $partes[] = $creados . ' insumo'  . ($creados  > 1 ? 's' : '') . ' nuevo' . ($creados > 1 ? 's' : '');
        if ($sumados)  $partes[] = 'stock sumado a ' . $sumados . ' existente' . ($sumados > 1 ? 's' : '');
        if ($omitidos) $partes[] = $omitidos . ' fila' . ($omitidos > 1 ? 's' : '') . ' omitida' . ($omitidos > 1 ? 's' : '');

        set_flash($creados || $sumados ? 'success' : 'error',
            $partes ? 'Importación lista: ' . implode(', ', $partes) . '.' : 'No se cargó ninguna fila.');
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
$q      = trim($_GET['q'] ?? '');

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
// Búsqueda universal: nombre, tipo, unidad, precio, stock, vencimiento y depósito.
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (
        i.nombre LIKE ?
        OR i.tipo_insumo LIKE ?
        OR i.unidad_medida LIKE ?
        OR i.unidad_stock LIKE ?
        OR CAST(i.precio_estimado_usd AS CHAR) LIKE ?
        OR CAST(i.stock_actual AS CHAR) LIKE ?
        OR DATE_FORMAT(i.fecha_vencimiento, '%d/%m/%Y') LIKE ?
        OR EXISTS (SELECT 1 FROM depositos d2 WHERE d2.id = i.deposito_id AND d2.nombre LIKE ?)
    )";
    for ($k = 0; $k < 8; $k++) $params[] = $like;
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
    if ($fv < $hoy)   return '<span class="badge" style="background:oklch(0.450 0.160 28 / 0.10);color:var(--danger);border:1px solid oklch(0.450 0.160 28 / 0.10);">⚠ Vencido</span>';
    if ($fv <= $en30d) return '<span class="badge" style="background:oklch(0.470 0.120 70 / 0.10);color:var(--se-warning);border:1px solid oklch(0.470 0.120 70 / 0.10);">⏰ Próximo</span>';
    $dias = (new DateTime($fv))->diff(new DateTime($hoy))->days;
    return '<span style="color:var(--accent);font-size:0.82em;">✓ '.$dias.'d</span>';
}

function tipoBadge($tipo) {
    $map = [
        'semilla'      => ['color'=>'var(--accent)','bg'=>'rgba(80,200,120,0.15)', 'border'=>'rgba(80,200,120,0.3)',  'icon'=>'fa-seedling'],
        'fertilizante' => ['color'=>'var(--accent)','bg'=>'oklch(0.480 0.100 240 / 0.10)', 'border'=>'oklch(0.480 0.100 240 / 0.10)',  'icon'=>'fa-flask'],
        'agroquimico'  => ['color'=>'var(--se-warning)','bg'=>'oklch(0.470 0.120 70 / 0.10)', 'border'=>'oklch(0.470 0.120 70 / 0.10)',  'icon'=>'fa-spray-can'],
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
    cursor: pointer; border: 1px solid var(--border); background: var(--n-25);
    color: var(--text-muted); transition: all 0.2s; white-space: nowrap;
}
.tipo-tab:hover { border-color: var(--accent); color: var(--text-primary); }
.tipo-tab.active { background: var(--accent); color: var(--on-accent); border-color: var(--accent); box-shadow: 0 0 10px var(--accent-glow); }

/* ── DEPÓSITO CHIPS (filtro) ── */
.dep-filter-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px; font-size: 0.82rem; font-weight: 600;
    cursor: pointer; border: 1px solid var(--border); background: var(--n-25);
    color: var(--text-muted); transition: all 0.2s; white-space: nowrap;
}
.dep-filter-btn:hover { border-color: var(--accent); color: var(--accent); }
.dep-filter-btn.active { background:var(--accent-soft); color:var(--accent); border-color: var(--border); }

/* ── TARJETAS DEPÓSITO ── */
.dep-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-bottom: 24px; }
.dep-card {
    background:var(--n-0); border:1px solid var(--border);
    border-radius: 14px; padding: 18px; position: relative;
    transition: background 0.2s, transform 0.2s;
}
.dep-card:hover { background: var(--n-50); transform: translateY(-2px); }
.dep-card-actions { position: absolute; top: 10px; right: 10px; display: flex; gap: 4px; opacity: 0; transition: opacity 0.2s; }
.dep-card:hover .dep-card-actions { opacity: 1; }

/* ── STOCK DISPLAY ── */
.stock-display { display: flex; flex-direction: column; gap: 4px; min-width: 90px; }
.stock-val { font-weight: 700; font-size: 1rem; }
.stock-unit { font-size: 0.78rem; color: var(--text-muted); }

/* ── VENC ── */
.venc-item { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-radius: 10px; border: 1px solid var(--border); background: var(--n-25); transition: background 0.2s; }
.venc-item:hover { background: var(--n-25); }
.venc-date { font-size: 0.8rem; font-weight: 700; min-width: 72px; text-align: center; padding: 6px 10px; border-radius: 8px; }
.venc-date.vencido { background: oklch(0.450 0.160 28 / 0.10); color: var(--danger); }
.venc-date.proximo { background: oklch(0.470 0.120 70 / 0.10); color: var(--se-warning); }
.venc-date.ok      { background: var(--accent-soft);  color: var(--accent); }

/* ── SORT ── */
.sort-select { padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--n-100); color: var(--text-primary); font-size: 0.85rem; cursor: pointer; }
.sort-select:focus { outline: none; border-color: var(--accent); }
</style>

<!-- ===== ALERTAS DE STOCK ===== -->
<?php if (!empty($alertas_stock)): ?>
<div class="glass-panel" style="margin-bottom: 20px; border-color: oklch(0.470 0.120 70 / 0.10);">
    <h2 style="font-size:1rem; font-weight:600; margin-bottom:14px; color:var(--se-warning);">
        <i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i>
        Alertas de Stock Bajo (<?= count($alertas_stock) ?> insumo<?= count($alertas_stock) > 1 ? 's' : '' ?>)
    </h2>
    <div style="display:flex; flex-wrap:wrap; gap:10px;">
        <?php foreach ($alertas_stock as $al): ?>
        <div style="display:flex; align-items:center; gap:10px; background:oklch(0.470 0.120 70 / 0.10); border:1px solid oklch(0.470 0.120 70 / 0.10); border-radius:10px; padding:10px 14px;">
            <i class="fas fa-box-open" style="color:var(--se-warning);"></i>
            <div>
                <div style="font-weight:600; font-size:0.9rem;"><?= htmlspecialchars($al['nombre']) ?></div>
                <div style="font-size:0.78rem; color:var(--text-muted);">
                    Stock actual: <strong style="color:var(--se-warning)"><?= number_format((float)$al['stock_actual'],2,',','.') ?></strong>
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
            <i class="fas fa-warehouse" style="color: var(--accent); margin-right:8px;"></i>
            Mis Depósitos / Almacenes
        </h2>
        <button class="btn" onclick="openDepositoModal()"
            style="background:var(--n-0); border:1px solid var(--border); color:var(--text-primary); font-size:0.85rem;">
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
                    style="padding:3px 7px; font-size:0.75rem; color:var(--accent); background:var(--n-100); border-radius:6px;"
                    onclick='editDeposito(<?= json_encode($dep, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                    <i class="fas fa-edit"></i>
                </button>
                <form method="POST" style="display:inline;" onsubmit="if(!confirm('¿Eliminar este depósito? Los insumos asociados quedarán sin depósito.')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_deposito">
                    <input type="hidden" name="dep_id" value="<?= $dep['id'] ?>">
                    <button type="submit" class="btn"
                        style="padding:3px 7px; font-size:0.75rem; color:var(--danger); background:var(--n-100); border-radius:6px;">
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
                    <div style="font-size:1.3rem; font-weight:800; color: var(--accent);"><?= $dep_resumen['items'] ?></div>
                    <div style="font-size: 0.8rem; color:var(--text-muted);">ítems</div>
                </div>
                <div>
                    <div style="font-size:1.1rem; font-weight:700; color:var(--accent);"><?= ap_plata(moneda_convertir($pdo, $usuario_id, $dep_resumen['valor_usd'], 'USD'), 0) ?></div>
                    <div style="font-size: 0.8rem; color:var(--text-muted);">valor est. <?= moneda_actual() ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Tarjeta: Sin depósito -->
        <?php if (isset($resumen_dep['sin']) && $resumen_dep['sin']['items'] > 0): ?>
        <div class="dep-card" style="background:var(--n-25); border-color:var(--border);">
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
            <?php moneda_toggle(); ?>
            <i class="fas fa-sort" style="color: var(--text-muted); font-size: 0.9rem;"></i>
            <select class="sort-select" id="sortSelect" aria-label="Ordenar el listado" onchange="setOrden(this.value)">
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
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; padding-top:12px; border-top:1px solid var(--border);">
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
        <?php $buscador_placeholder = 'Buscar insumo, tipo, precio, depósito, vto...'; include 'includes/buscador.php'; ?>
        <div style="display:flex; gap:8px;">
            <?php
            // Se arrastran los filtros de tipo y depósito que están activos en
            // pantalla: se exporta lo que se está viendo, no el inventario entero.
            $exp_params = http_build_query(array_filter([
                'tipo'     => 'insumos',
                't_insumo' => $f_tipo !== 'todos' ? $f_tipo : null,
                'dep_id'   => $f_dep === 'sin' ? -1 : ($f_dep !== 'todos' ? (int)$f_dep : null),
            ], fn($v) => $v !== null && $v !== ''));
            boton_exportar([
                ['etiqueta' => 'Excel', 'href' => 'api/reporte_excel.php?' . $exp_params,
                 'icono' => 'fa-file-excel', 'color' => '#10b981', 'detalle' => 'Planilla editable'],
                ['etiqueta' => 'PDF',   'href' => 'api/reporte_pdf.php?' . $exp_params,
                 'icono' => 'fa-file-pdf',   'color' => '#ff7b72', 'detalle' => 'Listo para imprimir',
                 'nueva_pestana' => true],
            ]);
            ?>
            <button class="btn" onclick="impAbrir()" title="Cargar insumos desde un remito, una lista de precios o una planilla"
                    style="background:var(--n-0); border:1px solid var(--border); color:var(--text-primary); font-size:0.85rem;">
                <i class="fas fa-file-import"></i> Importar
            </button>
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
                        <div style="font-size: 1.05rem; font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">
                            <?= htmlspecialchars($ins['nombre']) ?>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                            <?= tipoBadge($ins['tipo_insumo']) ?>
                            <?php if ($ins['deposito_nombre']): ?>
                                <span style="display:inline-flex; align-items:center; gap:4px; background:var(--accent-soft); color:var(--accent); border:1px solid var(--border); border-radius:6px; padding:2px 6px; font-size:0.75rem; font-weight:600;">
                                    <i class="fas fa-warehouse" style="font-size: 0.8rem;"></i>
                                    <?= htmlspecialchars($ins['deposito_nombre']) ?>
                                </span>
                            <?php else: ?>
                                <span style="display:inline-flex; align-items:center; gap:4px; background:var(--n-100); color:var(--text-muted); border:1px solid var(--border); border-radius:6px; padding:2px 6px; font-size:0.75rem;">
                                    <i class="fas fa-box" style="font-size: 0.8rem;"></i> Sin depósito
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php /* El catálogo guarda el precio en dólares, siempre: la
                             columna es precio_estimado_usd y el formulario lo pide
                             así. Mirando en pesos se convierte con la cotización de
                             referencia —un precio de catálogo no tiene fecha—, y se
                             deja a la vista el de dólares, que es el que se cargó. */ ?>
                    <td data-label="Precio Est.">
                        <span style="font-weight: 600;">
                            <?= ap_plata(moneda_convertir($pdo, $usuario_id, $ins['precio_estimado_usd'], 'USD')) ?>
                        </span>
                        <?php if (moneda_actual() !== 'USD'): ?>
                            <br><small style="color:var(--text-muted);">US$<?= number_format($ins['precio_estimado_usd'], 2, ',', '.') ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-label="Stock Actual">
                        <?php $st = (float)($ins['stock_actual'] ?? 0); ?>
                        <div class="stock-display">
                            <span class="stock-val" style="color: <?= $st <= 0 ? 'var(--danger)' : ($st < 10 ? 'var(--se-warning)' : 'var(--accent)') ?>;">
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
            <a href="?page=<?= $page-1 ?>&tipo=<?= $f_tipo ?>&deposito_id=<?= $f_dep ?>&order=<?= $f_order ?>&q=<?= urlencode($q) ?>" class="btn" style="background:var(--n-100); color:var(--text-primary); padding:8px 16px;"><i class="fas fa-chevron-left"></i> Anterior</a>
        <?php endif; ?>
        
        <span style="color:var(--text-muted); align-self:center; font-size:0.9rem;">Página <?= $page ?> de <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&tipo=<?= $f_tipo ?>&deposito_id=<?= $f_dep ?>&order=<?= $f_order ?>&q=<?= urlencode($q) ?>" class="btn" style="background:var(--n-100); color:var(--text-primary); padding:8px 16px;">Siguiente <i class="fas fa-chevron-right"></i></a>
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
                    &bull; <span style="color: var(--accent);"><i class="fas fa-warehouse" style="font-size:0.7rem;"></i> <?= htmlspecialchars($ins['deposito_nombre']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align: right; font-size: 0.82rem; white-space: nowrap;">
                <?php if($diasNum < 0): ?>
                    <span style="color: var(--danger); font-weight: 700;">Venció hace <?= abs($diasNum) ?> días</span>
                <?php elseif($diasNum === 0): ?>
                    <span style="color: var(--se-warning); font-weight: 700;">⚠ Vence hoy</span>
                <?php else: ?>
                    <span style="color: <?= $cls === 'proximo' ? 'var(--se-warning)' : 'var(--accent)' ?>; font-weight: 600;">
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
                <label for="depNombre">Nombre del Depósito <span style="color:var(--danger);">*</span></label>
                <input type="text" name="dep_nombre" id="depNombre" required
                    placeholder="Ej: Galpón Norte, Silo 1, Depósito Campo Sur"
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="depDesc">Descripción <small style="color:var(--text-muted);">(Opcional)</small></label>
                <input type="text" name="dep_descripcion" id="depDesc"
                    placeholder="Ej: Para herbicidas, semillas de soja..."
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="depUbic">Ubicación <small style="color:var(--text-muted);">(Opcional)</small></label>
                <input type="text" name="dep_ubicacion" id="depUbic"
                    placeholder="Ej: Ruta 9 Km 45, Campo El Ombú"
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:4px;">
                <button type="button" class="btn" onclick="closeDepModal()" style="background:rgba(255,255,255,0.1);color:var(--text-primary);">Cancelar</button>
                <button type="submit" class="btn" style="background:var(--n-0); border:1px solid var(--border); color:var(--text-primary);">
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
                <label for="nombreInput">Nombre del Insumo</label>
                <input type="text" name="nombre" id="nombreInput" required
                    placeholder="Ej: Glifosato 64%, Urea, Soja Don Mario"
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);">
            </div>

            <!-- Tipo + Unidad + Depósito -->
            <div class="form-grid-2">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label for="tipoInput">Tipo de Insumo</label>
                    <select name="tipo_insumo" id="tipoInput" required
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-color); color:var(--text-primary);">
                        <option value="semilla">🌱 Semilla</option>
                        <option value="fertilizante">💧 Fertilizante</option>
                        <option value="agroquimico">🧪 Agroquímico</option>
                        <option value="inoculante">🔬 Inoculante</option>
                        <option value="otro">📦 Otro</option>
                    </select>
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label for="unidadInput">Unidad de Medida</label>
                    <select name="unidad_medida" id="unidadInput" required
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-color); color:var(--text-primary);">
                        <option value="kg">Kilogramos (kg)</option>
                        <option value="lt">Litros (lt)</option>
                        <option value="dosis">Dosis</option>
                        <option value="bolsa">Bolsa</option>
                    </select>
                </div>
            </div>

            <!-- Depósito -->
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="depositoInput">
                    <i class="fas fa-warehouse" style="color: var(--accent); margin-right:5px;"></i>
                    Depósito / Almacén
                    <small style="color:var(--text-muted);">(Opcional)</small>
                </label>
                <select name="deposito_id" id="depositoInput"
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-color); color:var(--text-primary);">
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
                <label for="stockMinInput">Stock Mínimo (Punto de Reorden) <small style="color:var(--text-muted);">(Opcional)</small></label>
                <input type="number" step="0.01" name="stock_minimo" id="stockMinInput"
                    placeholder="Ej: 50 — se alertará cuando llegue a este valor"
                    style="padding:10px; border-radius:6px; border:1px solid oklch(0.470 0.120 70 / 0.10); background:oklch(0.470 0.120 70 / 0.10); color:var(--text-primary);">
            </div>

            <!-- Precio + Stock -->
            <div class="form-grid-2">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label for="precioInput">Precio Est. (USD / Unidad)</label>
                    <input type="number" step="0.0001" name="precio_estimado_usd" id="precioInput" required
                        placeholder="Ej: 6.50"
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);">
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label for="stockInput">Stock Actual</label>
                    <input type="number" step="0.01" name="stock_actual" id="stockInput"
                        placeholder="Ej: 200"
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);">
                </div>
            </div>

            <!-- Unidad Stock + Vencimiento -->
            <div class="form-grid-2">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label for="unidadStockInput">Unidad de Stock <small style="color:var(--text-muted);">(Opcional)</small></label>
                    <input type="text" name="unidad_stock" id="unidadStockInput"
                        placeholder="Ej: litros, bolsas, kg"
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);">
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label for="vencInput">Fecha Vencimiento <small style="color:var(--text-muted);">(Opcional)</small></label>
                    <input type="date" name="fecha_vencimiento" id="vencInput"
                        style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary);">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:6px;">
                <button type="button" class="btn" onclick="closeModal()" style="background: rgba(255,255,255,0.1); color: var(--text-primary);">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     IMPORTACIÓN ASISTIDA
     Subir un remito / planilla → revisar lo detectado → confirmar la carga.
     El archivo se lee en el servidor y no sale a ningún servicio externo.
     ═══════════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Zona de subida ── */
.imp-sub { color: var(--text-muted); font-size: 0.88rem; line-height: 1.5; margin-bottom: 18px; }
.imp-zona {
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    padding: 32px 20px; border-radius: 12px; cursor: pointer; text-align: center;
    border: 2px dashed var(--rule-strong); background: var(--n-50);
    transition: background 0.2s, border-color 0.2s;
}
.imp-zona:hover, .imp-zona:focus-visible, .imp-zona.encima {
    background: var(--n-50); border-color: var(--border); outline: none;
}
.imp-zona i { font-size: 1.9rem; color: var(--accent); }
.imp-zona strong { font-size: 0.95rem; color: var(--text-primary); }
.imp-zona span { font-size: 0.78rem; color: var(--text-muted); }

.imp-pegar { margin-top: 16px; }
.imp-pegar summary { cursor: pointer; font-size: 0.87rem; color: var(--accent); padding: 6px 0; }
.imp-pegar p { font-size: 0.8rem; color: var(--text-muted); margin: 8px 0; }
.imp-pegar textarea {
    width: 100%; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.82rem;
    padding: 10px; border-radius: 8px; border: 1px solid var(--border);
    background: var(--n-100); color: var(--text-primary); resize: vertical;
}

.imp-nota {
    margin-top: 16px; padding: 11px 14px; border-radius: 9px; font-size: 0.8rem; line-height: 1.5;
    background: oklch(0.470 0.120 70 / 0.10); border: 1px solid oklch(0.470 0.120 70 / 0.10); color: var(--text-muted);
}
.imp-nota strong { color: var(--se-warning); }

.imp-error {
    margin-top: 16px; padding: 12px 14px; border-radius: 9px; font-size: 0.85rem; line-height: 1.5;
    background: oklch(0.450 0.160 28 / 0.10); border: 1px solid oklch(0.450 0.160 28 / 0.10); color: #ff9d96;
}
.imp-acciones { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 20px; flex-wrap: wrap; }

/* ── Animación de análisis ──
   Un haz que barre una hoja de papel. Es deliberadamente pausada: leer un
   comprobante lleva un momento y el usuario tiene que entender que algo pasó
   antes de que le aparezca una lista para revisar. */
.imp-analisis { padding: 12px 0 4px; text-align: center; }
.imp-escaner {
    position: relative; width: 92px; height: 116px; margin: 0 auto 26px;
    border-radius: 8px; background: var(--n-100);
    border: 1px solid var(--border); overflow: hidden;
}
.imp-escaner b {
    display: block; height: 7px; margin: 11px 14px 0; border-radius: 3px;
    background: rgba(165,180,252,0.35);
}
.imp-escaner b:nth-child(2) { width: 60%; }
.imp-escaner b:nth-child(4) { width: 72%; }
.imp-escaner b:nth-child(5) { width: 45%; }
.imp-haz {
    position: absolute; left: 0; right: 0; height: 34px; top: -34px;
    background: linear-gradient(180deg, transparent, oklch(0.480 0.100 240 / 0.10), transparent);
    animation: impBarrer 1.6s ease-in-out infinite;
}
@keyframes impBarrer {
    0%   { top: -34px; }
    100% { top: 116px; }
}

.imp-pasos { list-style: none; padding: 0; margin: 0; display: inline-flex; flex-direction: column; gap: 11px; text-align: left; }
.imp-pasos li {
    display: flex; align-items: center; gap: 10px; font-size: 0.87rem;
    color: var(--text-muted); opacity: 0.45; transition: opacity 0.3s, color 0.3s;
}
.imp-pasos li::before {
    content: ''; width: 15px; height: 15px; border-radius: 50%; flex: none;
    border: 2px solid currentColor; opacity: 0.5;
}
.imp-pasos li.activo { opacity: 1; color: var(--accent); }
.imp-pasos li.activo::before {
    border-color: var(--accent); border-top-color: transparent; opacity: 1;
    animation: impGirar 0.7s linear infinite;
}
.imp-pasos li.listo { opacity: 1; color: var(--accent); }
.imp-pasos li.listo::before {
    content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
    border: none; font-size: 0.78rem; opacity: 1; display: flex; align-items: center; justify-content: center;
}
@keyframes impGirar { to { transform: rotate(360deg); } }

/* Sin animación: los pasos siguen avanzando, pero nada gira ni barre. */
@media (prefers-reduced-motion: reduce) {
    .imp-haz { animation: none; top: 0; height: 116px; opacity: 0.25; }
    .imp-pasos li.activo::before { animation: none; border-top-color: currentColor; }
}

/* ── Ventana de confirmación ── */
.imp-ancho { max-width: 1080px; max-height: calc(100vh - 70px); overflow-y: auto; }
@media (max-width: 768px) { .imp-ancho { max-width: 100%; } }

.imp-aviso {
    padding: 11px 14px; border-radius: 9px; font-size: 0.83rem; line-height: 1.5; margin-bottom: 14px;
    background: oklch(0.470 0.120 70 / 0.10); border: 1px solid oklch(0.470 0.120 70 / 0.10); color: var(--text-muted);
}
.imp-barra {
    display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;
    padding-bottom: 14px; margin-bottom: 6px; border-bottom: 1px solid var(--border);
}
.imp-campo { display: flex; flex-direction: column; gap: 4px; }
.imp-campo span { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
.imp-campo select { padding: 7px 10px !important; font-size: 0.82rem !important; width: auto !important; min-width: 130px; }

.imp-tabla-wrap { overflow-x: auto; margin: 4px 0 8px; }
.imp-tabla { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.imp-tabla th {
    text-align: left; padding: 9px 8px; font-size: 0.8rem; text-transform: uppercase;
    letter-spacing: 0.4px; color: var(--text-muted); border-bottom: 1px solid var(--border); white-space: nowrap;
}
.imp-tabla td { padding: 6px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.imp-tabla tr.excluida { opacity: 0.35; }

/* El cuadre contra el total del comprobante. Verde no quiere decir "está bien
   cargado": quiere decir "los números cierran entre sí", que es lo único que una
   máquina puede afirmar. */
.imp-cuadre {
    display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
    padding: 11px 14px; border-radius: 10px; margin: 4px 0 12px;
    font-size: 0.88rem; border: 1px solid;
}
.imp-cuadre b { font-weight: 700; }
.imp-cuadre small { color: var(--text-muted); font-size: 0.82rem; }
.imp-cuadre.cierra    { background: var(--accent-soft);  border-color: var(--accent);  color: var(--accent); }
.imp-cuadre.no-cierra { background: var(--warning-soft); border-color: var(--warning); color: var(--warning); }
.imp-cuadre.sin-total { background: var(--surface-sunk); border-color: var(--border); color: var(--text-muted); }

/* Un precio que se despegó del que ya le conocíamos al insumo. No se bloquea
   nada: los precios suben, y el que sabe si es real es el productor. */
.imp-tabla input.sospechoso {
    border-color: var(--warning);
    background: var(--warning-soft);
}
.imp-alerta-precio {
    display: block; font-size: 0.74rem; color: var(--warning);
    margin-top: 3px; line-height: 1.35;
}
/* La columna del precio se ensancha cuando hay un aviso: apretada, el texto
   caía en cinco renglones de dos palabras y se leía peor que el número. */
.imp-tabla td:has(.imp-alerta-precio:not(:empty)) { min-width: 190px; }
.imp-tabla input[type=text], .imp-tabla input[type=number], .imp-tabla input[type=date], .imp-tabla select {
    padding: 6px 8px !important; font-size: 0.82rem !important; border-radius: 6px !important; width: 100%;
}
.imp-tabla input[type=checkbox] { width: 17px; height: 17px; accent-color: var(--accent); cursor: pointer; }
.imp-col-nombre { min-width: 210px; }
.imp-col-accion { min-width: 175px; }
.imp-col-num    { width: 96px; }
.imp-col-num input { text-align: right; font-variant-numeric: tabular-nums; }
.imp-col-fecha  { width: 145px; }
.imp-ref { display: block; font-size: 0.8rem; color: var(--text-muted); margin-top: 3px; }
.imp-contador { margin-right: auto; font-size: 0.85rem; color: var(--text-muted); }

/* ── Salir ─────────────────────────────────────────────────────────────────
   Arriba a la derecha y de 44px, que es el mínimo para tocar con el dedo sin
   errarle. El panel pasa a position:relative para que la cruz quede anclada a
   él y no a la pantalla. */
#importModal .modal-panel, #importConfirmModal .modal-panel { position: relative; }
.imp-cerrar {
    position: absolute; top: 10px; right: 10px;
    width: 44px; height: 44px; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; font-family: inherit;
    background: none; border: 0; border-radius: 10px;
    color: var(--text-muted); cursor: pointer; z-index: 2;
}
.imp-cerrar:hover { background: var(--surface-hover); color: var(--text-primary); }
.imp-cerrar:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 1px; }
/* Que el título no se meta abajo de la cruz. */
#importModal .modal-panel h2, #importConfirmModal .modal-panel > h2 { padding-right: 46px; }

/* ── La foto ───────────────────────────────────────────────────────────────
   La imagen todavía no se lee sola, pero tenerla en pantalla mientras se
   escribe convierte "acordarse del remito" en "copiar lo que se está viendo",
   que es otra cosa. */
.imp-foto-caja { display: grid; grid-template-columns: 1fr; gap: 16px; }
@media (min-width: 900px) { .imp-foto-caja { grid-template-columns: 1.1fr 1fr; } }

/* En el paso de la foto el panel se ensancha. En el ancho normal la imagen
   quedaba del tamaño de una estampilla, y toda la idea es poder LEER el remito
   mientras se escribe al lado. */
#importModal .modal-panel:has(#impPasoFoto:not([hidden])) {
    max-width: 1080px; max-height: calc(100vh - 70px); overflow-y: auto;
}
@media (max-width: 768px) {
    #importModal .modal-panel:has(#impPasoFoto:not([hidden])) { max-width: 100%; }
}

.imp-foto-marco {
    position: relative; background: var(--surface-sunk);
    border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
    max-height: 460px; display: flex; align-items: center; justify-content: center;
}
.imp-foto-marco img {
    max-width: 100%; max-height: 460px; display: block;
    cursor: zoom-in; transition: transform 0.15s ease;
}
.imp-foto-marco img.ampliada { cursor: zoom-out; transform: scale(2); }

/* El veredicto de la medición. Verde no dice "va a leerse perfecto": dice
   "por nitidez, luz y tamaño, esta foto no tiene nada que la descalifique". */
/* Una línea. Antes era un párrafo con viñetas y el consejo completo de cada
   problema: con dos problemas ocupaba media pantalla del teléfono, arriba de la
   foto que se quiere mirar. */
.imp-veredicto {
    padding: 8px 12px; border-radius: 8px; margin-bottom: 10px;
    font-size: 0.86rem; border: 1px solid; line-height: 1.4;
}
.imp-veredicto b { font-weight: 700; }
.imp-veredicto.sirve   { background: var(--accent-soft);  border-color: var(--accent);  color: var(--accent); }
.imp-veredicto.no-sirve{ background: var(--warning-soft); border-color: var(--warning); color: var(--warning); }

.imp-medidas {
    display: flex; flex-wrap: wrap; gap: 14px; margin-top: 8px;
    font-size: 0.76rem; color: var(--text-muted); font-variant-numeric: tabular-nums;
}
.imp-medidas span b { font-weight: 700; color: var(--text-primary); }
/* El cuadro crece con lo que tiene adentro.
   Eran 210 píxeles fijos: con un renglón leído quedaban dos tercios de caja
   vacía empujando los botones fuera de la pantalla del teléfono, y con un remito
   de veinte renglones había que hacer scroll adentro del cuadro teniendo media
   pantalla libre al lado.
   El alto lo calcula impAjustarAlto(); el piso y el techo los pone esta regla,
   así crecer nunca se come la pantalla y el máximo deja su propio scroll. */
/* Sin min-height: el piso lo pone rows, que es lo que mueve impAjustarAlto().
   Queda el techo, para que un remito largo no empuje los botones fuera de la
   pantalla, con su propia barra. */
.imp-foto-lado textarea, .imp-pegar textarea {
    max-height: 42vh; overflow-y: auto;
}
.imp-foto-lado textarea {
    width: 100%; font-family: ui-monospace, monospace; font-size: 0.84rem;
    padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color);
    background: var(--input-bg); color: var(--text-primary); resize: vertical;
}
.imp-foto-lado p { font-size: 0.83rem; color: var(--text-muted); margin: 0 0 8px; }

/* El lector */
.imp-leer { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
.imp-leer-nota { font-size: 0.76rem; color: var(--text-muted); flex: 1 1 160px; line-height: 1.35; }
.imp-leer-estado {
    padding: 10px 13px; border-radius: 10px; margin-bottom: 10px;
    font-size: 0.85rem; border: 1px solid; line-height: 1.45;
}
.imp-leer-estado b { display: block; margin-bottom: 3px; }
.imp-leer-estado.trabajando { background: var(--surface-sunk); border-color: var(--border); color: var(--text-muted); }
.imp-leer-estado.bien       { background: var(--accent-soft);  border-color: var(--accent);  color: var(--accent); }
.imp-leer-estado.dudoso     { background: var(--warning-soft); border-color: var(--warning); color: var(--warning); }
.imp-leer-estado.falla      { background: var(--danger-soft);  border-color: var(--danger);  color: var(--danger); }

.imp-barra-progreso {
    height: 5px; border-radius: 3px; background: var(--border);
    overflow: hidden; margin-top: 7px;
}
.imp-barra-progreso i {
    display: block; height: 100%; width: 0; background: var(--accent);
    transition: width 0.25s ease;
}

/* Dónde dudó el lector. Es lo que dirige la mirada al revisar: sin esto, la
   revisión es "leer todo de nuevo", que no la hace nadie. */
.imp-dudas {
    font-size: 0.8rem; color: var(--text-muted); margin-bottom: 10px;
    line-height: 1.5;
}
.imp-crudo { margin-top: 8px; font-size: 0.78rem; }
.imp-crudo summary { cursor: pointer; color: var(--text-muted); padding: 4px 0; }
.imp-crudo pre {
    max-height: 190px; overflow: auto; margin: 6px 0 0;
    background: var(--surface-sunk); border: 1px solid var(--border);
    border-radius: 8px; padding: 10px; white-space: pre-wrap; word-break: break-word;
    color: var(--text-muted); font-size: 0.74rem; line-height: 1.4;
}

.imp-dudas b { color: var(--warning); }
.imp-dudas mark {
    background: var(--warning-soft); color: var(--warning);
    padding: 1px 5px; border-radius: 4px; font-weight: 600;
    font-variant-numeric: tabular-nums;
}

/* ── En el teléfono ────────────────────────────────────────────────────────
   La tabla de revisión tiene ocho columnas. A 390 píxeles de ancho eso es una
   tira con scroll lateral donde se ve el nombre y medio "qué hago": para tocar
   la cantidad de la tercera fila hay que arrastrar de costado y perder de vista
   de qué fila era. Medido en un teléfono: la tabla desbordaba.

   Acá cada fila pasa a ser una carta con los campos uno abajo del otro, y cada
   uno dice qué es —la etiqueta sale del data-label que pone impFila()—. Es la
   misma información, en una forma que se puede tocar. */
@media (max-width: 760px) {
    .modal-wrapper { padding: 12px; }
    #importModal .modal-panel, #importConfirmModal .modal-panel { padding: 18px 14px; }

    .imp-tabla-wrap { overflow-x: visible; }
    .imp-tabla, .imp-tabla tbody, .imp-tabla tr, .imp-tabla td { display: block; width: 100%; }
    .imp-tabla thead { display: none; }
    .imp-tabla tr {
        border: 1px solid var(--border); border-radius: 12px;
        padding: 10px 12px; margin-bottom: 12px; background: var(--bg-card);
    }
    .imp-tabla td {
        border: 0; padding: 5px 0;
        display: grid; grid-template-columns: 84px 1fr; align-items: center; gap: 10px;
    }
    .imp-tabla td::before {
        content: attr(data-label);
        font-size: 0.72rem; font-weight: 700; letter-spacing: 0.02em;
        color: var(--text-muted); text-transform: uppercase;
    }
    /* Los anchos fijos de columna no significan nada apilados. */
    .imp-col-num, .imp-col-fecha, .imp-col-nombre, .imp-col-accion { width: auto; min-width: 0; }
    .imp-col-num input { text-align: left; }
    .imp-tabla td:has(.imp-alerta-precio:not(:empty)) { min-width: 0; }
    .imp-alerta-precio { grid-column: 2; }
    .imp-ref { grid-column: 2; }

    /* Los botones, cómodos y uno por renglón. */
    .imp-acciones { flex-direction: column; align-items: stretch; }
    .imp-acciones .btn { width: 100%; justify-content: center; min-height: 46px; }
    .imp-contador { margin: 0 0 4px; text-align: center; }

    .imp-barra { flex-direction: column; align-items: stretch; }
    .imp-campo { width: 100%; }
    .imp-leer { flex-direction: column; align-items: stretch; }
    .imp-leer .btn { width: 100%; justify-content: center; min-height: 46px; }
    /* El flex-basis de 160px estaba pensado para la fila: al apilar en columna
       pasa a ser ALTO, y la nota dejaba un hueco de 160 píxeles en blanco entre
       el botón y el cuadro de texto. */
    .imp-leer-nota { flex: 0 0 auto; text-align: center; }
}

</style>

<!-- ===== MODAL: Importar (subida + análisis) ===== -->
<div id="importModal" class="modal-wrapper">
    <div class="glass-panel modal-panel">

        <?php /* Salir. En el teléfono no hay tecla Escape ni lugar para hacer clic
                 afuera —el modal ocupa la pantalla entera—, así que sin esto la
                 única salida era el botón Cancelar del primer paso: quien llegaba
                 a la foto quedaba encerrado. */ ?>
        <button type="button" class="imp-cerrar" onclick="impCerrar()" aria-label="Cerrar">&times;</button>

        <!-- Paso 1: elegir el origen -->
        <div id="impPasoOrigen">
            <h2 style="margin-bottom:6px;">📥 Importar insumos</h2>
            <p class="imp-sub">
                Se lee el archivo en el servidor, sin mandarlo a ningún servicio externo.
                Después revisás la lista y nada se guarda hasta que lo confirmes.
            </p>

            <div id="impZona" class="imp-zona" tabindex="0" role="button" aria-label="Elegir archivo para importar">
                <i class="fas fa-cloud-arrow-up" aria-hidden="true"></i>
                <strong>Arrastrá el archivo o hacé clic para elegirlo</strong>
                <span>Excel (.xlsx) · CSV · PDF digital · foto del remito — hasta 8 MB</span>
            </div>
            <?php /* SIN capture. Estaba puesto como comodidad —abre la cámara de
                     atrás directamente— pero el efecto real es el contrario: con
                     ese atributo el teléfono va DERECHO a la cámara y no ofrece la
                     galería, así que no se puede elegir una foto ya sacada. Sin él,
                     el teléfono muestra el menú de siempre y ahí están las dos. */ ?>
            <input type="file" id="impInput" aria-label="Elegir el archivo a importar"
                   accept=".xlsx,.xlsm,.csv,.txt,.tsv,.pdf,image/*" hidden>

            <details class="imp-pegar">
                <summary><i class="fas fa-paste"></i> …o pegar la tabla a mano</summary>
                <p>Copiá los renglones de un mail o de un Excel abierto, o escribilos: <strong>cantidad, descripción y precio</strong>.</p>
                <textarea id="impTexto" rows="5" aria-label="Pegar acá el listado de insumos" placeholder="2 bolsas  Urea granulada  0,62&#10;10 lt  Glifosato 64%  5,80"></textarea>
                <div class="imp-acciones">
                    <button type="button" class="btn btn-primary" onclick="impAnalizarTexto()">
                        <i class="fas fa-wand-magic-sparkles"></i> Analizar el texto
                    </button>
                </div>
            </details>

            <div id="impError" class="imp-error" hidden></div>

            <div class="imp-acciones">
                <button type="button" class="btn" onclick="impCerrar()" style="background:rgba(255,255,255,0.1); color:var(--text-primary);">Cancelar</button>
            </div>
        </div>

        <!-- Paso foto: la medición y la carga mirándola -->
        <div id="impPasoFoto" hidden>
            <h2 style="margin-bottom:6px;">La foto del remito</h2>
            <p class="imp-sub" id="impFotoSub"></p>

            <div id="impVeredicto" class="imp-veredicto"></div>

            <div class="imp-foto-caja">
                <div>
                    <div class="imp-foto-marco">
                        <img id="impFotoImg" alt="Foto del remito. Tocala para ampliar.">
                    </div>
                    <div class="imp-medidas" id="impMedidas"></div>
                </div>
                <div class="imp-foto-lado">
                    <div class="imp-leer">
                        <button type="button" class="btn btn-primary" id="impLeerBtn" onclick="impLeerFoto()">
                            <i class="fas fa-eye"></i> Intentar leerla
                        </button>
                        <span class="imp-leer-nota">Se lee acá, en tu teléfono.</span>
                    </div>
                    <div id="impLeerEstado" class="imp-leer-estado" hidden></div>
                    <div id="impDudas" class="imp-dudas" hidden></div>

                    <p><strong>Cantidad, descripción y precio</strong> por renglón.
                       Si hay total, ponelo al final: con eso verifico la suma.</p>
                    <?php /* rows chico: es el alto de arranque y el que vale si
                             el javascript no corriera. El alto de verdad lo pone
                             impAjustarAlto() según lo que haya adentro. */ ?>
                    <textarea id="impTextoFoto" rows="3"
                              aria-label="Escribí acá los renglones del remito"
                              placeholder="2.500 kg  Urea granulada     1.200,00&#10;800 lt    Glifosato 62%      5.000,00&#10;TOTAL                        7.000.000,00"></textarea>
                    <?php /* Lo que el filtro descartó, por si se pasó de listo y
                             tiró un renglón que servía. Plegado, para que no
                             estorbe: el 99% de las veces es el membrete y el
                             fondo de la foto. */ ?>
                    <details id="impCrudo" class="imp-crudo" hidden></details>

                    <div class="imp-acciones">
                        <button type="button" class="btn" onclick="impPaso('origen')"
                                style="background:rgba(255,255,255,0.1); color:var(--text-primary);">Otra foto</button>
                        <button type="button" class="btn btn-primary" onclick="impAnalizarTexto('impTextoFoto')">
                            <i class="fas fa-wand-magic-sparkles"></i> Analizar lo escrito
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Paso 2: análisis en curso -->
        <div id="impPasoAnalisis" class="imp-analisis" hidden>
            <h2 style="margin-bottom:22px;">Leyendo el comprobante…</h2>
            <div class="imp-escaner" aria-hidden="true">
                <b></b><b></b><b></b><b></b><b></b>
                <div class="imp-haz"></div>
            </div>
            <ol class="imp-pasos" id="impPasos" aria-live="polite">
                <li>Leyendo el archivo</li>
                <li>Buscando las columnas</li>
                <li>Reconociendo los insumos</li>
                <li>Comparando con tu inventario</li>
            </ol>
        </div>
    </div>
</div>

<!-- ===== MODAL: Confirmar lo detectado ===== -->
<div id="importConfirmModal" class="modal-wrapper">
    <div class="glass-panel modal-panel imp-ancho">
        <button type="button" class="imp-cerrar" onclick="impCerrar()" aria-label="Cerrar">&times;</button>
        <h2 id="impConfTitulo" style="margin-bottom:6px;">Revisá antes de cargar</h2>
        <p class="imp-sub" id="impConfSub"></p>

        <div id="impConfAviso" class="imp-aviso" hidden></div>

        <div class="imp-barra">
            <div id="impRemap" style="display:flex; flex-wrap:wrap; gap:12px;"></div>
            <?php /* En qué moneda están los precios del PAPEL. El catálogo guarda
                     siempre en dólares, así que si el comprobante viene en pesos hay
                     que convertirlo al guardar. Antes no se preguntaba y se guardaba
                     el peso como si fuera dólar: un error de mil veces sobre cada
                     precio y sobre la valuación del depósito. */ ?>
            <label for="impMoneda" class="imp-campo">
                <span>Los precios del comprobante están en</span>
                <select id="impMoneda" onchange="impCambiarMoneda(this.value)">
                    <option value="ARS">$ pesos</option>
                    <option value="USD">US$ dólares</option>
                </select>
            </label>
            <label for="impDepGlobal" class="imp-campo">
                <span>Depósito para todos</span>
                <select id="impDepGlobal" onchange="impAplicarDeposito(this.value)">
                    <option value="">— Sin depósito —</option>
                    <?php foreach ($depositos as $dep): ?>
                    <option value="<?= (int)$dep['id'] ?>"><?= htmlspecialchars($dep['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <?php /* La comprobación que no depende de que nadie mire: la suma de los
                 renglones contra el total impreso en el comprobante. Es aritmética,
                 así que un dígito mal tipeado —o mal leído— salta solo. */ ?>
        <div id="impCuadre" class="imp-cuadre" hidden></div>

        <div class="imp-tabla-wrap">
            <table class="imp-tabla">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="impTodos" checked onchange="impMarcarTodos(this.checked)" aria-label="Seleccionar todo"></th>
                        <th class="imp-col-nombre">Insumo</th>
                        <th class="imp-col-accion">Qué hago con esta fila</th>
                        <th>Tipo</th>
                        <th class="imp-col-num">Cantidad</th>
                        <th>Unidad</th>
                        <th class="imp-col-num" id="impThPrecio">Precio</th>
                        <th class="imp-col-fecha">Vence</th>
                    </tr>
                </thead>
                <tbody id="impTbody"></tbody>
            </table>
        </div>

        <div class="imp-acciones">
            <span class="imp-contador" id="impContador"></span>
            <button type="button" class="btn" onclick="impVolver()" style="background:rgba(255,255,255,0.1); color:var(--text-primary);">Volver</button>
            <button type="button" class="btn btn-primary" onclick="impConfirmar()">
                <i class="fas fa-check"></i> Cargar seleccionados
            </button>
        </div>

        <form id="impForm" method="POST" style="display:none;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="import_guardar">
            <input type="hidden" name="items" id="impItemsJson">
            <?php /* La moneda del comprobante. Sin esto el servidor no sabe si el
                     precio que llega son pesos o dólares, y el catálogo guarda
                     siempre dólares. */ ?>
            <input type="hidden" name="moneda" id="impMonedaJson" value="ARS">
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

// ─── IMPORTACIÓN ASISTIDA ───────────────────────────────────────────────────
//
// Todo lo que se muestra en la tabla de confirmación viene de un archivo que
// subió el usuario, así que se construye con createElement/textContent y nunca
// con innerHTML: una celda de un CSV puede contener HTML y no tiene por qué
// terminar interpretándose.

const IMP = {
    csrf: <?= json_encode(get_csrf_token()) ?>,
    inventario: <?= json_encode(array_map(fn($i) => ['id' => (int)$i['id'], 'nombre' => $i['nombre']], $insumos_full), JSON_UNESCAPED_UNICODE) ?>,
    datos: null,
    items: [],
    animacion: null,
    moneda: 'ARS',      // en qué moneda están los precios del comprobante
    cotizacion: 0,      // para poder mostrarlos en la otra
    paso: 'origen',     // dónde estaba, para que "Volver" no borre lo escrito
    fotoUrl: null,      // la imagen en memoria, hasta que se la suelte
    fotoImg: null,      // la imagen tal cual, para que el lector la amplíe él
    medidas: null,      // lo que dio el medidor; de ahí sale cuánto ampliar
};

/* Cuánto se puede apartar el precio del comprobante del que ya le conocíamos al
   insumo antes de marcarlo. No es un límite de inflación: es un detector de
   dígitos. Un 1 leído o tipeado como 7 multiplica por 7; un dígito de más, por
   10. Una suba real, aunque sea fuerte, rara vez llega al triple entre dos
   compras. Por eso el umbral va en 3: separa el error del aumento. */
const IMP_SALTO_PRECIO = 3;

const IMP_TIPOS = [
    ['semilla', '🌱 Semilla'], ['fertilizante', '💧 Fertilizante'], ['agroquimico', '🧪 Agroquímico'],
    ['inoculante', '🔬 Inoculante'], ['otro', '📦 Otro'],
];
const IMP_UNIDADES = [['kg', 'kg'], ['lt', 'lt'], ['dosis', 'dosis'], ['bolsa', 'bolsa']];
const IMP_CAMPOS = [
    ['nombre', 'Nombre'], ['cantidad', 'Cantidad'], ['unidad', 'Unidad'],
    ['precio', 'Precio'], ['vencimiento', 'Vence'],
];

// ── Apertura y cierre ────────────────────────────────────────────────────────
function impAbrir() {
    impSoltarFoto();
    impPaso('origen');
    impMostrarError('');
    document.getElementById('impInput').value = '';
    document.getElementById('impTexto').value = '';
    document.getElementById('impTextoFoto').value = '';
    document.getElementById('importModal').style.display = 'block';
    document.body.classList.add('modal-open');
    /* Los cuadros vuelven al mínimo. Va DESPUÉS de mostrar el modal: mientras
       está oculto no tiene medidas, scrollHeight da cero y el alto quedaría mal
       calculado hasta que alguien escriba algo. */
    impAjustarAlto(document.getElementById('impTexto'));
    impAjustarAlto(document.getElementById('impTextoFoto'));
}

/** La imagen ocupa memoria hasta que se la suelta explícitamente. */
function impSoltarFoto() {
    IMP.fotoImg = null;
    IMP.medidas = null;
    const caja = document.getElementById('impLeerEstado');
    if (caja) caja.hidden = true;
    const dudas = document.getElementById('impDudas');
    if (dudas) dudas.hidden = true;
    if (!IMP.fotoUrl) return;
    URL.revokeObjectURL(IMP.fotoUrl);
    IMP.fotoUrl = null;
    document.getElementById('impFotoImg').removeAttribute('src');
    document.getElementById('impFotoImg').classList.remove('ampliada');
}

function impCerrar() {
    if (IMP.animacion) IMP.animacion.cancelar();
    impSoltarFoto();
    document.getElementById('importModal').style.display = 'none';
    document.getElementById('importConfirmModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

/**
 * Volver desde la revisión al paso del que se vino, SIN borrar nada.
 *
 * Antes esto llamaba a impAbrir(), que reinicia: quien había escrito diez
 * renglones mirando la foto y volvía para corregir uno, se encontraba con la
 * pantalla en blanco y la foto descartada. Se vuelve a donde estaba.
 */
function impVolver() {
    document.getElementById('importConfirmModal').style.display = 'none';
    impPaso(IMP.paso || 'origen');
    document.getElementById('importModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function impPaso(cual) {
    document.getElementById('impPasoOrigen').hidden   = (cual !== 'origen');
    document.getElementById('impPasoFoto').hidden     = (cual !== 'foto');
    document.getElementById('impPasoAnalisis').hidden = (cual !== 'analisis');
    // 'analisis' es de paso: volver ahí no tendría sentido.
    if (cual !== 'analisis') IMP.paso = cual;

    /* Recién ahora el cuadro tiene medidas. Antes de mostrarse no se lo puede
       medir, así que el alto se acomoda acá, cuando el paso aparece. */
    if (cual === 'foto') impAjustarAlto(document.getElementById('impTextoFoto'));
}

/**
 * El cuadro de texto, del alto de lo que tiene adentro.
 *
 * Se pone en 'auto' antes de medir a propósito: scrollHeight devuelve el alto
 * del contenido O el alto fijado, lo que sea mayor, así que sin ese paso el
 * cuadro puede crecer pero nunca achicarse.
 *
 * El piso y el techo los pone la CSS (min-height y max-height): acá sólo se
 * calcula, y el navegador recorta. Así el techo puede ir en unidades de
 * pantalla y sigue valiendo cuando se gira el teléfono.
 */
function impAjustarAlto(caja) {
    if (!caja) return;
    /* Se cuentan renglones y se mueve ROWS, que es el mecanismo propio del
       textarea. No se toca height.
     *
     * La versión por píxeles —poner height en cero, leer scrollHeight y escribir
     * el resultado— no funcionaba acá, y costó averiguar por qué: si se le fija
     * un alto mientras el elemento todavía no está en pantalla (al abrir el
     * modal el paso de la foto está oculto), el navegador después ignora
     * cualquier alto nuevo. Se le pidieron 120, 202, 300 y 500 píxeles y siguió
     * mostrando 76; sacado del documento y vuelto a poner en el MISMO lugar,
     * obedecía. Un estado interno, no una regla de CSS.
     *
     * Con rows nada de eso pasa: es un atributo, no un estilo calculado, y vale
     * igual esté el elemento visible o no. El techo lo sigue poniendo el
     * max-height de la CSS, que le deja su propia barra si el remito es largo. */
    const lineas = (caja.value || '').split('\n').length;
    caja.rows = Math.max(3, Math.min(lineas + 1, 16));
}

function impMostrarError(msg) {
    const caja = document.getElementById('impError');
    caja.textContent = msg || '';
    caja.hidden = !msg;
}

// ── Elegir el archivo ────────────────────────────────────────────────────────
(function () {
    const zona  = document.getElementById('impZona');
    const input = document.getElementById('impInput');
    if (!zona || !input) return;

    zona.addEventListener('click', () => input.click());
    zona.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', () => { if (input.files[0]) impAnalizarArchivo(input.files[0]); });

    ['dragenter', 'dragover'].forEach(ev => zona.addEventListener(ev, e => {
        e.preventDefault(); zona.classList.add('encima');
    }));
    ['dragleave', 'drop'].forEach(ev => zona.addEventListener(ev, e => {
        e.preventDefault(); zona.classList.remove('encima');
    }));
    zona.addEventListener('drop', e => {
        const f = e.dataTransfer && e.dataTransfer.files[0];
        if (f) impAnalizarArchivo(f);
    });

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (document.getElementById('importModal').style.display === 'block') impCerrar();
    });

    /* Ampliar la foto tocándola. Un remito térmico entra entero en la pantalla y
       después no se lee ni un renglón: sin poder acercar, la foto al lado no
       sirve para copiar de ahí. */
    const foto = document.getElementById('impFotoImg');
    if (foto) {
        foto.addEventListener('click', () => foto.classList.toggle('ampliada'));
    }

    // Los dos cuadros de texto se acomodan solos a medida que se escribe.
    ['impTextoFoto', 'impTexto'].forEach(id => {
        const caja = document.getElementById(id);
        if (!caja) return;
        caja.addEventListener('input', () => impAjustarAlto(caja));
    });

    /* El de pegar vive dentro de un desplegable: mientras está cerrado no tiene
       medidas, así que se acomoda al abrirlo. */
    const pegar = document.querySelector('.imp-pegar');
    if (pegar) {
        pegar.addEventListener('toggle', () => {
            if (pegar.open) impAjustarAlto(document.getElementById('impTexto'));
        });
    }
})();

// ── Envío y animación ────────────────────────────────────────────────────────
function impAnalizarArchivo(archivo) {
    // Una foto no viaja al servidor: no hay nada que parsear todavía, y medirla
    // acá es instantáneo. Subir ocho megas para que del otro lado digan "está
    // movida" sería hacer esperar por una respuesta que se sabe antes de mandar.
    if (archivo && /^image\//.test(archivo.type)) { impFoto(archivo); return; }

    const datos = new FormData();
    datos.append('origen', 'archivo');
    datos.append('archivo', archivo);
    impEnviar(datos, resp => impMostrarResultado(resp));
}

function impAnalizarTexto(cual) {
    const caja = document.getElementById(cual || 'impTexto');
    const texto = caja.value;
    if (!texto.trim()) { impMostrarError('Escribí o pegá algo antes de analizar.'); return; }
    const datos = new FormData();
    datos.append('origen', 'texto');
    datos.append('texto', texto);
    impEnviar(datos, resp => impMostrarResultado(resp));
}

/* ═════════════════════════════════════════════════════════════════════════════
   ¿ESTA FOTO SIRVE?
   ═════════════════════════════════════════════════════════════════════════════

   Tres mediciones clásicas, sin nada entrenado y sin nada probabilístico. Cada
   una detecta un problema distinto y contesta en milisegundos, así que el aviso
   llega antes de que nadie espere nada:

     · NITIDEZ — varianza del laplaciano, el detector de bordes de siempre. Una
       foto movida no tiene bordes marcados. La diferencia entre una nítida y una
       movida es de dos órdenes de magnitud: no es un umbral delicado.

     · CONTRASTE — dónde cae el papel y dónde la tinta en el histograma. En un
       documento el promedio no dice nada (es casi todo blanco); lo que importa
       es cuánto se separan uno de otro.

     · ALTURA DE LA LETRA — se proyectan los píxeles oscuros fila por fila. Los
       renglones de texto aparecen como bandas y los espacios entre ellos como
       valles; el alto de esas bandas es el alto de la letra. Abajo de cierto
       tamaño no hay lectura posible, ni humana ni automática.

   Todo se mide sobre la imagen llevada a un tamaño fijo, porque si no las
   medidas no se pueden comparar entre una foto de 12 megapíxeles y una de
   WhatsApp: la misma foto daría números distintos según con qué se sacó.
   ═════════════════════════════════════════════════════════════════════════════ */

const IMP_FOTO_LADO = 2000;   // techo para medir; nunca agranda (ver abajo)
const IMP_OCR_LADO  = 2200;   // tamaño al que se lee; acá SÍ se agranda

/**
 * Una copia de la imagen con el lado mayor en `lado`.
 *
 * @param agrandar  Si es false, nunca agranda: sólo achica cuando la foto es
 *                  más grande que el techo.
 *
 * Para MEDIR nunca se agranda: estirar una foto no le agrega detalle, y si se
 * la estira, las letras miden más píxeles sin ser más legibles — el medidor
 * diría que está bien justo cuando no lo está.
 *
 * Para LEER sí conviene agrandar. Medido con un remito real que vino por
 * WhatsApp a 720×1280: leído a su tamaño no reconoció NADA del renglón del
 * artículo; agrandado a 2200 reconoció cinco de siete palabras clave, número de
 * código y vencimiento incluidos. No es que aparezca información: es que al
 * lector le sirve tener la letra en unos 30 píxeles de alto, y a 720 de ancho
 * las de la tabla quedaban en siete.
 */
function impEscalar(img, lado, agrandar) {
    let escala = lado / Math.max(img.width, img.height);
    if (!agrandar) escala = Math.min(1, escala);
    const c = document.createElement('canvas');
    c.width  = Math.max(1, Math.round(img.width  * escala));
    c.height = Math.max(1, Math.round(img.height * escala));
    const x = c.getContext('2d');
    x.imageSmoothingEnabled = true;
    x.imageSmoothingQuality = 'high';
    x.drawImage(img, 0, 0, c.width, c.height);
    c._escala = escala;          // para poder volver a la medida real de la foto
    return c;
}

/** Los umbrales. Salen de imágenes de prueba y habría que afinarlos con fotos reales. */
const IMP_FOTO_MIN = {
    nitidez:   120,   // abajo de esto está movida o fuera de foco
    contraste:  60,   // menos que esto y la tinta se confunde con el papel
    papel:      90,   // el papel tiene que verse como papel, no como sombra
    /* Alto de la letra CHICA, en píxeles de la foto original. Trece es el piso
       clásico del reconocimiento de texto: abajo de eso los trazos se tocan y
       ya no hay con qué distinguir un 5 de un 6. Verificado contra un remito
       real que vino por WhatsApp: su letra de tabla medía nueve, y el lector no
       pudo con ella. */
    altoLetra:  13,
};

function impFoto(archivo) {
    impMostrarError('');
    impSoltarFoto();                       // si había otra, se libera
    const url = URL.createObjectURL(archivo);
    IMP.fotoUrl = url;
    const img = new Image();

    img.onload = () => {
        /* Dos copias con propósitos distintos.
         *
         * La de MEDIR va siempre al mismo tamaño, porque si no las mediciones no
         * se pueden comparar: la misma foto daría números distintos sacada con un
         * teléfono de 12 megapíxeles o con uno viejo, y los umbrales no
         * significarían nada.
         *
         * La de LEER va más grande, porque al lector le sirve toda la resolución
         * que se le pueda dar sin volverlo lento. Mandarle la de medir sería
         * tirar detalle justo donde hace falta. */
        const c = impEscalar(img, IMP_FOTO_LADO, false);
        // La imagen queda guardada, no una copia ya escalada: el lector decide
        // después a qué tamaño conviene ampliarla, y eso depende de lo que mida.
        IMP.fotoImg = img;

        let m;
        try {
            m = impMedirFoto(c);
            IMP.medidas = m;
        } catch (e) {
            // Un canvas "sucio" (por ejemplo, una imagen de otro origen) no se puede
            // leer. No es motivo para no dejar cargar: se sigue sin veredicto.
            m = null;
        }

        document.getElementById('impFotoImg').src = url;
        impPintarVeredicto(m, archivo, img);
        impPaso('foto');
    };

    img.onerror = () => {
        impSoltarFoto();
        impMostrarError('No pude abrir esa imagen. Puede estar incompleta o en un formato que el navegador no lee.');
    };
    img.src = url;
}

/* ═════════════════════════════════════════════════════════════════════════════
   LEER LA FOTO

   Corre ENTERO en el dispositivo del productor: la imagen no sale de ahí, no
   hay servicio de terceros y no cuesta nada por uso. Todo lo que carga —el
   programa y los datos del castellano— sale de este mismo servidor, así que
   tampoco depende de que un CDN siga existiendo.

   LO QUE ESTO NO ES
   No es exacto y no puede serlo: reconocer un carácter es siempre una
   estimación. Por eso el resultado NO va derecho al importador — se escribe en
   el mismo cuadro de texto donde se escribiría a mano. Queda a la vista, se
   corrige, y recién ahí se analiza. Todo lo que viene después ya existía y ya
   estaba probado: el mismo parser, la misma verificación de suma contra el
   total y la misma alerta de precio fuera de rango.

   Ese es el punto: el lector adelanta trabajo, pero no decide nada. Los
   números los sigue confirmando una persona, y si se le escapa algo, la suma
   no cierra y salta.
   ═════════════════════════════════════════════════════════════════════════════ */

const IMP_OCR = {
    base: 'assets/vendor/tesseract/',
    cargando: null,     // promesa de la carga, para no bajarlo dos veces
    worker: null,
    corriendo: false,
};

/** Trae tesseract una sola vez y deja el lector listo. */
function impCargarLector(alProgreso) {
    if (IMP_OCR.worker) return Promise.resolve(IMP_OCR.worker);
    if (IMP_OCR.cargando) return IMP_OCR.cargando;

    IMP_OCR.cargando = new Promise((ok, mal) => {
        const s = document.createElement('script');
        s.src = IMP_OCR.base + 'tesseract.min.js';
        s.onload = () => ok();
        s.onerror = () => mal(new Error('No pude cargar el lector desde el servidor.'));
        document.head.appendChild(s);
    }).then(() => {
        // Las rutas van explícitas: sin esto tesseract se las busca en un CDN,
        // y la idea es justamente no depender de nadie.
        return Tesseract.createWorker('spa', 1, {
            workerPath: IMP_OCR.base + 'worker.min.js',
            corePath:   IMP_OCR.base,
            langPath:   IMP_OCR.base + 'lang',
            gzip:       true,
            logger:     m => alProgreso && alProgreso(m),
        });
    }).then(w => {
        IMP_OCR.worker = w;
        return w;
    }).catch(e => {
        IMP_OCR.cargando = null;   // que se pueda reintentar
        throw e;
    });

    return IMP_OCR.cargando;
}

function impLeerEstado(clase, titulo, detalle, progreso) {
    const caja = document.getElementById('impLeerEstado');
    caja.hidden = false;
    caja.className = 'imp-leer-estado ' + clase;
    caja.textContent = '';
    const b = document.createElement('b');
    b.textContent = titulo;
    caja.appendChild(b);
    if (detalle) caja.appendChild(document.createTextNode(detalle));
    if (progreso !== undefined) {
        const barra = document.createElement('div');
        barra.className = 'imp-barra-progreso';
        const i = document.createElement('i');
        i.style.width = Math.round(progreso * 100) + '%';
        barra.appendChild(i);
        caja.appendChild(barra);
    }
}

/**
 * A qué tamaño conviene ampliar para leer.
 *
 * Al lector le sirve tener la letra en unos 28 píxeles: más chica se le tocan
 * los trazos, más grande no gana nada y tarda de más. Como ya se midió cuánto
 * mide la letra chica en la foto original, el factor sale de una división en vez
 * de ser un número fijo. Con un número fijo, la foto entera de un remito y un
 * recorte de la tabla —que necesitan ampliaciones muy distintas— quedaban los
 * dos mal.
 */
function impLadoParaLeer(img) {
    const alto = (IMP.medidas && IMP.medidas.altoLetra) || 0;
    let factor = alto > 0 ? 28 / alto : 2;
    factor = Math.max(1, Math.min(factor, 6));
    const lado = Math.max(img.naturalWidth, img.naturalHeight) * factor;
    return Math.round(Math.max(1600, Math.min(lado, 3600)));
}

/** Una copia lista para el lector; binarizada si se pide. */
function impPrepararParaLeer(img, lado, binarizar) {
    const c = impEscalar(img, lado, true);
    if (!binarizar) return c;

    const x = c.getContext('2d');
    const d = x.getImageData(0, 0, c.width, c.height);
    const p = d.data;
    const n = c.width * c.height;

    const gris = new Float32Array(n);
    for (let i = 0; i < n; i++) gris[i] = 0.299 * p[i*4] + 0.587 * p[i*4+1] + 0.114 * p[i*4+2];

    // El mismo Otsu que usa la medición: separa tinta de papel sin suponer
    // cuánto ocupa cada una.
    const hist = new Int32Array(256);
    for (let i = 0; i < n; i++) hist[Math.round(gris[i])]++;
    let st = 0;
    for (let g = 0; g < 256; g++) st += g * hist[g];
    let so = 0, po = 0, mv = -1, corte = 128;
    for (let g = 0; g < 256; g++) {
        po += hist[g]; if (!po) continue;
        const pc = n - po; if (!pc) break;
        so += g * hist[g];
        const mo = so / po, mc = (st - so) / pc;
        const e = po * pc * (mo - mc) * (mo - mc);
        if (e > mv) { mv = e; corte = g; }
    }
    for (let i = 0; i < n; i++) {
        const v = gris[i] < corte ? 0 : 255;
        p[i*4] = p[i*4+1] = p[i*4+2] = v; p[i*4+3] = 255;
    }
    x.putImageData(d, 0, 0);
    return c;
}

/* Cómo intentar leer, en orden. Cada intento es una forma distinta de mirar la
   misma foto, y se prueban todas porque NINGUNA sirve para los dos casos:

   psm 6 lee un bloque parejo de texto y es el que saca la tabla de un recorte.
   psm 3 es el automático: reparte la hoja en zonas y funciona con la foto
   entera del remito, pero sobre una tira angosta devuelve VACÍO —medido: cero
   de ocho palabras clave, mientras que con psm 6 salían cinco—.

   Por eso no se elige de antemano: se prueban y gana el que más renglones de
   mercadería produce, que es lo único que interesa. */
const IMP_OCR_INTENTOS = [
    { psm: '6',  binarizar: true,  nombre: 'bloque' },
    { psm: '3',  binarizar: false, nombre: 'hoja entera' },
    { psm: '11', binarizar: false, nombre: 'texto suelto' },
];

async function impLeerFoto() {
    if (IMP_OCR.corriendo || !IMP.fotoImg) return;
    IMP_OCR.corriendo = true;
    const boton = document.getElementById('impLeerBtn');
    boton.disabled = true;
    document.getElementById('impDudas').hidden = true;

    try {
        impLeerEstado('trabajando', 'Preparando el lector…',
                      'La primera vez baja unos megas; después queda guardado.', 0);

        const worker = await impCargarLector(m => {
            if (m.progress !== undefined && m.status !== 'recognizing text') {
                impLeerEstado('trabajando', 'Preparando el lector…',
                              'La primera vez baja unos megas; después queda guardado.', m.progress);
            }
        });

        const lado = impLadoParaLeer(IMP.fotoImg);
        let mejor = null;
        const arranque = Date.now();

        for (let i = 0; i < IMP_OCR_INTENTOS.length; i++) {
            /* Techo de tiempo. Sobre una foto mala cada intento tarda mucho más
               —medido: 27 segundos las tres pasadas contra menos de dos en una
               buena—, y seguir probando sobre algo que no se lee es hacer
               esperar al pedo. Con lo que se haya sacado hasta acá alcanza para
               decidir. */
            if (i > 0 && Date.now() - arranque > 18000) break;

            const intento = IMP_OCR_INTENTOS[i];
            impLeerEstado('trabajando', 'Leyendo la foto…',
                'Probando de leerla de varias formas (' + (i + 1) + ' de ' +
                IMP_OCR_INTENTOS.length + ').', (i + 0.5) / IMP_OCR_INTENTOS.length);

            const lienzo = impPrepararParaLeer(IMP.fotoImg, lado, intento.binarizar);
            await worker.setParameters({ tessedit_pageseg_mode: intento.psm });
            /* Los bloques hacen falta para la confianza POR PALABRA: es la que
               permite decir en qué números dudó, que es lo que hace revisable
               una lectura de veinte renglones. */
            const { data } = await worker.recognize(lienzo, {}, { text: true, blocks: true });

            const cuantos = impRenglonesUtiles(data.text || '').length;
            const largo = (data.text || '').trim().length;

            /* Se desempata por CUÁNTO TEXTO sacó, no por la confianza.
               La confianza de una pasada que no leyó nada viene alta —el lector
               informa 95 sobre un resultado vacío—, así que desempatar por ahí
               hacía ganar a la que no había leído nada contra la que sí había
               sacado el renglón. Medido: la pasada de hoja entera devolvía vacío
               con 95 y le ganaba a la de bloque, que traía el artículo. */
            if (!mejor || cuantos > mejor.cuantos ||
                (cuantos === mejor.cuantos && largo > mejor.largo)) {
                mejor = { cuantos: cuantos, largo: largo, data: data, intento: intento };
            }
            // Con varios renglones ya reconocidos no hace falta seguir probando.
            if (cuantos >= 2) break;
        }

        impMostrarLectura(mejor.data);

    } catch (e) {
        impLeerEstado('falla', 'No pude leer la foto.',
            (e && e.message ? e.message + ' ' : '') +
            'Podés escribir los renglones a mano mirándola, que es lo que estaba antes.');
    } finally {
        IMP_OCR.corriendo = false;
        boton.disabled = false;
    }
}

/**
 * Qué tan en serio hay que tomar lo que salió.
 *
 * El lector devuelve una confianza por palabra. Un promedio bajo, o mucha
 * basura de símbolos, quiere decir que leyó cualquier cosa — y decirlo es más
 * útil que entregar cinco renglones inventados con cara de datos.
 */
function impMostrarLectura(data) {
    const texto = (data.text || '').replace(/\n{3,}/g, '\n\n').trim();

    if (!texto) {
        impLeerEstado('falla', 'No encontré texto en la foto.',
            'Puede estar muy movida, muy oscura, o no ser un remito. Probá con otra, o escribí a mano.');
        return;
    }

    // Las palabras vienen anidadas en bloques → párrafos → renglones. Se las
    // aplana para poder mirar la confianza de cada una.
    const palabras = [];
    (data.blocks || []).forEach(bl => (bl.paragraphs || []).forEach(
        pa => (pa.lines || []).forEach(
            li => (li.words || []).forEach(p => { if ((p.text || '').trim()) palabras.push(p); }))));

    // Si no hubo bloques, queda la confianza global, que el lector siempre da.
    const confianza = palabras.length
        ? palabras.reduce((a, p) => a + (p.confidence || 0), 0) / palabras.length
        : (data.confidence || 0);
    // Basura: lo que no es letra, número ni puntuación de las que aparecen en un
    // remito. Cuando el lector se pierde, escupe ~ | ¬ { » y esas cosas.
    const basura = (texto.match(/[^\wáéíóúüñÁÉÍÓÚÜÑ\s.,:;%$()\/\-+°ºª]/g) || []).length;
    const proporcionBasura = basura / Math.max(1, texto.length);

    /* Se filtra sobre el texto PODADO, no sobre el crudo: la cola de basura del
       final de cada renglón se saca antes de buscar los renglones de mercadería.
       Si la lectura no trajo bloques no hay confianza por palabra y no se puede
       podar; ahí sigue de largo con el crudo, como antes. */
    const utiles = impRenglonesUtiles(impTextoPodado(data) || texto);

    /* SÓLO LOS RENGLONES QUE SIRVEN, no todo lo que leyó.
     *
     * Antes se volcaba el texto crudo, y en la foto de un remito eso son
     * cincuenta renglones: el membrete, la dirección, los datos del transporte,
     * el pie de página, y los pedazos del fondo, de la mano y del borde del
     * papel. El renglón que importa quedaba enterrado ahí adentro y la pantalla
     * era ilegible. Probado con un remito real: incomprensible.
     *
     * Va lo mismo que el importador va a usar, ya limpio. Lo demás se guarda por
     * si el filtro se pasó de listo, pero plegado y fuera del camino. */
    const caja = document.getElementById('impTextoFoto');
    caja.value = utiles.join('\n');
    impAjustarAlto(caja);          // que el cuadro quede del alto de lo leído
    impGuardarCrudo(texto);

    /* Los tres estados, en un renglón cada uno. El aviso de revisar los números
       queda, porque es lo único que no se puede dar por sabido: el lector se
       equivoca sin avisar y un dígito cambiado no se nota. */
    const cuantos = utiles.length + (utiles.length === 1 ? ' renglón' : ' renglones');
    if (!utiles.length) {
        impLeerEstado('dudoso', 'No encontré insumos en la foto.',
            'Escribilos a mano mirándola.');
    } else if (confianza >= 75 && proporcionBasura < 0.05) {
        impLeerEstado('bien', 'Saqué ' + cuantos + '.', 'Revisá los números contra la foto.');
    } else {
        impLeerEstado('dudoso', 'Saqué ' + cuantos + ', con dudas.',
            'Confianza ' + Math.round(confianza) + '/100. Revisalos uno por uno.');
    }

    impMostrarDudas(palabras, utiles.join('\n'));
}

/**
 * De todo lo que leyó, los renglones que de verdad son mercadería.
 *
 * Aplica la MISMA limpieza que el parser del servidor, para que lo que se ve en
 * pantalla sea exactamente lo que el importador va a usar. Si acá se mostrara
 * una cosa y allá se interpretara otra, la revisión no serviría de nada.
 */
function impRenglonesUtiles(texto) {
    // Frases del emisor y del cliente. No aparecen nunca describiendo mercadería.
    const RUIDO = /ing\.?\s*brutos|ingresos brutos|inicio actividades|domicilio|localidad|responsable inscripto|cuit|cuil|impreso por|firma del|retirada por|patentes|observaciones|transporte|documento n|condici[oó]n de venta/i;

    // La dirección del membrete: arranca con un número y no es mercadería.
    const CALLE = /^\d{1,4} de (enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\b|\b(av|avda|avenida|calle|ruta|esquina|piso|depto)\b/i;

    const salida = [];
    texto.split('\n').forEach(cruda => {
        // Bordes de la tabla a espacios, igual que en el servidor. Las rayas
        // largas y los guiones bajos también: así dibuja las líneas del
        // formulario. El guión corto no, que va en fechas y en lotes.
        let l = cruda.replace(/[|\[\]—–_¬]/g, ' ').replace(/\s+/g, ' ').trim();
        if (l.length < 4) return;
        if (RUIDO.test(l) || CALLE.test(l)) return;

        /* El total se conserva aunque no sea mercadería: es lo que después
           permite verificar que la suma cierre. */
        if (/^\s*(sub\s*total|total|neto)\b/i.test(l)) {
            if (/\d/.test(l)) salida.push(l);
            return;
        }

        /* Un código de primera columna no es la cantidad. Se reconoce por los
           ceros adelante, o por ser largo: leyendo la foto, el borde de la tabla
           se le pega y "0000100" llega como "10000100". */
        l = l.replace(/^\d{5,}\s+(?=\d)/, '').replace(/^0\d{2,}\s+(?=\d)/, '');

        /* Cantidad adelante, unidad opcional, y después la descripción. Si no
           entra, se prueba sin la primera palabra, hasta dos veces: adelante
           suele quedar pegado el borde de la tabla o el código del artículo con
           las letras cambiadas ("Oooo1o0" por "0000100", que es la confusión más
           común del lector). Mismo criterio que el servidor. */
        let m = null, resto = l;
        for (let i = 0; i <= 2; i++) {
            m = resto.match(/^\d[\d.,]*\s*[a-zA-Záéíóúñ]{0,8}\s+(.+)$/u);
            if (m) break;
            const corte = resto.indexOf(' ');
            if (corte === -1) break;
            resto = resto.slice(corte + 1);
        }
        if (!m) return;
        l = resto;

        /* Y la descripción tiene que ser una descripción: al menos una palabra
           de cinco letras. Sin esto entraban los restos del fondo de la foto
           —"3 ESA SA Ne", "5 — O ION RA"— y el cuadro se volvía ilegible otra
           vez. Mismo criterio que aplica el servidor. */
        if (!/\p{L}{5,}/u.test(m[1])) return;

        salida.push(l);
    });
    return salida;
}

/* ═════════════════════════════════════════════════════════════════════════════
   LA COLA DE BASURA AL FINAL DEL RENGLÓN

   EL CASO QUE LO MOTIVÓ
   Un remito real dio este renglón:

       1.00 FOSFURO DE ALUMINIO TECNOFOS x BOTELLA rvonacasos Jsaganas

   Hasta "BOTELLA" está bien. Las dos últimas no existen: son las columnas de
   precio de la derecha, que el lector barrió como si fueran parte del nombre.
   Pasa porque las columnas de un remito están cerca y la foto sale con la hoja
   apenas torcida, así que el renglón de una columna se solapa con el de la otra.

   HACEN FALTA DOS SEÑALES, Y ESTO ESTÁ MEDIDO
   Lo primero que probé fue cortar sólo por confianza baja, que suena a lo más
   sensato: es lo que el propio lector dice de lo que acaba de leer. Lo probé
   con Tesseract de verdad sobre un renglón dibujado a propósito, con una
   columna de precios sucia pegada a la derecha, y salió esto:

       90  "FOSFURO"      93  "ALUMINIO"     91  "TECNOFOS"
       37  "x"            37  "BOTELLA"      19  "“*"

   O sea que "BOTELLA" —una palabra real y necesaria— vino con la MISMA
   confianza que la basura. Es lógico: el ruido de la columna vecina la toca, y
   el lector duda de las dos por igual. Cortando por confianza sola en 60 le
   borraba "x BOTELLA" al producto. Mal.

   Y al revés tampoco alcanza mirar sólo la forma de la palabra: "rvonacasos" no
   se puede pronunciar, pero el catálogo está lleno de "TECNOFOS", siglas y
   marcas que a cualquier regla de ortografía también le parecen basura.

   Entonces se piden las dos cosas a la vez, y cada una tapa el agujero de la
   otra:
     · la FORMA decide — sólo se borra lo que no se puede pronunciar;
     · la CONFIANZA protege — una marca rara pero leída NÍTIDA no se toca nunca,
       por más impronunciable que sea.
   "BOTELLA" se salva por la forma aunque tenga confianza 37; un "Xzzyk" leído
   con confianza 90 se salva por la confianza aunque no se pueda pronunciar.

   LAS CINCO TRABAS
   Borrar es destructivo, así que sólo se corta cuando se cumple todo:
     1. la palabra no tiene ningún dígito — jamás se borra algo numérico, que es
        donde están los precios y las cantidades;
     2. no se puede pronunciar (o es puro símbolo);
     3. la confianza está por debajo del umbral;
     4. el renglón tiene al menos tres palabras — con dos no hay contexto para
        decidir nada, aunque lo que queda sí puede terminar en dos;
     5. lo que queda sigue teniendo una palabra de cinco letras, o sea que sigue
        pareciendo una descripción. Por eso "1 Glifosato xxvvzz" sí se poda a
        "1 Glifosato", que es un ítem entero, pero nada que borre el nombre del
        producto llega a pasar.
   Y como mucho tres palabras por renglón: si hay que sacar más, el renglón está
   mal de entrada y lo tiene que ver la persona, no taparlo yo.
   ═════════════════════════════════════════════════════════════════════════════ */

/* El umbral acá NO es el que decide: decide la forma de la palabra. Esto es sólo
   el techo que protege lo que el lector leyó nítido, así que va alto a propósito
   —el mismo 80 con el que se avisa de un número dudoso más arriba—. Bajarlo no
   hace el corte más prudente: lo hace depender de un número que, según la
   medición de arriba, no distingue "BOTELLA" de la basura. */
const IMP_CONF_BORRAR = 80;
const IMP_COLA_MAX = 3;

/* Arranques de palabra con dos consonantes que sí existen — en castellano y en
   los nombres de producto, que traen inglés y siglas. Lo que empieza con dos
   consonantes fuera de esta lista no lo puede pronunciar nadie: "rvonacasos",
   "Jsaganas". */
const IMP_ARRANQUES = /^(?:bl|br|cl|cr|dr|fl|fr|gl|gr|kl|kn|kr|pl|pr|ps|pn|qu|sc|sh|sk|sl|sm|sn|sp|st|sw|th|tl|tr|tw|ph|gn|gh|wh|wr|ch|ll|rr)/i;

/** ¿Es una palabra que nadie podría pronunciar, o puro símbolo suelto? */
function impPalabraImposible(t) {
    const letras = (t.match(/\p{L}/gu) || []).length;
    // Puro símbolo, sin una letra ni un número: basura segura.
    if (!letras && !/\d/.test(t)) return true;
    /* De tres letras para abajo no se opina: ahí viven "x", "kg", "un" y las
       siglas, y no hay con qué distinguirlas de un error. */
    if (letras < 4) return false;

    const limpia = t.replace(/[^\p{L}]/gu, '');
    const VOCAL = /[aeiouáéíóúüàèìòùâêîôûyAEIOUÁÉÍÓÚÜÀÈÌÒÙÂÊÎÔÛY]/;
    if (!VOCAL.test(limpia)) return true;                    // sin una sola vocal
    if (/[^aeiouáéíóúüy\W\d]{4,}/i.test(limpia)) return true; // cuatro consonantes seguidas
    // Dos consonantes al principio que no forman un arranque posible.
    if (!VOCAL.test(limpia[0]) && !VOCAL.test(limpia[1]) && !IMP_ARRANQUES.test(limpia)) return true;
    return false;
}

/** Los renglones con la confianza de cada palabra, que en data.text ya se perdió. */
function impRenglonesConPalabras(data) {
    const renglones = [];
    (data.blocks || []).forEach(bl => (bl.paragraphs || []).forEach(
        pa => (pa.lines || []).forEach(li => {
            const palabras = (li.words || [])
                .map(p => ({ texto: (p.text || '').trim(), conf: p.confidence || 0 }))
                .filter(p => p.texto);
            if (palabras.length) renglones.push(palabras);
        })));
    return renglones;
}

/** Le saca a un renglón la cola de palabras que el lector leyó mal. */
function impPodarCola(palabras) {
    const hayDescripcion = ps => ps.some(p => /\p{L}{5,}/u.test(p.texto));
    let fin = palabras.length;
    for (let i = 0; i < IMP_COLA_MAX; i++) {
        if (fin < 3) break;
        const u = palabras[fin - 1];
        if (/\d/.test(u.texto)) break;              // nunca un número
        if (!impPalabraImposible(u.texto)) break;   // se puede pronunciar: se queda
        if (u.conf >= IMP_CONF_BORRAR) break;       // lo leyó nítido: se queda
        if (!hayDescripcion(palabras.slice(0, fin - 1))) break;
        fin--;
    }
    return palabras.slice(0, fin);
}

/**
 * El texto leído, ya sin las colas.
 *
 * Devuelve null si la lectura no trajo bloques —hay fotos donde no vienen—; ahí
 * el que llama se queda con data.text tal cual, que es lo que había antes.
 */
function impTextoPodado(data) {
    const renglones = impRenglonesConPalabras(data);
    if (!renglones.length) return null;
    return renglones.map(r => impPodarCola(r).map(p => p.texto).join(' ')).join('\n');
}

/** Todo lo leído, plegado: la salida de emergencia si el filtro se pasó. */
function impGuardarCrudo(texto) {
    const caja = document.getElementById('impCrudo');
    if (!caja) return;
    caja.textContent = '';
    if (!texto) { caja.hidden = true; return; }
    caja.hidden = false;
    const s = document.createElement('summary');
    s.textContent = 'Ver todo lo que leí, sin filtrar';
    const pre = document.createElement('pre');
    pre.textContent = texto;
    caja.appendChild(s);
    caja.appendChild(pre);
}

/** Las palabras donde el lector dudó, sobre todo las que tienen números. */
function impMostrarDudas(palabras, textoFinal) {
    const caja = document.getElementById('impDudas');
    caja.textContent = '';

    /* Se muestran sólo las que tienen dígitos. Un nombre de insumo mal leído se
       ve a simple vista —"Ur3a" salta—, pero un precio mal leído se lee como un
       precio y no se nota. La atención tiene que ir a los números.

       Y sólo las que quedaron en los renglones que se van a usar. Antes se
       listaban también las del membrete y los CUIT —números que ni siquiera
       están en el cuadro—, y mandaba a comparar contra la foto cosas que no se
       iban a cargar. */
    const dudosas = palabras
        .filter(p => (p.confidence || 100) < 80 && /\d/.test(p.text || ''))
        .map(p => p.text.trim())
        // Nada de fragmentos de uno o dos caracteres: "00" o "6" sueltos no le
        // dicen a nadie qué mirar, y ensucian el aviso que sí importa.
        .filter(v => v.length >= 3 && textoFinal.indexOf(v) !== -1)
        .filter((v, i, a) => a.indexOf(v) === i)
        .slice(0, 12);

    if (!dudosas.length) { caja.hidden = true; return; }

    caja.hidden = false;
    const b = document.createElement('b');
    b.textContent = 'Dudé en estos números: ';
    caja.appendChild(b);
    dudosas.forEach((d, i) => {
        if (i) caja.appendChild(document.createTextNode(' '));
        const m = document.createElement('mark');
        m.textContent = d;
        caja.appendChild(m);
    });
    caja.appendChild(document.createTextNode(' — comparalos con la foto antes de seguir.'));
}

function impMedirFoto(canvas) {
    const w = canvas.width, h = canvas.height;
    const d = canvas.getContext('2d').getImageData(0, 0, w, h).data;

    // A gris una sola vez: las tres mediciones trabajan sobre lo mismo.
    const gris = new Float32Array(w * h);
    for (let i = 0, n = w * h; i < n; i++) {
        gris[i] = 0.299 * d[i * 4] + 0.587 * d[i * 4 + 1] + 0.114 * d[i * 4 + 2];
    }

    // ── Nitidez: varianza del laplaciano ──
    let suma = 0, suma2 = 0, n = 0;
    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const i = y * w + x;
            const lap = 4 * gris[i] - gris[i - 1] - gris[i + 1] - gris[i - w] - gris[i + w];
            suma += lap; suma2 += lap * lap; n++;
        }
    }
    const media = n ? suma / n : 0;
    const nitidez = n ? (suma2 / n - media * media) : 0;

    /* ── Dónde cae el papel y dónde la tinta ──
     *
     * Por percentiles NO SE PUEDE, y es un error fácil de cometer: en un
     * documento el texto es una minoría de los píxeles —un remito escrito es
     * un 3% de tinta y un 97% de papel—, así que el percentil 5 sigue cayendo
     * en el blanco y la cuenta da contraste cero sobre una foto perfecta.
     * Verificado: una imagen impecable daba "está quemada".
     *
     * Otsu no tiene ese problema. Busca el corte que mejor parte el histograma
     * en dos poblaciones, sin suponer nada sobre el tamaño de cada una, y de
     * ahí salen el gris promedio de la tinta y el del papel. Es el método
     * clásico de binarización de documentos, de 1979, y no tiene nada
     * entrenado ni nada probabilístico. */
    const total = w * h;
    const hist = new Int32Array(256);
    for (let i = 0; i < total; i++) hist[Math.round(gris[i])]++;

    let sumaTotal = 0;
    for (let g = 0; g < 256; g++) sumaTotal += g * hist[g];

    let sumaOscuro = 0, pesoOscuro = 0, mejorVar = -1, corte = 128;
    for (let g = 0; g < 256; g++) {
        pesoOscuro += hist[g];
        if (!pesoOscuro) continue;
        const pesoClaro = total - pesoOscuro;
        if (!pesoClaro) break;
        sumaOscuro += g * hist[g];
        const mediaOscuro = sumaOscuro / pesoOscuro;
        const mediaClaro  = (sumaTotal - sumaOscuro) / pesoClaro;
        const entre = pesoOscuro * pesoClaro * (mediaOscuro - mediaClaro) * (mediaOscuro - mediaClaro);
        if (entre > mejorVar) { mejorVar = entre; corte = g; }
    }

    // El promedio de cada lado del corte: eso es la tinta y eso es el papel.
    let sTinta = 0, nTinta = 0, sPapel = 0, nPapel = 0;
    for (let g = 0; g < 256; g++) {
        if (g <= corte) { sTinta += g * hist[g]; nTinta += hist[g]; }
        else            { sPapel += g * hist[g]; nPapel += hist[g]; }
    }
    const p5  = nTinta ? Math.round(sTinta / nTinta) : 0;     // tinta
    const p95 = nPapel ? Math.round(sPapel / nPapel) : 255;   // papel

    // ── Alto de la letra por proyección horizontal ──
    // Con el corte de Otsu se marca qué es tinta y se cuenta, fila por fila,
    // cuánto hay. Un renglón de texto es una banda de filas con tinta; el
    // espacio entre renglones, un valle. El alto típico de esas bandas es el
    // alto de la letra.
    const minOscuros = Math.max(3, w * 0.02);   // menos que esto es suciedad, no un renglón
    const bandas = [];
    let corrida = 0;
    for (let y = 0; y < h; y++) {
        let oscuros = 0;
        const fila = y * w;
        for (let x = 0; x < w; x++) if (gris[fila + x] < corte) oscuros++;
        if (oscuros >= minOscuros) {
            corrida++;
        } else if (corrida) {
            if (corrida > 1) bandas.push(corrida);   // una fila suelta es ruido
            corrida = 0;
        }
    }
    if (corrida > 1) bandas.push(corrida);

    bandas.sort((a, b) => a - b);

    /* EL CUARTIL DE ABAJO, NO LA MEDIANA. Esto se corrigió con un remito real.
     *
     * Un comprobante tiene el membrete grande, los títulos medianos y los
     * renglones de la tabla chiquitos. La mediana describe a los medianos —en
     * esa foto daba 29 píxeles y pasaba cómoda el control—, pero el renglón del
     * artículo, que es EL DATO, medía siete. El lector no pudo con él y el
     * medidor había dicho que la foto estaba bien.
     *
     * Lo que hay que mirar es la letra chica, porque ahí es donde está lo que se
     * quiere leer y es la primera en volverse ilegible. */
    const cuartil = bandas.length ? bandas[Math.floor(bandas.length * 0.25)] : 0;

    /* Y se devuelve a la escala de la FOTO ORIGINAL. Lo que decide si algo se
       puede leer es cuántos píxeles de verdad tiene la letra, no cuántos tiene
       después de que la achicamos para medirla. */
    const escala = canvas._escala || 1;
    let altoLetra = Math.round(cuartil / escala);

    /* Una banda que se come más del 15% del alto no es un renglón: es que la
       binarización dio "todo tinta". Pasa con mucho ruido de sensor o con una
       foto muy pareja, y devolvía cosas como "alto de letra: 584 px", que además
       de ser mentira hacía pasar el control de tamaño. Cuando la medida no es
       creíble se dice que no se pudo medir, en vez de inventar un número. */
    const letraConfiable = cuartil > 0 && bandas[bandas.length - 1] < h * 0.5;
    if (!letraConfiable) altoLetra = 0;

    return {
        nitidez: nitidez, papel: p95, tinta: p5, contraste: p95 - p5,
        altoLetra: altoLetra, letraConfiable: letraConfiable,
        renglones: bandas.length, ancho: w, alto: h,
    };
}

/**
 * El veredicto, en el idioma del que sacó la foto.
 *
 * Cada medición que falla se traduce a lo que hay que hacer distinto, no al
 * nombre del problema: "apoyá el codo" sirve, "varianza del laplaciano baja" no.
 * Y nunca bloquea: se puede cargar a mano igual, mirando una foto regular.
 */
function impPintarVeredicto(m, archivo, img) {
    const caja = document.getElementById('impVeredicto');
    const medidas = document.getElementById('impMedidas');
    caja.textContent = '';
    medidas.textContent = '';

    document.getElementById('impFotoSub').textContent =
        archivo.name + ' · ' + img.width + '×' + img.height + ' px';

    if (!m) {
        caja.className = 'imp-veredicto sirve';
        const b = document.createElement('b');
        b.textContent = 'No pude medir esta foto, pero podés usarla igual.';
        caja.appendChild(b);
        return;
    }

    /* Cada problema en dos o tres palabras.
     *
     * Antes cada uno era una frase con el consejo completo —"apoyá el codo o el
     * teléfono en algo firme y sacala de nuevo"— y con dos problemas el aviso
     * ocupaba media pantalla del teléfono, arriba de la foto que se quiere
     * mirar. El consejo era bueno; el lugar, no. Lo que hace falta saber de un
     * vistazo es SI la foto sirve y POR QUÉ NO, no qué hacer paso a paso: quien
     * la sacó ya sabe cómo sacar otra. */
    const problemas = [];
    if (m.nitidez < IMP_FOTO_MIN.nitidez) problemas.push('está movida');
    if (m.papel   < IMP_FOTO_MIN.papel)   problemas.push('está oscura');
    if (m.contraste < IMP_FOTO_MIN.contraste) {
        // El remedio es opuesto según la causa, y en dos palabras se distingue.
        problemas.push(m.papel > 235 ? 'hay reflejo' : 'poco contraste');
    }
    if (!m.letraConfiable)                        problemas.push('mucho ruido');
    else if (m.altoLetra < IMP_FOTO_MIN.altoLetra) problemas.push('letra muy chica');

    const b = document.createElement('b');
    if (!problemas.length) {
        caja.className = 'imp-veredicto sirve';
        b.textContent = '✓ Se lee bien';
        caja.appendChild(b);
    } else {
        caja.className = 'imp-veredicto no-sirve';
        b.textContent = '⚠ Calidad baja: ' + problemas.join(', ');
        caja.appendChild(b);
    }

    // Los números, en un renglón: sirven para discutir el veredicto en vez de
    // creerle, pero no para leerlos siempre.
    const partes = [
        'nitidez ' + Math.round(m.nitidez),
        'contraste ' + m.contraste,
        'letra ' + (m.letraConfiable ? m.altoLetra + ' px' : '?'),
    ];
    medidas.textContent = partes.join(' · ');
}

/**
 * Recorre los pasos de la animación. Devuelve una promesa que se resuelve
 * cuando terminan todos, para que el resultado no aparezca de golpe a los 200 ms
 * dejando al usuario sin entender qué pasó.
 */
function impAnimar() {
    const pasos = Array.from(document.querySelectorAll('#impPasos li'));
    pasos.forEach(li => li.className = '');

    const lento = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const espera = lento ? 620 : 200;
    let cancelado = false;

    const promesa = new Promise(resolve => {
        let i = 0;
        (function siguiente() {
            if (cancelado) return resolve();
            if (i > 0) pasos[i - 1].className = 'listo';
            if (i >= pasos.length) return resolve();
            pasos[i].className = 'activo';
            i++;
            setTimeout(siguiente, espera);
        })();
    });

    return { promesa, cancelar() { cancelado = true; } };
}

async function impEnviar(datos, alTerminar) {
    datos.append('csrf_token', IMP.csrf);
    datos.append('ajax', 'importar_insumos');   // hace que la cuenta demo reciba JSON y no HTML

    impMostrarError('');
    impPaso('analisis');
    IMP.animacion = impAnimar();

    try {
        const r = await fetch('api/importar_insumos.php', {
            method: 'POST', body: datos, credentials: 'same-origin',
        });
        const crudo = await r.text();

        let resp;
        try {
            resp = JSON.parse(crudo);
        } catch (e) {
            throw new Error('El servidor devolvió una respuesta inesperada. Probá recargar la página.');
        }
        if (!resp.ok) {
            // El endpoint manda `error` como texto; el bloqueo de la cuenta demo
            // que hace config/auth.php manda `error: true` y el detalle en `msg`.
            const detalle = (typeof resp.error === 'string' && resp.error) ? resp.error : (resp.msg || resp.message);
            throw new Error(detalle || 'No se pudo procesar el archivo.');
        }

        await IMP.animacion.promesa;
        alTerminar(resp);

    } catch (e) {
        IMP.animacion.cancelar();
        impPaso('origen');
        impMostrarError(e.message);
    } finally {
        IMP.animacion = null;
    }
}

// ── Resultado ────────────────────────────────────────────────────────────────
function impMostrarResultado(resp) {
    if (!resp.items || !resp.items.length) {
        impPaso('origen');
        impMostrarError(resp.aviso || 'No se reconoció ninguna fila de insumos en el archivo.');
        return;
    }

    IMP.datos = resp;
    IMP.cotizacion = Number(resp.cotizacion) || 0;
    IMP.moneda = 'ARS';
    document.getElementById('impMoneda').value = 'ARS';
    // El encabezado tiene que decir la moneda desde el arranque, no recién
    // cuando alguien toca el selector: es el dato que evita cargar pesos
    // creyendo que son dólares.
    document.getElementById('impThPrecio').textContent = 'Precio $';
    IMP.items = resp.items.map(it => Object.assign({}, it, {
        incluir: true,
        // Si se parece a algo que ya está en el inventario, la propuesta por
        // defecto es sumarle stock: un remito es una entrada de mercadería, no
        // un insumo nuevo.
        modo: it.match_id ? 'sumar' : 'nuevo',
        insumo_id: it.match_id || null,
        deposito_id: '',
    }));

    document.getElementById('impConfTitulo').textContent =
        'Encontré ' + IMP.items.length + (IMP.items.length === 1 ? ' insumo' : ' insumos');
    document.getElementById('impConfSub').textContent =
        'En ' + resp.archivo + '. Revisá, corregí lo que haga falta y destildá lo que no quieras cargar.';

    const aviso = document.getElementById('impConfAviso');
    aviso.textContent = resp.aviso || '';
    aviso.hidden = !resp.aviso;

    document.getElementById('impTodos').checked = true;
    document.getElementById('impDepGlobal').value = '';

    impRenderRemap();
    impRenderTabla();

    document.getElementById('importModal').style.display = 'none';
    document.getElementById('importConfirmModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

/**
 * Selectores para corregir qué columna es qué. Sólo aparece cuando el archivo
 * venía en columnas; un remito leído renglón por renglón no tiene columnas que
 * reasignar. El recálculo lo hace el servidor para no tener las reglas de
 * interpretación escritas dos veces.
 */
function impRenderRemap() {
    const cont = document.getElementById('impRemap');
    cont.textContent = '';

    const d = IMP.datos;
    if (!d || d.modo !== 'tabla' || !d.grid || !d.grid.length) return;

    const ancho = Math.max.apply(null, d.grid.map(f => f.length));
    if (ancho < 2) return;

    const etiquetas = [];
    for (let c = 0; c < ancho; c++) {
        const cab = (d.encabezado !== null && d.grid[d.encabezado]) ? String(d.grid[d.encabezado][c] || '').trim() : '';
        etiquetas.push(cab ? (c + 1) + '. ' + cab.slice(0, 22) : 'Columna ' + (c + 1));
    }

    IMP_CAMPOS.forEach(function (par) {
        const campo = par[0], titulo = par[1];
        const label = document.createElement('label');
        label.className = 'imp-campo';
        const span = document.createElement('span');
        span.textContent = titulo;
        label.appendChild(span);

        const sel = document.createElement('select');
        sel.add(new Option('—', ''));
        etiquetas.forEach((et, c) => sel.add(new Option(et, String(c))));
        sel.value = (d.mapeo && d.mapeo[campo] !== undefined) ? String(d.mapeo[campo]) : '';
        sel.addEventListener('change', () => {
            if (sel.value === '') delete IMP.datos.mapeo[campo];
            else IMP.datos.mapeo[campo] = parseInt(sel.value, 10);
            impRemapear();
        });
        label.appendChild(sel);
        cont.appendChild(label);
    });
}

async function impRemapear() {
    const d = IMP.datos;
    const datos = new FormData();
    datos.append('origen', 'remapeo');
    datos.append('csrf_token', IMP.csrf);
    datos.append('ajax', 'importar_insumos');
    datos.append('grid', JSON.stringify(d.grid));
    datos.append('mapeo', JSON.stringify(d.mapeo));
    datos.append('encabezado', d.encabezado === null ? '' : String(d.encabezado));

    try {
        const r = await fetch('api/importar_insumos.php', { method: 'POST', body: datos, credentials: 'same-origin' });
        const resp = await r.json();
        if (!resp.ok) {
            const detalle = (typeof resp.error === 'string' && resp.error) ? resp.error : (resp.msg || resp.message);
            throw new Error(detalle || 'No se pudo recalcular.');
        }

        const dep = document.getElementById('impDepGlobal').value;
        IMP.items = resp.items.map(it => Object.assign({}, it, {
            incluir: true,
            modo: it.match_id ? 'sumar' : 'nuevo',
            insumo_id: it.match_id || null,
            deposito_id: dep,
        }));
        impRenderTabla();
    } catch (e) {
        alert('No se pudo recalcular con esas columnas: ' + e.message);
    }
}

// ── Tabla editable ───────────────────────────────────────────────────────────
function impCrearSelect(opciones, valor, alCambiar) {
    const s = document.createElement('select');
    opciones.forEach(par => s.add(new Option(par[1], par[0])));
    s.value = valor;
    s.addEventListener('change', () => alCambiar(s.value));
    return s;
}

function impCrearInput(tipo, valor, alCambiar, extra) {
    const i = document.createElement('input');
    i.type = tipo;
    i.value = (valor === null || valor === undefined) ? '' : valor;
    Object.assign(i, extra || {});
    i.addEventListener('input', () => alCambiar(i.value));
    return i;
}

/**
 * Los campos de cantidad y precio son de texto, no <input type="number">.
 *
 * Con lang="es" el navegador localiza el campo numérico y espera la coma como
 * separador decimal, pero si el usuario escribe algo que el navegador considera
 * inválido devuelve una cadena vacía: el número tipeado se pierde sin aviso.
 * Con un campo de texto siempre se lee lo que la persona escribió y la coma la
 * interpretamos acá, igual que hace el importador del lado del servidor.
 */
function impNumero(valor) {
    if (typeof valor === 'number') return isFinite(valor) ? valor : 0;

    let s = String(valor == null ? '' : valor).replace(/[^\d.,\-]/g, '');
    if (s === '' || s === '-') return 0;

    const coma  = s.lastIndexOf(',');
    const punto = s.lastIndexOf('.');

    if (coma !== -1 && punto !== -1) {
        // Manda el separador que está más a la derecha: ése es el decimal.
        s = (coma > punto) ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
    } else if (coma !== -1) {
        s = s.replace(',', '.');                          // coma sola = decimal (es-AR)
    } else if (/^-?\d{1,3}(\.\d{3})+$/.test(s)) {
        s = s.replace(/\./g, '');                         // 1.250 = mil doscientos cincuenta
    }

    const n = parseFloat(s);
    return isFinite(n) ? n : 0;
}

/** Number a texto con coma decimal, para que el campo se vea como acá se escribe. */
function impTexto(valor) {
    if (valor === null || valor === undefined || valor === '') return '';
    return String(valor).replace('.', ',');
}

/** Cuando la fila suma stock, el nombre/tipo/unidad son del insumo que ya existe. */
function impPintarModo(tr, it) {
    const suma = (it.modo === 'sumar');
    ['nombre', 'tipo', 'unidad'].forEach(k => {
        if (!tr._campos[k]) return;
        tr._campos[k].disabled = suma;
        tr._campos[k].style.opacity = suma ? '0.4' : '';
    });
}

function impRenderTabla() {
    const tbody = document.getElementById('impTbody');
    tbody.textContent = '';
    IMP.items.forEach(it => tbody.appendChild(impFila(it)));
    impContar();
    impCuadrar();
}

// ── Las dos comprobaciones que no dependen de que nadie mire ─────────────────

/** Plata con separadores de acá. */
function impPlata(n, moneda) {
    return (moneda === 'USD' ? 'US$' : '$') +
           Number(n || 0).toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function impCambiarMoneda(valor) {
    IMP.moneda = (valor === 'USD') ? 'USD' : 'ARS';
    document.getElementById('impThPrecio').textContent =
        'Precio ' + (IMP.moneda === 'USD' ? 'US$' : '$');
    impRenderTabla();
}

/**
 * ¿El precio de esta fila se despegó del que ya le conocíamos al insumo?
 *
 * La comparación se hace SIEMPRE en dólares, que es como guarda el catálogo. Si
 * el comprobante viene en pesos hay que convertirlo antes: comparar un peso
 * contra un dólar da mil veces de diferencia y marcaría absolutamente todo,
 * que es lo mismo que no marcar nada.
 */
function impAlertaPrecio(it) {
    if (!it.match_id || !it.match_precio_usd) return null;
    const precio = impNumero(it.precio);
    if (!(precio > 0)) return null;

    let enUsd = precio;
    if (IMP.moneda === 'ARS') {
        if (!(IMP.cotizacion > 0)) return null;   // sin cotización no se puede comparar
        enUsd = precio / IMP.cotizacion;
    }

    const anterior = Number(it.match_precio_usd);
    const razon = enUsd / anterior;
    if (razon <= IMP_SALTO_PRECIO && razon >= 1 / IMP_SALTO_PRECIO) return null;

    const veces = razon > 1 ? razon : 1 / razon;
    return (razon > 1 ? 'Es ' : 'Es ') + veces.toFixed(1).replace('.', ',') +
           (razon > 1 ? ' veces más caro' : ' veces más barato') +
           ' que la última vez (US$' +
           anterior.toLocaleString('es-AR', {maximumFractionDigits: 2}) + '). Revisalo.';
}

/**
 * La suma de los renglones contra el total impreso.
 *
 * Se prueba contra CADA total que traiga el comprobante y también contra el
 * neto más IVA: los renglones suelen estar sin IVA y el total con, así que
 * exigir que dé exacto contra el único número grande del papel marcaría como
 * error algo que está bien. Si cierra contra alguno, cierra.
 */
function impCuadrar() {
    const caja = document.getElementById('impCuadre');
    const totales = (IMP.datos && IMP.datos.totales) || [];

    let suma = 0;
    IMP.items.forEach(it => {
        if (!it.incluir) return;
        suma += impNumero(it.cantidad) * impNumero(it.precio);
    });

    if (!totales.length) {
        // Sin total impreso no hay contra qué comparar, y decirlo es más honesto
        // que mostrar un tilde verde que no verificó nada.
        caja.hidden = false;
        caja.className = 'imp-cuadre sin-total';
        caja.textContent = '';
        const s = document.createElement('span');
        s.textContent = 'Los renglones suman ' + impPlata(suma, IMP.moneda) + '.';
        const t = document.createElement('small');
        t.textContent = ' El comprobante no trae un total impreso, así que no puedo verificar la suma: la revisión queda en vos.';
        caja.appendChild(s); caja.appendChild(t);
        return;
    }

    // Tolerancia: un peso, o el 0,5% para comprobantes grandes. Los centavos se
    // van en los redondeos de cada renglón y no son un error.
    let mejor = null;
    totales.forEach(tt => {
        [['', 1], [' + IVA 21%', 1.21], [' + IVA 10,5%', 1.105]].forEach(([nota, factor]) => {
            const esperado = tt.valor;
            const dif = Math.abs(suma * factor - esperado);
            if (mejor === null || dif < mejor.dif) {
                mejor = {dif: dif, etiqueta: tt.etiqueta, valor: esperado, nota: nota, factor: factor};
            }
        });
    });

    const tolerancia = Math.max(1, mejor.valor * 0.005);
    const cierra = mejor.dif <= tolerancia;

    caja.hidden = false;
    caja.className = 'imp-cuadre ' + (cierra ? 'cierra' : 'no-cierra');
    caja.textContent = '';

    const b = document.createElement('b');
    const detalle = document.createElement('small');

    if (cierra) {
        b.textContent = '✓ Cierra con el ' + mejor.etiqueta.toLowerCase() + ' del comprobante.';
        detalle.textContent = 'Los renglones suman ' + impPlata(suma, IMP.moneda) + mejor.nota +
            ' contra ' + impPlata(mejor.valor, IMP.moneda) + ' impresos.';
    } else {
        b.textContent = '⚠ No cierra: falta ' +
            impPlata(Math.abs(mejor.valor - suma * mejor.factor), IMP.moneda) + '.';
        detalle.textContent = 'Los renglones suman ' + impPlata(suma, IMP.moneda) +
            ' y el ' + mejor.etiqueta.toLowerCase() + ' del comprobante dice ' +
            impPlata(mejor.valor, IMP.moneda) + '. ' +
            'Puede faltar un renglón, sobrar uno, o haber un número mal leído. ' +
            'Podés cargarlo igual, pero conviene mirar antes.';
    }
    caja.appendChild(b);
    caja.appendChild(detalle);
}

function impFila(it) {
    const tr = document.createElement('tr');
    tr._campos = {};
    /* La etiqueta va en el propio td. En el teléfono la tabla se convierte en
       cartas —una por fila, con los campos uno abajo del otro— y ahí no hay
       encabezado de columna que mire: cada campo tiene que decir qué es. La CSS
       lo saca de data-label. */
    const celda = (clase, etiqueta) => {
        const td = document.createElement('td');
        if (clase) td.className = clase;
        if (etiqueta) td.setAttribute('data-label', etiqueta);
        tr.appendChild(td);
        return td;
    };

    // Incluir
    const chk = document.createElement('input');
    chk.type = 'checkbox';
    chk.checked = it.incluir;
    chk.setAttribute('aria-label', 'Incluir esta fila');
    chk.addEventListener('change', () => {
        it.incluir = chk.checked;
        tr.classList.toggle('excluida', !chk.checked);
        impContar();
        impCuadrar();   // sacar una fila cambia la suma
    });
    celda('', 'Incluir').appendChild(chk);

    // Nombre (+ referencia del comprobante, si la había)
    const cNombre = celda('imp-col-nombre', 'Insumo');
    tr._campos.nombre = impCrearInput('text', it.nombre, v => { it.nombre = v; }, { maxLength: 150 });
    cNombre.appendChild(tr._campos.nombre);
    if (it.referencia) {
        const ref = document.createElement('span');
        ref.className = 'imp-ref';
        ref.textContent = 'ref. ' + it.referencia;
        cNombre.appendChild(ref);
    }

    // Crear nuevo vs. sumar stock a uno existente
    const cAccion = celda('imp-col-accion', 'Qué hago');
    const sel = document.createElement('select');
    sel.add(new Option('➕ Crear nuevo', 'nuevo'));
    if (it.match_id) sel.add(new Option('📈 Sumar a: ' + it.match_nombre, String(it.match_id)));
    sel.value = (it.modo === 'sumar' && it.insumo_id) ? String(it.insumo_id) : 'nuevo';

    // El inventario completo se agrega recién cuando alguien abre el selector:
    // con cientos de insumos, clonarlo en cada fila haría pesada la tabla.
    let completo = false;
    sel.addEventListener('focus', () => {
        if (completo) return;
        completo = true;
        const puestos = {};
        Array.prototype.forEach.call(sel.options, o => { puestos[o.value] = true; });
        IMP.inventario.forEach(ins => {
            if (!puestos[String(ins.id)]) sel.add(new Option('📈 Sumar a: ' + ins.nombre, String(ins.id)));
        });
    });
    sel.addEventListener('change', () => {
        if (sel.value === 'nuevo') { it.modo = 'nuevo'; it.insumo_id = null; }
        else { it.modo = 'sumar'; it.insumo_id = parseInt(sel.value, 10); }
        impPintarModo(tr, it);
    });
    cAccion.appendChild(sel);

    // Tipo
    tr._campos.tipo = impCrearSelect(IMP_TIPOS, it.tipo || 'otro', v => { it.tipo = v; });
    celda('', 'Tipo').appendChild(tr._campos.tipo);

    // Cantidad. Cambiarla mueve la suma, así que se recalcula el cuadre.
    celda('imp-col-num', 'Cantidad').appendChild(
        impCrearInput('text', impTexto(it.cantidad), v => { it.cantidad = v; impCuadrar(); },
                      { inputMode: 'decimal', autocomplete: 'off' })
    );

    // Unidad
    tr._campos.unidad = impCrearSelect(IMP_UNIDADES, it.unidad_medida || 'kg', v => { it.unidad_medida = v; });
    celda('', 'Unidad').appendChild(tr._campos.unidad);

    // Precio, con el aviso cuando se despega del que ya le conocíamos al insumo.
    const cPrecio = celda('imp-col-num', 'Precio');
    const avisoPrecio = document.createElement('span');
    avisoPrecio.className = 'imp-alerta-precio';
    const inputPrecio = impCrearInput('text', impTexto(it.precio), v => {
        it.precio = v;
        repintarAviso();
        impCuadrar();
    }, { inputMode: 'decimal', autocomplete: 'off' });

    function repintarAviso() {
        const texto = impAlertaPrecio(it);
        avisoPrecio.textContent = texto || '';
        inputPrecio.classList.toggle('sospechoso', !!texto);
    }
    repintarAviso();

    cPrecio.appendChild(inputPrecio);
    cPrecio.appendChild(avisoPrecio);

    // Vencimiento
    celda('imp-col-fecha', 'Vence').appendChild(
        impCrearInput('date', it.vencimiento || '', v => { it.vencimiento = v; })
    );

    impPintarModo(tr, it);
    return tr;
}

function impMarcarTodos(valor) {
    IMP.items.forEach(it => { it.incluir = valor; });
    impRenderTabla();
}

function impAplicarDeposito(valor) {
    IMP.items.forEach(it => { it.deposito_id = valor; });
}

function impContar() {
    const marcadas = IMP.items.filter(it => it.incluir).length;
    document.getElementById('impContador').textContent =
        marcadas + ' de ' + IMP.items.length + (marcadas === 1 ? ' fila seleccionada' : ' filas seleccionadas');
}

// ── Confirmación ─────────────────────────────────────────────────────────────
function impConfirmar() {
    const dep = document.getElementById('impDepGlobal').value;

    const payload = IMP.items.filter(it => it.incluir).map(it => ({
        modo: it.modo,
        insumo_id: it.insumo_id || null,
        nombre: (it.nombre || '').trim(),
        tipo: it.tipo || 'otro',
        unidad_medida: it.unidad_medida || 'kg',
        unidad_stock: it.unidad_stock || '',
        cantidad: impNumero(it.cantidad),
        precio: impNumero(it.precio),
        vencimiento: it.vencimiento || '',
        deposito_id: it.deposito_id || dep || '',
    }));

    if (!payload.length) {
        alert('No hay ninguna fila seleccionada.');
        return;
    }
    const sinNombre = payload.some(p => p.modo === 'nuevo' && !p.nombre);
    if (sinNombre) {
        alert('Hay filas nuevas sin nombre. Completalas o destildalas.');
        return;
    }

    /* Sin cotización no se puede pasar un precio en pesos a la moneda del
       catálogo, y guardarlo igual sería repetir el error que esto viene a
       arreglar. Se avisa acá y no después de guardar. */
    if (IMP.moneda === 'ARS' && !(IMP.cotizacion > 0) && payload.some(p => p.precio > 0)) {
        alert('No tenés ninguna cotización del dólar cargada, y el catálogo guarda los precios '
            + 'en dólares. Cargá el tipo de cambio, o poné los precios en cero y completalos después.');
        return;
    }

    document.getElementById('impItemsJson').value = JSON.stringify(payload);
    document.getElementById('impMonedaJson').value = IMP.moneda;
    document.getElementById('impForm').submit();
}
</script>

<?php require_once 'includes/chat_motor.php'; ?>
<?php require_once 'includes/footer.php'; ?>
