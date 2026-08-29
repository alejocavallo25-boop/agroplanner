<?php
/**
 * api/registrar_insumo.php
 *
 * Da de alta un insumo en el catálogo, propuesto por el chat.
 *
 * Va aparte de api/registrar.php a propósito: no comparten ni un campo ni una
 * validación. Aquello guarda plata que salió contra un lote y una campaña; esto
 * guarda un producto en una lista. Meterlos en el mismo archivo con un if arriba
 * hacía que cada regla tuviera que aclarar a cuál de las dos altas pertenecía.
 *
 * Lo que sí comparten es el camino: POST, por las mismas dos razones de siempre.
 *
 *   - El bloqueo de cuentas de demostración de config/auth.php es una lista blanca
 *     sobre POST. Al entrar por acá, la demo queda bloqueada sola.
 *   - Hereda la validación de token CSRF por el mismo camino que el resto.
 *
 * Recibe CAMPOS YA CONFIRMADOS, nunca la frase original: el productor aprobó lo
 * que vio en pantalla y acá se guarda exactamente eso, sin volver a interpretar.
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

$nombre = trim((string)($_POST['nombre'] ?? ''));
$tipo   = (string)($_POST['tipo_insumo'] ?? '');
$unidad = (string)($_POST['unidad_medida'] ?? '');
$precio = (float)($_POST['precio_estimado_usd'] ?? 0);
$stock  = (float)($_POST['stock_actual'] ?? 0);

// Los enum de la base, que son los mismos desplegables del formulario de Insumos.
$TIPOS    = ['semilla','fertilizante','agroquimico','inoculante','otro'];
$UNIDADES = ['kg','lt','dosis','bolsa'];

$errores = [];
if ($nombre === '')                        $errores[] = 'falta el nombre';
if (mb_strlen($nombre) > 150)              $errores[] = 'el nombre es demasiado largo';
if (!in_array($tipo, $TIPOS, true))        $errores[] = 'el tipo no es válido';
if (!in_array($unidad, $UNIDADES, true))   $errores[] = 'la unidad no es válida';
/* El precio es en dólares y por unidad. Un cero o un negativo no es un precio, y
   sin precio el insumo no sirve para calcular ningún costo: es obligatorio en el
   formulario por la misma razón. */
if ($precio <= 0)                          $errores[] = 'el precio tiene que ser mayor a cero';
if ($stock < 0)                            $errores[] = 'el stock no puede ser negativo';

if ($errores) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'msg' => 'No pude guardarlo: ' . implode(', ', $errores) . '.'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /* Mismas columnas que el alta del formulario (insumos.php, acción 'add'). Las
       opcionales que el chat no pregunta —depósito, stock mínimo, vencimiento,
       unidad de stock— quedan en NULL, que es lo que deja el formulario cuando el
       productor no las completa. Se pueden llenar después desde Insumos. */
    $stmt = $pdo->prepare(
        "INSERT INTO insumos
            (usuario_id, nombre, tipo_insumo, unidad_medida, precio_estimado_usd,
             stock_actual, unidad_stock, fecha_vencimiento, deposito_id, stock_minimo, estado)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, 'activo')"
    );
    $stmt->execute([$usuario_id, $nombre, $tipo, $unidad, $precio, $stock]);

    echo json_encode([
        'ok' => true,
        'id' => (int)$pdo->lastInsertId(),
        'msg' => 'Listo, ' . $nombre . ' quedó en el catálogo de insumos.',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[motor/registrar_insumo] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar el insumo. No quedó nada a medias; probá de nuevo.'],
                     JSON_UNESCAPED_UNICODE);
}
