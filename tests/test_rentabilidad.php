<?php
/**
 * Las cuentas de las que depende la promesa del producto.
 *
 * Si algo de acá se rompe, el tablero y los reportes siguen abriendo sin error y
 * muestran números equivocados. Por eso son las primeras que se prueban.
 */

require_once __DIR__ . '/../includes/rentabilidad.php';

grupo('Rentabilidad por lote — caso corriente');

// 100 ha, entrego 350.000 kg, cobro $175.000.000, costos directos $90.000.000
// y alquiler $20.000.000.
$c = rentabilidad_lote([
    'sup'       => 100,
    'ingreso'   => 175000000,
    'costo_dir' => 90000000,
    'alquiler'  => 20000000,
    'kgs'       => 350000,
]);

esCerca('costo total = directos + alquiler',      110000000, $c['costo_total']);
esCerca('margen = ingreso - costo total',          65000000, $c['margen_total']);
esCerca('ingreso por hectarea',                     1750000, $c['ingreso_ha']);
esCerca('costo directo por hectarea',                900000, $c['costo_ha']);
esCerca('alquiler por hectarea',                     200000, $c['alquiler_ha']);
esCerca('margen por hectarea',                       650000, $c['margen_ha']);
esCerca('precio promedio por kg = 175M / 350.000',      500, $c['precio_promedio_kg']);

// Rinde de indiferencia: 110.000.000 / 500 = 220.000 kg para empatar,
// repartidos en 100 ha = 2.200 kg/ha.
esCerca('rinde de indiferencia en kg/ha',              2200, $c['rinde_indiferencia_ha']);

grupo('Rentabilidad — el margen negativo se informa, no se esconde');

$p = rentabilidad_lote([
    'sup' => 50, 'ingreso' => 10000000, 'costo_dir' => 12000000, 'alquiler' => 3000000, 'kgs' => 25000,
]);
esCerca('margen total negativo', -5000000, $p['margen_total']);
esCerca('margen por hectarea negativo', -100000, $p['margen_ha']);
esVerdad('el margen por ha es menor a cero', $p['margen_ha'] < 0);

grupo('Rentabilidad — divisiones por cero');

// Un lote sin superficie cargada no puede dar valores por hectarea. Se espera 0
// y no una division por cero ni un INF disfrazado de dato.
$sinSup = rentabilidad_lote(['sup' => 0, 'ingreso' => 5000, 'costo_dir' => 3000, 'alquiler' => 0, 'kgs' => 100]);
esCerca('sin superficie, ingreso/ha es 0', 0, $sinSup['ingreso_ha']);
esCerca('sin superficie, margen/ha es 0',  0, $sinSup['margen_ha']);
esCerca('sin superficie, rinde indif. es 0', 0, $sinSup['rinde_indiferencia_ha']);
esVerdad('sin superficie, el margen total igual se calcula', $sinSup['margen_total'] === 2000.0);

// Sin ventas no hay precio de referencia, asi que el rinde de indiferencia no
// tiene sentido: seria dividir por cero.
$sinVentas = rentabilidad_lote(['sup' => 80, 'ingreso' => 0, 'costo_dir' => 4000000, 'alquiler' => 1000000, 'kgs' => 0]);
esCerca('sin ventas, precio promedio es 0',   0, $sinVentas['precio_promedio_kg']);
esCerca('sin ventas, rinde indif. es 0',      0, $sinVentas['rinde_indiferencia_ha']);
esCerca('sin ventas, el costo por ha se calcula igual', 50000, $sinVentas['costo_ha']);

grupo('Rentabilidad — entradas ausentes o basura');

$vacio = rentabilidad_lote([]);
esCerca('lote vacio: todo en cero', 0, $vacio['margen_total']);
esCerca('lote vacio: sin superficie', 0, $vacio['sup']);

// Una superficie negativa es un dato imposible; se trata como sin superficie en
// vez de invertir el signo de todos los indicadores.
$neg = rentabilidad_lote(['sup' => -10, 'ingreso' => 1000, 'costo_dir' => 500, 'alquiler' => 0, 'kgs' => 10]);
esCerca('superficie negativa se ignora', 0, $neg['sup']);
esCerca('superficie negativa no invierte el margen por ha', 0, $neg['margen_ha']);
esCerca('el margen total sigue siendo correcto', 500, $neg['margen_total']);

// Los valores llegan de la base como texto; no deben romper la cuenta.
$texto = rentabilidad_lote(['sup' => '25.5', 'ingreso' => '1000000', 'costo_dir' => '400000', 'alquiler' => '100000', 'kgs' => '5000']);
esCerca('acepta numeros como texto', 500000, $texto['margen_total']);
esCerca('acepta superficie decimal como texto', 25.5, $texto['sup']);
