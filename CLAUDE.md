# AgroPlanner

Gestión agropecuaria multi-usuario (agricultura, tambo, ganadería) para
productores argentinos. PHP 8 + PDO + MySQL/MariaDB, sin framework ni composer.
PWA. Qué es y para quién: `PRODUCT.md`. Cada usuario ve sólo lo suyo: toda
consulta filtra por `usuario_id` de la sesión.

## Correrlo

- Local: `http://localhost/agroplanner/` (XAMPP, PHP 8.0). Base `agro_planner`,
  `root` sin clave, vía `.env`. La base local está casi vacía: para probar el
  chat hay que cargar datos de prueba (y borrarlos después).
- Producción: Hostinger (PHP 8.3), `agroplanner.online`. "Anda en local" no
  garantiza que ande allá: verificar después de cada deploy.
- Pruebas: `C:\xampp\php\php.exe tests\correr.php` (funciones puras, sin base).
  Nuevas pruebas: `tests/test_*.php`, se toman solas.

## Mapa

| Archivo | Qué es |
|---|---|
| `config/auth.php` | Sesión, `require_*()` por módulo y el guard de solo lectura. |
| `config/database.php` | PDO. Fija la colación de la conexión (ver abajo). |
| `controllers/DashboardController.php` | `getGlobalStats()`: de acá salen los números del panel, los reportes y el chat. |
| `includes/motor.php` | El chat ("Cafrita") de agricultura. Determinista, no es un LLM. |
| `includes/motor_tambo.php` | El chat del tambo. Motor aparte; reusa de `motor.php` sólo lo de lenguaje. |
| `includes/chat_ruteo.php` | Qué motor contesta. Decide la pantalla; cambia sólo ante señales inequívocas. |
| `api/consulta.php`, `api/consulta_tambo.php` | Puertas del chat (GET, sólo lectura). |
| `api/registrar*.php` | Guardan lo que el chat propone cargar (POST, con CSRF). |
| `migrations/` | Ver `migrations/LEEME.md`: sólo se corre `esquema_completo.sql`. |
| `includes/ia.php` | Cadena de IA gratis (Gemini → Groq → OpenRouter). Ver "IA" abajo. |
| `includes/asesor.php` | Cuentas de asesor: cambio de cuenta, vínculos y firma. Ver "Asesores". |

## El chat (`includes/motor.php`)

- **Nunca inventa un número.** Mapea la pregunta a una métrica de un catálogo
  cerrado y la cuenta la hace la base con los mismos filtros que el panel. Si el
  chat y el panel dan distinto, está mal el chat.
- `motor_responder()` es una cascada de ramas: **la primera que reconoce algo se
  queda con la pregunta**. El error típico no es un 500 sino contestar con
  seguridad un número de otra pregunta. Al agregar una rama, pensar el orden y
  probar con preguntas de las ramas vecinas.
- **Nombres cargados por el productor** (proveedor, que es texto libre): se
  buscan con `motor_nombra()`, palabra entera y 3 letras como mínimo. No con
  `strpos`/`motor_coincide()`: un proveedor tipeado "n" aparecía dentro de
  "cuánto" y se quedaba con cualquier pregunta. Lotes y cultivos todavía usan
  `strpos` (un "Lote 1" aparece dentro de "lote 12").
