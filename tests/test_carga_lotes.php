<?php
/* La cuenta de un gasto y el número que agrupa los gastos repartidos en varios
   lotes. Ver includes/carga_lotes.php: es la única versión de la cuenta, la que
   usa operaciones.php para la carga de a uno y para "Repartir en varios lotes". */
require_once __DIR__ . '/../includes/carga_lotes.php';

grupo('Gasto · la cuenta');
esCerca('labor: precio × ha', 2000, operacion_costo('labor', 20, 100, [], 100));
esCerca('labor: ignora insumos', 2000, operacion_costo('labor', 20, 100, [['c' => 5, 'p' => 10]], 100));
esCerca('insumos: dosis × precio × ha', 1600 + 160, operacion_costo('insumo', 99, 0, [['c' => 2, 'p' => 10], ['c' => 0.5, 'p' => 4]], 80));
esCerca('receta: labor sobre el lote entero, insumos sobre lo aplicado',
        15 * 100 + 2 * 10 * 80, operacion_costo('receta_labor', 15, operacion_ha_labor('receta_labor', 100, 80), [['c' => 2, 'p' => 10]], 80));
esCerca('labor sola: sobre lo trabajado', 80, operacion_ha_labor('labor', 100, 80));

grupo('Gasto · el número de una carga en varios lotes');
esVerdad('formato UUID', carga_id_valido(carga_nuevo_id()));
esVerdad('dos cargas, dos números', carga_nuevo_id() !== carga_nuevo_id());
esVerdad('un texto cualquiera no pasa', !carga_id_valido("x' OR 1=1"));
