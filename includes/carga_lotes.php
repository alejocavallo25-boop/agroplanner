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
 *   - Receta (labor + insumos): la labor se cobra sobre la superficie TOTAL del
 *     lote y los insumos sobre las hectáreas aplicadas (ver api/excel_receta.php).
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
    static $hay = null;
    if ($hay === null) {
        try {
            $hay = (bool)$pdo->query("SHOW COLUMNS FROM operaciones LIKE 'carga_id'")->fetch();
        } catch (Throwable $e) {
            $hay = false;
        }
    }
    return $hay;
}

/** Hectáreas sobre las que se cobra la labor. */
function operacion_ha_labor(string $tipo, float $sup, float $ha): float
{
    return $tipo === 'receta_labor' ? $sup : $ha;
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
 *      moneda, cargas, carga_id
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
    $con_carga = carga_columna_existe($pdo);
    if ($con_carga) $valores[] = $op['carga_id'];
    $pdo->prepare(
        "INSERT INTO operaciones (usuario_id, lote_id, cultivo_id, grupo_gasto, grupo_descripcion,
             tipo_componente, insumo_id, proveedor_servicio, cantidad_ha, precio_unitario, costo_total,
             moneda, fecha, campania_operacion, cultivo_operacion, cargas, hectareas"
        . ($con_carga ? ', carga_id' : '') . ")
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($con_carga ? ', ?' : '') . ")"
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
