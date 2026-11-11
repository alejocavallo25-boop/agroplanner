<?php
/* Pedidos de presupuesto: el Excel que se manda, la lectura del que vuelve
   completado por el proveedor (ida y vuelta de verdad, archivo .xlsx incluido)
   y la comparación de ofertas. Ver includes/presupuestos.php. */
require_once __DIR__ . '/../includes/presupuestos.php';

grupo('Presupuestos · el pedido');
[$ped, $items, $err] = pp_normalizar_pedido([
    'titulo' => 'Herbicidas 26/27', 'moneda' => 'USD', 'fecha_limite' => '2026-12-01',
    'items' => [
        ['insumo_id' => 7, 'descripcion' => 'Glifosato 62%', 'cantidad' => '300', 'unidad' => 'lt'],
        ['insumo_id' => 99, 'descripcion' => 'Paraquat 27,6%', 'cantidad' => '240,5', 'unidad' => 'lt'],
        ['descripcion' => '', 'cantidad' => ''],
    ]], [7 => true]);
es('sin errores', '', implode(' | ', $err));
es('dos renglones: el vacío no cuenta', 2, count($items));
es('el insumo ajeno queda como texto', 'NULL', var_export($items[1]['insumo_id'], true));
esCerca('cantidad con coma', 240.5, $items[1]['cantidad']);
es('orden = código del renglón', '1,2', implode(',', array_column($items, 'orden')));
[, , $err] = pp_normalizar_pedido(['titulo' => '', 'items' => [['descripcion' => 'Urea', 'cantidad' => '']]], []);
esVerdad('sin nombre ni cantidad se rechaza', count($err) === 2);

grupo('Presupuestos · el Excel va y vuelve');
$pedido = ['id' => 45, 'titulo' => 'Herbicidas 26/27', 'moneda' => 'USD', 'fecha_limite' => '2026-12-01', 'notas' => null];
$its = [];
foreach ($items as $i => $it) $its[] = $it + ['id' => 100 + $i];
$bytes = pp_xlsx($pedido, $its, 'Estancia La Paloma', 'Agroinsumos SA');
esVerdad('es un ZIP (un .xlsx)', str_starts_with($bytes, "PK"));
$tmp = tempnam(sys_get_temp_dir(), 'ppt') . '.xlsx';
file_put_contents($tmp, $bytes);
// El proveedor lo completa: precios, moneda y condición de pago.
$z = new ZipArchive(); $z->open($tmp);
$hoja = $z->getFromName('xl/worksheets/sheet1.xml');
esVerdad('la hoja es XML válido', simplexml_load_string($hoja) !== false);
$hoja = str_replace('<c r="E12" s="7"></c>', '<c r="E12" s="7"><v>5.8</v></c>', $hoja);
$hoja = str_replace('<c r="G12" s="6"/>', '<c r="G12" t="inlineStr" s="6"><is><t>Glifosato Atanor</t></is></c>', $hoja);
$hoja = str_replace('<c r="B8" s="6"/>', '<c r="B8" t="inlineStr" s="6"><is><t>Canje mayo 2027</t></is></c>', $hoja);
$z->addFromString('xl/worksheets/sheet1.xml', $hoja);
$z->close();
$hojas = imp_parse_xlsx($tmp);
$r = pp_leer_respuesta($hojas[0]['grid']);
unlink($tmp);
es('sin error', 'NULL', var_export($r['error'], true));
es('reconoce el número de pedido', 'PP-000045', $r['codigo']);
es('el proveedor que venía puesto', 'Agroinsumos SA', $r['proveedor']);
es('moneda', 'USD', pp_moneda_de($r['moneda_texto'], 'ARS'));
es('condición de pago', 'Canje mayo 2027', $r['condicion_pago']);
es('cotizó sólo el renglón 1', '1', implode(',', array_keys($r['precios'])));
esCerca('precio', 5.8, $r['precios'][1]['precio']);
es('marca ofrecida', 'Glifosato Atanor', $r['precios'][1]['marca']);

