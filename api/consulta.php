<?php
/**
 * api/consulta.php
 *
 * Puerta del motor de consultas. Recibe una pregunta en castellano y devuelve
 * la respuesta ya armada.
 *
 * Todo el trabajo está en includes/motor.php; acá sólo se resuelven la sesión,
 * el usuario y el formato. La consulta se calcula SIEMPRE contra el usuario de
 * la sesión: el motor nunca recibe un id de usuario desde el cliente.
 *
 * Es GET y de sólo lectura, así que no lleva token CSRF (no muta nada). Sí exige
 * sesión con permiso de agricultura, igual que el panel.
 */
require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';
require_once '../includes/motor.php';

header('Content-Type: application/json; charset=utf-8');

$usuario_id = (int)$_SESSION['usuario_id'];
$pregunta   = (string)($_GET['q'] ?? '');

// Un techo razonable: nadie pregunta un costo por hectárea en 500 caracteres, y
// evita que alguien mande un texto enorme a la comparación difusa.
if (mb_strlen($pregunta) > 300) {
    $pregunta = mb_substr($pregunta, 0, 300);
}

// El contexto es la memoria de la charla: qué campaña, lote y cultivo venía
// mirando, para que "¿y el margen?" se entienda sin repetir el filtro.
//
// Viaja desde el cliente y por eso NO se confía: el motor sólo lo acepta si el
// lote y el cultivo pertenecen de verdad a la campaña resuelta, y todo se
// consulta contra el usuario de la sesión. En el peor caso el contexto se ignora.
$contexto = [];
if (!empty($_GET['ciclo']))   $contexto['ciclo']   = (string)$_GET['ciclo'];
if (!empty($_GET['lote']))    $contexto['lote']    = (int)$_GET['lote'];
if (!empty($_GET['cultivo'])) $contexto['cultivo'] = (string)$_GET['cultivo'];
// De qué número veníamos hablando, para que "¿y en total?" retome el mismo.
if (!empty($_GET['metrica'])) $contexto['metrica'] = (string)$_GET['metrica'];

/* La moneda en que está mirando el panel. El chat contesta en la misma: si el
   panel dice US$583 y el chat dice $845.000, son dos números para lo mismo y el
   productor no tiene forma de saber cuál mirar. No cambia ningún dato — sólo en
   qué moneda se expresan los totales. */
$moneda = (($_GET['moneda'] ?? 'ARS') === 'USD') ? 'USD' : 'ARS';
motor_moneda($moneda);
$controller_moneda = $moneda;

/* Carga guiada a medio completar. El estado vive en el cliente y vuelve en cada
   vuelta: el servidor queda sin sesiones colgadas si alguien cierra el chat.
 *
 * Como todo lo que llega de afuera, no se confía: son datos para rellenar los
 * casilleros, y el guardado final los vuelve a validar contra los enum de la
 * base y contra los lotes del usuario. */
