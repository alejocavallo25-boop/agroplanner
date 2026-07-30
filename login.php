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
$notice = '';

// Aviso cuando la sesión se cerró por inactividad
if (isset($_GET['expired'])) {
    $notice = 'Tu sesión se cerró por inactividad. Iniciá sesión nuevamente.';
}

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
            // SELECT * a proposito. Si se nombra solo_lectura explicitamente y esa columna
            // todavia no existe en la base, PDO lanza excepcion y NADIE puede iniciar
            // sesion. Con * la columna se aprovecha si esta y se ignora si no: el login
            // deja de depender de que una migracion haya corrido.
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
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
                    // Cuenta de demostración: puede ver todo, no puede modificar nada.
                    // Lo hace cumplir config/auth.php en cada POST.
                    $_SESSION['solo_lectura'] = !empty($user['solo_lectura']);
                    
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

        /* --- Overlay de carga: vaca comiendo pasto --- */
        #loading-overlay {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; flex-direction: column;
            justify-content: center; align-items: center; gap: 22px;
            background: rgba(11, 15, 25, 0.85);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            opacity: 0; visibility: hidden;
            transition: opacity 0.35s ease, visibility 0.35s ease;
        }
        #loading-overlay.is-active { opacity: 1; visibility: visible; }

        .cow-loader { width: 210px; height: 150px; }
        .cow-loader svg { width: 100%; height: 100%; overflow: visible; }

        /* cabeza que baja a pastar y mastica */
        .cow-head {
            transform-box: view-box; transform-origin: 96px 80px;
            animation: cowGraze 3.2s ease-in-out infinite;
        }
        /* cola que se mece */
        .cow-tail {
            transform-box: view-box; transform-origin: 184px 80px;
            animation: cowTail 1.3s ease-in-out infinite;
        }
        /* pasto que se mece con el viento */
        .grass-sway {
            transform-box: view-box; transform-origin: 54px 145px;
            animation: grassSway 2.6s ease-in-out infinite;
        }
        /* mata de pasto que la vaca se va comiendo */
        .grass-eaten {
            transform-box: view-box; transform-origin: 54px 145px;
            animation: grassNibble 3.2s ease-in-out infinite;
        }
        .grass-blade {
            stroke: var(--accent); stroke-width: 5; fill: none;
            stroke-linecap: round;
            filter: drop-shadow(0 0 4px var(--accent-glow));
        }
        .grass-blade.light { stroke: #34d399; }
        /* trigo (agricultura) meciéndose */
        .wheat {
            transform-box: view-box;
            animation: wheatSway 3s ease-in-out infinite;
        }
        /* gota de leche que cae al balde (tambo) */
        .milk-drop { animation: milkDrop 1.6s ease-in infinite; }
        .loading-text {
            color: var(--text-muted); font-size: 15px; letter-spacing: 0.3px;
        }
        .loading-text::after {
            content: ''; animation: loadingDots 1.4s steps(4, end) infinite;
        }

        @keyframes cowGraze {
            0%, 12%   { transform: rotate(0deg); }
            28%, 30%  { transform: rotate(-20deg); }   /* baja al pasto */
            37%       { transform: rotate(-15deg); }   /* mastica */
            44%       { transform: rotate(-20deg); }
            51%       { transform: rotate(-15deg); }
            58%       { transform: rotate(-20deg); }
            74%, 100% { transform: rotate(0deg); }      /* levanta la cabeza */
        }
        @keyframes cowTail {
            0%, 100% { transform: rotate(-12deg); }
            50%      { transform: rotate(12deg); }
        }
        @keyframes grassSway {
            0%, 100% { transform: rotate(-5deg); }
            50%      { transform: rotate(5deg); }
        }
        @keyframes grassNibble {
            0%, 26%   { transform: scaleY(1); }
            40%       { transform: scaleY(0.45); }
            60%       { transform: scaleY(0.3); }
            80%, 100% { transform: scaleY(1); }         /* vuelve a crecer */
        }
        @keyframes wheatSway {
            0%, 100% { transform: rotate(-4deg); }
            50%      { transform: rotate(4deg); }
        }
        @keyframes milkDrop {
            0%   { transform: translateY(0); opacity: 0; }
            18%  { opacity: 1; }
            70%  { transform: translateY(7px); opacity: 1; }
            78%  { opacity: 0; }
            100% { transform: translateY(7px); opacity: 0; }
        }
        @keyframes loadingDots {
            0%   { content: ''; }
            25%  { content: '.'; }
            50%  { content: '..'; }
            75%  { content: '...'; }
        }
        /* Respeta a quien prefiere menos movimiento */
        @media (prefers-reduced-motion: reduce) {
            .cow-head, .cow-tail, .grass-sway, .grass-eaten,
            .wheat, .milk-drop, .loading-text::after { animation: none; }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="glass-panel login-box">
            <h2><i class="fas fa-leaf"></i> AgroPlanner</h2>
            <?php if ($notice): ?>
                <div style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; padding: 12px; border-radius: 8px; border: 1px solid rgba(245, 158, 11, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <?= htmlspecialchars($notice) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 12px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.3); margin-bottom: 24px; font-size: 14px; text-align: center;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <form method="POST" action="" id="login-form">
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

    <!-- Overlay de carga: granja con los 3 módulos (trigo, vaca, ordeñe) -->
    <div id="loading-overlay" aria-hidden="true">
        <div class="cow-loader" role="img" aria-label="Granja: trigo, una vaca pastando y el ordeñe">
            <svg viewBox="0 0 220 170" xmlns="http://www.w3.org/2000/svg">
                <!-- sombra -->
                <ellipse cx="138" cy="148" rx="62" ry="6" fill="rgba(0,0,0,0.28)"/>

                <!-- pasto que se mece -->
                <g class="grass-sway">
                    <path class="grass-blade" d="M30 145 Q24 124 30 106"/>
                    <path class="grass-blade light" d="M40 145 Q36 120 42 100"/>
                    <path class="grass-blade" d="M66 145 Q72 122 68 104"/>
                    <path class="grass-blade light" d="M78 145 Q84 126 80 108"/>
                </g>
                <!-- mata que la vaca se come -->
                <g class="grass-eaten">
                    <path class="grass-blade" d="M50 145 Q44 120 50 102"/>
                    <path class="grass-blade light" d="M58 145 Q64 122 60 104"/>
                </g>

                <!-- trigo (agricultura) -->
                <g class="wheat" style="transform-origin:11px 150px; animation-delay:-0.1s">
                    <line x1="11" y1="150" x2="11" y2="110" stroke="#caa23f" stroke-width="2.5" stroke-linecap="round"/>
                    <g fill="#e6b93f">
                        <ellipse cx="11" cy="106" rx="3" ry="5"/>
                        <ellipse cx="8" cy="112" rx="2.4" ry="4.2" transform="rotate(-28 8 112)"/>
                        <ellipse cx="14" cy="112" rx="2.4" ry="4.2" transform="rotate(28 14 112)"/>
                        <ellipse cx="8" cy="119" rx="2.4" ry="4.2" transform="rotate(-28 8 119)"/>
                        <ellipse cx="14" cy="119" rx="2.4" ry="4.2" transform="rotate(28 14 119)"/>
                    </g>
                    <g stroke="#e6b93f" stroke-width="1" stroke-linecap="round">
                        <line x1="11" y1="102" x2="11" y2="94"/>
                        <line x1="11" y1="103" x2="7" y2="96"/>
                        <line x1="11" y1="103" x2="15" y2="96"/>
                    </g>
                </g>
                <g class="wheat" style="transform-origin:19px 150px; animation-delay:-0.9s">
                    <line x1="19" y1="150" x2="19" y2="110" stroke="#caa23f" stroke-width="2.5" stroke-linecap="round"/>
                    <g fill="#e6b93f">
                        <ellipse cx="19" cy="106" rx="3" ry="5"/>
                        <ellipse cx="16" cy="112" rx="2.4" ry="4.2" transform="rotate(-28 16 112)"/>
                        <ellipse cx="22" cy="112" rx="2.4" ry="4.2" transform="rotate(28 22 112)"/>
                        <ellipse cx="16" cy="119" rx="2.4" ry="4.2" transform="rotate(-28 16 119)"/>
                        <ellipse cx="22" cy="119" rx="2.4" ry="4.2" transform="rotate(28 22 119)"/>
                    </g>
                    <g stroke="#e6b93f" stroke-width="1" stroke-linecap="round">
                        <line x1="19" y1="102" x2="19" y2="94"/>
                        <line x1="19" y1="103" x2="15" y2="96"/>
                        <line x1="19" y1="103" x2="23" y2="96"/>
                    </g>
                </g>
                <g class="wheat" style="transform-origin:27px 150px; animation-delay:-0.5s">
                    <line x1="27" y1="150" x2="27" y2="110" stroke="#caa23f" stroke-width="2.5" stroke-linecap="round"/>
                    <g fill="#e6b93f">
                        <ellipse cx="27" cy="106" rx="3" ry="5"/>
                        <ellipse cx="24" cy="112" rx="2.4" ry="4.2" transform="rotate(-28 24 112)"/>
                        <ellipse cx="30" cy="112" rx="2.4" ry="4.2" transform="rotate(28 30 112)"/>
                        <ellipse cx="24" cy="119" rx="2.4" ry="4.2" transform="rotate(-28 24 119)"/>
                        <ellipse cx="30" cy="119" rx="2.4" ry="4.2" transform="rotate(28 30 119)"/>
                    </g>
                    <g stroke="#e6b93f" stroke-width="1" stroke-linecap="round">
                        <line x1="27" y1="102" x2="27" y2="94"/>
                        <line x1="27" y1="103" x2="23" y2="96"/>
                        <line x1="27" y1="103" x2="31" y2="96"/>
                    </g>
                </g>

                <!-- patas -->
                <rect x="118" y="112" width="11" height="34" rx="5" fill="#d1d5db"/>
                <rect x="150" y="112" width="11" height="34" rx="5" fill="#d1d5db"/>
                <rect x="106" y="114" width="11" height="32" rx="5" fill="#e5e7eb"/>
                <rect x="162" y="114" width="11" height="32" rx="5" fill="#e5e7eb"/>
                <rect x="118" y="140" width="11" height="6" rx="2" fill="#374151"/>
                <rect x="150" y="140" width="11" height="6" rx="2" fill="#374151"/>
                <rect x="106" y="140" width="11" height="6" rx="2" fill="#374151"/>
                <rect x="162" y="140" width="11" height="6" rx="2" fill="#374151"/>

                <!-- cola -->
                <g class="cow-tail">
                    <path d="M184 80 Q198 96 192 120" fill="none" stroke="#d1d5db" stroke-width="4" stroke-linecap="round"/>
                    <circle cx="191" cy="122" r="5" fill="#374151"/>
                </g>

                <!-- cuerpo -->
                <ellipse cx="138" cy="96" rx="50" ry="30" fill="#f3f4f6" stroke="#d1d5db" stroke-width="1.5"/>
                <!-- manchas Holstein -->
                <ellipse cx="120" cy="86" rx="14" ry="11" fill="#374151"/>
                <ellipse cx="160" cy="104" rx="12" ry="9" fill="#374151"/>
                <ellipse cx="146" cy="78" rx="7" ry="5" fill="#374151"/>
                <!-- ubre + ordeñe al balde (tambo) -->
                <ellipse cx="139" cy="121" rx="10" ry="8" fill="#fbb6ce"/>
                <circle cx="135" cy="128" r="2" fill="#f191b0"/>
                <circle cx="143" cy="128" r="2" fill="#f191b0"/>
                <!-- balde -->
                <path d="M131 137 L147 137 L145 149 L133 149 Z" fill="#cbd5e1" stroke="#94a3b8" stroke-width="1.5"/>
                <ellipse cx="139" cy="137" rx="8" ry="2.2" fill="#f8fafc"/>
                <path d="M132 137 Q139 130 146 137" fill="none" stroke="#94a3b8" stroke-width="1.5"/>
                <!-- gota de leche -->
                <ellipse class="milk-drop" cx="139" cy="129" rx="2.2" ry="3" fill="#f8fafc"/>

                <!-- cabeza (baja a pastar) -->
                <g class="cow-head">
                    <path d="M99 70 C 88 74, 80 82, 74 98 L 90 110 C 100 98, 104 84, 105 76 Z" fill="#f3f4f6"/>
                    <ellipse cx="60" cy="102" rx="23" ry="18" fill="#f3f4f6"/>
                    <ellipse cx="72" cy="94" rx="12" ry="10" fill="#374151"/>
                    <ellipse cx="78" cy="86" rx="9" ry="5" fill="#e5e7eb" transform="rotate(-28 78 86)"/>
                    <ellipse cx="66" cy="83" rx="4" ry="6" fill="#d6b370" transform="rotate(-12 66 83)"/>
                    <ellipse cx="42" cy="110" rx="14" ry="11" fill="#fbb6ce"/>
                    <ellipse cx="38" cy="108" rx="2" ry="2.5" fill="#be5a78"/>
                    <ellipse cx="46" cy="113" rx="2" ry="2.5" fill="#be5a78"/>
                    <circle cx="56" cy="96" r="3.4" fill="#1f2937"/>
                    <circle cx="57.2" cy="95" r="1.1" fill="#fff"/>
                </g>
            </svg>
        </div>
        <span class="loading-text">Iniciando sesión</span>
    </div>

    <script>
        (function () {
            var form = document.getElementById('login-form');
            var overlay = document.getElementById('loading-overlay');
            if (!form || !overlay) return;

            var MIN_SHOW = 2800; // ms: deja apreciar a la vaca masticando un ciclo
            var sending = false;

            form.addEventListener('submit', function (e) {
                if (sending) return;       // ya en curso: dejá que envíe
                e.preventDefault();        // frenamos para mostrar la animación
                sending = true;

                overlay.classList.add('is-active');
                overlay.setAttribute('aria-hidden', 'false');
                var btn = form.querySelector('button[type="submit"]');
                if (btn) { btn.disabled = true; } // evita doble envío

                // Texto que rota los 3 módulos mientras carga
                var textEl = overlay.querySelector('.loading-text');
                if (textEl) {
                    var phrases = ['Preparando agricultura', 'Preparando ganadería', 'Preparando tambo'];
                    var pi = 0;
                    textEl.textContent = phrases[0];
                    setInterval(function () {
                        pi = (pi + 1) % phrases.length;
                        textEl.textContent = phrases[pi];
                    }, 850);
                }

                // Tras el delay mínimo, enviamos de verdad (el overlay sigue
                // visible hasta que el navegador carga la página siguiente).
                setTimeout(function () { form.submit(); }, MIN_SHOW);
            });
        })();
    </script>
</body>
</html>
