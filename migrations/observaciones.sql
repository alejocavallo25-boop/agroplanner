-- migrations/observaciones.sql
--
-- Observaciones de un gasto: un texto libre para anotar lo que no entra en
-- los demás campos ("se aplicó con viento", "faltó una carga"). Se guarda y se
-- edita desde operaciones.php y se ve en la lista debajo del detalle.
--
-- Historial, como las otras sueltas: en producción se corre
-- esquema_completo.sql, que ya trae todo esto. El código anda sin la columna
-- (operaciones_tiene_columna en includes/carga_lotes.php).

ALTER TABLE operaciones
    ADD COLUMN IF NOT EXISTS observaciones TEXT NULL DEFAULT NULL
        COMMENT 'Notas libres del productor sobre el gasto'
        AFTER carga_id;

-- Para revertir:
--   ALTER TABLE operaciones DROP COLUMN observaciones;
