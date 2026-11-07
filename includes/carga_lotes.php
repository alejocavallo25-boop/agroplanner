<?php
/* includes/carga_lotes.php — Guardar gastos, y cargarlos en varios lotes de una vez.
 *
 * DOS COSAS VIVEN ACÁ:
 *
 * 1. Cómo se guarda y se borra UN gasto (operacion_insertar / operacion_borrar).
 *    Lo usan operaciones.php (la carga de a uno y su "Repartir en varios lotes")
 *    y carga_lotes.php (la planilla). Es a propósito una sola versión: si la
 *    cuenta del costo o el descuento de stock estuvieran copiados en dos lados,
 *    tarde o temprano darían distinto, y el panel mostraría otro número.
 *
 * 2. La planilla por lotes (carga_lotes.php): una labor con sus insumos en
 *    varios lotes, cada uno con su fecha, sus hectáreas, su cultivo y, si hace
 *    falta, su propia dosis. Se guarda UN GASTO POR LOTE —igual que siempre, así
 *    el panel, los reportes y el chat no se enteran— y todos llevan el mismo
 *    carga_id para poder editarlos, borrarlos o repetirlos juntos.
 *
 * Las reglas de la cuenta, iguales a las de siempre:
 *   - Insumos: dosis/ha × precio × hectáreas aplicadas del lote.
 *   - Labor sola: precio/ha × hectáreas trabajadas.
 *   - Receta (labor + insumos): la labor se cobra sobre la superficie TOTAL del
 *     lote y los insumos sobre las hectáreas aplicadas (ver api/excel_receta.php).
 *   - El stock baja dosis × ha aplicadas, y puede quedar negativo.
 */

const CARGA_GRUPOS = ['siembra', 'cosecha', 'pulverizacion', 'fertilizacion', 'otros'];
const CARGA_TIPOS  = ['labor', 'insumo', 'receta_labor'];

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

/* ─── La planilla: de lo que manda el navegador a lo que se guarda ─────────── */

function carga_num($v): float
{
    // Acepta coma decimal: "0,8" es como lo escribe cualquiera acá.
    if (is_string($v)) $v = str_replace(',', '.', trim($v));
    return is_numeric($v) ? (float)$v : 0.0;
}

function carga_fecha_valida(string $f): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f;
}

/** "25/26 | Soja" → ['25/26', 'Soja']; "Soja" → [null, 'Soja']; "" → [null, null]. */
function carga_partir_cultivo(string $txt): array
{
    $txt = trim($txt);
    if ($txt === '') return [null, null];
    $p = explode(' | ', $txt);
    if (count($p) === 2) return [trim($p[0]) ?: null, trim($p[1]) ?: null];
    return [null, $txt];
}

/**
 * Lo que es igual para todos los lotes: la labor y la lista de insumos con su
 * dosis y precio. Lo usan la planilla y las plantillas.
 *
 * $insumos_propios: [insumo_id => true] de este usuario.
 * Devuelve [comun, errores]. comun.insumos va indexado por la clave que mandó
 * el navegador, que es la que usan las dosis de cada lote.
 */
