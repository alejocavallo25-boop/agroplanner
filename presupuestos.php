<?php
/* presupuestos.php — Pedidos de presupuesto de insumos.
 *
 *   (sin parámetros)   la lista de pedidos y el botón para armar uno
 *   ?nuevo=1           armar un pedido · ?editar=ID  cambiarlo (sólo sin ofertas)
 *   ?id=ID             el pedido: bajar el Excel, subir respuestas, comparar
 *   ?id=ID&excel=1     el .xlsx para mandar (con &para=Nombre, ya con el proveedor puesto)
 *
 * Las cuentas y el Excel: includes/presupuestos.php.
 */
require_once 'config/auth.php';
require_once 'config/database.php';
require_once 'includes/presupuestos.php';

validate_csrf();
$usuario_id = (int)$_SESSION['usuario_id'];
$listas = pp_tablas_listas($pdo);

// pp_pedido, pp_items, pp_ofertas, pp_precios y pp_guardar_oferta viven en includes/presupuestos.php:
// los comparte con el chat (api/presupuesto_chat.php).
$texto = fn($v, $max) => mb_substr(trim((string)$v), 0, $max) ?: null;

$id = (int)($_GET['id'] ?? 0);
$pedido = ($listas && $id) ? pp_pedido($pdo, $usuario_id, $id) : null;
if ($id && $listas && !$pedido) { header('Location: presupuestos.php'); exit; }

// ─── El Excel para mandar ──────────────────────────────────────────────────
if ($pedido && isset($_GET['excel'])) {
    require_once 'includes/asesor.php';
    $firma = firma_informe($pdo);
    $bytes = pp_xlsx($pedido, pp_items($pdo, $pedido['id']), (string)$_SESSION['username'],
                     mb_substr(trim((string)($_GET['para'] ?? '')), 0, 150), $firma ? firma_texto($firma) : '');
    $nombre = 'Pedido de presupuesto ' . pp_codigo((int)$pedido['id'])
            . (trim((string)($_GET['para'] ?? '')) !== '' ? ' - ' . preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string)$_GET['para']) : '') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nombre) . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

$errores = [];
$previo = null;

