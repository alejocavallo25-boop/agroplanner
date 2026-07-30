-- migrations/add_users_solo_lectura.sql
--
-- Cuentas de solo lectura (modo demostración).
--
-- Un usuario con solo_lectura = 1 puede recorrer todo el sistema y ver los datos de
-- SU propia cuenta, pero no puede crear, editar ni borrar nada. El bloqueo real vive
-- en config/auth.php, que corta cualquier POST antes de que llegue al handler de la
-- página; esta columna es sólo el interruptor, para poder marcar una cuenta sin
-- tocar código.
--
-- Se usa una columna en la base (y no el email hardcodeado) para que mañana se pueda
-- crear otra demo, o desactivar ésta, con un UPDATE.

ALTER TABLE users
    ADD COLUMN solo_lectura TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Cuenta de demostración: puede ver, no puede modificar';

-- Marcar la cuenta demo publicada en el portfolio.
-- Ajustar el email si alguna vez cambia.
UPDATE users SET solo_lectura = 1 WHERE email = 'demo@gmail.com';

-- Para revertir:
--   UPDATE users SET solo_lectura = 0 WHERE email = 'demo@gmail.com';
--   ALTER TABLE users DROP COLUMN solo_lectura;
