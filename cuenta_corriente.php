<?php
/* cuenta_corriente.php — Cuenta corriente con proveedores.
 *
 * Vista general: cuánto se debe en cada moneda, qué está vencido, qué vence y el
 * saldo de cada proveedor. Con ?proveedor=ID, los movimientos de uno con el
 * saldo renglón por renglón. Las cuentas: includes/cuenta_corriente.php.
 */
require_once 'config/auth.php';
require_once 'config/database.php';
require_once 'includes/cuenta_corriente.php';

validate_csrf();
$usuario_id = (int)$_SESSION['usuario_id'];
$hoy = date('Y-m-d');
$page_title = 'Cuenta corriente';
$listas = cc_tablas_listas($pdo);

$errores = [];
$form_previo = null;   // lo que se había cargado, para no perderlo si hay un error

if ($listas && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['action'] ?? '';
    $volver = 'cuenta_corriente.php';

    if ($accion === 'guardar_movimiento') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $datos = $_POST;
            // Proveedor nuevo escrito en el mismo formulario.
            if (($datos['proveedor_id'] ?? '') === 'nuevo') {
                $nombre = trim((string)($datos['proveedor_nuevo'] ?? ''));
                if ($nombre === '') throw new InvalidArgumentException('Escribí el nombre del proveedor nuevo.');
                $datos['proveedor_id'] = cc_proveedor_por_nombre($pdo, $usuario_id, $nombre);
            }
            $propios = array_fill_keys(array_map('intval', array_column(cc_proveedores($pdo, $usuario_id), 'id')), true);
            [$mov, $err] = cc_normalizar($datos, $propios);
            if ($err) throw new InvalidArgumentException(implode(' ', $err));

            if ($id > 0) {
                $st = $pdo->prepare("SELECT 1 FROM cc_movimientos WHERE id = ? AND usuario_id = ?");
                $st->execute([$id, $usuario_id]);
                if (!$st->fetchColumn()) throw new InvalidArgumentException('Ese movimiento ya no existe.');
                cc_actualizar($pdo, $usuario_id, $id, $mov);
            } else {
                cc_insertar($pdo, $usuario_id, $mov);
            }
            $pdo->commit();
            $nombres = ['compra' => 'Compra', 'pago' => 'Pago', 'nota_credito' => 'Nota de crédito'];
            set_flash('success', $nombres[$mov['tipo']] . ($id ? ' actualizada' : ' registrada') . ': ' . cc_plata($mov['monto'], $mov['moneda']) . '.');
            header('Location: cuenta_corriente.php?proveedor=' . $mov['proveedor_id']);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errores[] = $e->getMessage();
            $form_previo = $_POST;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[cuenta_corriente] ' . $e->getMessage());
            $errores[] = 'No se pudo guardar. No quedó nada a medias.';
            $form_previo = $_POST;
        }
    }

    if ($accion === 'borrar_movimiento') {
        $st = $pdo->prepare("SELECT proveedor_id FROM cc_movimientos WHERE id = ? AND usuario_id = ?");
        $st->execute([(int)$_POST['id'], $usuario_id]);
        $prov = $st->fetchColumn();
        if ($prov) {
            $pdo->prepare("DELETE FROM cc_movimientos WHERE id = ? AND usuario_id = ?")->execute([(int)$_POST['id'], $usuario_id]);
            set_flash('success', 'Movimiento borrado.');
            $volver .= '?proveedor=' . (int)$prov;
        }
        header("Location: $volver");
        exit;
    }

    if ($accion === 'guardar_proveedor') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['nombre'] ?? ''))), 0, 150);
        $datos = [
            mb_substr(trim((string)($_POST['cuit'] ?? '')), 0, 20) ?: null,
            mb_substr(trim((string)($_POST['telefono'] ?? '')), 0, 50) ?: null,
            mb_substr(trim((string)($_POST['notas'] ?? '')), 0, 255) ?: null,
        ];
        if ($nombre === '') {
            $errores[] = 'Poné el nombre del proveedor.';
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE proveedores SET nombre = ?, cuit = ?, telefono = ?, notas = ? WHERE id = ? AND usuario_id = ?")
                        ->execute(array_merge([$nombre], $datos, [$id, $usuario_id]));
                } else {
                    $pdo->prepare("INSERT INTO proveedores (usuario_id, nombre, cuit, telefono, notas) VALUES (?, ?, ?, ?, ?)")
                        ->execute(array_merge([$usuario_id, $nombre], $datos));
                    $id = (int)$pdo->lastInsertId();
                }
                set_flash('success', 'Proveedor guardado.');
                header('Location: cuenta_corriente.php?proveedor=' . $id);
                exit;
            } catch (PDOException $e) {
                $errores[] = $e->getCode() === '23000' ? 'Ya tenés un proveedor con ese nombre.' : 'No se pudo guardar el proveedor.';
                if ($e->getCode() !== '23000') error_log('[cuenta_corriente] ' . $e->getMessage());
            }
        }
    }

    if ($accion === 'borrar_proveedor') {
        // Sólo sin movimientos: borrar un proveedor con historia borraría su deuda.
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT COUNT(*) FROM cc_movimientos WHERE proveedor_id = ? AND usuario_id = ?");
        $st->execute([$id, $usuario_id]);
        if ((int)$st->fetchColumn() > 0) {
            set_flash('error', 'Ese proveedor tiene movimientos: borralos primero si de verdad querés eliminarlo.');
            header('Location: cuenta_corriente.php?proveedor=' . $id);
        } else {
            $pdo->prepare("DELETE FROM proveedores WHERE id = ? AND usuario_id = ?")->execute([$id, $usuario_id]);
            set_flash('success', 'Proveedor eliminado.');
            header('Location: cuenta_corriente.php');
        }
        exit;
    }
}

