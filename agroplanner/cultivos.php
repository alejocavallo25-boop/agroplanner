<?php
require_once 'config/auth.php';
require_once 'config/database.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Gestión de Cultivos / Campañas';

// Validación CSRF para todas las peticiones POST (ej: agregar/eliminar campaña)
validate_csrf();

$lote_id = isset($_GET['lote_id']) ? (int)$_GET['lote_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO cultivos (lote_id, nombre, fecha_siembra, fecha_cosecha_esperada, estado, usuario_id, ciclo) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['lote_id'], $_POST['nombre'], $_POST['fecha_siembra'], $_POST['fecha_cosecha_esperada'], $_POST['estado'], $usuario_id, $_POST['ciclo'] ?? null]);
            
            // Sincronizar con el lote si el estado es activo
            if ($_POST['estado'] === 'activo') {
                $stmtLote = $pdo->prepare("UPDATE lotes SET campania = ?, cultivo_actual = ? WHERE id = ? AND usuario_id = ?");
                $stmtLote->execute([$_POST['ciclo'] ?? null, $_POST['nombre'], $_POST['lote_id'], $usuario_id]);
            }
            $pdo->commit();
            set_flash('success', 'Campaña registrada exitosamente.');
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('error', 'Error al registrar campaña.');
        }
        header("Location: cultivos.php" . ($lote_id ? "?lote_id=$lote_id" : ""));
        exit;
    } elseif ($_POST['action'] === 'clean_ghosts') {
        $stmt = $pdo->prepare("
            UPDATE cultivos c
            JOIN lotes l ON c.lote_id = l.id
            SET c.estado = 'cosechado'
            WHERE c.usuario_id = ? AND c.estado = 'activo'
              AND (l.campania IS NULL OR l.campania != c.ciclo OR l.cultivo_actual != c.nombre)
        ");
        $stmt->execute([$usuario_id]);
        $afectados = $stmt->rowCount();
        set_flash('success', "Se limpiaron $afectados campañas fantasmas (pasaron a estado 'cosechado').");
        header("Location: cultivos.php" . ($lote_id ? "?lote_id=$lote_id" : ""));
        exit;
    } elseif ($_POST['action'] === 'change_status') {
        $id = (int)$_POST['id'];
        $new_status = $_POST['estado'];
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE cultivos SET estado = ? WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$new_status, $id, $usuario_id]);
            
            if ($new_status !== 'activo') {
                $stmtC = $pdo->prepare("SELECT lote_id, nombre, ciclo FROM cultivos WHERE id = ?");
                $stmtC->execute([$id]);
                $cult = $stmtC->fetch();
                if ($cult) {
                    $stmtLote = $pdo->prepare("UPDATE lotes SET campania = NULL, cultivo_actual = NULL WHERE id = ? AND usuario_id = ? AND campania = ? AND cultivo_actual = ?");
                    $stmtLote->execute([$cult['lote_id'], $usuario_id, $cult['ciclo'], $cult['nombre']]);
                }
            } else {
                $stmtC = $pdo->prepare("SELECT lote_id, nombre, ciclo FROM cultivos WHERE id = ?");
                $stmtC->execute([$id]);
                $cult = $stmtC->fetch();
                if ($cult) {
                    $stmtLote = $pdo->prepare("UPDATE lotes SET campania = ?, cultivo_actual = ? WHERE id = ? AND usuario_id = ?");
                    $stmtLote->execute([$cult['ciclo'], $cult['nombre'], $cult['lote_id'], $usuario_id]);
                }
            }
            $pdo->commit();
            set_flash('success', 'Estado actualizado correctamente.');
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('error', 'Error al actualizar el estado.');
        }
        header("Location: cultivos.php" . ($lote_id ? "?lote_id=$lote_id" : ""));
        exit;
    } elseif ($_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM cultivos WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$_POST['id'], $usuario_id]);
        set_flash('success', 'Campaña eliminada exitosamente.');
        header("Location: cultivos.php" . ($lote_id ? "?lote_id=$lote_id" : ""));
        exit;
    }
}

$stmt = $pdo->prepare("SELECT id, nombre FROM lotes WHERE usuario_id = ? ORDER BY nombre");
$stmt->execute([$usuario_id]);
$lotes = $stmt->fetchAll();

