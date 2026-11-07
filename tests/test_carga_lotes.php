<?php
/* Carga por lotes: la cuenta de cada gasto, lo que acepta la planilla y la ida y
   vuelta entre lo guardado y la planilla (editar y repetir). Ver includes/carga_lotes.php. */
require_once __DIR__ . '/../includes/carga_lotes.php';

grupo('Carga por lotes · la cuenta de un gasto');
esCerca('labor: precio × ha', 2000, operacion_costo('labor', 20, 100, [], 100));
esCerca('labor: ignora insumos', 2000, operacion_costo('labor', 20, 100, [['c' => 5, 'p' => 10]], 100));
esCerca('insumos: dosis × precio × ha', 1600 + 160, operacion_costo('insumo', 99, 0, [['c' => 2, 'p' => 10], ['c' => 0.5, 'p' => 4]], 80));
esCerca('receta: labor sobre el lote entero, insumos sobre lo aplicado',
        15 * 100 + 2 * 10 * 80, operacion_costo('receta_labor', 15, operacion_ha_labor('receta_labor', 100, 80), [['c' => 2, 'p' => 10]], 80));
esCerca('labor sola: sobre lo trabajado', 80, operacion_ha_labor('labor', 100, 80));

grupo('Carga por lotes · lo que acepta la planilla');
$lotes = [1 => 100.0, 2 => 50.0, 3 => 0.0];
$ins   = [7 => true, 8 => true];
$base = [
    'grupo' => 'siembra', 'tipo' => 'receta_labor', 'moneda' => 'USD', 'fecha' => '2026-10-01',
    'proveedor' => 'Contratista', 'precio' => '20',
    'insumos' => [
        '0' => ['insumo_id' => 7, 'nombre_libre' => '', 'dosis' => '70', 'precio' => '0,5'],
        '3' => ['insumo_id' => '', 'nombre_libre' => 'Inoculante', 'dosis' => '1', 'precio' => '8'],
        '4' => ['insumo_id' => '', 'nombre_libre' => '', 'dosis' => '', 'precio' => ''],  // fila vacía
    ],
    'lotes' => [
        ['id' => 1, 'fecha' => '', 'ha' => '', 'cultivo' => '25/26 | Soja', 'dosis' => []],
        ['id' => 2, 'fecha' => '2026-10-04', 'ha' => '40', 'cultivo' => 'Soja', 'dosis' => ['0' => '80', '3' => '0']],
        ['id' => 9, 'fecha' => '', 'ha' => '', 'cultivo' => '', 'dosis' => []],   // lote ajeno
        ['id' => 3, 'fecha' => '', 'ha' => '', 'cultivo' => '', 'dosis' => []],   // sin superficie
        ['id' => 1, 'fecha' => '', 'ha' => '', 'cultivo' => '', 'dosis' => []],   // repetido
    ],
];
$n = carga_normalizar($base, $lotes, $ins);
es('sin errores', '', implode(' | ', $n['errores']));
es('dos lotes: el ajeno, el sin superficie y el repetido no entran', 2, count($n['destinos']));
$d1 = $n['destinos'][0]; $d2 = $n['destinos'][1];
es('lote sin fecha toma la de arriba', '2026-10-01', $d1['fecha']);
es('lote con fecha propia la conserva', '2026-10-04', $d2['fecha']);
esCerca('sin hectáreas es el lote entero', 100, $d1['ha']);
esCerca('receta: la labor va sobre la superficie aunque se aplique menos', 50, $d2['ha_labor']);
es('campaña y cultivo partidos', '25/26|Soja', $d1['campania'] . '|' . $d1['cultivo']);
es('cultivo sin campaña', '|Soja', $d2['campania'] . '|' . $d2['cultivo']);
es('la fila vacía de insumos no cuenta; el lote 1 lleva los dos', 2, count($d1['insumos']));
esCerca('dosis de arriba', 70, $d1['insumos'][0]['c']);
esCerca('precio con coma decimal', 0.5, $d1['insumos'][0]['p']);
esCerca('dosis propia del lote', 80, $d2['insumos'][0]['c']);
es('dosis 0: ese insumo no va en ese lote', 1, count($d2['insumos']));
es('insumo a mano: sin id y con nombre', 'Inoculante', $d1['insumos'][1]['nombre']);
es('moneda', 'USD', $n['op']['moneda']);
$c = carga_costos($n);
esCerca('costo lote 1', 20 * 100 + 70 * 0.5 * 100 + 1 * 8 * 100, $c['por_lote'][1]);
esCerca('costo lote 2', 20 * 50 + 80 * 0.5 * 40, $c['por_lote'][2]);
esCerca('total', $c['por_lote'][1] + $c['por_lote'][2], $c['total']);

