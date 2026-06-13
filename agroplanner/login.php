<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'config/csrf.php';

// Validación CSRF para el login
validate_csrf();

if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Rate Limiting Básico por Sesión ---
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_locked_until'] = 0;
    }

    if (time() < $_SESSION['login_locked_until']) {
        $restantes = ceil(($_SESSION['login_locked_until'] - time()) / 60);
        $error = "Demasiados intentos fallidos. Intenta de nuevo en $restantes minuto(s).";
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email && $password) {
            $stmt = $pdo->prepare("SELECT id, username, password_hash, role, status, has_agricultura, has_tambo, has_ganaderia FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] === 'active') {
                    // Resetear intentos fallidos
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['login_locked_until'] = 0;
                    
                    // Regenerar sesión para mitigar Session Fixation
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['modulos'] = [
                        'agricultura' => (bool)$user['has_agricultura'],
                        'tambo' => (bool)$user['has_tambo'],
                        'ganaderia' => (bool)$user['has_ganaderia'],
                    ];
                    
                    if ($_SESSION['modulos']['agricultura']) {
                        header('Location: index.php');
                    } elseif ($_SESSION['modulos']['tambo']) {
                        header('Location: tambo.php');
                    } elseif ($_SESSION['modulos']['ganaderia']) {
                        header('Location: ganaderia.php');
                    } else {
                        header('Location: pendientes.php');
                    }
                    exit;
                } else {
                    $error = 'Tu cuenta aún no ha sido aprobada por el administrador.';
                }
            } else {
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['login_locked_until'] = time() + (10 * 60); // Bloqueo de 10 minutos
                    $error = 'Demasiados intentos fallidos. Intenta de nuevo en 10 minutos.';
                } else {
                    $error = 'Credenciales incorrectas.';
                }
            }
        } else {
            $error = 'Por favor, completa todos los campos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Planificador Agrícola</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Favicon: La hojita idéntica a AgroPlanner (Vectorial y Transparente) -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='-30 -30 572 572'><path fill='%2310b981' d='M471.3 6.7C477.7 .6 487-1.6 495.6 1.2 505.4 4.5 512 13.7 512 24l0 186.9c0 131.2-108.1 237.1-238.8 237.1-77 0-143.4-49.5-167.5-118.7-35.4 30.8-57.7 76.1-57.7 126.7 0 13.3-10.7 24-24 24S0 469.3 0 456C0 381.1 38.2 315.1 96.1 276.3 131.4 252.7 173.5 240 216 240l80 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-80 0c-39.7 0-77.3 8.8-111 24.5 23.3-70 89.2-120.5 167-120.5 66.4 0 115.8-22.1 148.7-44 19.2-12.8 35.5-28.1 50.7-45.3z'/></svg>">
   
    <style>
        body { 
            display: flex; justify-content: center; align-items: center; min-height: 100vh; 
        }
        .login-wrapper {
            width: 100%; max-width: 420px; padding: 20px;
        }
        .login-box h2 { 
            text-align: center; margin-bottom: 30px; color: var(--text-primary);
            font-weight: 700; font-size: 28px; letter-spacing: -0.5px;
        }
        .login-box h2 i {
            color: var(--accent);
            margin-right: 12px;
            filter: drop-shadow(0 0 8px var(--accent-glow));
        }
        .form-group { margin-bottom: 20px; }
        .register-link { 
            display: block; text-align: center; margin-top: 24px; 
            color: var(--text-muted); text-decoration: none; font-size: 14.5px; 
            transition: color 0.2s;
        }
        .register-link:hover { color: var(--accent); }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="glass-panel login-box">
            <h2><i class="fas fa-leaf"></i> AgroPlanner</h2>
            <?php if ($error): ?>
                <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 12px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <form method="POST" action="">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" id="email" name="email" placeholder="ejemplo@correo.com" required>
                </div>
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                </div>
                <div class="form-group" style="text-align: right; margin-top: -10px; margin-bottom: 20px;">
                    <a href="forgot_password.php" style="color: var(--accent); text-decoration: none; font-size: 13px;">¿Olvidaste tu contraseña?</a>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px;">Iniciar Sesión</button>
            </form>
            <a href="register.php" class="register-link">¿No tienes cuenta? Solicita acceso aquí</a>
        </div>
    </div>
</body>
</html>
