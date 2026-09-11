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
| `includes/motor.php` | El chat ("Cafrita"). Determinista, no es un LLM. |
| `api/consulta.php` | Puerta del chat (GET, sólo lectura). |
| `api/registrar*.php` | Guardan lo que el chat propone cargar (POST, con CSRF). |
| `migrations/` | Ver `migrations/LEEME.md`: sólo se corre `esquema_completo.sql`. |

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
- El recorte por etapa/rubro/proveedor sólo corre si la pregunta es de gasto:
  "¿cuánto rindió la cosecha?" pide el rinde, no el gasto en cosecha.
- Las preguntas que no entiende quedan en `motor_consultas_fallidas`: es la
  lista de qué falta enseñarle.

## Trampas de la base

- **Colaciones mezcladas en producción** (`1267 Illegal mix of collations`):
  toda comparación de texto entre tablas lleva `COLLATE utf8mb4_unicode_ci`
  explícito. No intentar normalizar las tablas: las FK lo hacen inviable.
- **Login, auth y bootstrap tienen que andar con y sin la migración nueva.**
  Una vez el login pidió una columna que no existía en producción y dio 500 a
  todos. Por eso lee `SELECT *` + `!empty($user['solo_lectura'])`.
- El deploy no toca la base: la migración va antes que el código que la usa.

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
