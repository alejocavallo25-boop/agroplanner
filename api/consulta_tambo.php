<?php
/**
 * api/consulta_tambo.php
 *
 * Puerta del chat en las pantallas del tambo. Hermana de api/consulta.php: GET,
 * sólo lectura, sin CSRF. Exige el módulo de tambo.
 *
 * El trabajo está en includes/motor_tambo.php, y quién contesta —el tambo o, si
 * la pregunta es claramente de agricultura y el productor la tiene, agricultura—
 * lo decide includes/chat_ruteo.php. La consulta se calcula siempre contra el
 * usuario de la sesión.
 */
require_once '../config/auth.php';
require_tambo();
require_once '../config/database.php';
require_once '../includes/chat_ruteo.php';
require_once '../includes/motor_tambo.php';

header('Content-Type: application/json; charset=utf-8');

$usuario_id = (int)$_SESSION['usuario_id'];
$pregunta   = mb_substr((string)($_GET['q'] ?? ''), 0, 300);

/* La memoria de la charla. Viaja desde el cliente, así que no se confía: el mes
   se valida con formato, la métrica contra el catálogo y el rubro contra lo que el
   productor tiene cargado (lo hace el motor). En el peor caso se ignora. */
$contexto = [];
if (!empty($_GET['mes']) && tambo_mes_valido((string)$_GET['mes'])) $contexto['mes'] = (string)$_GET['mes'];
if (!empty($_GET['metrica']))  $contexto['metrica'] = mb_substr((string)$_GET['metrica'], 0, 40);
if (!empty($_GET['rubro']))    $contexto['rubro']   = mb_substr((string)$_GET['rubro'], 0, 220);
if (!empty($_GET['previa']))   $contexto['previa']  = mb_substr((string)$_GET['previa'], 0, 300);

$moneda = (($_GET['moneda'] ?? 'ARS') === 'USD') ? 'USD' : 'ARS';
motor_moneda($moneda);
// Por si la pregunta termina en el motor de agricultura: su controlador lee esto.
$controller_moneda = $moneda;

try {
    $r = chat_responder($pdo, $usuario_id, $pregunta, $contexto, 'tambo');

    $r['filtros'] = is_array($r['filtros'] ?? null) ? $r['filtros'] : [];
    $r['filtros']['moneda'] = motor_moneda();

    if (($r['tipo'] ?? '') === 'sin_entender' && trim($pregunta) !== '') {
        $prefijo = ($r['modulo'] ?? 'tambo') === 'tambo' ? '[tambo] ' : '';
        motor_registrar_fallo($pdo, $usuario_id, $pregunta, $prefijo . ($r['respuesta'] ?? ''));
    }

    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[motor_tambo] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'ok' => false, 'tipo' => 'error', 'modulo' => 'tambo',
        'respuesta' => 'No pude resolver la consulta en este momento.',
        'detalle' => 'Probá de nuevo en un momento.',
        'valor' => null, 'filtros' => [], 'link' => null, 'sugerencias' => [],
    ], JSON_UNESCAPED_UNICODE);
}
