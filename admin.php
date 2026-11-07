<?php
require_once 'config/auth.php';
require_once 'config/database.php';
require_once 'includes/asesor.php';
require_admin();

// Validación CSRF para acciones Administrativas
validate_csrf();

$page_title = 'Administración de Usuarios';
$msg = '';

// Procesamiento de Acciones via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'approve' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->execute([$id]);
        $msg = 'approved';
    } elseif ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        if ($id !== 1) { // Prevenir borrar admin principal
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $msg = 'deleted';
        }
    } elseif ($_POST['action'] === 'update_modules' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $has_agri  = isset($_POST['has_agricultura']) ? 1 : 0;
        $has_tambo = isset($_POST['has_tambo'])       ? 1 : 0;
        $has_gana  = isset($_POST['has_ganaderia'])   ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE users SET has_agricultura = ?, has_tambo = ?, has_ganaderia = ? WHERE id = ?");
        $stmt->execute([$has_agri, $has_tambo, $has_gana, $id]);
        
        // Si el admin editó su propia sesión → actualizar módulos en vivo
        if ($id === (int)$_SESSION['usuario_id']) {
            $_SESSION['modulos'] = [
                'agricultura' => (bool)$has_agri,
                'tambo'       => (bool)$has_tambo,
                'ganaderia'   => (bool)$has_gana,
            ];
        }
        $msg = 'modules_updated';
    } elseif ($_POST['action'] === 'update_asesor' && isset($_POST['id'])) {
        /* Asesor y sus clientes. Ver includes/asesor.php. La lista que llega es la
           lista entera: lo que no viene se desvincula. */
        $id = (int)$_POST['id'];
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $st->execute([$id]);
        $asesor = $st->fetch();

        if ($asesor && puede_ser_asesor($asesor)) {
            $es_asesor = !empty($_POST['es_asesor']) ? 1 : 0;
            $pedidos   = array_unique(array_map('intval', (array)($_POST['clientes'] ?? [])));
            $validos   = [];
            if ($es_asesor && $pedidos) {
                $marcas = implode(',', array_fill(0, count($pedidos), '?'));
                $st = $pdo->prepare("SELECT * FROM users WHERE id IN ($marcas)");
                $st->execute(array_values($pedidos));
                foreach ($st->fetchAll() as $c) {
                    if (asesor_puede_ser_cliente($c, $id)) $validos[] = (int)$c['id'];
                }
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE users SET es_asesor = ?, firma_nombre = ?, firma_detalle = ? WHERE id = ?")
                    ->execute([
                        $es_asesor,
                        mb_substr(trim($_POST['firma_nombre'] ?? ''), 0, 150) ?: null,
                        mb_substr(trim($_POST['firma_detalle'] ?? ''), 0, 150) ?: null,
                        $id,
                    ]);
                if ($validos) {
                    $marcas = implode(',', array_fill(0, count($validos), '?'));
                    $pdo->prepare("DELETE FROM asesor_clientes WHERE asesor_id = ? AND cliente_id NOT IN ($marcas)")
                        ->execute(array_merge([$id], $validos));
                    $ins = $pdo->prepare("INSERT IGNORE INTO asesor_clientes (asesor_id, cliente_id, origen) VALUES (?, ?, 'admin')");
                    foreach ($validos as $c) $ins->execute([$id, $c]);
                } else {
                    $pdo->prepare("DELETE FROM asesor_clientes WHERE asesor_id = ?")->execute([$id]);
                }
                $pdo->commit();
                $msg = 'asesor_updated';
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[admin] no pude guardar el asesor: ' . $e->getMessage());
                $msg = 'asesor_error';
            }

            // El admin se marcó a sí mismo: se refleja en su sesión sin volver a entrar.
            if ($id === (int)$_SESSION['usuario_id']) {
                if ($es_asesor) {
                    $_SESSION['asesor_id']       = $id;
                    $_SESSION['asesor_username'] = $asesor['username'];
                } else {
                    unset($_SESSION['asesor_id'], $_SESSION['asesor_username']);
                }
            }
        } else {
            $msg = 'asesor_error';
        }
    }
}

// Fetch users
$stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

/* Vínculos asesor → clientes. Sin la migración, la tabla no existe: el panel
   sigue andando y la parte de asesores se esconde con un aviso. */
