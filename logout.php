<?php
/* Abre la sesion con la misma configuracion que el resto —csrf.php le pone
   httponly, samesite y secure—; hace falta tenerla abierta para destruirla. */
require_once 'config/csrf.php';
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header('Location: login.php');
exit;
