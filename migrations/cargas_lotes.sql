-- migrations/cargas_lotes.sql
--
-- Carga por lotes (carga_lotes.php): la misma labor en varios lotes de una vez.
-- Ver includes/carga_lotes.php.
--
-- Historial, como las otras sueltas: en producción se corre
-- esquema_completo.sql, que ya trae todo esto.

-- Los gastos que salieron de una misma carga comparten este número: así se
-- editan, se borran y se repiten juntos. NULL = gasto suelto.
ALTER TABLE operaciones
    ADD COLUMN IF NOT EXISTS carga_id CHAR(36) NULL DEFAULT NULL
        COMMENT 'Gastos cargados juntos en varios lotes: se editan y se borran juntos'
        AFTER idempotencia;

-- Plantillas de labor: "Siembra soja" con su labor, insumos y dosis.
CREATE TABLE IF NOT EXISTS plantillas_labor (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    usuario_id  INT(11) NOT NULL,
    nombre      VARCHAR(100) NOT NULL,
    datos       TEXT NOT NULL COMMENT 'JSON: grupo, tipo, labor, precio, moneda e insumos con dosis',
    creado_en   TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_plantilla_nombre (usuario_id, nombre),
    CONSTRAINT fk_plantillas_labor_usuario FOREIGN KEY (usuario_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para revertir:
--   DROP TABLE plantillas_labor;
--   ALTER TABLE operaciones DROP COLUMN carga_id;
