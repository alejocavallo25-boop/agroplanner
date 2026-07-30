<?php
/**
 * config/csrf.php
 * Sistema de protección contra falsificación de peticiones en sitios cruzados (CSRF)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Genera un nuevo token CSRF si no existe uno en la sesión.
 * @return string
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica si el token enviado coincide con el de la sesión.
 * @param string $token
 * @return bool
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Función utilitaria para inyectar el campo oculto en formularios HTML.
 */
function csrf_field() {
    $token = get_csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Middleware para validar el token automáticamente en peticiones POST.
 * Llama a esta función al inicio de los controladores que procesan formularios.
 */
function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            header('HTTP/1.1 403 Forbidden');
            die('Error 403: Intento de CSRF detectado o token de seguridad expirado. Por favor, recarga la página e intenta nuevamente.');
        }
    }
}