if ($listas && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['action'] ?? '';

    // ── Crear o cambiar el pedido ───────────────────────────────────────────
    if ($accion === 'guardar_pedido') {
        $st = $pdo->prepare("SELECT id FROM insumos WHERE usuario_id = ?");
        $st->execute([$usuario_id]);
        $propios = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
        [$ped, $items, $err] = pp_normalizar_pedido([
            'titulo' => $_POST['titulo'] ?? '', 'moneda' => $_POST['moneda'] ?? '',
            'fecha_limite' => $_POST['fecha_limite'] ?? '', 'notas' => $_POST['notas'] ?? '',
            'items' => json_decode((string)($_POST['items'] ?? '[]'), true) ?: [],
        ], $propios);
        $editar = (int)($_POST['id'] ?? 0);
        if ($editar) {
            $existente = pp_pedido($pdo, $usuario_id, $editar);
            if (!$existente) $err[] = 'Ese pedido ya no existe.';
            elseif (pp_ofertas($pdo, $editar)) $err[] = 'El pedido ya tiene ofertas cargadas: los renglones no se pueden cambiar.';
        }
        if (!$err) {
            $pdo->beginTransaction();
            try {
                if ($editar) {
                    $pdo->prepare("UPDATE presupuesto_pedidos SET titulo = ?, moneda = ?, fecha_limite = ?, notas = ? WHERE id = ? AND usuario_id = ?")
                        ->execute([$ped['titulo'], $ped['moneda'], $ped['fecha_limite'], $ped['notas'], $editar, $usuario_id]);
                    $pdo->prepare("DELETE FROM presupuesto_items WHERE pedido_id = ?")->execute([$editar]);
                    $nuevo = $editar;
                } else {
                    $pdo->prepare("INSERT INTO presupuesto_pedidos (usuario_id, titulo, moneda, fecha_limite, notas) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$usuario_id, $ped['titulo'], $ped['moneda'], $ped['fecha_limite'], $ped['notas']]);
                    $nuevo = (int)$pdo->lastInsertId();
                }
                $ins = $pdo->prepare("INSERT INTO presupuesto_items (pedido_id, orden, insumo_id, descripcion, cantidad, unidad) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($items as $it) $ins->execute([$nuevo, $it['orden'], $it['insumo_id'], $it['descripcion'], $it['cantidad'], $it['unidad']]);
                $pdo->commit();
                set_flash('success', 'Pedido ' . pp_codigo($nuevo) . ' guardado. Bajá el Excel y mandáselo a tus proveedores.');
                header('Location: presupuestos.php?id=' . $nuevo);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[presupuestos] ' . $e->getMessage());
                $err[] = 'No se pudo guardar el pedido.';
            }
        }
        $errores = $err;
        $previo = $_POST;
    }

    if ($accion === 'borrar_pedido' && ($p = pp_pedido($pdo, $usuario_id, (int)$_POST['id']))) {
        $pdo->prepare("DELETE FROM presupuesto_pedidos WHERE id = ? AND usuario_id = ?")->execute([$p['id'], $usuario_id]);
        set_flash('success', 'Pedido ' . pp_codigo((int)$p['id']) . ' borrado.');
        header('Location: presupuestos.php');
        exit;
    }

    // ── Subir las respuestas de los proveedores ─────────────────────────────
    if ($accion === 'subir_respuestas' && $pedido) {
        $archivos = $_FILES['archivos'] ?? null;
        $leidos = []; $fallas = [];
        $n = $archivos ? count((array)$archivos['name']) : 0;
        for ($i = 0; $i < $n; $i++) {
            $nombre = basename((string)$archivos['name'][$i]);
            if ($archivos['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            if ($archivos['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($archivos['tmp_name'][$i])) { $fallas[] = "$nombre: no se pudo subir."; continue; }
            if ($archivos['size'][$i] > 8 * 1024 * 1024) { $fallas[] = "$nombre: pesa más de 8 MB."; continue; }
            try {
                // La misma lectura que usa el chat (includes/presupuestos.php).
                $r = pp_cargar_respuesta($pdo, $usuario_id, $archivos['tmp_name'][$i], $nombre, $pedido);
                $leidos[] = $r['proveedor'] . ' (' . $r['cotizados'] . ' de ' . $r['renglones'] . ' renglones' . ($r['reemplazo'] ? ', reemplazó la anterior' : '') . ')';
            } catch (RuntimeException $e) {
                $fallas[] = "$nombre: " . $e->getMessage();
            } catch (Throwable $e) {
                error_log('[presupuestos] ' . $e->getMessage());
                $fallas[] = "$nombre: no se pudo leer.";
            }
        }
        if ($leidos) set_flash('success', 'Leí ' . implode('; ', $leidos) . '.' . ($fallas ? ' No pude con: ' . implode(' ', $fallas) : ''));
        else set_flash('error', $fallas ? 'No pude leer ninguna respuesta. ' . implode(' ', $fallas) : 'No elegiste ningún archivo.');
        header('Location: presupuestos.php?id=' . $pedido['id']);
        exit;
    }

    // ── Una oferta cargada a mano (WhatsApp, teléfono, PDF) ─────────────────
    if ($accion === 'guardar_oferta' && $pedido) {
        $items = pp_items($pdo, $pedido['id']);
        $proveedor = mb_substr(trim((string)($_POST['proveedor'] ?? '')), 0, 150);
        $precios = [];
        foreach ($items as $it) {
            $v = imp_a_numero((string)($_POST['precio'][$it['id']] ?? ''));
            if ($v !== null && $v > 0) {
                $precios[$it['id']] = ['precio' => round($v, 4), 'marca' => $texto($_POST['marca'][$it['id']] ?? '', 150), 'observacion' => null];
            }
        }
        $editar = (int)($_POST['oferta_id'] ?? 0);
        if ($editar && !in_array($editar, array_map('intval', array_column(pp_ofertas($pdo, $pedido['id']), 'id')), true)) $editar = 0;
        if ($proveedor === '') $errores[] = 'Poné el nombre del proveedor.';
        if (!$precios) $errores[] = 'Cargá al menos un precio.';
        if (!$errores) {
            $pdo->beginTransaction();
            pp_guardar_oferta($pdo, (int)$pedido['id'], [
                'proveedor' => $proveedor, 'moneda' => ($_POST['moneda'] ?? '') === 'ARS' ? 'ARS' : 'USD',
                'condicion_pago' => $texto($_POST['condicion_pago'] ?? '', 150), 'plazo_entrega' => $texto($_POST['plazo_entrega'] ?? '', 100),
                'validez' => $texto($_POST['validez'] ?? '', 100), 'origen' => 'manual', 'archivo' => null,
            ], $precios, $editar ?: null);
            $pdo->commit();
            set_flash('success', 'Oferta de ' . $proveedor . ' guardada.');
            header('Location: presupuestos.php?id=' . $pedido['id']);
            exit;
        }
        $previo = $_POST;
    }

    if ($accion === 'borrar_oferta' && $pedido) {
        $pdo->prepare("DELETE FROM presupuesto_ofertas WHERE id = ? AND pedido_id = ?")->execute([(int)$_POST['oferta_id'], $pedido['id']]);
        set_flash('success', 'Oferta borrada.');
        header('Location: presupuestos.php?id=' . $pedido['id']);
        exit;
    }
}

// ─── Qué se muestra ─────────────────────────────────────────────────────────
$editar_id = (int)($_GET['editar'] ?? 0);
$armando = $listas && (isset($_GET['nuevo']) || $editar_id || ($previo && ($previo['action'] ?? '') === 'guardar_pedido'));
$ped_editar = $editar_id ? pp_pedido($pdo, $usuario_id, $editar_id) : null;
if ($editar_id && (!$ped_editar || pp_ofertas($pdo, $editar_id))) { header('Location: presupuestos.php' . ($ped_editar ? '?id=' . $editar_id : '')); exit; }

$st = $pdo->prepare("SELECT id, nombre, unidad_medida, unidad_stock, stock_actual, stock_minimo FROM insumos WHERE usuario_id = ? AND estado = 'activo' ORDER BY nombre");
$st->execute([$usuario_id]);
$catalogo = $st->fetchAll(PDO::FETCH_ASSOC);

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$num = fn($n, $d = 2) => number_format((float)$n, $d, ',', '.');
$plata = fn($n, $mon) => ($mon === 'USD' ? 'US$ ' : '$ ') . number_format((float)$n, 2, ',', '.');
$cant = fn($n) => rtrim(rtrim(number_format((float)$n, 3, ',', '.'), '0'), ',');
$J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE;

$page_title = 'Pedidos de presupuesto';
require_once 'includes/header.php';
?>

<?php if (!$listas): ?>
<div class="glass-panel">
    <h3 style="margin-bottom:8px;">Falta actualizar la base</h3>
    <p style="color: var(--text-muted);">Los pedidos de presupuesto necesitan las tablas nuevas. Corré <code>migrations/esquema_completo.sql</code> en phpMyAdmin y volvé a entrar.</p>
</div>
<?php else: ?>

<?php foreach ($errores as $e): ?>
<div class="pp-error" role="alert"><i class="fas fa-exclamation-circle"></i> <?= $h($e) ?></div>
<?php endforeach; ?>

<?php if ($armando):
    /* ════════════ ARMAR O CAMBIAR UN PEDIDO ════════════ */
    $base = $previo ?: ($ped_editar ?: ['titulo' => '', 'moneda' => 'USD', 'fecha_limite' => '', 'notas' => '']);
    $items_ini = $previo ? (json_decode((string)($previo['items'] ?? '[]'), true) ?: [])
                         : ($ped_editar ? array_map(fn($i) => ['insumo_id' => $i['insumo_id'], 'descripcion' => $i['descripcion'],
                              'cantidad' => $cant($i['cantidad']), 'unidad' => $i['unidad']], pp_items($pdo, $ped_editar['id'])) : []);
?>
<form method="POST" class="glass-panel pp-panel" onsubmit="return ppEnviarPedido()">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="guardar_pedido">
    <input type="hidden" name="id" value="<?= (int)($ped_editar['id'] ?? ($previo['id'] ?? 0)) ?>">
    <input type="hidden" name="items" id="ppItemsJson">
    <div class="pp-cabecera">
        <div>
            <a href="presupuestos.php" class="pp-volver"><i class="fas fa-arrow-left"></i> Pedidos</a>
            <h2 class="pp-h2" style="margin-top:6px;"><?= $ped_editar ? 'Cambiar pedido ' . pp_codigo((int)$ped_editar['id']) : 'Nuevo pedido de presupuesto' ?></h2>
            <p class="pp-ayuda">Armá la lista una vez; después bajás el mismo Excel para cada proveedor.</p>
        </div>
    </div>
    <div class="pp-grid">
        <div class="pp-campo" style="grid-column: span 2;"><label for="ppTitulo">Nombre del pedido</label><input type="text" name="titulo" id="ppTitulo" maxlength="150" required placeholder="Ej: Herbicidas campaña 26/27" value="<?= $h($base['titulo']) ?>"></div>
        <div class="pp-campo"><label for="ppMoneda">Pedir precios en</label>
            <select name="moneda" id="ppMoneda"><option value="USD" <?= $base['moneda'] === 'USD' ? 'selected' : '' ?>>US$ dólares</option><option value="ARS" <?= $base['moneda'] === 'ARS' ? 'selected' : '' ?>>$ pesos</option></select></div>
        <div class="pp-campo"><label for="ppLimite">Responder antes del <small class="pp-muted">(opcional)</small></label><input type="date" name="fecha_limite" id="ppLimite" value="<?= $h($base['fecha_limite']) ?>"></div>
        <div class="pp-campo" style="grid-column: 1 / -1;"><label for="ppNotas">Aclaraciones para el proveedor <small class="pp-muted">(opcional)</small></label><input type="text" name="notas" id="ppNotas" maxlength="500" placeholder="Ej: entrega en el campo, Ruta 9 km 512" value="<?= $h($base['notas']) ?>"></div>
    </div>

    <div class="pp-bloque">
        <div class="pp-cabecera">
            <div class="pp-bloque-tit">Insumos a cotizar</div>
            <?php if (array_filter($catalogo, fn($c) => $c['stock_minimo'] !== null && (float)$c['stock_actual'] <= (float)$c['stock_minimo'])): ?>
            <button type="button" class="btn pp-btn-sec" onclick="ppStockBajo()"><i class="fas fa-triangle-exclamation"></i> Agregar los que están en stock bajo</button>
            <?php endif; ?>
        </div>
        <div class="table-container">
            <table class="pp-tabla-items">
                <thead><tr><th>Insumo</th><th style="width:140px">Cantidad</th><th style="width:120px">Unidad</th><th style="width:44px"></th></tr></thead>
                <tbody id="ppFilas"></tbody>
            </table>
        </div>
        <button type="button" class="btn pp-btn-sec" onclick="ppAgregar()"><i class="fas fa-plus"></i> Agregar renglón</button>
    </div>
    <div class="pp-fila" style="justify-content:flex-end;">
        <a href="<?= $ped_editar ? 'presupuestos.php?id=' . (int)$ped_editar['id'] : 'presupuestos.php' ?>" class="btn pp-btn-sec">Cancelar</a>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Guardar pedido</button>
    </div>
</form>

<script>
const PP_CATALOGO = <?= json_encode($catalogo, $J) ?>;
let ppFilas = <?= json_encode(array_values($items_ini), $J) ?>;
const ppEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function ppPintar() {
    if (!ppFilas.length) ppFilas.push({ insumo_id: null, descripcion: '', cantidad: '', unidad: '' });
    document.getElementById('ppFilas').innerHTML = ppFilas.map((f, i) => `<tr>
        <td data-label="Insumo">
            <select onchange="ppElegir(${i}, this.value)" aria-label="Insumo del stock">
                <option value="">Escribir a mano…</option>
                ${PP_CATALOGO.map(c => `<option value="${c.id}" ${c.id == f.insumo_id ? 'selected' : ''}>${ppEsc(c.nombre)}</option>`).join('')}
            </select>
            <input type="text" value="${ppEsc(f.descripcion)}" maxlength="200" placeholder="Descripción (ej: Glifosato 62% x 20 lt)" oninput="ppFilas[${i}].descripcion = this.value" aria-label="Descripción" style="margin-top:6px;">
        </td>
        <td data-label="Cantidad"><input type="text" inputmode="decimal" value="${ppEsc(f.cantidad)}" placeholder="0" oninput="ppFilas[${i}].cantidad = this.value" aria-label="Cantidad"></td>
        <td data-label="Unidad"><input type="text" value="${ppEsc(f.unidad)}" maxlength="30" placeholder="lt, kg, bolsa" oninput="ppFilas[${i}].unidad = this.value" aria-label="Unidad"></td>
        <td><button type="button" class="pp-quitar" onclick="ppFilas.splice(${i}, 1); ppPintar();" title="Quitar" aria-label="Quitar renglón"><i class="fas fa-xmark"></i></button></td>
    </tr>`).join('');
}
function ppElegir(i, id) {
    const c = PP_CATALOGO.find(x => x.id == id);
    ppFilas[i].insumo_id = c ? parseInt(id) : null;
    if (c) { ppFilas[i].descripcion = c.nombre; ppFilas[i].unidad = c.unidad_stock || c.unidad_medida || ''; }
    ppPintar();
}
function ppAgregar() { ppFilas.push({ insumo_id: null, descripcion: '', cantidad: '', unidad: '' }); ppPintar(); }
/* Lo que falta para llegar al mínimo, como punto de partida: se corrige a gusto. */
function ppStockBajo() {
    ppFilas = ppFilas.filter(f => f.descripcion.trim());
    PP_CATALOGO.filter(c => c.stock_minimo !== null && parseFloat(c.stock_actual) <= parseFloat(c.stock_minimo)).forEach(c => {
        if (ppFilas.some(f => f.insumo_id == c.id)) return;
        const falta = Math.max(0, parseFloat(c.stock_minimo) - parseFloat(c.stock_actual || 0));
        ppFilas.push({ insumo_id: parseInt(c.id), descripcion: c.nombre, cantidad: falta > 0 ? String(Math.ceil(falta)) : '', unidad: c.unidad_stock || c.unidad_medida || '' });
    });
    ppPintar();
}
function ppEnviarPedido() {
    document.getElementById('ppItemsJson').value = JSON.stringify(ppFilas.filter(f => (f.descripcion || '').trim()));
    return true;
}
ppPintar();
</script>

<?php elseif (!$pedido):
    /* ════════════ LISTA DE PEDIDOS ════════════ */
    $st = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM presupuesto_items i WHERE i.pedido_id = p.id) AS renglones,
                                (SELECT COUNT(*) FROM presupuesto_ofertas o WHERE o.pedido_id = p.id) AS ofertas
                           FROM presupuesto_pedidos p WHERE p.usuario_id = ? ORDER BY p.creado_en DESC, p.id DESC");
    $st->execute([$usuario_id]);
    $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="glass-panel pp-panel">
    <div class="pp-cabecera">
        <div>
            <h2 class="pp-h2">Pedidos de presupuesto</h2>
            <p class="pp-ayuda">Armás la lista, mandás el Excel a cada proveedor, subís lo que te devuelven y la app compara las ofertas.</p>
        </div>
        <a href="presupuestos.php?nuevo=1" class="btn btn-primary"><i class="fas fa-plus"></i> Nuevo pedido</a>
    </div>
    <?php if (!$pedidos): ?>
        <p class="pp-muted" style="margin:0;">Todavía no armaste ningún pedido.</p>
    <?php else: ?>
    <div class="table-container">
        <table>
            <thead><tr><th>Pedido</th><th>Renglones</th><th>Ofertas</th><th>Responder antes del</th></tr></thead>
            <tbody>
            <?php foreach ($pedidos as $p): ?>
                <tr>
                    <td data-label="Pedido"><a href="presupuestos.php?id=<?= (int)$p['id'] ?>" class="pp-link"><?= $h($p['titulo']) ?></a><br><small class="pp-muted"><?= pp_codigo((int)$p['id']) ?> · <?= date('d/m/Y', strtotime($p['creado_en'])) ?></small></td>
                    <td data-label="Renglones"><?= (int)$p['renglones'] ?></td>
                    <td data-label="Ofertas"><?= (int)$p['ofertas'] ?: '<span class="pp-muted">ninguna todavía</span>' ?></td>
                    <td data-label="Responder antes del"><?= $p['fecha_limite'] ? date('d/m/Y', strtotime($p['fecha_limite'])) : '<span class="pp-muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else:
    /* ════════════ UN PEDIDO ════════════ */
    $items = pp_items($pdo, $pedido['id']);
    $ofertas = pp_ofertas($pdo, $pedido['id']);
    $precios = pp_precios($pdo, $ofertas);
    require_once 'includes/dolar.php';
    dolar_asegurar_tabla($pdo);
    $ref = dolar_referencia($pdo, $usuario_id);
    $cmp = $ofertas ? pp_comparar($items, $ofertas, $precios, (float)$ref['valor']) : null;
    $nombre_oferta = array_column($ofertas, 'proveedor', 'id');
    // Nombres para sugerir: los proveedores de la cuenta corriente y los que ya ofertaron.
    require_once 'includes/cuenta_corriente.php';
    $sugeridos = cc_tablas_listas($pdo) ? array_column(cc_proveedores($pdo, $usuario_id), 'nombre') : [];
    $sugeridos = array_values(array_unique(array_merge($sugeridos, array_column($ofertas, 'proveedor'))));
?>
<div class="glass-panel pp-panel">
    <div class="pp-cabecera">
        <div>
            <a href="presupuestos.php" class="pp-volver"><i class="fas fa-arrow-left"></i> Pedidos</a>
            <h2 class="pp-h2" style="margin-top:6px;"><?= $h($pedido['titulo']) ?></h2>
            <p class="pp-ayuda"><?= pp_codigo((int)$pedido['id']) ?> · precios en <?= $pedido['moneda'] === 'USD' ? 'dólares' : 'pesos' ?><?= $pedido['fecha_limite'] ? ' · responder antes del ' . date('d/m/Y', strtotime($pedido['fecha_limite'])) : '' ?><?= $pedido['notas'] ? ' · ' . $h($pedido['notas']) : '' ?></p>
        </div>
        <div class="pp-fila">
            <?php if (!$ofertas): ?><a href="presupuestos.php?editar=<?= (int)$pedido['id'] ?>" class="btn pp-btn-sec"><i class="fas fa-pen"></i> Cambiar</a><?php endif; ?>
            <form method="POST" onsubmit="return confirm('¿Borrar el pedido con todas sus ofertas?');" style="margin:0;">
                <?php csrf_field(); ?><input type="hidden" name="action" value="borrar_pedido"><input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>">
                <button type="submit" class="btn pp-btn-sec pp-rojo"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>

    <div class="pp-pasos">
        <div class="pp-paso">
            <div class="pp-paso-n">1</div>
            <div class="pp-paso-cuerpo">
                <b>Mandá el Excel</b>
                <p class="pp-muted">El mismo archivo para todos. Si ponés el nombre del proveedor, sale ya completado.</p>
                <form method="GET" class="pp-fila" style="margin:0;">
                    <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>"><input type="hidden" name="excel" value="1">
                    <input type="text" name="para" maxlength="150" placeholder="Proveedor (opcional)" list="ppSugeridos" class="pp-input-chico" aria-label="Proveedor para el Excel">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-file-excel"></i> Bajar Excel</button>
                </form>
            </div>
        </div>
        <div class="pp-paso">
            <div class="pp-paso-n">2</div>
            <div class="pp-paso-cuerpo">
                <b>Subí lo que te devolvieron</b>
                <p class="pp-muted">Podés elegir varios archivos juntos. Si un proveedor manda otro igual, reemplaza al anterior.</p>
                <form method="POST" enctype="multipart/form-data" class="pp-fila" style="margin:0;">
                    <?php csrf_field(); ?><input type="hidden" name="action" value="subir_respuestas">
                    <input type="file" name="archivos[]" multiple accept=".xlsx,.xlsm,.csv" required aria-label="Archivos de los proveedores" class="pp-input-chico">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Subir</button>
                </form>
                <button type="button" class="pp-mini-btn" onclick="ppAbrirOferta()"><i class="fas fa-keyboard"></i> Me pasó los precios por WhatsApp o en PDF: cargarlos a mano</button>
            </div>
        </div>
    </div>
    <datalist id="ppSugeridos"><?php foreach ($sugeridos as $s): ?><option value="<?= $h($s) ?>"><?php endforeach; ?></datalist>
</div>

<?php if (!$cmp): ?>
<div class="glass-panel pp-panel">
    <h2 class="pp-h2">Renglones del pedido</h2>
    <div class="table-container">
        <table>
            <thead><tr><th style="width:60px">Cód.</th><th>Insumo</th><th style="text-align:right">Cantidad</th><th>Unidad</th></tr></thead>
            <tbody><?php foreach ($items as $it): ?>
                <tr><td data-label="Cód."><?= (int)$it['orden'] ?></td><td data-label="Insumo"><?= $h($it['descripcion']) ?></td><td data-label="Cantidad" style="text-align:right"><?= $cant($it['cantidad']) ?></td><td data-label="Unidad"><?= $h($it['unidad'] ?: '—') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
    <p class="pp-muted" style="margin:0;">Cuando subas las respuestas, acá vas a ver la comparación.</p>
</div>
<?php else:
    $mon_cmp = $cmp['moneda_cmp'];
?>
<div class="glass-panel pp-panel">
    <h2 class="pp-h2">Comparación</h2>
    <?php if ($cmp['mezcla']): ?>
        <div class="pp-aviso"><i class="fas fa-circle-info"></i>
            <?= $cmp['puede_comparar']
                ? 'Hay ofertas en pesos y en dólares: para compararlas, las de pesos se pasan a dólares con el dólar de referencia ($ ' . $num($ref['valor']) . ($ref['estimado'] ? ', estimado' : '') . '). Cada oferta se guarda en la moneda en que vino.'
                : 'Hay ofertas en pesos y en dólares y no hay ninguna cotización del dólar cargada: cargala para poder compararlas.' ?>
        </div>
    <?php endif; ?>
    <?php if ($cmp['puede_comparar']): ?>
    <div class="pp-resumen">
        <?php if ($cmp['mejor_completa'] !== null): $t = $cmp['totales'][$cmp['mejor_completa']]; $o = array_column($ofertas, null, 'id')[$cmp['mejor_completa']]; ?>
        <div class="pp-kpi pp-kpi-bien">
            <div class="pp-kpi-tit">La oferta completa más barata</div>
            <div class="pp-kpi-val"><?= $h($o['proveedor']) ?></div>
            <div class="pp-kpi-det"><?= $plata($t['total'], $o['moneda']) ?><?= $cmp['mezcla'] && $o['moneda'] !== 'USD' ? ' · ≈ ' . $plata($t['total_cmp'], 'USD') : '' ?></div>
        </div>
        <?php else: ?>
        <div class="pp-kpi"><div class="pp-kpi-tit">Oferta completa</div><div class="pp-kpi-det">Ningún proveedor cotizó todos los renglones.</div></div>
        <?php endif; ?>
        <?php if ($cmp['combinacion'] !== null): ?>
        <div class="pp-kpi">
            <div class="pp-kpi-tit">Comprando cada insumo al más barato</div>
            <div class="pp-kpi-val"><?= $plata($cmp['combinacion'], $mon_cmp) ?></div>
            <div class="pp-kpi-det"><?= $cmp['ahorro_combinando'] > 0.005 ? 'Ahorrás ' . $plata($cmp['ahorro_combinando'], $mon_cmp) . ' contra la mejor oferta completa' : 'Igual que la mejor oferta completa' ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="table-container pp-comparacion">
        <table>
            <thead>
                <tr>
                    <th>Insumo</th>
                    <?php foreach ($ofertas as $o): ?>
                    <th class="pp-th-oferta">
                        <div class="pp-th-prov"><?= $h($o['proveedor']) ?></div>
                        <small class="pp-muted"><?= $o['moneda'] === 'USD' ? 'US$' : '$' ?><?= $o['condicion_pago'] ? ' · ' . $h($o['condicion_pago']) : '' ?><?= $o['plazo_entrega'] ? ' · ' . $h($o['plazo_entrega']) : '' ?><?= $o['validez'] ? ' · válida ' . $h($o['validez']) : '' ?></small>
                        <div class="pp-th-acc">
                            <button type="button" class="pp-icono" title="Corregir precios" aria-label="Corregir precios de <?= $h($o['proveedor']) ?>" onclick='ppAbrirOferta(<?= json_encode($o, $J) ?>, <?= json_encode($precios[$o['id']] ?? new stdClass(), $J) ?>)'><i class="fas fa-pen"></i></button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('¿Borrar la oferta de <?= $h($o['proveedor']) ?>?');">
                                <?php csrf_field(); ?><input type="hidden" name="action" value="borrar_oferta"><input type="hidden" name="oferta_id" value="<?= (int)$o['id'] ?>">
                                <button type="submit" class="pp-icono pp-rojo" title="Borrar oferta" aria-label="Borrar oferta de <?= $h($o['proveedor']) ?>"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cmp['filas'] as $f): $it = $f['item']; ?>
                <tr>
                    <td data-label="Insumo"><b><?= $h($it['descripcion']) ?></b><br><small class="pp-muted"><?= $cant($it['cantidad']) ?> <?= $h($it['unidad'] ?? '') ?></small></td>
                    <?php foreach ($ofertas as $o): $c = $f['celdas'][$o['id']]; ?>
                    <td data-label="<?= $h($o['proveedor']) ?>" class="pp-celda <?= $c && $c['mejor'] && count($ofertas) > 1 ? 'pp-mejor' : '' ?>">
                        <?php if (!$c): ?><span class="pp-muted">no cotizó</span>
                        <?php else: ?>
                            <b><?= $plata($c['precio'], $o['moneda']) ?></b><small class="pp-muted"> c/u</small>
                            <br><small><?= $plata($c['total'], $o['moneda']) ?></small>
                            <?php if ($c['marca']): ?><br><small class="pp-marca"><?= $h($c['marca']) ?></small><?php endif; ?>
                            <?php if ($c['observacion']): ?><br><small class="pp-muted"><?= $h($c['observacion']) ?></small><?php endif; ?>
                            <?php if ($c['mejor'] && count($ofertas) > 1): ?><br><span class="pp-etiqueta">Más barato</span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><b>Total</b></td>
                    <?php foreach ($ofertas as $o): $t = $cmp['totales'][$o['id']]; ?>
                    <td class="pp-celda <?= $cmp['mejor_completa'] === $o['id'] && count($ofertas) > 1 ? 'pp-mejor' : '' ?>">
                        <b><?= $plata($t['total'], $o['moneda']) ?></b>
                        <?php if ($cmp['mezcla'] && $o['moneda'] !== 'USD' && $cmp['puede_comparar']): ?><br><small class="pp-muted">≈ <?= $plata($t['total_cmp'], 'USD') ?></small><?php endif; ?>
                        <br><small class="<?= $t['cotizados'] < count($items) ? 'pp-rojo' : 'pp-muted' ?>">cotizó <?= $t['cotizados'] ?> de <?= count($items) ?></small>
                    </td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal: oferta cargada a mano -->
<div id="ppModalOferta" class="modal-wrapper" style="display:none;">
    <div class="glass-panel modal-panel" style="max-width: 640px;">
        <h3 id="ppOfertaTit" style="margin-bottom:12px;">Cargar precios a mano</h3>
        <form method="POST" style="display:flex; flex-direction:column; gap:12px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="guardar_oferta">
            <input type="hidden" name="oferta_id" id="ppOfId" value="">
            <div class="pp-grid2">
                <div><label for="ppOfProv">Proveedor</label><input type="text" name="proveedor" id="ppOfProv" maxlength="150" list="ppSugeridos" required></div>
                <div><label for="ppOfMon">Moneda</label><select name="moneda" id="ppOfMon"><option value="USD">US$ dólares</option><option value="ARS">$ pesos</option></select></div>
                <div><label for="ppOfCond">Condición de pago</label><input type="text" name="condicion_pago" id="ppOfCond" maxlength="150" placeholder="Ej: canje mayo"></div>
                <div><label for="ppOfPlazo">Plazo de entrega</label><input type="text" name="plazo_entrega" id="ppOfPlazo" maxlength="100" placeholder="Ej: 7 días"></div>
            </div>
            <div><label for="ppOfValidez">Validez</label><input type="text" name="validez" id="ppOfValidez" maxlength="100" placeholder="Ej: hasta el 30/11"></div>
            <div class="table-container" style="max-height: 46vh; overflow-y:auto;">
                <table>
                    <thead><tr><th>Insumo</th><th style="width:140px">Precio unitario</th><th style="width:170px">Marca <small>(opcional)</small></th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td data-label="Insumo"><?= $h($it['descripcion']) ?><br><small class="pp-muted"><?= $cant($it['cantidad']) ?> <?= $h($it['unidad'] ?? '') ?></small></td>
                            <td data-label="Precio unitario"><input type="text" inputmode="decimal" name="precio[<?= (int)$it['id'] ?>]" data-item="<?= (int)$it['id'] ?>" class="pp-of-precio" placeholder="no cotizó"></td>
                            <td data-label="Marca"><input type="text" name="marca[<?= (int)$it['id'] ?>]" data-item="<?= (int)$it['id'] ?>" class="pp-of-marca" maxlength="150"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn pp-btn-sec" onclick="document.getElementById('ppModalOferta').style.display='none'; document.body.classList.remove('modal-open');">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar oferta</button>
            </div>
        </form>
    </div>
</div>
<script>
function ppAbrirOferta(o, precios) {
    precios = precios || {};
    document.getElementById('ppOfertaTit').textContent = o ? 'Corregir la oferta de ' + o.proveedor : 'Cargar precios a mano';
    document.getElementById('ppOfId').value = o ? o.id : '';
    document.getElementById('ppOfProv').value = o ? o.proveedor : '';
    document.getElementById('ppOfMon').value = o ? o.moneda : <?= json_encode($pedido['moneda']) ?>;
    document.getElementById('ppOfCond').value = o ? (o.condicion_pago || '') : '';
    document.getElementById('ppOfPlazo').value = o ? (o.plazo_entrega || '') : '';
    document.getElementById('ppOfValidez').value = o ? (o.validez || '') : '';
    document.querySelectorAll('.pp-of-precio').forEach(i => { const p = precios[i.dataset.item]; i.value = p ? String(parseFloat(p.precio)).replace('.', ',') : ''; });
    document.querySelectorAll('.pp-of-marca').forEach(i => { const p = precios[i.dataset.item]; i.value = p ? (p.marca || '') : ''; });
    document.getElementById('ppModalOferta').style.display = 'flex';
    document.body.classList.add('modal-open');
}
<?php if ($previo && ($previo['action'] ?? '') === 'guardar_oferta'): ?>
ppAbrirOferta();
document.getElementById('ppOfProv').value = <?= json_encode((string)($previo['proveedor'] ?? ''), $J) ?>;
<?php foreach ((array)($previo['precio'] ?? []) as $k => $v): ?>
document.querySelector('.pp-of-precio[data-item="<?= (int)$k ?>"]') && (document.querySelector('.pp-of-precio[data-item="<?= (int)$k ?>"]').value = <?= json_encode((string)$v, $J) ?>);
<?php endforeach; ?>
<?php endif; ?>
</script>
<?php endif; /* vistas */ ?>

<style>
.pp-error { background: rgba(239,68,68,.1); color: #b91c1c; padding: 12px 18px; border-radius: 10px; border: 1px solid rgba(239,68,68,.3); }
.pp-panel { display: flex; flex-direction: column; gap: 14px; }
.pp-cabecera { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.pp-h2 { font-size: 1.15rem; font-weight: 600; margin: 0; }
.pp-ayuda { color: var(--text-muted); font-size: .88rem; margin: 4px 0 0; }
.pp-muted { color: var(--text-muted); }
.pp-rojo { color: var(--danger) !important; }
.pp-fila { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.pp-btn-sec { background: var(--n-100); color: var(--text-primary); border: 1px solid var(--border); }
.pp-link { color: var(--accent); font-weight: 600; text-decoration: none; }
.pp-link:hover { text-decoration: underline; }
.pp-volver { color: var(--text-muted); text-decoration: none; font-size: .88rem; }
.pp-volver:hover { color: var(--accent); }
.pp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.pp-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pp-campo { display: flex; flex-direction: column; gap: 5px; }
.pp-panel input:not([type=checkbox]):not([type=file]), .pp-panel select {
    width: 100%; padding: 9px 12px; border: 1px solid var(--border); background: var(--input-bg);
    color: var(--text-primary); border-radius: 8px; font-size: 14.5px; font-family: inherit;
}
.pp-input-chico { width: auto !important; flex: 1 1 200px; min-width: 0; }
.pp-bloque { display: flex; flex-direction: column; gap: 10px; padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--n-25); }
.pp-bloque-tit { font-weight: 600; }
.pp-quitar { background: transparent; color: var(--danger); border: none; padding: 6px 8px; cursor: pointer; }
.pp-pasos { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
.pp-paso { display: flex; gap: 12px; padding: 14px; border: 1px solid var(--border); border-radius: 12px; background: var(--n-25); }
.pp-paso-n { flex: none; width: 30px; height: 30px; border-radius: 50%; background: var(--accent); color: var(--on-accent); display: flex; align-items: center; justify-content: center; font-weight: 700; }
.pp-paso-cuerpo { display: flex; flex-direction: column; gap: 6px; min-width: 0; flex: 1; }
.pp-paso-cuerpo p { margin: 0; font-size: .86rem; }
.pp-mini-btn { background: none; border: none; color: var(--accent); cursor: pointer; font-size: .85rem; padding: 4px 0; text-align: left; }
.pp-aviso { padding: 10px 14px; border-radius: 10px; background: var(--n-100); border: 1px solid var(--border); font-size: .88rem; color: var(--text-muted); }
.pp-resumen { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
.pp-kpi { padding: 14px 16px; border-radius: 12px; border: 1px solid var(--border); background: var(--n-25); }
.pp-kpi-bien { border-color: var(--accent); background: var(--accent-soft); }
.pp-kpi-tit { font-size: .82rem; color: var(--text-muted); font-weight: 600; }
.pp-kpi-val { font-size: 1.35rem; font-weight: 700; margin: 2px 0; }
.pp-kpi-det { font-size: .88rem; color: var(--text-muted); }
.pp-comparacion th, .pp-comparacion td { vertical-align: top; }
.pp-th-oferta { min-width: 170px; }
.pp-th-prov { font-weight: 700; }
.pp-th-acc { margin-top: 4px; }
.pp-celda { white-space: nowrap; }
.pp-mejor { background: var(--accent-soft); }
.pp-etiqueta { display: inline-block; margin-top: 3px; font-size: .72rem; font-weight: 700; padding: 1px 8px; border-radius: 20px; background: var(--accent); color: var(--on-accent); }
.pp-marca { color: var(--se-warning); }
.pp-icono { background: none; border: none; color: var(--accent); cursor: pointer; padding: 4px 6px; }
.pp-comparacion tfoot td { border-top: 2px solid var(--border); }
@media (max-width: 560px) { .pp-grid2 { grid-template-columns: 1fr; } }
/* En el celular las tablas de la app se vuelven tarjetas. La comparación no:
   son columnas por proveedor y hay que poder mirarlas lado a lado, así que se
   queda como planilla con desplazamiento horizontal y el insumo fijo a la izquierda. */
@media (max-width: 768px) {
    .table-container.pp-comparacion { overflow-x: auto; }
    .table-container.pp-comparacion table { display: table; min-width: 560px; border-collapse: collapse; }
    .table-container.pp-comparacion thead { display: table-header-group; }
    .table-container.pp-comparacion tbody { display: table-row-group; }
    .table-container.pp-comparacion tfoot { display: table-footer-group; }
    .table-container.pp-comparacion tr,
    .table-container.pp-comparacion thead tr { display: table-row; margin: 0; border: 0; border-radius: 0; background: none; }
    .table-container.pp-comparacion th,
    .table-container.pp-comparacion td,
    .table-container.pp-comparacion td:last-child { display: table-cell; text-align: left; padding: 10px; border-bottom: 1px solid var(--border); background: none; }
    .table-container.pp-comparacion td::before { content: none; }
    .table-container.pp-comparacion td.pp-mejor { background: var(--accent-soft); }
    .table-container.pp-comparacion th:first-child,
    .table-container.pp-comparacion td:first-child { position: sticky; left: 0; z-index: 1; background: var(--panel-bg, #fff); min-width: 130px; }
}
</style>
<?php endif; /* tablas listas */ ?>

<?php require_once 'includes/footer.php'; ?>
