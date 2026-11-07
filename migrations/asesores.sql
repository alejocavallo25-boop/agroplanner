-- migrations/asesores.sql
--
-- Cuentas de asesor: un asesor trabaja en las cuentas de sus clientes.
-- Ver includes/asesor.php.
--
-- Historial, como las otras sueltas: en producción se corre
-- esquema_completo.sql, que ya trae todo esto.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS es_asesor TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Puede entrar a las cuentas vinculadas en asesor_clientes',
    ADD COLUMN IF NOT EXISTS firma_nombre VARCHAR(150) NULL DEFAULT NULL
        COMMENT 'Nombre que firma los informes del asesor',
    ADD COLUMN IF NOT EXISTS firma_detalle VARCHAR(150) NULL DEFAULT NULL
        COMMENT 'Segunda línea de la firma: título, matrícula, teléfono',
    ADD COLUMN IF NOT EXISTS firma_logo MEDIUMTEXT NULL DEFAULT NULL
        COMMENT 'Logo de la firma como data URI (png, jpg o webp)';

CREATE TABLE IF NOT EXISTS asesor_clientes (
    asesor_id  INT(11) NOT NULL,
    cliente_id INT(11) NOT NULL,
    origen     ENUM('admin','asesor') NOT NULL DEFAULT 'admin'
        COMMENT 'Quién hizo el vínculo: el admin desde su panel o el asesor al crear la cuenta',
    creado_en  TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (asesor_id, cliente_id),
    KEY idx_cliente (cliente_id),
    CONSTRAINT fk_asesor_clientes_asesor  FOREIGN KEY (asesor_id)  REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_asesor_clientes_cliente FOREIGN KEY (cliente_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para revertir:
--   DROP TABLE asesor_clientes;
--   ALTER TABLE users DROP COLUMN es_asesor, DROP COLUMN firma_nombre,
--                     DROP COLUMN firma_detalle, DROP COLUMN firma_logo;