$asesores_listos = $users && array_key_exists('es_asesor', $users[0]);
$vinculos = [];   // asesor_id => [cliente_id, ...]
$clientes_de = []; // cliente_id => [nombre del asesor, ...]
if ($asesores_listos) {
    try {
        $nombres = array_column($users, 'username', 'id');
        foreach ($pdo->query("SELECT asesor_id, cliente_id FROM asesor_clientes") as $v) {
            $vinculos[(int)$v['asesor_id']][] = (int)$v['cliente_id'];
            $clientes_de[(int)$v['cliente_id']][] = $nombres[$v['asesor_id']] ?? '?';
        }
    } catch (Throwable $e) {
        $asesores_listos = false;
    }
}
// Las cuentas que pueden ser clientes de alguien, para el modal.
$candidatos = [];
foreach ($users as $u) {
    if (($u['role'] ?? '') === 'admin' || !empty($u['solo_lectura'])) continue;
    $candidatos[] = ['id' => (int)$u['id'], 'nombre' => $u['username'], 'email' => $u['email']];
}

require_once 'includes/header.php';
?>

<div class="glass-panel" style="margin-top: 20px;">
    <div class="panel-header" style="margin-bottom: 25px;">
        <h3 style="font-size: 1.3rem; font-weight: 600;">Panel de Control de Usuarios</h3>
        <span class="badge" style="background: rgba(16,185,129,0.1); color: var(--accent); padding: 6px 12px;">Total: <?= count($users) ?> miembros</span>
    </div>
    
    <?php if ($msg === 'approved'): ?>
        <div style="background: rgba(16,185,129,0.1); color: var(--accent); padding: 12px; border-radius: 8px; border: 1px solid rgba(16,185,129,0.3); margin-bottom: 20px; font-size: 0.95rem;">
            <i class="fas fa-check-circle"></i> Usuario aprobado exitosamente.
        </div>
    <?php endif; ?>
    <?php if ($msg === 'deleted'): ?>
        <div style="background: rgba(239,68,68,0.1); color: var(--danger); padding: 12px; border-radius: 8px; border: 1px solid rgba(239,68,68,0.3); margin-bottom: 20px; font-size: 0.95rem;">
            <i class="fas fa-trash-alt"></i> Usuario eliminado del sistema.
        </div>
    <?php endif; ?>
    <?php if ($msg === 'modules_updated'): ?>
        <div style="background: rgba(59,130,246,0.1); color: #60a5fa; padding: 12px; border-radius: 8px; border: 1px solid rgba(59,130,246,0.3); margin-bottom: 20px; font-size: 0.95rem;">
            <i class="fas fa-cubes"></i> Módulos actualizados correctamente.
        </div>
    <?php endif; ?>
    <?php if ($msg === 'asesor_updated'): ?>
        <div style="background: rgba(59,130,246,0.1); color: #60a5fa; padding: 12px; border-radius: 8px; border: 1px solid rgba(59,130,246,0.3); margin-bottom: 20px; font-size: 0.95rem;">
            <i class="fas fa-user-tie"></i> Asesor y cuentas vinculadas guardados.
        </div>
    <?php endif; ?>
    <?php if ($msg === 'asesor_error'): ?>
        <div style="background: rgba(239,68,68,0.1); color: var(--danger); padding: 12px; border-radius: 8px; border: 1px solid rgba(239,68,68,0.3); margin-bottom: 20px; font-size: 0.95rem;">
            <i class="fas fa-exclamation-circle"></i> No se pudo guardar el asesor. La cuenta demo no puede ser asesor.
        </div>
    <?php endif; ?>
    <?php if (!$asesores_listos): ?>
        <div style="background: rgba(245,158,11,0.1); color: #b45309; padding: 12px; border-radius: 8px; border: 1px solid rgba(245,158,11,0.3); margin-bottom: 20px; font-size: 0.95rem;">
            <i class="fas fa-database"></i> Las cuentas de asesor no están disponibles: falta correr <code>migrations/esquema_completo.sql</code> en esta base.
        </div>
    <?php endif; ?>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Usuario / Email</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Módulos</th>
                    <?php if ($asesores_listos): ?><th>Asesor</th><?php endif; ?>
                    <th>Registro</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td data-label="ID">#<?= $u['id'] ?></td>
                    <td data-label="Usuario / Email">
                        <div style="display: flex; flex-direction: column;">
                            <strong><?= htmlspecialchars($u['username']) ?></strong>
                            <small style="color: var(--text-muted);"><?= htmlspecialchars($u['email']) ?></small>
                        </div>
                    </td>
                    <td data-label="Rol">
                        <span style="text-transform: uppercase; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.5px;">
                            <?= htmlspecialchars($u['role']) ?>
                        </span>
                    </td>
                    <td data-label="Estado">
                        <?php if ($u['status'] === 'active'): ?>
                            <span class="badge" style="background: rgba(16, 185, 129, 0.1); color: var(--accent);">Activo</span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(218,54,51,0.1); color: #ff7b72;">Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Módulos">
                        <div style="display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 5px;">
                            <?php if($u['has_agricultura']): ?><span class="badge" style="background:rgba(16,185,129,0.1);color:var(--accent);font-size:0.65rem;">AGRI</span><?php endif; ?>
                            <?php if($u['has_tambo']): ?><span class="badge" style="background:rgba(16,185,129,0.1);color:var(--accent);font-size:0.65rem;">TAMB</span><?php endif; ?>
                            <?php if($u['has_ganaderia']): ?><span class="badge" style="background:rgba(16,185,129,0.1);color:var(--accent);font-size:0.65rem;">GANA</span><?php endif; ?>
                            <?php if(!$u['has_agricultura'] && !$u['has_tambo'] && !$u['has_ganaderia']): ?>
                                <span style="font-size:0.75rem;color:var(--text-muted)">Ninguno</span>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-sm" style="background: rgba(255,255,255,0.05); color: var(--text-primary); padding: 4px 8px; font-size: 0.7rem;" onclick='openModulesModal(<?= $u['id'] ?>, <?= json_encode($u['username']) ?>, <?= $u['has_agricultura'] ?>, <?= $u['has_tambo'] ?>, <?= $u['has_ganaderia'] ?>)'><i class="fas fa-edit"></i> Edit</button>
                    </td>
                    <?php if ($asesores_listos): ?>
                    <td data-label="Asesor">
                        <div style="display: flex; flex-direction: column; gap: 4px; margin-bottom: 5px; font-size: 0.75rem;">
                            <?php if (!empty($u['es_asesor'])): ?>
                                <span class="badge" style="background:rgba(59,130,246,0.1);color:#3b82f6;font-size:0.65rem;align-self:flex-start;">ASESOR · <?= count($vinculos[(int)$u['id']] ?? []) ?> cuentas</span>
                            <?php endif; ?>
                            <?php if (!empty($clientes_de[(int)$u['id']])): ?>
                                <span style="color: var(--text-muted);">Cliente de <?= htmlspecialchars(implode(', ', $clientes_de[(int)$u['id']])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (puede_ser_asesor($u)): ?>
                        <button type="button" class="btn btn-sm" style="background: rgba(255,255,255,0.05); color: var(--text-primary); padding: 4px 8px; font-size: 0.7rem;"
                            onclick='openAsesorModal(<?= json_encode([
                                "id"        => (int)$u["id"],
                                "nombre"    => $u["username"],
                                "es_asesor" => !empty($u["es_asesor"]),
                                "firma_nombre"  => $u["firma_nombre"] ?? "",
                                "firma_detalle" => $u["firma_detalle"] ?? "",
                                "clientes"  => $vinculos[(int)$u["id"]] ?? [],
                            ], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fas fa-user-tie"></i> Asesor</button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td data-label="Registro"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                    <td data-label="Acciones" style="text-align: right;">
                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                            <?php if ($u['status'] === 'pending'): ?>
                                <form method="POST" style="margin:0;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: var(--accent); color: white; padding: 6px 12px; font-size: 0.8rem; border-radius: 6px;">Aprobar</button>
                                </form>
                            <?php endif; ?>
                            
                            <?php if ($u['id'] != 1): // admin root no se puede borrar ?>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('¿Estar seguro de eliminar permanentemente a este usuario?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: rgba(239,68,68,0.15); color: #ff7b72; padding: 6px 12px; font-size: 0.8rem; border-radius: 6px;">Eliminar</button>
                                </form>
                            <?php else: ?>
                                <small style="color: var(--text-muted); font-style: italic;">Inmune</small>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .btn-sm:hover {
        opacity: 0.8;
        transform: translateY(-1px);
    }
