<?php
/**
 * La lectura con IA de la foto de un remito.
 *
 * Lo que importa probar es la IDA Y VUELTA: lo que devuelve el modelo se pasa a
 * renglones de texto, y esos renglones los tiene que leer el parser de siempre
 * con los mismos números. Si el formato de imp_ia_a_texto() y lo que espera
 * imp_linea_a_item() se separan, la IA leería bien y el importador cargaría mal.
 */

require_once __DIR__ . '/../includes/importador_insumos.php';
require_once __DIR__ . '/../includes/ia.php';

/** Texto → ítems, por el mismo camino que el cuadro de la pantalla. */
function ia_items(string $texto): array
{
    return imp_procesar('texto', $texto, [])['items'];
}

grupo('IA: de la respuesta del modelo al texto del cuadro');

$r = imp_ia_a_texto([
    'es_remito' => true, 'moneda' => 'ARS',
    'renglones' => [
        ['cantidad' => 2500, 'unidad' => 'kg', 'descripcion' => 'Urea granulada', 'precio_unitario' => 1200, 'importe' => 3000000, 'vencimiento' => null],
        ['cantidad' => 800,  'unidad' => 'litros', 'descripcion' => 'Glifosato 62%', 'precio_unitario' => 5000, 'importe' => null, 'vencimiento' => '2029-06-30'],
    ],
    'total' => 7000000,
]);
es('dos renglones', 2, $r['renglones']);
es('moneda', 'ARS', $r['moneda']);
es('texto armado',
   "2500 kg Urea granulada 1.200,00\n800 lt Glifosato 62% 30/06/2029 5.000,00\nTOTAL 7.000.000,00",
   $r['texto']);

$items = ia_items($r['texto']);
es('el parser saca los dos', 2, count($items));
es('cantidad grande sin separador', 2500.0, $items[0]['cantidad']);
es('precio de miles', 1200.0, $items[0]['precio']);
es('nombre limpio', 'Urea granulada', $items[0]['nombre']);
es('vencimiento separado del nombre', '2029-06-30', $items[1]['vencimiento']);
es('nombre sin la fecha', 'Glifosato 62%', $items[1]['nombre']);

grupo('IA: casos del papel');

// Sólo el importe del renglón: el unitario sale de dividir.
$r = imp_ia_a_texto(['renglones' => [
    ['cantidad' => 4, 'unidad' => 'bidon', 'descripcion' => 'Atrazina 90', 'precio_unitario' => null, 'importe' => 1000],
]]);
$items = ia_items($r['texto']);
es('unitario desde el importe', 250.0, $items[0]['precio']);
es('bidón en la palabra del parser', 4.0, $items[0]['cantidad']);

// Cantidad con decimales y un nombre que arranca con número.
$r = imp_ia_a_texto(['renglones' => [
    ['cantidad' => 2.5, 'unidad' => 'lt', 'descripcion' => '2,4-D Amina', 'precio_unitario' => 6.8],
]]);
$items = ia_items($r['texto']);
es('cantidad decimal', 2.5, $items[0]['cantidad']);
es('precio con centavos', 6.8, $items[0]['precio']);
es('nombre que empieza con número', '2,4-D Amina', $items[0]['nombre']);

// El remito de prueba real (Grupo AgroApp): lote y vencimiento, sin precio.
$r = imp_ia_a_texto(['renglones' => [
    ['cantidad' => 1, 'unidad' => null, 'descripcion' => 'FOSFURO DE ALUMINIO TECNOFOS x BOTELLA',
     'precio_unitario' => null, 'importe' => null, 'lote' => 'FV00ACR009', 'vencimiento' => '2029-06-30'],
]]);
$items = ia_items($r['texto']);
es('lote al campo referencia', 'FV00ACR009', $items[0]['referencia']);
es('vencimiento con lote', '2029-06-30', $items[0]['vencimiento']);
es('nombre sin lote ni fecha', 'FOSFURO DE ALUMINIO TECNOFOS x BOTELLA', $items[0]['nombre']);

// Un lote que el parser no reconocería se deja afuera antes que ensuciar el nombre.
$r = imp_ia_a_texto(['renglones' => [
    ['cantidad' => 2, 'descripcion' => 'Semilla de soja', 'lote' => 'abc', 'precio_unitario' => null],
]]);
es('lote raro no entra', '2 Semilla de soja', $r['texto']);

