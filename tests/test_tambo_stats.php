<?php
/**
 * Las cuentas del mes del tambo (includes/tambo_stats.php).
 *
 * Es la cuenta que leen el panel, la comparativa y el chat: si cambia acá,
 * cambia en los tres lugares a la vez, que es justamente la idea.
 */

require_once __DIR__ . '/../includes/tambo_stats.php';

grupo('Tambo — un mes completo');

$t = tambo_calcular([
    'litros'         => 1000,
    'litros_otra'    => 50,
    'ingreso_leche'  => 500000,
    'ingreso_otra'   => 20000,
    'precio'         => 500,
    'carne_real'     => 30000,
    'dif_inventario' => 10000,
    'egresos' => [
        ['categoria' => 'Alimentación', 'subcategoria' => 'Concentrados', 'moneda' => 'ARS', 'total' => 300000, 'items' => 3],
        ['categoria' => 'Veterinaria',  'subcategoria' => 'Sanidad',      'moneda' => 'USD', 'total' => 100,    'items' => 1],
    ],
], 1000.0);

esCerca('los ingresos suman leche, otra leche y carne', 560000, $t['total_ingresos_ars']);
esCerca('un egreso en dólares entra en pesos al dólar del mes', 400000, $t['costos_ars_total']);
esCerca('y los pesos entran en dólares al mismo dólar', 400, $t['costos_usd']);
esCerca('margen bruto en pesos', 160000, $t['margen_bruto_ars']);
esCerca('margen bruto en dólares', 160, $t['margen_bruto_usd']);
esCerca('rentabilidad', 28.5714, $t['rentabilidad'], 0.001);
esCerca('con un solo dólar, la rentabilidad da igual en dólares',
        $t['margen_bruto_usd'] / $t['total_ingresos_usd'] * 100, $t['rentabilidad'], 0.0001);
esCerca('el costo final por litro descuenta carne y otra leche', 340, $t['costo_final_ars']);
esCerca('rinde de indiferencia en litros', 680, $t['rinde_indiferencia']);
esCerca('la leche en la composición incluye la otra leche', 92.857, $t['pct_leche'], 0.001);
es('el ranking de costos va de mayor a menor', 'Alimentación', array_key_first($t['costos_cat_ars']));
es('ítems son filas cargadas, no grupos', 4, $t['egresos_items']);
es('se marca que hay egresos en dólares', 'true', $t['egresos_usd'] ? 'true' : 'false');

grupo('Tambo — mes sin leche o vacío');

$v = tambo_calcular(['litros' => 0, 'egresos' => [
    ['categoria' => '', 'subcategoria' => null, 'moneda' => 'ARS', 'total' => 5000, 'items' => 1],
]], 1000.0);
esCerca('sin litros el costo por litro es cero, no un error', 0, $v['costo_final_ars']);
esCerca('y el rinde de indiferencia también', 0, $v['rinde_indiferencia']);
es('un egreso sin categoría cae en Otros', 'Otros', array_key_first($v['costos_cat_ars']));
es('con un gasto, el mes tiene datos', 'true', $v['hay_datos'] ? 'true' : 'false');

$nada = tambo_calcular([], 1000.0);
es('sin nada cargado, no hay datos', 'false', $nada['hay_datos'] ? 'true' : 'false');

grupo('Tambo — mes válido');

es('un mes bien escrito pasa', '2026-08', tambo_mes_valido('2026-08'));
es('el mes 13 no', null, tambo_mes_valido('2026-13'));
es('lo que llega por GET con basura, tampoco', null, tambo_mes_valido("2026-08' OR 1"));
