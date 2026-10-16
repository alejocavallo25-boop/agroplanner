<?php
/**
 * El diagnóstico de plagas y enfermedades por foto.
 *
 * Lo más importante que se prueba acá no es el formato sino la regla: NUNCA
 * sale una dosis, aunque el modelo la mande, y el aviso de consultar al
 * agrónomo está siempre que hay algo para aplicar. Eso no puede depender de
 * que el modelo obedezca las instrucciones.
 */

require_once __DIR__ . '/../includes/diagnostico_ia.php';
require_once __DIR__ . '/../includes/motor.php';

grupo('Diagnóstico: las dosis no pasan');

esVerdad('cc por hectárea',        dx_tiene_dosis('Aplicar 300 cc/ha'));
esVerdad('litros con decimales',    dx_tiene_dosis('1,5 l por hectárea'));
esVerdad('gramos',                  dx_tiene_dosis('20 g de producto'));
esVerdad('2,4-D no es una dosis',  !dx_tiene_dosis('2,4-D'));
esVerdad('un porcentaje no lo es', !dx_tiene_dosis('Glifosato 62%'));
esVerdad('un umbral no lo es',     !dx_tiene_dosis('20 isocas por metro lineal'));

$r = dx_a_respuesta([
    'es_planta' => true, 'cultivo' => 'soja',
    'diagnostico' => 'Oruga bolillera (Helicoverpa gelotopoeon)', 'tipo' => 'plaga', 'confianza' => 'media',
    'senales' => ['Perforaciones en vainas'],
    'manejo' => ['Monitorear con paño vertical', 'Aplicar clorantraniliprol a 40 cc/ha'],
    'principios_activos' => ['Clorantraniliprol', 'Spinetoram 60 ml/ha'],
    'umbral' => 'Aplicar 200 cc/ha cuando haya daño',
]);
esVerdad('el renglón con dosis del manejo se tira', strpos($r['detalle'], '40 cc') === false);
esVerdad('el manejo sin dosis queda', strpos($r['detalle'], 'paño vertical') !== false);
esVerdad('el activo sin dosis queda', strpos($r['detalle'], 'Clorantraniliprol') !== false);
esVerdad('el activo con dosis se tira', strpos($r['detalle'], 'Spinetoram') === false);
esVerdad('el umbral con dosis se tira', strpos($r['detalle'], '200 cc') === false);
esVerdad('siempre el aviso del agrónomo', strpos($r['detalle'], 'ingeniero agrónomo') !== false);
es('la frase principal', 'Parece Oruga bolillera (Helicoverpa gelotopoeon) (plaga, en soja). Confianza media.', $r['respuesta']);

grupo('Diagnóstico: los otros casos');

$r = dx_a_respuesta(['es_planta' => false, 'pedir_otra_foto' => null]);
es('no es una planta', 'No encontré una planta ni un bicho en la foto.', $r['respuesta']);

$r = dx_a_respuesta(['es_planta' => true, 'tipo' => 'benefico', 'diagnostico' => 'Vaquita (Eriopis connexa)', 'principios_activos' => ['Cipermetrina']]);
esVerdad('benéfico: dice que es un aliado', strpos($r['respuesta'], 'aliado') !== false);
esVerdad('benéfico: no ofrece con qué matarlo', strpos($r['detalle'], 'Cipermetrina') === false);
esVerdad('benéfico: avisa que la aplicación lo mata', strpos($r['detalle'], 'no hace falta controlarlo') !== false);

$r = dx_a_respuesta(['es_planta' => true, 'tipo' => 'sano', 'diagnostico' => null]);
es('sano', 'La planta se ve sana.', $r['respuesta']);
esVerdad('sano: sin aviso de aplicar', strpos($r['detalle'], 'agrónomo') === false);

$r = dx_a_respuesta(['es_planta' => true, 'tipo' => 'incierto', 'diagnostico' => null,
                     'confusiones' => ['Roya', 'Mancha marrón'], 'pedir_otra_foto' => 'Una del envés de la hoja']);
es('incierto', 'No me animo a decir qué es con esta foto.', $r['respuesta']);
esVerdad('incierto: muestra con qué se confunde', strpos($r['detalle'], 'Mancha marrón') !== false);
esVerdad('incierto: pide otra foto', strpos($r['detalle'], 'envés') !== false);

// Tipo desconocido o sin diagnóstico: no se inventa uno.
$r = dx_a_respuesta(['es_planta' => true, 'tipo' => 'virus raro', 'diagnostico' => 'Algo']);
es('tipo desconocido es incierto', 'No me animo a decir qué es con esta foto.', $r['respuesta']);

// Confianza fuera de la lista: se toma la más prudente.
$r = dx_a_respuesta(['es_planta' => true, 'tipo' => 'enfermedad', 'diagnostico' => 'Roya asiática', 'confianza' => 'altísima']);
esVerdad('confianza desconocida queda baja', strpos($r['respuesta'], 'Confianza baja') !== false);

es('sin forma es inválida', 'null', json_encode(dx_a_respuesta(['hola' => 1])));

grupo('Foto del chat: factura de gasto → frase para el motor');

/* La ida y vuelta: la frase la tiene que entender el motor con el mismo monto,
   la misma moneda y la misma fecha. Si el formato de dx_frase_gasto() y lo que
   lee el motor se separan, la IA leería bien y se cargaría otro número. */
