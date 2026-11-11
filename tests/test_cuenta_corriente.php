<?php
/* Cuenta corriente con proveedores: saldos por moneda, pagos aplicados a la
   factura que vence primero, vencimientos y lo que se acepta al cargar.
   Ver includes/cuenta_corriente.php. */
require_once __DIR__ . '/../includes/cuenta_corriente.php';

$mov = fn($id, $prov, $tipo, $moneda, $monto, $fecha, $venc = null) =>
    ['id' => $id, 'proveedor_id' => $prov, 'tipo' => $tipo, 'moneda' => $moneda, 'monto' => $monto,
     'fecha' => $fecha, 'vencimiento' => $venc, 'comprobante' => null, 'concepto' => null, 'forma_pago' => null];

grupo('Cuenta corriente · saldos');
$movs = [
    $mov(1, 7, 'compra', 'USD', 7369.88, '2026-09-21', '2027-05-31'),
    $mov(2, 7, 'compra', 'USD', 1000, '2026-10-01', '2026-11-01'),
    $mov(3, 7, 'pago', 'USD', 1500, '2026-11-05'),
    $mov(4, 7, 'compra', 'ARS', 250000, '2026-10-10', '2026-11-20'),
    $mov(5, 9, 'compra', 'USD', 300, '2026-10-01'),
    $mov(6, 9, 'nota_credito', 'USD', 500, '2026-10-02'),
];
$s = cc_saldos($movs);
esCerca('proveedor 7 en dólares: compras menos pago', 6869.88, $s[7]['USD']);
esCerca('proveedor 7 en pesos, aparte', 250000, $s[7]['ARS']);
esCerca('saldo a favor: nota de crédito mayor que la compra', -200, $s[9]['USD']);

grupo('Cuenta corriente · los pagos van a la factura que vence primero');
$p = cc_pendientes($movs);
$por = [];
foreach ($p as $x) $por[$x['id']] = $x['pendiente'];
es('la factura que vencía antes quedó pagada', 'no', isset($por[2]) ? 'sí' : 'no');
esCerca('el resto del pago bajó la otra', 7369.88 - 500, $por[1] ?? 0);
esCerca('la de pesos no la toca un pago en dólares', 250000, $por[4] ?? 0);
es('con saldo a favor no queda nada pendiente', 'no', isset($por[5]) ? 'sí' : 'no');
es('orden: primero la que vence antes', '4,1', implode(',', array_column($p, 'id')));
// Sin vencimiento: se ordena por fecha.
$p2 = cc_pendientes([$mov(10, 1, 'compra', 'ARS', 100, '2026-01-10'), $mov(11, 1, 'compra', 'ARS', 100, '2026-01-05'), $mov(12, 1, 'pago', 'ARS', 100, '2026-02-01')]);
es('sin vencimiento, el pago va a la más vieja', '10', implode(',', array_column($p2, 'id')));

grupo('Cuenta corriente · vencimientos');
es('ya pasó', 'vencida', cc_estado('2026-11-01', '2026-11-11'));
es('vence hoy no está vencida', 'proxima', cc_estado('2026-11-11', '2026-11-11'));
es('dentro de 15 días', 'proxima', cc_estado('2026-11-26', '2026-11-11'));
es('más adelante', 'al_dia', cc_estado('2027-05-31', '2026-11-11'));
es('sin fecha', 'sin_fecha', cc_estado(null, '2026-11-11'));
$r = cc_resumen($p, '2026-11-11');
esCerca('deuda en dólares', 6869.88, $r['USD']['deuda']);
esCerca('vencido en pesos: nada todavía', 0, $r['ARS']['vencido']);
esCerca('en pesos vence en 30 días', 250000, $r['ARS']['proximo']);
$r2 = cc_resumen(cc_pendientes([$mov(20, 1, 'compra', 'USD', 80, '2026-10-01', '2026-10-15')]), '2026-11-11');
esCerca('lo vencido se cuenta como vencido', 80, $r2['USD']['vencido']);

