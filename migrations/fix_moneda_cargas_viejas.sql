-- ═══════════════════════════════════════════════════════════════
-- Corrige la moneda de las cargas anteriores al selector ARS/USD
-- ═══════════════════════════════════════════════════════════════
-- QUÉ PASÓ
-- Hasta add_moneda_operaciones_ventas.sql, ni operaciones ni ventas
-- guardaban en qué moneda se había pagado o cobrado. Los formularios,
-- en cambio, rotulaban los precios en dólares:
--
--   produccion.php   "Precio por kg (USD)"   ejemplo: 320.00
--   operaciones.php  "Precio USD"            ejemplo: 6.50
--
-- La etiqueta y el ejemplo se contradecían: 320 es el precio del trigo
-- en PESOS por kilo, 0.175 es el mismo trigo en DÓLARES (USD 175 la
-- tonelada). Así que cada uno cargó según a cuál de los dos le creyó.
-- La migración de la moneda marcó todo como ARS por defecto, que es lo
-- correcto para no reinterpretar nada sin mirar, pero deja mal
-- etiquetadas las filas que se habían cargado en dólares.
--
-- POR QUÉ SE PUEDE DECIDIR SIN ADIVINAR
-- Entre un precio en pesos y el mismo precio en dólares hay dos órdenes
-- de magnitud, y el rango intermedio está vacío:
--
--   grano   en ARS   $200 a $500 el kilo   |   en USD   0,10 a 0,60
--   laboreo en ARS   $50.000+ por hectárea |   en USD   40 a 200
--
-- No existe grano a $10 el kilo ni laboreo a $1.000 la hectárea. Los
-- cortes de abajo caen en ese hueco vacío, así que no separan casos
-- parecidos: separan dos poblaciones que no se tocan. Una carga hecha
-- en pesos siguiendo el ejemplo (320) se queda en ARS, y una hecha en
-- dólares siguiendo la etiqueta (0.175) pasa a USD.
--
-- Los alquileres NO se tocan: esa tabla siempre tuvo su columna moneda
-- y su default ya era USD.
--
-- CÓMO USARLO
-- Correr por partes en phpMyAdmin, en orden. La PARTE 1 sólo mira. La
-- PARTE 2 respalda y corrige. La PARTE 4 deshace todo si hace falta.
-- ═══════════════════════════════════════════════════════════════


-- ───────────────────────────────────────────────────────────────
-- PARTE 1 · Diagnóstico. No modifica nada; correr y leer.
-- ───────────────────────────────────────────────────────────────

-- 1.a Ventas: qué precio por kilo tiene cada entrega y de qué lado del
--     corte cae. Mirá que la columna "veredicto" tenga sentido.
SELECT
    v.usuario_id,
    v.id,
    v.fecha_venta,
    v.cultivo_vendido,
    v.kg_cosechados,
    v.precio_kg,
    v.moneda AS moneda_hoy,
    CASE WHEN v.precio_kg < 10
         THEN 'se cargó en DÓLARES  → pasa a USD'
         ELSE 'se cargó en pesos    → queda en ARS'
    END AS veredicto
FROM produccion_ventas v
WHERE v.moneda = 'ARS'
ORDER BY v.usuario_id, v.precio_kg;

-- 1.b Operaciones: costo por hectárea de cada una.
SELECT
    o.usuario_id,
    o.id,
    o.fecha,
    o.grupo_gasto,
    o.tipo_componente,
    o.hectareas,
    o.costo_total,
    ROUND(o.costo_total / o.hectareas, 2) AS costo_por_ha,
    o.moneda AS moneda_hoy,
    CASE WHEN (o.costo_total / o.hectareas) < 1000
         THEN 'se cargó en DÓLARES  → pasa a USD'
         ELSE 'se cargó en pesos    → queda en ARS'
    END AS veredicto
FROM operaciones o
WHERE o.moneda = 'ARS'
  AND o.hectareas > 0
ORDER BY o.usuario_id, costo_por_ha;

-- 1.c Operaciones que el corte NO puede decidir porque no tienen
--     superficie cargada. Éstas quedan en ARS y hay que mirarlas a
--     mano. Si la lista sale vacía, no hay nada pendiente.
SELECT
    o.usuario_id, o.id, o.fecha, o.grupo_gasto,
    o.tipo_componente, o.hectareas, o.costo_total
