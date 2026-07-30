-- ─── Hectáreas aplicadas por operación ────────────────────────────────────
-- Guarda las hectáreas a las que se aplicó el insumo en una operación de costos.
-- Por defecto es la superficie total del lote, pero el usuario puede declarar
-- un valor menor (o mayor) por gasto. Se usa para calcular el costo y el
-- descuento de stock con las hectáreas reales en lugar del lote completo.
-- NULL en filas viejas → se usa la superficie del lote como respaldo.
ALTER TABLE operaciones
    ADD COLUMN hectareas DECIMAL(10,2) NULL;
