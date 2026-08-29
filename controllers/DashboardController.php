<?php
// controllers/DashboardController.php

require_once __DIR__ . '/../includes/dolar.php';

class DashboardController {
    private $pdo;
    private $usuario_id;
    private $dolarInfo = null;

    /* La moneda en que se DEVUELVEN los totales. No es la de los datos: cada
       movimiento guarda la suya y se convierte con la cotización de su propio mes.
       Se guarda en el controlador porque quien lo usa —el panel, el chat, los
       reportes— lo decide una vez por pedido y después hace muchas consultas.
       Pasarla en cada llamada obligaba a tocar diecisiete lugares para cambiar una
       cosa que es la misma en todos. */
    private $moneda = 'ARS';

    public function __construct($pdo, $usuario_id) {
        $this->pdo = $pdo;
        $this->usuario_id = $usuario_id;
    }

    /** Fija la moneda de presentación para todo lo que se pida después. */
    public function setMoneda(string $moneda): void {
        $this->moneda = ($moneda === 'USD') ? 'USD' : 'ARS';
    }

    public function getMoneda(): string {
        return $this->moneda;
    }

    /**
     * Tipo de cambio de referencia y qué tan confiable es.
     *
     * Se usa para convertir a USD los alquileres pagados en pesos cuando el mes
     * del pago no tiene cotización propia. Lo importante es el campo `estimado`:
     * dice que el usuario no cargó NINGUNA cotización y que el número es el valor
     * fijo del código. Antes eso se resolvía en silencio con un 1000 hardcodeado,
     * y con el mayorista cerca de 1500 el costo de alquiler salía 50% inflado sin
     * que nada lo indicara. Ahora la pantalla lo puede decir.
     *
     * @return array{valor:float, fuente:string, estimado:bool, mes:?string}
     */
    public function getDolarInfo(): array {
        if ($this->dolarInfo === null) {
            dolar_asegurar_tabla($this->pdo);
            $this->dolarInfo = dolar_referencia($this->pdo, $this->usuario_id);
        }
        return $this->dolarInfo;
    }

