<?php
/**
 * api/presupuesto_chat.php
 *
 * El chat recibe los Excel de presupuesto que devolvieron los proveedores, los
 * guarda en su pedido —reconocido por el número PP-… que trae cada archivo— y
 * contesta con la comparación. La lectura y la cuenta son las mismas que usa la
 * pantalla (includes/presupuestos.php): el chat no calcula nada por su lado.
 *
 * No necesita IA: leer un Excel es leer celdas.
 * Contesta con la forma de las respuestas del motor (ok, respuesta, detalle,
 * link), así el chat lo pinta como cualquier otra.
 */

require_once '../config/auth.php';
require_once '../config/database.php';
require_once '../includes/presupuestos.php';
require_once '../includes/dolar.php';

header('Content-Type: application/json; charset=utf-8');

function ppc_salir(array $r, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ppc_salir(['ok' => false, 'respuesta' => 'Método no permitido.'], 405);
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    ppc_salir(['ok' => false, 'respuesta' => 'Se venció la sesión del chat.', 'detalle' => 'Recargá la página y mandame los archivos de nuevo.'], 403);
}
$usuario_id = (int)$_SESSION['usuario_id'];
if (!pp_tablas_listas($pdo)) {
    ppc_salir(['ok' => false, 'respuesta' => 'Todavía no puedo comparar presupuestos.', 'detalle' => 'Falta actualizar la base de datos de la app.']);
}

$archivos = $_FILES['archivos'] ?? null;
$n = $archivos ? count((array)$archivos['name']) : 0;
if (!$n) ppc_salir(['ok' => false, 'respuesta' => 'No me llegó ningún archivo.']);

$pedidos = [];   // id => pedido, en el orden en que aparecen
$leidos = []; $fallas = [];
for ($i = 0; $i < min($n, 15); $i++) {
    $nombre = basename((string)$archivos['name'][$i]);
    if ($archivos['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($archivos['tmp_name'][$i])) { $fallas[] = "$nombre no se pudo subir"; continue; }
    if ($archivos['size'][$i] > 8 * 1024 * 1024) { $fallas[] = "$nombre pesa más de 8 MB"; continue; }
    try {
        $r = pp_cargar_respuesta($pdo, $usuario_id, $archivos['tmp_name'][$i], $nombre, null);
        $pedidos[(int)$r['pedido']['id']] = $r['pedido'];
        $leidos[] = $r['proveedor'] . ($r['reemplazo'] ? ' (reemplacé la que tenía)' : '');
    } catch (RuntimeException $e) {
        $fallas[] = "$nombre " . $e->getMessage();
    } catch (Throwable $e) {
        error_log('[presupuesto_chat] ' . $e->getMessage());
        $fallas[] = "$nombre no se pudo leer";
    }
}

// Los motivos ya traen su punto final: se sacan para no terminar en "..".
$fallas = array_map(fn($f) => rtrim($f, '. '), $fallas);
$aviso_fallas = $fallas ? ' No pude con: ' . implode('; ', $fallas) . '.' : '';

if (!$pedidos) {
    ppc_salir([
        'ok' => false,
        'respuesta' => $n === 1 ? 'No pude leer ese archivo como presupuesto.' : 'No pude leer ninguno de esos archivos como presupuesto.',
        'detalle' => trim(implode('; ', $fallas)) . '. Leo los Excel que bajás desde Presupuestos y te devuelven completados.',
        'link' => 'presupuestos.php', 'link_texto' => 'Ir a Presupuestos', 'link_icono' => 'fa-scale-balanced',
    ]);
}

// La comparación del pedido (o del último, si vinieron de varios).
dolar_asegurar_tabla($pdo);
$cotizacion = (float)dolar_referencia($pdo, $usuario_id)['valor'];
$pedido = end($pedidos);
$items = pp_items($pdo, (int)$pedido['id']);
$ofertas = pp_ofertas($pdo, (int)$pedido['id']);
$cmp = pp_comparar($items, $ofertas, pp_precios($pdo, $ofertas), $cotizacion);
$res = pp_resumen_chat($pedido, $items, $ofertas, $cmp, $cotizacion);

$cargadas = 'Guardé ' . (count($leidos) === 1 ? 'la oferta de ' : 'las ofertas de ') . implode(', ', $leidos) . '. ';
$otros = count($pedidos) > 1 ? ' Algunos archivos eran de otros pedidos: los guardé en cada uno.' : '';

ppc_salir([
    'ok' => true,
    'respuesta' => $cargadas . $res['respuesta'],
    'detalle' => trim($res['detalle'] . $otros . $aviso_fallas),
    'link' => 'presupuestos.php?id=' . (int)$pedido['id'],
    'link_texto' => 'Ver la comparación completa',
    'link_icono' => 'fa-scale-balanced',
]);
