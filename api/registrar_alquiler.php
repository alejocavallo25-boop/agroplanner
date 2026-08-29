<?php
/**
 * api/registrar_alquiler.php
 *
 * Registra un pago de alquiler propuesto por el chat.
 *
 * Va aparte de api/registrar.php por la razón que separa a los alquileres de todo
 * lo demás en esta aplicación: LA MONEDA. Un gasto de operación se guarda en pesos
 * y un alquiler en dólares. Compartir el archivo obligaba a que cada línea aclarara
 * de cuál de las dos monedas estaba hablando, y ese es exactamente el error que no
 * se puede cometer acá: un alquiler es de los números más grandes del año.
 *
 * Va por POST, por las mismas dos razones de siempre:
 *
 *   - El bloqueo de cuentas de demostración de config/auth.php es una lista blanca
 *     sobre POST. Al entrar por acá, la demo queda bloqueada sola.
 *   - Hereda la validación de token CSRF por el mismo camino que el resto.
 *
 * Recibe CAMPOS YA CONFIRMADOS, nunca la frase original.
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

$nivel      = (string)($_POST['nivel_imputacion'] ?? '');
$lote_id    = (int)($_POST['lote_id'] ?? 0);
$cultivo_id = (int)($_POST['cultivo_id'] ?? 0);
$campania   = trim((string)($_POST['campania'] ?? ''));
$monto      = (float)($_POST['monto_pagado'] ?? 0);
$fecha      = (string)($_POST['fecha_pago'] ?? '');

/* Los dos niveles que expone el formulario. El enum de la base acepta un tercero,
   'lote', pero es de una versión anterior: el propio formulario lo traduce a
   'cultivo' al abrir un registro viejo, así que no se crea ninguno nuevo. */
$NIVELES = ['cultivo', 'campania'];

$errores = [];
if (!in_array($nivel, $NIVELES, true)) $errores[] = 'el nivel de imputación no es válido';
if ($monto <= 0)                       $errores[] = 'el monto tiene que ser mayor a cero';
if ($campania === '')                  $errores[] = 'falta la campaña';
if (mb_strlen($campania) > 50)         $errores[] = 'la campaña es demasiado larga';

/* Misma validación de fecha que el gasto: el formato solo deja pasar "2026-02-31",
   y un alquiler pagado mañana no existe. */
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $mf) || !checkdate((int)$mf[2], (int)$mf[3], (int)$mf[1])) {
    $errores[] = 'la fecha no es válida';
} elseif ($fecha > date('Y-m-d')) {
    $errores[] = 'la fecha es posterior a hoy';
}

/* Por cultivo hacen falta el lote Y el cultivo, y los dos tienen que ser del
   usuario de la sesión. El cultivo además tiene que pertenecer a ese lote: si no,
   el pago quedaría imputado a una combinación que no existe en el campo. */
$nombreLote = null;
$nombreCultivo = null;
if ($nivel === 'cultivo') {
    $st = $pdo->prepare("SELECT nombre FROM lotes WHERE id = ? AND usuario_id = ?");
    $st->execute([$lote_id, $usuario_id]);
    $nombreLote = $st->fetchColumn() ?: null;
    if (!$nombreLote) $errores[] = 'el lote no existe o no es tuyo';

    $st = $pdo->prepare("SELECT nombre FROM cultivos WHERE id = ? AND usuario_id = ? AND lote_id = ?");
    $st->execute([$cultivo_id, $usuario_id, $lote_id]);
    $nombreCultivo = $st->fetchColumn() ?: null;
    if (!$nombreCultivo) $errores[] = 'ese cultivo no existe o no es de ese lote';
}

if ($errores) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'msg' => 'No pude guardarlo: ' . implode(', ', $errores) . '.'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /* Mismas columnas y misma normalización que alquileres.php: por campaña, lote y
       cultivo van en NULL. Y moneda siempre 'USD', que es la única opción que
       ofrece el formulario —su selector está oculto con USD fijo—. */
    $stmt = $pdo->prepare(
        "INSERT INTO alquileres
            (usuario_id, lote_id, cultivo_id, nivel_imputacion, campania,
             fecha_pago, monto_pagado, moneda, notas)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'USD', ?)"
    );
    $stmt->execute([
        $usuario_id,
        $nivel === 'cultivo' ? $lote_id : null,
        $nivel === 'cultivo' ? $cultivo_id : null,
        $nivel,
        $campania,
        $fecha,
        $monto,
        'Cargado desde el chat',
    ]);

    $donde = $nivel === 'cultivo'
        ? ' en ' . $nombreLote . ' (' . $nombreCultivo . ')'
        : ' para la campaña ' . $campania;

    echo json_encode([
        'ok' => true,
        'id' => (int)$pdo->lastInsertId(),
        'msg' => 'Listo, quedó registrado el alquiler de USD '
               . number_format($monto, 2, ',', '.') . $donde . '.',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[motor/registrar_alquiler] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar el alquiler. No quedó nada a medias; probá de nuevo.'],
                     JSON_UNESCAPED_UNICODE);
}