// ─── Datos ──────────────────────────────────────────────────────────────────
$proveedores = $listas ? cc_proveedores($pdo, $usuario_id) : [];
$prov_por_id = array_column($proveedores, null, 'id');
$movs        = $listas ? cc_movimientos($pdo, $usuario_id) : [];
$saldos      = cc_saldos($movs);
$pendientes  = cc_pendientes($movs);
$resumen     = cc_resumen($pendientes, $hoy);

$ver = isset($_GET['proveedor']) ? (int)$_GET['proveedor'] : 0;
$prov_actual = $prov_por_id[$ver] ?? null;
if ($ver && !$prov_actual) { header('Location: cuenta_corriente.php'); exit; }

// Próximo vencimiento de cada proveedor.
$proximo = [];
foreach ($pendientes as $p) {
    if ($p['vencimiento'] && !isset($proximo[$p['proveedor_id']])) $proximo[$p['proveedor_id']] = $p;
}

$etiqueta_estado = [
    'vencida'   => ['Vencida', 'cc-est-vencida'],
    'proxima'   => ['Vence pronto', 'cc-est-proxima'],
    'al_dia'    => ['Al día', 'cc-est-aldia'],
    'sin_fecha' => ['Sin vencimiento', 'cc-est-sinfecha'],
];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fmt_fecha = fn($f) => $f ? date('d/m/Y', strtotime($f)) : '—';
$J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE;

require_once 'includes/header.php';
?>

<?php if (!$listas): ?>
<div class="glass-panel">
    <h3 style="margin-bottom:8px;">Falta actualizar la base</h3>
    <p style="color: var(--text-muted);">La cuenta corriente necesita las tablas nuevas. Corré <code>migrations/esquema_completo.sql</code> en phpMyAdmin y volvé a entrar.</p>
</div>
<?php else: ?>

<?php foreach ($errores as $e): ?>
<div class="cc-error" role="alert"><i class="fas fa-exclamation-circle"></i> <?= $h($e) ?></div>
<?php endforeach; ?>

<?php if (!$prov_actual): /* ════════════ VISTA GENERAL ════════════ */ ?>

<div class="glass-panel cc-panel">
    <div class="cc-cabecera">
        <div>
            <h2 class="cc-h2">Lo que debés</h2>
            <p class="cc-ayuda">Pesos y dólares van por separado: no se suman con un tipo de cambio.</p>
        </div>
        <div class="cc-fila">
            <button type="button" class="btn cc-btn-sec" onclick="ccAbrirProveedor()"><i class="fas fa-user-plus"></i> Proveedor</button>
            <button type="button" class="btn cc-btn-sec" onclick="ccAbrirMovimiento('pago')"><i class="fas fa-hand-holding-dollar"></i> Registrar pago</button>
            <button type="button" class="btn btn-primary" onclick="ccAbrirMovimiento('compra')"><i class="fas fa-plus"></i> Registrar compra</button>
        </div>
    </div>

    <?php if (!$resumen): ?>
        <p class="cc-vacio"><?= $proveedores ? 'No le debés nada a nadie.' : 'Todavía no cargaste ninguna compra. Empezá con "Registrar compra", o anotala al importar una factura en Insumos.' ?></p>
    <?php else: ?>
    <div class="cc-kpis">
        <?php foreach (['USD', 'ARS'] as $mon): if (empty($resumen[$mon])) continue; $r = $resumen[$mon]; ?>
        <div class="cc-kpi">
            <div class="cc-kpi-mon"><?= $mon === 'USD' ? 'En dólares' : 'En pesos' ?></div>
            <div class="cc-kpi-total"><?= cc_plata($r['deuda'], $mon) ?></div>
            <div class="cc-kpi-det">
                <span class="<?= $r['vencido'] > 0 ? 'cc-rojo' : '' ?>">Vencido: <b><?= cc_plata($r['vencido'], $mon) ?></b></span>
                <span>Vence en 30 días: <b><?= cc_plata($r['proximo'], $mon) ?></b></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($pendientes): ?>
