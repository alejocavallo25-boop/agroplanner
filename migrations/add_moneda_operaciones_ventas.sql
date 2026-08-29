-- ─── Moneda por operación y por venta ────────────────────────────────────────
--
-- Hasta acá el margen sumaba monedas distintas sin decirlo: los alquileres se
-- calculaban en USD (alquileres ya tenía su columna `moneda` y el panel convertía
-- con tambo_dolar_mes), mientras que los costos de operaciones y los ingresos de
-- ventas entraban crudos, en pesos. El resultado era que un alquiler de USD 8.500
-- le restaba al margen ocho mil quinientos pesos en vez de doce millones.
--
-- La solución no es pasar todo a dólares al cargarlo: no hay tipo de cambio
-- histórico para convertir lo ya cargado —la tabla de cotizaciones arranca hace
-- pocos meses— y el productor no paga todo en la misma moneda: el contratista
-- factura en pesos y el alquiler se pacta en dólares. Se guarda lo que realmente
-- pagó, en la moneda en que lo pagó, y la conversión se hace al calcular, con la
-- cotización del mes de cada movimiento. Es lo que ya hacía alquileres.
--
-- DEFAULT 'ARS' a propósito: es lo que son todas las filas que existen hoy. La
-- migración no reinterpreta ni convierte nada — sólo pone nombre a lo que ya había.

ALTER TABLE operaciones
    ADD COLUMN moneda ENUM('ARS','USD') NOT NULL DEFAULT 'ARS'
    COMMENT 'Moneda en que se pagó. La conversión se hace al calcular.';

ALTER TABLE produccion_ventas
    ADD COLUMN moneda ENUM('ARS','USD') NOT NULL DEFAULT 'ARS'
    COMMENT 'Moneda del precio por kilo. La conversión se hace al calcular.';

-- Para revertir:
--   ALTER TABLE operaciones DROP COLUMN moneda;
--   ALTER TABLE produccion_ventas DROP COLUMN moneda;
