<?php
/**
 * Cómo lee el chat del tambo la pregunta, y a qué módulo la manda.
 *
 * Igual que en agricultura, un error acá no tira un 500: contesta con toda
 * seguridad otro número. Y con dos módulos hay un error nuevo, peor: contestar el
 * margen del tambo cuando se preguntó el de la campaña.
 */

require_once __DIR__ . '/../includes/motor_tambo.php';
require_once __DIR__ . '/../includes/chat_ruteo.php';

$met = fn(string $p) => motor_tambo_detectar_metrica(motor_normalizar($p));

grupo('Tambo — qué número pide');

es('margen a secas', 'margen_bruto', $met('¿Cuál es mi margen?'));
es('cuánto gané', 'margen_bruto', $met('¿cuánto gané en agosto?'));
es('costo por litro no es el costo', 'costo_litro', $met('¿Cuánto me cuesta el litro?'));
es('costo por litro, escrito así', 'costo_litro', $met('costo por litro de julio'));
es('margen por litro no es el margen', 'margen_litro', $met('¿cuánto gano por litro?'));
es('litros por vaca no son los litros', 'litros_vaca', $met('¿Cuántos litros por vaca?'));
es('cuántos litros da cada vaca', 'litros_vaca', $met('cuantos litros da cada vaca'));
es('los litros', 'litros', $met('¿Cuántos litros produje en agosto?'));
es('cuántos litros necesito es el rinde de indiferencia', 'rinde_indiferencia', $met('¿cuántos litros necesito para salir hecho?'));
es('precio de la leche', 'precio_litro', $met('¿A cuánto se pagó la leche?'));
es('cuánto gasté', 'costos', $met('¿Cuánto gasté?'));
es('egresos no se confunde con ingresos', 'costos', $met('¿cuántos egresos tuve?'));
es('cuánto vendí de carne es la carne', 'ingreso_carne', $met('¿cuánto vendí de carne?'));
es('ingresos de leche', 'ingreso_leche', $met('ingresos de leche de julio'));
es('ingresos a secas', 'ingresos', $met('¿cuánto facturé?'));
es('vacas en ordeñe', 'vacas', $met('¿Cuántas vacas tengo en ordeñe?'));
es('grasa es la calidad', 'calidad', $met('¿cómo viene la grasa?'));
es('rentabilidad', 'rentabilidad', $met('rentabilidad de agosto'));
es('rinde de indiferencia', 'rinde_indiferencia', $met('¿Cuál es el rinde de indiferencia?'));
es('un saludo no es una métrica', null, $met('hola'));

grupo('Tambo — rubros del gasto');

$rub = fn(string $p, array $conceptos = []) => motor_tambo_detectar_rubro(motor_normalizar($p), [], $conceptos);

es('una categoría', 'categoria:Alimentación', motor_tambo_rubro_a_texto($rub('¿Cuánto gasté en alimentación?')));
es('un sinónimo de categoría', 'categoria:Lubricantes y combustibles', motor_tambo_rubro_a_texto($rub('cuanto gaste en gasoil')));
es('una subcategoría', 'subcategoria:Concentrados', motor_tambo_rubro_a_texto($rub('cuanto gaste en concentrados')));
es('el nombre más largo gana: alimento balanceado es la subcategoría', 'subcategoria:Balanceados',
   motor_tambo_rubro_a_texto($rub('cuanto gaste en alimento balanceado')));
$repro = $rub('cuanto gaste en reproduccion');
es('una subcategoría que está en dos categorías las junta', 2, count($repro['pares'] ?? []));
es('"Alimentación" dentro de Sueldos no le gana a la categoría', 'categoria:Alimentación',
   motor_tambo_rubro_a_texto($rub('gasto en alimentacion')));
es('un concepto cargado por el productor', 'concepto:Maíz molido',
   motor_tambo_rubro_a_texto($rub('cuanto gaste en maiz molido', ['Maíz molido'])));
es('un concepto llamado "Gastos" no se queda con todas las preguntas', null,
   motor_tambo_rubro_a_texto($rub('cuanto gaste', ['Gastos'])));
es('ni uno de dos letras', null, motor_tambo_rubro_a_texto($rub('cuanto gaste en agosto', ['ag'])));
es('"vacas" es el rodeo, no el alquiler de vacas', null, motor_tambo_rubro_a_texto($rub('cuantas vacas tengo')));
es('el alquiler de vacas sí', 'subcategoria:Vacas', motor_tambo_rubro_a_texto($rub('cuanto pague de alquiler de vacas')));

grupo('Tambo — meses');

