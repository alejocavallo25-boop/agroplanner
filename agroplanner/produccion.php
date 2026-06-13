<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
require_once 'includes/cultivos.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Producción y Ventas';
validate_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Agregar venta parcial ─────────────────────────────────────────────────
    if ($_POST['action'] === 'add') {
        $lote_id = (int)$_POST['lote_id'];
        $cultivo_info = $_POST['form_cultivo'] ?? '';
        $campania = null; $cultivo = null;
        if ($cultivo_info) {
            $partes = explode(' | ', $cultivo_info);
            if (count($partes) === 2) { $campania = trim($partes[0]); $cultivo = trim($partes[1]); }
            else { $cultivo = trim($cultivo_info); }
        }
        $cultivo_id = cultivo_resolve($pdo, $usuario_id, $lote_id, $cultivo, $campania);
        $stmt = $pdo->prepare("INSERT INTO produccion_ventas (lote_id, cultivo_id, kg_cosechados, precio_kg, fecha_venta, usuario_id, campania_vendida, cultivo_vendido, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$lote_id, $cultivo_id, (float)$_POST['kg'], (float)$_POST['precio'], $_POST['fecha'], $usuario_id, $campania, $cultivo, trim($_POST['notas'] ?? '')]);
        // NO cerramos la campaña automáticamente — el usuario lo hace manualmente
        set_flash('success', 'Venta registrada exitosamente.');
        header("Location: produccion.php"); exit;

    // ── Editar venta ──────────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'edit') {
        $stmt = $pdo->prepare("UPDATE produccion_ventas SET kg_cosechados=?, precio_kg=?, fecha_venta=?, notas=? WHERE id=? AND usuario_id=?");
        $stmt->execute([(float)$_POST['kg'], (float)$_POST['precio'], $_POST['fecha'], trim($_POST['notas'] ?? ''), (int)$_POST['id'], $usuario_id]);
        set_flash('success', 'Venta actualizada exitosamente.');
        header("Location: produccion.php"); exit;

    // ── Cerrar campaña manualmente ────────────────────────────────────────────
    } elseif ($_POST['action'] === 'cerrar_campania') {
        $lote_id   = (int)$_POST['lote_id'];
        $campania  = $_POST['campania'] ?? '';
        $cultivo   = $_POST['cultivo']  ?? '';

        $pdo->beginTransaction();
        try {
            // Acumular totales SOLO de este cultivo (no mezclar otros cultivos del
            // mismo lote/campaña que pudieran existir por doble cultivo).
            $stmtTot = $pdo->prepare("
                SELECT SUM(pv.kg_cosechados) as kgs, SUM(pv.ingreso_total) as ingresos
                FROM produccion_ventas pv
                LEFT JOIN cultivos c ON pv.cultivo_id = c.id
                WHERE pv.lote_id = ? AND pv.usuario_id = ? AND pv.campania_vendida = ?
                  AND COALESCE(NULLIF(c.nombre, ''), NULLIF(pv.cultivo_vendido, ''), 'Sin especificar') = ?
            ");
            $stmtTot->execute([$lote_id, $usuario_id, $campania, $cultivo]);
            $tot = $stmtTot->fetch();

            // Guardar en historial
            $stmtH = $pdo->prepare("INSERT INTO lote_historial_campanas (lote_id, usuario_id, campania, cultivo, fecha_cierre, kg_total, ingreso_total) VALUES (?, ?, ?, ?, CURDATE(), ?, ?)");
            $stmtH->execute([$lote_id, $usuario_id, $campania, $cultivo, (float)$tot['kgs'], (float)$tot['ingresos']]);

            // Limpiar lote
            $pdo->prepare("UPDATE lotes SET campania=NULL, cultivo_actual=NULL WHERE id=? AND usuario_id=?")->execute([$lote_id, $usuario_id]);

            // Actualizar estado en la tabla cultivos
            $pdo->prepare("UPDATE cultivos SET estado='cosechado' WHERE lote_id=? AND usuario_id=? AND ciclo=? AND estado='activo'")->execute([$lote_id, $usuario_id, $campania]);

            $pdo->commit();
            set_flash('success', 'Campaña cerrada exitosamente. Lote liberado.');
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('error', 'Error al cerrar la campaña: ' . $e->getMessage());
        }
        header("Location: lotes.php?nueva_campania=$lote_id"); exit;

    // ── Eliminar venta ────────────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'delete') {
        $pdo->prepare("DELETE FROM produccion_ventas WHERE id=? AND usuario_id=?")->execute([(int)$_POST['id'], $usuario_id]);
        set_flash('success', 'Venta eliminada exitosamente.');
        header("Location: produccion.php"); exit;
    }
}

// ── Datos ─────────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, nombre, campania, cultivo_actual FROM lotes WHERE usuario_id=? ORDER BY nombre");
$stmt->execute([$usuario_id]);
$lotes = $stmt->fetchAll();
$lotes_json = json_encode($lotes);

$stmt = $pdo->prepare("SELECT lote_id, ciclo as campania, nombre as cultivo FROM cultivos WHERE usuario_id = ? ORDER BY ciclo DESC");
$stmt->execute([$usuario_id]);
$cultivos_adicionales = $stmt->fetchAll(PDO::FETCH_ASSOC);
$cultivos_json = json_encode($cultivos_adicionales);

// ─── Listado de ventas con Paginación ─────────────────────────────────────
$limit = 30;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// 1. Contar total para paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM produccion_ventas WHERE usuario_id = ?");
$stmtCount->execute([$usuario_id]);
$total_rows = (int)$stmtCount->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 2. Obtener registros paginados
$stmt = $pdo->prepare("
    SELECT p.*, l.nombre as lote_nombre,
           COALESCE(c.nombre, p.cultivo_vendido) as cultivo_nombre,
           p.campania_vendida, p.notas
    FROM produccion_ventas p
    JOIN lotes l ON p.lote_id = l.id
    LEFT JOIN cultivos c ON p.cultivo_id = c.id
    WHERE p.usuario_id = ?
    ORDER BY p.fecha_venta DESC, l.nombre
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$usuario_id]);
$ventas = $stmt->fetchAll();