$vuelta = function (array $factura) {
    $f = dx_frase_gasto($factura);
    $t = motor_normalizar($f);
    return [$f, motor_pide_alta($t), motor_detectar_monto($f), motor_moneda_dicha($t, $f), motor_detectar_fecha($f)];
};

[$f, $alta, $monto, $mon, $fecha] = $vuelta(['clase' => 'gasto', 'concepto' => 'Gasoil', 'total' => 850000,
                                             'moneda' => 'ARS', 'fecha' => '2026-08-14']);
es('la frase', 'Gasté 850.000,00 pesos en gasoil el 14/08/2026', $f);
esVerdad('el motor la toma como carga', $alta);
es('mismo monto',  850000.0,     $monto);
es('misma moneda', 'ARS',        $mon);
es('misma fecha',  '2026-08-14', $fecha);

// Un monto chico: sin centavos, el motor no lo tomaría (lo confunde con una cantidad).
[$f, , $monto, $mon] = $vuelta(['clase' => 'gasto', 'concepto' => 'Flete', 'total' => 850, 'moneda' => 'USD']);
es('monto chico con centavos', 'Gasté 850,00 dólares en flete', $f);
es('monto chico: lo lee', 850.0, $monto);
es('en dólares', 'USD', $mon);

// "gasoil 500 lts": el 500 le daría al motor una dosis y lo leería como un insumo.
[$f, , $monto] = $vuelta(['clase' => 'gasto', 'concepto' => 'Gasoil 500 lts', 'total' => 425000.5]);
es('sin la cantidad en el concepto', 'Gasté 425.000,50 en gasoil', $f);
es('monto con centavos', 425000.5, $monto);

// Una fecha adelante no se manda: el motor la rechazaría.
[$f] = $vuelta(['clase' => 'gasto', 'concepto' => 'Flete', 'total' => 1000, 'fecha' => '2099-01-01']);
es('fecha futura afuera', 'Gasté 1.000,00 en flete', $f);

es('sin total no hay frase',    'null', json_encode(dx_frase_gasto(['concepto' => 'Flete'])));
es('sin concepto no hay frase', 'null', json_encode(dx_frase_gasto(['total' => 1000, 'concepto' => '500'])));

$r = dx_a_respuesta_foto(['clase' => 'gasto', 'concepto' => 'gasoil', 'total' => 850000, 'moneda' => 'ARS',
                          'proveedor' => 'YPF Agro'], true, true);
es('factura: lo dice', 'Es una factura de YPF Agro: 850.000,00 pesos en gasoil. ¿Lo cargo como gasto?', $r['respuesta']);
es('factura: la frase como sugerencia', 'Gasté 850.000,00 pesos en gasoil', $r['sugerencias'][0]);
$r = dx_a_respuesta_foto(['clase' => 'gasto', 'concepto' => 'gasoil', 'total' => 850000], true, false);
esVerdad('desde el tambo: sin frase para tocar', empty($r['sugerencias']));

grupo('Foto del chat: planta, remito u otra cosa');

$remito = ['clase' => 'remito', 'es_remito' => true, 'proveedor' => 'Grupo AgroApp', 'moneda' => null,
           'renglones' => [['cantidad' => 1, 'descripcion' => 'FOSFURO DE ALUMINIO TECNOFOS x BOTELLA',
                            'lote' => 'FV00ACR009', 'vencimiento' => '2029-06-30', 'precio_unitario' => null]],
           'total' => null];

$r = dx_a_respuesta_foto($remito, true);
es('remito: lo dice', 'Es un remito de Grupo AgroApp con 1 renglón. ¿Lo cargo en el stock?', $r['respuesta']);
es('remito: lleva a Insumos', 'insumos.php?importar=ia', $r['link']);
es('remito: el texto para el importador',
   '1 FOSFURO DE ALUMINIO TECNOFOS x BOTELLA FV00ACR009 30/06/2029', $r['remito']['texto']);
esVerdad('remito: nunca el aviso de aplicar', strpos($r['detalle'], 'agrónomo') === false);

// Sin Agricultura no hay Insumos: no se ofrece un enlace que no puede abrir.
$r = dx_a_respuesta_foto($remito, false);
esVerdad('sin Agricultura: sin enlace', !isset($r['link']) && !isset($r['remito']));
esVerdad('sin Agricultura: dice dónde está', strpos($r['detalle'], 'Agricultura') !== false);

// Remito sin renglones legibles: que pruebe otro proveedor.
es('remito sin renglones es inválido', 'null',
   json_encode(dx_a_respuesta_foto(['clase' => 'remito', 'renglones' => []], true)));

es('otra cosa', 'No reconozco qué hay en la foto.', dx_a_respuesta_foto(['clase' => 'otro'], true)['respuesta']);

// La planta sigue yendo al diagnóstico, con o sin "clase".
$r = dx_a_respuesta_foto(['clase' => 'planta', 'es_planta' => true, 'tipo' => 'enfermedad',
                          'diagnostico' => 'Roya asiática', 'confianza' => 'media'], true);
esVerdad('planta: diagnóstico', strpos($r['respuesta'], 'Roya asiática') !== false);
esVerdad('planta: sin enlace a Insumos', !isset($r['link']));
esVerdad('sin "clase" también es planta',
         strpos(dx_a_respuesta_foto(['es_planta' => true, 'tipo' => 'sano'], true)['respuesta'], 'sana') !== false);