function carga_normalizar_comun(array $p, array $insumos_propios): array
{
    $err = [];
    $grupo = (string)($p['grupo'] ?? '');
    $tipo  = (string)($p['tipo'] ?? '');
    if (!in_array($grupo, CARGA_GRUPOS, true)) $err[] = 'Elegí qué labor es (siembra, fertilización…).';
    if (!in_array($tipo, CARGA_TIPOS, true))   $err[] = 'Elegí si es labor, insumos o las dos cosas.';

    $comun = [
        'grupo'      => $grupo,
        'grupo_desc' => $grupo === 'otros' ? (mb_substr(trim((string)($p['grupo_desc'] ?? '')), 0, 255) ?: null) : null,
        'tipo'       => $tipo,
        'moneda'     => ($p['moneda'] ?? '') === 'USD' ? 'USD' : 'ARS',
        'proveedor'  => null,
        'precio'     => 0.0,
        'insumos'    => [],
    ];

    if ($tipo === 'labor' || $tipo === 'receta_labor') {
        $comun['proveedor'] = mb_substr(trim((string)($p['proveedor'] ?? '')), 0, 255);
        $comun['precio']    = carga_num($p['precio'] ?? 0);
        if ($comun['precio'] < 0) $err[] = 'El precio de la labor no puede ser negativo.';
        if ($tipo === 'labor' && $comun['precio'] <= 0) $err[] = 'Poné el precio por hectárea de la labor.';
    }

    if ($tipo === 'insumo' || $tipo === 'receta_labor') {
        foreach ((array)($p['insumos'] ?? []) as $k => $i) {
            if (!is_array($i)) continue;
            $id     = (int)($i['insumo_id'] ?? 0) ?: null;
            $nombre = mb_substr(trim((string)($i['nombre_libre'] ?? '')), 0, 255);
            if (!$id && $nombre === '') continue;   // fila vacía
            if ($id && !isset($insumos_propios[$id])) {
                $err[] = 'Uno de los insumos elegidos ya no está en tu stock. Volvé a elegirlo.';
                continue;
            }
            $dosis  = carga_num($i['dosis'] ?? 0);
            $precio = carga_num($i['precio'] ?? 0);
            if ($dosis < 0 || $precio < 0) { $err[] = 'Las dosis y los precios no pueden ser negativos.'; continue; }
            $comun['insumos'][(string)$k] = [
                'id' => $id, 'nombre' => $id ? null : $nombre, 'dosis' => $dosis, 'p' => $precio,
            ];
        }
        if (!$comun['insumos']) $err[] = 'Agregá al menos un insumo.';
    }
    return [$comun, array_values(array_unique($err))];
}

/**
 * Valida la planilla entera y la deja lista para operacion_insertar().
 *
 * $lotes_propios: [lote_id => superficie] de este usuario. Un lote ajeno o sin
 * superficie no se guarda (como en operaciones.php).
 * Devuelve ['op' => ..., 'destinos' => [...], 'errores' => [...]].
 */
function carga_normalizar(array $p, array $lotes_propios, array $insumos_propios): array
{
    [$comun, $err] = carga_normalizar_comun($p, $insumos_propios);

    $fecha_comun = (string)($p['fecha'] ?? '');
    $destinos = [];
    foreach ((array)($p['lotes'] ?? []) as $l) {
        if (!is_array($l)) continue;
        $id = (int)($l['id'] ?? 0);
        if (!isset($lotes_propios[$id]) || isset($destinos[$id])) continue;
        $sup = (float)$lotes_propios[$id];
        if ($sup <= 0) continue;

        $fecha = trim((string)($l['fecha'] ?? '')) ?: $fecha_comun;
        if (!carga_fecha_valida($fecha)) { $err[] = 'Hay un lote sin fecha o con una fecha que no existe.'; continue; }

        // Sin hectáreas, o en cero, es el lote entero: igual que en la carga de a uno.
        $ha = carga_num($l['ha'] ?? 0);
        if ($ha <= 0) $ha = $sup;

        // Cada lote puede tener su dosis; vacía es "la de arriba". Dosis 0 = ese insumo no va en ese lote.
        $insumos = [];
        foreach ($comun['insumos'] as $k => $i) {
            $propia = $l['dosis'][$k] ?? '';
            $dosis  = ($propia === '' || $propia === null) ? $i['dosis'] : carga_num($propia);
            if ($dosis < 0) { $err[] = 'Las dosis no pueden ser negativas.'; continue; }
            if ($dosis == 0) continue;
            $insumos[] = ['id' => $i['id'], 'nombre' => $i['nombre'], 'c' => $dosis, 'p' => $i['p']];
        }
        if ($comun['tipo'] === 'insumo' && !$insumos) {
            $err[] = 'Hay un lote sin ninguna dosis cargada.';
            continue;
        }

        [$campania, $cultivo] = carga_partir_cultivo((string)($l['cultivo'] ?? ''));
        $destinos[$id] = [
            'lote_id' => $id, 'sup' => $sup, 'ha' => $ha,
            'ha_labor' => operacion_ha_labor($comun['tipo'], $sup, $ha),
            'fecha' => $fecha, 'campania' => $campania, 'cultivo' => $cultivo,
            'insumos' => $insumos,
        ];
    }
    if (!$destinos && !$err) $err[] = 'Tildá al menos un lote.';

    $op = $comun;
    unset($op['insumos']);
    $op['cargas'] = null;   // las cargas son de una aplicación en un lote: repartidas no significan nada
    $op['carga_id'] = null;

    return ['op' => $op, 'destinos' => array_values($destinos), 'errores' => array_values(array_unique($err))];
}

