-- ═══════════════════════════════════════════════════════════
-- TAMBO: Egresos / Costos
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `tambo_egresos` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`   INT(11) NOT NULL,
  `fecha`        DATE NOT NULL,
  `categoria`    VARCHAR(80)  NOT NULL,
  `subcategoria` VARCHAR(120) DEFAULT NULL,
  `concepto`     VARCHAR(200) DEFAULT NULL  COMMENT 'Item específico o texto libre "Otros"',
  `monto`        DECIMAL(14,2) NOT NULL,
  `notas`        TEXT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_egr_usuario_fecha` (`usuario_id`, `fecha`),
  INDEX `idx_egr_categoria`     (`usuario_id`, `categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Conceptos personalizados por usuario (ingredientes, unidades de luz, etc.)
CREATE TABLE IF NOT EXISTS `tambo_egresos_conceptos` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id`   INT(11) NOT NULL,
  `categoria`    VARCHAR(80)  NOT NULL,
  `subcategoria` VARCHAR(120) NOT NULL,
  `nombre`       VARCHAR(200) NOT NULL,
  `activo`       TINYINT(1)  NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_concepto` (`usuario_id`, `categoria`, `subcategoria`, `nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
