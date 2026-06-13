-- ═══════════════════════════════════════════════════════════════
-- Normalización del cultivo (Fase 2.2)
-- ═══════════════════════════════════════════════════════════════
-- Objetivo: que `cultivo_id` sea la referencia canónica del cultivo en
-- operaciones / produccion_ventas / alquileres, en vez del texto suelto.
--
-- Los FK de cultivo_id YA existen (operaciones_ibfk_2, produccion_ventas_ibfk_2).
-- Acá solo: (1) evitamos cultivos duplicados con un UNIQUE, y (2) corregimos
-- el FK de ventas para que borrar un cultivo NO borre las ventas.
--
-- Aplicar con: php migrations/run_normalize_cultivos.php  (idempotente)
-- ═══════════════════════════════════════════════════════════════

-- (1) Un cultivo queda identificado de forma única por usuario+lote+nombre+ciclo.
ALTER TABLE `cultivos`
    ADD UNIQUE KEY `uk_cultivo` (`usuario_id`, `lote_id`, `nombre`, `ciclo`);

-- (2) produccion_ventas.cultivo_id: pasar de ON DELETE CASCADE a SET NULL,
--     para no perder ventas si se elimina/fusiona un cultivo. (El borrado de
--     un lote sigue limpiando las ventas vía produccion_ventas_ibfk_1.)
ALTER TABLE `produccion_ventas` DROP FOREIGN KEY `produccion_ventas_ibfk_2`;
ALTER TABLE `produccion_ventas`
    ADD CONSTRAINT `produccion_ventas_ibfk_2`
    FOREIGN KEY (`cultivo_id`) REFERENCES `cultivos` (`id`) ON DELETE SET NULL;
