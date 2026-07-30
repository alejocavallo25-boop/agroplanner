CREATE TABLE IF NOT EXISTS `tambo_dif_inventario` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `usuario_id`      INT(11)      NOT NULL,
  `grupo_id`        VARCHAR(32)  NOT NULL COMMENT 'Agrupa filas del mismo registro',
  `mes`             VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM del mes actual',
  `mes_label_act`   VARCHAR(20)  DEFAULT NULL COMMENT 'Ej: mar-26',
  `mes_label_ant`   VARCHAR(20)  DEFAULT NULL COMMENT 'Ej: abr-25',
  `categoria`       VARCHAR(80)  NOT NULL,
  `cant_actual`     INT(11)      NOT NULL DEFAULT 0,
  `cant_anterior`   INT(11)      NOT NULL DEFAULT 0,
  `valor_unitario`  DECIMAL(14,2) NOT NULL DEFAULT 0,
  `criterio`        VARCHAR(150) DEFAULT NULL,
  `moneda`          ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
  `notas`           TEXT         DEFAULT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_dif_usuario_mes` (`usuario_id`, `mes`),
  INDEX `idx_dif_grupo`       (`grupo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
