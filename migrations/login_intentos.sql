-- ---------------------------------------------------------------------------
-- Freno contra la prueba de contraseñas en el login
--
-- Medido en producción: diez intentos seguidos con contraseña incorrecta
-- tardaron 43, 42 y 36 milisegundos. Ni demora, ni bloqueo, ni aviso. A esa
-- velocidad, una contraseña como "carlos1985" se encuentra en minutos.
--
-- CÓMO FRENA
-- Se anota cada intento fallido con la IP y el correo. Antes de comprobar la
-- contraseña se cuenta cuántos hubo en los últimos quince minutos: si pasan de
-- ocho, se rechaza sin siquiera mirar la clave.
--
-- Se cuenta por las DOS COSAS, y hace falta que sean las dos:
--   por CORREO, porque si no, se ataca una cuenta desde muchas IP;
--   por IP,     porque si no, se prueban muchas cuentas desde una sola máquina.
--
-- Ocho intentos es holgado para alguien que no se acuerda de su clave —tiene
-- ocho tiros y después espera un rato— e inútil para quien prueba de a miles.
--
-- Al entrar bien se borran los del correo: quien recordó su contraseña no
-- arrastra el castigo.
--
-- LO QUE ESTA TABLA NO GUARDA
-- La contraseña probada, no. Ni siquiera fallida: un registro de contraseñas
-- equivocadas es un diccionario de las variantes que usa la gente, y muchas
-- veces la correcta está ahí con una letra cambiada.
--
-- REVERSIBLE:
--   DROP TABLE login_intentos;
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS login_intentos (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    ip     VARCHAR(45)  NOT NULL COMMENT '45 caracteres: entra una IPv6 entera',
    email  VARCHAR(255) NOT NULL,
    cuando DATETIME     NOT NULL,
    INDEX idx_ip_cuando    (ip, cuando),
    INDEX idx_email_cuando (email, cuando)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para confirmar que quedó:
--   SHOW COLUMNS FROM login_intentos;
