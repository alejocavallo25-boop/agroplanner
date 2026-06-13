<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'config/csrf.php';

if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$message = '';
$token = $_GET['token'] ?? '';

if (!$token) {
    die("Token de seguridad no proporcionado.");
}

// Verificar token
$stmt = $pdo->prepare("SELECT id, reset_expires FROM users WHERE reset_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    $error = "El enlace de recuperación es inválido o no existe.";
} elseif (strtotime($user['reset_expires']) < time()) {
    $error = "El enlace de recuperación ha expirado. Por favor solicita uno nuevo.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    validate_csrf();
    
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } elseif ($password !== $password_confirm) {
        $error = "Las contraseñas no coinciden.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $updateStmt->execute([$hash, $user['id']]);
        
        $message = "Tu contraseña ha sido actualizada correctamente. Ya podés iniciar sesión.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña - AgroPlanner</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='-30 -30 572 572'><path fill='%2310b981' d='M471.3 6.7C477.7 .6 487-1.6 495.6 1.2 505.4 4.5 512 13.7 512 24l0 186.9c0 131.2-108.1 237.1-238.8 237.1-77 0-143.4-49.5-167.5-118.7-35.4 30.8-57.7 76.1-57.7 126.7 0 13.3-10.7 24-24 24S0 469.3 0 456C0 381.1 38.2 315.1 96.1 276.3 131.4 252.7 173.5 240 216 240l80 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-80 0c-39.7 0-77.3 8.8-111 24.5 23.3-70 89.2-120.5 167-120.5 66.4 0 115.8-22.1 148.7-44 19.2-12.8 35.5-28.1 50.7-45.3z'/></svg>">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-wrapper { width: 100%; max-width: 420px; padding: 20px; }
        .login-box h2 { text-align: center; margin-bottom: 30px; color: var(--text-primary); font-weight: 700; font-size: 24px; letter-spacing: -0.5px; }
        .form-group { margin-bottom: 20px; }
        .back-link { display: block; text-align: center; margin-top: 24px; color: var(--text-muted); text-decoration: none; font-size: 14.5px; transition: color 0.2s; }
        .back-link:hover { color: var(--accent); }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="glass-panel login-box">
            <h2>Crear Nueva Contraseña</h2>
            
            <?php if ($error): ?>
                <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 12px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
                <?php if (strpos($error, 'inválido') !== false || strpos($error, 'expirado') !== false): ?>
                    <a href="forgot_password.php" class="btn btn-primary" style="display:block; text-align:center; padding:12px;">Solicitar nuevo enlace</a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($message): ?>
                <div style="background: rgba(16, 185, 129, 0.1); color: var(--accent); padding: 12px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                </div>
                <a href="login.php" class="btn btn-primary" style="display:block; text-align:center; padding:12px;">Ir a Iniciar Sesión</a>
            <?php endif; ?>

            <?php if (!$message && (!$error || (strpos($error, 'inválido') === false && strpos($error, 'expirado') === false))): ?>
            <p style="color: var(--text-muted); font-size: 0.9rem; text-align: center; margin-bottom: 20px;">
                Ingresá una nueva contraseña para tu cuenta.
            </p>
            <form method="POST" action="">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label for="password">Nueva Contraseña</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" required minlength="6">
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirmar Contraseña</label>
                    <input type="password" id="password_confirm" name="password_confirm" placeholder="••••••••" required minlength="6">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; margin-top: 10px;">
                    Guardar Contraseña
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
