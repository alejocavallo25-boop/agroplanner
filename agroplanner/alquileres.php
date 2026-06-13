<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Gestión de Alquileres';

validate_csrf();

// ─── Acciones POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $nivel = $_POST['nivel_imputacion'] ?? 'lote';

    // Normalizar los IDs según el nivel elegido
    $lote_id = null;
    $cultivo_id = null;
    $campania = !empty($_POST['campania']) ? trim($_POST['campania']) : null;

    if ($nivel === 'lote' || $nivel === 'cultivo') {
        $lote_id = !empty($_POST['lote_id']) ? (int) $_POST['lote_id'] : null;
    }
    if ($nivel === 'cultivo') {
        $cultivo_id = !empty($_POST['cultivo_id']) ? (int) $_POST['cultivo_id'] : null;
    }

    if ($_POST['action'] === 'add') {
        $stmt = $pdo->prepare("
            INSERT INTO alquileres
                (usuario_id, lote_id, cultivo_id, nivel_imputacion, campania, fecha_pago, monto_pagado, moneda, notas)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $usuario_id,
            $lote_id,
            $cultivo_id,
            $nivel,
            $campania,
            $_POST['fecha_pago'],
            (float) $_POST['monto_pagado'],
            $_POST['moneda'] ?? 'USD',
            !empty($_POST['notas']) ? trim($_POST['notas']) : null,
        ]);
        set_flash('success', 'Pago de alquiler registrado exitosamente.');
        header("Location: alquileres.php");
        exit;

    } elseif ($_POST['action'] === 'edit') {
        $stmt = $pdo->prepare("
            UPDATE alquileres
            SET lote_id=?, cultivo_id=?, nivel_imputacion=?, campania=?,
                fecha_pago=?, monto_pagado=?, moneda=?, notas=?
            WHERE id=? AND usuario_id=?
        ");
        $stmt->execute([
            $lote_id,
            $cultivo_id,
            $nivel,
            $campania,
            $_POST['fecha_pago'],
            (float) $_POST['monto_pagado'],
            $_POST['moneda'] ?? 'USD',
            !empty($_POST['notas']) ? trim($_POST['notas']) : null,
            (int) $_POST['id'],
            $usuario_id,
        ]);
        set_flash('success', 'Pago de alquiler actualizado exitosamente.');
        header("Location: alquileres.php");
        exit;

    } elseif ($_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM alquileres WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$_POST['id'], $usuario_id]);
        set_flash('success', 'Pago de alquiler eliminado exitosamente.');
        header("Location: alquileres.php");
        exit;
    }
}

