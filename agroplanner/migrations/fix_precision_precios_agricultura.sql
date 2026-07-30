-- ═══════════════════════════════════════════════════════════════
-- Precisión de precios en Agricultura → permitir 3+ decimales
-- ═══════════════════════════════════════════════════════════════
-- Los formularios ahora aceptan hasta 4 decimales en los precios
-- (step="0.0001"). Para que la base no los redondee, las columnas de
-- precio/monto deben tener al menos 4 decimales.
--
-- Las columnas de operaciones / insumos ya se ampliaron en
--   fix_precision_precios_operaciones.sql
-- Acá faltan: precio de venta, monto de alquiler y costo de alquiler del lote.
--
-- Pegar en phpMyAdmin (Hostinger). Seguro y no destructivo.
-- Nota: si el diagnóstico muestra que alguna columna admite NULL,
--   ajustá el NOT NULL/DEFAULT de esa línea para respetar la definición actual.
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE `produccion_ventas`
    MODIFY COLUMN `precio_kg` DECIMAL(14,4) NOT NULL DEFAULT 0;

ALTER TABLE `alquileres`
    MODIFY COLUMN `monto_pagado` DECIMAL(14,4) NOT NULL DEFAULT 0;

ALTER TABLE `lotes`
    MODIFY COLUMN `costo_alquiler_tns_ha` DECIMAL(14,4) NULL DEFAULT NULL;
