-- ---------------------------------------------------------------------------
-- Que una carga hecha sin señal no se duplique al sincronizar
--
-- El teléfono guarda el gasto mientras no hay conexión y lo manda cuando vuelve.
-- El problema es el caso del medio: el POST llega al servidor, se guarda, y la
-- respuesta se pierde en el camino. El teléfono no tiene forma de distinguir eso
-- de "no llegó nunca", así que reintenta, y el gasto queda cargado dos veces.
--
-- En un cuaderno de costos eso no se nota hasta que el margen sale mal.
--
-- La solución es que cada carga lleve un número propio generado en el teléfono
-- ANTES de mandarla. Si llega dos veces con el mismo número, la segunda no entra.
-- El índice único es la garantía de verdad; el chequeo previo del PHP existe
-- para poder contestar "eso ya estaba" en vez de un error.
--
-- Se guarda por FILA y no por carga porque un gasto repartido en cinco lotes son
-- cinco filas con el mismo número: por eso el lote entra en el índice.
--
-- Las filas que ya existen quedan en NULL, y MySQL no considera iguales dos NULL
-- en un índice único, así que ninguna carga vieja choca con otra.
--
-- REVERSIBLE:
--   ALTER TABLE operaciones DROP INDEX uq_operaciones_idem, DROP COLUMN idempotencia;
-- ---------------------------------------------------------------------------

ALTER TABLE operaciones
    ADD COLUMN idempotencia CHAR(36) NULL DEFAULT NULL
        COMMENT 'Numero propio de la carga, generado en el telefono, para no duplicar al sincronizar',
    ADD UNIQUE KEY uq_operaciones_idem (usuario_id, idempotencia, lote_id);

-- Para confirmar que quedó bien:
--   SHOW COLUMNS FROM operaciones LIKE 'idempotencia';
--   SHOW INDEX FROM operaciones WHERE Key_name = 'uq_operaciones_idem';