<div class="glass-panel cc-panel">
    <h2 class="cc-h2">Facturas pendientes</h2>
    <p class="cc-ayuda">Los pagos se aplican a la factura que vence primero.</p>
    <div class="table-container">
        <table>
            <thead><tr><th>Vence</th><th>Proveedor</th><th>Comprobante</th><th style="text-align:right">Falta pagar</th></tr></thead>
            <tbody>
            <?php foreach ($pendientes as $p): $est = cc_estado($p['vencimiento'], $hoy); ?>
                <tr>
                    <td data-label="Vence">
                        <?= $fmt_fecha($p['vencimiento']) ?><br>
                        <span class="cc-est <?= $etiqueta_estado[$est][1] ?>"><?= $etiqueta_estado[$est][0] ?></span>
                    </td>
                    <td data-label="Proveedor"><a href="cuenta_corriente.php?proveedor=<?= (int)$p['proveedor_id'] ?>" class="cc-link"><?= $h($prov_por_id[$p['proveedor_id']]['nombre'] ?? '?') ?></a></td>
                    <td data-label="Comprobante">
                        <?= $h($p['comprobante'] ?: '—') ?>
                        <?php if ($p['concepto']): ?><br><small class="cc-muted"><?= $h($p['concepto']) ?></small><?php endif; ?>
                        <?php if ($p['forma_pago'] === 'canje'): ?><br><small class="cc-canje"><i class="fas fa-wheat-awn"></i> Canje <?= $h($p['canje_cultivo']) ?><?= $p['canje_toneladas'] ? ' · ' . number_format((float)$p['canje_toneladas'], 2, ',', '.') . ' t' : '' ?></small><?php endif; ?>
                    </td>
                    <td data-label="Falta pagar" style="text-align:right; white-space:nowrap;">
                        <b><?= cc_plata($p['pendiente'], $p['moneda']) ?></b>
                        <?php if ($p['pendiente'] < (float)$p['monto']): ?><br><small class="cc-muted">de <?= cc_plata((float)$p['monto'], $p['moneda']) ?></small><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($proveedores): ?>
<div class="glass-panel cc-panel">
    <div class="cc-cabecera">
        <h2 class="cc-h2">Proveedores</h2>
        <?php if (count($proveedores) > 6): ?><input type="search" id="ccBuscar" placeholder="Buscar proveedor…" aria-label="Buscar proveedor" class="cc-buscar"><?php endif; ?>
    </div>
    <div class="table-container">
        <table id="ccTablaProv">
            <thead><tr><th>Proveedor</th><th style="text-align:right">Saldo US$</th><th style="text-align:right">Saldo $</th><th>Próximo vencimiento</th></tr></thead>
            <tbody>
            <?php foreach ($proveedores as $pv): $s = $saldos[$pv['id']] ?? []; $px = $proximo[$pv['id']] ?? null; ?>
                <tr data-buscar="<?= $h(mb_strtolower($pv['nombre'])) ?>">
                    <td data-label="Proveedor"><a href="cuenta_corriente.php?proveedor=<?= (int)$pv['id'] ?>" class="cc-link"><?= $h($pv['nombre']) ?></a></td>
                    <?php foreach (['USD', 'ARS'] as $mon): $v = $s[$mon] ?? 0; ?>
                    <td data-label="Saldo <?= $mon === 'USD' ? 'US$' : '$' ?>" style="text-align:right; white-space:nowrap;" class="<?= $v < 0 ? 'cc-verde' : '' ?>">
                        <?= abs($v) < 0.005 ? '<span class="cc-muted">—</span>' : cc_plata($v, $mon) . ($v < 0 ? '<br><small>a tu favor</small>' : '') ?>
                    </td>
                    <?php endforeach; ?>
                    <td data-label="Próximo vencimiento">
                        <?php if ($px): $est = cc_estado($px['vencimiento'], $hoy); ?>
                            <?= $fmt_fecha($px['vencimiento']) ?> · <?= cc_plata($px['pendiente'], $px['moneda']) ?>
                            <br><span class="cc-est <?= $etiqueta_estado[$est][1] ?>"><?= $etiqueta_estado[$est][0] ?></span>
                        <?php else: ?><span class="cc-muted">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php else: /* ════════════ UN PROVEEDOR ════════════ */
    $mis_movs = array_values(array_filter($movs, fn($m) => (int)$m['proveedor_id'] === (int)$prov_actual['id']));
    $mis_saldos = $saldos[$prov_actual['id']] ?? [];
    $mis_pend = array_values(array_filter($pendientes, fn($p) => (int)$p['proveedor_id'] === (int)$prov_actual['id']));
    $pend_por_id = array_column($mis_pend, 'pendiente', 'id');
?>

