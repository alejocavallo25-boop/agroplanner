-- ─────────────────────────────────────────────────────────────────────────────
-- Tipo de cambio mayorista por usuario y mes.
--
-- La tabla ya existia en produccion, pero se creaba con un CREATE TABLE IF NOT
-- EXISTS al principio de tambo.php: se ejecutaba en cada carga de esa pantalla.
-- Eso obliga a que el usuario de la base tenga permiso de CREATE en produccion,
-- que es mas de lo que la aplicacion necesita para funcionar. Queda escrita aca
-- como corresponde y el codigo deja de hacer DDL en el camino de lectura.
--
-- El nombre arranca con "tambo_" por historia: se creo dentro de ese modulo. En
-- realidad es un dato de la empresa - con el se convierten a USD los alquileres
-- de Agricultura. Se conserva el nombre para no romper los datos ya cargados.
--
-- fuente = 'manual' gana siempre sobre 'api': si el productor escribio el tipo
-- de cambio al que realmente liquido, ni el cron ni ninguna pantalla lo pisan.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `tambo_dolar_mes` (
    `id`              INT(11)       NOT NULL AUTO_INCREMENT,
    `usuario_id`      INT(11)       NOT NULL,
    `mes`             VARCHAR(7)    NOT NULL COMMENT 'AAAA-MM',
    `dolar_mayorista` DECIMAL(12,4) NOT NULL,
    `fuente`          ENUM('api','manual') NOT NULL DEFAULT 'api',
    `creado_en`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_usuario_mes` (`usuario_id`, `mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las instalaciones viejas pueden no tener las columnas de fecha, porque la
-- version que creaba la tabla desde tambo.php no las incluia.
-- Si alguna ya existe, MySQL corta con "Duplicate column name" y se puede seguir.
ALTER TABLE `tambo_dolar_mes`
    ADD COLUMN `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `tambo_dolar_mes`
    ADD COLUMN `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
