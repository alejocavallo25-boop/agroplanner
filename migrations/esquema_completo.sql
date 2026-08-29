-- ═══════════════════════════════════════════════════════════════
-- AgroPlanner · Esquema completo de la base
-- ═══════════════════════════════════════════════════════════════
-- Reemplaza a las 20 migraciones sueltas de migrations/. Ninguna de
-- ellas era idempotente —ADD COLUMN pelado— así que correrlas en bloque
-- fallaba en la primera que ya estuviera aplicada, y no hay tabla que
-- registre cuáles corrieron. Este archivo se puede correr sin saberlo:
-- crea lo que falta, deja lo que ya está, y no borra nada nunca.
--
-- Correr entero, de arriba abajo, en phpMyAdmin. Es seguro repetirlo.
-- Si la pestaña SQL no acepta pegarlo entero por tamaño, subirlo por
-- la pestaña Importar, que lee el archivo.
--
-- SÓLO ESQUEMA, SIN DATOS. Las migraciones viejas traían además algún
-- UPDATE de datos —marcar la cuenta demo como solo lectura, prender los
-- módulos de los usuarios activos—. Eso no está acá: en una base que ya
-- viene funcionando ya corrió, y en una base nueva no hay a quién
-- aplicárselo. Si alguna vez se levanta un entorno desde cero, esos
-- UPDATE hay que correrlos aparte desde migrations/.
--
-- ANTES DE CORRERLO: hacer el backup de la PARTE 0. No es opcional.
-- ═══════════════════════════════════════════════════════════════


-- ───────────────────────────────────────────────────────────────
-- PARTE 0 · Backup. Hacerlo antes que nada.
-- ───────────────────────────────────────────────────────────────
-- En hPanel: Bases de datos → phpMyAdmin → elegir la base → pestaña
-- Exportar → método Rápido → formato SQL → Continuar. Guardar el
-- archivo antes de seguir.
--
-- Por SSH sería:
--   mysqldump -u USUARIO -p NOMBRE_BASE > backup_$(date +%F).sql
--
-- Este archivo no borra ni convierte datos, pero un ALTER sobre una
-- tabla con datos reales sin backup no se hace y punto.


-- ───────────────────────────────────────────────────────────────
-- PARTE 1 · Dónde estamos parados.
-- ───────────────────────────────────────────────────────────────
-- Tiene que decir MariaDB. Este archivo usa ADD COLUMN IF NOT EXISTS,
-- que es de MariaDB: en MySQL 8 falla. Si dice MySQL, pará acá.
SELECT VERSION() AS motor, DATABASE() AS base;

-- Cuántas de las 30 tablas esperadas ya existen.
SELECT COUNT(*) AS tablas_presentes, 30 AS tablas_esperadas
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
  AND TABLE_NAME IN ('users', 'cultivos', 'lotes', 'depositos', 'alquileres', 'cotizaciones_siogranos', 'feedlot_alimentos', 'feedlot_costos_fijos', 'feedlot_lotes', 'ganaderia_egresos', 'ganaderia_egresos_conceptos', 'ganaderia_inventario', 'ganaderia_movimientos', 'ganaderia_pesadas', 'ganaderia_reproductivo', 'insumos', 'lote_historial_campanas', 'motor_consultas_fallidas', 'motor_preferencias', 'operacion_insumos', 'operaciones', 'produccion_ventas', 'tambo_calidad', 'tambo_dif_inventario', 'tambo_dolar_mes', 'tambo_egresos', 'tambo_egresos_conceptos', 'tambo_produccion', 'tambo_rodeo', 'tambo_ventas_carne');