$meses = fn(string $p) => motor_tambo_meses_nombrados(motor_normalizar($p));
es('un mes sin año', '8:', implode(',', array_map(fn($m) => $m['mes'] . ':' . $m['anio'], $meses('¿cuánto gasté en agosto?'))));
es('con año pegado', '8:2025', implode(',', array_map(fn($m) => $m['mes'] . ':' . $m['anio'], $meses('margen de agosto de 2025'))));
es('dos meses en el orden escrito', '8,7', implode(',', array_column($meses('¿gasté más en agosto que en julio?'), 'mes')));
es('"mayor" no es mayo', '', implode(',', array_column($meses('¿cuál fue el mayor gasto?'), 'mes')));
es('setiembre y septiembre son el mismo', '9', implode(',', array_column($meses('setiembre'), 'mes')));
es('el mes anterior cruza el año', '2025-12', motor_tambo_mes_anterior('2026-01'));
es('etiqueta del mes', 'septiembre de 2026', motor_tambo_etiqueta_mes('2026-09'));

grupo('Tambo — qué forma tiene la pregunta');

esVerdad('compará dos meses', motor_tambo_pide_comparar('compara agosto con julio'));
esVerdad('contra el mes anterior', motor_tambo_pide_comparar('y contra el mes anterior'));
esVerdad('"diferencia de inventario" no es comparar', !motor_tambo_pide_comparar('diferencia de inventario de agosto'));
// El caso real: "saqué en" lleva adentro "que en".
esVerdad('"¿cuánta leche saqué en agosto?" no es comparar', !motor_tambo_pide_comparar(motor_normalizar('¿Cuánta leche saqué en agosto?')));
esVerdad('"¿gasté más en agosto que en julio?" sí', motor_tambo_pide_comparar(motor_normalizar('¿gasté más en agosto que en julio?')));
es('últimos 6 meses', 6, motor_tambo_pide_serie('litros de los ultimos 6 meses'));
es('últimos tres meses, en letras', 3, motor_tambo_pide_serie('margen de los ultimos tres meses'));
es('más de un año se recorta', MOTOR_TAMBO_SERIE_MAX, motor_tambo_pide_serie('ultimos 40 meses'));
esVerdad('en qué gasté más', motor_tambo_pide_ranking('en que gaste mas'));
esVerdad('cómo vengo es un resumen', motor_tambo_pide_resumen('como vengo'));

grupo('Tambo — formato');

motor_moneda('ARS');
es('pesos', '$1.234,50', motor_tambo_fmt(1234.5, 'dinero'));
es('negativo con el signo adelante', '-$1.234,50', motor_tambo_fmt(-1234.5, 'dinero'));
es('por litro en pesos, dos decimales', '$412,35 por litro', motor_tambo_fmt(412.345, 'dinero_litro'));
motor_moneda('USD');
es('por litro en dólares, tres decimales como el panel', 'US$0,285 por litro', motor_tambo_fmt(0.2849, 'dinero_litro'));
motor_moneda('ARS');
es('litros sin decimales', '180.500 litros', motor_tambo_fmt(180500, 'litros'));

grupo('Chat — a qué módulo va la pregunta');

$mod = fn(string $p) => chat_ruteo_modulo(motor_normalizar($p));
es('las vacas son del tambo', 'tambo', $mod('¿Cuántas vacas tengo en ordeñe?'));
es('la leche es del tambo', 'tambo', $mod('¿a cuánto se pagó la leche?'));
es('un lote es de agricultura', 'agricultura', $mod('¿cuánto rindió el lote 3?'));
es('una campaña escrita es de agricultura', 'agricultura', $mod('margen de la 25/26'));
es('el margen a secas no es de nadie: decide la pantalla', null, $mod('¿Cuál es mi margen?'));
es('cuánto gasté a secas tampoco', null, $mod('¿Cuánto gasté en agosto?'));
es('los litros a secas tampoco: también hay litros de glifosato', null, $mod('¿cuántos litros usé?'));
es('si nombra los dos, decide la pantalla', null, $mod('insumos para el tambo'));
es('"y en agricultura" pide agricultura', 'agricultura', $mod('¿Y en agricultura?'));
es('"y en el tambo" pide el tambo', 'tambo', $mod('¿Y en el tambo?'));
esVerdad('"¿y en el tambo?", sacado el módulo, es relleno: repite la pregunta anterior',
         motor_resto_es_relleno(chat_ruteo_sin_modulo(motor_normalizar('¿Y en el tambo?'))));
esVerdad('"¿y cuántas vacas en el tambo?" no es relleno: es una pregunta nueva',
         !motor_resto_es_relleno(chat_ruteo_sin_modulo(motor_normalizar('¿y cuántas vacas en el tambo?'))));
