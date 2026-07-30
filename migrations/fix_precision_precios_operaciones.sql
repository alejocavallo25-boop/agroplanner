-- ═══════════════════════════════════════════════════════════════
-- FIX: costo estimado ≠ costo guardado en operaciones
-- ═══════════════════════════════════════════════════════════════
-- Causa: el formulario acepta precios/cantidades con 4 decimales
--   (step="0.0001"), pero las columnas guardaban con 2 decimales.
--   Ej: precio 0.945 → se guardaba 0.95 en operacion_insumos, mientras
--   que costo_total ya se había calculado con 0.945. Al reabrir la
--   operación el modal recalcula con 0.95 y muestra un total distinto.
--
-- Solución: ampliar la precisión a 4 decimales en las columnas de
--   precio y cantidad para que no se pierda información.
--
-- Pegar en phpMyAdmin (Hostinger). Seguro y no destructivo.
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE `operacion_insumos`
    MODIFY COLUMN `precio_unitario` DECIMAL(14,4) NOT NULL DEFAULT 0,
    MODIFY COLUMN `cantidad_ha`     DECIMAL(14,4) NOT NULL DEFAULT 0;

ALTER TABLE `operaciones`
    MODIFY COLUMN `precio_unitario` DECIMAL(14,4) NOT NULL DEFAULT 0,
    MODIFY COLUMN `cantidad_ha`     DECIMAL(14,4) NOT NULL DEFAULT 0;

ALTER TABLE `insumos`
    MODIFY COLUMN `precio_estimado_usd` DECIMAL(14,4) NOT NULL DEFAULT 0;
