<?php
/* includes/carga_lotes.php — Cómo se guarda y se borra un gasto.
 *
 * operacion_insertar / operacion_borrar / operacion_costo. Los usa
 * operaciones.php, para la carga de a uno y para "Repartir en varios lotes"
 * (un gasto por lote, todos con el mismo carga_id). Es a propósito una sola
 * versión: si la cuenta del costo o el descuento de stock estuvieran copiados
 * en dos lados, tarde o temprano darían distinto, y el panel mostraría otro
 * número.
 *
 * Hubo una planilla aparte para cargar en varios lotes (carga_lotes.php). Se
 * sacó: hacía lo mismo que "Repartir en varios lotes" del formulario, y tener
 * dos caminos para lo mismo confundía.
 *
 * Las reglas de la cuenta, iguales a las de siempre:
 *   - Insumos: dosis/ha × precio × hectáreas aplicadas del lote.
 *   - Labor sola: precio/ha × hectáreas trabajadas.
 *   - Receta (labor + insumos): la labor y los insumos se cobran sobre las
 *     hectáreas aplicadas. Antes la labor iba siempre sobre la superficie TOTAL
 *     del lote y el campo quedaba bloqueado: no había forma de cargar una
 *     aplicación en parte del lote.
 *   - El stock baja dosis × ha aplicadas, y puede quedar negativo.
 */

/* ─── Un gasto ─────────────────────────────────────────────────────────────── */

/**
 * Si la base ya tiene operaciones.carga_id (migrations/cargas_lotes.sql).
 *
 * El deploy no toca la base: si el código llega antes que la migración, un
 * INSERT con esa columna dejaba a TODOS sin poder registrar un gasto. Sin la
 * columna se guarda igual, sólo que los gastos no quedan agrupados en cargas.
 */
function carga_columna_existe(PDO $pdo): bool
{
    return operaciones_tiene_columna($pdo, 'carga_id');
}

/**
 * Si la tabla operaciones ya tiene una columna que agregó una migración. Lo
 * mismo que carga_id vale para observaciones: el código puede llegar antes
 * que la migración, y un INSERT con una columna que no existe deja a todos
 * sin poder cargar un gasto.
 */
function operaciones_tiene_columna(PDO $pdo, string $columna): bool
{
    static $cache = [];
    if (!isset($cache[$columna])) {
        try {
            // Con quote() y no con "LIKE ?": con prepares nativos, SHOW no acepta
            // parámetros, tiraba excepción y la columna parecía no existir.
            $cache[$columna] = (bool)$pdo->query("SHOW COLUMNS FROM operaciones LIKE " . $pdo->quote($columna))->fetch();
        } catch (Throwable $e) {
            $cache[$columna] = false;
        }
    }
    return $cache[$columna];
}

/** Hectáreas sobre las que se cobra la labor: las aplicadas, también en una receta. */
function operacion_ha_labor(string $tipo, float $sup, float $ha): float
{
    return $ha > 0 ? $ha : $sup;
}

/** Costo de un gasto. $insumos: [['c' => dosis/ha, 'p' => precio], ...]. */
function operacion_costo(string $tipo, float $precio_labor, float $ha_labor, array $insumos, float $ha_insumos): float
{
    $total = in_array($tipo, ['labor', 'receta_labor'], true) ? $precio_labor * $ha_labor : 0.0;
    if (in_array($tipo, ['insumo', 'receta_labor'], true)) {
        foreach ($insumos as $f) $total += (float)$f['c'] * (float)$f['p'] * $ha_insumos;
    }
    return $total;
}

/**
 * Guarda un gasto con sus insumos y descuenta el stock. No abre transacción:
 * la abre quien llama, para que varios lotes queden todos o ninguno.
 *
 * $op: grupo, grupo_desc, tipo (labor|insumo|receta_labor), proveedor, precio,
 *      moneda, cargas, carga_id, observaciones
 * $d:  lote_id, cultivo_id, campania, cultivo, fecha, ha_labor, ha,
 *      insumos [['id' => insumo_id|null, 'nombre' => texto|null, 'c' => dosis, 'p' => precio]]
 */