if ($lote_id) {
    $stmt = $pdo->prepare("SELECT c.*, l.nombre as lote_nombre FROM cultivos c JOIN lotes l ON c.lote_id = l.id WHERE c.lote_id = ? AND c.usuario_id = ? ORDER BY c.created_at DESC");
    $stmt->execute([$lote_id, $usuario_id]);
    $cultivos = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT c.*, l.nombre as lote_nombre FROM cultivos c JOIN lotes l ON c.lote_id = l.id WHERE c.usuario_id = ? ORDER BY c.created_at DESC");
    $stmt->execute([$usuario_id]);
    $cultivos = $stmt->fetchAll();
}

require_once 'includes/header.php';
?>

<div class="glass-panel" style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="font-size: 1.2rem; font-weight: 500;">Campañas Agrícolas <?= $lote_id ? "del Lote Seleccionado" : "" ?></h2>
        <div style="display: flex; gap: 10px;">
            <a href="lotes.php" class="btn" style="background: rgba(255,255,255,0.1); color: white;"><i class="fas fa-arrow-left"></i> Volver a Lotes</a>
            <form method="POST" style="margin: 0;" onsubmit="return confirm('¿Marcar como cosechadas todas las campañas activas que no estén asignadas actualmente a un lote?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="clean_ghosts">
                <button type="submit" class="btn" style="background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3);">
                    <i class="fas fa-broom"></i> Limpiar Fantasmas
                </button>
            </form>
            <button class="btn btn-primary" onclick="document.getElementById('addCultivoModal').style.display='flex'">
                <i class="fas fa-plus"></i> Nueva Campaña
            </button>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Lote</th>
                    <th>Cultivo</th>
                    <th>Ciclo</th>
                    <th>Siembra</th>
                    <th>Cosecha Esperada</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cultivos as $cultivo): ?>
                <tr>
                    <td data-label="Lote"><?= htmlspecialchars($cultivo['lote_nombre']) ?></td>
                    <td data-label="Cultivo"><strong><?= htmlspecialchars($cultivo['nombre']) ?></strong></td>
                    <td data-label="Ciclo"><?= htmlspecialchars($cultivo['ciclo'] ?? '-') ?></td>
                    <td data-label="Siembra"><?= date('d/m/Y', strtotime($cultivo['fecha_siembra'])) ?></td>
                    <td data-label="Cosecha Esperada"><?= date('d/m/Y', strtotime($cultivo['fecha_cosecha_esperada'])) ?></td>
                    <td data-label="Estado">
                        <form method="POST" style="margin: 0;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="id" value="<?= $cultivo['id'] ?>">
                            <select name="estado" onchange="this.form.submit()" style="padding: 4px 8px; border-radius: 6px; background: rgba(0,0,0,0.2); color: white; border: 1px solid var(--border); font-size: 0.85rem; cursor: pointer;">
                                <option value="activo" <?= $cultivo['estado'] === 'activo' ? 'selected' : '' ?>>Activo</option>
                                <option value="cosechado" <?= $cultivo['estado'] === 'cosechado' ? 'selected' : '' ?>>Cosechado</option>
                                <option value="perdido" <?= $cultivo['estado'] === 'perdido' ? 'selected' : '' ?>>Perdido</option>
                            </select>
                        </form>
                    </td>
                    <td data-label="Acciones">
                        <form method="POST" style="display: inline;" onsubmit="if(!confirm('¿Eliminar campaña?')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $cultivo['id'] ?>">
                            <button type="submit" class="btn" style="color: var(--danger); background: transparent; padding: 4px 8px;"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($cultivos) === 0): ?>
                <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No hay campañas registradas</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="addCultivoModal" class="modal-wrapper">
    <div class="glass-panel modal-panel">
        <h2 style="margin-bottom: 20px;">Registrar Nueva Campaña</h2>
        <form method="POST" style="display: flex; flex-direction: column; gap: 15px;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Lote</label>
                <select name="lote_id" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;">
                    <?php foreach($lotes as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= ($lote_id == $l['id']) ? 'selected' : '' ?>><?= htmlspecialchars($l['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Nombre del Cultivo</label>
                <input type="text" name="nombre" required placeholder="Ej: Soja de 1ra" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Ciclo Comercial (Opcional)</label>
                <input type="text" name="ciclo" placeholder="Ej: 25/26" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Fecha de Siembra</label>
                <input type="date" name="fecha_siembra" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Fecha de Cosecha Esperada</label>
                <input type="date" name="fecha_cosecha_esperada" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Estado Inicial</label>
                <select name="estado" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;">
                    <option value="activo">Activo</option>
                    <option value="cosechado">Cosechado</option>
                    <option value="perdido">Perdido</option>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                <button type="button" class="btn" onclick="document.getElementById('addCultivoModal').style.display='none'" style="background: rgba(255,255,255,0.1); color: white;">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
