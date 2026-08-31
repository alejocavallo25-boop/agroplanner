<?php
/**
 * api/campo_datos.php
 *
 * Lo que el teléfono necesita tener guardado para poder cargar un gasto parado
 * en el lote, sin señal: los lotes, los insumos del catálogo, la campaña y un
 * token de formulario fresco.
 *
 * Se pide cada vez que hay conexión y se guarda en el teléfono. Sin esto, la
 * pantalla de campo no tendría de dónde sacar la lista de lotes justo cuando no
 * puede preguntarla.
 *
 * Es GET y de sólo lectura: no muta nada, así que no lleva token CSRF. Sí exige
 * sesión con permiso de agricultura, igual que el resto.
 *
 * Va todo contra el usuario de la sesión. El teléfono nunca manda un id de
 * usuario, y lo que se guarda ahí es lo mismo que ya se ve en pantalla.
 */
require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');
// Que no lo cachee ni el navegador ni el service worker: son los datos del campo
// y tienen que llegar frescos cada vez que hay señal.
header('Cache-Control: no-store');

$usuario_id = (int)$_SESSION['usuario_id'];

try {
    /* TODOS los lotes del usuario, no los de una campaña. Para cargar valen
       todos: la misma razón por la que el chat usa motor_lotes_del_usuario() en
       las dos puertas de alta. Estando en el campo, el lote donde uno está parado
       tiene que aparecer aunque este año no tenga nada cargado todavía. */
    $st = $pdo->prepare(
        "SELECT id, nombre, COALESCE(superficie, 0) AS superficie, campania
           FROM lotes WHERE usuario_id = ? ORDER BY nombre"
    );
    $st->execute([$usuario_id]);
    $lotes = array_map(fn($l) => [
        'id'   => (int)$l['id'],
        'nom'  => (string)$l['nombre'],
        'sup'  => (float)$l['superficie'],
    ], $st->fetchAll());

    /* El catálogo de insumos, con el stock. El stock viaja para poder mostrarlo
       —"te quedan 400 kg"— pero NO se descuenta en el teléfono: eso lo hace el
       servidor al guardar, con la misma cuenta de siempre. Descontar de los dos
       lados dejaría el stock corrido sin que nada lo avise. */
    $st = $pdo->prepare(
        "SELECT id, nombre, unidad_medida, COALESCE(stock_actual, 0) AS stock
           FROM insumos WHERE usuario_id = ? AND estado = 'activo' ORDER BY nombre"
    );
    $st->execute([$usuario_id]);
    $insumos = array_map(fn($i) => [
        'id'    => (int)$i['id'],
        'nom'   => (string)$i['nombre'],
        'un'    => (string)$i['unidad_medida'],
        'stock' => (float)$i['stock'],
    ], $st->fetchAll());

    /* La campaña en curso: la más reciente que tenga algo cargado, y si no hay
       nada, la de los lotes. Es la que el formulario va a proponer, y en el campo
       nadie quiere elegirla de una lista cada vez. */
    $st = $pdo->prepare(
        "SELECT campania_operacion AS c FROM operaciones
          WHERE usuario_id = ? AND campania_operacion IS NOT NULL AND campania_operacion <> ''
          ORDER BY fecha DESC LIMIT 1"
    );
    $st->execute([$usuario_id]);
    $campania = $st->fetchColumn() ?: null;
    if (!$campania) {
        $st = $pdo->prepare(
            "SELECT campania FROM lotes
              WHERE usuario_id = ? AND campania IS NOT NULL AND campania <> ''
              ORDER BY campania DESC LIMIT 1"
        );
        $st->execute([$usuario_id]);
        $campania = $st->fetchColumn() ?: null;
    }

    echo json_encode([
        'ok'        => true,
        'lotes'     => $lotes,
        'insumos'   => $insumos,
        'campania'  => $campania,
        // Mismas etiquetas que usa el chat, para que las dos puertas digan igual.
        'grupos'    => [
            ['v' => 'siembra',       't' => 'Siembra'],
            ['v' => 'pulverizacion', 't' => 'Pulverización'],
            ['v' => 'fertilizacion', 't' => 'Fertilización'],
            ['v' => 'cosecha',       't' => 'Cosecha'],
            ['v' => 'otros',         't' => 'Otros gastos'],
        ],
        'csrf'          => get_csrf_token(),
        // La demo puede mirar todo y no guardar nada: la pantalla lo dice de entrada
        // en vez de dejar cargar y fallar recién al sincronizar.
        'solo_lectura'  => es_solo_lectura(),
        'usuario'       => (string)($_SESSION['username'] ?? ''),
        'servidor_hora' => date('c'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[campo_datos] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'No pude traer los datos del campo.'],
                     JSON_UNESCAPED_UNICODE);
}
