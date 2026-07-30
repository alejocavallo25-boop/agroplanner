-- ═══════════════════════════════════════════════════════════════
-- FIX: cultivos/campañas duplicados
-- ═══════════════════════════════════════════════════════════════
-- Causa: la app crea cultivos con cultivo_resolve(), que usa
--   INSERT ... ON DUPLICATE KEY UPDATE apoyado en el índice UNIQUE
--   uk_cultivo (usuario_id, lote_id, nombre, ciclo).
-- Si ese índice NO existe en la base, cada guardado de operación / venta /
--   lote / alquiler inserta una fila nueva → se acumulan cultivos idénticos
--   (ej: "Maíz (Tardío) · 25/26" repetido decenas de veces).
--
-- Este script: (0) normaliza ciclo NULL→'', (1) calcula la fila canónica
--   (menor id) por grupo, (2) repunta las referencias, (3) borra duplicados
--   y (4) crea el índice UNIQUE para que no vuelva a pasar.
--
-- Pegar tal cual en phpMyAdmin (Hostinger). Es seguro: no borra ventas ni
--   operaciones, solo fusiona las filas repetidas de la tabla cultivos.
-- ═══════════════════════════════════════════════════════════════

-- (0) Normalizar ciclo NULL → '' para que agrupe igual que cultivo_resolve().
UPDATE cultivos SET ciclo = '' WHERE ciclo IS NULL;

-- (1) Mapa duplicado → canónico (menor id) por (usuario_id, lote_id, nombre, ciclo).
DROP TEMPORARY TABLE IF EXISTS tmp_cultivo_map;
CREATE TEMPORARY TABLE tmp_cultivo_map AS
SELECT c.id AS dup_id, m.keep_id
FROM cultivos c
JOIN (
    SELECT usuario_id, lote_id, nombre, ciclo, MIN(id) AS keep_id
    FROM cultivos
    GROUP BY usuario_id, lote_id, nombre, ciclo
) m
  ON m.usuario_id = c.usuario_id
 AND m.lote_id    = c.lote_id
 AND m.nombre     = c.nombre
 AND m.ciclo      = c.ciclo
WHERE c.id <> m.keep_id;

-- (2) Repuntar todas las referencias a la fila canónica (antes de borrar).
UPDATE operaciones       o JOIN tmp_cultivo_map t ON o.cultivo_id = t.dup_id SET o.cultivo_id = t.keep_id;
UPDATE produccion_ventas p JOIN tmp_cultivo_map t ON p.cultivo_id = t.dup_id SET p.cultivo_id = t.keep_id;
UPDATE alquileres        a JOIN tmp_cultivo_map t ON a.cultivo_id = t.dup_id SET a.cultivo_id = t.keep_id;

-- (3) Borrar los cultivos duplicados (ya nadie los referencia).
DELETE c FROM cultivos c JOIN tmp_cultivo_map t ON c.id = t.dup_id;

DROP TEMPORARY TABLE IF EXISTS tmp_cultivo_map;

-- (4) Crear el índice UNIQUE que evita futuros duplicados.
--     (Si re-ejecutás y dice "Duplicate key name 'uk_cultivo'", ya está aplicado: ignoralo.)
ALTER TABLE cultivos ADD UNIQUE KEY uk_cultivo (usuario_id, lote_id, nombre, ciclo);