- **La moneda de un gasto cargado es la que DICE la frase** ("gasté 850
  dólares", `motor_moneda_dicha()`), no la que el chat está mirando. Antes se
  ignoraba y se guardaba $850 pesos. Si la frase no la dice, el paso a paso la
  pregunta (paso `moneda`, antes del primer monto). Durante la carga,
  `api/consulta.php` no devuelve esa moneda como moneda de lectura del chat.
- El recorte por etapa/rubro/proveedor sólo corre si la pregunta es de gasto:
  "¿cuánto rindió la cosecha?" pide el rinde, no el gasto en cosecha.
- El chat manda las cargas con `URLSearchParams`: una lista de objetos (los
  `insumos` de una pulverización) tiene que ir con `JSON.stringify`, o llega
  como `[object Object]` y se guardaba un solo insumo.
- "Corregir" en la confirmación de un gasto devuelve `alta_estado` (la carga con
  `insumos_lista`), no `alta`: esos son los datos ya armados para guardar y una
  aplicación de varios insumos volvía con uno solo.
- Las preguntas que no entiende quedan en `motor_consultas_fallidas` (las del
  tambo con `[tambo]` en el motivo): es la lista de qué falta enseñarle.
- **Tambo**: no sumarle ramas a `motor.php` ("margen" y "cuánto gasté" existen en
  los dos módulos). Una pantalla se suma al chat con `$chat_modulo = 'tambo';`
  antes del require de `includes/chat_motor.php`. Las frases se buscan como
  palabras enteras: "sa*que en* agosto" se leía como comparación.

## Gastos (`operaciones.php`)

- **Multilote = una operación por lote**, guardadas en una sola transacción, y
  con el mismo `carga_id` (NULL = gasto suelto). Reparto por ha: precio/ha y
  dosis/ha iguales; en "Cant. Total" se divide por la suma de las ha. Los campos
  van con el id del lote como clave (`multi_ha[12]`); el servidor entra en
  multilote si llega `multi_lote[]`. La edición en el modal es de un lote.
- **Guardar, editar y borrar un gasto pasa por `includes/carga_lotes.php`**
  (`operacion_insertar`, `operacion_costo`, `operacion_borrar`). No volver a
  copiar la cuenta del costo ni el descuento de stock en otra pantalla.
- **Para varios lotes hay un solo camino: "Repartir en varios lotes"** del
  formulario. Hubo una planilla aparte (`carga_lotes.php`) y se sacó: hacía lo
  mismo y confundía. Para quien tiene muchos lotes, la lista tiene botones por
  cultivo actual (tildan todos esos lotes), buscador y "ver sólo los elegidos";
  "Todos/Ninguno" actúan sobre lo que se ve. La tabla `plantillas_labor` quedó
  en la base sin uso.

## Stock (`insumos.php`)

- **Los filtros son enlaces, y viven en tres lugares.** `urlFiltro()` arma la URL
  conservando los demás parámetros; el WHERE los aplica; y `$exp_params` se los
  pasa a `api/reporte_excel.php` y `api/reporte_pdf.php`. Un filtro nuevo que no
  se agregue a los tres hace que el archivo que baja el productor no sea lo que
  tiene en pantalla.
- **El orden sale de la lista blanca `ORDENES`**, nunca de interpolar el GET.
- Una sola lista de depósitos: los chips son a la vez el filtro y el resumen. No
  volver a poner tarjetas aparte — eso duplicaba los mismos nombres dos veces en
  la misma pantalla.
- **Importador** (`includes/importador_insumos.php`): lee remitos (cantidad
  adelante) y facturas/notas de pedido (artículo primero, números al final;
  la cuenta cantidad × precio ≈ importe decide qué columna es cuál). El filtro
  de renglones está **dos veces**: `imp_linea_a_item()` en PHP e
  `impRenglonesUtiles()` en `insumos.php`, para lo que lee el lector de fotos.
  Cambiar uno sin el otro hace que la pantalla muestre renglones que después
  desaparecen. Pruebas: `tests/test_importador*.php`.
- **PDF sin texto** (escaneados, o "Microsoft Print to PDF" que dibuja las
  letras: cero fuentes): el servidor contesta `pdf_sin_texto` y el navegador
  pasa las páginas a imagen con pdf.js (`assets/vendor/pdfjs`, 3.11.174,
  `isEvalSupported:false` por la CSP) y las lee como una foto.

## Tambo

- **Todo es mensual**: producción, egresos y carne se guardan con `fecha = YYYY-MM-01`.
  Los litros del mes van enteros en `litros_manana` (`litros_tarde` queda en 0).
- **La carne se guarda prorrateada**: `monto_total = monto_original / 12`, y la
  diferencia de inventario dividida por períodos, que se guardan en
  `cantidad_animales` (no son animales). Sumar siempre `monto_total`.
- **Los números del mes salen de `includes/tambo_stats.php`** (panel, comparativa
  y chat). No volver a copiar las consultas en una pantalla: antes había dos
  copias y con un mes sin dólar cargado daban distinto.

## IA (`includes/ia.php`)

- **Complemento, nunca camino único**, y **la IA sólo transcribe**: lo leído
  entra por una puerta que ya existía (cuadro de texto del importador, carga de
  gastos del motor) y se confirma ahí. Sin claves en el `.env`, todo como antes.
- Claves del dueño en el `.env`, **entre comillas** (sin ellas un caracter raro
  rompe `parse_ini_file` y se pierde todo el `.env`, base incluida). Cupo de
  toda la app: topes por usuario y registro en `ia_uso`.
- Foto del chat (`includes/diagnostico_ia.php`): planta, remito o factura.
  Diagnóstico sin marcas ni dosis — se filtran en el servidor y el aviso del
  agrónomo lo pone el servidor; no aflojar eso. Los formatos IA→parser tienen
  pruebas de ida y vuelta (`tests/test_*_ia.php`): correrlas si se tocan.
- Gratis = frágil: los modelos cambian sin aviso (se pisan con `IA_*_MODELO`),
  Gemini da 503 por rachas largas (503/429/timeout ponen en pausa), Groq limita
  la respuesta por minuto (`max_tokens`), y el respaldo no puede ser de Google.
- Dictado: Whisper ante el silencio repite la pista (`ia_voz_es_alucinacion()`).

## Trampas de la base

- **Colaciones mezcladas en producción** (`1267 Illegal mix of collations`):
  toda comparación de texto entre tablas lleva `COLLATE utf8mb4_unicode_ci`
  explícito. No intentar normalizar las tablas: las FK lo hacen inviable.
- **Login, auth y bootstrap tienen que andar con y sin la migración nueva.**
  Una vez el login pidió una columna que no existía en producción y dio 500 a
  todos. Por eso lee `SELECT *` + `!empty($user['solo_lectura'])`.
- El deploy no toca la base: la migración va antes que el código que la usa.

## Asesores (`includes/asesor.php`)

- Un asesor entra a las cuentas de sus clientes (`asesor_clientes`; las vincula
  el admin en `admin.php` o el asesor al crear una cuenta en `asesor.php`).
  **Al entrar, `usuario_id` pasa a ser el del cliente** y la cuenta real queda
  en `$_SESSION['asesor_id']`: ninguna consulta sabe de asesores, y así tiene
  que seguir. No agregar "o es cliente del asesor" a los WHERE.
- `config/auth.php` revisa el vínculo en cada pedido (sólo si hay `asesor_id`).
  Dentro de un cliente el rol es siempre `user`. Admins y demo no son clientes.
- Al cambiar de cuenta se borra el `sessionStorage` (memoria del chat, remito de
  la IA) y se rota el CSRF: si no, una carga a medio hacer o una pestaña vieja
  guardaba en el cliente equivocado. `header.php` recarga las otras pestañas.
- Pantallas nuevas de un módulo: sumarlas a `ASESOR_PAGINAS_MODULO`, o al
  cambiar de cuenta se va al inicio en vez de quedarse ahí.
- Informes nuevos: `firma_informe($pdo)` + `firma_html()` / `firma_texto()`.

## Cuenta corriente (`includes/cuenta_corriente.php`)

- Proveedores y sus movimientos (`proveedores`, `cc_movimientos`): compra suma
  deuda; pago y nota de crédito la bajan. Monto siempre positivo, el signo lo
  da el tipo. Es de todos los módulos (`cuenta_corriente.php`, menú "Comercial").
- **Pesos y dólares nunca se suman.** Un pago en la otra moneda se convierte
  una vez, al cargarlo, con la cotización que pone el productor, y se guarda
  lo pagado y a cuánto (`pagado_moneda`, `pagado_monto`, `cotizacion`).
- Los pagos se aplican a la factura que vence primero (`cc_pendientes`, FIFO):
  de ahí sale qué está vencido. La tasa es informativa: no se calculan intereses.
- Al importar una factura en Insumos se puede anotar la compra (misma
  transacción que el stock). Anda sin la migración: `cc_tablas_listas()`.
- En un formulario, una función con el mismo nombre que el `id` de un control
  no se ejecuta desde `onchange` (el navegador resuelve el control): pasó acá.

## Pedidos de presupuesto (`includes/presupuestos.php`)

- Pedido → Excel para el proveedor → se suben las respuestas → comparación.
  El Excel es un **.xlsx de verdad armado a mano** (`pp_xlsx`, ZIP con XML), no
  el HTML con extensión .xls de los reportes: ese el proveedor no lo puede
  devolver bien. Hoja protegida sin contraseña; celdas amarillas libres.
- Cada renglón se reconoce al volver por la columna "Cód." (`orden`) y el
  archivo por el número `PP-000045`, no por la posición. Reenviar el archivo de
  un proveedor reemplaza su oferta. Precios por WhatsApp/PDF: carga a mano.
- Monedas mezcladas: se comparan pasadas a dólares con el dólar de referencia,
  sólo en pantalla; cada oferta se guarda en su moneda.
- **El chat también recibe las respuestas** (clip → `api/presupuesto_chat.php`,
  sin IA): deduce el pedido del `PP-…` del archivo y contesta con
  `pp_resumen_chat()`. Pantalla y chat leen con la misma `pp_cargar_respuesta()`
  y comparan con la misma `pp_comparar()`: no duplicar ninguna de las dos.
- Pruebas de ida y vuelta con un .xlsx de verdad en `tests/test_presupuestos.php`.
  Las hojas de algunos programas vienen con ruta absoluta ("/xl/worksheets/…"):
  `imp_parse_xlsx` lo contempla; antes rechazaba esos archivos.

## Cuenta demo — solo lectura

`users.solo_lectura = 1`. `config/auth.php` corta todo POST de esas cuentas,
con lista blanca (`POST_SOLO_LECTURA_PERMITIDOS`): una acción nueva queda
bloqueada sola. El banner de `includes/header.php` es cortesía, no el bloqueo.

## Deploy

- Repo `alejocavallo25-boop/agroplanner` (privado), rama `main`. Producción se
  publica desde el repo con el Git de Hostinger.
- Git no está en el PATH: usar el de GitHub Desktop,
  `%LOCALAPPDATA%\GitHubDesktop\app-<la más nueva>\resources\app\git\cmd\git.exe`.
  Toma `credential.helper = manager` del gitconfig de GitHub Desktop.
- El `.env` de producción vive sólo en el servidor (`public_html/.env`).
- Hostinger corre PHP como FastCGI: `php_flag`/`php_value` en `.htaccess` se
  ignoran. Los errores van a `php-errors.log` por `config/errors.php`.
- El `.htaccess` bloquea `.md`, `.sql`, `.log` y `.gitignore`: todo lo demás
  del repo queda público en `public_html`.