// ─── API AJAX: Traer cultivos de un lote ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api']) && $_GET['api'] === 'cultivos_lote') {
    $lote_id_api = (int) ($_GET['lote_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, nombre, ciclo FROM cultivos WHERE lote_id = ? AND usuario_id = ? ORDER BY nombre");
    $stmt->execute([$lote_id_api, $usuario_id]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── Lotes del usuario ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, nombre, superficie, tenencia, costo_alquiler_tns_ha, campania FROM lotes WHERE usuario_id = ? ORDER BY nombre");
$stmt->execute([$usuario_id]);
$lotes = $stmt->fetchAll();
$lotes_json = json_encode($lotes);

// ─── Pizarra soja para calculadora (SIO-Granos + Conversión USD) ──────────
// ─── Pizarra soja para calculadora (Usa el valor guardado de SIO-Granos) ───
$pizarra_soja = 0;
try {
    // 1. Obtener el último precio guardado en la tabla de cotizaciones (el mismo que usa el panel general)
    // Usamos LIKE 'Soja C%' para evitar problemas de encoding con la tilde
    $sio_stmt = $pdo->query("SELECT precio_promedio FROM cotizaciones_siogranos WHERE cultivo LIKE 'Soja C%' ORDER BY fecha DESC LIMIT 1");
    $sio_row = $sio_stmt->fetch();
    $sio_soja_ars = (float) ($sio_row['precio_promedio'] ?? 0);

    if ($sio_soja_ars > 0) {
        // Para no "buscar" (hacer peticiones lentas), usamos un dólar de referencia guardado o el manual
        $dolar_stmt = $pdo->query("SELECT dolar_mayorista FROM tambo_dolar_mes ORDER BY mes DESC LIMIT 1");
        $dolar_val = (float) ($dolar_stmt->fetch()['dolar_mayorista'] ?? 1000);
        
        $pizarra_soja_ars = $sio_soja_ars;
        $pizarra_soja = $sio_soja_ars / $dolar_val;
    }
} catch (\Exception $e) {
}



// ─── Listado de alquileres con Paginación ────────────────────────────────
$limit = 30;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// 1. Contar total para paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM alquileres WHERE usuario_id = ?");
$stmtCount->execute([$usuario_id]);
$total_rows = (int)$stmtCount->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 2. Obtener todos los registros para el resumen superior (sin paginar)
$stmtAll = $pdo->prepare("
    SELECT a.*, l.nombre AS lote_nombre, c.nombre AS cultivo_nombre
    FROM alquileres a
    LEFT JOIN lotes l ON a.lote_id = l.id
    LEFT JOIN cultivos c ON a.cultivo_id = c.id
    WHERE a.usuario_id = ?
");
$stmtAll->execute([$usuario_id]);
$alquileres_full = $stmtAll->fetchAll();

// 3. Obtener registros paginados para la tabla
$stmt = $pdo->prepare("
    SELECT a.*,
           l.nombre  AS lote_nombre,
           l.superficie,
           c.nombre  AS cultivo_nombre,
           c.ciclo   AS cultivo_ciclo
    FROM alquileres a
    LEFT JOIN lotes    l ON a.lote_id   = l.id
    LEFT JOIN cultivos c ON a.cultivo_id = c.id
    WHERE a.usuario_id = ?
    ORDER BY a.fecha_pago DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$usuario_id]);
$alquileres = $stmt->fetchAll();

// ─── Resumen por nivel/agrupador (usando el set completo) ─────────────────
$resumen = [];
foreach ($alquileres_full as $a) {
    // Clave de agrupamiento visual
    if ($a['nivel_imputacion'] === 'cultivo' && $a['cultivo_nombre']) {
        $key = $a['lote_nombre'] . ' › ' . $a['cultivo_nombre'];
    } elseif ($a['nivel_imputacion'] === 'campania') {
        $key = '🗓 Campaña ' . ($a['campania'] ?? '—');
    } else {
        $key = $a['lote_nombre'] ?? '—';
    }

    if (!isset($resumen[$key]))
        $resumen[$key] = ['total_usd' => 0, 'total_ars' => 0, 'pagos' => 0, 'nivel' => $a['nivel_imputacion']];
    if ($a['moneda'] === 'USD')
        $resumen[$key]['total_usd'] += $a['monto_pagado'];
    else
        $resumen[$key]['total_ars'] += $a['monto_pagado'];
    $resumen[$key]['pagos']++;
}

require_once 'includes/header.php';
?>

<style>
    .alq-resumen-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .alq-chip {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 20px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        transition: background 0.2s;
    }

    .alq-chip:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    .alq-chip.nivel-cultivo {
        border-left: 3px solid rgba(52, 211, 153, 0.6);
    }

    .alq-chip.nivel-campania {
        border-left: 3px solid rgba(251, 191, 36, 0.6);
    }

    .alq-chip.nivel-lote {
        border-left: 3px solid rgba(99, 102, 241, 0.6);
    }

    .nivel-badge-lote {
        background: rgba(99, 102, 241, 0.12);
        color: #a5b4fc;
        border: 1px solid rgba(99, 102, 241, 0.3);
    }

    .nivel-badge-cultivo {
        background: rgba(52, 211, 153, 0.12);
        color: #6ee7b7;
        border: 1px solid rgba(52, 211, 153, 0.3);
    }

    .nivel-badge-campania {
        background: rgba(251, 191, 36, 0.12);
        color: #fde68a;
        border: 1px solid rgba(251, 191, 36, 0.3);
    }

    .alq-calc-box {
        background: rgba(16, 185, 129, 0.07);
        border: 1px dashed rgba(16, 185, 129, 0.3);
        border-radius: 10px;
        padding: 14px 18px;
        margin-top: 4px;
    }

    /* Selector de nivel */
    .nivel-selector {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .nivel-btn {
        flex: 1;
        min-width: 100px;
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: rgba(255, 255, 255, 0.03);
        color: var(--text-muted);
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .nivel-btn.active-lote {
        background: rgba(99, 102, 241, 0.2);
        border-color: rgba(99, 102, 241, 0.5);
        color: #c7d2fe;
    }

    .nivel-btn.active-cultivo {
        background: rgba(52, 211, 153, 0.2);
        border-color: rgba(52, 211, 153, 0.5);
        color: #6ee7b7;
    }

    .nivel-btn.active-campania {
        background: rgba(251, 191, 36, 0.2);
        border-color: rgba(251, 191, 36, 0.5);
        color: #fde68a;
    }

    .nivel-btn:not(.active-lote):not(.active-cultivo):not(.active-campania):hover {
        background: rgba(255, 255, 255, 0.06);
        color: var(--text-primary);
    }
</style>

<!-- ===== RESUMEN RÁPIDO ===== -->
<?php if (!empty($resumen)): ?>
    <div class="alq-resumen-grid">
        <?php foreach ($resumen as $key => $r): ?>
            <div class="alq-chip nivel-<?= $r['nivel'] ?>">
                <span style="font-size:0.78rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.8px;">
                    <?php if ($r['nivel'] === 'cultivo'): ?>🌱
                    <?php elseif ($r['nivel'] === 'campania'): ?>🗓
                    <?php else: ?><i class="fas fa-map-marked-alt" style="color:var(--accent);"></i>
                    <?php endif; ?>
                    &nbsp;<?= htmlspecialchars($key) ?>
                </span>
                <?php if ($r['total_usd'] > 0): ?>
                    <span style="font-size:1.4rem; font-weight:800; color:#ff7b72;">
                        $<?= number_format($r['total_usd'], 2, ',', '.') ?> <small style="font-size:0.55em;opacity:.7;">USD</small>
                    </span>
                <?php endif; ?>
                <?php if ($r['total_ars'] > 0): ?>
                    <span style="font-size:1.2rem; font-weight:700; color:#f59e0b;">
                        $<?= number_format($r['total_ars'], 0, ',', '.') ?> <small style="font-size:0.55em;opacity:.7;">ARS</small>
                    </span>
                <?php endif; ?>
                <span style="font-size:0.8rem; color:var(--text-muted);"><?= $r['pagos'] ?> pago<?= $r['pagos'] != 1 ? 's' : '' ?>
                    registrado<?= $r['pagos'] != 1 ? 's' : '' ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ===== TABLA DE ALQUILERES ===== -->
<div class="glass-panel" style="margin-bottom: 24px;">
    <div class="panel-header">
        <h2 style="font-size: 1.2rem; font-weight: 500;">
            <i class="fas fa-file-contract" style="color: var(--accent); margin-right: 8px;"></i>
            Pagos de Alquiler Registrados
        </h2>
        <div style="display:flex; gap:8px;">
            <a href="api/reporte_pdf.php?tipo=alquileres" target="_blank" class="btn"
                style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#ff7b72; font-size:0.85rem;">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <button class="btn btn-primary" onclick="openNewAlqModal()">
                <i class="fas fa-plus"></i> Registrar Pago
            </button>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Fecha Pago</th>
                    <th>Imputación</th>
                    <th>Campaña</th>
                    <th>Monto</th>
                    <th>Notas</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alquileres as $a): ?>
                    <tr>
                        <td data-label="Fecha Pago"><?= date('d/m/Y', strtotime($a['fecha_pago'])) ?></td>
                        <td data-label="Imputación">
                            <?php if ($a['nivel_imputacion'] === 'cultivo'): ?>
                                <span class="badge nivel-badge-cultivo"
                                    style="border-radius:6px;padding:3px 8px;font-size:0.75rem;">🌱 Cultivo</span>
                                <br><strong style="font-size:0.9rem;"><?= htmlspecialchars($a['lote_nombre'] ?? '—') ?></strong>
                                <br><small
                                    style="color:var(--text-muted);"><?= htmlspecialchars($a['cultivo_nombre'] ?? '—') ?><?= $a['cultivo_ciclo'] ? ' &bull; ' . $a['cultivo_ciclo'] : '' ?></small>
                            <?php elseif ($a['nivel_imputacion'] === 'campania'): ?>
                                <span class="badge nivel-badge-campania"
                                    style="border-radius:6px;padding:3px 8px;font-size:0.75rem;">🗓 Campaña</span>
                                <br><strong style="font-size:0.9rem;">Global</strong>
                            <?php else: ?>
                                <span class="badge nivel-badge-lote"
                                    style="border-radius:6px;padding:3px 8px;font-size:0.75rem;">📍 Lote</span>
                                <br><strong style="font-size:0.9rem;"><?= htmlspecialchars($a['lote_nombre'] ?? '—') ?></strong>
                                <br><small style="color:var(--text-muted);"><?= number_format($a['superficie'] ?? 0, 1, ',', '.') ?>
                                    ha</small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Campaña">
                            <?= $a['campania'] ? '<span class="badge" style="background:rgba(16,185,129,0.1);color:var(--accent);border:1px solid rgba(16,185,129,0.2);">' . htmlspecialchars($a['campania']) . '</span>' : '<span style="color:var(--text-muted);">—</span>' ?>
                        </td>
                        <td data-label="Monto" style="font-weight:700; color:#ff7b72;">
                            -$<?= number_format($a['monto_pagado'], 2, ',', '.') ?>
                            <small style="color:var(--text-muted); font-weight:400;"> <?= htmlspecialchars($a['moneda']) ?></small>
                        </td>
                        <td data-label="Notas">
                            <span style="color:var(--text-muted); font-size:0.85rem;">
                                <?= $a['notas'] ? htmlspecialchars($a['notas']) : '—' ?>
                            </span>
                        </td>
                        <td data-label="Acciones">
                            <button type="button" class="btn"
                                style="color:var(--accent);background:transparent;padding:4px 8px;"
                                onclick='editAlq(<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display:inline;"
                                onsubmit="if(!confirm('¿Eliminar pago de alquiler?')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn"
                                    style="color:var(--danger);background:transparent;padding:4px 8px;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($alquileres) === 0): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:var(--text-muted);padding:40px;">
                            <i class="fas fa-file-contract"
                                style="font-size:2rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                            No hay pagos de alquiler registrados
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <div style="display:flex; justify-content: center; gap:10px; margin-top:20px; padding-bottom:10px;">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;"><i class="fas fa-chevron-left"></i> Anterior</a>
        <?php endif; ?>
        
        <span style="color:var(--text-muted); align-self:center; font-size:0.9rem;">Página <?= $page ?> de <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;">Siguiente <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===== CALCULADORA DE REFERENCIA ===== -->
<div class="glass-panel">
    <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:16px;">
        <i class="fas fa-calculator" style="color:var(--warning);margin-right:8px;"></i>
        Calculadora de Alquiler a Pizarra
    </h2>
    <div
        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:0;">
            Referencia rápida: calcula el costo de alquiler basado en la pizarra actual.
        </p>
        <div
            style="display: flex; align-items: center; gap: 10px; background: rgba(16, 185, 129, 0.1); padding: 8px 16px; border-radius: 12px; border: 1px solid var(--accent-glow);">
            <div style="display: flex; flex-direction: column;">
                <span
                    style="font-size: 0.65rem; color: var(--accent); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Pizarra
                    Soja (Ref.)</span>
                <span style="font-size: 1.1rem; font-weight: 800; color: white;">
                    <?php if (isset($pizarra_soja_ars) && $pizarra_soja_ars > 0): ?>
                        $<?= number_format($pizarra_soja_ars, 0, ',', '.') ?> <small style="font-size: 0.7rem; color: var(--text-muted); font-weight: 400;">ARS</small>
                        <span style="font-size: 0.8rem; color: var(--text-muted); margin: 0 4px;">≈</span>
                    <?php endif; ?>
                    $<?= number_format($pizarra_soja, 2, ',', '.') ?>
                    <small style="font-size: 0.7rem; color: var(--text-muted); font-weight: 400;">USD/tn</small>
                </span>
            </div>
            <i class="fas fa-seedling" style="color: var(--accent); font-size: 1.2rem; margin-left: 5px;"></i>
        </div>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
        <div style="display:flex;flex-direction:column;gap:5px;flex:1;min-width:140px;">
            <label style="font-size:0.85rem;color:var(--text-muted);">Sup. (ha)</label>
            <input type="number" id="calcSup" step="0.1" placeholder="Ej: 120" oninput="calcAlquiler()"
                style="padding:10px;border-radius:6px;border:1px solid var(--border);background:rgba(0,0,0,0.2);color:white;">
        </div>
        <div style="display:flex;flex-direction:column;gap:5px;flex:1;min-width:140px;">
            <label style="font-size:0.85rem;color:var(--text-muted);">QQ / ha</label>
            <input type="number" id="calcQq" step="0.1" placeholder="Ej: 18" oninput="syncUnits('qq')"
                style="padding:10px;border-radius:6px;border:1px solid var(--border);background:rgba(0,0,0,0.2);color:white;">
        </div>
        <div style="display:flex;flex-direction:column;gap:5px;flex:1;min-width:140px;">
            <label style="font-size:0.85rem;color:var(--text-muted);">KG / ha</label>
            <input type="number" id="calcKg" step="1" placeholder="Ej: 1800" oninput="syncUnits('kg')"
                style="padding:10px;border-radius:6px;border:1px solid var(--border);background:rgba(0,0,0,0.2);color:white;">
        </div>
        <input type="hidden" id="calcTns" value="0">
        <div class="alq-calc-box" id="calcResult" style="display:none; flex:2; min-width:200px;">
            <div style="font-size:0.8rem;color:var(--text-muted);">Total estimado a pagar</div>
            <div id="calcTotal" style="font-size:1.6rem;font-weight:800;color:var(--accent);">$0.00 USD</div>
            <div id="calcDetalle" style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;"></div>
        </div>
    </div>
</div>

<!-- ===== MODAL: Alta / Edición ===== -->
<div id="alqModal" class="modal-wrapper">
    <div class="glass-panel modal-panel">
        <h2 id="alqModalTitle" style="margin-bottom:20px;">Registrar Pago de Alquiler</h2>
        <form method="POST" style="display:flex;flex-direction:column;gap:14px;" id="alqForm"
            onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="alqAction" value="add">
            <input type="hidden" name="id" id="alqId" value="">

            <!-- ─── NIVEL DE IMPUTACIÓN ─── -->
            <div>
                <label style="font-size:0.85rem;color:var(--text-muted);display:block;margin-bottom:8px;">Nivel de
                    Imputación</label>
                <div class="nivel-selector">
                    <button type="button" id="btnNivelCultivo" class="nivel-btn active-cultivo"
                        onclick="setNivel('cultivo')"> 🌱 Por Cultivo</button>
                    <button type="button" id="btnNivelCampania" class="nivel-btn" onclick="setNivel('campania')">🗓 Por
                        Campaña</button>
                </div>
                <input type="hidden" name="nivel_imputacion" id="alqNivel" value="cultivo">
            </div>

            <!-- ─── LOTE ─── -->
            <div id="wrapLote" style="display:flex;flex-direction:column;gap:5px;">
                <label>Lote</label>
                <select name="lote_id" id="alqLoteSelect" onchange="onLoteChange()"
                    style="padding:10px;border-radius:6px;border:1px solid var(--border);background:var(--bg-color);color:white;">
                    <option value="">-- Seleccionar Lote --</option>
                    <?php foreach ($lotes as $l): ?>
                        <option value="<?= $l['id'] ?>" data-camp="<?= htmlspecialchars($l['campania'] ?? '') ?>">
                            <?= htmlspecialchars($l['nombre']) ?> (<?= $l['superficie'] ?> ha)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ─── CULTIVO (solo visible en modo cultivo) ─── -->
            <div id="wrapCultivo" style="display:none;flex-direction:column;gap:5px;">
                <label>Cultivo del Lote</label>
                <select name="cultivo_id" id="alqCultivoSelect"
                    style="padding:10px;border-radius:6px;border:1px solid var(--border);background:var(--bg-color);color:white;">
                    <option value="">-- Primero seleccioná un Lote --</option>
                </select>
            </div>

            <div class="form-grid-2">
                <div style="display:flex;flex-direction:column;gap:5px;">
                    <label>Campaña <small style="color:var(--text-muted);">(Ej: 24/25)</small></label>
                    <input type="text" name="campania" id="alqCampania" placeholder="Ej: 24/25"
                        style="padding:10px;border-radius:6px;border:1px solid var(--border);background:rgba(0,0,0,0.2);color:white;">
                </div>
                <div style="display:flex;flex-direction:column;gap:5px;">
                    <label>Fecha de Pago</label>
                    <input type="date" name="fecha_pago" id="alqFecha" value="<?= date('Y-m-d') ?>" required
                        style="padding:10px;border-radius:6px;border:1px solid var(--border);background:rgba(0,0,0,0.2);color:white;">
                </div>
            </div>

            <div class="form-grid-2">
                <div style="display:flex;flex-direction:column;gap:5px;">
                    <label>Monto Pagado (USD)</label>
                    <input type="number" step="0.01" name="monto_pagado" id="alqMonto" required placeholder="Ej: 8500"
                        style="padding:10px;border-radius:6px;border:1px solid var(--border);background:rgba(0,0,0,0.2);color:white;">
                </div>
                <div style="display:none;">
                    <label>Moneda</label>
                    <select name="moneda" id="alqMoneda">
                        <option value="USD" selected>USD — Dólares</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:5px;">
                <label>Notas <small style="color:var(--text-muted);">(Opcional)</small></label>
                <input type="text" name="notas" id="alqNotas" placeholder="Ej: 1er cuota campaña 24/25"
                    style="padding:10px;border-radius:6px;border:1px solid var(--border);background:rgba(0,0,0,0.2);color:white;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:4px;">
                <button type="button" class="btn" onclick="closeAlqModal()"
                    style="background:rgba(255,255,255,0.1);color:white;">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    const pizarraSoja = <?= $pizarra_soja ?>;
    let currentNivel = 'cultivo';

    // ─── NIVEL DE IMPUTACIÓN ────────────────────────────────────────────────
    function setNivel(nivel) {
        if (nivel === 'lote') nivel = 'cultivo'; // Fallback for old records
        currentNivel = nivel;
        document.getElementById('alqNivel').value = nivel;

        // Reset botones
        ['cultivo', 'campania'].forEach(n => {
            const btn = document.getElementById('btnNivel' + n.charAt(0).toUpperCase() + n.slice(1));
            if (btn) btn.className = 'nivel-btn';
        });
        document.getElementById('btnNivel' + nivel.charAt(0).toUpperCase() + nivel.slice(1)).className = 'nivel-btn active-' + nivel;

        // Visibilidad de campos
        document.getElementById('wrapLote').style.display = (nivel === 'cultivo') ? 'flex' : 'none';
        document.getElementById('wrapCultivo').style.display = (nivel === 'cultivo') ? 'flex' : 'none';

        // Si requieren lote, lo hacemos required; si es campaña, no
        document.getElementById('alqLoteSelect').required = (nivel === 'cultivo');
    }

    // ─── CAMBIO DE LOTE → CARGAR CULTIVOS ───────────────────────────────────
    function onLoteChange() {
        const loteId = document.getElementById('alqLoteSelect').value;
        const opt = document.getElementById('alqLoteSelect').options[document.getElementById('alqLoteSelect').selectedIndex];

        // Autorellenar campaña desde lote
        if (opt) {
            document.getElementById('alqCampania').value = opt.dataset.camp || '';
        }

        // Cargar cultivos si estamos en modo cultivo
        if (currentNivel === 'cultivo' && loteId) {
            document.getElementById('alqCultivoSelect').innerHTML = '<option value="">Cargando cultivos...</option>';
            fetch(`alquileres.php?api=cultivos_lote&lote_id=${loteId}`)
                .then(r => r.json())
                .then(cultivos => {
                    const sel = document.getElementById('alqCultivoSelect');
                    sel.innerHTML = '';
                    if (cultivos.length === 0) {
                        sel.innerHTML = '<option value="">-- El lote no tiene cultivos registrados --</option>';
                    } else {
                        sel.innerHTML = '<option value="">-- Seleccionar Cultivo --</option>';
                        cultivos.forEach(c => {
                            const label = c.nombre + (c.ciclo ? ' · ' + c.ciclo : '');
                            sel.innerHTML += `<option value="${c.id}">${label}</option>`;
                        });
                    }
                })
                .catch(err => {
                    console.error(err);
                    document.getElementById('alqCultivoSelect').innerHTML = '<option value="">Error al cargar cultivos</option>';
                });
        } else if (!loteId) {
            document.getElementById('alqCultivoSelect').innerHTML = '<option value="">-- Primero seleccioná un Lote --</option>';
        }
    }

    // ─── ABRIR MODAL NUEVO ───────────────────────────────────────────────────
    function openNewAlqModal() {
        document.getElementById('alqModalTitle').innerText = '➕ Registrar Pago de Alquiler';
        document.getElementById('alqAction').value = 'add';
        document.getElementById('alqId').value = '';
        document.getElementById('alqLoteSelect').value = '';
        document.getElementById('alqCampania').value = '';
        document.getElementById('alqFecha').value = '<?= date('Y-m-d') ?>';
        document.getElementById('alqMonto').value = '';
        document.getElementById('alqMoneda').value = 'USD';
        document.getElementById('alqNotas').value = '';
        document.getElementById('alqCultivoSelect').innerHTML = '<option value="">-- Primero seleccioná un Lote --</option>';
        setNivel('cultivo');
        document.getElementById('alqModal').style.display = 'flex';
    }

    // ─── ABRIR MODAL EDITAR ──────────────────────────────────────────────────
    function editAlq(a) {
        document.getElementById('alqModalTitle').innerText = '✏️ Editar Pago de Alquiler';
        document.getElementById('alqAction').value = 'edit';
        document.getElementById('alqId').value = a.id;
        document.getElementById('alqLoteSelect').value = a.lote_id || '';
        document.getElementById('alqCampania').value = a.campania || '';
        document.getElementById('alqFecha').value = a.fecha_pago;
        document.getElementById('alqMonto').value = a.monto_pagado;
        document.getElementById('alqMoneda').value = a.moneda || 'USD';
        document.getElementById('alqNotas').value = a.notas || '';

        const nivel = a.nivel_imputacion || 'cultivo';
        setNivel(nivel);

        if (nivel === 'cultivo' && a.lote_id) {
            fetch(`alquileres.php?api=cultivos_lote&lote_id=${a.lote_id}`)
                .then(r => r.json())
                .then(cultivos => {
                    const sel = document.getElementById('alqCultivoSelect');
                    sel.innerHTML = '<option value="">-- Seleccionar Cultivo --</option>';
                    cultivos.forEach(c => {
                        const label = c.nombre + (c.ciclo ? ' · ' + c.ciclo : '');
                        sel.innerHTML += `<option value="${c.id}" ${c.id == a.cultivo_id ? 'selected' : ''}>${label}</option>`;
                    });
                });
        }

        document.getElementById('alqModal').style.display = 'flex';
    }

    function closeAlqModal() {
        document.getElementById('alqModal').style.display = 'none';
    }

    window.addEventListener('click', e => {
        const modal = document.getElementById('alqModal');
        if (e.target === modal) closeAlqModal();
    });

    /* ─── Calculadora de referencia ─── */
    function syncUnits(origin) {
        const qqInput = document.getElementById('calcQq');
        const kgInput = document.getElementById('calcKg');
        const tnsInput = document.getElementById('calcTns');

        if (origin === 'qq') {
            const qq = parseFloat(qqInput.value) || 0;
            kgInput.value = (qq * 100).toFixed(0);
            tnsInput.value = (qq / 10).toFixed(2);
        } else {
            const kg = parseFloat(kgInput.value) || 0;
            qqInput.value = (kg / 100).toFixed(1);
            tnsInput.value = (kg / 1000).toFixed(3);
        }
        calcAlquiler();
    }

    function calcAlquiler() {
        const sup = parseFloat(document.getElementById('calcSup').value) || 0;
        const tns = parseFloat(document.getElementById('calcTns').value) || 0;
        const res = document.getElementById('calcResult');
        if (sup > 0 && tns > 0 && pizarraSoja > 0) {
            const total = sup * tns * pizarraSoja;
            const kgTotal = (sup * tns * 1000).toLocaleString('es-AR');
            document.getElementById('calcTotal').innerText = '$' + total.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' USD';
            document.getElementById('calcDetalle').innerText = sup + ' ha × ' + tns + ' TNS/ha = ' + kgTotal + ' kg totales × $' + pizarraSoja.toFixed(2) + ' soja';
            res.style.display = 'block';
        } else {
            res.style.display = 'none';
        }
    }
</script>

<?php require_once 'includes/footer.php'; ?>