if (!empty($_GET['alta'])) {
    $a = json_decode((string)$_GET['alta'], true);
    if (is_array($a)) {
        $limpio = [];
        // Qué se está cargando: un gasto de una operación o un insumo del catálogo.
        if (isset($a['que'])) {
            $limpio['que'] = in_array($a['que'], ['insumo','alquiler','venta'], true) ? $a['que'] : 'gasto';
        }

        // ── Casilleros de la entrega vendida ──
        // El ingreso no viaja: lo calcula la base al guardar (columna generada).
        if (isset($a['moneda']))      $limpio['moneda']      = $a['moneda'] === 'USD' ? 'USD' : 'ARS';
        if (isset($a['kg']))          $limpio['kg']          = (float)$a['kg'];
        if (isset($a['precio_kg']))   $limpio['precio_kg']   = (float)$a['precio_kg'];
        if (isset($a['fecha_venta'])) $limpio['fecha_venta'] = (string)$a['fecha_venta'];

        // ── Casilleros del pago de alquiler ──
        if (isset($a['nivel_imputacion'])) $limpio['nivel_imputacion'] = (string)$a['nivel_imputacion'];
        if (isset($a['lote_id']))          $limpio['lote_id']          = (int)$a['lote_id'];
        if (isset($a['cultivo_id']))       $limpio['cultivo_id']       = (int)$a['cultivo_id'];
        if (isset($a['cultivo_nombre']))   $limpio['cultivo_nombre']   = mb_substr(trim((string)$a['cultivo_nombre']), 0, 100);
        if (isset($a['campania']))         $limpio['campania']         = mb_substr(trim((string)$a['campania']), 0, 50);
        if (isset($a['monto_pagado']))     $limpio['monto_pagado']     = (float)$a['monto_pagado'];
        if (isset($a['fecha_pago']))       $limpio['fecha_pago']       = (string)$a['fecha_pago'];

        // ── Casilleros del insumo del catálogo ──
        if (isset($a['nombre']))              $limpio['nombre']              = mb_substr(trim((string)$a['nombre']), 0, 150);
        if (isset($a['tipo_insumo']))         $limpio['tipo_insumo']         = (string)$a['tipo_insumo'];
        if (isset($a['unidad_medida']))       $limpio['unidad_medida']       = (string)$a['unidad_medida'];
        if (isset($a['precio_estimado_usd'])) $limpio['precio_estimado_usd'] = (float)$a['precio_estimado_usd'];
        if (isset($a['stock_actual']))        $limpio['stock_actual']        = (float)$a['stock_actual'];

        // ── Casilleros del gasto ──
        if (isset($a['grupo_gasto']))     $limpio['grupo_gasto']     = (string)$a['grupo_gasto'];
        if (isset($a['tipo_componente'])) $limpio['tipo_componente'] = (string)$a['tipo_componente'];
        // El nombre del insumo lo escribe el productor, así que se le pone techo
        // acá igual que en el guardado: es texto libre y viaja por la URL.
        if (isset($a['insumo_nombre']))   $limpio['insumo_nombre']   = mb_substr(trim((string)$a['insumo_nombre']), 0, 150);
        // El id se revalida contra los insumos del usuario al guardar; acá sólo se
        // le da forma. insumo_libre es la marca de que eligió escribirlo a mano.
        if (isset($a['insumo_id']))       $limpio['insumo_id']       = (int)$a['insumo_id'];
        if (isset($a['insumo_unidad']))   $limpio['insumo_unidad']   = (string)$a['insumo_unidad'];
        if (isset($a['insumo_cantidad'])) $limpio['insumo_cantidad'] = (float)$a['insumo_cantidad'];
        if (isset($a['insumo_libre']))    $limpio['insumo_libre']    = 1;
        /* Los lotes elegidos, que pueden ser varios. Se recorta la lista a un
           máximo razonable: nadie tiene doscientos lotes, y sin techo esto es una
           lista de ids que llega de afuera y termina en un IN de la consulta. */
        if (isset($a['lotes']) && is_array($a['lotes'])) {
            $limpio['lotes'] = array_values(array_unique(array_filter(
                array_map('intval', array_slice($a['lotes'], 0, 200)),
                fn($id) => $id > 0
            )));
        }
        if (isset($a['reparto']))         $limpio['reparto']         = $a['reparto'] === 'hectarea' ? 'hectarea' : 'total';
        if (isset($a['costo_total']))     $limpio['costo_total']     = (float)$a['costo_total'];
        if (isset($a['fecha']))           $limpio['fecha']           = (string)$a['fecha'];
        $contexto['alta'] = $limpio;
    }
}

try {
    $r = motor_responder($pdo, $usuario_id, $pregunta, $contexto);

    // Lo que no supo contestar se anota, para saber qué agregar después con
    // datos en vez de con intuición. No afecta la respuesta que ya se calculó.
    if (($r['tipo'] ?? '') === 'sin_entender' && trim($pregunta) !== '') {
        motor_registrar_fallo($pdo, $usuario_id, $pregunta, $r['respuesta'] ?? '');
    }

    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // El detalle técnico va al log, no a la pantalla del productor.
    error_log('[motor] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'tipo' => 'error',
        'respuesta' => 'No pude resolver la consulta en este momento.',
        'detalle' => 'Probá de nuevo en un momento.',
        'valor' => null, 'filtros' => [], 'link' => null, 'sugerencias' => [],
    ], JSON_UNESCAPED_UNICODE);
}