// Agrupar por campaña para mostrar subtotales
$ventas_por_campania = [];
foreach ($ventas as $v) {
    // Agrupamos por lote + campaña + cultivo, para que cada cultivo se cierre
    // por separado con sus propios totales (clave para doble cultivo).
    $cultivo_key = $v['cultivo_nombre'] ?: 'Sin especificar';
    $key = ($v['campania_vendida'] ?: 'Sin campaña') . '||' . $v['lote_id'] . '||' . $cultivo_key;
    if (!isset($ventas_por_campania[$key])) {
        $ventas_por_campania[$key] = [
            'campania'   => $v['campania_vendida'] ?: 'Sin campaña',
            'lote_id'    => $v['lote_id'],
            'lote_nombre'=> $v['lote_nombre'],
            'cultivo'    => $cultivo_key,
            'ventas'     => [],
            'total_kgs'  => 0,
            'total_ing'  => 0,
        ];
    }
    $ventas_por_campania[$key]['ventas'][]    = $v;
    $ventas_por_campania[$key]['total_kgs']  += (float)$v['kg_cosechados'];
    $ventas_por_campania[$key]['total_ing']  += (float)$v['ingreso_total'];
}

// Campañas ya cerradas (en historial) — para NO mostrar botón en grupos ya cerrados
$stmtH = $pdo->prepare("SELECT CONCAT(lote_id,'||',campania,'||',COALESCE(NULLIF(cultivo,''),'Sin especificar')) as clave FROM lote_historial_campanas WHERE usuario_id=?");
$stmtH->execute([$usuario_id]);
$campanias_cerradas = array_flip($stmtH->fetchAll(PDO::FETCH_COLUMN)); // usamos flip para buscar en O(1)

// Lotes SIN campaña activa (candidatos a recibir nueva campaña)
$lotes_sin_campania = array_filter($lotes, fn($l) => empty($l['campania']));