grupo('Presupuestos · Excel guardado por otro programa');
/* Algunos programas escriben la ruta de la hoja absoluta ("/xl/worksheets/...")
   en vez de relativa: el lector la buscaba en "xl/xl/..." y rechazaba el archivo. */
$tmp = tempnam(sys_get_temp_dir(), 'ppa') . '.xlsx';
file_put_contents($tmp, pp_xlsx($pedido, $its, 'X'));
$z = new ZipArchive(); $z->open($tmp);
$rels = str_replace('Target="worksheets/sheet1.xml"', 'Target="/xl/worksheets/sheet1.xml"', $z->getFromName('xl/_rels/workbook.xml.rels'));
$z->addFromString('xl/_rels/workbook.xml.rels', $rels);
$z->close();
$hojas = imp_parse_xlsx($tmp);
unlink($tmp);
es('ruta absoluta de la hoja: se lee igual', 'PP-000045', pp_leer_respuesta($hojas[0]['grid'])['codigo']);

$r = pp_leer_respuesta([['Condición de pago', '', '', '', 'Plazo de entrega', '10 días'], ['Cód.', 'Precio unitario'], ['1', '5']]);
es('condición vacía no se lleva el rótulo de al lado', '', $r['condicion_pago']);
es('y el plazo se lee bien', '10 días', $r['plazo_entrega']);

grupo('Presupuestos · respuestas que no sirven');
$r = pp_leer_respuesta([['Factura'], ['Glifosato', '300', '5,80']]);
esVerdad('un archivo con otro formato avisa que hay que cargarlo a mano', str_contains((string)$r['error'], 'a mano'));
$r = pp_leer_respuesta([['Pedido', 'PP-000045'], ['Cód.', 'Insumo', 'Cantidad', 'Unidad', 'Precio unitario'], ['1', 'Glifosato', '300', 'lt', '']]);
esVerdad('sin ningún precio avisa', str_contains((string)$r['error'], 'sin ningún precio'));
$r = pp_leer_respuesta([['Cód.', 'Insumo', 'Precio unitario'], ['2', 'Paraquat', 'US$ 3,25'], ['1', 'Glifosato', '5,8'], ['', 'TOTAL', '999']]);
es('filas reordenadas y precio con símbolo: se reconocen por el código', '2,1', implode(',', array_keys($r['precios'])));
esCerca('precio con símbolo', 3.25, $r['precios'][2]['precio']);

grupo('Presupuestos · moneda');
es('U$S', 'USD', pp_moneda_de('U$S', 'ARS'));
es('dólares', 'USD', pp_moneda_de('Dólares', 'ARS'));
es('$ solo', 'ARS', pp_moneda_de('$', 'USD'));
es('pesos', 'ARS', pp_moneda_de('pesos', 'USD'));
es('vacío: la del pedido', 'USD', pp_moneda_de('', 'USD'));

grupo('Presupuestos · la comparación');
$items = [['id' => 1, 'orden' => 1, 'descripcion' => 'Glifosato', 'cantidad' => 300, 'unidad' => 'lt'],
          ['id' => 2, 'orden' => 2, 'descripcion' => 'Paraquat', 'cantidad' => 240, 'unidad' => 'lt']];
$ofertas = [['id' => 10, 'proveedor' => 'A', 'moneda' => 'USD'], ['id' => 11, 'proveedor' => 'B', 'moneda' => 'USD'], ['id' => 12, 'proveedor' => 'C', 'moneda' => 'USD']];
$precios = [10 => [1 => ['precio' => 5.80], 2 => ['precio' => 3.40]],
            11 => [1 => ['precio' => 6.10], 2 => ['precio' => 3.10]],
            12 => [1 => ['precio' => 5.50]]];
