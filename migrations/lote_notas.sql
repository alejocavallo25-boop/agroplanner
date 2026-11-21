-- migrations/lote_notas.sql
--
-- Libreta del lote: notas con fecha que el productor anota sobre un lote
-- ("malezas en la cabecera", "piedra el 10/11"), aparte de los gastos. Se
-- cargan y se ven en lotes.php. La campaña es la que tenía el lote al anotar.
--
-- Historial, como las otras sueltas: en producción se corre
-- esquema_completo.sql, que ya trae todo esto. El código anda sin la tabla
-- (lote_notas_lista() en includes/lote_notas.php).

CREATE TABLE IF NOT EXISTS lote_notas (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    usuario_id  INT(11) NOT NULL,
    lote_id     INT(11) NOT NULL,
    fecha       DATE NOT NULL,
    campania    VARCHAR(50) NULL DEFAULT NULL COMMENT 'La del lote al anotar',
    texto       TEXT NOT NULL,
    creado_en   TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY idx_lote_notas_lote (usuario_id, lote_id, fecha),
    KEY fk_lote_notas_lote (lote_id),
    CONSTRAINT fk_lote_notas_lote FOREIGN KEY (lote_id) REFERENCES lotes (id) ON DELETE CASCADE,
    CONSTRAINT fk_lote_notas_usuario FOREIGN KEY (usuario_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para revertir:
--   DROP TABLE lote_notas;