function operacion_insertar(PDO $pdo, int $usuario_id, array $op, array $d): int
{
    $tipo        = $op['tipo'];
    $con_labor   = $tipo !== 'insumo';
    $con_insumos = $tipo !== 'labor';
    $insumos     = $con_insumos ? $d['insumos'] : [];
    $ha_labor    = $con_labor ? (float)$d['ha_labor'] : 0.0;

    $valores = [
        $usuario_id, $d['lote_id'], $d['cultivo_id'], $op['grupo'], $op['grupo_desc'],
        $tipo === 'insumo' ? 'multi_insumo' : $tipo,
        $op['proveedor'], $ha_labor, $op['precio'],
        operacion_costo($tipo, (float)$op['precio'], $ha_labor, $insumos, (float)$d['ha']),
        $op['moneda'], $d['fecha'], $d['campania'], $d['cultivo'], $op['cargas'],
        $con_insumos ? $d['ha'] : null,
    ];
    // Columnas que dependen de una migración: sólo si la base ya las tiene.
    $extra = [];
    if (carga_columna_existe($pdo)) $extra['carga_id'] = $op['carga_id'];
    if (operaciones_tiene_columna($pdo, 'observaciones')) $extra['observaciones'] = $op['observaciones'] ?? null;
    $valores = array_merge($valores, array_values($extra));
    $pdo->prepare(
        "INSERT INTO operaciones (usuario_id, lote_id, cultivo_id, grupo_gasto, grupo_descripcion,
             tipo_componente, insumo_id, proveedor_servicio, cantidad_ha, precio_unitario, costo_total,
             moneda, fecha, campania_operacion, cultivo_operacion, cargas, hectareas"
        . ($extra ? ', ' . implode(', ', array_keys($extra)) : '') . ")
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . str_repeat(', ?', count($extra)) . ")"
    )->execute($valores);
    $id = (int)$pdo->lastInsertId();
    operacion_guardar_insumos($pdo, $usuario_id, $id, $insumos, (float)$d['ha']);
    return $id;
}

/** Los insumos de un gasto, descontando del stock dosis × ha. */
function operacion_guardar_insumos(PDO $pdo, int $usuario_id, int $op_id, array $insumos, float $ha): void
{
    $ins = $pdo->prepare("INSERT INTO operacion_insumos (operacion_id, insumo_id, nombre_libre, cantidad_ha, precio_unitario) VALUES (?, ?, ?, ?, ?)");
    // Permite stock negativo: no se clampa a 0 ni se desactiva el insumo al llegar a cero.
    $stock = $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual - ? WHERE id = ? AND usuario_id = ?");
    foreach ($insumos as $f) {
        $ins->execute([$op_id, $f['id'], $f['nombre'], $f['c'], $f['p']]);
        if ($f['id']) $stock->execute([$f['c'] * $ha, $f['id'], $usuario_id]);
    }
}

/**
 * Devuelve al stock lo que había descontado un gasto. false si el gasto no es
 * de este usuario: quien llama tiene que cortar ahí.
 */
