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

$message = '';
$error = '';
$recovery_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    // --- Rate Limiting Básico ---
    if (!isset($_SESSION['forgot_attempts'])) {
        $_SESSION['forgot_attempts'] = 0;
        $_SESSION['forgot_locked_until'] = 0;
    }

    if (time() < $_SESSION['forgot_locked_until']) {
        $restantes = ceil(($_SESSION['forgot_locked_until'] - time()) / 60);
        $error = "Demasiados intentos. Intenta de nuevo en $restantes minuto(s).";
    } else {
        $email = trim($_POST['email'] ?? '');

        if ($email) {
            $_SESSION['forgot_attempts']++;
            
            if ($_SESSION['forgot_attempts'] >= 5) {
                $_SESSION['forgot_locked_until'] = time() + (10 * 60); // Bloqueo de 10 minutos
                $error = 'Demasiados intentos. Intenta de nuevo en 10 minutos.';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hora
                    
                    $updateStmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                    $updateStmt->execute([$token, $expires, $user['id']]);
                    
                    // OPCIÓN A: Para desarrollo local mostramos el link en pantalla
                    // En producción, aquí iría la lógica de mail()
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'];
                    $base_dir = dirname($_SERVER['PHP_SELF']);
                    $base_dir = $base_dir === '\\' || $base_dir === '/' ? '' : $base_dir;
                    
                    $recovery_url = $protocol . '://' . $host . $base_dir . '/reset_password.php?token=' . $token;
                    
                    $message = "Si el correo electrónico existe en nuestra base de datos, hemos generado un enlace para recuperar la contraseña.";
                    $recovery_link = $recovery_url;
                } else {
                    // Por seguridad mostramos el mismo mensaje aunque no exista
                    $message = "Si el correo electrónico existe en nuestra base de datos, hemos generado un enlace para recuperar la contraseña.";
                }
            }
        } else {
            $error = 'Por favor, ingresa tu correo electrónico.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña - AgroPlanner</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Favicon -->
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
            <h2>Recuperar Contraseña</h2>
            
            <?php if ($error): ?>
                <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 12px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div style="background: rgba(16, 185, 129, 0.1); color: var(--accent); padding: 12px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                </div>
                
                <?php if ($recovery_link): ?>
                <div style="background: rgba(255, 255, 255, 0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 24px; word-break: break-all;">
                    <strong style="color: #38bdf8; font-size: 0.85rem; text-transform: uppercase;">MODO DESARROLLO (SOLO LOCAL):</strong><br>
                    <a href="<?= $recovery_link ?>" style="color: #fff; font-size: 0.9rem; text-decoration: underline; margin-top: 5px; display: inline-block;">
                        Haz clic aquí para cambiar tu contraseña
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$message || !$recovery_link): ?>
            <p style="color: var(--text-muted); font-size: 0.9rem; text-align: center; margin-bottom: 20px;">
                Ingresá tu correo electrónico y te enviaremos instrucciones para restablecer tu contraseña.
            </p>
            <form method="POST" action="">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" id="email" name="email" placeholder="ejemplo@correo.com" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; margin-top: 10px;">
                    Solicitar Recuperación
                </button>
            </form>
            <?php endif; ?>

            <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Volver al Login</a>
        </div>
    </div>
</body>
</html>
