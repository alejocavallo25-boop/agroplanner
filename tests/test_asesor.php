<?php
/* Cuentas de asesor: lo que decide a dónde va, quién puede ser cliente y qué
   firma sale en los informes. Ver includes/asesor.php. */
require_once __DIR__ . '/../includes/asesor.php';

grupo('Asesor · a dónde va al cambiar de cuenta');
$agri  = ['agricultura' => true,  'tambo' => false, 'ganaderia' => false];
$tambo = ['agricultura' => false, 'tambo' => true,  'ganaderia' => false];
$nada  = ['agricultura' => false, 'tambo' => false, 'ganaderia' => false];
es('se queda en la pantalla si tiene el módulo', 'operaciones.php', asesor_destino('operaciones.php', $agri));
es('si no tiene el módulo va al inicio del suyo', 'tambo.php', asesor_destino('operaciones.php', $tambo));
es('sin módulos, a pendientes', 'pendientes.php', asesor_destino('tambo.php', $nada));
es('el panel del asesor siempre', 'asesor.php', asesor_destino('asesor.php', $tambo));
es('una ruta con carpetas se reduce al nombre', 'index.php', asesor_destino('../../index.php', $agri));
es('una dirección de afuera no pasa', 'index.php', asesor_destino('https://otro.sitio/x', $agri));
es('el panel de admin no es destino', 'index.php', asesor_destino('admin.php', $agri));

grupo('Asesor · quién puede ser cliente');
esVerdad('un usuario común sí', asesor_puede_ser_cliente(['id' => 7, 'role' => 'user'], 3));
esVerdad('él mismo no', !asesor_puede_ser_cliente(['id' => 3, 'role' => 'user'], 3));
esVerdad('un admin no', !asesor_puede_ser_cliente(['id' => 7, 'role' => 'admin'], 3));
esVerdad('la demo no', !asesor_puede_ser_cliente(['id' => 7, 'role' => 'user', 'solo_lectura' => 1], 3));
esVerdad('la demo no puede ser asesor', !puede_ser_asesor(['solo_lectura' => 1]));
esVerdad('sin la columna de la migración, sí puede', puede_ser_asesor(['id' => 1]));

grupo('Asesor · la sesión de un cliente nunca es admin');
$_SESSION = [];
sesion_cargar_cuenta(['id' => 9, 'username' => 'juan', 'role' => 'admin',
                      'has_agricultura' => 1, 'has_tambo' => 0, 'has_ganaderia' => 0], true);
es('rol forzado a user', 'user', $_SESSION['role']);
es('usuario del cliente', 9, $_SESSION['usuario_id']);
esVerdad('módulos del cliente', $_SESSION['modulos']['agricultura'] && !$_SESSION['modulos']['tambo']);
sesion_cargar_cuenta(['id' => 1, 'username' => 'yo', 'role' => 'admin',
                      'has_agricultura' => 1, 'has_tambo' => 1, 'has_ganaderia' => 1]);
es('en su propia cuenta conserva el rol', 'admin', $_SESSION['role']);
$_SESSION = [];

grupo('Asesor · logo');
// PNG de 1x1 transparente.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$uri = logo_a_data_uri($png);
esVerdad('un PNG se acepta', $uri !== null && str_starts_with($uri, 'data:image/png;base64,'));
esVerdad('lo que arma se reconoce como válido', logo_es_valido($uri));
es('un SVG no (puede llevar scripts)', 'NULL', var_export(logo_a_data_uri('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), true));
es('texto cualquiera no', 'NULL', var_export(logo_a_data_uri('hola'), true));
es('más grande que el tope no', 'NULL', var_export(logo_a_data_uri($png . str_repeat("\0", ASESOR_LOGO_MAX_BYTES)), true));
esVerdad('un data URI con comillas no es válido', !logo_es_valido('data:image/png;base64,AAAA" onerror="alert(1)'));
esVerdad('un SVG guardado no es válido', !logo_es_valido('data:image/svg+xml;base64,PHN2Zz4='));
esVerdad('null no es válido', !logo_es_valido(null));

grupo('Asesor · firma');
$f = firma_lineas(['username' => 'mgomez', 'firma_nombre' => '  ', 'firma_detalle' => null, 'firma_logo' => 'javascript:x']);
es('sin nombre firma con el usuario', 'mgomez', $f['nombre']);
es('sin detalle queda vacío', '', $f['detalle']);
es('un logo inválido se descarta', 'NULL', var_export($f['logo'], true));
$f = firma_lineas(['username' => 'mgomez', 'firma_nombre' => 'María Gómez', 'firma_detalle' => 'Ing. Agr. · Mat. 1234', 'firma_logo' => $uri]);
es('con nombre usa el nombre', 'María Gómez', $f['nombre']);
es('texto para el Excel', 'Elaborado por: María Gómez · Ing. Agr. · Mat. 1234', firma_texto($f));
$html = firma_html(['nombre' => '<b>X</b>', 'detalle' => '', 'logo' => null]);
esVerdad('el nombre va escapado', str_contains($html, '&lt;b&gt;X&lt;/b&gt;') && !str_contains($html, '<b>X'));
esVerdad('sin logo no hay imagen', !str_contains($html, '<img'));
esVerdad('con logo hay imagen', str_contains(firma_html($f), '<img src="data:image/png;base64,'));