function operacion_devolver_stock(PDO $pdo, int $usuario_id, int $id): bool
{
    $st = $pdo->prepare("SELECT tipo_componente, insumo_id, cantidad_ha, lote_id, hectareas FROM operaciones WHERE id = ? AND usuario_id = ?");
    $st->execute([$id, $usuario_id]);
    $op = $st->fetch(PDO::FETCH_ASSOC);
    if (!$op) return false;

    // Con las hectáreas guardadas; si el gasto es viejo (NULL), con la superficie del lote.
    $ha = $op['hectareas'];
    if ($ha === null) {
        $st = $pdo->prepare("SELECT superficie FROM lotes WHERE id = ?");
        $st->execute([$op['lote_id']]);
        $ha = $st->fetchColumn() ?: 0;
    }
    $ha = (float)$ha;

    $devolver = $pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?");
    // Formato viejo: un insumo solo, colgado del gasto mismo.
    if ($op['tipo_componente'] === 'insumo' && $op['insumo_id']) {
        $devolver->execute([(float)$op['cantidad_ha'] * $ha, $op['insumo_id'], $usuario_id]);
    }
    $st = $pdo->prepare("SELECT insumo_id, cantidad_ha FROM operacion_insumos WHERE operacion_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
        if ($h['insumo_id']) $devolver->execute([(float)$h['cantidad_ha'] * $ha, $h['insumo_id'], $usuario_id]);
    }
    return true;
}

/** Borra un gasto devolviendo su stock. Los insumos se van en cascada. */
function operacion_borrar(PDO $pdo, int $usuario_id, int $id): bool
{
    if (!operacion_devolver_stock($pdo, $usuario_id, $id)) return false;
    $pdo->prepare("DELETE FROM operaciones WHERE id = ? AND usuario_id = ?")->execute([$id, $usuario_id]);
    return true;
}

/* ─── La receta impresa (api/pdf_receta.php y api/excel_receta.php) ────────── */

/**
 * El título de la receta según el grupo del gasto. Decía "PULVERIZACIÓN" fijo,
 * y una siembra o una fertilización salían impresas como pulverización.
 */
function receta_titulo(array $op): string
{
    $titulos = [
        'siembra' => 'SIEMBRA', 'fertilizacion' => 'FERTILIZACIÓN',
        'pulverizacion' => 'PULVERIZACIÓN', 'cosecha' => 'COSECHA',
    ];
    $g = (string)($op['grupo_gasto'] ?? '');
    if (isset($titulos[$g])) return $titulos[$g];
    $desc = trim((string)($op['grupo_descripcion'] ?? ''));
    return $desc !== '' ? mb_strtoupper($desc, 'UTF-8') : 'LABOR';
}

/**
 * Las cuentas de la receta con lo GUARDADO, para que el papel diga lo mismo que
 * la lista de gastos: insumos sobre las hectáreas aplicadas (`hectareas`) y la
 * labor sobre las de la labor (`cantidad_ha`). Antes las dos se recalculaban
 * con la superficie del lote, y una aplicación en parte del lote salía impresa
 * con otro costo. Gastos viejos sin esos datos: la superficie del lote.
 *
 * $insumos: filas de operacion_insumos (cantidad_ha, precio_unitario).
 */
function receta_numeros(array $op, array $insumos, float $sup_lote): array
{
    $ha_aplic = (isset($op['hectareas']) && $op['hectareas'] !== null && (float)$op['hectareas'] > 0)
        ? (float)$op['hectareas'] : $sup_lote;
    $ha_labor = (float)($op['cantidad_ha'] ?? 0) > 0 ? (float)$op['cantidad_ha'] : $ha_aplic;
    $cargas   = max(1, (int)($op['cargas'] ?? 1));
    $total_ins = 0.0;
    foreach ($insumos as $i) $total_ins += (float)$i['cantidad_ha'] * $ha_aplic * (float)$i['precio_unitario'];
    $labor = (float)($op['precio_unitario'] ?? 0) * $ha_labor;
    $total = $total_ins + $labor;
    return [
        'ha_aplic' => $ha_aplic, 'ha_labor' => $ha_labor, 'cargas' => $cargas,
        'ha_carga' => $ha_aplic / $cargas, 'insumos' => $total_ins, 'labor' => $labor,
        'total' => $total, 'total_ha' => $ha_aplic > 0 ? $total / $ha_aplic : 0.0,
    ];
}

function carga_nuevo_id(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function carga_id_valido(string $id): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1;
}
