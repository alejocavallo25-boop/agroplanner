-- migrations/normalize_collation_utf8mb4_unicode_ci.sql
--
-- PROBLEMA QUE RESUELVE
-- Ninguno de los CREATE TABLE del proyecto declaraba COLLATE, sólo
-- "DEFAULT CHARSET=utf8mb4". Cada tabla quedó entonces con la colación que el
-- servidor tuviera por defecto cuando se creó, y terminaron mezcladas:
-- unas en utf8mb4_general_ci y otras en utf8mb4_unicode_ci.
--
-- Al comparar texto entre dos tablas con colación distinta, MySQL aborta con:
--   1267 Illegal mix of collations (utf8mb4_general_ci) and (utf8mb4_unicode_ci)
--
-- Fue lo que tiró abajo el tablero: DashboardController.php hace
--   COALESCE(cultivos.nombre, produccion_ventas.cultivo_vendido) = ?
-- y esas dos columnas estaban en colaciones distintas.
--
-- Se normaliza todo a utf8mb4_unicode_ci (no general_ci) porque ordena y compara
-- correctamente los acentos y la ñ, que es lo que corresponde para datos en español.
--
-- ANTES DE CORRER: hacé un respaldo de la base (phpMyAdmin -> Exportar, o el
-- backup automático del hPanel). Convertir tablas reescribe los índices.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 1 — Ver qué tablas están fuera de norma (sólo consulta, no cambia nada)
-- ─────────────────────────────────────────────────────────────────────────────
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'
ORDER BY TABLE_NAME;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 2 — Generar los ALTER necesarios.
-- Ejecutá esto, copiá la columna de resultados y pegala como nueva consulta.
-- Sólo genera líneas para las tablas que hagan falta.
-- ─────────────────────────────────────────────────────────────────────────────
SELECT CONCAT(
         'ALTER TABLE `', TABLE_NAME,
         '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
       ) AS sentencia
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
  AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'
ORDER BY TABLE_NAME;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 3 — Que las tablas NUEVAS nazcan ya con la colación correcta.
-- Reemplazá el nombre si la base se llama distinto.
-- ─────────────────────────────────────────────────────────────────────────────
-- ALTER DATABASE `u515247413_agroplanner`
--     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- PASO 4 — Verificar que no quedó ninguna fuera de norma.
-- Tiene que devolver 0 filas.
-- ─────────────────────────────────────────────────────────────────────────────
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
  AND TABLE_COLLATION <> 'utf8mb4_unicode_ci';
