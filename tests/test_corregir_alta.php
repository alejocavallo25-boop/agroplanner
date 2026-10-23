<?php
/**
 * "Me equivoqué" a mitad de una carga del chat.
 *
 * Se ofrece corregir lo último que contestó; si era algo de antes, se sigue y se
 * corrige en el resumen final. Lo que se prueba es que "lo último" sea de verdad
 * lo último, y que un insumo ya cerrado de una aplicación se pueda nombrar.
 */

require_once __DIR__ . '/../includes/motor.php';

grupo('Corregir — cuál fue el último dato');

$previo = fn(array $s) => motor_alta_dato_previo($s + ['que' => 'gasto']);

$s = ['grupo_gasto' => 'pulverizacion', 'lotes' => [3]];
es('preguntando el tipo, lo último fue el lote', 'lotes', $previo($s)['previo']);
esVerdad('la carga sigue abierta', $previo($s)['pendiente']);

es('sin nada cargado no hay qué corregir', '', (string)$previo([])['previo']);

$s = ['grupo_gasto' => 'pulverizacion', 'lotes' => [3], 'tipo_componente' => 'receta', 'moneda' => 'ARS',
      'labor_costo' => 8000, 'insumo_nombre' => 'Glifosato', 'insumo_id' => 5, 'insumo_dosis' => 2];
es('preguntando el precio, lo último fue la dosis', 'insumo_dosis', $previo($s)['previo']);

$s['insumo_precio'] = 7000;
es('preguntando si va otro, lo último fue el precio', 'insumo_precio', $previo($s)['previo']);

$s['insumo_precio_de'] = 'catalogo';
es('si el precio lo trajo el catálogo, lo último fue la dosis', 'insumo_dosis', $previo($s)['previo']);

$s = ['grupo_gasto' => 'pulverizacion', 'lotes' => [3], 'tipo_componente' => 'receta', 'moneda' => 'ARS',
      'labor_costo' => 8000,
      'insumos_lista' => [['id' => 5, 'nombre' => 'Glifosato', 'dosis' => 2, 'precio' => 7000],
                          ['id' => 0, 'nombre' => 'Coadyuvante', 'dosis' => 0.1, 'precio' => 9000]]];
es('después de "agrego otro", lo último es el insumo anterior', 'lista:1', $previo($s)['previo']);
es('y se nombra por su nombre', 'Coadyuvante', motor_alta_etiqueta_campo('lista:1', $s + ['que' => 'gasto']));

$s['insumos_cerrado'] = 1;
$s['fecha'] = '2026-10-20';
esVerdad('con todo cargado, la carga ya no está abierta', !$previo($s)['pendiente']);

grupo('Corregir — qué pide corregir');

$c = fn(string $frase, array $s = []) => motor_alta_pide_correccion(motor_normalizar($frase), 'gasto', $s);
es('"me equivoqué" sin decir qué', '', $c('me equivoque'));
esVerdad('una respuesta no es una corrección', $c('120') === null);
es('"corregir la labor" en una aplicación', 'labor_costo', $c('corregir la labor', ['labor_costo' => 8000]));
es('un insumo de la lista por su nombre', 'lista:0', $c('corregir glifosato', $s));
es('el chip con el nombre del insumo', 'lista:1', $c('Corregir Coadyuvante', $s));
es('"la dosis" con varios insumos pregunta cuál', '', $c('corregir la dosis', $s));
es('"la dosis" con un solo insumo es ése', 'lista:0',
   $c('corregir la dosis', ['insumos_lista' => [['nombre' => 'Urea', 'dosis' => 100, 'precio' => 900]]]));

grupo('Carga — la moneda se pregunta antes del primer monto');

$pend = fn(array $s) => (string)motor_alta_paso_pendiente($s + ['que' => 'gasto']);
$s = ['grupo_gasto' => 'pulverizacion', 'lotes' => [3], 'tipo_componente' => 'receta'];
es('después del tipo, la moneda', 'moneda', $pend($s));
es('con la moneda, la labor', 'labor_costo', $pend($s + ['moneda' => 'USD']));
es('mano de obra: la moneda antes del monto', 'moneda',
   $pend(['grupo_gasto' => 'cosecha', 'lotes' => [3], 'tipo_componente' => 'labor']));
es('si la frase la dijo, no se pregunta', 'costo_total',
   $pend(['grupo_gasto' => 'otros', 'lotes' => [3], 'tipo_componente' => 'labor', 'moneda' => 'USD']));
es('"Dólares" del chip', 'USD', (string)motor_moneda_dicha(motor_normalizar('Dólares'), 'Dólares'));
es('"Pesos" del chip', 'ARS', (string)motor_moneda_dicha(motor_normalizar('Pesos'), 'Pesos'));
es('"me equivoqué" después de la moneda ofrece la moneda', 'moneda', $previo($s + ['moneda' => 'USD'])['previo']);
es('"corregir la moneda"', 'moneda', $c('corregir la moneda'));

grupo('Corregir — "era un dato anterior"');

$a = fn(string $frase) => motor_alta_dato_anterior(motor_normalizar($frase)) ? 'si' : 'no';
es('el chip', 'si', $a(MOTOR_DATO_ANTERIOR));
es('dicho a mano', 'si', $a('no, era otro dato'));
es('una respuesta común no lo es', 'no', $a('glifosato'));
