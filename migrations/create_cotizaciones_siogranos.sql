-- Migración: Tabla de cotizaciones del Monitor SIO-Granos (MAGYP)
-- Fuente: https://monitorsiogranos.magyp.gob.ar/
-- Reemplaza la tabla cotizaciones_matba

CREATE TABLE IF NOT EXISTS `cotizaciones_siogranos` (
    `id`                    INT(11) NOT NULL AUTO_INCREMENT,
    `fecha`                 DATE NOT NULL COMMENT 'Fecha de la cotización',
    `cultivo`               VARCHAR(50) NOT NULL COMMENT 'Ej: Soja Cámara, Maíz, Trigo Cámara',
    `producto_id`           VARCHAR(30) NOT NULL COMMENT 'ID interno SIO-Granos: sojacamara, maiz, etc.',
    `zona`                  VARCHAR(80) NOT NULL DEFAULT 'General' COMMENT 'Ej: General, Rosario Norte, Bahía Blanca',
    `zona_id`               VARCHAR(10) NOT NULL DEFAULT '0' COMMENT 'ID de zona en SIO-Granos',
    `precio_promedio`       DECIMAL(12,2) DEFAULT NULL COMMENT 'Precio promedio ponderado ($/ton)',
    `precio_minimo`         DECIMAL(12,2) DEFAULT NULL COMMENT 'Precio mínimo del día ($/ton)',
    `precio_maximo`         DECIMAL(12,2) DEFAULT NULL COMMENT 'Precio máximo del día ($/ton)',
    `precio_modal`          DECIMAL(12,2) DEFAULT NULL COMMENT 'Precio más frecuente del día ($/ton)',
    `moneda`                VARCHAR(5) NOT NULL DEFAULT 'ARS',
    `fecha_actualizacion`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_unico_cotizacion` (`fecha`, `producto_id`, `zona_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
