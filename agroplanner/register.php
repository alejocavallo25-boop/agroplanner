<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'config/csrf.php';

// Validación CSRF para el registro
validate_csrf();

if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $email && $password) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            $error = 'El correo o el nombre de usuario ya están en uso.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, 'user', 'pending')");
            if ($stmt->execute([$username, $email, $hash])) {
                $success = 'Registro exitoso. Tu cuenta está pendiente de aprobación por parte del administrador.';
                
                // Attempt to send email to admin
                $to = 'alejocavallo25@gmail.com';
                $subject = 'Nuevo usuario registrado - Agricultura SAAS';
                $message = "Se ha registrado un nuevo usuario:\n\nUsuario: $username\nEmail: $email\n\nPor favor, ingresa al panel de administración para aprobarlo: http://localhost/Planificador%20de%20Agricultura/admin.php";
                $headers = "From: noreply@agricultura-saas.local";
                @mail($to, $subject, $message, $headers); // Supress error if mail() is not configured
            } else {
                $error = 'Error en el registro. Inténtalo de nuevo.';
            }
        }
    } else {
        $error = 'Por favor, completa todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - Planificador Agrícola</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Favicon: La hojita idéntica a AgroPlanner (Vectorial y Transparente) -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='-30 -30 572 572'><path fill='%2310b981' d='M471.3 6.7C477.7 .6 487-1.6 495.6 1.2 505.4 4.5 512 13.7 512 24l0 186.9c0 131.2-108.1 237.1-238.8 237.1-77 0-143.4-49.5-167.5-118.7-35.4 30.8-57.7 76.1-57.7 126.7 0 13.3-10.7 24-24 24S0 469.3 0 456C0 381.1 38.2 315.1 96.1 276.3 131.4 252.7 173.5 240 216 240l80 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-80 0c-39.7 0-77.3 8.8-111 24.5 23.3-70 89.2-120.5 167-120.5 66.4 0 115.8-22.1 148.7-44 19.2-12.8 35.5-28.1 50.7-45.3z'/></svg>">

    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-wrapper { width: 100%; max-width: 420px; padding: 20px; }
        .login-box h2 { text-align: center; margin-bottom: 30px; color: var(--text-primary); font-weight: 700; font-size: 28px; letter-spacing: -0.5px; }
        .login-box h2 i { color: var(--accent); margin-right: 12px; filter: drop-shadow(0 0 8px var(--accent-glow)); }
        .form-group { margin-bottom: 20px; }
        .register-link { display: block; text-align: center; margin-top: 24px; color: var(--text-muted); text-decoration: none; font-size: 14.5px; transition: color 0.2s; }
        .register-link:hover { color: var(--accent); }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="glass-panel login-box">
            <h2><i class="fas fa-leaf"></i> Crear Cuenta</h2>
            <?php if ($error): ?>
                <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 12px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div style="background: rgba(16, 185, 129, 0.1); color: var(--accent); padding: 12px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <?= htmlspecialchars($success) ?>
                </div>
                <a href="login.php" class="btn btn-primary" style="width: 100%; padding: 14px; text-align: center; font-size: 16px;">Ir a Iniciar Sesión</a>
            <?php else: ?>
                <form method="POST" action="">
                    <?php csrf_field(); ?>
                    <div class="form-group">
                        <label for="username">Nombre de Usuario</label>
                        <input type="text" id="username" name="username" placeholder="juanperez" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Correo Electrónico</label>
                        <input type="email" id="email" name="email" placeholder="ejemplo@correo.com" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Contraseña</label>
                        <input type="password" id="password" name="password" placeholder="••••••••" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; margin-top: 10px;">Registrarse</button>
                </form>
                <a href="login.php" class="register-link">¿Ya tienes cuenta? Inicia sesión aquí</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