    public function getCiclos() {
        $stmt = $this->pdo->prepare("
            SELECT ciclo FROM (
                SELECT campania as ciclo FROM lotes WHERE usuario_id = ? AND campania IS NOT NULL AND campania != ''
                UNION
                SELECT campania_operacion as ciclo FROM operaciones WHERE usuario_id = ? AND campania_operacion IS NOT NULL AND campania_operacion != ''
                UNION
                SELECT campania_vendida as ciclo FROM produccion_ventas WHERE usuario_id = ? AND campania_vendida IS NOT NULL AND campania_vendida != ''
                UNION
                SELECT ciclo FROM cultivos WHERE usuario_id = ? AND ciclo IS NOT NULL AND ciclo != ''
            ) as ciclos_temp
            ORDER BY ciclo DESC
        ");
        $stmt->execute([$this->usuario_id, $this->usuario_id, $this->usuario_id, $this->usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Lotes que participan en la campaña dada (para el selector del panel).
     * Devuelve [['id'=>.., 'nombre'=>..], ...] ordenados por nombre.
     */
    public function getLotesDelCiclo($ciclo_sel) {
        if (!$ciclo_sel) return [];
        /* La superficie viaja con el lote porque hace falta para repartir un gasto
           entre varios (el chat lo prorratea por hectárea). Es aditivo: sale del
           mismo registro que el id, así que el DISTINCT no cambia de resultado, y
           quien sólo usaba id y nombre los sigue teniendo. */
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT l.id, l.nombre, l.superficie
            FROM lotes l
            LEFT JOIN cultivos c ON c.lote_id = l.id
            LEFT JOIN operaciones o ON o.lote_id = l.id
            LEFT JOIN produccion_ventas pv ON pv.lote_id = l.id
            WHERE (l.campania = ? OR c.ciclo = ? OR o.campania_operacion = ? OR pv.campania_vendida = ?)
              AND l.usuario_id = ?
            ORDER BY l.nombre
        ");
        $stmt->execute([$ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $this->usuario_id]);
        return $stmt->fetchAll();
    }

    /**
     * Especies/cultivos presentes en la campaña dada (para el selector del panel).
     * Usa la misma derivación COALESCE que getCultivosData para que las etiquetas coincidan.
     */
    public function getCultivosDelCiclo($ciclo_sel) {
        if (!$ciclo_sel) return [];
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), NULLIF(pv.cultivo_vendido, ''), NULLIF(l.cultivo_actual, ''), 'Sin Especificar') as especie
            FROM lotes l
            LEFT JOIN cultivos c ON c.lote_id = l.id AND c.ciclo = ?
            LEFT JOIN operaciones o ON o.lote_id = l.id AND o.campania_operacion = ?
            LEFT JOIN produccion_ventas pv ON pv.lote_id = l.id AND pv.campania_vendida = ?
            WHERE (l.campania = ? OR c.ciclo = ? OR o.campania_operacion = ? OR pv.campania_vendida = ?)
              AND l.usuario_id = ?
            ORDER BY especie
        ");
        $stmt->execute([$ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $this->usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param array|null $rango  ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     *
     * El rango de fechas se agregó para que el motor de consultas pueda pedir
     * CUALQUIER métrica de un período ("¿cuánto vendí en agosto?"), y no sólo el
     * gasto. Antes eso vivía como una consulta aparte dentro del motor, que es
     * como se terminan teniendo dos cálculos distintos para lo mismo.
     *
     * Es aditivo: con $rango en null se comporta exactamente como antes, así que
     * el panel, el Excel y el PDF no se enteran.
     *
     * Campaña y rango se combinan: si vienen las dos, se intersectan; si viene
     * sólo el rango, se mira por fecha sin importar la campaña —que es lo
     * correcto, porque una campaña cruza dos años calendario.
     */
    public function getGlobalStats($ciclo_sel, $lote_sel = null, $cultivo_sel = null, $rango = null, $moneda = null) {
        // Sin moneda explícita se usa la del controlador, fijada con setMoneda().
        $moneda = $moneda ?? $this->moneda;
        /* $moneda es la moneda en que se DEVUELVE todo, no la que se guardó. Cada
           fila trae la suya y se convierte con la cotización de su propio mes, así
           que un alquiler de USD 8.500 vale 8.500 dólares mirado en dólares y los
           pesos que costó el mes que se pagó mirado en pesos.

           Antes no se convertía nada: los ingresos y los costos venían crudos en
           pesos y los alquileres en dólares, y el margen los restaba entre sí. Un
           alquiler de USD 8.500 le sacaba al margen ocho mil quinientos pesos. */
        $moneda = ($moneda === 'USD') ? 'USD' : 'ARS';

        $stats = [
            'ingresos' => 0, 'costos_directos' => 0, 'costos_alquiler' => 0,
            'hectareas' => 0, 'kg' => 0, 'margen_neto' => 0, 'rendimiento_ha' => 0,
            'costo_por_kg' => 0, 'costo_por_ha' => 0, 'punto_equilibrio_kg_ha' => 0,
            // Cuántos movimientos hubo que convertir sin la cotización de su mes.
            'alquiler_sin_cotizacion' => 0,
            'sin_cotizacion' => 0,
            'moneda' => $moneda,
        ];

        // Sin campaña Y sin rango no hay nada que acotar: se devuelve en cero.
        if (!$ciclo_sel && !$rango) return $stats;

        // Normalización de filtros opcionales (lote por id, cultivo por especie derivada)
        $lote_sel = ($lote_sel !== null && $lote_sel !== '') ? (int)$lote_sel : null;
        $cultivo_sel = ($cultivo_sel !== null && $cultivo_sel !== '') ? $cultivo_sel : null;
        $desde = $rango['desde'] ?? null;
        $hasta = $rango['hasta'] ?? null;

        // El de respaldo, para los meses sin cotización propia.
        $dolar_ref = $this->getDolarInfo()['valor'];

        // Ingresos
        $conv = dolar_sql_convertir('pv.ingreso_total', 'pv.moneda', $moneda, $dolar_ref, 'dmv');
        $sinc = dolar_sql_sin_cotizacion('pv.moneda', $moneda, 'dmv');
        $sql = "SELECT COALESCE(SUM($conv), 0) as total, COALESCE(SUM(pv.kg_cosechados), 0) as kgs,
                       COALESCE(SUM($sinc), 0) as sin_cotizacion
                  FROM produccion_ventas pv
                  LEFT JOIN cultivos c ON pv.cultivo_id = c.id"
             . dolar_sql_join('pv', 'fecha_venta', 'dmv')
             . " WHERE pv.usuario_id = ?";
        $params = [$this->usuario_id];
        if ($ciclo_sel) { $sql .= " AND (pv.campania_vendida = ? OR c.ciclo = ?)"; $params[] = $ciclo_sel; $params[] = $ciclo_sel; }
        if ($desde) { $sql .= " AND pv.fecha_venta BETWEEN ? AND ?"; $params[] = $desde; $params[] = $hasta; }
        if ($lote_sel !== null) { $sql .= " AND pv.lote_id = ?"; $params[] = $lote_sel; }
        if ($cultivo_sel !== null) { $sql .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(pv.cultivo_vendido, ''), 'Sin Especificar') COLLATE utf8mb4_unicode_ci = ?"; $params[] = $cultivo_sel; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch();
        $stats['ingresos'] = (float)$res['total'];
        $stats['kg'] = (float)$res['kgs'];
        $stats['sin_cotizacion'] += (int)$res['sin_cotizacion'];

        // Costos Directos
        $conv = dolar_sql_convertir('o.costo_total', 'o.moneda', $moneda, $dolar_ref, 'dmo');
        $sinc = dolar_sql_sin_cotizacion('o.moneda', $moneda, 'dmo');
        $sql = "SELECT COALESCE(SUM($conv), 0) as total, COALESCE(SUM($sinc), 0) as sin_cotizacion
                  FROM operaciones o
                  LEFT JOIN cultivos c ON o.cultivo_id = c.id"
             . dolar_sql_join('o', 'fecha', 'dmo')
             . " WHERE o.usuario_id = ?";
        $params = [$this->usuario_id];
        if ($ciclo_sel) { $sql .= " AND (o.campania_operacion = ? OR c.ciclo = ?)"; $params[] = $ciclo_sel; $params[] = $ciclo_sel; }
        if ($desde) { $sql .= " AND o.fecha BETWEEN ? AND ?"; $params[] = $desde; $params[] = $hasta; }
        if ($lote_sel !== null) { $sql .= " AND o.lote_id = ?"; $params[] = $lote_sel; }
        if ($cultivo_sel !== null) { $sql .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar') COLLATE utf8mb4_unicode_ci = ?"; $params[] = $cultivo_sel; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch();
        $stats['costos_directos'] = (float)$res['total'];
        $stats['sin_cotizacion'] += (int)$res['sin_cotizacion'];

         // Hectareas
         //
         // La superficie no tiene fecha: un lote no se achica en agosto. Cuando se
         // pide un período se toman los lotes que tuvieron movimiento en ese lapso,
         // y su superficie entera. Así "costo por hectárea de agosto" significa lo
         // que uno espera: lo gastado ese mes repartido en la superficie trabajada.
         $sql = "
             SELECT DISTINCT l.id, l.superficie
             FROM lotes l
             LEFT JOIN cultivos c ON c.lote_id = l.id
             LEFT JOIN operaciones o ON o.lote_id = l.id
             LEFT JOIN produccion_ventas pv ON pv.lote_id = l.id
             WHERE l.usuario_id = ?";
         $params = [$this->usuario_id];
         if ($ciclo_sel) {
             $sql .= " AND (l.campania = ? OR c.ciclo = ? OR o.campania_operacion = ? OR pv.campania_vendida = ?)";
             array_push($params, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel);
         }
         if ($desde) {
             $sql .= " AND (o.fecha BETWEEN ? AND ? OR pv.fecha_venta BETWEEN ? AND ?)";
             array_push($params, $desde, $hasta, $desde, $hasta);
         }
         if ($lote_sel !== null) { $sql .= " AND l.id = ?"; $params[] = $lote_sel; }
         if ($cultivo_sel !== null) {
             $sql .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), NULLIF(pv.cultivo_vendido, ''), NULLIF(l.cultivo_actual, ''), 'Sin Especificar') = ?";
             $params[] = $cultivo_sel;
         }
         $stmt = $this->pdo->prepare($sql);
         $stmt->execute($params);
         $lotes_involucrados = $stmt->fetchAll();
         foreach ($lotes_involucrados as $l) {
             $stats['hectareas'] += (float)$l['superficie'];
         }

        /* Alquiler — se convierte a la misma moneda que todo lo demás, con el dólar
           del mes del pago; si ese mes no tiene cotización, con el de referencia.
           Antes esta consulta devolvía SIEMPRE dólares aunque los otros dos totales
           vinieran en pesos, y el margen los restaba igual.

           Se cuenta cuántos pagos hubo que convertir sin la cotización de su propio
           mes. Ese número es lo que le permite a la pantalla decir "este margen es
           aproximado y por esto", en vez de mostrar una conversión inventada con la
           misma cara que una exacta. */
        $conv = dolar_sql_convertir('a.monto_pagado', 'a.moneda', $moneda, $dolar_ref, 'dm');
        $sinc = dolar_sql_sin_cotizacion('a.moneda', $moneda, 'dm');
        $sql = "
             SELECT COALESCE(SUM($conv), 0) as total,
                    COALESCE(SUM($sinc), 0) as pagos_sin_cotizacion
             FROM alquileres a
             LEFT JOIN lotes    l  ON a.lote_id    = l.id
             LEFT JOIN cultivos c  ON a.cultivo_id = c.id"
             . dolar_sql_join('a', 'fecha_pago', 'dm')
             . " WHERE a.usuario_id = ?";
         $params = [$this->usuario_id];
         if ($ciclo_sel) {
             $sql .= " AND (a.campania = ? OR l.campania = ? OR c.ciclo = ?)";
             array_push($params, $ciclo_sel, $ciclo_sel, $ciclo_sel);
         }
         if ($desde) { $sql .= " AND a.fecha_pago BETWEEN ? AND ?"; array_push($params, $desde, $hasta); }
         if ($lote_sel !== null) { $sql .= " AND (a.lote_id = ? OR c.lote_id = ?)"; $params[] = $lote_sel; $params[] = $lote_sel; }
         if ($cultivo_sel !== null) { $sql .= " AND COALESCE(NULLIF(c.nombre, ''), 'Sin Especificar') = ?"; $params[] = $cultivo_sel; }
         $stmt = $this->pdo->prepare($sql);
         $stmt->execute($params);
         $alq = $stmt->fetch();
         $stats['costos_alquiler']            = (float)$alq['total'];
         $stats['alquiler_sin_cotizacion']    = (int)$alq['pagos_sin_cotizacion'];
         $stats['sin_cotizacion']            += (int)$alq['pagos_sin_cotizacion'];

        /* Ahora los tres términos están en la misma moneda, así que la resta
           significa algo. Antes no: los dos primeros venían en pesos y el tercero
           en dólares. */
        $stats['margen_neto']    = $stats['ingresos'] - $stats['costos_directos'] - $stats['costos_alquiler'];
        $stats['rendimiento_ha'] = $stats['hectareas'] > 0 ? $stats['kg'] / $stats['hectareas'] : 0;
        $costos_totales = $stats['costos_directos'] + $stats['costos_alquiler'];
        $stats['costo_por_kg']   = $stats['kg'] > 0 ? $costos_totales / $stats['kg'] : 0;
        $stats['costo_por_ha']   = $stats['hectareas'] > 0 ? $costos_totales / $stats['hectareas'] : 0;
        
        $precio_promedio_global = $stats['kg'] > 0 ? $stats['ingresos'] / $stats['kg'] : 0;
        $stats['punto_equilibrio_kg_ha'] = ($precio_promedio_global > 0 && $stats['hectareas'] > 0) ? ($costos_totales / $precio_promedio_global) / $stats['hectareas'] : 0;

        return $stats;
    }

    public function getCultivosData($ciclo_sel, $lote_sel = null, $cultivo_sel = null, $moneda = null) {
        $moneda = $moneda ?? $this->moneda;
        $cultivos_data = [];
        if (!$ciclo_sel) return $cultivos_data;

        // Normalización de filtros opcionales
        $lote_sel = ($lote_sel !== null && $lote_sel !== '') ? (int)$lote_sel : null;
        $cultivo_sel = ($cultivo_sel !== null && $cultivo_sel !== '') ? $cultivo_sel : null;

        /* Misma moneda que getGlobalStats(). Las tarjetas por lote tienen que
           hablar la misma que los KPI de arriba: media pantalla en pesos y media en
           dólares es el mismo error de antes, sólo que repartido. */
        $moneda = ($moneda === 'USD') ? 'USD' : 'ARS';
        $dolar_ref = $this->getDolarInfo()['valor'];
        $fVenta = dolar_sql_factor('pv.moneda', $moneda, $dolar_ref, 'dmv');
        $fOper  = dolar_sql_factor('o.moneda',  $moneda, $dolar_ref, 'dmo');
        $fAlq   = dolar_sql_factor('a.moneda',  $moneda, $dolar_ref, 'dm');

        $sql = "
            SELECT DISTINCT
                COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), NULLIF(pv.cultivo_vendido, ''), NULLIF(l.cultivo_actual, ''), 'Sin Especificar') as especie,
                l.id as lote_id, l.nombre as lote_nombre, l.superficie as lote_sup, l.tenencia, l.costo_alquiler_tns_ha
            FROM lotes l
            LEFT JOIN cultivos c ON c.lote_id = l.id AND c.ciclo = ?
            LEFT JOIN operaciones o ON o.lote_id = l.id AND o.campania_operacion = ?
            LEFT JOIN produccion_ventas pv ON pv.lote_id = l.id AND pv.campania_vendida = ?
            WHERE (l.campania = ? OR c.ciclo = ? OR o.campania_operacion = ? OR pv.campania_vendida = ?)
            AND l.usuario_id = ?";
        $params = [$ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $this->usuario_id];
        if ($lote_sel !== null) { $sql .= " AND l.id = ?"; $params[] = $lote_sel; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $res_cultivos = $stmt->fetchAll();

        // Filtro por cultivo/especie (se aplica sobre la etiqueta derivada)
        if ($cultivo_sel !== null) {
            $res_cultivos = array_values(array_filter($res_cultivos, function ($rc) use ($cultivo_sel) {
                return $rc['especie'] === $cultivo_sel;
            }));
        }

        // 2. Pre-calcular cantidad de cultivos por lote para dividir costos compartidos
        $cultivos_por_lote = [];
        foreach ($res_cultivos as $rc) {
            $lote_id = $rc['lote_id'];
            if (!isset($cultivos_por_lote[$lote_id])) $cultivos_por_lote[$lote_id] = 0;
            $cultivos_por_lote[$lote_id]++;
        }

        foreach ($res_cultivos as $rc) {
            $esp = $rc['especie'];
            $lote_id = $rc['lote_id'];
            $divisor = max(1, $cultivos_por_lote[$lote_id]);

            if (!isset($cultivos_data[$esp])) {
                $cultivos_data[$esp] = ['lotes' => [], 'total_ingreso' => 0, 'total_costo' => 0, 'total_alq' => 0];
            }
            
            // --- INGRESOS ---
            //
            // El COLLATE explicito va sobre la EXPRESION de columnas, nunca sobre un
            // parametro. Con EMULATE_PREPARES en false, al preparar la consulta el
            // marcador ? todavia no tiene tipo y MySQL lo trata como binario, asi que
            // "? COLLATE utf8mb4_unicode_ci" aborta con:
            //   1253 COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'binary'
            // Del lado del parametro no hace falta: la conexion ya fija la colacion
            // en config/database.php, asi que el literal y el parametro coinciden solos.
            $stmtI = $this->pdo->prepare("
                SELECT SUM(pv.ingreso_total * $fVenta) as total, SUM(pv.kg_cosechados) as kgs
                FROM produccion_ventas pv
                LEFT JOIN cultivos c ON pv.cultivo_id = c.id "
                . dolar_sql_join('pv', 'fecha_venta', 'dmv') . "
                WHERE pv.lote_id = ? AND (pv.campania_vendida = ? OR c.ciclo = ?)
                AND (COALESCE(NULLIF(c.nombre, ''), NULLIF(pv.cultivo_vendido, ''), 'Sin Especificar') COLLATE utf8mb4_unicode_ci = ?
                     OR (? = 'Sin Especificar' AND c.nombre IS NULL AND pv.cultivo_vendido IS NULL))
            ");
            $stmtI->execute([$lote_id, $ciclo_sel, $ciclo_sel, $esp, $esp]);
            $resI = $stmtI->fetch();
            $ingreso_lote = (float)$resI['total'];
            $kgs_lote = (float)$resI['kgs'];

            // --- COSTOS (Operaciones) ---
            /* El factor de moneda multiplica al final de cada término, no adentro
               de los CASE: los CASE reparten el gasto entre cultivos y eso es
               independiente de la moneda. Separadas, cada cosa se lee sola. */
            $stmtC = $this->pdo->prepare("
                SELECT
                    SUM((CASE
                        WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') COLLATE utf8mb4_unicode_ci = ?) THEN o.costo_total
                        WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') COLLATE utf8mb4_unicode_ci = '') THEN (o.costo_total / ?)
                        ELSE 0
                    END) * $fOper) as total,
                    SUM(
                        (CASE
                            WHEN o.tipo_componente = 'labor'        THEN o.costo_total
                            WHEN o.tipo_componente = 'receta_labor' THEN (o.precio_unitario * o.cantidad_ha)
                            ELSE 0
                        END)
                        *
                        (CASE
                            WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') COLLATE utf8mb4_unicode_ci = ?) THEN 1
                            WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') COLLATE utf8mb4_unicode_ci = '') THEN (1.0 / ?)
                            ELSE 0
                        END)
                        * $fOper
                    ) as labores,
                    SUM(
                        (CASE
                            WHEN o.tipo_componente IN ('insumo', 'multi_insumo') THEN o.costo_total
                            WHEN o.tipo_componente = 'receta_labor'              THEN (o.costo_total - (o.precio_unitario * o.cantidad_ha))
                            ELSE 0
                        END)
                        *
                        (CASE
                            WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') COLLATE utf8mb4_unicode_ci = ?) THEN 1
                            WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') COLLATE utf8mb4_unicode_ci = '') THEN (1.0 / ?)
                            ELSE 0
                        END)
                        * $fOper
                    ) as insumos
                FROM operaciones o
                LEFT JOIN cultivos c ON o.cultivo_id = c.id "
                . dolar_sql_join('o', 'fecha', 'dmo') . "
                WHERE o.lote_id = ? AND (o.campania_operacion = ? OR c.ciclo = ?)
            ");
            $stmtC->execute([$esp, $divisor, $esp, $divisor, $esp, $divisor, $lote_id, $ciclo_sel, $ciclo_sel]);
            $costos_lote = $stmtC->fetch();
            $costo_dir = (float)$costos_lote['total'];
            $labores = (float)$costos_lote['labores'];
            $insumos = (float)$costos_lote['insumos'];

            // --- ALQUILERES --- (USD + ARS convertidos al dólar del mes del pago)
            $stmtA = $this->pdo->prepare("
                SELECT
                    SUM((CASE
                        WHEN a.nivel_imputacion = 'lote' THEN (a.monto_pagado / ?)
                        WHEN a.nivel_imputacion = 'cultivo' AND COALESCE(NULLIF(c.nombre, ''), '') = ? THEN a.monto_pagado
                        ELSE 0
                    END) * $fAlq) as total
                FROM alquileres a
                LEFT JOIN cultivos c ON a.cultivo_id = c.id "
                . dolar_sql_join('a', 'fecha_pago', 'dm') . "
                WHERE a.usuario_id = ?
                AND (a.lote_id = ? OR c.lote_id = ?)
                AND (a.campania = ? OR c.ciclo = ?)
            ");
            $stmtA->execute([$divisor, $esp, $this->usuario_id, $lote_id, $lote_id, $ciclo_sel, $ciclo_sel]);
            $alq_lote = (float)$stmtA->fetch()['total'];

            $cultivos_data[$esp]['lotes'][] = [
                'nombre'    => $rc['lote_nombre'],
                'sup'       => $rc['lote_sup'],
                'ingreso'   => $ingreso_lote,
                'kgs'       => $kgs_lote,
                'costo_dir' => $costo_dir,
                'labores'   => $labores,
                'insumos'   => $insumos,
                'alquiler'  => $alq_lote
            ];
            $cultivos_data[$esp]['total_ingreso'] += $ingreso_lote;
            $cultivos_data[$esp]['total_costo']   += $costo_dir;
            $cultivos_data[$esp]['total_alq']     += $alq_lote;
        }
        return $cultivos_data;
    }
}
