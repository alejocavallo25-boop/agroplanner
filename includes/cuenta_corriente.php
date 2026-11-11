<?php
/* includes/cuenta_corriente.php — Cuenta corriente con proveedores.
 *
 * Responde "cuánto le debo a quién, en qué moneda y qué vence". Cada compra
 * suma deuda; cada pago o nota de crédito la baja. El monto se guarda siempre
 * positivo y en la moneda de la cuenta: el signo lo pone el tipo.
 *
 * DOS REGLAS QUE NO SE NEGOCIAN:
 *
 * 1. Pesos y dólares no se mezclan nunca. Un proveedor puede tener una cuenta
 *    en cada moneda y cada una tiene su saldo. Sumarlas con un tipo de cambio
 *    daría un número que cambia solo cada día y que no figura en ningún papel.
 *    Un pago hecho en la otra moneda se convierte UNA vez, al cargarlo, con la
 *    cotización que dice el productor, y se guarda lo que pagó y a cuánto.
 *
 * 2. Los pagos se aplican a la factura más vieja (FIFO, por vencimiento). Así
 *    se sabe qué factura sigue pendiente y cuál está vencida sin pedirle al
 *    productor que impute cada pago a mano, que es lo que nadie hace.
 *
 * La tasa es informativa: se muestra, no se calculan intereses. Un interés
 * calculado acá sería un número que la app inventa y que el proveedor puede
 * liquidar distinto.
 *
 * Tiene que andar sin la migración (migrations/cuenta_corriente.sql):
 * cc_tablas_listas() dice si está, y sin ella la pantalla avisa en vez de
 * romper, y el importador de insumos no ofrece anotar la compra.
 */

const CC_TIPOS = ['compra', 'pago', 'nota_credito'];
const CC_FORMAS_PAGO = [
    'transferencia' => 'Transferencia',
    'efectivo'      => 'Efectivo',
    'cheque'        => 'Cheque',
    'echeq'         => 'eCheq',
    'canje'         => 'Canje en granos',
    'otro'          => 'Otro',
];
const CC_DIAS_AVISO = 15;   // "vence pronto": dentro de estos días

/* ─── Puras ───────────────────────────────────────────────────────────────── */

