-- Agregar campos de cantidad, unidad de medida y moneda a egresos
ALTER TABLE tambo_egresos
    ADD COLUMN IF NOT EXISTS cantidad       DECIMAL(12,3) DEFAULT NULL   AFTER concepto,
    ADD COLUMN IF NOT EXISTS unidad         ENUM('kg','lt','unidad') DEFAULT 'unidad' AFTER cantidad,
    ADD COLUMN IF NOT EXISTS precio_unitario DECIMAL(14,4) DEFAULT NULL  AFTER unidad,
    ADD COLUMN IF NOT EXISTS moneda         ENUM('ARS','USD') DEFAULT 'ARS' AFTER precio_unitario;
