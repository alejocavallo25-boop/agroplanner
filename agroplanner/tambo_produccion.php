<?php
require_once 'config/auth.php';
require_once 'config/database.php';
require_tambo();
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Ingresos del Tambo';

validate_csrf();

// ─── Tabla Dif Inventario ──────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `tambo_dif_inventario` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `usuario_id` INT NOT NULL,
  `venta_carne_id` INT NOT NULL,
  `mes_label_act` VARCHAR(20),
  `mes_label_ant` VARCHAR(20),
  `categoria` VARCHAR(80) NOT NULL,
  `cant_actual` INT NOT NULL DEFAULT 0,
  `cant_anterior` INT NOT NULL DEFAULT 0,
  `valor_unitario` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `criterio` VARCHAR(150),
  PRIMARY KEY(`id`),
  INDEX `idx_venta_carne`(`venta_carne_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $pdo->exec("ALTER TABLE tambo_ventas_carne ADD COLUMN monto_original DECIMAL(14,2) NULL AFTER monto_total");
} catch (\PDOException $e) {}

$CATEGORIAS_DIF = [
    'Comunitaria'     => 'GENERALES 12-14 MESES MAX',
    'Maternidad'      => 'GENERALES 12-14 MESES MAX',
    'Vaquillonas'     => 'RC ADELANTADA PARIDA MAXIMO',
    'Secas'           => 'ORDEÑE 2DO PARTO MAX',
    'Recrías'         => 'REG DE CRIA 12-14 MESES MAX',
    'Vacas en ordeñe' => 'ORDEÑE 2DO PARTO MAX',
    'Preparto'        => 'ORDEÑE 2DO PARTO MAX',
    'Mastitis'        => 'ORDEÑE 2DO PARTO MAX',
    'Enfermería'      => 'ORDEÑE 2DO PARTO MAX',
];

// ─── POST Actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_produccion') {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $fecha = $_POST['fecha'] . '-01';
        $lm = (float) str_replace(',', '.', str_replace('.', '', $_POST['litros'] ?? '0'));
        $lt = 0;
        $precio = !empty($_POST['precio_litro']) ? (float) str_replace(',', '.', str_replace('.', '', $_POST['precio_litro'])) : null;
        $destino = $_POST['destino'] ?? 'buena';
        $notas = trim($_POST['notas'] ?? '');

        if ($id) {
            $stmt = $pdo->prepare("UPDATE tambo_produccion SET fecha=?, litros_manana=?, litros_tarde=?, precio_litro=?, destino=?, notas=? WHERE id=? AND usuario_id=?");
            $stmt->execute([$fecha, $lm, $lt, $precio, $destino, $notas ?: null, $id, $usuario_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO tambo_produccion (usuario_id, fecha, litros_manana, litros_tarde, precio_litro, destino, notas) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $fecha, $lm, $lt, $precio, $destino, $notas ?: null]);
        }
        set_flash('success', 'Ingreso de leche guardado correctamente.');
        header('Location: tambo_produccion.php');
        exit;

    } elseif ($_POST['action'] === 'delete_produccion') {
        $stmt = $pdo->prepare("DELETE FROM tambo_produccion WHERE id=? AND usuario_id=?");
        $stmt->execute([$_POST['id'], $usuario_id]);
        set_flash('success', 'Registro eliminado correctamente.');
        header('Location: tambo_produccion.php');
        exit;

    } elseif ($_POST['action'] === 'save_carne') {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $categoria_animal = $_POST['categoria_animal'] ?? 'otro';
        $cantidad_animales = !empty($_POST['cantidad_animales']) ? (int) str_replace(',', '.', str_replace('.', '', $_POST['cantidad_animales'])) : null;
        $kg_vivo = !empty($_POST['kg_vivo']) ? (float) str_replace(',', '.', str_replace('.', '', $_POST['kg_vivo'])) : null;
        $precio_kg = !empty($_POST['precio_kg']) ? (float) str_replace(',', '.', str_replace('.', '', $_POST['precio_kg'])) : null;
        $monto_original = !empty($_POST['monto_carne']) ? (float) str_replace(',', '.', str_replace('.', '', $_POST['monto_carne'])) : 0;
        $monto_total = $monto_original / 12;
        $fecha_carne = $_POST['fecha_carne'] . '-01';

        if ($id) {
            $stmt = $pdo->prepare("UPDATE tambo_ventas_carne SET fecha=?, tipo=?, categoria_animal=?, cantidad_animales=?, kg_vivo=?, precio_kg=?, monto_total=?, monto_original=?, notas=? WHERE id=? AND usuario_id=?");
            $stmt->execute([$fecha_carne, 'venta_carne', $categoria_animal, $cantidad_animales, $kg_vivo, $precio_kg, $monto_total, $monto_original, trim($_POST['notas_carne'] ?? '') ?: null, $id, $usuario_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO tambo_ventas_carne (usuario_id, fecha, tipo, categoria_animal, cantidad_animales, kg_vivo, precio_kg, monto_total, monto_original, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $fecha_carne, 'venta_carne', $categoria_animal, $cantidad_animales, $kg_vivo, $precio_kg, $monto_total, $monto_original, trim($_POST['notas_carne'] ?? '') ?: null]);
        }
        set_flash('success', 'Ingreso de carne guardado correctamente.');
        header('Location: tambo_produccion.php');
        exit;

    } elseif ($_POST['action'] === 'save_dif_inventario') {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $fecha = $_POST['fecha_dif'] . '-01';
        $mes_act = trim($_POST['mes_label_act'] ?? '');
        $mes_ant = trim($_POST['mes_label_ant'] ?? '');
        $notas = trim($_POST['notas_dif'] ?? '');
        
        $periodos = !empty($_POST['cant_periodos']) ? max(1, (int)$_POST['cant_periodos']) : 1;

        // 1. Calcular Gran Total
        $gran_total = 0;
        foreach ($CATEGORIAS_DIF as $cat => $def_criterio) {
            $ca = (int)($_POST['cant_actual'][$cat] ?? 0);
            $cb = (int)($_POST['cant_anterior'][$cat] ?? 0);
            $vu = (float)str_replace(',', '.', str_replace('.', '', $_POST['valor_unitario'][$cat] ?? 0));
            $gran_total += ($ca - $cb) * $vu;
        }

        $monto_guardar = $gran_total / $periodos;

        // 2. Guardar maestro en tambo_ventas_carne
        if ($id) {
            $stmt = $pdo->prepare("UPDATE tambo_ventas_carne SET fecha=?, monto_total=?, monto_original=?, cantidad_animales=?, notas=? WHERE id=? AND usuario_id=?");
            $stmt->execute([$fecha, $monto_guardar, $gran_total, $periodos, $notas ?: null, $id, $usuario_id]);
            $venta_carne_id = $id;
            // Borramos detalles viejos para reinsertar limpios
            $pdo->prepare("DELETE FROM tambo_dif_inventario WHERE venta_carne_id=?")->execute([$venta_carne_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO tambo_ventas_carne (usuario_id, fecha, tipo, monto_total, monto_original, cantidad_animales, notas) VALUES (?, ?, 'diferencia_inventario', ?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $fecha, $monto_guardar, $gran_total, $periodos, $notas ?: null]);
            $venta_carne_id = $pdo->lastInsertId();
        }

        // 3. Guardar detalle en tambo_dif_inventario
        $stmt_det = $pdo->prepare("INSERT INTO tambo_dif_inventario (usuario_id, venta_carne_id, mes_label_act, mes_label_ant, categoria, cant_actual, cant_anterior, valor_unitario, criterio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($CATEGORIAS_DIF as $cat => $def_criterio) {
            $ca = (int)($_POST['cant_actual'][$cat] ?? 0);
            $cb = (int)($_POST['cant_anterior'][$cat] ?? 0);
            $vu = (float)str_replace(',', '.', str_replace('.', '', $_POST['valor_unitario'][$cat] ?? 0));
            $cr = trim($_POST['criterio'][$cat] ?? $def_criterio) ?: $def_criterio;
            $stmt_det->execute([$usuario_id, $venta_carne_id, $mes_act, $mes_ant, $cat, $ca, $cb, $vu, $cr]);
        }

        set_flash('success', 'Diferencia de inventario guardada correctamente.');
        header('Location: tambo_produccion.php');
        exit;

    } elseif ($_POST['action'] === 'delete_carne') {
        // Al borrar carne o diferencia, borramos también el detalle si lo tiene
        $stmt_det = $pdo->prepare("DELETE FROM tambo_dif_inventario WHERE venta_carne_id=? AND usuario_id=?");
        $stmt_det->execute([$_POST['id'], $usuario_id]);

        $stmt = $pdo->prepare("DELETE FROM tambo_ventas_carne WHERE id=? AND usuario_id=?");
        $stmt->execute([$_POST['id'], $usuario_id]);
        
        set_flash('success', 'Registro eliminado correctamente.');
        header('Location: tambo_produccion.php');
        exit;
    }
}

// ─── Datos para la vista ───────────────────────────────────────────────────

$mes_sel       = $_GET['mes'] ?? date('Y-m');
$mes_start     = $mes_sel . '-01';
$mes_end       = date('Y-m-t', strtotime($mes_start));

// Estadísticas del mes actual (Leche)
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN destino != 'otra' THEN litros_total ELSE 0 END) as total_buena,
        SUM(CASE WHEN destino = 'otra' THEN litros_total ELSE 0 END) as total_otra
    FROM tambo_produccion 
    WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?
");
$stmt->execute([$usuario_id, $mes_start, $mes_end]);
$stats_mes = $stmt->fetch();

// Promedios Carne (Últimos 12 meses)
$stmt_carne_12 = $pdo->prepare("
    SELECT SUM(monto_total) as total_ingreso, SUM(kg_vivo) as total_kg
    FROM tambo_ventas_carne
    WHERE usuario_id = ? AND tipo = 'venta_carne' AND fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
");
$stmt_carne_12->execute([$usuario_id]);
$stats_carne_12 = $stmt_carne_12->fetch();
$promedio_mensual_ingreso = ($stats_carne_12['total_ingreso'] ?: 0) / 12;
$promedio_mensual_kg = ($stats_carne_12['total_kg'] ?: 0) / 12;

// Historial detallado
$filtro_tipo = $_GET['tipo_historial'] ?? '';

$where_leche = "WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?";
$params_leche = [$usuario_id, $mes_start, $mes_end];

$where_carne = "WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?";
$params_carne = [$usuario_id, $mes_start, $mes_end];

$history = [];

if ($filtro_tipo === '' || $filtro_tipo === 'buena' || $filtro_tipo === 'otra') {
    $stmt_leche = $pdo->prepare("
        SELECT id, fecha, destino as tipo_raw, litros_total, precio_litro, (litros_total * precio_litro) as total_pesos, notas
        FROM tambo_produccion $where_leche
    ");
    $stmt_leche->execute($params_leche);
    foreach ($stmt_leche->fetchAll() as $row) {
        if ($filtro_tipo !== '' && $row['tipo_raw'] !== $filtro_tipo)
            continue;
        $tipo_label = $row['tipo_raw'] === 'buena' ? '<i class="fas fa-check-circle" style="color:var(--accent);"></i> Leche Buena' : '<i class="fas fa-exclamation-triangle" style="color:#60a5fa;"></i> Leche Otra';
        $history[] = [
            'id' => $row['id'],
            'fecha' => $row['fecha'],
            'tipo' => $tipo_label,
            'tipo_raw' => $row['tipo_raw'],
            'litros' => $row['litros_total'],
            'precio' => $row['precio_litro'],
            'total' => (float) $row['total_pesos'],
            'notas' => $row['notas'],
            'categoria' => 'leche'
        ];
    }
}

if ($filtro_tipo === '' || $filtro_tipo === 'venta_carne' || $filtro_tipo === 'diferencia_inventario') {
    $stmt_carne = $pdo->prepare("
        SELECT id, fecha, tipo as tipo_raw, categoria_animal, cantidad_animales, kg_vivo, precio_kg, monto_total as total_pesos, monto_original, notas
        FROM tambo_ventas_carne $where_carne
    ");
    $stmt_carne->execute($params_carne);
    foreach ($stmt_carne->fetchAll() as $row) {
        if ($filtro_tipo !== '' && $row['tipo_raw'] !== $filtro_tipo)
            continue;

        $tipo_label = '<i class="fas fa-drumstick-bite" style="color:#f87171;"></i> Venta de Carne';
        if ($row['tipo_raw'] === 'diferencia_inventario') {
            $tipo_label = '<i class="fas fa-boxes-stacked" style="color:#f59e0b;"></i> Diferencia Inventario';
        } else {
            $cabezas = $row['cantidad_animales'] ? ' x ' . $row['cantidad_animales'] : '';
            $tipo_label .= ' (' . ucfirst($row['categoria_animal']) . $cabezas . ')';
        }

        $detalles_dif = [];
        if ($row['tipo_raw'] === 'diferencia_inventario') {
            $stmt_dif = $pdo->prepare("SELECT * FROM tambo_dif_inventario WHERE venta_carne_id = ?");
            $stmt_dif->execute([$row['id']]);
            $detalles_dif = $stmt_dif->fetchAll(PDO::FETCH_ASSOC);
        }

        $history[] = [
            'id' => $row['id'],
            'fecha' => $row['fecha'],
            'tipo' => $tipo_label,
            'tipo_raw' => $row['tipo_raw'],
            'categoria_animal' => $row['categoria_animal'],
            'cantidad_animales' => $row['cantidad_animales'],
            'kg_vivo' => $row['kg_vivo'],
            'precio_kg' => $row['precio_kg'],
            'total' => (float) $row['total_pesos'],
            'monto_original' => $row['monto_original'] !== null ? (float)$row['monto_original'] : null,
            'notas' => $row['notas'],
            'categoria' => 'carne',
            'detalles_dif' => $detalles_dif
        ];
    }
}

usort($history, function ($a, $b) {
    return strcmp($b['fecha'], $a['fecha']);
});

// ─── Paginación del Historial Unificado ───────────────────────────────────
$limit = 30;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$total_rows = count($history);
$total_pages = ceil($total_rows / $limit);
$history_paginated = array_slice($history, $offset, $limit);

require_once 'includes/header.php';
?>

<style>
    .kpi-card {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        transition: transform .2s;
        position: relative;
        overflow: hidden;
        max-width: 300px;
        margin-bottom: 24px;
    }

    .kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        border-radius: 16px 16px 0 0;
        background: linear-gradient(90deg, #10b981, #34d399);
    }

    .kpi-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        margin-bottom: 4px;
        background: rgba(16, 185, 129, .12);
        color: #10b981;
    }

    .kpi-label {
        font-size: .75rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .6px;
        font-weight: 600;
    }

    .kpi-value {
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.1;
    }

    .kpi-sub {
        font-size: .8rem;
        color: var(--text-muted);
    }

    .chart-card {
        background: rgba(255, 255, 255, .03);
        border: 1px solid rgba(255, 255, 255, .08);
        border-radius: 16px;
        padding: 24px;
    }

    .tab-nav {
        display: flex;
        gap: 4px;
        margin-bottom: 20px;
        border-bottom: 1px solid rgba(255, 255, 255, .07);
        padding-bottom: 0;
    }

    .tab-btn {
        padding: 10px 18px;
        border: none;
        background: transparent;
        color: var(--text-muted);
        font-size: .9rem;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        transition: all .2s;
        font-family: inherit;
        margin-bottom: -1px;
    }

    .tab-btn.active {
        color: var(--accent);
        border-bottom-color: var(--accent);
        font-weight: 600;
    }

    .tab-btn:hover:not(.active) {
        color: var(--text-primary);
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
        margin-bottom: 14px;
    }

    .form-group label {
        font-size: .85rem;
        color: var(--text-muted);
    }

    .form-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-bottom: 14px;
    }

    input[type=text],
    input[type=date],
    input[type=number],
    select,
    textarea {
        padding: 10px 12px;
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, .1);
        background: rgba(0, 0, 0, .2);
        color: white;
        font-family: inherit;
        font-size: .9rem;
        width: 100%;
        box-sizing: border-box;
    }

    input:focus,
    select:focus,
    textarea:focus {
        outline: none;
        border-color: var(--accent);
    }

    @media(max-width:900px) {
        .form-grid-2 {
            grid-template-columns: 1fr;
        }
    }
</style>



<!-- Filtro Global Mensual -->
<div style="display:flex; justify-content: flex-end; align-items: center; margin-bottom: 16px;">
    <input type="month" value="<?= $mes_sel ?>" onchange="location.href='tambo_produccion.php?mes='+this.value+'&tipo_historial=<?= urlencode($filtro_tipo) ?>'" style="padding: 8px 14px; border-radius: 20px; border: 1px solid var(--accent); background: rgba(16,185,129,0.1); color: white; cursor: pointer; font-weight: 500;">
</div>

<!-- KPIs Leche -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
    <div class="kpi-card" style="margin-bottom: 0;">
        <div class="kpi-icon"><i class="fas fa-droplet"></i></div>
        <span class="kpi-label">Litros Leche Buena</span>
        <span class="kpi-value"><?= number_format((float) $stats_mes['total_buena'], 0) ?> L</span>
        <span class="kpi-sub">Total acumulado en <?= date('F Y', strtotime($mes_start)) ?></span>
    </div>
    <div class="kpi-card" style="margin-bottom: 0;">
        <div class="kpi-icon" style="color: #60a5fa; background: rgba(59,130,246,0.12);"><i class="fas fa-flask"></i></div>
        <span class="kpi-label">Litros Leche Otra</span>
        <span class="kpi-value" style="color: #60a5fa;"><?= number_format((float) $stats_mes['total_otra'], 0) ?> L</span>
        <span class="kpi-sub">Total acumulado en <?= date('F Y', strtotime($mes_start)) ?></span>
    </div>
</div>

<!-- Formularios en tabs -->
<div class="chart-card">
    <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('leche', this)"><i class="fas fa-tint"></i> Ingresos
            Leche</button>
        <button class="tab-btn" onclick="switchTab('carne', this)"><i class="fas fa-drumstick-bite"></i> Ingresos
            Carne</button>
        <button class="tab-btn" onclick="switchTab('dif-inventario', this)"><i class="fas fa-boxes-stacked"></i> Diferencia Inventario</button>
    </div>

    <!-- Tab Leche -->
    <div id="tab-leche" class="tab-content active">
        <form method="POST" style="display:flex; flex-direction:column;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_produccion">
            <input type="hidden" name="id" value="">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Fecha</label>
                    <input type="month" name="fecha" value="<?= date('Y-m') ?>" onclick="this.showPicker && this.showPicker();" required>
                </div>
                <div class="form-group">
                    <label>Tipo de Leche</label>
                    <select name="destino" id="tipoLeche" onchange="toggleNotasLeche()">
                        <option value="buena">✓ Buena</option>
                        <option value="otra">⚠ Otra (Descripción)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" id="notasLecheContainer" style="display:none;">
                <label>Descripción / Observación de "Otra"</label>
                <textarea name="notas" id="notasLeche" rows="2" placeholder="Describa el tipo de leche..."></textarea>
            </div>


            <div class="form-group" style="margin-bottom:14px;">
                <label>Litros Totales</label>
                <input type="text" inputmode="decimal" class="format-number" name="litros" placeholder="Ej: 2,900" required>
            </div>
            <div class="form-group">
                <label>Precio por Litro <small style="color:var(--text-muted)">(ARS — opcional)</small></label>
                <input type="text" inputmode="decimal" class="format-number" name="precio_litro" placeholder="Ej: 285.50">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;"><i class="fas fa-save"></i> Registrar
                Ingreso Leche</button>
        </form>
    </div>

    <!-- Tab Carne -->
    <div id="tab-carne" class="tab-content">
        <div style="display:flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
            <form method="POST" style="display:flex; flex-direction:column; flex: 2; min-width: 300px;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save_carne">
                <input type="hidden" name="id" value="">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Fecha de Ingreso</label>
                        <input type="month" name="fecha_carne" value="<?= date('Y-m') ?>" onclick="this.showPicker && this.showPicker();" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo de Ingreso (Carne)</label>
                        <select name="tipo_carne" id="tipoCarneSelect" required onchange="toggleCategoriaCarne()">
                            <option value="venta_carne">Venta de Carne</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="categoriaAnimalContainer">
                    <div style="display:flex; gap:15px;">
                        <div style="flex:1;">
                            <label>Categoría Animal</label>
                            <select name="categoria_animal" id="categoriaAnimalSelect">
                                <option value="vaca">Vaca</option>
                                <option value="vaquillona">Vaquillona</option>
                                <option value="ternero">Ternero</option>
                                <option value="ternera">Ternera</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label>Cantidad (Cabezas) <small style="color:var(--text-muted);">(Opcional)</small></label>
                            <input type="text" inputmode="decimal" class="format-number" name="cantidad_animales" id="carneCantidad" placeholder="Ej: 5"
                                oninput="calcTotalCarne()">
                        </div>
                    </div>
                </div>

                <div class="form-grid-2" id="kgPrecioContainer">
                    <div class="form-group">
                        <label>Kg Vivo <small style="color:var(--text-muted);">(Opcional)</small></label>
                        <input type="text" inputmode="decimal" class="format-number" name="kg_vivo" id="carneKgVivo" placeholder="Ej: 450"
                            oninput="calcTotalCarne()">
                    </div>
                    <div class="form-group">
                        <label>Precio por Kg Vivo ($) <small style="color:var(--text-muted);">(Opcional)</small></label>
                        <input type="text" inputmode="decimal" class="format-number" name="precio_kg" id="carnePrecioKg" placeholder="Ej: 1,500"
                            oninput="calcTotalCarne()">
                    </div>
                </div>

                <div class="form-group">
                    <label>Monto Total ($)</label>
                    <input type="text" inputmode="decimal" class="format-number" name="monto_carne" id="carneMontoTotal" required
                        placeholder="Ej: 250,000" style="font-weight:bold; color:var(--accent);" oninput="calcPromedioCarne()">
                    <div style="margin-top:8px; padding:10px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; flex-direction:column;">
                            <span style="font-size:0.8rem; font-weight:700; color:var(--text-primary);">Total Promedio</span>
                            <span style="font-size:0.7rem; color:var(--text-muted);">(Total dividido 12) *Éste es el valor que se guarda como ingreso.</span>
                        </div>
                        <span id="carneMontoPromedio" style="font-weight:bold; color:#34d399; font-size:1.1rem;">$0</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Notas / Descripción</label>
                    <textarea name="notas_carne" id="notasCarne" rows="2"
                        placeholder="Ej: Venta de 2 vacas de rechazo..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary"
                    style="align-self: flex-start; margin-top: 10px; width:100%;"><i class="fas fa-save"></i> Registrar
                    Ingreso Carne</button>
            </form>


        </div>
    </div>

    <!-- Tab Diferencia Inventario -->
    <div id="tab-dif-inventario" class="tab-content">
        <form method="POST" id="formDifInventario" style="display:flex; flex-direction:column;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_dif_inventario">
            <input type="hidden" name="id" value="">

            <div class="form-grid-2" style="margin-bottom: 20px;">
                <div class="form-group">
                    <label>Fecha del Cierre (Registro)</label>
                    <input type="month" name="fecha_dif" value="<?= date('Y-m') ?>" onclick="this.showPicker && this.showPicker();" required>
                </div>
                <div style="display:flex; gap:14px;">
                    <div class="form-group" style="flex:1;">
                        <label>Mes Stock Cierre</label>
                        <input type="month" id="difMesAct" name="mes_label_act" onchange="calcPeriodosDif()">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Mes Stock Inicio</label>
                        <input type="month" id="difMesAnt" name="mes_label_ant" onchange="calcPeriodosDif()">
                    </div>
                    <div class="form-group" style="width:120px;">
                        <label>Periodos <small>(Meses)</small></label>
                        <input type="number" id="difPeriodos" name="cant_periodos" value="1" min="1" oninput="calcGranTotalDif()" style="text-align:center; font-weight:bold; color:var(--accent);">
                    </div>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; min-width:800px; font-size:0.85rem;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.02);">
                            <th style="padding:10px; text-align:left; color:var(--text-muted);">Categoría</th>
                            <th style="padding:10px; text-align:center; color:var(--text-muted); width:110px;">Stock de Cierre</th>
                            <th style="padding:10px; text-align:center; color:var(--text-muted); width:110px;">Stock Inicio</th>
                            <th style="padding:10px; text-align:center; color:var(--text-muted); width:80px;">Diferencia</th>
                            <th style="padding:10px; text-align:right; color:var(--text-muted); width:130px;">Valor Unit. ($)</th>
                            <th style="padding:10px; text-align:right; color:var(--text-muted); width:130px;">Valor Total ($)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($CATEGORIAS_DIF as $cat => $def_crit): ?>
                        <tr class="dif-row" data-cat="<?= htmlspecialchars($cat) ?>" style="border-bottom:1px solid rgba(255,255,255,0.04);">
                            <td style="padding:8px 10px; font-weight:600;"><?= htmlspecialchars($cat) ?></td>
                            <td style="padding:8px 10px;"><input type="number" name="cant_actual[<?= htmlspecialchars($cat) ?>]" class="dif-ca" value="0" min="0" oninput="calcFilaDif(this)" style="padding:6px; font-size:0.85rem; text-align:center;"></td>
                            <td style="padding:8px 10px;"><input type="number" name="cant_anterior[<?= htmlspecialchars($cat) ?>]" class="dif-cb" value="0" min="0" oninput="calcFilaDif(this)" style="padding:6px; font-size:0.85rem; text-align:center;"></td>
                            <td style="padding:8px 10px; text-align:center; font-weight:bold;" class="dif-dif">0</td>
                            <td style="padding:8px 10px;"><input type="text" inputmode="decimal" name="valor_unitario[<?= htmlspecialchars($cat) ?>]" class="format-number dif-vu" value="" placeholder="0" oninput="calcFilaDif(this)" style="padding:6px; font-size:0.85rem; text-align:right;"></td>
                            <td style="padding:8px 10px; text-align:right; font-weight:bold;" class="dif-vt">$0</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:rgba(167,139,250,0.08); border-top:2px solid rgba(167,139,250,0.25);">
                            <td colspan="5" style="padding:12px 10px; text-align:right; font-weight:bold;">GRAN TOTAL DIF. INVENTARIO</td>
                            <td style="padding:12px 10px; text-align:right; font-weight:bold; font-size:1.05rem;" id="difGranTotal">$0</td>
                        </tr>
                        <tr style="background:rgba(255,255,255,0.02);">
                            <td colspan="5" style="padding:12px 10px; text-align:right; font-weight:bold; font-size:0.85rem; color:var(--text-muted);">PROMEDIO MENSUAL (Total / Periodos)</td>
                            <td style="padding:12px 10px; text-align:right; font-weight:bold; font-size:0.95rem; color:var(--text-muted);" id="difPromedioMensual">$0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="form-group" style="margin-top:20px;">
                <label>Notas / Descripción</label>
                <textarea name="notas_dif" rows="2" placeholder="Observaciones del período..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="align-self: flex-start; margin-top: 10px; width:100%; background:#a78bfa; border-color:#a78bfa;">
                <i class="fas fa-save"></i> Registrar Diferencia de Inventario
            </button>
        </form>
    </div>

</div>

<!-- Tabla historial producción -->
<div class="chart-card" style="margin-top: 24px;">
    <div class="chart-title" style="justify-content: space-between; flex-wrap: wrap; gap:10px;">
        <div><i class="fas fa-list"></i> Detalle de Ingresos (ARS)</div>
        <form method="GET"
            style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; background:rgba(255,255,255,0.02); padding:10px 16px; border-radius:10px; border:1px solid rgba(255,255,255,0.05); margin-bottom:10px;">
            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes_sel) ?>">
            
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="font-size:0.85rem; color:var(--text-muted); margin:0; font-weight:600;">Tipo:</label>
                <select name="tipo_historial" onchange="this.form.submit()"
                    style="padding:8px 12px; border-radius:6px; border:1px solid rgba(255,255,255,0.1); background:rgba(0,0,0,0.2); color:white; font-family:inherit; font-size:0.9rem; min-width:170px; outline:none;">
                    <option value="">Todos los tipos</option>
                    <option value="buena" <?= $filtro_tipo == 'buena' ? 'selected' : '' ?>>🥛 Leche Buena</option>
                    <option value="otra" <?= $filtro_tipo == 'otra' ? 'selected' : '' ?>>⚠️ Leche Otra</option>
                    <option value="diferencia_inventario" <?= $filtro_tipo == 'diferencia_inventario' ? 'selected' : '' ?>>📦
                        Diferencia Inventario</option>
                    <option value="venta_carne" <?= $filtro_tipo == 'venta_carne' ? 'selected' : '' ?>>🥩 Venta de Carne
                    </option>
                </select>
            </div>

            <?php if ($filtro_tipo): ?>
                <a href="tambo_produccion.php?mes=<?= urlencode($mes_sel) ?>" class="btn"
                    style="padding:8px 14px; background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.2); border-radius:6px; text-decoration:none; font-size:0.85rem; display:flex; align-items:center; gap:6px;"><i
                        class="fas fa-times"></i> Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-container" style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <th
                        style="padding: 12px; color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">
                        Fecha</th>
                    <th
                        style="padding: 12px; color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">
                        Tipo de Ingreso</th>
                    <th
                        style="padding: 12px; color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">
                        Detalles</th>
                    <th
                        style="padding: 12px; color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">
                        Total Ingreso</th>
                    <th
                        style="padding: 12px; color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">
                        Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:40px; color:var(--text-muted);">
                            <i class="fas fa-search"
                                style="font-size:2rem; opacity:.2; display:block; margin-bottom:8px;"></i>
                            No se encontraron ingresos con esos filtros.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history_paginated as $row): ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 12px;"><strong><?= date('d/m/Y', strtotime($row['fecha'])) ?></strong></td>
                            <td style="padding: 12px;"><?= $row['tipo'] ?></td>
                            <td style="padding: 12px; font-size: 0.85rem; color: var(--text-muted);">
                                <?php if ($row['categoria'] === 'leche'): ?>
                                    <?= number_format($row['litros'], 1) ?> L × $<?= number_format($row['precio'] ?? 0, 2) ?><br>
                                <?php elseif ($row['categoria'] === 'carne' && $row['tipo_raw'] === 'venta_carne'): ?>
                                    <?php if ($row['kg_vivo'] > 0): ?>
                                        <?= number_format($row['kg_vivo'], 1) ?> kg ×
                                        $<?= number_format($row['precio_kg'] ?? 0, 2) ?><br>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?= htmlspecialchars($row['notas'] ?? '') ?>
                            </td>
                            <td style="padding: 12px; font-weight:700; color:var(--accent);">
                                $<?= number_format($row['total'], 2, ',', '.') ?></td>
                            <td style="padding: 12px;">
                                <button type="button" class="btn"
                                    style="color: var(--accent); background: transparent; padding: 4px;"
                                    onclick='editarIngreso(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    title="Editar"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display: inline;" onsubmit="if(!confirm('¿Eliminar registro?')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action"
                                        value="<?= $row['categoria'] === 'leche' ? 'delete_produccion' : 'delete_carne' ?>">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <button type="submit" class="btn"
                                        style="color: var(--danger); background: transparent; padding: 4px;" title="Eliminar"><i
                                            class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <div style="display:flex; justify-content: center; gap:10px; margin:20px 0; padding-bottom:10px;">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&mes=<?= urlencode($mes_sel) ?>&tipo_historial=<?= urlencode($filtro_tipo) ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;"><i class="fas fa-chevron-left"></i> Anterior</a>
        <?php endif; ?>
        
        <span style="color:var(--text-muted); align-self:center; font-size:0.9rem;">Página <?= $page ?> de <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&mes=<?= urlencode($mes_sel) ?>&tipo_historial=<?= urlencode($filtro_tipo) ?>" class="btn" style="background:rgba(255,255,255,0.05); color:white; padding:8px 16px;">Siguiente <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
    function switchTab(tab, btn) {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + tab).classList.add('active');
        btn.classList.add('active');
    }

    function toggleNotasLeche() {
        const val = document.getElementById('tipoLeche').value;
        const container = document.getElementById('notasLecheContainer');
        const input = document.getElementById('notasLeche');
        if (val === 'otra') {
            container.style.display = 'flex';
            input.required = true;
        } else {
            container.style.display = 'none';
            input.required = false;
            input.value = '';
        }
    }

    function unformatVal(val) {
        if (!val) return 0;
        let clean = val.toString().replace(/\./g, '').replace(',', '.');
        return parseFloat(clean) || 0;
    }

    function formatVal(val) {
        if (!val && val !== 0) return '';
        let parts = val.toString().split('.');
        if (parts[0]) parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return parts.join(',');
    }

    function calcPromedioCarne() {
        const total = unformatVal(document.getElementById('carneMontoTotal').value) || 0;
        const promedio = total / 12;
        document.getElementById('carneMontoPromedio').textContent = '$' + formatVal(promedio.toFixed(2));
    }

    function calcTotalCarne() {
        const cant = unformatVal(document.getElementById('carneCantidad').value) || 1;
        const kg = unformatVal(document.getElementById('carneKgVivo').value) || 0;
        const precio = unformatVal(document.getElementById('carnePrecioKg').value) || 0;
        if (kg > 0 && precio > 0) {
            // Cálculo: Cantidad * Kg * Precio
            const total = (cant * kg * precio);
            document.getElementById('carneMontoTotal').value = formatVal(total.toFixed(2));
            calcPromedioCarne();
        }
    }

    document.querySelectorAll('.format-number').forEach(input => {
        input.addEventListener('input', function(e) {
            let cursor = this.selectionStart || 0;
            let oldLength = this.value.length;
            
            let val = this.value.replace(/[^0-9,]/g, '');
            let parts = val.split(',');
            if (parts.length > 2) parts = [parts[0], parts.slice(1).join('')];
            if (parts[0]) parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            let newVal = parts.join(',');
            
            this.value = newVal;
            
            let newLength = newVal.length;
            cursor += (newLength - oldLength);
            if (cursor < 0) cursor = 0;
            try { this.setSelectionRange(cursor, cursor); } catch(err) {}
        });
    });

    function toggleCategoriaCarne() {
        const val = document.getElementById('tipoCarneSelect').value;
        const container = document.getElementById('categoriaAnimalContainer');
        if (val === 'venta_carne') {
            container.style.display = 'flex';
        } else {
            container.style.display = 'none';
            document.getElementById('categoriaAnimalSelect').value = 'otro';
        }
    }

    function calcPeriodosDif() {
        const vAct = document.getElementById('difMesAct').value;
        const vAnt = document.getElementById('difMesAnt').value;
        
        if (vAct && vAnt) {
            const dAct = new Date(vAct + '-01T00:00:00');
            const dAnt = new Date(vAnt + '-01T00:00:00');
            
            // Diferencia en meses
            let months = (dAct.getFullYear() - dAnt.getFullYear()) * 12;
            months -= dAnt.getMonth();
            months += dAct.getMonth();
            
            // Validar que sea positivo
            if (months > 0) {
                document.getElementById('difPeriodos').value = months;
            } else {
                document.getElementById('difPeriodos').value = 1;
            }
        }
        calcGranTotalDif();
    }

    function calcFilaDif(input) {
        const row = input.closest('tr');
        const ca = parseFloat(row.querySelector('.dif-ca').value) || 0;
        const cb = parseFloat(row.querySelector('.dif-cb').value) || 0;
        const vu = unformatVal(row.querySelector('.dif-vu').value) || 0;
        const dif = ca - cb;
        const vt = dif * vu;

        const tdDif = row.querySelector('.dif-dif');
        tdDif.textContent = dif > 0 ? '+' + dif : dif;
        tdDif.style.color = dif > 0 ? '#34d399' : (dif < 0 ? '#f87171' : 'inherit');

        const tdVt = row.querySelector('.dif-vt');
        tdVt.textContent = (vt >= 0 ? '+' : '') + '$' + formatVal(Math.abs(vt).toFixed(2));
        tdVt.style.color = vt > 0 ? '#34d399' : (vt < 0 ? '#f87171' : 'inherit');

        calcGranTotalDif();
    }

    function calcGranTotalDif() {
        let total = 0;
        document.querySelectorAll('.dif-row').forEach(row => {
            const ca = parseFloat(row.querySelector('.dif-ca').value) || 0;
            const cb = parseFloat(row.querySelector('.dif-cb').value) || 0;
            const vu = unformatVal(row.querySelector('.dif-vu').value) || 0;
            total += (ca - cb) * vu;
        });
        const el = document.getElementById('difGranTotal');
        el.textContent = (total >= 0 ? '+' : '') + '$' + formatVal(Math.abs(total).toFixed(2));
        el.style.color = total >= 0 ? '#34d399' : '#f87171';
        
        let periodos = parseInt(document.getElementById('difPeriodos').value) || 1;
        if (periodos < 1) periodos = 1;
        let prom = total / periodos;
        
        const elProm = document.getElementById('difPromedioMensual');
        elProm.textContent = (prom >= 0 ? '+' : '') + '$' + formatVal(Math.abs(prom).toFixed(2));
        elProm.style.color = prom >= 0 ? '#34d399' : '#f87171';
    }

    function editarIngreso(row) {
        let mesStr = row.fecha ? row.fecha.substring(0, 7) : '';
        if (row.categoria === 'leche') {
            switchTab('leche', document.querySelector('.tab-btn:nth-child(1)'));
            const form = document.querySelector('#tab-leche form');
            form.querySelector('[name="id"]').value = row.id;
            form.querySelector('[name="fecha"]').value = mesStr;
            form.querySelector('[name="destino"]').value = row.tipo_raw;
            form.querySelector('[name="litros"]').value = formatVal(row.litros);
            form.querySelector('[name="precio_litro"]').value = formatVal(row.precio) || '';
            form.querySelector('[name="notas"]').value = row.notas || '';
            toggleNotasLeche();
        } else if (row.tipo_raw === 'diferencia_inventario') {
            switchTab('dif-inventario', document.querySelector('.tab-btn:nth-child(3)'));
            const form = document.querySelector('#formDifInventario');
            form.querySelector('[name="id"]').value = row.id;
            form.querySelector('[name="fecha_dif"]').value = mesStr;
            form.querySelector('[name="notas_dif"]').value = row.notas || '';
            
            // Limpiar valores por defecto
            document.querySelectorAll('.dif-ca, .dif-cb').forEach(el => el.value = '0');
            document.querySelectorAll('.dif-vu').forEach(el => el.value = '');
            
            if (row.detalles_dif && row.detalles_dif.length > 0) {
                // Meses del primer detalle
                form.querySelector('[name="mes_label_act"]').value = row.detalles_dif[0].mes_label_act || '';
                form.querySelector('[name="mes_label_ant"]').value = row.detalles_dif[0].mes_label_ant || '';
                
                if (row.cantidad_animales) {
                    form.querySelector('[name="cant_periodos"]').value = row.cantidad_animales;
                } else {
                    calcPeriodosDif();
                }
                
                row.detalles_dif.forEach(det => {
                    const tr = form.querySelector(`.dif-row[data-cat="${det.categoria}"]`);
                    if (tr) {
                        tr.querySelector('.dif-ca').value = det.cant_actual;
                        tr.querySelector('.dif-cb').value = det.cant_anterior;
                        tr.querySelector('.dif-vu').value = formatVal(det.valor_unitario);
                        calcFilaDif(tr.querySelector('.dif-ca'));
                    }
                });
            }
        } else {
            switchTab('carne', document.querySelector('.tab-btn:nth-child(2)'));
            const form = document.querySelector('#tab-carne form');
            form.querySelector('[name="id"]').value = row.id;
            form.querySelector('[name="fecha_carne"]').value = mesStr;
            form.querySelector('[name="tipo_carne"]').value = row.tipo_raw;

            const catSelect = form.querySelector('[name="categoria_animal"]');
            if (catSelect) catSelect.value = row.categoria_animal || 'otro';

            const cantInput = form.querySelector('[name="cantidad_animales"]');
            if (cantInput) cantInput.value = formatVal(row.cantidad_animales) || '';

            const kgInput = form.querySelector('[name="kg_vivo"]');
            if (kgInput) kgInput.value = formatVal(row.kg_vivo) || '';

            const precioInput = form.querySelector('[name="precio_kg"]');
            if (precioInput) precioInput.value = formatVal(row.precio_kg) || '';

            const valTotal = row.monto_original !== null ? row.monto_original : row.total;
            form.querySelector('[name="monto_carne"]').value = formatVal(valTotal);
            calcPromedioCarne();
            
            form.querySelector('[name="notas_carne"]').value = row.notas || '';

            toggleCategoriaCarne();
        }
        // Scroll hacia el form
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }


</script>

<?php require_once 'includes/footer.php'; ?>