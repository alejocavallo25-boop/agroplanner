-- ═══════════════════════════════════════════════════════════
-- GANADERÍA: Egresos / Costos
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ganaderia_egresos` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`   INT(11) NOT NULL,
  `fecha`        DATE NOT NULL,
  `categoria`    VARCHAR(80)  NOT NULL,
  `subcategoria` VARCHAR(120) DEFAULT NULL,
  `concepto`     VARCHAR(200) DEFAULT NULL,
  `cantidad`     DECIMAL(12,3) DEFAULT NULL,
  `unidad`       ENUM('kg','lt','unidad') DEFAULT 'unidad',
  `precio_unitario` DECIMAL(14,4) DEFAULT NULL,
  `monto`        DECIMAL(14,2) NOT NULL,
  `moneda`       ENUM('ARS','USD') DEFAULT 'ARS',
  `notas`        TEXT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_gan_egr_usuario_fecha` (`usuario_id`, `fecha`),
  INDEX `idx_gan_egr_categoria`     (`usuario_id`, `categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Conceptos personalizados
CREATE TABLE IF NOT EXISTS `ganaderia_egresos_conceptos` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`   INT(11) NOT NULL,
  `categoria`    VARCHAR(80)  NOT NULL,
  `subcategoria` VARCHAR(120) NOT NULL,
  `nombre`       VARCHAR(200) NOT NULL,
  `activo`       TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gan_concepto` (`usuario_id`, `categoria`, `subcategoria`, `nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
