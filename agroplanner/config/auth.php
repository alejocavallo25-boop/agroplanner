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

// Redirect to login if user is not authenticated
if (!isset($_SESSION['usuario_id'])) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/api');
    // Si dirname es '\' (Windows root), arreglarlo
    if ($base === '\\' || $base === '/') $base = '';
    header("Location: $base/login.php");
    exit;
}

// Helper functions for auth
function require_admin() {
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
