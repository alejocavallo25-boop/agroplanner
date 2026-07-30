-- Migración: Agregar soporte de imputación flexible a la tabla alquileres
-- Ejecutar en agro_planner DB

ALTER TABLE alquileres 
    ADD COLUMN IF NOT EXISTS nivel_imputacion ENUM('lote', 'cultivo', 'campania') NOT NULL DEFAULT 'lote' AFTER campania,
    ADD COLUMN IF NOT EXISTS cultivo_id INT NULL AFTER nivel_imputacion;

-- Hacer lote_id nullable (para alquileres imputados solo a una campaña general)
ALTER TABLE alquileres 
    MODIFY COLUMN lote_id INT NULL;

-- Foreign key hacia cultivos (si no existe ya)
ALTER TABLE alquileres 
    ADD CONSTRAINT IF NOT EXISTS fk_alquileres_cultivo 
    FOREIGN KEY (cultivo_id) REFERENCES cultivos(id) ON DELETE SET NULL;
