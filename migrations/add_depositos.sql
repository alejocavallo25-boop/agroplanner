-- ─── Depósitos / Almacenes por usuario ───────────────────────────────────
CREATE TABLE IF NOT EXISTS depositos (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    nombre      VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NULL,
    ubicacion   VARCHAR(255) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ─── Agregar columna deposito_id a insumos ────────────────────────────────
ALTER TABLE insumos
    ADD COLUMN deposito_id INT NULL,
    ADD CONSTRAINT fk_insumos_deposito
        FOREIGN KEY (deposito_id) REFERENCES depositos(id) ON DELETE SET NULL;
