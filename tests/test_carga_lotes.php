<?php
/* La cuenta de un gasto y el número que agrupa los gastos repartidos en varios
   lotes. Ver includes/carga_lotes.php: es la única versión de la cuenta, la que
   usa operaciones.php para la carga de a uno y para "Repartir en varios lotes". */
require_once __DIR__ . '/../includes/carga_lotes.php';

grupo('Gasto · la cuenta');
esCerca('labor: precio × ha', 2000, operacion_costo('labor', 20, 100, [], 100));
esCerca('labor: ignora insumos', 2000, operacion_costo('labor', 20, 100, [['c' => 5, 'p' => 10]], 100));
esCerca('insumos: dosis × precio × ha', 1600 + 160, operacion_costo('insumo', 99, 0, [['c' => 2, 'p' => 10], ['c' => 0.5, 'p' => 4]], 80));
// Una aplicación en parte del lote: la labor también va sobre lo aplicado
// (antes iba siempre sobre el lote entero y no se podía cargar menos).
esCerca('receta: labor e insumos sobre lo aplicado',
        15 * 80 + 2 * 10 * 80, operacion_costo('receta_labor', 15, operacion_ha_labor('receta_labor', 100, 80), [['c' => 2, 'p' => 10]], 80));
esCerca('receta sin ha aplicadas: el lote entero', 100, operacion_ha_labor('receta_labor', 100, 0));
esCerca('labor sola: sobre lo trabajado', 80, operacion_ha_labor('labor', 100, 80));

// La receta impresa: título según el grupo (salía "PULVERIZACIÓN" siempre).
es('título: siembra', 'SIEMBRA', receta_titulo(['grupo_gasto' => 'siembra']));
es('título: fertilización', 'FERTILIZACIÓN', receta_titulo(['grupo_gasto' => 'fertilizacion']));
es('título: pulverización', 'PULVERIZACIÓN', receta_titulo(['grupo_gasto' => 'pulverizacion']));
es('título: otros con descripción', 'RIEGO', receta_titulo(['grupo_gasto' => 'otros', 'grupo_descripcion' => 'Riego']));
// Las cuentas de la receta con lo guardado: 80 ha aplicadas de un lote de 100,
// labor sobre 70 ha (lo que dijo el productor).
$n = receta_numeros(['hectareas' => 80, 'cantidad_ha' => 70, 'precio_unitario' => 15, 'cargas' => 4],
                    [['cantidad_ha' => 2, 'precio_unitario' => 10]], 100);
esCerca('receta impresa: insumos sobre ha aplicadas', 1600, $n['insumos']);
esCerca('receta impresa: labor sobre sus ha', 1050, $n['labor']);
esCerca('receta impresa: ha por carga', 20, $n['ha_carga']);
esCerca('receta impresa: por ha aplicada', (1600 + 1050) / 80, $n['total_ha']);
$n = receta_numeros(['hectareas' => null, 'cantidad_ha' => 0, 'precio_unitario' => 10, 'cargas' => null], [], 50);
esCerca('receta vieja sin ha: el lote', 500, $n['labor']);

grupo('Gasto · el número de una carga en varios lotes');
esVerdad('formato UUID', carga_id_valido(carga_nuevo_id()));
esVerdad('dos cargas, dos números', carga_nuevo_id() !== carga_nuevo_id());
esVerdad('un texto cualquiera no pasa', !carga_id_valido("x' OR 1=1"));
