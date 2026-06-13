-- ═══════════════════════════════════════════════════════════
-- TAMBO: Tipo de cambio histórico por mes
-- Permite cerrar cada mes al dólar de ese momento en vez de
-- recalcular siempre al tipo de cambio actual.
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `tambo_dolar_mes` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `usuario_id`      INT(11)       NOT NULL,
  `mes`             VARCHAR(7)    NOT NULL COMMENT 'Formato YYYY-MM',
  `dolar_mayorista` DECIMAL(12,4) NOT NULL COMMENT 'TC mayorista al cierre del mes',
  `fuente`          ENUM('api','manual') NOT NULL DEFAULT 'api'
                    COMMENT 'api = guardado automático desde dolarapi.com, manual = ingresado por el usuario',
  `creado_en`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuario_mes` (`usuario_id`, `mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tipo de cambio dólar mayorista por mes para cierres históricos';
