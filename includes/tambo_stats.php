<?php
/**
 * includes/tambo_stats.php
 *
 * Los números del tambo de un mes: la única cuenta.
 *
 * La usan el panel (tambo.php), la comparativa (tambo_comparativa.php) y el chat
 * (includes/motor_tambo.php). Antes el panel y la comparativa hacían cada uno su
 * copia de las mismas consultas, y con un mes sin dólar cargado no daban igual:
 * el panel usaba la última cotización cargada y la comparativa 1000, o salía a
 * buscar la API en medio de la carga de la página. Si el chat hubiera sumado una
 * tercera copia, "¿cuánto gané en agosto?" podía contestar un número que no está
 * en ninguna de las dos pantallas.
 *
 * Está partida en dos a propósito:
 *   - tambo_stats() lee la base.
 *   - tambo_calcular() hace las cuentas sobre lo leído, sin base: es la parte que
 *     tiene pruebas (tests/test_tambo_stats.php).
 *
 * Trampas del esquema que esta función ya resuelve y nadie más debería repetir:
 *   - Todo es mensual: las filas se guardan con fecha = YYYY-MM-01.
 *   - La carne ya viene prorrateada en monto_total (monto_original / 12, o
 *     dividida por períodos en la diferencia de inventario). Se suma monto_total.
 *   - La leche "otra" no cuenta como litros del mes pero sí como ingreso: en el
 *     costo por litro entra como recupero, igual que la carne.
 */

require_once __DIR__ . '/dolar.php';

/**
 * El dólar con el que se cierra un mes.
 *
 * El cargado para ese mes (manual o del cron). Si no hay, el último que tenga el
 * productor, marcado como estimado para que quien lo muestre lo diga. Nunca se
 * consulta la API desde acá: eso lo hace cron/get_dolar.php.
 *
 * @return array{valor:float, fuente:string, estimado:bool, guardado:?float}
 */
function tambo_dolar_del_mes(PDO $pdo, int $uid, string $mes): array
{
    $tc = dolar_del_mes($pdo, $uid, $mes);
    if ($tc && $tc['valor'] > 0) {
        return ['valor' => $tc['valor'], 'fuente' => $tc['fuente'], 'estimado' => false, 'guardado' => $tc['valor']];
    }
    $ref = dolar_referencia($pdo, $uid);
    return ['valor' => $ref['valor'], 'fuente' => 'estimado', 'estimado' => true, 'guardado' => null];
}

/** "2026-08" válido, o null. */
function tambo_mes_valido(string $mes): ?string
{
    return preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $mes) ? $mes : null;
}

/**
 * Lee la base y devuelve todos los números del mes.
 *
 * @param string|null $categoria  Recorta SÓLO los egresos (así filtra la comparativa).
 */