<div class="glass-panel cc-panel">
    <div class="cc-cabecera">
        <div>
            <a href="cuenta_corriente.php" class="cc-volver"><i class="fas fa-arrow-left"></i> Todos los proveedores</a>
            <h2 class="cc-h2" style="margin-top:6px;"><?= $h($prov_actual['nombre']) ?></h2>
            <p class="cc-ayuda">
                <?= $h(implode(' · ', array_filter([$prov_actual['cuit'] ? 'CUIT ' . $prov_actual['cuit'] : null, $prov_actual['telefono'], $prov_actual['notas']]))) ?>
                <button type="button" class="cc-mini-btn" onclick='ccAbrirProveedor(<?= json_encode($prov_actual, $J) ?>)'><i class="fas fa-pen"></i> Datos</button>
            </p>
        </div>
        <div class="cc-fila">
            <button type="button" class="btn cc-btn-sec" onclick="ccAbrirMovimiento('nota_credito', <?= (int)$prov_actual['id'] ?>)"><i class="fas fa-receipt"></i> Nota de crédito</button>
            <button type="button" class="btn cc-btn-sec" onclick="ccAbrirMovimiento('pago', <?= (int)$prov_actual['id'] ?>)"><i class="fas fa-hand-holding-dollar"></i> Registrar pago</button>
            <button type="button" class="btn btn-primary" onclick="ccAbrirMovimiento('compra', <?= (int)$prov_actual['id'] ?>)"><i class="fas fa-plus"></i> Registrar compra</button>
        </div>
    </div>

    <div class="cc-kpis">
        <?php $alguno = false; foreach (['USD', 'ARS'] as $mon): if (!isset($mis_saldos[$mon])) continue; $alguno = true; $v = $mis_saldos[$mon]; ?>
        <div class="cc-kpi">
            <div class="cc-kpi-mon">Saldo en <?= $mon === 'USD' ? 'dólares' : 'pesos' ?></div>
            <div class="cc-kpi-total <?= $v < 0 ? 'cc-verde' : '' ?>"><?= cc_plata(abs($v), $mon) ?></div>
            <div class="cc-kpi-det"><span><?= abs($v) < 0.005 ? 'Cuenta saldada' : ($v > 0 ? 'Le debés' : 'A tu favor') ?></span></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$alguno): ?><p class="cc-vacio">Sin movimientos todavía.</p><?php endif; ?>
    </div>
</div>

<?php foreach (['USD', 'ARS'] as $mon):
    $lista = array_values(array_filter($mis_movs, fn($m) => $m['moneda'] === $mon));
    if (!$lista) continue;
    $corrido = 0.0;
?>
<div class="glass-panel cc-panel">
    <h2 class="cc-h2">Movimientos en <?= $mon === 'USD' ? 'dólares' : 'pesos' ?></h2>
    <div class="table-container">
        <table>
            <thead><tr><th>Fecha</th><th>Movimiento</th><th style="text-align:right">Debe</th><th style="text-align:right">Haber</th><th style="text-align:right">Saldo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $m): $corrido += cc_signo($m['tipo']) * (float)$m['monto']; ?>
                <tr>
                    <td data-label="Fecha"><?= $fmt_fecha($m['fecha']) ?></td>
                    <td data-label="Movimiento">
                        <b><?= ['compra' => 'Compra', 'pago' => 'Pago', 'nota_credito' => 'Nota de crédito'][$m['tipo']] ?></b>
                        <?= $m['comprobante'] ? ' · ' . $h($m['comprobante']) : '' ?>
                        <?php if ($m['concepto']): ?><br><small class="cc-muted"><?= $h($m['concepto']) ?></small><?php endif; ?>
                        <?php if ($m['tipo'] === 'compra'): ?>
                            <br><small class="cc-muted">
                                <?= $m['vencimiento'] ? 'Vence ' . $fmt_fecha($m['vencimiento']) : 'Sin vencimiento' ?>
                                <?= $m['tasa_mensual'] !== null ? ' · tasa ' . number_format((float)$m['tasa_mensual'], 2, ',', '.') . ' % mensual' : '' ?>
                            </small>
                            <?php if ($m['forma_pago'] === 'canje'): ?><br><small class="cc-canje"><i class="fas fa-wheat-awn"></i> Canje <?= $h($m['canje_cultivo']) ?><?= $m['canje_toneladas'] ? ' · ' . number_format((float)$m['canje_toneladas'], 2, ',', '.') . ' t' : '' ?></small><?php endif; ?>
                            <?php if (isset($pend_por_id[$m['id']])): $est = cc_estado($m['vencimiento'], $hoy); ?>
                                <br><span class="cc-est <?= $etiqueta_estado[$est][1] ?>"><?= $etiqueta_estado[$est][0] ?> · falta <?= cc_plata($pend_por_id[$m['id']], $mon) ?></span>
                            <?php else: ?>
                                <br><span class="cc-est cc-est-pagada">Pagada</span>
                            <?php endif; ?>
                        <?php elseif ($m['tipo'] === 'pago'): ?>
                            <br><small class="cc-muted"><?= $h(CC_FORMAS_PAGO[$m['forma_pago']] ?? 'Otro') ?>
                            <?php if ($m['pagado_moneda']): ?> · pagado <?= cc_plata((float)$m['pagado_monto'], $m['pagado_moneda']) ?> a <?= number_format((float)$m['cotizacion'], 2, ',', '.') ?><?php endif; ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-label="Debe" style="text-align:right; white-space:nowrap;"><?= $m['tipo'] === 'compra' ? cc_plata((float)$m['monto'], $mon) : '' ?></td>
                    <td data-label="Haber" style="text-align:right; white-space:nowrap;"><?= $m['tipo'] !== 'compra' ? cc_plata((float)$m['monto'], $mon) : '' ?></td>
                    <td data-label="Saldo" style="text-align:right; white-space:nowrap;" class="<?= $corrido < -0.004 ? 'cc-verde' : '' ?>"><b><?= cc_plata($corrido, $mon) ?></b></td>
                    <td data-label="" style="white-space:nowrap; text-align:right;">
                        <button type="button" class="cc-icono" title="Editar" aria-label="Editar" onclick='ccAbrirMovimiento(null, null, <?= json_encode($m, $J) ?>)'><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Borrar este movimiento? El saldo se recalcula.');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="borrar_movimiento">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="cc-icono cc-rojo" title="Borrar" aria-label="Borrar"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$mis_movs): ?>