FROM operaciones o
WHERE o.moneda = 'ARS'
  AND (o.hectareas IS NULL OR o.hectareas = 0)
ORDER BY o.usuario_id, o.costo_total;

-- 1.d Resumen por usuario: cuántas filas cambiarían.
SELECT 'ventas' AS tabla, usuario_id, COUNT(*) AS filas_a_corregir
FROM produccion_ventas
WHERE moneda = 'ARS' AND precio_kg < 10
GROUP BY usuario_id
UNION ALL
SELECT 'operaciones', usuario_id, COUNT(*)
FROM operaciones
WHERE moneda = 'ARS' AND hectareas > 0 AND (costo_total / hectareas) < 1000
GROUP BY usuario_id;


-- ───────────────────────────────────────────────────────────────
-- PARTE 2 · Respaldo y corrección. Correr después de leer la PARTE 1.
-- ───────────────────────────────────────────────────────────────

-- 2.a Respaldo. Guarda la moneda que tenía cada fila antes de tocarla,
--     que es lo único que este script cambia. Con esto la PARTE 4
--     puede volver atrás exactamente.
DROP TABLE IF EXISTS `_bkp_moneda_ventas`;
CREATE TABLE `_bkp_moneda_ventas` AS
SELECT id, moneda, NOW() AS respaldado_el
FROM produccion_ventas;

DROP TABLE IF EXISTS `_bkp_moneda_operaciones`;
CREATE TABLE `_bkp_moneda_operaciones` AS
SELECT id, moneda, NOW() AS respaldado_el
FROM operaciones;

-- 2.b Ventas cargadas en dólares.
--     No hace falta tocar ingreso_total: es una columna generada
--     (kg_cosechados × precio_kg) y queda en la misma moneda que
--     precio_kg. La conversión la hace el panel al mostrar, con la
--     cotización del mes de cada entrega.
UPDATE produccion_ventas
SET moneda = 'USD'
WHERE moneda = 'ARS'
  AND precio_kg < 10;

-- 2.c Operaciones cargadas en dólares.
UPDATE operaciones
SET moneda = 'USD'
WHERE moneda = 'ARS'
  AND hectareas > 0
  AND (costo_total / hectareas) < 1000;


-- ───────────────────────────────────────────────────────────────
-- PARTE 3 · Verificación. Correr después de la PARTE 2.
-- ───────────────────────────────────────────────────────────────

-- 3.a Cuántas filas quedaron de cada moneda.
SELECT 'ventas' AS tabla, moneda, COUNT(*) AS filas
FROM produccion_ventas GROUP BY moneda
UNION ALL
SELECT 'operaciones', moneda, COUNT(*)
FROM operaciones GROUP BY moneda;

-- 3.b Control de que no quedó nada absurdo del lado de los pesos:
--     estas dos consultas tienen que volver VACÍAS.
SELECT id, precio_kg, 'venta en ARS con precio de dólar' AS problema
FROM produccion_ventas
WHERE moneda = 'ARS' AND precio_kg < 10;

SELECT id, costo_total, hectareas, 'operación en ARS con costo de dólar' AS problema
FROM operaciones
WHERE moneda = 'ARS' AND hectareas > 0 AND (costo_total / hectareas) < 1000;


-- ───────────────────────────────────────────────────────────────
-- PARTE 4 · Vuelta atrás. Sólo si algo salió mal.
-- ───────────────────────────────────────────────────────────────
-- Deja las dos tablas exactamente como estaban antes de la PARTE 2.
-- Está comentado a propósito: descomentar y correr sólo si hace falta.
--
-- UPDATE produccion_ventas v
--   JOIN `_bkp_moneda_ventas` b ON b.id = v.id
--   SET v.moneda = b.moneda;
--
-- UPDATE operaciones o
--   JOIN `_bkp_moneda_operaciones` b ON b.id = o.id
--   SET o.moneda = b.moneda;


-- ───────────────────────────────────────────────────────────────
-- PARTE 5 · Limpieza. Recién cuando el panel se vea bien.
-- ───────────────────────────────────────────────────────────────
-- Borra los respaldos. Después de esto ya no se puede volver atrás
-- con la PARTE 4, así que mirá el panel antes.
--
-- DROP TABLE `_bkp_moneda_ventas`;
-- DROP TABLE `_bkp_moneda_operaciones`;