function tambo_stats(PDO $pdo, int $uid, string $mes, ?string $categoria = null): array
{
    $mes   = tambo_mes_valido($mes) ?? date('Y-m');
    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $dolar = tambo_dolar_del_mes($pdo, $uid, $mes);

    // ── Leche ──
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS filas,
            SUM(CASE WHEN destino != 'otra' THEN litros_total ELSE 0 END) AS litros,
            SUM(CASE WHEN destino  = 'otra' THEN litros_total ELSE 0 END) AS litros_otra,
            SUM(CASE WHEN destino != 'otra' THEN litros_total * precio_litro ELSE 0 END) AS ingreso_leche,
            SUM(CASE WHEN destino  = 'otra' THEN litros_total * precio_litro ELSE 0 END) AS ingreso_otra,
            MAX(precio_litro) AS precio
        FROM tambo_produccion
        WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?
    ");
    $st->execute([$uid, $desde, $hasta]);
    $leche = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Carne: venta real y diferencia de inventario, ya prorrateadas ──
    $st = $pdo->prepare("
        SELECT tipo, SUM(monto_total) AS total, COUNT(*) AS filas
        FROM tambo_ventas_carne
        WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?
        GROUP BY tipo
    ");
    $st->execute([$uid, $desde, $hasta]);
    $carneReal = 0.0; $difInv = 0.0; $filasCarne = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $filasCarne += (int)$f['filas'];
        if ($f['tipo'] === 'diferencia_inventario') $difInv += (float)$f['total'];
        else                                         $carneReal += (float)$f['total'];
    }

    // ── Egresos ──
    $sql = "SELECT categoria, subcategoria, moneda, SUM(monto) AS total, COUNT(*) AS items
            FROM tambo_egresos
            WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?";
    $params = [$uid, $desde, $hasta];
    if ($categoria !== null && $categoria !== '') {
        $sql .= " AND categoria = ?";
        $params[] = $categoria;
    }
    $sql .= " GROUP BY categoria, subcategoria, moneda";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $egresos = $st->fetchAll(PDO::FETCH_ASSOC);

    // ── Rodeo y calidad: el último dato cargado hasta el fin del mes ──
    $st = $pdo->prepare("SELECT * FROM tambo_rodeo WHERE usuario_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 1");
    $st->execute([$uid, $hasta]);
    $rodeo = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $st = $pdo->prepare("SELECT * FROM tambo_calidad WHERE usuario_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 1");
    $st->execute([$uid, $hasta]);
    $calidad = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $s = tambo_calcular([
        'litros'          => (float)($leche['litros'] ?? 0),
        'litros_otra'     => (float)($leche['litros_otra'] ?? 0),
        'ingreso_leche'   => (float)($leche['ingreso_leche'] ?? 0),
        'ingreso_otra'    => (float)($leche['ingreso_otra'] ?? 0),
        'precio'          => (float)($leche['precio'] ?? 0),
        'carne_real'      => $carneReal,
        'dif_inventario'  => $difInv,
        'egresos'         => $egresos,
    ], $dolar['valor']);

    return $s + [
        'mes'             => $mes,
        'desde'           => $desde,
        'hasta'           => $hasta,
        'dolar_fuente'    => $dolar['fuente'],
        'dolar_estimado'  => $dolar['estimado'],
        'dolar_guardado'  => $dolar['guardado'],
        'filas_leche'     => (int)($leche['filas'] ?? 0),
        'filas_carne'     => $filasCarne,
        'rodeo' => [
            'fecha'        => $rodeo['fecha'] ?? null,
            'vacas_ordene' => (int)($rodeo['vacas_ordene'] ?? 0),
            'vacas_secas'  => (int)($rodeo['vacas_secas']  ?? 0),
            'vaquillonas'  => (int)($rodeo['vaquillonas']  ?? 0),
            'terneros'     => (int)($rodeo['terneros']     ?? 0),
        ],
        'calidad' => [
            'fecha'       => $calidad['fecha'] ?? null,
            'tenor_graso' => isset($calidad['tenor_graso']) ? (float)$calidad['tenor_graso'] : null,
            'tenor_prot'  => isset($calidad['tenor_prot'])  ? (float)$calidad['tenor_prot']  : null,
            'rcs'         => isset($calidad['rcs'])         ? (int)$calidad['rcs']           : null,
            'ufc'         => isset($calidad['ufc'])         ? (int)$calidad['ufc']           : null,
        ],
    ];
}

/**
 * El último mes con algo cargado (leche, egresos o carne), o null si no hay nada.
 *
 * Es el mes por defecto del chat: el tambo se carga por mes y a mes vencido, así
 * que "el mes actual" casi siempre está vacío y contestar con ceros sería engañoso.
 */