</style>

<!-- Modal Módulos -->
<div id="modulesModal" class="modal-wrapper" style="display: none;">
    <div class="glass-panel modal-panel">
        <h3 style="margin-bottom: 15px;">Módulos: <span id="modUserName" style="color:var(--accent);"></span></h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_modules">
            <input type="hidden" name="id" id="modUserId" value="">
            
            <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" name="has_agricultura" id="modAgri" value="1" style="width: 18px; height: 18px; accent-color: var(--accent);">
                    <span>Agricultura</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" name="has_tambo" id="modTamb" value="1" style="width: 18px; height: 18px; accent-color: var(--accent);">
                    <span>Tambo</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" name="has_ganaderia" id="modGana" value="1" style="width: 18px; height: 18px; accent-color: var(--accent);">
                    <span>Ganadería</span>
                </label>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn" onclick="document.getElementById('modulesModal').style.display='none';" style="background: rgba(255,255,255,0.1); color: white;">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<?php if ($asesores_listos): ?>
<!-- Modal Asesor: marcarlo y elegir las cuentas que ve -->
<div id="asesorModal" class="modal-wrapper" style="display: none;">
    <div class="glass-panel modal-panel" style="max-width: 560px; width: 100%;">
        <h3 style="margin-bottom: 15px;">Asesor: <span id="asUserName" style="color:var(--accent);"></span></h3>
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_asesor">
            <input type="hidden" name="id" id="asUserId" value="">

            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 16px;">
                <input type="checkbox" name="es_asesor" id="asEsAsesor" value="1" style="width: 18px; height: 18px; accent-color: var(--accent);">
                <span>Es asesor: puede entrar a las cuentas que marques abajo</span>
            </label>

            <div id="asDatos">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                    <div>
                        <label for="asFirmaNombre" style="font-size: .85rem;">Nombre en la firma</label>
                        <input type="text" name="firma_nombre" id="asFirmaNombre" maxlength="150">
                    </div>
                    <div>
                        <label for="asFirmaDetalle" style="font-size: .85rem;">Segunda línea</label>
                        <input type="text" name="firma_detalle" id="asFirmaDetalle" maxlength="150" placeholder="Ing. Agr. · Mat. 1234">
                    </div>
                </div>
                <small style="color: var(--text-muted); display:block; margin-bottom: 14px;">El logo lo sube el asesor desde su panel.</small>

                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom: 8px;">
                    <strong style="font-size: .95rem;">Cuentas vinculadas <span id="asContador" style="color: var(--text-muted); font-weight: 400;"></span></strong>
                    <input type="search" id="asBuscar" placeholder="Buscar…" aria-label="Buscar cuenta" style="max-width: 200px; padding: 6px 10px;">
                </div>
                <div id="asLista" style="max-height: 280px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; margin-bottom: 20px;"></div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn" onclick="document.getElementById('asesorModal').style.display='none';" style="background: rgba(255,255,255,0.1);">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
