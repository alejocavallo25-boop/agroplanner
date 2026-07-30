<?php
// config/auth.php
if (session_status() === PHP_SESSION_NONE) {
    // Session hardening before starting
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    
    // Activar Secure solo si está bajo HTTPS para no romper entornos de desarrollo locales HTTP
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    
    session_start();
}

require_once __DIR__ . '/csrf.php';

// Ruta base para redirecciones (funciona tanto en páginas como en /api)
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/api');
// Si dirname es '\' (Windows root), arreglarlo
if ($base === '\\' || $base === '/') $base = '';

// Redirect to login if user is not authenticated
if (!isset($_SESSION['usuario_id'])) {
    header("Location: $base/login.php");
    exit;
}

// ─── Timeout de sesión por inactividad ──────────────────────────────────────
// Tras este lapso sin actividad, se cierra la sesión y se exige re-login.
$inactivity_limit = 3 * 60 * 60; // 3 horas
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $inactivity_limit) {
    session_unset();
    session_destroy();
    header("Location: $base/login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

// ─── Cuentas de solo lectura (modo demostración) ───────────────────────────
//
// Todas las escrituras del sistema entran por POST (se verificó: los 79 INSERT/UPDATE/
// DELETE de los 18 archivos cuelgan de un handler `REQUEST_METHOD === 'POST'`). Por eso
// alcanza con cortar el POST acá, que se incluye al principio de las 22 páginas
// autenticadas y ANTES de que cada una procese su formulario.
//
// Es una lista blanca a propósito (denegar por defecto): si mañana se agrega una acción
// nueva, queda bloqueada sola. Sólo se dejan pasar los POST que se sabe que no escriben.
//
// login.php / register.php / forgot_password.php / reset_password.php NO incluyen este
// archivo, así que el demo puede iniciar sesión con normalidad.

function es_solo_lectura(): bool {
    return !empty($_SESSION['solo_lectura']);
}

// POST que sólo consultan y no modifican nada.
// tambo_egresos.php lo usa para traer los egresos de un mes sin recargar la página.
const POST_SOLO_LECTURA_PERMITIDOS = ['get_egresos_mes'];

if (es_solo_lectura() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = $_POST['ajax'] ?? '';
    if (!in_array($ajax, POST_SOLO_LECTURA_PERMITIDOS, true)) {
        $msg = 'Esta es una cuenta de demostración: podés recorrer todo el sistema, '
             . 'pero no guardar cambios.';

        // Si la página esperaba JSON hay que responderle JSON, o el frontend se cuelga.
        $esperaJson = $ajax !== ''
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        http_response_code(403);
        if ($esperaJson) {
            header('Content-Type: application/json; charset=utf-8');
            // Se repiten las claves porque cada handler lee una distinta (ok / msg / error).
            echo json_encode([
                'ok'      => false,
                'error'   => true,
                'msg'     => $msg,
                'message' => $msg,
                'solo_lectura' => true,
            ]);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<script>alert(' . json_encode($msg, JSON_UNESCAPED_UNICODE) . ');history.back();</script>';
        }
        exit;
    }
}

// Helper functions for auth
function require_admin() {
    // Una cuenta de demostración nunca entra al panel de administración, aunque por
    // error quedara marcada como admin: ahí se ven los mails del resto de los usuarios.
    if (es_solo_lectura()) {
        echo "<script>alert('El panel de administración no está disponible en la cuenta de demostración.'); window.location.href='index.php';</script>";
        exit;
    }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo "<script>alert('Acceso denegado. Se requiere nivel de administrador.'); window.location.href='index.php';</script>";
        exit;
    }
}

function require_agricultura() {
    if (!isset($_SESSION['modulos']['agricultura']) || !$_SESSION['modulos']['agricultura']) {
        echo "<script>alert('Acceso denegado. No tienes habilitado el módulo de Agricultura.'); window.location.href='pendientes.php';</script>";
        exit;
    }
}

function require_tambo() {
    if (!isset($_SESSION['modulos']['tambo']) || !$_SESSION['modulos']['tambo']) {
        echo "<script>alert('Acceso denegado. No tienes habilitado el módulo de Tambo.'); window.location.href='pendientes.php';</script>";
        exit;
    }
}

function require_ganaderia() {
    if (!isset($_SESSION['modulos']['ganaderia']) || !$_SESSION['modulos']['ganaderia']) {
        echo "<script>alert('Acceso denegado. No tienes habilitado el módulo de Ganadería.'); window.location.href='pendientes.php';</script>";
        exit;
    }
}

// ─── Flash Messages ────────────────────────────────────────────────────────
function set_flash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
?>
