<?php
/**
 * Facturas y notas de pedido: el artículo primero y los números en columnas al
 * final, al revés que un remito. Sale de una nota de pedido real de un
 * distribuidor de agroquímicos (Rojosoft, impresa con "Microsoft Print to PDF")
 * que el importador no leía: el PDF no tenía ni una letra de texto, y aun
 * leyéndolo con el lector de fotos no reconocía ningún renglón.
 */
require_once __DIR__ . '/../includes/importador_insumos.php';

grupo('Nota de pedido · renglones con los números al final');
$r = imp_linea_a_item('CLETODIM FENDER 24% (cyo) x 20 LTS - 0000698 120,00 LTS 5,75 719,33 0,00');
es('nombre sin el código', 'CLETODIM FENDER 24% (cyo) x 20 LTS', $r['nombre'] ?? null);
es('código como referencia', '0000698', $r['referencia'] ?? null);
esCerca('cantidad', 120, $r['cantidad'] ?? 0);
es('unidad', 'LTS', $r['unidad'] ?? null);
esCerca('precio unitario, no importe ÷ cantidad', 5.75, $r['precio'] ?? 0);
$r = imp_linea_a_item('GLIFOSATO GLINOMYL FULL 88% x 15 KGS - 0000645 300,00 KGS 5,80 1.813,95 0,00');
esCerca('importe con miles', 5.8, $r['precio'] ?? 0);
$r = imp_linea_a_item('IMAZETAPYR 10.6% x 20 LTS - 0000055 120,00 LTS 4,55 569,21');
esCerca('sin la columna de descuento', 4.55, $r['precio'] ?? 0);
es('el 10.6% del nombre no se toma por número', 'IMAZETAPYR 10.6% x 20 LTS', $r['nombre'] ?? null);

grupo('Nota de pedido · la cuenta cantidad × precio ≈ importe');
$r = imp_linea_a_item('HERBICIDA SULFENTRAZONE x 10 LTS - 0000049 60,00 LTS 1895 1.185,32 0,00');
esCerca('coma de los centavos perdida al leer la foto: 1895 → 18,95', 18.95, $r['precio'] ?? 0);
$r = imp_linea_columnas_al_final('PRODUCTO RARO x 20 LTS 120,00 LTS 5,75 9.999,00 0,00');
es('si la cuenta no cierra, no se toma como este formato', 'NULL', var_export($r, true));
$r = imp_linea_columnas_al_final('GLIFOSATO 62 5,80 696,00');
es('dos números sueltos no alcanzan', 'NULL', var_export($r, true));

grupo('Nota de pedido · lo que no es mercadería');
es('cliente con su número', 'NULL', var_export(imp_linea_a_item('Cliente: 02369 - TAVAGNUTTI VANESA'), true));
es('vendedor con su legajo y el recargo pegado', 'NULL',
   var_export(imp_linea_a_item('01401 - ROSSI FRANCO MAXIMILIANO Bonificación/Recargo 248,31'), true));
es('código y nombre sueltos', 'NULL', var_export(imp_linea_a_item('02369 - TAVAGNUTTI VANESA'), true));

grupo('Nota de pedido · el comprobante entero, como texto');
$texto = "GRUPO AGROAPP S.A. NOTA DE PEDIDO 0002-00010794\n"
       . "9 DE JULIO 846, LA PUERTA | LA PUERTA (CORDOBA) Vencimiento: 31/05/2027\n"
       . "Cliente: 02369 - TAVAGNUTTI VANESA CUIT: 27-30968584-4\n"
       . "Condición de pago: 01. CUENTA CORRIENTE\n"
       . "Artículo Comprobante Cantidad UM Precio Importe Porcentaje Desc/Rec\n"
       . "CLETODIM FENDER 24% (cyo) x 20 LTS - 0000698 120,00 LTS 5,75 719,33 0,00\n"
       . "GLIFOSATO GLINOMYL FULL 88% x 15 KGS - 0000645 300,00 KGS 5,80 1.813,95 0,00\n"
       . "HERBICIDA SULFENTRAZONE x 10 LTS - 0000049 60,00 LTS 18,95 1.185,32 0,00\n"
       . "IMAZAPYR IMATRON 48% x 5 LTS - 0000706 5,00 LTS 31,50 164,19 0,00\n"
       . "IMAZETAPYR 10.6% x 20 LTS - 0000055 120,00 LTS 4,55 569,21 0,00\n"
       . "PARAQUAT 27.6% x 20 LTS - 0000057 240,00 LTS 3,25 813,15 0,00\n"
       . "S-METOLACLOR 96% x 20 LTS - 0000062 120,00 LTS 6,60 825,66 0,00\n"
       . "Vendedor Sub total 5.842,50\n"
       . "01401 - ROSSI FRANCO MAXIMILIANO Bonificación/Recargo 248,31\n"
       . "SON: SIETE MIL TRESCIENTOS SESENTA Y NUEVE DÓLARES CON 88/100\n"
       . "21/09/2026 Total 7.369,88\n";
$res = imp_procesar('texto', $texto, []);
es('siete renglones, ni el cliente ni el vendedor', 7, count($res['items']));
$suma = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio'], $res['items']));
esCerca('cantidad × precio suma el subtotal impreso', 5842.50, $suma, 0.001);
esVerdad('el subtotal impreso se encuentra para verificar', in_array(5842.5, array_column($res['totales'], 'valor')));
es('todos son agroquímicos', 'agroquimico', implode(',', array_unique(array_column($res['items'], 'tipo'))));
es('unidades: litros y kilos', 'lt,kg', implode(',', array_unique(array_column($res['items'], 'unidad_medida'))));
es('la moneda: dólares', 'USD', $res['moneda_sugerida']);

grupo('Moneda del comprobante');
es('importe en letras en pesos', 'ARS', imp_moneda_documento('SON: PESOS CIEN MIL CON 00/100'));
es('el símbolo U$S', 'USD', imp_moneda_documento('Precio U$S 5,75'));
es('sin pistas, no se adivina', 'NULL', var_export(imp_moneda_documento('Total 7.369,88'), true));
es('"en pesos" en letras gana a un U$S de la cotización', 'ARS',
   imp_moneda_documento("SON: PESOS ONCE MILLONES\ncotización U\$S 1514,50"));

grupo('PDF sin texto');
// Un PDF mínimo con las letras dibujadas: curvas y ninguna fuente.
$curvas = str_repeat("1.0 2.0 3.0 4.0 5.0 6.0 c\n", 600) . "f\n";
$pdf = "%PDF-1.7\n1 0 obj\n<< /Length " . strlen($curvas) . " >>\nstream\n" . $curvas . "endstream\nendobj\n%%EOF";
$tmp = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($tmp, $pdf);
$res = imp_procesar('pdf', $tmp, []);
esVerdad('se marca para leerlo como foto', $res['pdf_sin_texto']);
esVerdad('el aviso dice que las letras están dibujadas', str_contains((string)$res['aviso'], 'dibujadas'));
unlink($tmp);