/* Admins y la demo no pueden ser clientes de nadie: no vienen en la lista. */
const AS_CANDIDATOS = <?= json_encode($candidatos, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function openAsesorModal(a) {
    document.getElementById('asUserId').value = a.id;
    document.getElementById('asUserName').textContent = a.nombre;
    document.getElementById('asEsAsesor').checked = a.es_asesor;
    document.getElementById('asFirmaNombre').value = a.firma_nombre || '';
    document.getElementById('asFirmaDetalle').value = a.firma_detalle || '';
    document.getElementById('asBuscar').value = '';

    const lista = document.getElementById('asLista');
    lista.replaceChildren();
    AS_CANDIDATOS.filter(c => c.id !== a.id).forEach(c => {
        const fila = document.createElement('label');
        fila.style.cssText = 'display:flex;align-items:center;gap:10px;padding:6px 0;cursor:pointer;';
        fila.dataset.buscar = (c.nombre + ' ' + c.email).toLowerCase();
        const chk = document.createElement('input');
        chk.type = 'checkbox'; chk.name = 'clientes[]'; chk.value = c.id;
        chk.checked = a.clientes.includes(c.id);
        chk.style.cssText = 'width:18px;height:18px;accent-color:var(--accent);flex:none;';
        chk.addEventListener('change', asContar);
        const txt = document.createElement('span');
        txt.innerHTML = '<strong></strong> <small style="color:var(--text-muted)"></small>';
        txt.querySelector('strong').textContent = c.nombre;
        txt.querySelector('small').textContent = c.email;
        fila.append(chk, txt);
        lista.append(fila);
    });
    asContar();
    asMostrarDatos();
    document.getElementById('asesorModal').style.display = 'flex';
}
function asContar() {
    const n = document.querySelectorAll('#asLista input:checked').length;
    document.getElementById('asContador').textContent = '(' + n + ')';
}
function asMostrarDatos() {
    // Sin la marca de asesor se desvinculan todas: se avisa apagando la lista.
    // La firma no se apaga, así no se pierde si se lo vuelve a marcar después.
    const es = document.getElementById('asEsAsesor').checked;
    const lista = document.getElementById('asLista');
    lista.style.opacity = es ? '1' : '.4';
    lista.querySelectorAll('input').forEach(i => i.disabled = !es);
    document.getElementById('asBuscar').disabled = !es;
}
document.getElementById('asEsAsesor').addEventListener('change', asMostrarDatos);
document.getElementById('asBuscar').addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('#asLista label').forEach(f => {
        f.style.display = !q || f.dataset.buscar.includes(q) ? 'flex' : 'none';
    });
});
</script>
<?php endif; ?>

<script>
function openModulesModal(id, username, agri, tambo, gana) {
    document.getElementById('modUserId').value = id;
    document.getElementById('modUserName').textContent = username;
    document.getElementById('modAgri').checked = (agri == 1);
    document.getElementById('modTamb').checked = (tambo == 1);
    document.getElementById('modGana').checked = (gana == 1);
    document.getElementById('modulesModal').style.display = 'flex';
}
</script>

<?php require_once 'includes/footer.php'; ?>