// Números que llegan como texto a la argentina.
$r = imp_ia_a_texto(['renglones' => [
    ['cantidad' => '10', 'descripcion' => 'Fosfuro de aluminio', 'precio_unitario' => '45.300,50'],
]]);
es('precio en texto argentino', '10 Fosfuro de aluminio 45.300,50', $r['texto']);

// Lo que no sirve se descarta, no se carga con ceros.
$r = imp_ia_a_texto(['renglones' => [
    ['cantidad' => null, 'descripcion' => 'Algo ilegible', 'precio_unitario' => 10],
    ['cantidad' => 3,    'descripcion' => '',              'precio_unitario' => 10],
    ['cantidad' => -2,   'descripcion' => 'Negativo raro', 'precio_unitario' => 10],
    'basura',
    ['cantidad' => 1,    'descripcion' => "Semilla\nsoja | DM", 'precio_unitario' => null],
]]);
es('queda sólo el renglón sano', 1, $r['renglones']);
es('sin saltos ni bordes', '1 Semilla soja DM', $r['texto']);
es('sin renglones no hay TOTAL', '', imp_ia_a_texto(['renglones' => [], 'total' => 100])['texto']);

grupo('IA: respuestas que no son remito o no tienen forma');

es('no es remito', false, imp_ia_a_texto(['es_remito' => false])['es_remito']);
es('sin renglones es inválida', 'null', json_encode(imp_ia_a_texto(['moneda' => 'ARS'])));
es('moneda desconocida queda null', null, imp_ia_a_texto(['renglones' => [], 'moneda' => 'EUR'])['moneda']);

// es() compara como texto y un array es siempre "Array": las listas van en JSON.
es('JSON envuelto en ```json',  '{"a":1}', json_encode(ia_extraer_json("```json\n{\"a\": 1}\n```")));
es('JSON con frase adelante',    '{"a":1}', json_encode(ia_extraer_json('Acá está: {"a": 1}')));
es('sin JSON da null',           'null',    json_encode(ia_extraer_json('No puedo leer la imagen.')));
es('JSON roto da null',          'null',    json_encode(ia_extraer_json('{"a": 1,,}')));

grupo('IA: voz');

// Whisper ante el silencio completa con el cierre de un video subtitulado.
esVerdad('silencio: subtítulos de Amara',  ia_voz_es_alucinacion('Subtítulos realizados por la comunidad de Amara.org'));
esVerdad('silencio: gracias por ver',      ia_voz_es_alucinacion('¡Gracias por ver el video!'));
esVerdad('silencio: vacío',                ia_voz_es_alucinacion(' . '));
esVerdad('un dictado de verdad pasa',     !ia_voz_es_alucinacion('Gasté 500 dólares en gasoil en el lote La Esperanza'));
// Medido: tres segundos de silencio devolvieron un pedazo de la pista.
$pista = 'Productor agropecuario de Argentina. Lotes: La Esperanza, El Ombú, Lote 12. Insumos: Urea.';
esVerdad('silencio: repite la pista',      ia_voz_es_alucinacion('Lotes, El Ombú, Lote 12. Insumos.', $pista));
esVerdad('lo dicho con nombres de la pista pasa',
         !ia_voz_es_alucinacion('Cuánto gasté en el lote La Esperanza', $pista));
esVerdad('silencio: repite la pista tal cual', ia_voz_es_alucinacion('Lote 12', $pista));
esVerdad('las mismas palabras en otro orden pasan',
         !ia_voz_es_alucinacion('Urea en Lote 12', $pista));
esVerdad('voz sin clave de Groq',         !ia_voz_disponible(['IA_GEMINI_CLAVE' => 'x']));
esVerdad('voz con clave de Groq',          ia_voz_disponible(['IA_GROQ_CLAVE' => 'g']));

grupo('IA: proveedores');

es('sin claves no hay ninguno', '[]', json_encode(ia_proveedores([])));
es('OpenRouter sin modelo usa uno fijo que lee imágenes', 'nvidia/nemotron-3.5-super-vl:free',
   ia_proveedores(['IA_OPENROUTER_CLAVE' => 'x', 'IA_OPENROUTER_MODELO' => ''])[0]['modelo']);
es('orden: Gemini, su versión liviana y Groq', '["gemini","gemini-lite","groq"]',
   json_encode(array_column(ia_proveedores(['IA_GROQ_CLAVE' => 'g', 'IA_GEMINI_CLAVE' => 'm']), 'nombre')));
