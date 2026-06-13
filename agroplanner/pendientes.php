<?php
require_once 'config/auth.php';
$page_title = 'Bienvenido a AgroPlanner';

// Si ya tiene algún módulo, lo redirigimos a donde corresponde
if (isset($_SESSION['modulos'])) {
    if ($_SESSION['modulos']['agricultura']) {
        header('Location: index.php');
        exit;
    } elseif ($_SESSION['modulos']['tambo']) {
        header('Location: tambo.php');
        exit;
    } elseif ($_SESSION['modulos']['ganaderia']) {
        header('Location: ganaderia.php');
        exit;
    }
}

require_once 'includes/header.php';
?>

<div class="glass-panel" style="text-align: center; padding: 60px 20px; max-width: 600px; margin: 40px auto;">
    <i class="fas fa-clock" style="font-size: 3.5rem; color: var(--accent); margin-bottom: 20px;"></i>
    <h2 style="font-size: 1.8rem; margin-bottom: 15px; color: var(--text-primary);">Cuenta Aprobada</h2>
    <p style="color: var(--text-muted); font-size: 1.1rem; line-height: 1.6;">
        Tu cuenta está activa, pero aún no tienes ningún módulo asignado (Agricultura, Tambo, Ganadería).<br>
        Por favor, contacta al administrador del sistema para que te asigne los módulos que te correspondan.
    </p>
    <div style="margin-top: 30px;">
        <a href="logout.php" class="btn" style="background: rgba(255,255,255,0.1); color: white;">Cerrar sesión por ahora</a>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
