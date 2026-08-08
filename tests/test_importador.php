<?php
/**
 * El importador de archivos: conversion de numeros y fechas, reconocimiento de
 * encabezados y lectura de un remito renglon por renglon.
 *
 * Casi todos estos casos salieron de bugs reales, no de imaginacion: el precio
 * multiplicado por mil, el encabezado que no matcheaba porque la palabra clave
 * no estaba al principio, la coma de los decimales tomada como separador de
 * columnas. Quedan acá para que no vuelvan.
 */

require_once __DIR__ . '/../includes/importador_insumos.php';

grupo('Numeros en formato argentino');

es('mil doscientos treinta y cuatro con 56', 1234.56, imp_a_numero('1.234,56'));
es('formato ingles con punto decimal',       1234.56, imp_a_numero('1234.56'));
es('con simbolo de peso y miles',            45300,   imp_a_numero('$ 45.300,00'));
es('decimal chico',                          0.62,    imp_a_numero('0,62'));
es('punto decimal con un decimal',           2.5,     imp_a_numero('2.5'));
es('mil doscientos cincuenta',               1250,    imp_a_numero('1.250'));
es('negativo',                               -300.5,  imp_a_numero('-300,50'));
es('texto sin numeros da null',              null,    imp_a_numero('abc'));
es('cadena vacia da null',                   null,    imp_a_numero(''));

grupo('Fechas');

es('barra',        '2027-03-15', imp_a_fecha('15/03/2027'));
es('ISO',          '2027-03-15', imp_a_fecha('2027-03-15'));
es('guion y anio corto', '2027-03-15', imp_a_fecha('15-3-27'));
es('serial de Excel',    '2027-01-01', imp_a_fecha('46388'));
es('fecha imposible da null', null, imp_a_fecha('31/02/2027'));
es('texto suelto da null',    null, imp_a_fecha('nada'));

grupo('Serial de Excel a fecha');

es('el epoch de Unix', '1970-01-01', imp_serial_a_fecha(25569));
es('anio 2024',        '2024-01-01', imp_serial_a_fecha(45292));
// Se descartan los seriales bajos para no pisar el bug del 29/02/1900 que Excel
// arrastra de Lotus 1-2-3.
es('serial demasiado bajo se descarta', null, imp_serial_a_fecha(30));

grupo('Numeros de celda de Excel a notacion local');

// El XML de un .xlsx guarda siempre el numero con punto decimal, aunque la
// planilla muestre coma. Si ese texto entra crudo a imp_a_numero(), un precio de
// 123,456 se leia como 123456: mil veces mas.
es('decimales de tres cifras', '123,456', imp_numero_a_texto_local('123.456'));
es('entero sin decimales',     '1250',    imp_numero_a_texto_local('1250'));
es('decimal corto',            '0,62',    imp_numero_a_texto_local('0.62'));
es('no deja ceros de relleno', '5,8',     imp_numero_a_texto_local('5.80'));
es('ida y vuelta conserva el valor', 123.456, imp_a_numero(imp_numero_a_texto_local('123.456')));

grupo('Reconocimiento de encabezados');

$dic = imp_diccionario_campos();

// La palabra clave puede estar en cualquier parte, no solo al principio.
esVerdad('"Fecha de vencimiento" es un vencimiento', imp_puntaje_encabezado('Fecha de vencimiento', $dic['vencimiento']) > 0);
esVerdad('"Valor unitario" es un precio',            imp_puntaje_encabezado('Valor unitario', $dic['precio']) > 0);
esVerdad('"Detalle del articulo" es un nombre',      imp_puntaje_encabezado('Detalle del articulo', $dic['nombre']) > 0);
esVerdad('"Cant." es una cantidad',                  imp_puntaje_encabezado('Cant.', $dic['cantidad']) > 0);
esVerdad('"U.M." es una unidad',                     imp_puntaje_encabezado('U.M.', $dic['unidad']) > 0);

