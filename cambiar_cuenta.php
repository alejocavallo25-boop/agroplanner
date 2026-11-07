<?php
/* cambiar_cuenta.php — El asesor pasa a la cuenta de un cliente, o vuelve a la suya.
 *
 * POST con CSRF: cuenta_id (la cuenta a la que va) y volver (la pantalla en la
 * que estaba, para quedarse ahí si la cuenta nueva tiene ese módulo).
 * Ver includes/asesor.php.
 */
require_once 'config/auth.php';
require_once 'config/database.php';
require_once 'includes/asesor.php';

validate_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !asesor_activo()) {
    header('Location: ' . inicio_segun_modulos($_SESSION['modulos'] ?? []));
    exit;
}

$asesor_id = (int)$_SESSION['asesor_id'];
$cuenta_id = (int)($_POST['cuenta_id'] ?? 0);

$st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$st->execute([$cuenta_id]);
$cuenta = $st->fetch(PDO::FETCH_ASSOC);

$como_cliente = $cuenta_id !== $asesor_id;
$permitido = $cuenta && (!$como_cliente
    || (asesor_tiene_cliente($pdo, $asesor_id, $cuenta_id) && asesor_puede_ser_cliente($cuenta, $asesor_id)));

if (!$permitido) {
    set_flash('error', 'No tenés acceso a esa cuenta.');
    header('Location: ' . inicio_segun_modulos($_SESSION['modulos'] ?? []));
    exit;
}

session_regenerate_id(true);
sesion_cargar_cuenta($cuenta, $como_cliente);

/* Token nuevo: un formulario que quedó abierto en otra pestaña con la cuenta
   anterior ya no se puede mandar. Sin esto, un gasto cargado en esa pestaña se
   guardaría en la cuenta nueva, porque la sesión es una sola para todas. */
unset($_SESSION['csrf_token']);

$destino = asesor_destino((string)($_POST['volver'] ?? ''), $_SESSION['modulos']);
$nombre  = $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cambiando de cuenta…</title>
    <noscript><meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($destino) ?>"></noscript>
</head>
<body style="font-family:system-ui,sans-serif;color:#475569;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;">
    <p>Entrando a la cuenta de <strong><?= htmlspecialchars($nombre) ?></strong>…</p>
    <script>
    /* La memoria del chat y el remito que leyó la IA viven en el navegador, no en
       la sesión. Si no se borran, el chat del cliente nuevo arranca con el lote
       o la carga a medio hacer del anterior, y la podría guardar en él. */
    try { sessionStorage.clear(); } catch (e) {}
    // Avisa a las otras pestañas abiertas que cambió la cuenta (ver includes/header.php).
    try { localStorage.setItem('ap_cuenta', <?= json_encode((string)$_SESSION['usuario_id']) ?>); } catch (e) {}
    location.replace(<?= json_encode($destino) ?>);
    </script>
</body>
</html>
