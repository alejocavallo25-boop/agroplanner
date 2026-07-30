-- ═══════════════════════════════════════════════════════════════
-- MÓDULO TAMBO
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `tambo_rodeo` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT(11) NOT NULL,
  `fecha`       DATE NOT NULL,
  `vacas_ordene` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `vacas_secas`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `vaquillonas`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `terneros`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `notas`        TEXT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rodeo_usuario_fecha` (`usuario_id`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tambo_produccion` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT(11) NOT NULL,
  `fecha`       DATE NOT NULL,
  `litros_manana`  DECIMAL(8,1) DEFAULT 0,
  `litros_tarde`   DECIMAL(8,1) DEFAULT 0,
  `litros_total`   DECIMAL(8,1) GENERATED ALWAYS AS (`litros_manana` + `litros_tarde`) STORED,
  `precio_litro`   DECIMAL(10,2) DEFAULT NULL COMMENT 'ARS por litro pagado ese día',
  `destino`        ENUM('industria','auto_consumo','descarte') DEFAULT 'industria',
  `notas`          TEXT DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_prod_usuario_fecha` (`usuario_id`, `fecha`),
  INDEX `idx_prod_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tambo_calidad` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT(11) NOT NULL,
  `fecha`       DATE NOT NULL,
  `rcs`         INT UNSIGNED DEFAULT NULL COMMENT 'Recuento Células Somáticas (miles)',
  `ufc`         INT UNSIGNED DEFAULT NULL COMMENT 'UFC Bacterias Totales (miles)',
  `tenor_graso` DECIMAL(4,2) DEFAULT NULL COMMENT 'Porcentaje %',
  `tenor_prot`  DECIMAL(4,2) DEFAULT NULL COMMENT 'Porcentaje %',
  `temp_tank`   DECIMAL(4,1) DEFAULT NULL COMMENT 'Temperatura tanque °C',
  `notas`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_calidad_fecha` (`usuario_id`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════════════════
-- MÓDULO GANADERÍA
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ganaderia_inventario` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT(11) NOT NULL,
  `categoria`   ENUM('vacas_cria','vaquillonas','novillos','terneros','terneras','toros','toros_raza') NOT NULL,
  `cantidad`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `fecha`       DATE NOT NULL COMMENT 'Fecha de actualización del stock',
  `ejercicio`   VARCHAR(10) DEFAULT NULL COMMENT 'Ej: 2024/25',
  `notas`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_inv_usuario` (`usuario_id`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ganaderia_movimientos` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT(11) NOT NULL,
  `fecha`       DATE NOT NULL,
  `tipo`        ENUM('entrada','salida','transferencia','nacimiento','muerte') NOT NULL,
  `categoria`   ENUM('vacas_cria','vaquillonas','novillos','terneros','terneras','toros','toros_raza') NOT NULL,
  `cantidad`    SMALLINT UNSIGNED NOT NULL,
  `precio_cabeza` DECIMAL(12,2) DEFAULT NULL COMMENT 'ARS por cabeza',
  `total_kg`    DECIMAL(10,1) DEFAULT NULL COMMENT 'Kg totales de la tropilla',
  `proveedor_destino` VARCHAR(150) DEFAULT NULL,
  `ejercicio`   VARCHAR(10) DEFAULT NULL,
  `notas`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_mov_fecha` (`usuario_id`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ganaderia_pesadas` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT(11) NOT NULL,
  `fecha`       DATE NOT NULL,
  `categoria`   ENUM('vacas_cria','vaquillonas','novillos','terneros','terneras','toros') NOT NULL,
  `cantidad_pesada`  SMALLINT UNSIGNED NOT NULL,
  `peso_promedio_kg` DECIMAL(7,1) NOT NULL,
  `peso_total_kg`    DECIMAL(10,1) GENERATED ALWAYS AS (`cantidad_pesada` * `peso_promedio_kg`) STORED,
  `notas`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_pesadas_fecha` (`usuario_id`, `fecha`, `categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ganaderia_reproductivo` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`    INT(11) NOT NULL,
  `ejercicio`     VARCHAR(10) NOT NULL COMMENT 'Ej: 2024/25',
  `vacas_servicio` SMALLINT UNSIGNED DEFAULT 0,
  `pct_prenez`    DECIMAL(5,2) DEFAULT NULL,
  `pct_destete`   DECIMAL(5,2) DEFAULT NULL,
  `terneros_logrados` SMALLINT UNSIGNED DEFAULT 0,
  `fecha_inicio_servicio` DATE DEFAULT NULL,
  `fecha_fin_servicio`    DATE DEFAULT NULL,
  `notas`         TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repro_usuario_ejercicio` (`usuario_id`, `ejercicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
