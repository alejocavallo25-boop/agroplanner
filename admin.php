<?php
require_once 'config/auth.php';
require_once 'config/database.php';
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
    }
}

// Fetch users
$stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

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

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Usuario / Email</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Módulos</th>
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