<form method="POST" onsubmit="return confirm('¿Eliminar este proveedor?');" style="align-self:flex-start;">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="borrar_proveedor">
    <input type="hidden" name="id" value="<?= (int)$prov_actual['id'] ?>">
    <button type="submit" class="btn cc-btn-sec cc-rojo"><i class="fas fa-trash"></i> Eliminar proveedor</button>
</form>
<?php endif; ?>

<?php endif; /* vistas */ ?>

<!-- ═══ Modal: compra, pago o nota de crédito ═══ -->
<div id="ccModalMov" class="modal-wrapper" style="display:none;">
    <div class="glass-panel modal-panel" style="max-width: 560px;">
        <h3 id="ccMovTitulo" style="margin-bottom: 14px;">Registrar compra</h3>
        <form method="POST" id="ccFormMov" style="display:flex; flex-direction:column; gap:12px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="guardar_movimiento">
            <input type="hidden" name="id" id="ccMovId" value="">
            <input type="hidden" name="tipo" id="ccMovTipo" value="compra">

            <div class="cc-tipos" role="radiogroup" aria-label="Tipo de movimiento">
                <button type="button" data-tipo="compra" onclick="ccTipo('compra')">Compra</button>
                <button type="button" data-tipo="pago" onclick="ccTipo('pago')">Pago</button>
                <button type="button" data-tipo="nota_credito" onclick="ccTipo('nota_credito')">Nota de crédito</button>
            </div>

            <div>
                <label for="ccProv">Proveedor</label>
                <select name="proveedor_id" id="ccProv" required onchange="ccProvCambio()">
                    <option value="">Elegí…</option>
                    <?php foreach ($proveedores as $pv): ?><option value="<?= (int)$pv['id'] ?>"><?= $h($pv['nombre']) ?></option><?php endforeach; ?>
                    <option value="nuevo">+ Proveedor nuevo…</option>
                </select>
                <input type="text" name="proveedor_nuevo" id="ccProvNuevo" maxlength="150" placeholder="Nombre del proveedor nuevo" style="margin-top:8px;" hidden>
            </div>

            <div class="cc-grid2">
                <div><label for="ccFecha">Fecha</label><input type="date" name="fecha" id="ccFecha" required></div>
                <div><label for="ccMoneda">Moneda de la cuenta</label>
                    <select name="moneda" id="ccMoneda" onchange="ccCalcularConvertido()"><option value="USD">US$ dólares</option><option value="ARS">$ pesos</option></select></div>
            </div>

            <div class="cc-grid2">
                <div id="ccMontoWrap"><label for="ccMonto">Importe</label><input type="text" inputmode="decimal" name="monto" id="ccMonto" placeholder="0,00"></div>
                <div><label for="ccComprobante">Comprobante</label><input type="text" name="comprobante" id="ccComprobante" maxlength="60" placeholder="Ej: 0002-00010794"></div>
            </div>

            <div><label for="ccConcepto">Concepto <small class="cc-muted">(opcional)</small></label><input type="text" name="concepto" id="ccConcepto" maxlength="255" placeholder="Ej: herbicidas campaña 26/27"></div>

            <div id="ccSoloCompra" class="cc-bloque">
                <div class="cc-grid2">
                    <div><label for="ccVenc">Vencimiento</label><input type="date" name="vencimiento" id="ccVenc"></div>
                    <div><label for="ccTasa">Tasa mensual % <small class="cc-muted">(opcional)</small></label><input type="text" inputmode="decimal" name="tasa_mensual" id="ccTasa" placeholder="Ej: 0,5"></div>
                </div>
                <div>
                    <label for="ccCondicion">Cómo se paga</label>
                    <select name="condicion" id="ccCondicion" onchange="ccMostrarCanje()"><option value="cuenta_corriente">Cuenta corriente</option><option value="canje">Canje en granos</option></select>
                </div>
                <div class="cc-grid2" id="ccCanje" hidden>
                    <div><label for="ccCanjeCultivo">Grano</label><input type="text" name="canje_cultivo" id="ccCanjeCultivo" maxlength="60" placeholder="Ej: Soja" list="ccGranos">
                        <datalist id="ccGranos"><option value="Soja"><option value="Maíz"><option value="Trigo"><option value="Girasol"><option value="Sorgo"><option value="Cebada"></datalist></div>
                    <div><label for="ccCanjeTn">Toneladas <small class="cc-muted">(si ya se sabe)</small></label><input type="text" inputmode="decimal" name="canje_toneladas" id="ccCanjeTn" placeholder="Ej: 25"></div>
                </div>
            </div>

            <div id="ccSoloPago" class="cc-bloque" hidden>
                <div><label for="ccForma">Forma de pago</label>
                    <select name="forma_pago" id="ccForma">
                        <?php foreach (CC_FORMAS_PAGO as $k => $v): ?><option value="<?= $k ?>"><?= $h($v) ?></option><?php endforeach; ?>
                    </select></div>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="otra_moneda" id="ccOtraMoneda" value="1" onchange="ccMostrarConversion()" style="width:18px; height:18px;">
                    <span id="ccOtraMonedaTxt">Lo pagué en pesos</span>
                </label>
                <div class="cc-grid2" id="ccConv" hidden>
                    <div><label for="ccPagado" id="ccPagadoLbl">Pagué en pesos</label><input type="text" inputmode="decimal" name="pagado_monto" id="ccPagado" placeholder="0,00" oninput="ccCalcularConvertido()"></div>
                    <div><label for="ccCot">Cotización</label><input type="text" inputmode="decimal" name="cotizacion" id="ccCot" placeholder="Ej: 1514,50" oninput="ccCalcularConvertido()"></div>
                </div>
                <small class="cc-muted" id="ccConvRes" hidden></small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:4px;">
                <button type="button" class="btn cc-btn-sec" onclick="ccCerrar('ccModalMov')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Modal: proveedor ═══ -->