function tambo_ultimo_mes_con_datos(PDO $pdo, int $uid): ?string
{
    $st = $pdo->prepare("
        SELECT MAX(f) FROM (
            SELECT MAX(fecha) AS f FROM tambo_produccion   WHERE usuario_id = ?
            UNION ALL
            SELECT MAX(fecha)      FROM tambo_egresos      WHERE usuario_id = ?
            UNION ALL
            SELECT MAX(fecha)      FROM tambo_ventas_carne WHERE usuario_id = ?
        ) t
    ");
    $st->execute([$uid, $uid, $uid]);
    $f = $st->fetchColumn();
    return $f ? substr((string)$f, 0, 7) : null;
}

/**
 * El año más reciente en que ese mes tiene algo cargado, o null.
 * Para "agosto" a secas: el agosto que el productor tiene cargado, no el del calendario.
 */
function tambo_anio_con_datos(PDO $pdo, int $uid, int $mes): ?int
{
    $st = $pdo->prepare("
        SELECT MAX(a) FROM (
            SELECT MAX(YEAR(fecha)) AS a FROM tambo_produccion WHERE usuario_id = ? AND MONTH(fecha) = ?
            UNION ALL
            SELECT MAX(YEAR(fecha))      FROM tambo_egresos    WHERE usuario_id = ? AND MONTH(fecha) = ?
        ) t
    ");
    $st->execute([$uid, $mes, $uid, $mes]);
    $a = $st->fetchColumn();
    return $a ? (int)$a : null;
}

/** Los conceptos de egreso que el productor cargó alguna vez (texto libre). */
function tambo_conceptos(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare("
        SELECT DISTINCT concepto FROM tambo_egresos
        WHERE usuario_id = ? AND concepto IS NOT NULL AND concepto != ''
    ");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Las categorías que el productor tiene cargadas, por si alguna no está en la estructura. */
function tambo_categorias(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare("SELECT DISTINCT categoria FROM tambo_egresos WHERE usuario_id = ?");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Lo gastado en un concepto en un mes. Pasa por tambo_calcular() para que la
 * conversión de moneda sea exactamente la del resto.
 *
 * @return array{ars:float, usd:float, items:int, donde:array<string,array<string,float>>}
 */
function tambo_gasto_concepto(PDO $pdo, int $uid, string $mes, string $concepto): array
{
    $mes   = tambo_mes_valido($mes) ?? date('Y-m');
    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $st = $pdo->prepare("
        SELECT categoria, subcategoria, moneda, SUM(monto) AS total, COUNT(*) AS items
        FROM tambo_egresos
        WHERE usuario_id = ? AND fecha >= ? AND fecha <= ? AND concepto = ?
        GROUP BY categoria, subcategoria, moneda
    ");
    $st->execute([$uid, $desde, $hasta, $concepto]);
    $c = tambo_calcular(['egresos' => $st->fetchAll(PDO::FETCH_ASSOC)],
                        tambo_dolar_del_mes($pdo, $uid, $mes)['valor']);
    return ['ars' => $c['costos_ars_total'], 'usd' => $c['costos_usd'],
            'items' => $c['egresos_items'], 'donde' => $c['costos_subcat_ars']];
}

/**
 * Las cuentas del mes, sin base.
 *
 * Un solo dólar por mes para todo: por eso el % de margen da lo mismo en pesos
 * que en dólares, y los rankings tienen el mismo orden en las dos monedas.
 */
function tambo_calcular(array $c, float $dolar): array
{
    $div = fn(float $a, float $b): float => $b != 0.0 ? $a / $b : 0.0;
    $usd = fn(float $ars): float => $dolar > 0 ? $ars / $dolar : 0.0;

    $litros       = (float)($c['litros'] ?? 0);
    $leche        = (float)($c['ingreso_leche'] ?? 0);
    $otra         = (float)($c['ingreso_otra'] ?? 0);
    $carneReal    = (float)($c['carne_real'] ?? 0);
    $difInv       = (float)($c['dif_inventario'] ?? 0);
    $carne        = $carneReal + $difInv;
    $ingresos     = $leche + $otra + $carne;

    $costosArs = 0.0; $costosUsd = 0.0; $items = 0; $hayUsd = false;
    $catArs = []; $catUsd = []; $subArs = []; $subUsd = [];
    foreach ($c['egresos'] ?? [] as $e) {
        $total = (float)$e['total'];
        $enUsd = ($e['moneda'] ?? 'ARS') === 'USD';
        $hayUsd = $hayUsd || $enUsd;
        $mArs  = $enUsd ? $total * $dolar : $total;
        $mUsd  = $enUsd ? $total : $usd($total);
        $cat   = trim((string)($e['categoria'] ?? '')) ?: 'Otros';
        $sub   = trim((string)($e['subcategoria'] ?? '')) ?: 'General';

        $costosArs += $mArs;
        $costosUsd += $mUsd;
        $items     += (int)($e['items'] ?? 0);
        $catArs[$cat] = ($catArs[$cat] ?? 0) + $mArs;
        $catUsd[$cat] = ($catUsd[$cat] ?? 0) + $mUsd;
        $subArs[$cat][$sub] = ($subArs[$cat][$sub] ?? 0) + $mArs;
        $subUsd[$cat][$sub] = ($subUsd[$cat][$sub] ?? 0) + $mUsd;
    }
    arsort($catArs);
    arsort($catUsd);

    $margenArs = $ingresos - $costosArs;
    $margenUsd = $usd($ingresos) - $costosUsd;

    // Por litro: el costo bruto menos lo que recuperan la carne y la otra leche.
    $costoFinalArs = $div($costosArs, $litros) - $div($carne, $litros) - $div($otra, $litros);
    $costoFinalUsd = $div($costosUsd, $litros) - $div($usd($carne), $litros) - $div($usd($otra), $litros);
    $precio        = (float)($c['precio'] ?? 0);

    return [
        'dolar'                  => $dolar,
        'litros'                 => $litros,
        'litros_otra'            => (float)($c['litros_otra'] ?? 0),
        'precio_leche_ars'       => $precio,

        'ingreso_leche_ars'      => $leche,
        'ingreso_leche_usd'      => $usd($leche),
        'ingreso_otra_ars'       => $otra,
        'ingreso_otra_usd'       => $usd($otra),
        'ingreso_carne_real_ars' => $carneReal,
        'ingreso_carne_real_usd' => $usd($carneReal),
        'ingreso_dif_inv_ars'    => $difInv,
        'ingreso_dif_inv_usd'    => $usd($difInv),
        'ingreso_carne_ars'      => $carne,
        'ingreso_carne_usd'      => $usd($carne),
        'total_ingresos_ars'     => $ingresos,
        'total_ingresos_usd'     => $usd($ingresos),
        // "Leche" en la composición del ingreso es toda la leche, la otra incluida.
        'pct_leche'              => $div($leche + $otra, $ingresos) * 100,
        'pct_carne'              => $div($carne, $ingresos) * 100,

        'costos_ars_total'       => $costosArs,
        'costos_usd'             => $costosUsd,
        'costos_cat_ars'         => $catArs,
        'costos_cat_usd'         => $catUsd,
        'costos_subcat_ars'      => $subArs,
        'costos_subcat_usd'      => $subUsd,
        'egresos_items'          => $items,
        // Si hay egresos en dólares, el dólar del mes mueve también el total en pesos.
        'egresos_usd'            => $hayUsd,

        'margen_bruto_ars'       => $margenArs,
        'margen_bruto_usd'       => $margenUsd,
        'rentabilidad'           => $div($margenArs, $ingresos) * 100,
        'margen_litro_ars'       => $div($margenArs, $litros),
        'margen_litro_usd'       => $div($margenUsd, $litros),

        'ingreso_leche_litro_ars' => $div($leche, $litros),
        'ingreso_leche_litro_usd' => $div($usd($leche), $litros),
        'costo_bruto_litro_ars'  => $div($costosArs, $litros),
        'costo_bruto_litro_usd'  => $div($costosUsd, $litros),
        'recupero_carne_litro_ars' => $div($carne, $litros),
        'recupero_carne_litro_usd' => $div($usd($carne), $litros),
        'recupero_otra_litro_ars'  => $div($otra, $litros),
        'recupero_otra_litro_usd'  => $div($usd($otra), $litros),
        'costo_final_ars'        => $costoFinalArs,
        'costo_final_usd'        => $costoFinalUsd,
        // Litros que hacen falta, al precio del mes, para cubrir el costo neto de recuperos.
        'rinde_indiferencia'     => $precio > 0 ? ($costoFinalArs * $litros) / $precio : 0.0,

        'hay_datos'              => $litros > 0 || $ingresos != 0.0 || $costosArs != 0.0,
    ];
}