-- ───────────────────────────────────────────────────────────────
-- PARTE 2 · Tablas que falten. Las que ya están no se tocan.
-- ───────────────────────────────────────────────────────────────
-- Las tablas se referencian entre sí, así que el orden de creación
-- importaría. Se apagan los chequeos mientras se crean y se vuelven a
-- prender al final: es de sesión, no cambia nada de la base.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `status` enum('pending','active') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `has_agricultura` tinyint(1) DEFAULT 0,
  `has_tambo` tinyint(1) DEFAULT 0,
  `has_ganaderia` tinyint(1) DEFAULT 0,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `solo_lectura` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Cuenta de demostraciÃ³n: puede ver, no puede modificar',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cultivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lote_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `fecha_siembra` date DEFAULT NULL,
  `fecha_cosecha_esperada` date DEFAULT NULL,
  `estado` enum('activo','cosechado','perdido') DEFAULT 'activo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL DEFAULT 1,
  `ciclo` varchar(50) DEFAULT NULL COMMENT 'Ej: 25/26',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cultivo` (`usuario_id`,`lote_id`,`nombre`,`ciclo`),
  KEY `lote_id` (`lote_id`),
  CONSTRAINT `cultivos_ibfk_1` FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cultivos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `superficie` decimal(10,2) NOT NULL COMMENT 'En hect??reas',
  `ubicacion` varchar(255) DEFAULT NULL,
  `tipo_suelo` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL DEFAULT 1,
  `latitud` decimal(10,8) DEFAULT NULL,
  `longitud` decimal(11,8) DEFAULT NULL,
  `tenencia` enum('propio','alquilado') DEFAULT 'propio',
  `costo_alquiler_tns_ha` decimal(14,4) DEFAULT NULL,
  `campania` varchar(50) DEFAULT NULL,
  `cultivo_actual` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_lotes_usuario` (`usuario_id`),
  CONSTRAINT `fk_lotes_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `depositos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `ubicacion` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `depositos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alquileres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `lote_id` int(11) DEFAULT NULL,
  `cultivo_id` int(11) DEFAULT NULL,
  `nivel_imputacion` enum('lote','cultivo','campania') NOT NULL DEFAULT 'lote',
  `campania` varchar(50) DEFAULT NULL,
  `fecha_pago` date NOT NULL,
  `monto_pagado` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `moneda` enum('USD','ARS') DEFAULT 'USD',
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cotizaciones_siogranos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL COMMENT 'Fecha de la cotizaci??n',
  `cultivo` varchar(50) NOT NULL COMMENT 'Ej: Soja C??mara, Ma??z, Trigo C??mara',
  `producto_id` varchar(30) NOT NULL COMMENT 'ID interno SIO-Granos: sojacamara, maiz, etc.',
  `zona` varchar(80) NOT NULL DEFAULT 'General' COMMENT 'Ej: General, Rosario Norte, Bah??a Blanca',
  `zona_id` varchar(10) NOT NULL DEFAULT '0' COMMENT 'ID de zona en SIO-Granos',
  `precio_promedio` decimal(12,2) DEFAULT NULL COMMENT 'Precio promedio ponderado ($/ton)',
  `precio_minimo` decimal(12,2) DEFAULT NULL COMMENT 'Precio m??nimo del d??a ($/ton)',
  `precio_maximo` decimal(12,2) DEFAULT NULL COMMENT 'Precio m??ximo del d??a ($/ton)',
  `precio_modal` decimal(12,2) DEFAULT NULL COMMENT 'Precio m??s frecuente del d??a ($/ton)',
  `moneda` varchar(5) NOT NULL DEFAULT 'ARS',
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_unico_cotizacion` (`fecha`,`producto_id`,`zona_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feedlot_alimentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lote_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fase` enum('invernada','engorde') NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `kg_x_dia` decimal(8,3) DEFAULT 0.000,
  `precio_kg` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_lf` (`lote_id`,`fase`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feedlot_costos_fijos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lote_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `concepto` varchar(100) NOT NULL,
  `categoria` varchar(30) DEFAULT 'otro',
  `cantidad` decimal(10,2) DEFAULT 1.00,
  `precio_unitario` decimal(14,2) DEFAULT 0.00,
  `monto_mensual` decimal(14,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_l` (`lote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feedlot_lotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `cant_animales` int(11) DEFAULT 1000,
  `pct_invernada` decimal(5,2) DEFAULT 70.00,
  `pct_engorde` decimal(5,2) DEFAULT 30.00,
  `kg_entrada_inv` decimal(8,2) DEFAULT 130.00,
  `kg_salida_inv` decimal(8,2) DEFAULT 240.00,
  `dias_invernada` int(11) DEFAULT 110,
  `kg_entrada_eng` decimal(8,2) DEFAULT 240.00,
  `kg_salida_eng` decimal(8,2) DEFAULT 360.00,
  `dias_engorde` int(11) DEFAULT 92,
  `conv_inv` decimal(5,2) DEFAULT 1.00,
  `conv_eng` decimal(5,2) DEFAULT 1.30,
  `desperdicio_pct` decimal(5,2) DEFAULT 2.00,
  `precio_compra` decimal(14,2) DEFAULT 0.00,
  `precio_venta_inv` decimal(14,2) DEFAULT 0.00,
  `precio_venta_eng` decimal(14,2) DEFAULT 0.00,
  `flete_compra_pct` decimal(5,2) DEFAULT 10.00,
  `flete_venta_pct` decimal(5,2) DEFAULT 5.00,
  `usd_referencia` decimal(10,2) DEFAULT 1185.00,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_u` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ganaderia_egresos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `categoria` varchar(80) NOT NULL,
  `subcategoria` varchar(120) DEFAULT NULL,
  `concepto` varchar(200) DEFAULT NULL,
  `cantidad` decimal(12,3) DEFAULT NULL,
  `unidad` enum('kg','lt','unidad') DEFAULT 'unidad',
  `precio_unitario` decimal(14,4) DEFAULT NULL,
  `monto` decimal(14,2) NOT NULL,
  `moneda` enum('ARS','USD') DEFAULT 'ARS',
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gan_egr_usuario_fecha` (`usuario_id`,`fecha`),
  KEY `idx_gan_egr_categoria` (`usuario_id`,`categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ganaderia_egresos_conceptos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `categoria` varchar(80) NOT NULL,
  `subcategoria` varchar(120) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gan_concepto` (`usuario_id`,`categoria`,`subcategoria`,`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ganaderia_inventario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `categoria` enum('vacas_cria','vaquillonas','novillos','terneros','terneras','toros','toros_raza') NOT NULL,
  `cantidad` smallint(5) unsigned NOT NULL DEFAULT 0,
  `fecha` date NOT NULL COMMENT 'Fecha de actualizaci├│n del stock',
  `ejercicio` varchar(10) DEFAULT NULL COMMENT 'Ej: 2024/25',
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inv_usuario` (`usuario_id`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ganaderia_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `tipo` enum('entrada','salida','transferencia','nacimiento','muerte') NOT NULL,
  `categoria` enum('vacas_cria','vaquillonas','novillos','terneros','terneras','toros','toros_raza') NOT NULL,
  `cantidad` smallint(5) unsigned NOT NULL,
  `precio_cabeza` decimal(12,2) DEFAULT NULL COMMENT 'ARS por cabeza',
  `total_kg` decimal(10,1) DEFAULT NULL COMMENT 'Kg totales de la tropilla',
  `proveedor_destino` varchar(150) DEFAULT NULL,
  `ejercicio` varchar(10) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mov_fecha` (`usuario_id`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ganaderia_pesadas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `categoria` enum('vacas_cria','vaquillonas','novillos','terneros','terneras','toros') NOT NULL,
  `cantidad_pesada` smallint(5) unsigned NOT NULL,
  `peso_promedio_kg` decimal(7,1) NOT NULL,
  `peso_total_kg` decimal(10,1) GENERATED ALWAYS AS (`cantidad_pesada` * `peso_promedio_kg`) STORED,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pesadas_fecha` (`usuario_id`,`fecha`,`categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ganaderia_reproductivo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `ejercicio` varchar(10) NOT NULL COMMENT 'Ej: 2024/25',
  `vacas_servicio` smallint(5) unsigned DEFAULT 0,
  `pct_prenez` decimal(5,2) DEFAULT NULL,
  `pct_destete` decimal(5,2) DEFAULT NULL,
  `terneros_logrados` smallint(5) unsigned DEFAULT 0,
  `fecha_inicio_servicio` date DEFAULT NULL,
  `fecha_fin_servicio` date DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repro_usuario_ejercicio` (`usuario_id`,`ejercicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `insumos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `tipo_insumo` enum('semilla','fertilizante','agroquimico','inoculante','otro') NOT NULL,
  `unidad_medida` enum('kg','lt','dosis','bolsa') NOT NULL,
  `precio_estimado_usd` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `estado` enum('activo','inactivo') DEFAULT 'activo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `stock_actual` decimal(12,4) DEFAULT 0.0000,
  `unidad_stock` varchar(50) DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `deposito_id` int(11) DEFAULT NULL,
  `stock_minimo` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `fk_insumos_deposito` (`deposito_id`),
  CONSTRAINT `fk_insumos_deposito` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `insumos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lote_historial_campanas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lote_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `campania` varchar(50) DEFAULT NULL,
  `cultivo` varchar(100) DEFAULT NULL,
  `fecha_cierre` date NOT NULL,
  `kg_total` decimal(14,2) DEFAULT NULL,
  `ingreso_total` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lote_id` (`lote_id`),
  CONSTRAINT `lote_historial_campanas_ibfk_1` FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `motor_consultas_fallidas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `pregunta` varchar(300) NOT NULL,
  `motivo` varchar(120) DEFAULT NULL,
  `veces` int(11) NOT NULL DEFAULT 1,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_u` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `motor_preferencias` (
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(60) DEFAULT NULL,
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `operacion_insumos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `operacion_id` int(11) NOT NULL,
  `insumo_id` int(11) DEFAULT NULL,
  `cantidad_ha` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `precio_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `nombre_libre` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operacion_id` (`operacion_id`),
  KEY `insumo_id` (`insumo_id`),
  CONSTRAINT `operacion_insumos_ibfk_1` FOREIGN KEY (`operacion_id`) REFERENCES `operaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operacion_insumos_ibfk_2` FOREIGN KEY (`insumo_id`) REFERENCES `insumos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `operaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `lote_id` int(11) NOT NULL,
  `cultivo_id` int(11) DEFAULT NULL,
  `grupo_gasto` enum('siembra','cosecha','pulverizacion','fertilizacion','otros') NOT NULL,
  `tipo_componente` enum('semilla','fertilizante','agroquimico','labor','maquinaria','insumo','multi_insumo','receta_labor') NOT NULL,
  `insumo_id` int(11) DEFAULT NULL,
  `proveedor_servicio` varchar(255) DEFAULT NULL,
  `cantidad_ha` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `precio_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `costo_total` decimal(12,2) NOT NULL,
  `fecha` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `campania_operacion` varchar(50) DEFAULT NULL,
  `cultivo_operacion` varchar(100) DEFAULT NULL,
  `grupo_descripcion` varchar(255) DEFAULT NULL,
  `cargas` int(11) DEFAULT 1,
  `hectareas` decimal(10,2) DEFAULT NULL,
  `moneda` enum('ARS','USD') NOT NULL DEFAULT 'ARS' COMMENT 'Moneda en que se pagÃ³. La conversiÃ³n se hace al calcular.',
  PRIMARY KEY (`id`),
  KEY `lote_id` (`lote_id`),
  KEY `cultivo_id` (`cultivo_id`),
  KEY `insumo_id` (`insumo_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `operaciones_ibfk_1` FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operaciones_ibfk_2` FOREIGN KEY (`cultivo_id`) REFERENCES `cultivos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `operaciones_ibfk_3` FOREIGN KEY (`insumo_id`) REFERENCES `insumos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `operaciones_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `produccion_ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lote_id` int(11) NOT NULL,
  `cultivo_id` int(11) DEFAULT NULL,
  `kg_cosechados` decimal(14,2) DEFAULT NULL,
  `precio_kg` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `ingreso_total` decimal(12,2) GENERATED ALWAYS AS (`kg_cosechados` * `precio_kg`) STORED,
  `fecha_venta` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL DEFAULT 1,
  `campania_vendida` varchar(50) DEFAULT NULL,
  `cultivo_vendido` varchar(100) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `moneda` enum('ARS','USD') NOT NULL DEFAULT 'ARS' COMMENT 'Moneda del precio por kilo. La conversiÃ³n se hace al calcular.',
  PRIMARY KEY (`id`),
  KEY `lote_id` (`lote_id`),
  KEY `fk_produccion_ventas_usuario` (`usuario_id`),
  KEY `produccion_ventas_ibfk_2` (`cultivo_id`),
  CONSTRAINT `fk_produccion_ventas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `produccion_ventas_ibfk_1` FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `produccion_ventas_ibfk_2` FOREIGN KEY (`cultivo_id`) REFERENCES `cultivos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tambo_calidad` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `rcs` int(10) unsigned DEFAULT NULL COMMENT 'Recuento C├®lulas Som├íticas (miles)',
  `ufc` int(10) unsigned DEFAULT NULL COMMENT 'UFC Bacterias Totales (miles)',
  `tenor_graso` decimal(4,2) DEFAULT NULL COMMENT 'Porcentaje %',
  `tenor_prot` decimal(4,2) DEFAULT NULL COMMENT 'Porcentaje %',
  `temp_tank` decimal(4,1) DEFAULT NULL COMMENT 'Temperatura tanque ┬░C',
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_calidad_fecha` (`usuario_id`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tambo_dif_inventario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `venta_carne_id` int(11) NOT NULL,
  `mes_label_act` varchar(20) DEFAULT NULL,
  `mes_label_ant` varchar(20) DEFAULT NULL,
  `categoria` varchar(80) NOT NULL,
  `cant_actual` int(11) NOT NULL DEFAULT 0,
  `cant_anterior` int(11) NOT NULL DEFAULT 0,
  `valor_unitario` decimal(14,2) NOT NULL DEFAULT 0.00,
  `criterio` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_venta_carne` (`venta_carne_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tambo_dolar_mes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `mes` varchar(7) NOT NULL COMMENT 'Formato YYYY-MM',
  `dolar_mayorista` decimal(12,4) NOT NULL COMMENT 'TC mayorista al cierre del mes',
  `fuente` enum('api','manual') NOT NULL DEFAULT 'api' COMMENT 'api = guardado automático desde dolarapi.com, manual = ingresado por el usuario',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuario_mes` (`usuario_id`,`mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tipo de cambio dólar mayorista por mes para cierres históricos';

CREATE TABLE IF NOT EXISTS `tambo_egresos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `categoria` varchar(80) NOT NULL,
  `subcategoria` varchar(120) DEFAULT NULL,
  `concepto` varchar(200) DEFAULT NULL COMMENT 'Item espec├¡fico o texto libre "Otros"',
  `cantidad` decimal(12,3) DEFAULT NULL,
  `unidad` enum('kg','lt','unidad') DEFAULT 'unidad',
  `precio_unitario` decimal(14,4) DEFAULT NULL,
  `moneda` enum('ARS','USD') DEFAULT 'ARS',
  `monto` decimal(14,2) NOT NULL,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_egr_usuario_fecha` (`usuario_id`,`fecha`),
  KEY `idx_egr_categoria` (`usuario_id`,`categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tambo_egresos_conceptos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `categoria` varchar(80) NOT NULL,
  `subcategoria` varchar(120) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_concepto` (`usuario_id`,`categoria`,`subcategoria`,`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tambo_produccion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `litros_manana` decimal(8,1) DEFAULT 0.0,
  `litros_tarde` decimal(8,1) DEFAULT 0.0,
  `litros_total` decimal(8,1) GENERATED ALWAYS AS (`litros_manana` + `litros_tarde`) STORED,
  `precio_litro` decimal(10,2) DEFAULT NULL COMMENT 'ARS por litro pagado ese d├¡a',
  `destino` enum('industria','auto_consumo','descarte','buena','otra') DEFAULT 'buena',
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prod_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tambo_rodeo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `vacas_ordene` smallint(5) unsigned NOT NULL DEFAULT 0,
  `vacas_secas` smallint(5) unsigned NOT NULL DEFAULT 0,
  `vaquillonas` smallint(5) unsigned NOT NULL DEFAULT 0,
  `terneros` smallint(5) unsigned NOT NULL DEFAULT 0,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rodeo_usuario_fecha` (`usuario_id`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tambo_ventas_carne` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `tipo` enum('diferencia_inventario','venta_carne') NOT NULL,
  `categoria_animal` enum('vaca','vaquillona','ternero','ternera','otro') DEFAULT 'otro',
  `cantidad_animales` int(11) DEFAULT NULL,
  `kg_vivo` decimal(10,2) DEFAULT NULL,
  `precio_kg` decimal(10,2) DEFAULT NULL,
  `monto_total` decimal(12,2) NOT NULL,
  `monto_original` decimal(14,2) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


-- ───────────────────────────────────────────────────────────────
-- PARTE 3 · Columnas que falten en tablas que ya existen.
-- ───────────────────────────────────────────────────────────────
-- Esto es lo que fueron agregando las migraciones sueltas con el
-- tiempo. Una base vieja puede tener la tabla pero no la columna.

-- users
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `username` varchar(50) NOT NULL AFTER `id`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email` varchar(100) NOT NULL AFTER `username`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `password_hash` varchar(255) NOT NULL AFTER `email`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `role` enum('admin','user') NULL DEFAULT 'user' AFTER `password_hash`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `status` enum('pending','active') NULL DEFAULT 'pending' AFTER `role`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `status`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `has_agricultura` tinyint(1) NULL DEFAULT 0 AFTER `created_at`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `has_tambo` tinyint(1) NULL DEFAULT 0 AFTER `has_agricultura`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `has_ganaderia` tinyint(1) NULL DEFAULT 0 AFTER `has_tambo`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `reset_token` varchar(255) NULL DEFAULT NULL AFTER `has_ganaderia`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `reset_expires` datetime NULL DEFAULT NULL AFTER `reset_token`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `solo_lectura` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Cuenta de demostraciÃ³n: puede ver, no puede modificar' AFTER `reset_expires`;

-- cultivos
ALTER TABLE `cultivos` ADD COLUMN IF NOT EXISTS `lote_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `cultivos` ADD COLUMN IF NOT EXISTS `nombre` varchar(100) NOT NULL AFTER `lote_id`;
ALTER TABLE `cultivos` ADD COLUMN IF NOT EXISTS `fecha_siembra` date NULL DEFAULT NULL AFTER `nombre`;
ALTER TABLE `cultivos` ADD COLUMN IF NOT EXISTS `fecha_cosecha_esperada` date NULL DEFAULT NULL AFTER `fecha_siembra`;
ALTER TABLE `cultivos` ADD COLUMN IF NOT EXISTS `estado` enum('activo','cosechado','perdido') NULL DEFAULT 'activo' AFTER `fecha_cosecha_esperada`;
ALTER TABLE `cultivos` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `estado`;
ALTER TABLE `cultivos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL DEFAULT 1 AFTER `created_at`;
ALTER TABLE `cultivos` ADD COLUMN IF NOT EXISTS `ciclo` varchar(50) NULL DEFAULT NULL COMMENT 'Ej: 25/26' AFTER `usuario_id`;

-- lotes
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `nombre` varchar(100) NOT NULL AFTER `id`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `superficie` decimal(10,2) NOT NULL COMMENT 'En hect??reas' AFTER `nombre`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `ubicacion` varchar(255) NULL DEFAULT NULL AFTER `superficie`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `tipo_suelo` varchar(100) NULL DEFAULT NULL AFTER `ubicacion`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `tipo_suelo`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL DEFAULT 1 AFTER `created_at`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `latitud` decimal(10,8) NULL DEFAULT NULL AFTER `usuario_id`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `longitud` decimal(11,8) NULL DEFAULT NULL AFTER `latitud`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `tenencia` enum('propio','alquilado') NULL DEFAULT 'propio' AFTER `longitud`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `costo_alquiler_tns_ha` decimal(14,4) NULL DEFAULT NULL AFTER `tenencia`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `campania` varchar(50) NULL DEFAULT NULL AFTER `costo_alquiler_tns_ha`;
ALTER TABLE `lotes` ADD COLUMN IF NOT EXISTS `cultivo_actual` varchar(100) NULL DEFAULT NULL AFTER `campania`;

-- depositos
ALTER TABLE `depositos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `depositos` ADD COLUMN IF NOT EXISTS `nombre` varchar(100) NOT NULL AFTER `usuario_id`;
ALTER TABLE `depositos` ADD COLUMN IF NOT EXISTS `descripcion` varchar(255) NULL DEFAULT NULL AFTER `nombre`;
ALTER TABLE `depositos` ADD COLUMN IF NOT EXISTS `ubicacion` varchar(255) NULL DEFAULT NULL AFTER `descripcion`;
ALTER TABLE `depositos` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `ubicacion`;

-- alquileres
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `lote_id` int(11) NULL DEFAULT NULL AFTER `usuario_id`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `cultivo_id` int(11) NULL DEFAULT NULL AFTER `lote_id`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `nivel_imputacion` enum('lote','cultivo','campania') NOT NULL DEFAULT 'lote' AFTER `cultivo_id`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `campania` varchar(50) NULL DEFAULT NULL AFTER `nivel_imputacion`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `fecha_pago` date NOT NULL AFTER `campania`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `monto_pagado` decimal(14,4) NOT NULL DEFAULT 0.0000 AFTER `fecha_pago`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `moneda` enum('USD','ARS') NULL DEFAULT 'USD' AFTER `monto_pagado`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `moneda`;
ALTER TABLE `alquileres` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- cotizaciones_siogranos
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL COMMENT 'Fecha de la cotizaci??n' AFTER `id`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `cultivo` varchar(50) NOT NULL COMMENT 'Ej: Soja C??mara, Ma??z, Trigo C??mara' AFTER `fecha`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `producto_id` varchar(30) NOT NULL COMMENT 'ID interno SIO-Granos: sojacamara, maiz, etc.' AFTER `cultivo`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `zona` varchar(80) NOT NULL DEFAULT 'General' COMMENT 'Ej: General, Rosario Norte, Bah??a Blanca' AFTER `producto_id`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `zona_id` varchar(10) NOT NULL DEFAULT '0' COMMENT 'ID de zona en SIO-Granos' AFTER `zona`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `precio_promedio` decimal(12,2) NULL DEFAULT NULL COMMENT 'Precio promedio ponderado ($/ton)' AFTER `zona_id`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `precio_minimo` decimal(12,2) NULL DEFAULT NULL COMMENT 'Precio m??nimo del d??a ($/ton)' AFTER `precio_promedio`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `precio_maximo` decimal(12,2) NULL DEFAULT NULL COMMENT 'Precio m??ximo del d??a ($/ton)' AFTER `precio_minimo`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `precio_modal` decimal(12,2) NULL DEFAULT NULL COMMENT 'Precio m??s frecuente del d??a ($/ton)' AFTER `precio_maximo`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `moneda` varchar(5) NOT NULL DEFAULT 'ARS' AFTER `precio_modal`;
ALTER TABLE `cotizaciones_siogranos` ADD COLUMN IF NOT EXISTS `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `moneda`;

-- feedlot_alimentos
ALTER TABLE `feedlot_alimentos` ADD COLUMN IF NOT EXISTS `lote_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `feedlot_alimentos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `lote_id`;
ALTER TABLE `feedlot_alimentos` ADD COLUMN IF NOT EXISTS `fase` enum('invernada','engorde') NOT NULL AFTER `usuario_id`;
ALTER TABLE `feedlot_alimentos` ADD COLUMN IF NOT EXISTS `nombre` varchar(100) NOT NULL AFTER `fase`;
ALTER TABLE `feedlot_alimentos` ADD COLUMN IF NOT EXISTS `kg_x_dia` decimal(8,3) NULL DEFAULT 0.000 AFTER `nombre`;
ALTER TABLE `feedlot_alimentos` ADD COLUMN IF NOT EXISTS `precio_kg` decimal(10,2) NULL DEFAULT 0.00 AFTER `kg_x_dia`;

-- feedlot_costos_fijos
ALTER TABLE `feedlot_costos_fijos` ADD COLUMN IF NOT EXISTS `lote_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `feedlot_costos_fijos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `lote_id`;
ALTER TABLE `feedlot_costos_fijos` ADD COLUMN IF NOT EXISTS `concepto` varchar(100) NOT NULL AFTER `usuario_id`;
ALTER TABLE `feedlot_costos_fijos` ADD COLUMN IF NOT EXISTS `categoria` varchar(30) NULL DEFAULT 'otro' AFTER `concepto`;
ALTER TABLE `feedlot_costos_fijos` ADD COLUMN IF NOT EXISTS `cantidad` decimal(10,2) NULL DEFAULT 1.00 AFTER `categoria`;
ALTER TABLE `feedlot_costos_fijos` ADD COLUMN IF NOT EXISTS `precio_unitario` decimal(14,2) NULL DEFAULT 0.00 AFTER `cantidad`;
ALTER TABLE `feedlot_costos_fijos` ADD COLUMN IF NOT EXISTS `monto_mensual` decimal(14,2) NULL DEFAULT 0.00 AFTER `precio_unitario`;

-- feedlot_lotes
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `nombre` varchar(100) NOT NULL AFTER `usuario_id`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `fecha_inicio` date NULL DEFAULT NULL AFTER `nombre`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `cant_animales` int(11) NULL DEFAULT 1000 AFTER `fecha_inicio`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `pct_invernada` decimal(5,2) NULL DEFAULT 70.00 AFTER `cant_animales`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `pct_engorde` decimal(5,2) NULL DEFAULT 30.00 AFTER `pct_invernada`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `kg_entrada_inv` decimal(8,2) NULL DEFAULT 130.00 AFTER `pct_engorde`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `kg_salida_inv` decimal(8,2) NULL DEFAULT 240.00 AFTER `kg_entrada_inv`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `dias_invernada` int(11) NULL DEFAULT 110 AFTER `kg_salida_inv`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `kg_entrada_eng` decimal(8,2) NULL DEFAULT 240.00 AFTER `dias_invernada`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `kg_salida_eng` decimal(8,2) NULL DEFAULT 360.00 AFTER `kg_entrada_eng`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `dias_engorde` int(11) NULL DEFAULT 92 AFTER `kg_salida_eng`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `conv_inv` decimal(5,2) NULL DEFAULT 1.00 AFTER `dias_engorde`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `conv_eng` decimal(5,2) NULL DEFAULT 1.30 AFTER `conv_inv`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `desperdicio_pct` decimal(5,2) NULL DEFAULT 2.00 AFTER `conv_eng`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `precio_compra` decimal(14,2) NULL DEFAULT 0.00 AFTER `desperdicio_pct`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `precio_venta_inv` decimal(14,2) NULL DEFAULT 0.00 AFTER `precio_compra`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `precio_venta_eng` decimal(14,2) NULL DEFAULT 0.00 AFTER `precio_venta_inv`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `flete_compra_pct` decimal(5,2) NULL DEFAULT 10.00 AFTER `precio_venta_eng`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `flete_venta_pct` decimal(5,2) NULL DEFAULT 5.00 AFTER `flete_compra_pct`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `usd_referencia` decimal(10,2) NULL DEFAULT 1185.00 AFTER `flete_venta_pct`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `usd_referencia`;
ALTER TABLE `feedlot_lotes` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- ganaderia_egresos
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `usuario_id`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `categoria` varchar(80) NOT NULL AFTER `fecha`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `subcategoria` varchar(120) NULL DEFAULT NULL AFTER `categoria`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `concepto` varchar(200) NULL DEFAULT NULL AFTER `subcategoria`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `cantidad` decimal(12,3) NULL DEFAULT NULL AFTER `concepto`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `unidad` enum('kg','lt','unidad') NULL DEFAULT 'unidad' AFTER `cantidad`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `precio_unitario` decimal(14,4) NULL DEFAULT NULL AFTER `unidad`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `monto` decimal(14,2) NOT NULL AFTER `precio_unitario`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `moneda` enum('ARS','USD') NULL DEFAULT 'ARS' AFTER `monto`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `moneda`;
ALTER TABLE `ganaderia_egresos` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- ganaderia_egresos_conceptos
ALTER TABLE `ganaderia_egresos_conceptos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `ganaderia_egresos_conceptos` ADD COLUMN IF NOT EXISTS `categoria` varchar(80) NOT NULL AFTER `usuario_id`;
ALTER TABLE `ganaderia_egresos_conceptos` ADD COLUMN IF NOT EXISTS `subcategoria` varchar(120) NOT NULL AFTER `categoria`;
ALTER TABLE `ganaderia_egresos_conceptos` ADD COLUMN IF NOT EXISTS `nombre` varchar(200) NOT NULL AFTER `subcategoria`;
ALTER TABLE `ganaderia_egresos_conceptos` ADD COLUMN IF NOT EXISTS `activo` tinyint(1) NOT NULL DEFAULT 1 AFTER `nombre`;

-- ganaderia_inventario
ALTER TABLE `ganaderia_inventario` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `ganaderia_inventario` ADD COLUMN IF NOT EXISTS `categoria` enum('vacas_cria','vaquillonas','novillos','terneros','terneras','toros','toros_raza') NOT NULL AFTER `usuario_id`;
ALTER TABLE `ganaderia_inventario` ADD COLUMN IF NOT EXISTS `cantidad` smallint(5) unsigned NOT NULL DEFAULT 0 AFTER `categoria`;
ALTER TABLE `ganaderia_inventario` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL COMMENT 'Fecha de actualizaci├│n del stock' AFTER `cantidad`;
ALTER TABLE `ganaderia_inventario` ADD COLUMN IF NOT EXISTS `ejercicio` varchar(10) NULL DEFAULT NULL COMMENT 'Ej: 2024/25' AFTER `fecha`;
ALTER TABLE `ganaderia_inventario` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `ejercicio`;
ALTER TABLE `ganaderia_inventario` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- ganaderia_movimientos
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `usuario_id`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `tipo` enum('entrada','salida','transferencia','nacimiento','muerte') NOT NULL AFTER `fecha`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `categoria` enum('vacas_cria','vaquillonas','novillos','terneros','terneras','toros','toros_raza') NOT NULL AFTER `tipo`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `cantidad` smallint(5) unsigned NOT NULL AFTER `categoria`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `precio_cabeza` decimal(12,2) NULL DEFAULT NULL COMMENT 'ARS por cabeza' AFTER `cantidad`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `total_kg` decimal(10,1) NULL DEFAULT NULL COMMENT 'Kg totales de la tropilla' AFTER `precio_cabeza`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `proveedor_destino` varchar(150) NULL DEFAULT NULL AFTER `total_kg`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `ejercicio` varchar(10) NULL DEFAULT NULL AFTER `proveedor_destino`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `ejercicio`;
ALTER TABLE `ganaderia_movimientos` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- ganaderia_pesadas
ALTER TABLE `ganaderia_pesadas` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `ganaderia_pesadas` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `usuario_id`;
ALTER TABLE `ganaderia_pesadas` ADD COLUMN IF NOT EXISTS `categoria` enum('vacas_cria','vaquillonas','novillos','terneros','terneras','toros') NOT NULL AFTER `fecha`;
ALTER TABLE `ganaderia_pesadas` ADD COLUMN IF NOT EXISTS `cantidad_pesada` smallint(5) unsigned NOT NULL AFTER `categoria`;
ALTER TABLE `ganaderia_pesadas` ADD COLUMN IF NOT EXISTS `peso_promedio_kg` decimal(7,1) NOT NULL AFTER `cantidad_pesada`;
ALTER TABLE `ganaderia_pesadas` ADD COLUMN IF NOT EXISTS `peso_total_kg` decimal(10,1) AS (`cantidad_pesada` * `peso_promedio_kg`) STORED AFTER `peso_promedio_kg`;
ALTER TABLE `ganaderia_pesadas` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `peso_total_kg`;
ALTER TABLE `ganaderia_pesadas` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- ganaderia_reproductivo
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `ejercicio` varchar(10) NOT NULL COMMENT 'Ej: 2024/25' AFTER `usuario_id`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `vacas_servicio` smallint(5) unsigned NULL DEFAULT 0 AFTER `ejercicio`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `pct_prenez` decimal(5,2) NULL DEFAULT NULL AFTER `vacas_servicio`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `pct_destete` decimal(5,2) NULL DEFAULT NULL AFTER `pct_prenez`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `terneros_logrados` smallint(5) unsigned NULL DEFAULT 0 AFTER `pct_destete`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `fecha_inicio_servicio` date NULL DEFAULT NULL AFTER `terneros_logrados`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `fecha_fin_servicio` date NULL DEFAULT NULL AFTER `fecha_inicio_servicio`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `fecha_fin_servicio`;
ALTER TABLE `ganaderia_reproductivo` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- insumos
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `nombre` varchar(150) NOT NULL AFTER `usuario_id`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `tipo_insumo` enum('semilla','fertilizante','agroquimico','inoculante','otro') NOT NULL AFTER `nombre`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `unidad_medida` enum('kg','lt','dosis','bolsa') NOT NULL AFTER `tipo_insumo`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `precio_estimado_usd` decimal(14,4) NOT NULL DEFAULT 0.0000 AFTER `unidad_medida`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `estado` enum('activo','inactivo') NULL DEFAULT 'activo' AFTER `precio_estimado_usd`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `estado`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `stock_actual` decimal(12,4) NULL DEFAULT 0.0000 AFTER `created_at`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `unidad_stock` varchar(50) NULL DEFAULT NULL AFTER `stock_actual`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `fecha_vencimiento` date NULL DEFAULT NULL AFTER `unidad_stock`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `deposito_id` int(11) NULL DEFAULT NULL AFTER `fecha_vencimiento`;
ALTER TABLE `insumos` ADD COLUMN IF NOT EXISTS `stock_minimo` decimal(10,2) NULL DEFAULT NULL AFTER `deposito_id`;

-- lote_historial_campanas
ALTER TABLE `lote_historial_campanas` ADD COLUMN IF NOT EXISTS `lote_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `lote_historial_campanas` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `lote_id`;
ALTER TABLE `lote_historial_campanas` ADD COLUMN IF NOT EXISTS `campania` varchar(50) NULL DEFAULT NULL AFTER `usuario_id`;
ALTER TABLE `lote_historial_campanas` ADD COLUMN IF NOT EXISTS `cultivo` varchar(100) NULL DEFAULT NULL AFTER `campania`;
ALTER TABLE `lote_historial_campanas` ADD COLUMN IF NOT EXISTS `fecha_cierre` date NOT NULL AFTER `cultivo`;
ALTER TABLE `lote_historial_campanas` ADD COLUMN IF NOT EXISTS `kg_total` decimal(14,2) NULL DEFAULT NULL AFTER `fecha_cierre`;
ALTER TABLE `lote_historial_campanas` ADD COLUMN IF NOT EXISTS `ingreso_total` decimal(12,2) NULL DEFAULT 0.00 AFTER `kg_total`;
ALTER TABLE `lote_historial_campanas` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `ingreso_total`;

-- motor_consultas_fallidas
ALTER TABLE `motor_consultas_fallidas` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `motor_consultas_fallidas` ADD COLUMN IF NOT EXISTS `pregunta` varchar(300) NOT NULL AFTER `usuario_id`;
ALTER TABLE `motor_consultas_fallidas` ADD COLUMN IF NOT EXISTS `motivo` varchar(120) NULL DEFAULT NULL AFTER `pregunta`;
ALTER TABLE `motor_consultas_fallidas` ADD COLUMN IF NOT EXISTS `veces` int(11) NOT NULL DEFAULT 1 AFTER `motivo`;
ALTER TABLE `motor_consultas_fallidas` ADD COLUMN IF NOT EXISTS `creado_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `veces`;

-- motor_preferencias
ALTER TABLE `motor_preferencias` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL FIRST;
ALTER TABLE `motor_preferencias` ADD COLUMN IF NOT EXISTS `nombre` varchar(60) NULL DEFAULT NULL AFTER `usuario_id`;
ALTER TABLE `motor_preferencias` ADD COLUMN IF NOT EXISTS `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `nombre`;

-- operacion_insumos
ALTER TABLE `operacion_insumos` ADD COLUMN IF NOT EXISTS `operacion_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `operacion_insumos` ADD COLUMN IF NOT EXISTS `insumo_id` int(11) NULL DEFAULT NULL AFTER `operacion_id`;
ALTER TABLE `operacion_insumos` ADD COLUMN IF NOT EXISTS `cantidad_ha` decimal(14,4) NOT NULL DEFAULT 0.0000 AFTER `insumo_id`;
ALTER TABLE `operacion_insumos` ADD COLUMN IF NOT EXISTS `precio_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000 AFTER `cantidad_ha`;
ALTER TABLE `operacion_insumos` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `precio_unitario`;
ALTER TABLE `operacion_insumos` ADD COLUMN IF NOT EXISTS `nombre_libre` varchar(255) NULL DEFAULT NULL AFTER `created_at`;

-- operaciones
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `lote_id` int(11) NOT NULL AFTER `usuario_id`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `cultivo_id` int(11) NULL DEFAULT NULL AFTER `lote_id`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `grupo_gasto` enum('siembra','cosecha','pulverizacion','fertilizacion','otros') NOT NULL AFTER `cultivo_id`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `tipo_componente` enum('semilla','fertilizante','agroquimico','labor','maquinaria','insumo','multi_insumo','receta_labor') NOT NULL AFTER `grupo_gasto`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `insumo_id` int(11) NULL DEFAULT NULL AFTER `tipo_componente`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `proveedor_servicio` varchar(255) NULL DEFAULT NULL AFTER `insumo_id`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `cantidad_ha` decimal(14,4) NOT NULL DEFAULT 0.0000 AFTER `proveedor_servicio`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `precio_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000 AFTER `cantidad_ha`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `costo_total` decimal(12,2) NOT NULL AFTER `precio_unitario`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `costo_total`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `fecha`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `campania_operacion` varchar(50) NULL DEFAULT NULL AFTER `created_at`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `cultivo_operacion` varchar(100) NULL DEFAULT NULL AFTER `campania_operacion`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `grupo_descripcion` varchar(255) NULL DEFAULT NULL AFTER `cultivo_operacion`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `cargas` int(11) NULL DEFAULT 1 AFTER `grupo_descripcion`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `hectareas` decimal(10,2) NULL DEFAULT NULL AFTER `cargas`;
ALTER TABLE `operaciones` ADD COLUMN IF NOT EXISTS `moneda` enum('ARS','USD') NOT NULL DEFAULT 'ARS' COMMENT 'Moneda en que se pagÃ³. La conversiÃ³n se hace al calcular.' AFTER `hectareas`;

-- produccion_ventas
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `lote_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `cultivo_id` int(11) NULL DEFAULT NULL AFTER `lote_id`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `kg_cosechados` decimal(14,2) NULL DEFAULT NULL AFTER `cultivo_id`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `precio_kg` decimal(14,4) NOT NULL DEFAULT 0.0000 AFTER `kg_cosechados`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `ingreso_total` decimal(12,2) AS (`kg_cosechados` * `precio_kg`) STORED AFTER `precio_kg`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `fecha_venta` date NOT NULL AFTER `ingreso_total`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `fecha_venta`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL DEFAULT 1 AFTER `created_at`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `campania_vendida` varchar(50) NULL DEFAULT NULL AFTER `usuario_id`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `cultivo_vendido` varchar(100) NULL DEFAULT NULL AFTER `campania_vendida`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `notas` varchar(255) NULL DEFAULT NULL AFTER `cultivo_vendido`;
ALTER TABLE `produccion_ventas` ADD COLUMN IF NOT EXISTS `moneda` enum('ARS','USD') NOT NULL DEFAULT 'ARS' COMMENT 'Moneda del precio por kilo. La conversiÃ³n se hace al calcular.' AFTER `notas`;

-- tambo_calidad
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `usuario_id`;
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `rcs` int(10) unsigned NULL DEFAULT NULL COMMENT 'Recuento C├®lulas Som├íticas (miles)' AFTER `fecha`;
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `ufc` int(10) unsigned NULL DEFAULT NULL COMMENT 'UFC Bacterias Totales (miles)' AFTER `rcs`;
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `tenor_graso` decimal(4,2) NULL DEFAULT NULL COMMENT 'Porcentaje %' AFTER `ufc`;
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `tenor_prot` decimal(4,2) NULL DEFAULT NULL COMMENT 'Porcentaje %' AFTER `tenor_graso`;
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `temp_tank` decimal(4,1) NULL DEFAULT NULL COMMENT 'Temperatura tanque ┬░C' AFTER `tenor_prot`;
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `temp_tank`;
ALTER TABLE `tambo_calidad` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- tambo_dif_inventario
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `venta_carne_id` int(11) NOT NULL AFTER `usuario_id`;
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `mes_label_act` varchar(20) NULL DEFAULT NULL AFTER `venta_carne_id`;
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `mes_label_ant` varchar(20) NULL DEFAULT NULL AFTER `mes_label_act`;
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `categoria` varchar(80) NOT NULL AFTER `mes_label_ant`;
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `cant_actual` int(11) NOT NULL DEFAULT 0 AFTER `categoria`;
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `cant_anterior` int(11) NOT NULL DEFAULT 0 AFTER `cant_actual`;
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `valor_unitario` decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `cant_anterior`;
ALTER TABLE `tambo_dif_inventario` ADD COLUMN IF NOT EXISTS `criterio` varchar(150) NULL DEFAULT NULL AFTER `valor_unitario`;

-- tambo_dolar_mes
ALTER TABLE `tambo_dolar_mes` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `tambo_dolar_mes` ADD COLUMN IF NOT EXISTS `mes` varchar(7) NOT NULL COMMENT 'Formato YYYY-MM' AFTER `usuario_id`;
ALTER TABLE `tambo_dolar_mes` ADD COLUMN IF NOT EXISTS `dolar_mayorista` decimal(12,4) NOT NULL COMMENT 'TC mayorista al cierre del mes' AFTER `mes`;
ALTER TABLE `tambo_dolar_mes` ADD COLUMN IF NOT EXISTS `fuente` enum('api','manual') NOT NULL DEFAULT 'api' COMMENT 'api = guardado automático desde dolarapi.com, manual = ingresado por el usuario' AFTER `dolar_mayorista`;
ALTER TABLE `tambo_dolar_mes` ADD COLUMN IF NOT EXISTS `creado_en` timestamp NOT NULL DEFAULT current_timestamp() AFTER `fuente`;
ALTER TABLE `tambo_dolar_mes` ADD COLUMN IF NOT EXISTS `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `creado_en`;

-- tambo_egresos
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `usuario_id`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `categoria` varchar(80) NOT NULL AFTER `fecha`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `subcategoria` varchar(120) NULL DEFAULT NULL AFTER `categoria`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `concepto` varchar(200) NULL DEFAULT NULL COMMENT 'Item espec├¡fico o texto libre "Otros"' AFTER `subcategoria`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `cantidad` decimal(12,3) NULL DEFAULT NULL AFTER `concepto`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `unidad` enum('kg','lt','unidad') NULL DEFAULT 'unidad' AFTER `cantidad`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `precio_unitario` decimal(14,4) NULL DEFAULT NULL AFTER `unidad`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `moneda` enum('ARS','USD') NULL DEFAULT 'ARS' AFTER `precio_unitario`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `monto` decimal(14,2) NOT NULL AFTER `moneda`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `monto`;
ALTER TABLE `tambo_egresos` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- tambo_egresos_conceptos
ALTER TABLE `tambo_egresos_conceptos` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `tambo_egresos_conceptos` ADD COLUMN IF NOT EXISTS `categoria` varchar(80) NOT NULL AFTER `usuario_id`;
ALTER TABLE `tambo_egresos_conceptos` ADD COLUMN IF NOT EXISTS `subcategoria` varchar(120) NOT NULL AFTER `categoria`;
ALTER TABLE `tambo_egresos_conceptos` ADD COLUMN IF NOT EXISTS `nombre` varchar(200) NOT NULL AFTER `subcategoria`;
ALTER TABLE `tambo_egresos_conceptos` ADD COLUMN IF NOT EXISTS `activo` tinyint(1) NOT NULL DEFAULT 1 AFTER `nombre`;
ALTER TABLE `tambo_egresos_conceptos` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `activo`;

-- tambo_produccion
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `usuario_id`;
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `litros_manana` decimal(8,1) NULL DEFAULT 0.0 AFTER `fecha`;
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `litros_tarde` decimal(8,1) NULL DEFAULT 0.0 AFTER `litros_manana`;
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `litros_total` decimal(8,1) AS (`litros_manana` + `litros_tarde`) STORED AFTER `litros_tarde`;
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `precio_litro` decimal(10,2) NULL DEFAULT NULL COMMENT 'ARS por litro pagado ese d├¡a' AFTER `litros_total`;
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `destino` enum('industria','auto_consumo','descarte','buena','otra') NULL DEFAULT 'buena' AFTER `precio_litro`;
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `destino`;
ALTER TABLE `tambo_produccion` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- tambo_rodeo
ALTER TABLE `tambo_rodeo` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `tambo_rodeo` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `usuario_id`;
ALTER TABLE `tambo_rodeo` ADD COLUMN IF NOT EXISTS `vacas_ordene` smallint(5) unsigned NOT NULL DEFAULT 0 AFTER `fecha`;
ALTER TABLE `tambo_rodeo` ADD COLUMN IF NOT EXISTS `vacas_secas` smallint(5) unsigned NOT NULL DEFAULT 0 AFTER `vacas_ordene`;
ALTER TABLE `tambo_rodeo` ADD COLUMN IF NOT EXISTS `vaquillonas` smallint(5) unsigned NOT NULL DEFAULT 0 AFTER `vacas_secas`;
ALTER TABLE `tambo_rodeo` ADD COLUMN IF NOT EXISTS `terneros` smallint(5) unsigned NOT NULL DEFAULT 0 AFTER `vaquillonas`;
ALTER TABLE `tambo_rodeo` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `terneros`;
ALTER TABLE `tambo_rodeo` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;

-- tambo_ventas_carne
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `usuario_id` int(11) NOT NULL AFTER `id`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `fecha` date NOT NULL AFTER `usuario_id`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `tipo` enum('diferencia_inventario','venta_carne') NOT NULL AFTER `fecha`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `categoria_animal` enum('vaca','vaquillona','ternero','ternera','otro') NULL DEFAULT 'otro' AFTER `tipo`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `cantidad_animales` int(11) NULL DEFAULT NULL AFTER `categoria_animal`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `kg_vivo` decimal(10,2) NULL DEFAULT NULL AFTER `cantidad_animales`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `precio_kg` decimal(10,2) NULL DEFAULT NULL AFTER `kg_vivo`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `monto_total` decimal(12,2) NOT NULL AFTER `precio_kg`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `monto_original` decimal(14,2) NULL DEFAULT NULL AFTER `monto_total`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `notas` text NULL DEFAULT NULL AFTER `monto_original`;
ALTER TABLE `tambo_ventas_carne` ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `notas`;


-- ───────────────────────────────────────────────────────────────
-- PARTE 4 · Precisión de las columnas de plata.
-- ───────────────────────────────────────────────────────────────
-- La PARTE 3 no alcanza acá: ADD COLUMN IF NOT EXISTS no toca una
-- columna que ya existe, así que si quedó con 2 decimales pasa de
-- largo en silencio. Estas columnas guardan precios y cantidades: con
-- 2 decimales, un precio de 0,945 se guarda 0,95 y el total deja de
-- coincidir con el que se calculó al cargarlo.
--
-- MODIFY es idempotente por naturaleza: si ya está en 14,4 no hace
-- nada. Sólo amplía, nunca achica, así que no puede truncar un dato.

ALTER TABLE `operacion_insumos` MODIFY COLUMN `precio_unitario` DECIMAL(14,4) NOT NULL DEFAULT 0;
ALTER TABLE `operacion_insumos` MODIFY COLUMN `cantidad_ha` DECIMAL(14,4) NOT NULL DEFAULT 0;
ALTER TABLE `operaciones` MODIFY COLUMN `precio_unitario` DECIMAL(14,4) NOT NULL DEFAULT 0;
ALTER TABLE `operaciones` MODIFY COLUMN `cantidad_ha` DECIMAL(14,4) NOT NULL DEFAULT 0;
ALTER TABLE `insumos` MODIFY COLUMN `precio_estimado_usd` DECIMAL(14,4) NOT NULL DEFAULT 0;
ALTER TABLE `produccion_ventas` MODIFY COLUMN `precio_kg` DECIMAL(14,4) NOT NULL DEFAULT 0;
ALTER TABLE `alquileres` MODIFY COLUMN `monto_pagado` DECIMAL(14,4) NOT NULL DEFAULT 0;
ALTER TABLE `lotes` MODIFY COLUMN `costo_alquiler_tns_ha` DECIMAL(14,4) NULL DEFAULT NULL;


-- ───────────────────────────────────────────────────────────────
-- PARTE 5 · Verificación. Correr al final.
-- ───────────────────────────────────────────────────────────────
-- Compara la base contra el esquema esperado y muestra lo que no
-- coincida. Las PARTES 2 a 4 arreglan lo que saben arreglar; esto
-- delata cualquier otra diferencia en vez de dejarla pasar callada.
--
-- El esquema esperado va embebido en la consulta, no en una tabla
-- auxiliar: crearla exige permisos de CREATE y falla si phpMyAdmin no
-- tiene tu base seleccionada. Así no hace falta escribir nada.

-- Lo que falta o no coincide. VACÍO = la base está como tiene que estar.
SELECT e.tabla, e.columna, e.tipo AS esperado,
       COALESCE(c.COLUMN_TYPE, '(no existe)') AS encontrado
FROM (
        SELECT 'users' AS tabla, 'id' AS columna, 'int(11)' AS tipo
        UNION ALL SELECT 'users', 'username', 'varchar(50)'
        UNION ALL SELECT 'users', 'email', 'varchar(100)'
        UNION ALL SELECT 'users', 'password_hash', 'varchar(255)'
        UNION ALL SELECT 'users', 'role', 'enum(''admin'',''user'')'
        UNION ALL SELECT 'users', 'status', 'enum(''pending'',''active'')'
        UNION ALL SELECT 'users', 'created_at', 'timestamp'
        UNION ALL SELECT 'users', 'has_agricultura', 'tinyint(1)'
        UNION ALL SELECT 'users', 'has_tambo', 'tinyint(1)'
        UNION ALL SELECT 'users', 'has_ganaderia', 'tinyint(1)'
        UNION ALL SELECT 'users', 'reset_token', 'varchar(255)'
        UNION ALL SELECT 'users', 'reset_expires', 'datetime'
        UNION ALL SELECT 'users', 'solo_lectura', 'tinyint(1)'
        UNION ALL SELECT 'cultivos', 'id', 'int(11)'
        UNION ALL SELECT 'cultivos', 'lote_id', 'int(11)'
        UNION ALL SELECT 'cultivos', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'cultivos', 'fecha_siembra', 'date'
        UNION ALL SELECT 'cultivos', 'fecha_cosecha_esperada', 'date'
        UNION ALL SELECT 'cultivos', 'estado', 'enum(''activo'',''cosechado'',''perdido'')'
        UNION ALL SELECT 'cultivos', 'created_at', 'timestamp'
        UNION ALL SELECT 'cultivos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'cultivos', 'ciclo', 'varchar(50)'
        UNION ALL SELECT 'lotes', 'id', 'int(11)'
        UNION ALL SELECT 'lotes', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'lotes', 'superficie', 'decimal(10,2)'
        UNION ALL SELECT 'lotes', 'ubicacion', 'varchar(255)'
        UNION ALL SELECT 'lotes', 'tipo_suelo', 'varchar(100)'
        UNION ALL SELECT 'lotes', 'created_at', 'timestamp'
        UNION ALL SELECT 'lotes', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'lotes', 'latitud', 'decimal(10,8)'
        UNION ALL SELECT 'lotes', 'longitud', 'decimal(11,8)'
        UNION ALL SELECT 'lotes', 'tenencia', 'enum(''propio'',''alquilado'')'
        UNION ALL SELECT 'lotes', 'costo_alquiler_tns_ha', 'decimal(14,4)'
        UNION ALL SELECT 'lotes', 'campania', 'varchar(50)'
        UNION ALL SELECT 'lotes', 'cultivo_actual', 'varchar(100)'
        UNION ALL SELECT 'depositos', 'id', 'int(11)'
        UNION ALL SELECT 'depositos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'depositos', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'depositos', 'descripcion', 'varchar(255)'
        UNION ALL SELECT 'depositos', 'ubicacion', 'varchar(255)'
        UNION ALL SELECT 'depositos', 'created_at', 'timestamp'
        UNION ALL SELECT 'alquileres', 'id', 'int(11)'
        UNION ALL SELECT 'alquileres', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'alquileres', 'lote_id', 'int(11)'
        UNION ALL SELECT 'alquileres', 'cultivo_id', 'int(11)'
        UNION ALL SELECT 'alquileres', 'nivel_imputacion', 'enum(''lote'',''cultivo'',''campania'')'
        UNION ALL SELECT 'alquileres', 'campania', 'varchar(50)'
        UNION ALL SELECT 'alquileres', 'fecha_pago', 'date'
        UNION ALL SELECT 'alquileres', 'monto_pagado', 'decimal(14,4)'
        UNION ALL SELECT 'alquileres', 'moneda', 'enum(''USD'',''ARS'')'
        UNION ALL SELECT 'alquileres', 'notas', 'text'
        UNION ALL SELECT 'alquileres', 'created_at', 'timestamp'
        UNION ALL SELECT 'cotizaciones_siogranos', 'id', 'int(11)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'fecha', 'date'
        UNION ALL SELECT 'cotizaciones_siogranos', 'cultivo', 'varchar(50)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'producto_id', 'varchar(30)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'zona', 'varchar(80)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'zona_id', 'varchar(10)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'precio_promedio', 'decimal(12,2)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'precio_minimo', 'decimal(12,2)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'precio_maximo', 'decimal(12,2)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'precio_modal', 'decimal(12,2)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'moneda', 'varchar(5)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'fecha_actualizacion', 'datetime'
        UNION ALL SELECT 'feedlot_alimentos', 'id', 'int(11)'
        UNION ALL SELECT 'feedlot_alimentos', 'lote_id', 'int(11)'
        UNION ALL SELECT 'feedlot_alimentos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'feedlot_alimentos', 'fase', 'enum(''invernada'',''engorde'')'
        UNION ALL SELECT 'feedlot_alimentos', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'feedlot_alimentos', 'kg_x_dia', 'decimal(8,3)'
        UNION ALL SELECT 'feedlot_alimentos', 'precio_kg', 'decimal(10,2)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'id', 'int(11)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'lote_id', 'int(11)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'concepto', 'varchar(100)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'categoria', 'varchar(30)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'cantidad', 'decimal(10,2)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'precio_unitario', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'monto_mensual', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_lotes', 'id', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'feedlot_lotes', 'fecha_inicio', 'date'
        UNION ALL SELECT 'feedlot_lotes', 'cant_animales', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'pct_invernada', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'pct_engorde', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'kg_entrada_inv', 'decimal(8,2)'
        UNION ALL SELECT 'feedlot_lotes', 'kg_salida_inv', 'decimal(8,2)'
        UNION ALL SELECT 'feedlot_lotes', 'dias_invernada', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'kg_entrada_eng', 'decimal(8,2)'
        UNION ALL SELECT 'feedlot_lotes', 'kg_salida_eng', 'decimal(8,2)'
        UNION ALL SELECT 'feedlot_lotes', 'dias_engorde', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'conv_inv', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'conv_eng', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'desperdicio_pct', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'precio_compra', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_lotes', 'precio_venta_inv', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_lotes', 'precio_venta_eng', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_lotes', 'flete_compra_pct', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'flete_venta_pct', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'usd_referencia', 'decimal(10,2)'
        UNION ALL SELECT 'feedlot_lotes', 'notas', 'text'
        UNION ALL SELECT 'feedlot_lotes', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_egresos', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_egresos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_egresos', 'fecha', 'date'
        UNION ALL SELECT 'ganaderia_egresos', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'ganaderia_egresos', 'subcategoria', 'varchar(120)'
        UNION ALL SELECT 'ganaderia_egresos', 'concepto', 'varchar(200)'
        UNION ALL SELECT 'ganaderia_egresos', 'cantidad', 'decimal(12,3)'
        UNION ALL SELECT 'ganaderia_egresos', 'unidad', 'enum(''kg'',''lt'',''unidad'')'
        UNION ALL SELECT 'ganaderia_egresos', 'precio_unitario', 'decimal(14,4)'
        UNION ALL SELECT 'ganaderia_egresos', 'monto', 'decimal(14,2)'
        UNION ALL SELECT 'ganaderia_egresos', 'moneda', 'enum(''ARS'',''USD'')'
        UNION ALL SELECT 'ganaderia_egresos', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_egresos', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'subcategoria', 'varchar(120)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'nombre', 'varchar(200)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'activo', 'tinyint(1)'
        UNION ALL SELECT 'ganaderia_inventario', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_inventario', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_inventario', 'categoria', 'enum(''vacas_cria'',''vaquillonas'',''novillos'',''terneros'',''terneras'',''toros'',''toros_raza'')'
        UNION ALL SELECT 'ganaderia_inventario', 'cantidad', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_inventario', 'fecha', 'date'
        UNION ALL SELECT 'ganaderia_inventario', 'ejercicio', 'varchar(10)'
        UNION ALL SELECT 'ganaderia_inventario', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_inventario', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_movimientos', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_movimientos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_movimientos', 'fecha', 'date'
        UNION ALL SELECT 'ganaderia_movimientos', 'tipo', 'enum(''entrada'',''salida'',''transferencia'',''nacimiento'',''muerte'')'
        UNION ALL SELECT 'ganaderia_movimientos', 'categoria', 'enum(''vacas_cria'',''vaquillonas'',''novillos'',''terneros'',''terneras'',''toros'',''toros_raza'')'
        UNION ALL SELECT 'ganaderia_movimientos', 'cantidad', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_movimientos', 'precio_cabeza', 'decimal(12,2)'
        UNION ALL SELECT 'ganaderia_movimientos', 'total_kg', 'decimal(10,1)'
        UNION ALL SELECT 'ganaderia_movimientos', 'proveedor_destino', 'varchar(150)'
        UNION ALL SELECT 'ganaderia_movimientos', 'ejercicio', 'varchar(10)'
        UNION ALL SELECT 'ganaderia_movimientos', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_movimientos', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_pesadas', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_pesadas', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_pesadas', 'fecha', 'date'
        UNION ALL SELECT 'ganaderia_pesadas', 'categoria', 'enum(''vacas_cria'',''vaquillonas'',''novillos'',''terneros'',''terneras'',''toros'')'
        UNION ALL SELECT 'ganaderia_pesadas', 'cantidad_pesada', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_pesadas', 'peso_promedio_kg', 'decimal(7,1)'
        UNION ALL SELECT 'ganaderia_pesadas', 'peso_total_kg', 'decimal(10,1)'
        UNION ALL SELECT 'ganaderia_pesadas', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_pesadas', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_reproductivo', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'ejercicio', 'varchar(10)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'vacas_servicio', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_reproductivo', 'pct_prenez', 'decimal(5,2)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'pct_destete', 'decimal(5,2)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'terneros_logrados', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_reproductivo', 'fecha_inicio_servicio', 'date'
        UNION ALL SELECT 'ganaderia_reproductivo', 'fecha_fin_servicio', 'date'
        UNION ALL SELECT 'ganaderia_reproductivo', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_reproductivo', 'created_at', 'timestamp'
        UNION ALL SELECT 'insumos', 'id', 'int(11)'
        UNION ALL SELECT 'insumos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'insumos', 'nombre', 'varchar(150)'
        UNION ALL SELECT 'insumos', 'tipo_insumo', 'enum(''semilla'',''fertilizante'',''agroquimico'',''inoculante'',''otro'')'
        UNION ALL SELECT 'insumos', 'unidad_medida', 'enum(''kg'',''lt'',''dosis'',''bolsa'')'
        UNION ALL SELECT 'insumos', 'precio_estimado_usd', 'decimal(14,4)'
        UNION ALL SELECT 'insumos', 'estado', 'enum(''activo'',''inactivo'')'
        UNION ALL SELECT 'insumos', 'created_at', 'timestamp'
        UNION ALL SELECT 'insumos', 'stock_actual', 'decimal(12,4)'
        UNION ALL SELECT 'insumos', 'unidad_stock', 'varchar(50)'
        UNION ALL SELECT 'insumos', 'fecha_vencimiento', 'date'
        UNION ALL SELECT 'insumos', 'deposito_id', 'int(11)'
        UNION ALL SELECT 'insumos', 'stock_minimo', 'decimal(10,2)'
        UNION ALL SELECT 'lote_historial_campanas', 'id', 'int(11)'
        UNION ALL SELECT 'lote_historial_campanas', 'lote_id', 'int(11)'
        UNION ALL SELECT 'lote_historial_campanas', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'lote_historial_campanas', 'campania', 'varchar(50)'
        UNION ALL SELECT 'lote_historial_campanas', 'cultivo', 'varchar(100)'
        UNION ALL SELECT 'lote_historial_campanas', 'fecha_cierre', 'date'
        UNION ALL SELECT 'lote_historial_campanas', 'kg_total', 'decimal(14,2)'
        UNION ALL SELECT 'lote_historial_campanas', 'ingreso_total', 'decimal(12,2)'
        UNION ALL SELECT 'lote_historial_campanas', 'created_at', 'timestamp'
        UNION ALL SELECT 'motor_consultas_fallidas', 'id', 'int(11)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'pregunta', 'varchar(300)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'motivo', 'varchar(120)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'veces', 'int(11)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'creado_at', 'timestamp'
        UNION ALL SELECT 'motor_preferencias', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'motor_preferencias', 'nombre', 'varchar(60)'
        UNION ALL SELECT 'motor_preferencias', 'actualizado_at', 'timestamp'
        UNION ALL SELECT 'operacion_insumos', 'id', 'int(11)'
        UNION ALL SELECT 'operacion_insumos', 'operacion_id', 'int(11)'
        UNION ALL SELECT 'operacion_insumos', 'insumo_id', 'int(11)'
        UNION ALL SELECT 'operacion_insumos', 'cantidad_ha', 'decimal(14,4)'
        UNION ALL SELECT 'operacion_insumos', 'precio_unitario', 'decimal(14,4)'
        UNION ALL SELECT 'operacion_insumos', 'created_at', 'timestamp'
        UNION ALL SELECT 'operacion_insumos', 'nombre_libre', 'varchar(255)'
        UNION ALL SELECT 'operaciones', 'id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'lote_id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'cultivo_id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'grupo_gasto', 'enum(''siembra'',''cosecha'',''pulverizacion'',''fertilizacion'',''otros'')'
        UNION ALL SELECT 'operaciones', 'tipo_componente', 'enum(''semilla'',''fertilizante'',''agroquimico'',''labor'',''maquinaria'',''insumo'',''multi_insumo'',''receta_labor'')'
        UNION ALL SELECT 'operaciones', 'insumo_id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'proveedor_servicio', 'varchar(255)'
        UNION ALL SELECT 'operaciones', 'cantidad_ha', 'decimal(14,4)'
        UNION ALL SELECT 'operaciones', 'precio_unitario', 'decimal(14,4)'
        UNION ALL SELECT 'operaciones', 'costo_total', 'decimal(12,2)'
        UNION ALL SELECT 'operaciones', 'fecha', 'date'
        UNION ALL SELECT 'operaciones', 'created_at', 'timestamp'
        UNION ALL SELECT 'operaciones', 'campania_operacion', 'varchar(50)'
        UNION ALL SELECT 'operaciones', 'cultivo_operacion', 'varchar(100)'
        UNION ALL SELECT 'operaciones', 'grupo_descripcion', 'varchar(255)'
        UNION ALL SELECT 'operaciones', 'cargas', 'int(11)'
        UNION ALL SELECT 'operaciones', 'hectareas', 'decimal(10,2)'
        UNION ALL SELECT 'operaciones', 'moneda', 'enum(''ARS'',''USD'')'
        UNION ALL SELECT 'produccion_ventas', 'id', 'int(11)'
        UNION ALL SELECT 'produccion_ventas', 'lote_id', 'int(11)'
        UNION ALL SELECT 'produccion_ventas', 'cultivo_id', 'int(11)'
        UNION ALL SELECT 'produccion_ventas', 'kg_cosechados', 'decimal(14,2)'
        UNION ALL SELECT 'produccion_ventas', 'precio_kg', 'decimal(14,4)'
        UNION ALL SELECT 'produccion_ventas', 'ingreso_total', 'decimal(12,2)'
        UNION ALL SELECT 'produccion_ventas', 'fecha_venta', 'date'
        UNION ALL SELECT 'produccion_ventas', 'created_at', 'timestamp'
        UNION ALL SELECT 'produccion_ventas', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'produccion_ventas', 'campania_vendida', 'varchar(50)'
        UNION ALL SELECT 'produccion_ventas', 'cultivo_vendido', 'varchar(100)'
        UNION ALL SELECT 'produccion_ventas', 'notas', 'varchar(255)'
        UNION ALL SELECT 'produccion_ventas', 'moneda', 'enum(''ARS'',''USD'')'
        UNION ALL SELECT 'tambo_calidad', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_calidad', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_calidad', 'fecha', 'date'
        UNION ALL SELECT 'tambo_calidad', 'rcs', 'int(10) unsigned'
        UNION ALL SELECT 'tambo_calidad', 'ufc', 'int(10) unsigned'
        UNION ALL SELECT 'tambo_calidad', 'tenor_graso', 'decimal(4,2)'
        UNION ALL SELECT 'tambo_calidad', 'tenor_prot', 'decimal(4,2)'
        UNION ALL SELECT 'tambo_calidad', 'temp_tank', 'decimal(4,1)'
        UNION ALL SELECT 'tambo_calidad', 'notas', 'text'
        UNION ALL SELECT 'tambo_calidad', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_dif_inventario', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'venta_carne_id', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'mes_label_act', 'varchar(20)'
        UNION ALL SELECT 'tambo_dif_inventario', 'mes_label_ant', 'varchar(20)'
        UNION ALL SELECT 'tambo_dif_inventario', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'tambo_dif_inventario', 'cant_actual', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'cant_anterior', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'valor_unitario', 'decimal(14,2)'
        UNION ALL SELECT 'tambo_dif_inventario', 'criterio', 'varchar(150)'
        UNION ALL SELECT 'tambo_dolar_mes', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_dolar_mes', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_dolar_mes', 'mes', 'varchar(7)'
        UNION ALL SELECT 'tambo_dolar_mes', 'dolar_mayorista', 'decimal(12,4)'
        UNION ALL SELECT 'tambo_dolar_mes', 'fuente', 'enum(''api'',''manual'')'
        UNION ALL SELECT 'tambo_dolar_mes', 'creado_en', 'timestamp'
        UNION ALL SELECT 'tambo_dolar_mes', 'actualizado_en', 'timestamp'
        UNION ALL SELECT 'tambo_egresos', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_egresos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_egresos', 'fecha', 'date'
        UNION ALL SELECT 'tambo_egresos', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'tambo_egresos', 'subcategoria', 'varchar(120)'
        UNION ALL SELECT 'tambo_egresos', 'concepto', 'varchar(200)'
        UNION ALL SELECT 'tambo_egresos', 'cantidad', 'decimal(12,3)'
        UNION ALL SELECT 'tambo_egresos', 'unidad', 'enum(''kg'',''lt'',''unidad'')'
        UNION ALL SELECT 'tambo_egresos', 'precio_unitario', 'decimal(14,4)'
        UNION ALL SELECT 'tambo_egresos', 'moneda', 'enum(''ARS'',''USD'')'
        UNION ALL SELECT 'tambo_egresos', 'monto', 'decimal(14,2)'
        UNION ALL SELECT 'tambo_egresos', 'notas', 'text'
        UNION ALL SELECT 'tambo_egresos', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'subcategoria', 'varchar(120)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'nombre', 'varchar(200)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'activo', 'tinyint(1)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_produccion', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_produccion', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_produccion', 'fecha', 'date'
        UNION ALL SELECT 'tambo_produccion', 'litros_manana', 'decimal(8,1)'
        UNION ALL SELECT 'tambo_produccion', 'litros_tarde', 'decimal(8,1)'
        UNION ALL SELECT 'tambo_produccion', 'litros_total', 'decimal(8,1)'
        UNION ALL SELECT 'tambo_produccion', 'precio_litro', 'decimal(10,2)'
        UNION ALL SELECT 'tambo_produccion', 'destino', 'enum(''industria'',''auto_consumo'',''descarte'',''buena'',''otra'')'
        UNION ALL SELECT 'tambo_produccion', 'notas', 'text'
        UNION ALL SELECT 'tambo_produccion', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_rodeo', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_rodeo', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_rodeo', 'fecha', 'date'
        UNION ALL SELECT 'tambo_rodeo', 'vacas_ordene', 'smallint(5) unsigned'
        UNION ALL SELECT 'tambo_rodeo', 'vacas_secas', 'smallint(5) unsigned'
        UNION ALL SELECT 'tambo_rodeo', 'vaquillonas', 'smallint(5) unsigned'
        UNION ALL SELECT 'tambo_rodeo', 'terneros', 'smallint(5) unsigned'
        UNION ALL SELECT 'tambo_rodeo', 'notas', 'text'
        UNION ALL SELECT 'tambo_rodeo', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_ventas_carne', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_ventas_carne', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_ventas_carne', 'fecha', 'date'
        UNION ALL SELECT 'tambo_ventas_carne', 'tipo', 'enum(''diferencia_inventario'',''venta_carne'')'
        UNION ALL SELECT 'tambo_ventas_carne', 'categoria_animal', 'enum(''vaca'',''vaquillona'',''ternero'',''ternera'',''otro'')'
        UNION ALL SELECT 'tambo_ventas_carne', 'cantidad_animales', 'int(11)'
        UNION ALL SELECT 'tambo_ventas_carne', 'kg_vivo', 'decimal(10,2)'
        UNION ALL SELECT 'tambo_ventas_carne', 'precio_kg', 'decimal(10,2)'
        UNION ALL SELECT 'tambo_ventas_carne', 'monto_total', 'decimal(12,2)'
        UNION ALL SELECT 'tambo_ventas_carne', 'monto_original', 'decimal(14,2)'
        UNION ALL SELECT 'tambo_ventas_carne', 'notas', 'text'
        UNION ALL SELECT 'tambo_ventas_carne', 'created_at', 'timestamp'
) e
LEFT JOIN information_schema.COLUMNS c
       ON c.TABLE_SCHEMA = DATABASE()
      AND c.TABLE_NAME   = e.tabla
      AND c.COLUMN_NAME  = e.columna
WHERE c.COLUMN_TYPE IS NULL OR c.COLUMN_TYPE <> e.tipo
ORDER BY e.tabla, e.columna;

-- Columnas de más: están en la base pero no en el esquema esperado.
-- No se borran —pueden ser de algo que todavía no está en el repo—
-- pero conviene saber que están.
SELECT c.TABLE_NAME AS tabla, c.COLUMN_NAME AS columna, c.COLUMN_TYPE AS tipo
FROM information_schema.COLUMNS c
JOIN information_schema.TABLES t
       ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
      AND t.TABLE_TYPE = 'BASE TABLE'
LEFT JOIN (
        SELECT 'users' AS tabla, 'id' AS columna, 'int(11)' AS tipo
        UNION ALL SELECT 'users', 'username', 'varchar(50)'
        UNION ALL SELECT 'users', 'email', 'varchar(100)'
        UNION ALL SELECT 'users', 'password_hash', 'varchar(255)'
        UNION ALL SELECT 'users', 'role', 'enum(''admin'',''user'')'
        UNION ALL SELECT 'users', 'status', 'enum(''pending'',''active'')'
        UNION ALL SELECT 'users', 'created_at', 'timestamp'
        UNION ALL SELECT 'users', 'has_agricultura', 'tinyint(1)'
        UNION ALL SELECT 'users', 'has_tambo', 'tinyint(1)'
        UNION ALL SELECT 'users', 'has_ganaderia', 'tinyint(1)'
        UNION ALL SELECT 'users', 'reset_token', 'varchar(255)'
        UNION ALL SELECT 'users', 'reset_expires', 'datetime'
        UNION ALL SELECT 'users', 'solo_lectura', 'tinyint(1)'
        UNION ALL SELECT 'cultivos', 'id', 'int(11)'
        UNION ALL SELECT 'cultivos', 'lote_id', 'int(11)'
        UNION ALL SELECT 'cultivos', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'cultivos', 'fecha_siembra', 'date'
        UNION ALL SELECT 'cultivos', 'fecha_cosecha_esperada', 'date'
        UNION ALL SELECT 'cultivos', 'estado', 'enum(''activo'',''cosechado'',''perdido'')'
        UNION ALL SELECT 'cultivos', 'created_at', 'timestamp'
        UNION ALL SELECT 'cultivos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'cultivos', 'ciclo', 'varchar(50)'
        UNION ALL SELECT 'lotes', 'id', 'int(11)'
        UNION ALL SELECT 'lotes', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'lotes', 'superficie', 'decimal(10,2)'
        UNION ALL SELECT 'lotes', 'ubicacion', 'varchar(255)'
        UNION ALL SELECT 'lotes', 'tipo_suelo', 'varchar(100)'
        UNION ALL SELECT 'lotes', 'created_at', 'timestamp'
        UNION ALL SELECT 'lotes', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'lotes', 'latitud', 'decimal(10,8)'
        UNION ALL SELECT 'lotes', 'longitud', 'decimal(11,8)'
        UNION ALL SELECT 'lotes', 'tenencia', 'enum(''propio'',''alquilado'')'
        UNION ALL SELECT 'lotes', 'costo_alquiler_tns_ha', 'decimal(14,4)'
        UNION ALL SELECT 'lotes', 'campania', 'varchar(50)'
        UNION ALL SELECT 'lotes', 'cultivo_actual', 'varchar(100)'
        UNION ALL SELECT 'depositos', 'id', 'int(11)'
        UNION ALL SELECT 'depositos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'depositos', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'depositos', 'descripcion', 'varchar(255)'
        UNION ALL SELECT 'depositos', 'ubicacion', 'varchar(255)'
        UNION ALL SELECT 'depositos', 'created_at', 'timestamp'
        UNION ALL SELECT 'alquileres', 'id', 'int(11)'
        UNION ALL SELECT 'alquileres', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'alquileres', 'lote_id', 'int(11)'
        UNION ALL SELECT 'alquileres', 'cultivo_id', 'int(11)'
        UNION ALL SELECT 'alquileres', 'nivel_imputacion', 'enum(''lote'',''cultivo'',''campania'')'
        UNION ALL SELECT 'alquileres', 'campania', 'varchar(50)'
        UNION ALL SELECT 'alquileres', 'fecha_pago', 'date'
        UNION ALL SELECT 'alquileres', 'monto_pagado', 'decimal(14,4)'
        UNION ALL SELECT 'alquileres', 'moneda', 'enum(''USD'',''ARS'')'
        UNION ALL SELECT 'alquileres', 'notas', 'text'
        UNION ALL SELECT 'alquileres', 'created_at', 'timestamp'
        UNION ALL SELECT 'cotizaciones_siogranos', 'id', 'int(11)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'fecha', 'date'
        UNION ALL SELECT 'cotizaciones_siogranos', 'cultivo', 'varchar(50)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'producto_id', 'varchar(30)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'zona', 'varchar(80)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'zona_id', 'varchar(10)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'precio_promedio', 'decimal(12,2)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'precio_minimo', 'decimal(12,2)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'precio_maximo', 'decimal(12,2)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'precio_modal', 'decimal(12,2)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'moneda', 'varchar(5)'
        UNION ALL SELECT 'cotizaciones_siogranos', 'fecha_actualizacion', 'datetime'
        UNION ALL SELECT 'feedlot_alimentos', 'id', 'int(11)'
        UNION ALL SELECT 'feedlot_alimentos', 'lote_id', 'int(11)'
        UNION ALL SELECT 'feedlot_alimentos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'feedlot_alimentos', 'fase', 'enum(''invernada'',''engorde'')'
        UNION ALL SELECT 'feedlot_alimentos', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'feedlot_alimentos', 'kg_x_dia', 'decimal(8,3)'
        UNION ALL SELECT 'feedlot_alimentos', 'precio_kg', 'decimal(10,2)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'id', 'int(11)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'lote_id', 'int(11)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'concepto', 'varchar(100)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'categoria', 'varchar(30)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'cantidad', 'decimal(10,2)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'precio_unitario', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_costos_fijos', 'monto_mensual', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_lotes', 'id', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'nombre', 'varchar(100)'
        UNION ALL SELECT 'feedlot_lotes', 'fecha_inicio', 'date'
        UNION ALL SELECT 'feedlot_lotes', 'cant_animales', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'pct_invernada', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'pct_engorde', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'kg_entrada_inv', 'decimal(8,2)'
        UNION ALL SELECT 'feedlot_lotes', 'kg_salida_inv', 'decimal(8,2)'
        UNION ALL SELECT 'feedlot_lotes', 'dias_invernada', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'kg_entrada_eng', 'decimal(8,2)'
        UNION ALL SELECT 'feedlot_lotes', 'kg_salida_eng', 'decimal(8,2)'
        UNION ALL SELECT 'feedlot_lotes', 'dias_engorde', 'int(11)'
        UNION ALL SELECT 'feedlot_lotes', 'conv_inv', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'conv_eng', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'desperdicio_pct', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'precio_compra', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_lotes', 'precio_venta_inv', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_lotes', 'precio_venta_eng', 'decimal(14,2)'
        UNION ALL SELECT 'feedlot_lotes', 'flete_compra_pct', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'flete_venta_pct', 'decimal(5,2)'
        UNION ALL SELECT 'feedlot_lotes', 'usd_referencia', 'decimal(10,2)'
        UNION ALL SELECT 'feedlot_lotes', 'notas', 'text'
        UNION ALL SELECT 'feedlot_lotes', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_egresos', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_egresos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_egresos', 'fecha', 'date'
        UNION ALL SELECT 'ganaderia_egresos', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'ganaderia_egresos', 'subcategoria', 'varchar(120)'
        UNION ALL SELECT 'ganaderia_egresos', 'concepto', 'varchar(200)'
        UNION ALL SELECT 'ganaderia_egresos', 'cantidad', 'decimal(12,3)'
        UNION ALL SELECT 'ganaderia_egresos', 'unidad', 'enum(''kg'',''lt'',''unidad'')'
        UNION ALL SELECT 'ganaderia_egresos', 'precio_unitario', 'decimal(14,4)'
        UNION ALL SELECT 'ganaderia_egresos', 'monto', 'decimal(14,2)'
        UNION ALL SELECT 'ganaderia_egresos', 'moneda', 'enum(''ARS'',''USD'')'
        UNION ALL SELECT 'ganaderia_egresos', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_egresos', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'subcategoria', 'varchar(120)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'nombre', 'varchar(200)'
        UNION ALL SELECT 'ganaderia_egresos_conceptos', 'activo', 'tinyint(1)'
        UNION ALL SELECT 'ganaderia_inventario', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_inventario', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_inventario', 'categoria', 'enum(''vacas_cria'',''vaquillonas'',''novillos'',''terneros'',''terneras'',''toros'',''toros_raza'')'
        UNION ALL SELECT 'ganaderia_inventario', 'cantidad', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_inventario', 'fecha', 'date'
        UNION ALL SELECT 'ganaderia_inventario', 'ejercicio', 'varchar(10)'
        UNION ALL SELECT 'ganaderia_inventario', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_inventario', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_movimientos', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_movimientos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_movimientos', 'fecha', 'date'
        UNION ALL SELECT 'ganaderia_movimientos', 'tipo', 'enum(''entrada'',''salida'',''transferencia'',''nacimiento'',''muerte'')'
        UNION ALL SELECT 'ganaderia_movimientos', 'categoria', 'enum(''vacas_cria'',''vaquillonas'',''novillos'',''terneros'',''terneras'',''toros'',''toros_raza'')'
        UNION ALL SELECT 'ganaderia_movimientos', 'cantidad', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_movimientos', 'precio_cabeza', 'decimal(12,2)'
        UNION ALL SELECT 'ganaderia_movimientos', 'total_kg', 'decimal(10,1)'
        UNION ALL SELECT 'ganaderia_movimientos', 'proveedor_destino', 'varchar(150)'
        UNION ALL SELECT 'ganaderia_movimientos', 'ejercicio', 'varchar(10)'
        UNION ALL SELECT 'ganaderia_movimientos', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_movimientos', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_pesadas', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_pesadas', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_pesadas', 'fecha', 'date'
        UNION ALL SELECT 'ganaderia_pesadas', 'categoria', 'enum(''vacas_cria'',''vaquillonas'',''novillos'',''terneros'',''terneras'',''toros'')'
        UNION ALL SELECT 'ganaderia_pesadas', 'cantidad_pesada', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_pesadas', 'peso_promedio_kg', 'decimal(7,1)'
        UNION ALL SELECT 'ganaderia_pesadas', 'peso_total_kg', 'decimal(10,1)'
        UNION ALL SELECT 'ganaderia_pesadas', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_pesadas', 'created_at', 'timestamp'
        UNION ALL SELECT 'ganaderia_reproductivo', 'id', 'int(11)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'ejercicio', 'varchar(10)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'vacas_servicio', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_reproductivo', 'pct_prenez', 'decimal(5,2)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'pct_destete', 'decimal(5,2)'
        UNION ALL SELECT 'ganaderia_reproductivo', 'terneros_logrados', 'smallint(5) unsigned'
        UNION ALL SELECT 'ganaderia_reproductivo', 'fecha_inicio_servicio', 'date'
        UNION ALL SELECT 'ganaderia_reproductivo', 'fecha_fin_servicio', 'date'
        UNION ALL SELECT 'ganaderia_reproductivo', 'notas', 'text'
        UNION ALL SELECT 'ganaderia_reproductivo', 'created_at', 'timestamp'
        UNION ALL SELECT 'insumos', 'id', 'int(11)'
        UNION ALL SELECT 'insumos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'insumos', 'nombre', 'varchar(150)'
        UNION ALL SELECT 'insumos', 'tipo_insumo', 'enum(''semilla'',''fertilizante'',''agroquimico'',''inoculante'',''otro'')'
        UNION ALL SELECT 'insumos', 'unidad_medida', 'enum(''kg'',''lt'',''dosis'',''bolsa'')'
        UNION ALL SELECT 'insumos', 'precio_estimado_usd', 'decimal(14,4)'
        UNION ALL SELECT 'insumos', 'estado', 'enum(''activo'',''inactivo'')'
        UNION ALL SELECT 'insumos', 'created_at', 'timestamp'
        UNION ALL SELECT 'insumos', 'stock_actual', 'decimal(12,4)'
        UNION ALL SELECT 'insumos', 'unidad_stock', 'varchar(50)'
        UNION ALL SELECT 'insumos', 'fecha_vencimiento', 'date'
        UNION ALL SELECT 'insumos', 'deposito_id', 'int(11)'
        UNION ALL SELECT 'insumos', 'stock_minimo', 'decimal(10,2)'
        UNION ALL SELECT 'lote_historial_campanas', 'id', 'int(11)'
        UNION ALL SELECT 'lote_historial_campanas', 'lote_id', 'int(11)'
        UNION ALL SELECT 'lote_historial_campanas', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'lote_historial_campanas', 'campania', 'varchar(50)'
        UNION ALL SELECT 'lote_historial_campanas', 'cultivo', 'varchar(100)'
        UNION ALL SELECT 'lote_historial_campanas', 'fecha_cierre', 'date'
        UNION ALL SELECT 'lote_historial_campanas', 'kg_total', 'decimal(14,2)'
        UNION ALL SELECT 'lote_historial_campanas', 'ingreso_total', 'decimal(12,2)'
        UNION ALL SELECT 'lote_historial_campanas', 'created_at', 'timestamp'
        UNION ALL SELECT 'motor_consultas_fallidas', 'id', 'int(11)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'pregunta', 'varchar(300)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'motivo', 'varchar(120)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'veces', 'int(11)'
        UNION ALL SELECT 'motor_consultas_fallidas', 'creado_at', 'timestamp'
        UNION ALL SELECT 'motor_preferencias', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'motor_preferencias', 'nombre', 'varchar(60)'
        UNION ALL SELECT 'motor_preferencias', 'actualizado_at', 'timestamp'
        UNION ALL SELECT 'operacion_insumos', 'id', 'int(11)'
        UNION ALL SELECT 'operacion_insumos', 'operacion_id', 'int(11)'
        UNION ALL SELECT 'operacion_insumos', 'insumo_id', 'int(11)'
        UNION ALL SELECT 'operacion_insumos', 'cantidad_ha', 'decimal(14,4)'
        UNION ALL SELECT 'operacion_insumos', 'precio_unitario', 'decimal(14,4)'
        UNION ALL SELECT 'operacion_insumos', 'created_at', 'timestamp'
        UNION ALL SELECT 'operacion_insumos', 'nombre_libre', 'varchar(255)'
        UNION ALL SELECT 'operaciones', 'id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'lote_id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'cultivo_id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'grupo_gasto', 'enum(''siembra'',''cosecha'',''pulverizacion'',''fertilizacion'',''otros'')'
        UNION ALL SELECT 'operaciones', 'tipo_componente', 'enum(''semilla'',''fertilizante'',''agroquimico'',''labor'',''maquinaria'',''insumo'',''multi_insumo'',''receta_labor'')'
        UNION ALL SELECT 'operaciones', 'insumo_id', 'int(11)'
        UNION ALL SELECT 'operaciones', 'proveedor_servicio', 'varchar(255)'
        UNION ALL SELECT 'operaciones', 'cantidad_ha', 'decimal(14,4)'
        UNION ALL SELECT 'operaciones', 'precio_unitario', 'decimal(14,4)'
        UNION ALL SELECT 'operaciones', 'costo_total', 'decimal(12,2)'
        UNION ALL SELECT 'operaciones', 'fecha', 'date'
        UNION ALL SELECT 'operaciones', 'created_at', 'timestamp'
        UNION ALL SELECT 'operaciones', 'campania_operacion', 'varchar(50)'
        UNION ALL SELECT 'operaciones', 'cultivo_operacion', 'varchar(100)'
        UNION ALL SELECT 'operaciones', 'grupo_descripcion', 'varchar(255)'
        UNION ALL SELECT 'operaciones', 'cargas', 'int(11)'
        UNION ALL SELECT 'operaciones', 'hectareas', 'decimal(10,2)'
        UNION ALL SELECT 'operaciones', 'moneda', 'enum(''ARS'',''USD'')'
        UNION ALL SELECT 'produccion_ventas', 'id', 'int(11)'
        UNION ALL SELECT 'produccion_ventas', 'lote_id', 'int(11)'
        UNION ALL SELECT 'produccion_ventas', 'cultivo_id', 'int(11)'
        UNION ALL SELECT 'produccion_ventas', 'kg_cosechados', 'decimal(14,2)'
        UNION ALL SELECT 'produccion_ventas', 'precio_kg', 'decimal(14,4)'
        UNION ALL SELECT 'produccion_ventas', 'ingreso_total', 'decimal(12,2)'
        UNION ALL SELECT 'produccion_ventas', 'fecha_venta', 'date'
        UNION ALL SELECT 'produccion_ventas', 'created_at', 'timestamp'
        UNION ALL SELECT 'produccion_ventas', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'produccion_ventas', 'campania_vendida', 'varchar(50)'
        UNION ALL SELECT 'produccion_ventas', 'cultivo_vendido', 'varchar(100)'
        UNION ALL SELECT 'produccion_ventas', 'notas', 'varchar(255)'
        UNION ALL SELECT 'produccion_ventas', 'moneda', 'enum(''ARS'',''USD'')'
        UNION ALL SELECT 'tambo_calidad', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_calidad', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_calidad', 'fecha', 'date'
        UNION ALL SELECT 'tambo_calidad', 'rcs', 'int(10) unsigned'
        UNION ALL SELECT 'tambo_calidad', 'ufc', 'int(10) unsigned'
        UNION ALL SELECT 'tambo_calidad', 'tenor_graso', 'decimal(4,2)'
        UNION ALL SELECT 'tambo_calidad', 'tenor_prot', 'decimal(4,2)'
        UNION ALL SELECT 'tambo_calidad', 'temp_tank', 'decimal(4,1)'
        UNION ALL SELECT 'tambo_calidad', 'notas', 'text'
        UNION ALL SELECT 'tambo_calidad', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_dif_inventario', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'venta_carne_id', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'mes_label_act', 'varchar(20)'
        UNION ALL SELECT 'tambo_dif_inventario', 'mes_label_ant', 'varchar(20)'
        UNION ALL SELECT 'tambo_dif_inventario', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'tambo_dif_inventario', 'cant_actual', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'cant_anterior', 'int(11)'
        UNION ALL SELECT 'tambo_dif_inventario', 'valor_unitario', 'decimal(14,2)'
        UNION ALL SELECT 'tambo_dif_inventario', 'criterio', 'varchar(150)'
        UNION ALL SELECT 'tambo_dolar_mes', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_dolar_mes', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_dolar_mes', 'mes', 'varchar(7)'
        UNION ALL SELECT 'tambo_dolar_mes', 'dolar_mayorista', 'decimal(12,4)'
        UNION ALL SELECT 'tambo_dolar_mes', 'fuente', 'enum(''api'',''manual'')'
        UNION ALL SELECT 'tambo_dolar_mes', 'creado_en', 'timestamp'
        UNION ALL SELECT 'tambo_dolar_mes', 'actualizado_en', 'timestamp'
        UNION ALL SELECT 'tambo_egresos', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_egresos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_egresos', 'fecha', 'date'
        UNION ALL SELECT 'tambo_egresos', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'tambo_egresos', 'subcategoria', 'varchar(120)'
        UNION ALL SELECT 'tambo_egresos', 'concepto', 'varchar(200)'
        UNION ALL SELECT 'tambo_egresos', 'cantidad', 'decimal(12,3)'
        UNION ALL SELECT 'tambo_egresos', 'unidad', 'enum(''kg'',''lt'',''unidad'')'
        UNION ALL SELECT 'tambo_egresos', 'precio_unitario', 'decimal(14,4)'
        UNION ALL SELECT 'tambo_egresos', 'moneda', 'enum(''ARS'',''USD'')'
        UNION ALL SELECT 'tambo_egresos', 'monto', 'decimal(14,2)'
        UNION ALL SELECT 'tambo_egresos', 'notas', 'text'
        UNION ALL SELECT 'tambo_egresos', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'categoria', 'varchar(80)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'subcategoria', 'varchar(120)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'nombre', 'varchar(200)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'activo', 'tinyint(1)'
        UNION ALL SELECT 'tambo_egresos_conceptos', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_produccion', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_produccion', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_produccion', 'fecha', 'date'
        UNION ALL SELECT 'tambo_produccion', 'litros_manana', 'decimal(8,1)'
        UNION ALL SELECT 'tambo_produccion', 'litros_tarde', 'decimal(8,1)'
        UNION ALL SELECT 'tambo_produccion', 'litros_total', 'decimal(8,1)'
        UNION ALL SELECT 'tambo_produccion', 'precio_litro', 'decimal(10,2)'
        UNION ALL SELECT 'tambo_produccion', 'destino', 'enum(''industria'',''auto_consumo'',''descarte'',''buena'',''otra'')'
        UNION ALL SELECT 'tambo_produccion', 'notas', 'text'
        UNION ALL SELECT 'tambo_produccion', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_rodeo', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_rodeo', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_rodeo', 'fecha', 'date'
        UNION ALL SELECT 'tambo_rodeo', 'vacas_ordene', 'smallint(5) unsigned'
        UNION ALL SELECT 'tambo_rodeo', 'vacas_secas', 'smallint(5) unsigned'
        UNION ALL SELECT 'tambo_rodeo', 'vaquillonas', 'smallint(5) unsigned'
        UNION ALL SELECT 'tambo_rodeo', 'terneros', 'smallint(5) unsigned'
        UNION ALL SELECT 'tambo_rodeo', 'notas', 'text'
        UNION ALL SELECT 'tambo_rodeo', 'created_at', 'timestamp'
        UNION ALL SELECT 'tambo_ventas_carne', 'id', 'int(11)'
        UNION ALL SELECT 'tambo_ventas_carne', 'usuario_id', 'int(11)'
        UNION ALL SELECT 'tambo_ventas_carne', 'fecha', 'date'
        UNION ALL SELECT 'tambo_ventas_carne', 'tipo', 'enum(''diferencia_inventario'',''venta_carne'')'
        UNION ALL SELECT 'tambo_ventas_carne', 'categoria_animal', 'enum(''vaca'',''vaquillona'',''ternero'',''ternera'',''otro'')'
        UNION ALL SELECT 'tambo_ventas_carne', 'cantidad_animales', 'int(11)'
        UNION ALL SELECT 'tambo_ventas_carne', 'kg_vivo', 'decimal(10,2)'
        UNION ALL SELECT 'tambo_ventas_carne', 'precio_kg', 'decimal(10,2)'
        UNION ALL SELECT 'tambo_ventas_carne', 'monto_total', 'decimal(12,2)'
        UNION ALL SELECT 'tambo_ventas_carne', 'monto_original', 'decimal(14,2)'
        UNION ALL SELECT 'tambo_ventas_carne', 'notas', 'text'
        UNION ALL SELECT 'tambo_ventas_carne', 'created_at', 'timestamp'
) e ON e.tabla = c.TABLE_NAME AND e.columna = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.TABLE_NAME NOT LIKE '\_%'
  AND e.columna IS NULL
ORDER BY c.TABLE_NAME, c.COLUMN_NAME;

-- Colación: tiene que volver VACÍA. Una tabla con colación distinta hace
-- que un JOIN por texto contra otra tabla aborte con 1267 Illegal mix of
-- collations. Ya tiró el tablero una vez.
--
-- OJO: esto lo DETECTA pero no lo arregla. Convertir una tabla que ya
-- existe reescribe sus índices, que es una operación bastante más pesada
-- que todo lo demás de este archivo, y no corresponde meterla acá de
-- prepo. Si devuelve filas, correr normalize_collation_utf8mb4_unicode_ci.sql
SELECT TABLE_NAME AS tabla, TABLE_COLLATION AS colacion
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
  AND TABLE_NAME NOT LIKE '\_%'
  AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'
ORDER BY TABLE_NAME;
