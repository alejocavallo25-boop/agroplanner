<?php
/**
 * api/registrar_venta.php
 *
 * Registra una entrega vendida, propuesta por el chat.
 *
 * Es la única de las cuatro altas que SUMA al margen en vez de restarle, y por eso
 * conviene tenerla a la vista y no escondida en un if de otro archivo.
 *
 * No recibe el ingreso: en la base `produccion_ventas.ingreso_total` es una columna
 * generada (kg_cosechados × precio_kg). Mandarlo sería mandar un dato que MySQL va
 * a ignorar y recalcular, y peor, daría la impresión de que se puede corregir a
 * mano. El chat lo muestra en la confirmación, que es donde el productor lo
 * reconoce, pero lo que se guarda son los dos factores.
 *
 * Va por POST, por las mismas dos razones de siempre:
 *
 *   - El bloqueo de cuentas de demostración de config/auth.php es una lista blanca
 *     sobre POST. Al entrar por acá, la demo queda bloqueada sola.
 *   - Hereda la validación de token CSRF por el mismo camino que el resto.
 */
require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']);
    exit;
}

if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Se venció la sesión del formulario. Recargá la página y probá de nuevo.']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

$lote_id    = (int)($_POST['lote_id'] ?? 0);
$cultivo_id = (int)($_POST['cultivo_id'] ?? 0);
$campania   = trim((string)($_POST['campania'] ?? ''));
$kg         = (float)($_POST['kg'] ?? 0);
$precio     = (float)($_POST['precio_kg'] ?? 0);
$fecha      = (string)($_POST['fecha_venta'] ?? '');
// La moneda del precio. Se guarda tal cual; el panel convierte al mirar.
$moneda     = (($_POST['moneda'] ?? 'ARS') === 'USD') ? 'USD' : 'ARS';

$errores = [];
if ($kg <= 0)                  $errores[] = 'los kilos tienen que ser mayores a cero';
if ($precio <= 0)              $errores[] = 'el precio tiene que ser mayor a cero';
if ($campania === '')          $errores[] = 'falta la campaña';
if (mb_strlen($campania) > 50) $errores[] = 'la campaña es demasiado larga';

// Misma validación de fecha que las otras altas: el formato solo deja pasar
// "2026-02-31", y una entrega de mañana todavía no ocurrió.
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $mf) || !checkdate((int)$mf[2], (int)$mf[3], (int)$mf[1])) {
    $errores[] = 'la fecha no es válida';
} elseif ($fecha > date('Y-m-d')) {
    $errores[] = 'la fecha es posterior a hoy';
}

/* El lote y el cultivo tienen que ser del usuario de la sesión, y el cultivo tiene
   que ser DE ESE LOTE: una entrega imputada a una combinación que no existe en el
   campo ensucia el rinde del lote y el ingreso de la campaña a la vez. */
$st = $pdo->prepare("SELECT nombre FROM lotes WHERE id = ? AND usuario_id = ?");
$st->execute([$lote_id, $usuario_id]);
$nombreLote = $st->fetchColumn() ?: null;
if (!$nombreLote) $errores[] = 'el lote no existe o no es tuyo';

$st = $pdo->prepare("SELECT nombre FROM cultivos WHERE id = ? AND usuario_id = ? AND lote_id = ?");
$st->execute([$cultivo_id, $usuario_id, $lote_id]);
$nombreCultivo = $st->fetchColumn() ?: null;
if (!$nombreCultivo) $errores[] = 'ese cultivo no existe o no es de ese lote';

if ($errores) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'msg' => 'No pude guardarlo: ' . implode(', ', $errores) . '.'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /* Mismas columnas que produccion.php. cultivo_vendido guarda el nombre como
       instantánea —el formulario hace lo mismo—: si mañana el cultivo se renombra,
       la venta sigue diciendo qué se entregó. */
    $stmt = $pdo->prepare(
        "INSERT INTO produccion_ventas
            (lote_id, cultivo_id, kg_cosechados, precio_kg, moneda, fecha_venta,
             usuario_id, campania_vendida, cultivo_vendido, notas)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$lote_id, $cultivo_id, $kg, $precio, $moneda, $fecha,
                    $usuario_id, $campania, $nombreCultivo, 'Cargado desde el chat']);

    // Se relee lo que calculó la base, en vez de repetir la multiplicación acá.
    $st = $pdo->prepare("SELECT ingreso_total FROM produccion_ventas WHERE id = ?");
    $st->execute([$id = (int)$pdo->lastInsertId()]);
    $ingreso = (float)$st->fetchColumn();

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'msg' => 'Listo, quedó registrada la entrega de ' . number_format($kg, 2, ',', '.')
               . ' kg de ' . $nombreCultivo . ' en ' . $nombreLote
               . ': $' . number_format($ingreso, 2, ',', '.') . '.',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[motor/registrar_venta] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar la entrega. No quedó nada a medias; probá de nuevo.'],
                     JSON_UNESCAPED_UNICODE);
}