$mal = carga_normalizar(['grupo' => 'x', 'tipo' => 'y', 'lotes' => []], $lotes, $ins);
esVerdad('grupo y tipo inválidos se rechazan', count($mal['errores']) >= 2);
$mal = carga_normalizar(['tipo' => 'labor', 'grupo' => 'siembra', 'precio' => '0', 'fecha' => '2026-10-01',
                         'lotes' => [['id' => 1]]], $lotes, $ins);
esVerdad('labor sin precio se rechaza', str_contains(implode(' ', $mal['errores']), 'precio'));
$mal = carga_normalizar(['tipo' => 'insumo', 'grupo' => 'siembra', 'fecha' => '2026-10-01',
                         'insumos' => [['insumo_id' => 99, 'dosis' => 1, 'precio' => 1]], 'lotes' => [['id' => 1]]], $lotes, $ins);
esVerdad('un insumo de otro usuario se rechaza', str_contains(implode(' ', $mal['errores']), 'stock'));
$mal = carga_normalizar(['tipo' => 'labor', 'grupo' => 'siembra', 'precio' => '5', 'fecha' => '2026-02-30',
                         'lotes' => [['id' => 1]]], $lotes, $ins);
esVerdad('una fecha que no existe se rechaza', str_contains(implode(' ', $mal['errores']), 'fecha'));
$mal = carga_normalizar(['tipo' => 'labor', 'grupo' => 'siembra', 'precio' => '5', 'fecha' => '2026-10-01', 'lotes' => []], $lotes, $ins);
esVerdad('sin lotes se rechaza', str_contains(implode(' ', $mal['errores']), 'lote'));
$mal = carga_normalizar(['tipo' => 'insumo', 'grupo' => 'fertilizacion', 'fecha' => '2026-10-01',
                         'insumos' => [['insumo_id' => 7, 'dosis' => '100', 'precio' => '1']],
                         'lotes' => [['id' => 1, 'dosis' => ['0' => '0']]]], $lotes, $ins);
esVerdad('sólo insumos y un lote con todo en 0 se rechaza', str_contains(implode(' ', $mal['errores']), 'dosis'));
$mal = carga_normalizar(['tipo' => 'insumo', 'grupo' => 'fertilizacion', 'fecha' => '2026-10-01',
                         'insumos' => [['insumo_id' => 7, 'dosis' => '-1', 'precio' => '1']], 'lotes' => [['id' => 1]]], $lotes, $ins);
esVerdad('dosis negativa se rechaza', str_contains(implode(' ', $mal['errores']), 'negativ'));

grupo('Carga por lotes · ida y vuelta (editar y repetir)');
/* Lo que guardaría la base para la carga de arriba, y vuelta a la planilla:
   tiene que dar lo mismo al volver a normalizar. */
