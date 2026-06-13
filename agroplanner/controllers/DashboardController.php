<?php
// controllers/DashboardController.php

class DashboardController {
    private $pdo;
    private $usuario_id;
    private $dolarRef = null;
    private $dolarTablaLista = false;

    // Dólar de referencia cuando el usuario no tiene NINGÚN tipo de cambio cargado.
    // Solo se usa como último recurso para no dividir por cero; en cuanto exista
    // al menos un valor en tambo_dolar_mes se usa ese (o el del mes del pago).
    const DOLAR_FALLBACK = 1000.0;

    public function __construct($pdo, $usuario_id) {
        $this->pdo = $pdo;
        $this->usuario_id = $usuario_id;
    }

    /**
     * Garantiza que la tabla de tipo de cambio exista. Un usuario que solo usa
     * Agricultura podría no haber abierto nunca el módulo Tambo (donde se crea),
     * y el dashboard hace JOIN contra ella para convertir alquileres en ARS.
     */
    private function ensureDolarTabla() {
        if ($this->dolarTablaLista) return;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `tambo_dolar_mes` (
            `id`              INT(11)       NOT NULL AUTO_INCREMENT,
            `usuario_id`      INT(11)       NOT NULL,
            `mes`             VARCHAR(7)    NOT NULL,
            `dolar_mayorista` DECIMAL(12,4) NOT NULL,
            `fuente`          ENUM('api','manual') NOT NULL DEFAULT 'api',
            `creado_en`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `actualizado_en`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_usuario_mes` (`usuario_id`, `mes`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->dolarTablaLista = true;
    }

    /**
     * Tipo de cambio de respaldo: el último dólar mayorista cargado por el usuario.
     * Se usa cuando un pago en ARS no tiene dólar para SU mes exacto, y como base
     * general. Cae a DOLAR_FALLBACK solo si el usuario no cargó ningún dólar.
     */
    private function getDolarReferencia() {
        if ($this->dolarRef !== null) return $this->dolarRef;
        $this->ensureDolarTabla();
        $stmt = $this->pdo->prepare("SELECT dolar_mayorista FROM tambo_dolar_mes WHERE usuario_id = ? ORDER BY mes DESC LIMIT 1");
        $stmt->execute([$this->usuario_id]);
        $val = (float)($stmt->fetchColumn() ?: 0);
        $this->dolarRef = $val > 0 ? $val : self::DOLAR_FALLBACK;
        return $this->dolarRef;
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

    public function getGlobalStats($ciclo_sel) {
        $stats = [
            'ingresos' => 0, 'costos_directos' => 0, 'costos_alquiler' => 0, 
            'hectareas' => 0, 'kg' => 0, 'margen_neto' => 0, 'rendimiento_ha' => 0,
            'costo_por_tn' => 0, 'costo_por_ha' => 0
        ];

        if (!$ciclo_sel) return $stats;

        // Ingresos
        $stmt = $this->pdo->prepare("SELECT SUM(pv.ingreso_total) as total, SUM(pv.kg_cosechados) as kgs FROM produccion_ventas pv LEFT JOIN cultivos c ON pv.cultivo_id = c.id WHERE (pv.campania_vendida = ? OR c.ciclo = ?) AND pv.usuario_id = ?");
        $stmt->execute([$ciclo_sel, $ciclo_sel, $this->usuario_id]);
        $res = $stmt->fetch();
        $stats['ingresos'] = (float)$res['total'];
        $stats['kg'] = (float)$res['kgs'];

        // Costos Directos
        $stmt = $this->pdo->prepare("SELECT SUM(o.costo_total) as total FROM operaciones o LEFT JOIN cultivos c ON o.cultivo_id = c.id WHERE (o.campania_operacion = ? OR c.ciclo = ?) AND o.usuario_id = ?");
        $stmt->execute([$ciclo_sel, $ciclo_sel, $this->usuario_id]);
        $stats['costos_directos'] = (float)$stmt->fetch()['total'];

         // Hectareas
         $stmt = $this->pdo->prepare("
             SELECT DISTINCT l.id, l.superficie
             FROM lotes l
             LEFT JOIN cultivos c ON c.lote_id = l.id
             LEFT JOIN operaciones o ON o.lote_id = l.id
             LEFT JOIN produccion_ventas pv ON pv.lote_id = l.id
             WHERE (l.campania = ? OR c.ciclo = ? OR o.campania_operacion = ? OR pv.campania_vendida = ?)
             AND l.usuario_id = ?
         ");
         $stmt->execute([$ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $this->usuario_id]);
         $lotes_involucrados = $stmt->fetchAll();
         foreach ($lotes_involucrados as $l) {
             $stats['hectareas'] += (float)$l['superficie'];
         }

        // Alquiler — incluye pagos en USD y en ARS (estos se convierten a USD con
        // el dólar del mes del pago; si falta, con el último dólar disponible).
        $dolar_ref = $this->getDolarReferencia();
        $stmt = $this->pdo->prepare("
             SELECT COALESCE(SUM(
                 CASE WHEN a.moneda = 'USD' THEN a.monto_pagado
                      ELSE a.monto_pagado / COALESCE(dm.dolar_mayorista, ?)
                 END
             ), 0) as total
             FROM alquileres a
             LEFT JOIN lotes    l  ON a.lote_id    = l.id
             LEFT JOIN cultivos c  ON a.cultivo_id = c.id
             LEFT JOIN tambo_dolar_mes dm ON dm.usuario_id = a.usuario_id AND dm.mes = DATE_FORMAT(a.fecha_pago, '%Y-%m')
             WHERE a.usuario_id = ? AND (a.campania = ? OR l.campania = ? OR c.ciclo = ?)
         ");
         $stmt->execute([$dolar_ref, $this->usuario_id, $ciclo_sel, $ciclo_sel, $ciclo_sel]);
         $stats['costos_alquiler'] = (float)$stmt->fetch()['total'];

        $stats['margen_neto']    = $stats['ingresos'] - $stats['costos_directos'] - $stats['costos_alquiler'];
        $stats['rendimiento_ha'] = $stats['hectareas'] > 0 ? $stats['kg'] / $stats['hectareas'] : 0;
        $costos_totales = $stats['costos_directos'] + $stats['costos_alquiler'];
        $stats['costo_por_kg']   = $stats['kg'] > 0 ? $costos_totales / $stats['kg'] : 0;
        $stats['costo_por_ha']   = $stats['hectareas'] > 0 ? $costos_totales / $stats['hectareas'] : 0;
        
        $precio_promedio_global = $stats['kg'] > 0 ? $stats['ingresos'] / $stats['kg'] : 0;
        $stats['punto_equilibrio_kg_ha'] = ($precio_promedio_global > 0 && $stats['hectareas'] > 0) ? ($costos_totales / $precio_promedio_global) / $stats['hectareas'] : 0;

        return $stats;
    }

    public function getCultivosData($ciclo_sel) {
        $cultivos_data = [];
        if (!$ciclo_sel) return $cultivos_data;

        $dolar_ref = $this->getDolarReferencia();

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), NULLIF(pv.cultivo_vendido, ''), NULLIF(l.cultivo_actual, ''), 'Sin Especificar') as especie,
                l.id as lote_id, l.nombre as lote_nombre, l.superficie as lote_sup, l.tenencia, l.costo_alquiler_tns_ha
            FROM lotes l
            LEFT JOIN cultivos c ON c.lote_id = l.id AND c.ciclo = ?
            LEFT JOIN operaciones o ON o.lote_id = l.id AND o.campania_operacion = ?
            LEFT JOIN produccion_ventas pv ON pv.lote_id = l.id AND pv.campania_vendida = ?
            WHERE (l.campania = ? OR c.ciclo = ? OR o.campania_operacion = ? OR pv.campania_vendida = ?)
            AND l.usuario_id = ?
        ");
        $stmt->execute([$ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $ciclo_sel, $this->usuario_id]);
        $res_cultivos = $stmt->fetchAll();

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
            $stmtI = $this->pdo->prepare("
                SELECT SUM(pv.ingreso_total) as total, SUM(pv.kg_cosechados) as kgs
                FROM produccion_ventas pv 
                LEFT JOIN cultivos c ON pv.cultivo_id = c.id 
                WHERE pv.lote_id = ? AND (pv.campania_vendida = ? OR c.ciclo = ?)
                AND (COALESCE(NULLIF(c.nombre, ''), NULLIF(pv.cultivo_vendido, ''), 'Sin Especificar') = ? 
                     OR (? = 'Sin Especificar' AND c.nombre IS NULL AND pv.cultivo_vendido IS NULL))
            ");
            $stmtI->execute([$lote_id, $ciclo_sel, $ciclo_sel, $esp, $esp]);
            $resI = $stmtI->fetch();
            $ingreso_lote = (float)$resI['total'];
            $kgs_lote = (float)$resI['kgs'];

            // --- COSTOS (Operaciones) ---
            $stmtC = $this->pdo->prepare("
                SELECT 
                    SUM(CASE 
                        WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') = ?) THEN o.costo_total
                        WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') = '') THEN (o.costo_total / ?)
                        ELSE 0 
                    END) as total,
                    SUM(CASE 
                        WHEN o.tipo_componente = 'labor' THEN 
                            CASE 
                                WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') = ?) THEN o.costo_total
                                WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') = '') THEN (o.costo_total / ?)
                                ELSE 0 
                            END
                        ELSE 0 
                    END) as labores,
                    SUM(CASE 
                        WHEN o.tipo_componente = 'insumo' THEN 
                            CASE 
                                WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') = ?) THEN o.costo_total
                                WHEN (COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), '') = '') THEN (o.costo_total / ?)
                                ELSE 0 
                            END
                        ELSE 0 
                    END) as insumos
                FROM operaciones o 
                LEFT JOIN cultivos c ON o.cultivo_id = c.id 
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
                    SUM(CASE
                        WHEN a.nivel_imputacion = 'lote' THEN ((CASE WHEN a.moneda = 'USD' THEN a.monto_pagado ELSE a.monto_pagado / COALESCE(dm.dolar_mayorista, ?) END) / ?)
                        WHEN a.nivel_imputacion = 'cultivo' AND COALESCE(NULLIF(c.nombre, ''), '') = ? THEN (CASE WHEN a.moneda = 'USD' THEN a.monto_pagado ELSE a.monto_pagado / COALESCE(dm.dolar_mayorista, ?) END)
                        ELSE 0
                    END) as total
                FROM alquileres a
                LEFT JOIN cultivos c ON a.cultivo_id = c.id
                LEFT JOIN tambo_dolar_mes dm ON dm.usuario_id = a.usuario_id AND dm.mes = DATE_FORMAT(a.fecha_pago, '%Y-%m')
                WHERE a.usuario_id = ?
                AND (a.lote_id = ? OR c.lote_id = ?)
                AND (a.campania = ? OR c.ciclo = ?)
            ");
            $stmtA->execute([$dolar_ref, $divisor, $esp, $dolar_ref, $this->usuario_id, $lote_id, $lote_id, $ciclo_sel, $ciclo_sel]);
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
