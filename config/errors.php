<?php
/**
 * config/errors.php
 *
 * Registro de errores de produccion.
 *
 * Sin esto, cualquier excepcion no capturada devuelve una pantalla gris de 500 y no
 * queda rastro en ningun lado accesible: hay que ir a buscar el log del servidor, que
 * en Hostinger no siempre esta a mano. Con esto, todo error fatal queda escrito en
 * php-errors.log, en la raiz de la aplicacion, y se puede abrir desde el administrador
 * de archivos del hPanel.
 *
 * El archivo esta en .gitignore: es de cada entorno y no se versiona.
 *
 * Al visitante nunca se le muestra el detalle tecnico. Sale un mensaje neutro y, si es
 * una peticion que esperaba JSON, un JSON de error para que el frontend no se cuelgue.
 */

const LOG_ERRORES = __DIR__ . '/../php-errors.log';

function ap_registrar_error(string $tipo, string $mensaje, string $archivo, int $linea, string $traza = ''): void
{
    $ruta = $_SERVER['REQUEST_URI'] ?? 'cli';
    $usuario = $_SESSION['usuario_id'] ?? '-';
    $entrada = sprintf(
        "[%s] %s: %s\n  en %s linea %d\n  url=%s usuario=%s\n%s\n",
        date('Y-m-d H:i:s'),
        $tipo,
        $mensaje,
        $archivo,
        $linea,
        $ruta,
        $usuario,
        $traza ? "  traza:\n" . preg_replace('/^/m', '    ', $traza) . "\n" : ''
    );
    // @ para que un problema de permisos al escribir el log no genere OTRO error
    @file_put_contents(LOG_ERRORES, $entrada, FILE_APPEND | LOCK_EX);
}

function ap_espera_json(): bool
{
    return !empty($_POST['ajax'])
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function ap_mostrar_falla(): void
{
    if (headers_sent()) return;
    http_response_code(500);
    if (ap_espera_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => true, 'msg' => 'Ocurrio un error inesperado. Ya quedo registrado.']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
           . '<div style="font-family:system-ui,sans-serif;max-width:34rem;margin:14vh auto;padding:0 1.5rem;line-height:1.6">'
           . '<h1 style="font-size:1.3rem;margin-bottom:.6rem">Ocurrio un error inesperado</h1>'
           . '<p style="color:#555">El problema quedo registrado. Volve a intentar en unos minutos; '
           . 'si sigue pasando, avisanos.</p></div>';
    }
}

set_exception_handler(function (Throwable $t) {
    ap_registrar_error(get_class($t), $t->getMessage(), $t->getFile(), $t->getLine(), $t->getTraceAsString());
    ap_mostrar_falla();
});

// Los errores fatales no pasan por el handler de excepciones: se agarran al terminar.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ap_registrar_error('Error fatal', $e['message'], $e['file'], $e['line']);
        ap_mostrar_falla();
    }
});