$ops = []; $hijos = [];
foreach ($n['destinos'] as $i => $d) {
    $ops[] = ['id' => 100 + $i, 'lote_id' => $d['lote_id'], 'grupo_gasto' => 'siembra', 'grupo_descripcion' => null,
              'tipo_componente' => 'receta_labor', 'moneda' => 'USD', 'proveedor_servicio' => 'Contratista',
              'precio_unitario' => '20.0000', 'fecha' => $d['fecha'], 'cantidad_ha' => $d['ha_labor'],
              'hectareas' => $d['ha'], 'campania_operacion' => $d['campania'], 'cultivo_operacion' => $d['cultivo']];
    foreach ($d['insumos'] as $f) {
        $hijos[100 + $i][] = ['insumo_id' => $f['id'], 'nombre_libre' => $f['nombre'], 'cantidad_ha' => $f['c'], 'precio_unitario' => $f['p']];
    }
}
$planilla = carga_desde_operaciones($ops, $hijos);
es('tipo', 'receta_labor', $planilla['tipo']);
es('insumos: la unión de los lotes', 2, count($planilla['insumos']));
es('dosis igual a la de arriba queda vacía', '', $planilla['lotes'][0]['dosis'][0]);
es('dosis propia se conserva', '80', $planilla['lotes'][1]['dosis'][0]);
es('insumo que el lote no llevó queda en 0', '0', $planilla['lotes'][1]['dosis'][1]);
$planilla['lotes'] = array_map(fn($l) => $l + ['id' => $l['id']], $planilla['lotes']);
$vuelta = carga_normalizar($planilla, $lotes, $ins);
es('vuelve sin errores', '', implode(' | ', $vuelta['errores']));
es('mismos gastos al volver', json_encode($n['destinos']), json_encode($vuelta['destinos']));
esCerca('mismo total al volver', $c['total'], carga_costos($vuelta)['total']);
// El primer gasto guardado es el de la dosis distinta: la de arriba igual tiene que ser la de la mayoría.
$op3 = fn($id, $lote, $ha) => ['id' => $id, 'lote_id' => $lote, 'grupo_gasto' => 'fertilizacion', 'grupo_descripcion' => null,
    'tipo_componente' => 'multi_insumo', 'moneda' => 'ARS', 'proveedor_servicio' => null, 'precio_unitario' => 0,
    'fecha' => '2026-01-01', 'cantidad_ha' => 0, 'hectareas' => $ha, 'campania_operacion' => null, 'cultivo_operacion' => null];
$h3 = fn($d) => [['insumo_id' => 7, 'nombre_libre' => null, 'cantidad_ha' => $d, 'precio_unitario' => 1]];
$mayoria = carga_desde_operaciones([$op3(1, 1, 10), $op3(2, 2, 10), $op3(3, 3, 10)], [1 => $h3(150), 2 => $h3(100), 3 => $h3(100)]);
esCerca('la dosis de arriba es la de la mayoría de los lotes', 100, $mayoria['insumos'][0]['dosis']);
es('y el lote distinto queda con la suya', '150', $mayoria['lotes'][0]['dosis'][0]);
$empate = carga_desde_operaciones([$op3(1, 1, 40), $op3(2, 2, 100)], [1 => $h3(80), 2 => $h3(70)]);
esCerca('empate: la del lote con más hectáreas', 70, $empate['insumos'][0]['dosis']);

$solo = carga_desde_operaciones([['id' => 5, 'lote_id' => 1, 'grupo_gasto' => 'siembra', 'grupo_descripcion' => null,
    'tipo_componente' => 'multi_insumo', 'moneda' => 'ARS', 'proveedor_servicio' => null, 'precio_unitario' => 0,
    'fecha' => '2026-01-01', 'cantidad_ha' => 0, 'hectareas' => 30, 'campania_operacion' => null, 'cultivo_operacion' => null]],
    [5 => [['insumo_id' => 7, 'nombre_libre' => null, 'cantidad_ha' => 2, 'precio_unitario' => 3]]]);
es('multi_insumo se lee como "sólo insumos"', 'insumo', $solo['tipo']);

grupo('Carga por lotes · plantillas');
[$pl, $err] = plantilla_datos($base, $ins, [7 => 'Semilla soja']);
es('sin errores', '', implode(' | ', $err));
es('sin lotes ni fecha', 'no', isset($pl['lotes']) || isset($pl['fecha']) ? 'sí' : 'no');
es('el insumo del stock guarda también su nombre', 'Semilla soja', $pl['insumos'][0]['nombre_libre']);
es('dos insumos (la fila vacía no)', 2, count($pl['insumos']));
esVerdad('id de carga con formato UUID', carga_id_valido(carga_nuevo_id()));
esVerdad('un id cualquiera no pasa', !carga_id_valido("x' OR 1=1"));