require_once 'includes/header.php';
?>
<style>
.campania-group { margin-bottom: 28px; }
.campania-header {
    display: flex; justify-content: space-between; align-items: center;
    background: rgba(16,185,129,0.06); border: 1px solid rgba(16,185,129,0.2);
    border-radius: 10px; padding: 12px 18px; margin-bottom: 8px; flex-wrap: wrap; gap: 10px;
}
.campania-header h3 { margin: 0; font-size: 1rem; font-weight: 700; color: var(--accent); display: flex; align-items: center; gap: 8px; }
.campania-subtotal { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.campania-subtotal span { font-size: 0.85rem; color: var(--text-muted); }
.campania-subtotal strong { color: var(--text-primary); }
.btn-cerrar-campania { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #ff7b72; font-size: 0.8rem; padding: 5px 12px; border-radius: 20px; cursor: pointer; transition: all 0.2s; }
.btn-cerrar-campania:hover { background: rgba(239,68,68,0.25); }
</style>

<div class="glass-panel" style="margin-bottom: 24px;">
    <div class="panel-header">
        <h2 style="font-size: 1.2rem; font-weight: 500;">
            <i class="fas fa-wheat-awn" style="color: var(--accent); margin-right: 8px;"></i>
            Registro de Cosechas y Ventas
        </h2>
        <button class="btn btn-primary" onclick="openAddModal()">
            <i class="fas fa-plus"></i> Registrar Entrega
        </button>
    </div>

    <?php if (empty($ventas_por_campania)): ?>
    <div style="text-align:center; padding: 40px; color: var(--text-muted);">
        <i class="fas fa-wheat-awn" style="font-size:2.5rem; opacity:0.2; display:block; margin-bottom:12px;"></i>
        No hay registros de ventas o cosechas
    </div>
    <?php else: ?>
    <?php foreach ($ventas_por_campania as $key => $grupo): ?>
    <div class="campania-group">
        <!-- Encabezado de Campaña -->
        <div class="campania-header">
            <h3>
                <i class="fas fa-seedling"></i>
                <?= htmlspecialchars($grupo['campania']) ?> — <?= htmlspecialchars($grupo['lote_nombre']) ?>
                <span style="font-weight:400; color: var(--text-muted); font-size:0.85rem;">(<?= htmlspecialchars($grupo['cultivo']) ?>)</span>
            </h3>
            <div class="campania-subtotal">
                <span>Total: <strong><?= number_format($grupo['total_kgs'], 2, ',', '.') ?> kg</strong></span>
                <span>Ingreso: <strong style="color: var(--accent);">$<?= number_format($grupo['total_ing'], 2, ',', '.') ?></strong></span>
                <?php
                // ── LÓGICA CORREGIDA: mostrar "Cerrar Campaña" solo si:
                // 1. El lote tiene campaña activa (campania != null)
                // 2. La campaña activa del lote es EXACTAMENTE la misma que este grupo
                // 3. Esta campaña NO está ya en el historial de cerradas
                $claveHistorial = $grupo['lote_id'] . '||' . $grupo['campania'] . '||' . $grupo['cultivo'];
                $yaEnHistorial  = isset($campanias_cerradas[$claveHistorial]);

                $loteActivo = null;
                foreach ($lotes as $l) {
                    if ($l['id'] == $grupo['lote_id']
                        && !empty($l['campania'])
                        && $l['campania'] === $grupo['campania']) // ← mismo nombre de campaña
                    {
                        $loteActivo = $l; break;
                    }
                }
                if ($loteActivo && !$yaEnHistorial): ?>
                <form method="POST" style="display:inline;"
                    onsubmit="if(!confirm('¿Cerrar la campaña <?= htmlspecialchars($grupo['campania'], ENT_QUOTES) ?> del <?= htmlspecialchars($grupo['lote_nombre'], ENT_QUOTES) ?>?\nEsto libera el lote para una nueva campaña y guarda el historial.')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action"   value="cerrar_campania">
                    <input type="hidden" name="lote_id"  value="<?= $grupo['lote_id'] ?>">
                    <input type="hidden" name="campania" value="<?= htmlspecialchars($grupo['campania']) ?>">
                    <input type="hidden" name="cultivo"  value="<?= htmlspecialchars($grupo['cultivo']) ?>">
                    <button type="submit" class="btn btn-cerrar-campania">
                        <i class="fas fa-flag-checkered"></i> Cerrar Campaña
                    </button>
                </form>
                <?php elseif ($yaEnHistorial): ?>
                    <span style="font-size:0.78rem; color:var(--text-muted); background:rgba(16,185,129,0.07); border:1px solid rgba(16,185,129,0.15); border-radius:20px; padding:4px 10px;">
                        <i class="fas fa-check-circle" style="color:var(--accent);"></i> Cerrada
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabla de entregas de esta campaña -->
        <div class="table-container" style="margin-bottom: 0;">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Kilogramos</th>
                        <th>Precio/kg (USD)</th>
                        <th>Ingreso</th>
                        <th>Notas</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grupo['ventas'] as $v): ?>
                    <tr>
                        <td data-label="Fecha"><?= date('d/m/Y', strtotime($v['fecha_venta'])) ?></td>
                        <td data-label="Kilogramos"><strong><?= number_format($v['kg_cosechados'], 2, ',', '.') ?></strong> kg</td>
                        <td data-label="Precio/kg">$<?= number_format($v['precio_kg'], 2, ',', '.') ?></td>
                        <td data-label="Ingreso" style="color: var(--accent); font-weight: 600;">$<?= number_format($v['ingreso_total'], 2, ',', '.') ?></td>
                        <td data-label="Notas"><small style="color:var(--text-muted);"><?= htmlspecialchars($v['notas'] ?: '—') ?></small></td>
                        <td data-label="Acciones">
                            <button type="button" class="btn" style="color:var(--accent); background:transparent; padding:4px 8px;"
                                onclick='openEditModal(<?= json_encode($v, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="if(!confirm('¿Eliminar esta entrega?')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id"     value="<?= $v['id'] ?>">
                                <button type="submit" class="btn" style="color:var(--danger); background:transparent; padding:4px 8px;"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <div style="display:flex; justify-content: center; gap:10px; margin-top:20px; padding-bottom:10px;">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;"><i class="fas fa-chevron-left"></i> Anterior</a>
        <?php endif; ?>
        
        <span style="color:var(--text-muted); align-self:center; font-size:0.9rem;">Página <?= $page ?> de <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;">Siguiente <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ===== MODAL: Agregar / Editar Entrega ===== -->
<div id="produccionModal" class="modal-wrapper">
    <div class="glass-panel modal-panel">
        <h2 id="prodModalTitle" style="margin-bottom: 20px;">Registrar Entrega de Cosecha</h2>
        <form method="POST" style="display: flex; flex-direction: column; gap: 15px;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="prodAction" value="add">
            <input type="hidden" name="id"     id="prodId"     value="">

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Lote</label>
                <select name="lote_id" id="loteSelectP" required
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;"
                    onchange="updateCultivosP()">
                    <option value="">-- Seleccionar Lote --</option>
                    <?php foreach($lotes as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;" id="cultPContainer">
                <label>Campaña / Cultivo</label>
                <select name="form_cultivo" id="cultivoSelectP"
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;">
                    <option value="">-- Seleccionar primero un lote --</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label>Kilogramos entregados</label>
                    <input type="number" step="0.01" name="kg" id="prodKgs" required
                        placeholder="Ej: 150.50"
                        style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label>Precio por kg (USD)</label>
                    <input type="number" step="0.01" name="precio" id="prodPrecio" required
                        placeholder="Ej: 320.00"
                        style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
                </div>
            </div>

            <!-- Preview ingreso -->
            <div id="prodPreview" style="display:none; background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.2); border-radius:8px; padding:10px; text-align:center;">
                <span style="font-size:0.8rem; color:var(--text-muted);">Ingreso estimado</span><br>
                <span id="prodPreviewVal" style="font-size:1.4rem; font-weight:800; color:var(--accent);">0.00 USD</span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Fecha de Entrega</label>
                <input type="date" name="fecha" id="prodFecha" value="<?= date('Y-m-d') ?>" required
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Notas <small style="color:var(--text-muted);">(Opcional)</small></label>
                <input type="text" name="notas" id="prodNotas"
                    placeholder="Ej: 1ra entrega, destino exportación..."
                    style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                <button type="button" class="btn" onclick="closeProdModal()" style="background:rgba(255,255,255,0.1); color:white;">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
const lotesRawP = <?= $lotes_json ?>;
const cultivosRawP = <?= $cultivos_json ?>;

function openAddModal() {
    document.getElementById('prodModalTitle').innerText = 'Registrar Entrega de Cosecha';
    document.getElementById('prodAction').value = 'add';
    document.getElementById('prodId').value = '';
    document.getElementById('loteSelectP').value = '';
    document.getElementById('cultivoSelectP').innerHTML = '<option value="">-- Seleccionar primero un lote --</option>';
    document.getElementById('cultPContainer').style.display = 'flex';
    document.getElementById('prodKgs').value = '';
    document.getElementById('prodPrecio').value = '';
    document.getElementById('prodFecha').value = '<?= date('Y-m-d') ?>';
    document.getElementById('prodNotas').value = '';
    document.getElementById('prodPreview').style.display = 'none';
    document.getElementById('produccionModal').style.display = 'flex';
    document.body.classList.add('modal-open');
}

function openEditModal(v) {
    document.getElementById('prodModalTitle').innerText = 'Editar Entrega';
    document.getElementById('prodAction').value = 'edit';
    document.getElementById('prodId').value = v.id;
    document.getElementById('cultPContainer').style.display = 'none'; // Al editar no se cambia campaña
    document.getElementById('loteSelectP').value = v.lote_id;
    document.getElementById('prodKgs').value = v.kg_cosechados;
    document.getElementById('prodPrecio').value = v.precio_kg;
    document.getElementById('prodFecha').value = v.fecha_venta;
    document.getElementById('prodNotas').value = v.notas || '';
    calcPreview();
    document.getElementById('produccionModal').style.display = 'flex';
    document.body.classList.add('modal-open');
}

function closeProdModal() {
    document.getElementById('produccionModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

function updateCultivosP() {
    const loteId = document.getElementById('loteSelectP').value;
    const sel    = document.getElementById('cultivoSelectP');
    sel.innerHTML = '<option value="">-- General / Sin Cultivo Específico --</option>';
    if (loteId) {
        let options = [];
        const info = lotesRawP.find(l => l.id == loteId);
        if (info && info.campania && info.cultivo_actual) {
            options.push({ c: info.campania, cult: info.cultivo_actual });
        }
        cultivosRawP.forEach(c => {
            if (c.lote_id == loteId && c.campania && c.cultivo) {
                if (!options.some(o => o.c === c.campania && o.cult === c.cultivo)) {
                    options.push({ c: c.campania, cult: c.cultivo });
                }
            }
        });
        options.forEach(opt => {
            const val = opt.c + ' | ' + opt.cult;
            sel.innerHTML += `<option value="${val}">${val}</option>`;
        });
        if (options.length === 1) {
            sel.value = options[0].c + ' | ' + options[0].cult;
        }
    }
}

function calcPreview() {
    const kgs   = parseFloat(document.getElementById('prodKgs').value) || 0;
    const precio= parseFloat(document.getElementById('prodPrecio').value) || 0;
    const total = kgs * precio;
    const prev  = document.getElementById('prodPreview');
    if (total > 0) {
        prev.style.display = 'block';
        document.getElementById('prodPreviewVal').textContent = total.toLocaleString('es-AR', {minimumFractionDigits:2}) + ' USD';
    } else {
        prev.style.display = 'none';
    }
}

document.getElementById('prodKgs').addEventListener('input', calcPreview);
document.getElementById('prodPrecio').addEventListener('input', calcPreview);
</script>
