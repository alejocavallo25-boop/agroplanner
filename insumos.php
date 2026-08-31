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
</style>

<!-- ===== MODAL: Importar (subida + análisis) ===== -->
<div id="importModal" class="modal-wrapper">
    <div class="glass-panel modal-panel">

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
                <span>Excel (.xlsx) · CSV · PDF digital — hasta 8 MB</span>
            </div>
            <input type="file" id="impInput" aria-label="Elegir el archivo a importar" accept=".xlsx,.xlsm,.csv,.txt,.tsv,.pdf" hidden>

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

            <div class="imp-nota">
                <strong>Una foto de un remito en papel no se puede leer acá.</strong>
                Una foto son píxeles: para convertirlos en texto haría falta OCR, que este importador no incluye.
                Sirven el Excel, el CSV y el PDF que genera un sistema. Si sólo tenés el papel, usá “pegar la tabla”.
            </div>

            <div id="impError" class="imp-error" hidden></div>

            <div class="imp-acciones">
                <button type="button" class="btn" onclick="impCerrar()" style="background:rgba(255,255,255,0.1); color:var(--text-primary);">Cancelar</button>
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
    impPaso('origen');
    impMostrarError('');
    document.getElementById('impInput').value = '';
    document.getElementById('impTexto').value = '';
    document.getElementById('importModal').style.display = 'block';
    document.body.classList.add('modal-open');
}

function impCerrar() {
    if (IMP.animacion) IMP.animacion.cancelar();
    document.getElementById('importModal').style.display = 'none';
    document.getElementById('importConfirmModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function impVolver() {
    document.getElementById('importConfirmModal').style.display = 'none';
    impAbrir();
}

function impPaso(cual) {
    document.getElementById('impPasoOrigen').hidden   = (cual !== 'origen');
    document.getElementById('impPasoAnalisis').hidden = (cual !== 'analisis');
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
})();

// ── Envío y animación ────────────────────────────────────────────────────────
function impAnalizarArchivo(archivo) {
    const datos = new FormData();
    datos.append('origen', 'archivo');
    datos.append('archivo', archivo);
    impEnviar(datos, resp => impMostrarResultado(resp));
}

function impAnalizarTexto() {
    const texto = document.getElementById('impTexto').value;
    if (!texto.trim()) { impMostrarError('Pegá algún texto antes de analizar.'); return; }
    const datos = new FormData();
    datos.append('origen', 'texto');
    datos.append('texto', texto);
    impEnviar(datos, resp => impMostrarResultado(resp));
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
    const celda = clase => {
        const td = document.createElement('td');
        if (clase) td.className = clase;
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
    celda().appendChild(chk);

    // Nombre (+ referencia del comprobante, si la había)
    const cNombre = celda('imp-col-nombre');
    tr._campos.nombre = impCrearInput('text', it.nombre, v => { it.nombre = v; }, { maxLength: 150 });
    cNombre.appendChild(tr._campos.nombre);
    if (it.referencia) {
        const ref = document.createElement('span');
        ref.className = 'imp-ref';
        ref.textContent = 'ref. ' + it.referencia;
        cNombre.appendChild(ref);
    }

    // Crear nuevo vs. sumar stock a uno existente
    const cAccion = celda('imp-col-accion');
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
    celda().appendChild(tr._campos.tipo);

    // Cantidad. Cambiarla mueve la suma, así que se recalcula el cuadre.
    celda('imp-col-num').appendChild(
        impCrearInput('text', impTexto(it.cantidad), v => { it.cantidad = v; impCuadrar(); },
                      { inputMode: 'decimal', autocomplete: 'off' })
    );

    // Unidad
    tr._campos.unidad = impCrearSelect(IMP_UNIDADES, it.unidad_medida || 'kg', v => { it.unidad_medida = v; });
    celda().appendChild(tr._campos.unidad);

    // Precio, con el aviso cuando se despega del que ya le conocíamos al insumo.
    const cPrecio = celda('imp-col-num');
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
    celda('imp-col-fecha').appendChild(
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