$c = pp_comparar($items, $ofertas, $precios, 0);
esCerca('total de A', 300 * 5.8 + 240 * 3.4, $c['totales'][10]['total']);
es('C no cotizó todo', 1, $c['totales'][12]['cotizados']);
es('el más barato de cada renglón', '12,11', $c['filas'][0]['mejor'] . ',' . $c['filas'][1]['mejor']);
es('la mejor oferta completa (C no cuenta)', 10, $c['mejor_completa']);
esCerca('comprando cada uno al más barato', 300 * 5.5 + 240 * 3.1, $c['combinacion']);
esCerca('cuánto se ahorra combinando', (300 * 5.8 + 240 * 3.4) - (300 * 5.5 + 240 * 3.1), $c['ahorro_combinando']);
es('misma moneda: sin conversión', 'no', $c['mezcla'] ? 'sí' : 'no');

$ofertas[1]['moneda'] = 'ARS';
$precios[11] = [1 => ['precio' => 6000], 2 => ['precio' => 4800]];   // en pesos
$c = pp_comparar($items, $ofertas, $precios, 1500);
es('monedas mezcladas: se compara en dólares', 'sí|USD', ($c['mezcla'] ? 'sí' : 'no') . '|' . $c['moneda_cmp']);
esCerca('el total de B queda en pesos, como vino', 300 * 6000 + 240 * 4800, $c['totales'][11]['total']);
esCerca('y en dólares sólo para comparar', (300 * 6000 + 240 * 4800) / 1500, $c['totales'][11]['total_cmp']);
$c = pp_comparar($items, $ofertas, $precios, 0);
es('mezcladas y sin dólar cargado: no se compara', 'no', $c['puede_comparar'] ? 'sí' : 'no');

grupo('Presupuestos · lo que contesta el chat');
$ped = ['id' => 3, 'titulo' => 'Siembra gruesa'];
$ofUsd = [['id' => 10, 'proveedor' => 'A', 'moneda' => 'USD'], ['id' => 11, 'proveedor' => 'B', 'moneda' => 'USD'], ['id' => 12, 'proveedor' => 'C', 'moneda' => 'USD']];
$prUsd = [10 => [1 => ['precio' => 5.80], 2 => ['precio' => 3.40]], 11 => [1 => ['precio' => 6.10], 2 => ['precio' => 3.10]], 12 => [1 => ['precio' => 5.50]]];
$r = pp_resumen_chat($ped, $items, $ofUsd, pp_comparar($items, $ofUsd, $prUsd, 0), 0);
esVerdad('nombra el pedido y la mejor oferta completa con su total',
    str_contains($r['respuesta'], '"Siembra gruesa" (PP-000003)') && str_contains($r['respuesta'], 'La completa más barata es A, US$ 2.556,00'));
esVerdad('dice cuánto ahorra combinando', str_contains($r['detalle'], 'Comprando cada insumo al más barato: US$ 2.394,00, ahorrás US$ 162,00'));
esVerdad('el más barato de cada insumo', str_contains($r['detalle'], 'Glifosato, C US$ 5,50; Paraquat, B US$ 3,10'));
esVerdad('quién no cotizó', str_contains($r['detalle'], 'C no cotizó 1 renglón'));
$solo = [['id' => 10, 'proveedor' => 'A', 'moneda' => 'USD']];
$r = pp_resumen_chat($ped, $items, $solo, pp_comparar($items, $solo, [10 => $prUsd[10]], 0), 0);
esVerdad('una sola oferta: la muestra y pide las otras', str_contains($r['respuesta'], 'una sola oferta: A, US$ 2.556,00') && str_contains($r['detalle'], 'mandámelas'));
$mix = [['id' => 10, 'proveedor' => 'A', 'moneda' => 'USD'], ['id' => 11, 'proveedor' => 'B', 'moneda' => 'ARS']];
$prMix = [10 => $prUsd[10], 11 => [1 => ['precio' => 6000], 2 => ['precio' => 4800]]];
$r = pp_resumen_chat($ped, $items, $mix, pp_comparar($items, $mix, $prMix, 1500), 1500);
esVerdad('monedas mezcladas: aclara con qué dólar comparó', str_contains($r['detalle'], 'dólar de referencia ($ 1.500,00)'));
$r = pp_resumen_chat($ped, $items, $mix, pp_comparar($items, $mix, $prMix, 0), 0);
esVerdad('sin dólar cargado: no compara y lo dice', str_contains($r['detalle'], 'no las puedo comparar'));