<div id="ccModalProv" class="modal-wrapper" style="display:none;">
    <div class="glass-panel modal-panel" style="max-width: 460px;">
        <h3 id="ccProvTitulo" style="margin-bottom: 14px;">Proveedor nuevo</h3>
        <form method="POST" style="display:flex; flex-direction:column; gap:12px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="guardar_proveedor">
            <input type="hidden" name="id" id="ccPvId" value="">
            <div><label for="ccPvNombre">Nombre</label><input type="text" name="nombre" id="ccPvNombre" maxlength="150" required></div>
            <div class="cc-grid2">
                <div><label for="ccPvCuit">CUIT</label><input type="text" name="cuit" id="ccPvCuit" maxlength="20"></div>
                <div><label for="ccPvTel">Teléfono</label><input type="text" name="telefono" id="ccPvTel" maxlength="50"></div>
            </div>
            <div><label for="ccPvNotas">Notas</label><input type="text" name="notas" id="ccPvNotas" maxlength="255" placeholder="Ej: vendedor Franco Rossi"></div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn cc-btn-sec" onclick="ccCerrar('ccModalProv')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<style>
.cc-error { background: rgba(239,68,68,.1); color: #b91c1c; padding: 12px 18px; border-radius: 10px; border: 1px solid rgba(239,68,68,.3); }
.cc-panel { display: flex; flex-direction: column; gap: 14px; }
.cc-cabecera { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.cc-h2 { font-size: 1.15rem; font-weight: 600; margin: 0; }
.cc-ayuda { color: var(--text-muted); font-size: .88rem; margin: 4px 0 0; }
.cc-fila { display: flex; gap: 8px; flex-wrap: wrap; }
.cc-btn-sec { background: var(--n-100); color: var(--text-primary); border: 1px solid var(--border); }
.cc-vacio { color: var(--text-muted); margin: 0; }
.cc-muted { color: var(--text-muted); }
.cc-rojo { color: var(--danger) !important; }
.cc-verde { color: var(--accent); }
.cc-canje { color: var(--se-warning); }
.cc-link { color: var(--accent); font-weight: 600; text-decoration: none; }
.cc-link:hover { text-decoration: underline; }
.cc-volver { color: var(--text-muted); text-decoration: none; font-size: .88rem; }
.cc-volver:hover { color: var(--accent); }
.cc-buscar { max-width: 240px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; }
.cc-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
.cc-kpi { background: var(--n-25); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
.cc-kpi-mon { font-size: .82rem; color: var(--text-muted); font-weight: 600; }
.cc-kpi-total { font-size: 1.6rem; font-weight: 700; margin: 2px 0 6px; font-variant-numeric: tabular-nums; }
.cc-kpi-det { display: flex; flex-direction: column; gap: 2px; font-size: .86rem; color: var(--text-muted); }
.cc-est { display: inline-block; margin-top: 3px; font-size: .74rem; font-weight: 600; padding: 2px 8px; border-radius: 20px; }
.cc-est-vencida  { background: rgba(239,68,68,.12); color: #b91c1c; }
.cc-est-proxima  { background: var(--warning-soft, rgba(245,158,11,.14)); color: var(--se-warning); }
.cc-est-aldia    { background: var(--accent-soft); color: var(--accent); }
.cc-est-sinfecha { background: var(--n-100); color: var(--text-muted); }
.cc-est-pagada   { background: var(--n-100); color: var(--text-muted); }
.cc-mini-btn { background: none; border: none; color: var(--accent); cursor: pointer; font-size: .82rem; padding: 2px 4px; }
.cc-icono { background: none; border: none; color: var(--accent); cursor: pointer; padding: 6px 8px; min-width: 36px; min-height: 36px; }
.cc-tipos { display: flex; gap: 6px; background: var(--n-100); padding: 4px; border-radius: 10px; }
.cc-tipos button { flex: 1; border: none; background: transparent; padding: 8px; border-radius: 7px; font: inherit; font-weight: 600; color: var(--text-muted); cursor: pointer; }
.cc-tipos button.on { background: var(--accent); color: var(--on-accent); }
.cc-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.cc-bloque { display: flex; flex-direction: column; gap: 10px; padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--n-25); }
.cc-bloque[hidden], .cc-grid2[hidden] { display: none; }
#ccFormMov label { font-size: .88rem; }
@media (max-width: 560px) { .cc-grid2 { grid-template-columns: 1fr; } }
</style>

<script>
const CC_HOY = <?= json_encode($hoy) ?>;
const CC_PREVIO = <?= json_encode($form_previo, $J) ?>;

function ccCerrar(id) { document.getElementById(id).style.display = 'none'; document.body.classList.remove('modal-open'); }
function ccMostrar(id) { document.getElementById(id).style.display = 'flex'; document.body.classList.add('modal-open'); }
const ccEl = id => document.getElementById(id);

function ccTipo(t) {
    ccEl('ccMovTipo').value = t;
    document.querySelectorAll('.cc-tipos button').forEach(b => b.classList.toggle('on', b.dataset.tipo === t));
    ccEl('ccSoloCompra').hidden = t !== 'compra';
    ccEl('ccSoloPago').hidden = t !== 'pago';
    const titulos = { compra: 'Registrar compra', pago: 'Registrar pago', nota_credito: 'Nota de crédito' };
    ccEl('ccMovTitulo').textContent = (ccEl('ccMovId').value ? 'Editar: ' : '') + titulos[t];
    ccMostrarConversion();
}
function ccProvCambio() { const n = ccEl('ccProv').value === 'nuevo'; ccEl('ccProvNuevo').hidden = !n; ccEl('ccProvNuevo').required = n; if (n) ccEl('ccProvNuevo').focus(); }
function ccMostrarCanje() { ccEl('ccCanje').hidden = ccEl('ccCondicion').value !== 'canje'; }

function ccNum(v) { v = String(v || '').trim(); if (v.includes(',')) v = v.replace(/\./g, '').replace(',', '.'); else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, ''); const n = parseFloat(v); return isNaN(n) ? 0 : n; }
function ccFmt(n, mon) { return (mon === 'USD' ? 'US$ ' : '$ ') + n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

/* Pago en la otra moneda: el importe que baja la deuda sale de la cuenta, no se tipea. */
function ccMostrarConversion() {
    const otra = ccEl('ccOtraMoneda').checked && ccEl('ccMovTipo').value === 'pago';
    const cuenta = ccEl('ccMoneda').value;
    const pagada = cuenta === 'USD' ? 'pesos' : 'dólares';
    ccEl('ccOtraMonedaTxt').textContent = 'Lo pagué en ' + pagada;
    ccEl('ccPagadoLbl').textContent = 'Pagué en ' + pagada;
    ccEl('ccConv').hidden = !otra;
    ccEl('ccConvRes').hidden = !otra;
    ccEl('ccMontoWrap').style.opacity = otra ? '.5' : '1';
    ccEl('ccMonto').readOnly = otra;
    ccCalcularConvertido();
}
function ccCalcularConvertido() {
    if (!ccEl('ccOtraMoneda').checked || ccEl('ccMovTipo').value !== 'pago') { ccOtraMonedaTextos(); return; }
    const cuenta = ccEl('ccMoneda').value, pagado = ccNum(ccEl('ccPagado').value), cot = ccNum(ccEl('ccCot').value);
    if (pagado > 0 && cot > 0) {
        const monto = cuenta === 'USD' ? pagado / cot : pagado * cot;
        ccEl('ccMonto').value = monto.toFixed(2).replace('.', ',');
        ccEl('ccConvRes').textContent = 'Baja la deuda en ' + ccFmt(monto, cuenta) + '.';
    } else {
        ccEl('ccConvRes').textContent = 'Poné cuánto pagaste y a qué cotización.';
    }
}
function ccOtraMonedaTextos() { const p = ccEl('ccMoneda').value === 'USD' ? 'pesos' : 'dólares'; ccEl('ccOtraMonedaTxt').textContent = 'Lo pagué en ' + p; ccEl('ccPagadoLbl').textContent = 'Pagué en ' + p; }

function ccAbrirMovimiento(tipo, proveedorId, m) {
    ccEl('ccFormMov').reset();
    ccEl('ccMovId').value = m ? m.id : '';
    ccEl('ccFecha').value = CC_HOY;
    if (proveedorId) ccEl('ccProv').value = proveedorId;
    if (m) {
        tipo = m.tipo;
        ccEl('ccProv').value = m.proveedor_id; ccEl('ccFecha').value = m.fecha; ccEl('ccMoneda').value = m.moneda;
        ccEl('ccMonto').value = String(m.monto).replace('.', ','); ccEl('ccComprobante').value = m.comprobante || ''; ccEl('ccConcepto').value = m.concepto || '';
        ccEl('ccVenc').value = m.vencimiento || ''; ccEl('ccTasa').value = m.tasa_mensual !== null ? String(m.tasa_mensual).replace('.', ',') : '';
        ccEl('ccCondicion').value = m.forma_pago === 'canje' && m.tipo === 'compra' ? 'canje' : 'cuenta_corriente';
        ccEl('ccCanjeCultivo').value = m.canje_cultivo || ''; ccEl('ccCanjeTn').value = m.canje_toneladas ? String(m.canje_toneladas).replace('.', ',') : '';
        if (m.tipo === 'pago') ccEl('ccForma').value = m.forma_pago || 'otro';
        ccEl('ccOtraMoneda').checked = !!m.pagado_moneda;
        ccEl('ccPagado').value = m.pagado_monto ? String(m.pagado_monto).replace('.', ',') : '';
        ccEl('ccCot').value = m.cotizacion ? String(m.cotizacion).replace('.', ',') : '';
    }
    ccProvCambio(); ccMostrarCanje(); ccTipo(tipo || 'compra');
    ccMostrar('ccModalMov');
}

function ccAbrirProveedor(p) {
    ccEl('ccProvTitulo').textContent = p ? 'Datos del proveedor' : 'Proveedor nuevo';
    ccEl('ccPvId').value = p ? p.id : ''; ccEl('ccPvNombre').value = p ? p.nombre : '';
    ccEl('ccPvCuit').value = p ? (p.cuit || '') : ''; ccEl('ccPvTel').value = p ? (p.telefono || '') : ''; ccEl('ccPvNotas').value = p ? (p.notas || '') : '';
    ccMostrar('ccModalProv');
}

// Hubo un error al guardar: se vuelve a abrir con lo que se había cargado.
if (CC_PREVIO && CC_PREVIO.action === 'guardar_movimiento') {
    ccAbrirMovimiento(CC_PREVIO.tipo, null, null);
    const p = CC_PREVIO;
    ['proveedor_id:ccProv', 'proveedor_nuevo:ccProvNuevo', 'fecha:ccFecha', 'moneda:ccMoneda', 'monto:ccMonto', 'comprobante:ccComprobante',
     'concepto:ccConcepto', 'vencimiento:ccVenc', 'tasa_mensual:ccTasa', 'condicion:ccCondicion', 'canje_cultivo:ccCanjeCultivo',
     'canje_toneladas:ccCanjeTn', 'forma_pago:ccForma', 'pagado_monto:ccPagado', 'cotizacion:ccCot'].forEach(par => {
        const [k, id] = par.split(':'); if (p[k] !== undefined) ccEl(id).value = p[k];
    });
    ccEl('ccMovId').value = p.id || '';
    ccEl('ccOtraMoneda').checked = !!p.otra_moneda;
    ccProvCambio(); ccMostrarCanje(); ccTipo(p.tipo || 'compra');
}

const buscar = ccEl('ccBuscar');
if (buscar) buscar.addEventListener('input', () => {
    const q = buscar.value.trim().toLowerCase();
    document.querySelectorAll('#ccTablaProv tbody tr').forEach(tr => tr.style.display = !q || tr.dataset.buscar.includes(q) ? '' : 'none');
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { ccCerrar('ccModalMov'); ccCerrar('ccModalProv'); } });
</script>

<?php endif; /* tablas listas */ ?>

<?php require_once 'includes/footer.php'; ?>