/** Número escrito a mano: acepta "1.234,56", "1234,56" y "1234.56". */
function cc_num($v): float
{
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    if (strpos($s, ',') !== false) $s = str_replace(['.', ','], ['', '.'], $s);
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

function cc_fecha_valida(?string $f): bool
{
    if ($f === null || $f === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f;
}

/** +1 si el movimiento suma deuda, -1 si la baja. */
function cc_signo(string $tipo): int
{
    return $tipo === 'compra' ? 1 : -1;
}

/** Saldo por proveedor y moneda: [proveedor_id][moneda] => saldo. Positivo = se le debe. */
function cc_saldos(array $movs): array
{
    $s = [];
    foreach ($movs as $m) {
        $p = (int)$m['proveedor_id'];
        $s[$p][$m['moneda']] = round(($s[$p][$m['moneda']] ?? 0) + cc_signo($m['tipo']) * (float)$m['monto'], 2);
    }
    return $s;
}

/**
 * Las compras que todavía tienen algo sin pagar, después de aplicar los pagos
 * y notas de crédito a la más vieja primero (por vencimiento, o por fecha si no
 * tiene). Cada una vuelve con 'pendiente'.
 */
function cc_pendientes(array $movs): array
{
    $grupos = [];
    foreach ($movs as $m) $grupos[(int)$m['proveedor_id'] . '|' . $m['moneda']][] = $m;

    $out = [];
    foreach ($grupos as $lista) {
        $compras = array_values(array_filter($lista, fn($m) => $m['tipo'] === 'compra'));
        usort($compras, fn($a, $b) =>
            [($a['vencimiento'] ?: $a['fecha']), $a['fecha'], (int)$a['id']]
            <=> [($b['vencimiento'] ?: $b['fecha']), $b['fecha'], (int)$b['id']]);
        $haber = 0.0;
        foreach ($lista as $m) if ($m['tipo'] !== 'compra') $haber += (float)$m['monto'];

        foreach ($compras as $c) {
            $aplicado = min($haber, (float)$c['monto']);
            $haber -= $aplicado;
            $pendiente = round((float)$c['monto'] - $aplicado, 2);
            if ($pendiente > 0.004) {
                $c['pendiente'] = $pendiente;
                $out[] = $c;
            }
        }
    }
    usort($out, fn($a, $b) => [($a['vencimiento'] ?: '9999-12-31'), $a['fecha']] <=> [($b['vencimiento'] ?: '9999-12-31'), $b['fecha']]);
    return $out;
}

/** vencida | proxima (dentro de CC_DIAS_AVISO) | al_dia | sin_fecha */
function cc_estado(?string $vencimiento, string $hoy): string
{
    if (!$vencimiento) return 'sin_fecha';
    if ($vencimiento < $hoy) return 'vencida';
    $limite = date('Y-m-d', strtotime($hoy . ' +' . CC_DIAS_AVISO . ' days'));
    return $vencimiento <= $limite ? 'proxima' : 'al_dia';
}

/** Totales por moneda de lo pendiente: deuda, vencido y lo que vence en 30 días. */
function cc_resumen(array $pendientes, string $hoy): array
{
    $r = [];
    $en30 = date('Y-m-d', strtotime($hoy . ' +30 days'));
    foreach ($pendientes as $p) {
        $m = $p['moneda'];
        $r[$m] = $r[$m] ?? ['deuda' => 0.0, 'vencido' => 0.0, 'proximo' => 0.0];
        $r[$m]['deuda'] += $p['pendiente'];
        if ($p['vencimiento'] && $p['vencimiento'] < $hoy) $r[$m]['vencido'] += $p['pendiente'];
        elseif ($p['vencimiento'] && $p['vencimiento'] <= $en30) $r[$m]['proximo'] += $p['pendiente'];
    }
    foreach ($r as &$t) foreach ($t as &$v) $v = round($v, 2);
    return $r;
}

/**
 * Valida un movimiento cargado a mano y lo deja listo para guardar.
 * $proveedores_propios: [proveedor_id => true] de este usuario.
 * Devuelve [movimiento, errores].
 */
function cc_normalizar(array $p, array $proveedores_propios): array
{
    $err = [];
    $tipo = (string)($p['tipo'] ?? '');
    if (!in_array($tipo, CC_TIPOS, true)) $err[] = 'Elegí si es una compra, un pago o una nota de crédito.';

    $prov = (int)($p['proveedor_id'] ?? 0);
    if (!isset($proveedores_propios[$prov])) $err[] = 'Elegí el proveedor.';

    $fecha = trim((string)($p['fecha'] ?? ''));
    if (!cc_fecha_valida($fecha)) $err[] = 'La fecha no es válida.';

    $moneda = ($p['moneda'] ?? '') === 'USD' ? 'USD' : 'ARS';
    $m = [
        'tipo' => $tipo, 'proveedor_id' => $prov, 'fecha' => $fecha, 'moneda' => $moneda,
        'comprobante' => mb_substr(trim((string)($p['comprobante'] ?? '')), 0, 60) ?: null,
        'concepto'    => mb_substr(trim((string)($p['concepto'] ?? '')), 0, 255) ?: null,
        'vencimiento' => null, 'forma_pago' => null,
        'pagado_moneda' => null, 'pagado_monto' => null, 'cotizacion' => null,
        'canje_cultivo' => null, 'canje_toneladas' => null, 'tasa_mensual' => null,
        'monto' => round(cc_num($p['monto'] ?? 0), 2),
    ];

    if ($tipo === 'compra') {
        $venc = trim((string)($p['vencimiento'] ?? ''));
        if ($venc !== '') {
            if (!cc_fecha_valida($venc)) $err[] = 'El vencimiento no es una fecha válida.';
            elseif (cc_fecha_valida($fecha) && $venc < $fecha) $err[] = 'El vencimiento no puede ser anterior a la fecha de la compra.';
            else $m['vencimiento'] = $venc;
        }
        if (($p['condicion'] ?? '') === 'canje') {
            $m['forma_pago'] = 'canje';
            $m['canje_cultivo'] = mb_substr(trim((string)($p['canje_cultivo'] ?? '')), 0, 60) ?: null;
            $t = cc_num($p['canje_toneladas'] ?? 0);
            if ($t < 0) $err[] = 'Las toneladas del canje no pueden ser negativas.';
            $m['canje_toneladas'] = $t > 0 ? round($t, 3) : null;
            if (!$m['canje_cultivo']) $err[] = 'Decí con qué grano es el canje.';
        }
        $tasa = trim((string)($p['tasa_mensual'] ?? ''));
        if ($tasa !== '') {
            $t = cc_num($tasa);
            if ($t < 0 || $t > 30) $err[] = 'La tasa mensual tiene que estar entre 0 y 30 %.';
            else $m['tasa_mensual'] = round($t, 3);
        }
    }

    if ($tipo === 'pago') {
        $forma = (string)($p['forma_pago'] ?? '');
        $m['forma_pago'] = isset(CC_FORMAS_PAGO[$forma]) ? $forma : 'otro';
        /* Pagado en la otra moneda: se convierte una vez, ahora, con la
           cotización que dice el productor, y queda guardado lo que pagó. */
        if (!empty($p['otra_moneda'])) {
            $pagado = round(cc_num($p['pagado_monto'] ?? 0), 2);
            $cot = round(cc_num($p['cotizacion'] ?? 0), 4);
            if ($pagado <= 0 || $cot <= 0) {
                $err[] = 'Para un pago en la otra moneda, poné cuánto pagaste y a qué cotización.';
            } else {
                $m['pagado_moneda'] = $moneda === 'USD' ? 'ARS' : 'USD';
                $m['pagado_monto'] = $pagado;
                $m['cotizacion'] = $cot;
                $m['monto'] = round($moneda === 'USD' ? $pagado / $cot : $pagado * $cot, 2);
            }
        }
    }

    if ($m['monto'] <= 0 && !$err) $err[] = 'Poné el importe.';
    if ($m['monto'] >= 1e11) $err[] = 'El importe es demasiado grande.';

    return [$m, array_values(array_unique($err))];
}

/** "US$ 1.234,56" o "$ 1.234,56". */
function cc_plata(float $n, string $moneda): string
{
    return ($moneda === 'USD' ? 'US$ ' : '$ ') . number_format($n, 2, ',', '.');
}

/* ─── Con la base ─────────────────────────────────────────────────────────── */

function cc_tablas_listas(PDO $pdo): bool
{
    static $listas = null;
    if ($listas === null) {
        try { $pdo->query("SELECT 1 FROM cc_movimientos LIMIT 0"); $pdo->query("SELECT 1 FROM proveedores LIMIT 0"); $listas = true; }
        catch (Throwable $e) { $listas = false; }
    }
    return $listas;
}

function cc_proveedores(PDO $pdo, int $usuario_id): array
{
    $st = $pdo->prepare("SELECT * FROM proveedores WHERE usuario_id = ? ORDER BY nombre");
    $st->execute([$usuario_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cc_movimientos(PDO $pdo, int $usuario_id, ?int $proveedor_id = null): array
{
    $sql = "SELECT * FROM cc_movimientos WHERE usuario_id = ?";
    $par = [$usuario_id];
    if ($proveedor_id !== null) { $sql .= " AND proveedor_id = ?"; $par[] = $proveedor_id; }
    $st = $pdo->prepare($sql . " ORDER BY fecha, id");
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** El proveedor con ese nombre, o uno nuevo. Mismo nombre = mismo proveedor (sin importar mayúsculas). */
function cc_proveedor_por_nombre(PDO $pdo, int $usuario_id, string $nombre): int
{
    $nombre = mb_substr(trim(preg_replace('/\s+/u', ' ', $nombre)), 0, 150);
    $pdo->prepare("INSERT INTO proveedores (usuario_id, nombre) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)")
        ->execute([$usuario_id, $nombre]);
    return (int)$pdo->lastInsertId();
}

const CC_COLUMNAS = ['proveedor_id', 'tipo', 'fecha', 'vencimiento', 'comprobante', 'concepto', 'moneda', 'monto',
                     'forma_pago', 'pagado_moneda', 'pagado_monto', 'cotizacion', 'canje_cultivo', 'canje_toneladas', 'tasa_mensual'];

function cc_insertar(PDO $pdo, int $usuario_id, array $m): int
{
    $cols = CC_COLUMNAS;
    $pdo->prepare("INSERT INTO cc_movimientos (usuario_id, " . implode(', ', $cols) . ")
                   VALUES (?" . str_repeat(', ?', count($cols)) . ")")
        ->execute(array_merge([$usuario_id], array_map(fn($c) => $m[$c], $cols)));
    return (int)$pdo->lastInsertId();
}

function cc_actualizar(PDO $pdo, int $usuario_id, int $id, array $m): void
{
    $cols = CC_COLUMNAS;
    $pdo->prepare("UPDATE cc_movimientos SET " . implode(' = ?, ', $cols) . " = ? WHERE id = ? AND usuario_id = ?")
        ->execute(array_merge(array_map(fn($c) => $m[$c], $cols), [$id, $usuario_id]));
}
