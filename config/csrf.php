<?php
/**
 * config/csrf.php
 * Sistema de protección contra falsificación de peticiones en sitios cruzados (CSRF)
 */

/* ─── Cómo se crea la cookie de sesión ────────────────────────────────────────
 *
 * Va ACÁ y no en auth.php, y esa es la corrección.
 *
 * auth.php ya endurecía la cookie, pero la sesión no siempre nace por ahí: las
 * páginas públicas —login, registro, recuperar contraseña, términos— incluyen
 * este archivo SIN pasar por auth.php. O sea que la cookie se creaba en el
 * login, con los valores por defecto de PHP, y esa misma cookie seguía siendo la
 * sesión después de entrar.
 *
 * Verificado en producción: la cookie llegaba SIN HttpOnly. Con eso, cualquier
 * XSS puede leerla con document.cookie y quedarse con la sesión del productor.
 *
 * Estas tres líneas tienen que correr ANTES de session_start(): después ya no
 * hacen nada, la cookie ya se emitió.
 *
 *   httponly  el javascript no la puede leer.
 *   samesite  no se manda desde otro sitio; es la segunda defensa contra CSRF.
 *   secure    sólo por HTTPS. Se detecta mirando también la cabecera del proxy:
 *             detrás del CDN, $_SERVER['HTTPS'] no siempre llega en 'on', y con
 *             la comprobación ingenua la cookie viajaba sin la marca.
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');

    $porHttps = (($_SERVER['HTTPS'] ?? '') === 'on')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
             || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    // En el XAMPP local se sirve por http: con esto puesto, no habría sesión.
    if ($porHttps) ini_set('session.cookie_secure', 1);

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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (isset($_POST['csrf_token']) && verify_csrf_token($_POST['csrf_token'])) return;

    /* El caso real de esto no es un ataque: es la pestaña que quedó abierta
       durante horas y el token venció. Le pasa a cualquiera.
     *
     * Antes se cortaba con un die() que decía "Error 403: Intento de CSRF
     * detectado": pantalla en blanco, sin estilos, jerga incomprensible, y el
     * productor convencido de que hizo algo mal. Ahora vuelve a la pantalla de
     * donde venía con un mensaje que explica qué pasó y qué hacer.
     *
     * La protección no cambia: la petición se descarta igual. Lo único que cambia
     * es cómo se le cuenta. */
    error_log('[csrf] token inválido o vencido en ' . ($_SERVER['REQUEST_URI'] ?? '?'));

    if (function_exists('set_flash')) {
        set_flash('error', 'Se venció la sesión del formulario por seguridad y no se guardó nada. '
                         . 'Suele pasar cuando la página quedó abierta un rato largo. '
                         . 'Volvé a cargar los datos y guardá otra vez.');
    }

    // Se vuelve a la misma pantalla; si no se puede saber cuál era, al panel.
    $volver = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    // Sólo destinos propios: un Referer externo no debe poder redirigir a ningún lado.
    $host = parse_url($volver, PHP_URL_HOST);
    if ($host !== null && $host !== ($_SERVER['HTTP_HOST'] ?? '')) {
        $volver = 'index.php';
    }

    header('Location: ' . $volver);
    exit;
}