/** Costo de cada lote y total, para el resumen que se confirma. */
function carga_costos(array $norm): array
{
    $por_lote = [];
    foreach ($norm['destinos'] as $d) {
        $por_lote[$d['lote_id']] = operacion_costo($norm['op']['tipo'], (float)$norm['op']['precio'],
                                                   $d['ha_labor'], $d['insumos'], $d['ha']);
    }
    return ['por_lote' => $por_lote, 'total' => array_sum($por_lote)];
}

/**
 * Guarda la planilla: un gasto por lote, todos con el mismo carga_id, en una
 * sola transacción. Si $editar trae un carga_id, primero borra los gastos de
 * esa carga (devolviendo su stock) y los reemplaza.
 */
function carga_guardar(PDO $pdo, int $usuario_id, array $norm, ?string $editar = null): string
{
    require_once __DIR__ . '/cultivos.php';
    $pdo->beginTransaction();
    try {
        if ($editar !== null) {
            $st = $pdo->prepare("SELECT id FROM operaciones WHERE usuario_id = ? AND carga_id = ?");
            $st->execute([$usuario_id, $editar]);
            $viejos = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!$viejos) throw new RuntimeException('La carga que se quería editar ya no existe.');
            foreach ($viejos as $id) operacion_borrar($pdo, $usuario_id, (int)$id);
        }
        $op = $norm['op'];
        $op['carga_id'] = $editar ?? carga_nuevo_id();
        foreach ($norm['destinos'] as $d) {
            $d['cultivo_id'] = cultivo_resolve($pdo, $usuario_id, $d['lote_id'], $d['cultivo'], $d['campania']);
            operacion_insertar($pdo, $usuario_id, $op, $d);
        }
        $pdo->commit();
        return $op['carga_id'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ─── De lo guardado a la planilla (editar o repetir) ──────────────────────── */

/**
 * Arma la planilla a partir de gastos ya guardados. Puro: recibe las filas.
 * $ops: filas de operaciones; $hijos: [operacion_id => [filas de operacion_insumos]].
 * Las dosis de un lote van vacías cuando son iguales a la de arriba.
 */
function carga_desde_operaciones(array $ops, array $hijos): array
{
    if (!$ops) return [];
    $primero = $ops[0];
    $tipo = $primero['tipo_componente'] === 'multi_insumo' ? 'insumo' : $primero['tipo_componente'];
    if (!in_array($tipo, CARGA_TIPOS, true)) $tipo = 'insumo';

    /* Insumos: la unión de todos los lotes. La dosis de arriba —la que hereda un
       lote que se agregue al editar— es la que más lotes llevan; si empatan, la
       de más hectáreas. No la del primer lote: los gastos se guardan por orden
       alfabético, y si el primero era justo el que tenía una dosis distinta, esa
       pasaba a ser la de todos. */
    $insumos = [];
    $votos = [];   // clave => [dosis => [lotes, ha]]
    $clave_de = fn($h) => $h['insumo_id'] ? 'i' . (int)$h['insumo_id'] : 'n' . mb_strtolower(trim((string)$h['nombre_libre']));
    foreach ($ops as $o) {
        $ha_op = $o['hectareas'] !== null ? (float)$o['hectareas'] : (float)$o['cantidad_ha'];
        foreach ($hijos[$o['id']] ?? [] as $h) {
            $k = $clave_de($h);
            if (!isset($insumos[$k])) {
                $insumos[$k] = [
                    'insumo_id'    => $h['insumo_id'] ? (int)$h['insumo_id'] : null,
                    'nombre_libre' => $h['insumo_id'] ? '' : (string)$h['nombre_libre'],
                    'dosis'        => (float)$h['cantidad_ha'],
                    'precio'       => (float)$h['precio_unitario'],
                ];
            }
            $v = (string)(float)$h['cantidad_ha'];
            $votos[$k][$v][0] = ($votos[$k][$v][0] ?? 0) + 1;
            $votos[$k][$v][1] = ($votos[$k][$v][1] ?? 0) + $ha_op;
        }
    }
    foreach ($votos as $k => $porDosis) {
        uasort($porDosis, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $insumos[$k]['dosis'] = (float)array_key_first($porDosis);
    }
    $claves = array_keys($insumos);

    $lotes = [];
    foreach ($ops as $o) {
        $dosis = [];
        $propias = [];
        foreach ($hijos[$o['id']] ?? [] as $h) $propias[$clave_de($h)] = (float)$h['cantidad_ha'];
        foreach ($claves as $i => $k) {
            if (!isset($propias[$k]))                       $dosis[$i] = '0';   // ese lote no lo llevó
            elseif ($propias[$k] != $insumos[$k]['dosis'])  $dosis[$i] = (string)$propias[$k];
            else                                            $dosis[$i] = '';
        }
        $ha = $o['hectareas'] !== null ? (float)$o['hectareas'] : (float)$o['cantidad_ha'];
        $camp = (string)($o['campania_operacion'] ?? '');
        $cult = (string)($o['cultivo_operacion'] ?? '');
        $lotes[] = [
            'id'      => (int)$o['lote_id'],
            'fecha'   => (string)$o['fecha'],
            'ha'      => $ha > 0 ? $ha : '',
            'cultivo' => $camp !== '' && $cult !== '' ? "$camp | $cult" : $cult,
            'dosis'   => $dosis,
        ];
    }

    return [
        'grupo'      => $primero['grupo_gasto'],
        'grupo_desc' => (string)($primero['grupo_descripcion'] ?? ''),
        'tipo'       => $tipo,
        'moneda'     => $primero['moneda'] ?? 'ARS',
        'proveedor'  => (string)($primero['proveedor_servicio'] ?? ''),
        'precio'     => (float)$primero['precio_unitario'],
        'fecha'      => (string)$primero['fecha'],
        'insumos'    => array_values($insumos),
        'lotes'      => $lotes,
    ];
}

/** Lee de la base los gastos de una carga (o un gasto suelto) y arma la planilla. */
function carga_leer(PDO $pdo, int $usuario_id, ?string $carga_id, ?int $op_id = null): array
{
    if ($carga_id !== null) {
        $st = $pdo->prepare("SELECT * FROM operaciones WHERE usuario_id = ? AND carga_id = ? ORDER BY id");
        $st->execute([$usuario_id, $carga_id]);
    } else {
        $st = $pdo->prepare("SELECT * FROM operaciones WHERE usuario_id = ? AND id = ?");
        $st->execute([$usuario_id, (int)$op_id]);
    }
    $ops = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$ops) return [];

    $ids = array_column($ops, 'id');
    $st = $pdo->prepare("SELECT * FROM operacion_insumos WHERE operacion_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id");
    $st->execute($ids);
    $hijos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) $hijos[$h['operacion_id']][] = $h;
    return carga_desde_operaciones($ops, $hijos);
}

/* ─── Plantillas ──────────────────────────────────────────────────────────── */

/** Lo que se guarda de una plantilla: la parte común, sin lotes ni fechas. */
function plantilla_datos(array $p, array $insumos_propios, array $nombres_insumos): array
{
    [$c, $err] = carga_normalizar_comun($p, $insumos_propios);
    $insumos = [];
    foreach ($c['insumos'] as $i) {
        $insumos[] = [
            'insumo_id'    => $i['id'],
            // El nombre va también para los del stock: si después se borra ese
            // insumo, la plantilla lo sigue mostrando como texto.
            'nombre_libre' => $i['id'] ? ($nombres_insumos[$i['id']] ?? '') : $i['nombre'],
            'dosis'        => $i['dosis'],
            'precio'       => $i['p'],
        ];
    }
    return [[
        'grupo' => $c['grupo'], 'grupo_desc' => (string)$c['grupo_desc'], 'tipo' => $c['tipo'],
        'moneda' => $c['moneda'], 'proveedor' => (string)$c['proveedor'], 'precio' => $c['precio'],
        'insumos' => $insumos,
    ], $err];
}