// Y no debe matchear fragmentos sueltos dentro de otras palabras.
es('"Volumen" no es una unidad', 0, imp_puntaje_encabezado('Volumen', $dic['unidad']));
es('"Consumo" no es una unidad', 0, imp_puntaje_encabezado('Consumo', $dic['unidad']));

// Un total de renglon no es un precio unitario.
esVerdad('"Precio Unitario" le gana a "Importe Total"',
    imp_puntaje_encabezado('Precio Unitario', $dic['precio']) > imp_puntaje_encabezado('Importe Total', $dic['precio']));

grupo('Mapeo de una fila de encabezado');

$m = imp_mapear_encabezado(['Cantidad', 'Descripcion', 'U.M.', 'Precio Unitario', 'Importe Total']);
es('la descripcion es el nombre', 1, $m['mapeo']['nombre']);
es('la cantidad',                 0, $m['mapeo']['cantidad']);
es('la unidad',                   2, $m['mapeo']['unidad']);
es('el precio sale del unitario, no del total', 3, $m['mapeo']['precio']);

// Cada columna se asigna a un solo campo.
$cols = array_values($m['mapeo']);
es('ninguna columna se usa dos veces', count($cols), count(array_unique($cols)));

grupo('Remito renglon por renglon');

$item = imp_linea_a_item('1 Trasmission Shaft Pompe 3" or 4" 2008-7633-680');
es('cantidad al principio',        1, $item['cantidad']);
es('el nombre queda limpio',       'Trasmission Shaft Pompe 3" or 4"', $item['nombre']);
es('la referencia se separa',      '2008-7633-680', $item['referencia']);

$item2 = imp_linea_a_item('2 bolsas Urea granulada 45.300,00');
es('cantidad con unidad pegada',   2, $item2['cantidad']);
es('nombre sin el importe',        'Urea granulada', $item2['nombre']);
es('importe al final',             45300, $item2['precio']);

// Los encabezados y pies del comprobante no son mercaderia.
foreach (['CANTIDAD DENOMINACION REFERENCIA', 'OBSERVACIONES:', 'REMITO N 0002-00008206',
          'FECHA: 24/07/2026', 'TOTAL 45.300,00'] as $ruido) {
    es('se descarta el ruido: ' . mb_substr($ruido, 0, 22), null, imp_linea_a_item($ruido));
}

grupo('Adivinanza de tipo y unidad');

es('urea es fertilizante',        'fertilizante', imp_adivinar_tipo('Urea granulada'));
es('glifosato es agroquimico',    'agroquimico',  imp_adivinar_tipo('Glifosato 64%'));
es('soja es semilla',             'semilla',      imp_adivinar_tipo('Semilla Soja DM 4670'));
es('bradyrhizobium es inoculante','inoculante',   imp_adivinar_tipo('Inoculante Bradyrhizobium'));
es('un repuesto es otro',         'otro',         imp_adivinar_tipo('Impeller 8 pulgadas'));

es('litros', 'lt',    imp_adivinar_unidad('litros'));
es('kg',     'kg',    imp_adivinar_unidad('kg'));
es('bolsas', 'bolsa', imp_adivinar_unidad('bolsas'));
es('dosis',  'dosis', imp_adivinar_unidad('dosis'));

grupo('Separador de columnas');

// Una tabla de verdad tiene el mismo separador en casi todas las filas.
es('punto y coma', ';', imp_detectar_separador("a;b;c\n1;2;3\n4;5;6"));
es('tabulacion',  "\t", imp_detectar_separador("a\tb\n1\t2\n3\t4"));

// Un remito pegado a mano NO esta separado en columnas: las comas de los
// importes no lo convierten en un CSV.
es('un remito no tiene separador', null, imp_detectar_separador(
    "REMITO N 0002-00008206\nFECHA: 24/07/2026\n1 Trasmission Shaft\n2 bolsas Urea granulada 45.300,00\nOBSERVACIONES:"
));