grupo('Cuenta corriente · lo que se acepta al cargar');
$propios = [7 => true];
[$m, $e] = cc_normalizar(['tipo' => 'compra', 'proveedor_id' => 7, 'fecha' => '2026-09-21', 'moneda' => 'USD',
    'monto' => '7.369,88', 'comprobante' => '0002-00010794', 'vencimiento' => '2027-05-31',
    'condicion' => 'canje', 'canje_cultivo' => 'Soja', 'canje_toneladas' => '25,5', 'tasa_mensual' => '0,5'], $propios);
es('compra en canje: sin errores', '', implode(' | ', $e));
esCerca('importe con miles y coma', 7369.88, $m['monto']);
es('canje', 'canje|Soja|25.5', $m['forma_pago'] . '|' . $m['canje_cultivo'] . '|' . $m['canje_toneladas']);
esCerca('tasa', 0.5, $m['tasa_mensual']);

[$m, $e] = cc_normalizar(['tipo' => 'pago', 'proveedor_id' => 7, 'fecha' => '2026-11-05', 'moneda' => 'USD',
    'otra_moneda' => '1', 'pagado_monto' => '1.514.500', 'cotizacion' => '1514,50', 'forma_pago' => 'transferencia'], $propios);
es('pago en pesos a una cuenta en dólares: sin errores', '', implode(' | ', $e));
esCerca('baja la deuda en dólares a la cotización dada', 1000, $m['monto']);
es('guarda lo que se pagó y a cuánto', 'ARS|1514500|1514.5', $m['pagado_moneda'] . '|' . $m['pagado_monto'] . '|' . $m['cotizacion']);

[$m, $e] = cc_normalizar(['tipo' => 'pago', 'proveedor_id' => 7, 'fecha' => '2026-11-05', 'moneda' => 'ARS',
    'otra_moneda' => '1', 'pagado_monto' => '100', 'cotizacion' => '1500'], $propios);
esCerca('pago en dólares a una cuenta en pesos', 150000, $m['monto']);
es('forma de pago desconocida queda como otro', 'otro', $m['forma_pago']);

$err = fn($p) => implode(' ', cc_normalizar($p, $propios)[1]);
esVerdad('proveedor ajeno se rechaza', str_contains($err(['tipo' => 'compra', 'proveedor_id' => 99, 'fecha' => '2026-01-01', 'monto' => '5']), 'proveedor'));
esVerdad('sin importe se rechaza', str_contains($err(['tipo' => 'compra', 'proveedor_id' => 7, 'fecha' => '2026-01-01', 'monto' => '']), 'importe'));
esVerdad('vencimiento antes de la compra se rechaza', str_contains($err(['tipo' => 'compra', 'proveedor_id' => 7, 'fecha' => '2026-05-01', 'vencimiento' => '2026-04-01', 'monto' => '5']), 'anterior'));
esVerdad('canje sin grano se rechaza', str_contains($err(['tipo' => 'compra', 'proveedor_id' => 7, 'fecha' => '2026-05-01', 'monto' => '5', 'condicion' => 'canje']), 'grano'));
esVerdad('pago en otra moneda sin cotización se rechaza', str_contains($err(['tipo' => 'pago', 'proveedor_id' => 7, 'fecha' => '2026-05-01', 'otra_moneda' => '1', 'pagado_monto' => '100']), 'cotización'));
esVerdad('fecha que no existe se rechaza', str_contains($err(['tipo' => 'pago', 'proveedor_id' => 7, 'fecha' => '2026-02-30', 'monto' => '5']), 'fecha'));
esVerdad('tasa absurda se rechaza', str_contains($err(['tipo' => 'compra', 'proveedor_id' => 7, 'fecha' => '2026-05-01', 'monto' => '5', 'tasa_mensual' => '80']), 'tasa'));
esVerdad('tipo inventado se rechaza', str_contains($err(['tipo' => 'regalo', 'proveedor_id' => 7, 'fecha' => '2026-05-01', 'monto' => '5']), 'compra'));

grupo('Cuenta corriente · formato');
es('dólares', 'US$ 7.369,88', cc_plata(7369.88, 'USD'));
es('pesos', '$ 250.000,00', cc_plata(250000, 'ARS'));
esCerca('número con punto decimal', 1234.5, cc_num('1234.5'));
esCerca('miles con punto sin decimales', 1514500, cc_num('1.514.500'));
