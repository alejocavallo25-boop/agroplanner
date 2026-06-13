<?php
require_once 'config/auth.php';
require_once 'config/database.php';
require_tambo();
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Panel General';

validate_csrf();

// Crear tabla histórica si no existe (MySQL)
$pdo->exec("CREATE TABLE IF NOT EXISTS `tambo_dolar_mes` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `usuario_id` INT NOT NULL,
    `mes` VARCHAR(7) NOT NULL,
    `dolar_mayorista` DECIMAL(12,4) NOT NULL,
    `fuente` ENUM('api','manual') NOT NULL DEFAULT 'api',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_usuario_mes` (`usuario_id`,`mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Filtro mensual
$mes_sel       = $_GET['mes'] ?? date('Y-m');
$mes_start     = $mes_sel . '-01';
$mes_end       = date('Y-m-t', strtotime($mes_start));
$es_mes_actual = ($mes_sel === date('Y-m'));

// POST: guardar tipo de cambio manual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_dolar') {
    $tc = (float) str_replace(',', '.', $_POST['dolar_manual'] ?? 0);
    if ($tc > 0) {
        $pdo->prepare("INSERT INTO tambo_dolar_mes (usuario_id,mes,dolar_mayorista,fuente)
            VALUES (?,?,?,'manual')
            ON DUPLICATE KEY UPDATE dolar_mayorista=VALUES(dolar_mayorista),fuente='manual'")
            ->execute([$usuario_id, $mes_sel, $tc]);
    }
    set_flash('success', 'Tipo de cambio guardado correctamente para ' . date('F Y', strtotime($mes_sel . '-01')) . '.');
    header("Location: tambo.php?mes={$mes_sel}"); exit;
}

// Obtener tipo de cambio
$dolar_cache    = 1000;
$dolar_fuente   = 'api';
$dolar_sin_dato = false;
$dolar_guardado = null;

$stmt = $pdo->prepare("SELECT dolar_mayorista, fuente FROM tambo_dolar_mes WHERE usuario_id=? AND mes=?");
$stmt->execute([$usuario_id, $mes_sel]);
$tc_db = $stmt->fetch();
if ($tc_db) {
    $dolar_guardado = (float)$tc_db['dolar_mayorista'];
    $dolar_fuente   = $tc_db['fuente'];
}

// API en vivo
$dolar_live = null;
$ctx = stream_context_create(['http'=>['timeout'=>2],'https'=>['timeout'=>2]]);
$api_resp = @json_decode(@file_get_contents('https://dolarapi.com/v1/dolares/mayorista', false, $ctx), true);
if ($api_resp && isset($api_resp['venta'])) $dolar_live = (float)$api_resp['venta'];

if ($es_mes_actual) {
    $dolar_cache = $dolar_live ?? $dolar_guardado ?? 1000;
    if ($dolar_live) {
        $pdo->prepare("INSERT INTO tambo_dolar_mes (usuario_id,mes,dolar_mayorista,fuente)
            VALUES (?,?,?,'api')
            ON DUPLICATE KEY UPDATE dolar_mayorista=VALUES(dolar_mayorista),fuente='api'")
            ->execute([$usuario_id, $mes_sel, $dolar_live]);
        $dolar_guardado = $dolar_live;
    }
} else {
    if ($dolar_guardado) {
        $dolar_cache = $dolar_guardado;
    } else {
        $dolar_sin_dato = true;
        $dolar_cache    = $dolar_live ?? 1000;
        $dolar_fuente   = 'estimado';
    }
}

require_once 'includes/header.php';

// Producción Leche Mes
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN destino != 'otra' THEN litros_total ELSE 0 END) as litros, 
        SUM(CASE WHEN destino = 'otra' THEN litros_total ELSE 0 END) as litros_otra,
        SUM(CASE WHEN destino != 'otra' THEN litros_total * precio_litro ELSE 0 END) as ingreso_ars, 
        SUM(CASE WHEN destino = 'otra' THEN litros_total * precio_litro ELSE 0 END) as ingreso_otra_leche_ars,
        MAX(precio_litro) as ultimo_precio
    FROM tambo_produccion 
    WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?
");
$stmt->execute([$usuario_id, $mes_start, $mes_end]);
$prod_mes = $stmt->fetch();
$litros_mes = (float)$prod_mes['litros'];
$litros_otra_leche = (float)$prod_mes['litros_otra'];
$ingreso_leche_ars = (float)$prod_mes['ingreso_ars'];
$ingreso_otra_leche_ars = (float)$prod_mes['ingreso_otra_leche_ars'];
$ingreso_leche_usd = $dolar_cache > 0 ? ($ingreso_leche_ars + $ingreso_otra_leche_ars) / $dolar_cache : 0;
$precio_leche_ars = (float)$prod_mes['ultimo_precio'];

// Producción Carne Mes
$stmt = $pdo->prepare("
    SELECT SUM(monto_total) as total
    FROM tambo_ventas_carne
    WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?
");
$stmt->execute([$usuario_id, $mes_start, $mes_end]);
$carne_res = $stmt->fetch();
$ingreso_carne_ars = (float)$carne_res['total'];
$ingreso_carne_usd = $dolar_cache > 0 ? $ingreso_carne_ars / $dolar_cache : 0; 

// Ingresos Totales
$total_ingresos_usd = $ingreso_leche_usd + $ingreso_carne_usd;
$pct_leche = $total_ingresos_usd > 0 ? ($ingreso_leche_usd / $total_ingresos_usd) * 100 : 0;
$pct_carne = $total_ingresos_usd > 0 ? ($ingreso_carne_usd / $total_ingresos_usd) * 100 : 0;

// Costos Egresos Mes
$stmt = $pdo->prepare("
    SELECT categoria, moneda, SUM(monto) as total
    FROM tambo_egresos
    WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?
    GROUP BY categoria, moneda
");
$stmt->execute([$usuario_id, $mes_start, $mes_end]);
$egresos_res = $stmt->fetchAll();

$costos_usd = 0;
$costos_ars_total = 0;
$ranking_costos = [];

foreach ($egresos_res as $egr) {
    $monto_usd = $egr['moneda'] === 'USD' ? (float)$egr['total'] : ($dolar_cache > 0 ? (float)$egr['total'] / $dolar_cache : 0);
    $costos_usd += $monto_usd;
    
    $monto_ars = $egr['moneda'] === 'ARS' ? (float)$egr['total'] : (float)$egr['total'] * $dolar_cache;
    $costos_ars_total += $monto_ars;
    
    $cat = $egr['categoria'];
    if (!isset($ranking_costos[$cat])) {
        $ranking_costos[$cat] = 0;
    }
    $ranking_costos[$cat] += $monto_usd;
}
arsort($ranking_costos);

// Rentabilidad
$total_ingresos_ars = $ingreso_leche_ars + $ingreso_otra_leche_ars + $ingreso_carne_ars;
$margen_bruto_usd = $total_ingresos_usd - $costos_usd;
$margen_bruto_ars = $total_ingresos_ars - $costos_ars_total;
$usd_litro = $litros_mes > 0 ? $margen_bruto_usd / $litros_mes : 0;
$ars_litro = $litros_mes > 0 ? $margen_bruto_ars / $litros_mes : 0;
$pct_margen = $total_ingresos_usd > 0 ? ($margen_bruto_usd / $total_ingresos_usd) * 100 : 0;

// Rodeo Actual
$stmt = $pdo->prepare("SELECT * FROM tambo_rodeo WHERE usuario_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 1");
$stmt->execute([$usuario_id, $mes_end]);
$rodeo_db = $stmt->fetch();
$rodeo = [
    'vacas_ordeñe' => (int)($rodeo_db['vacas_ordene']  ?? 0),
    'vacas_secas'  => (int)($rodeo_db['vacas_secas']   ?? 0),
    'vaquillonas'  => (int)($rodeo_db['vaquillonas']   ?? 0),
    'terneros'     => (int)($rodeo_db['terneros']       ?? 0),
];
$total_cabezas = array_sum($rodeo);

// Calidad
$stmt = $pdo->prepare("SELECT * FROM tambo_calidad WHERE usuario_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 1");
$stmt->execute([$usuario_id, $mes_end]);
$cal_db = $stmt->fetch();
$grasa = (float)($cal_db['tenor_graso'] ?? 0);
$prot  = (float)($cal_db['tenor_prot']  ?? 0);

// Historial de producción (Últimos 12 meses)
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(fecha, '%Y-%m') as mes_str,
        SUM(litros_total) as total_litros
    FROM tambo_produccion
    WHERE usuario_id = ? AND fecha >= DATE_SUB(?, INTERVAL 11 MONTH) AND fecha <= ?
    GROUP BY mes_str
    ORDER BY mes_str ASC
");
$stmt->execute([$usuario_id, $mes_start, $mes_end]);
$historial_prod = $stmt->fetchAll(PDO::FETCH_ASSOC);

$meses_es = ['01'=>'Ene', '02'=>'Feb', '03'=>'Mar', '04'=>'Abr', '05'=>'May', '06'=>'Jun', '07'=>'Jul', '08'=>'Ago', '09'=>'Sep', '10'=>'Oct', '11'=>'Nov', '12'=>'Dic'];
$labels_prod = [];
$data_prod = [];

// Rellenar 12 meses hacia atrás desde el mes seleccionado
$current_time = strtotime($mes_start);
$months_data = [];
for ($i = 11; $i >= 0; $i--) {
    $t = strtotime("-$i months", $current_time);
    $k = date('Y-m', $t);
    $months_data[$k] = 0;
}

// Llenar con datos reales
foreach ($historial_prod as $row) {
    if (isset($months_data[$row['mes_str']])) {
        $months_data[$row['mes_str']] = (float)$row['total_litros'];
    }
}

// Preparar arrays finales para JS
foreach ($months_data as $mes_str => $litros) {
    $partes = explode('-', $mes_str);
    $m = $partes[1];
    $y = substr($partes[0], 2, 2);
    $labels_prod[] = $meses_es[$m] . ' ' . $y;
    $data_prod[] = $litros;
}

// Datos para gráfico de costos
$labels_costos = array_keys($ranking_costos);
$data_costos = array_values($ranking_costos);
?>

<!-- Chart.js -->
<style>
.tambo-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.tambo-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}
.kpi-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 20px;
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
    overflow: hidden;
    min-width: 0;
    z-index: 1;
}
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    z-index: 2;
}
.kpi-card.green::before  { background: linear-gradient(90deg, #10b981, #34d399); }
.kpi-card.blue::before   { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.kpi-card.amber::before  { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.kpi-card.rose::before   { background: linear-gradient(90deg, #f43f5e, #fb7185); }

.kpi-card:hover { 
    transform: translateY(-8px); 
    border-color: rgba(255,255,255,0.2);
    box-shadow: 0 15px 35px -10px rgba(0,0,0,0.5);
}

.kpi-card .icon-bg {
    position: absolute;
    bottom: -15px;
    right: -10px;
    font-size: 5.5rem;
    opacity: 0.04;
    color: white;
    transition: all 0.4s;
    z-index: -1;
}

.kpi-card:hover .icon-bg {
    transform: scale(1.15) rotate(-10deg);
    opacity: 0.08;
}

.kpi-icon {
    width: 48px; height: 48px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    margin-bottom: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.kpi-icon.green { background: rgba(16,185,129,0.15); color: #10b981; }
.kpi-icon.blue  { background: rgba(59,130,246,0.15); color: #60a5fa; }
.kpi-icon.amber { background: rgba(245,158,11,0.15); color: #fbbf24; }
.kpi-icon.rose  { background: rgba(244,63,94,0.15);  color: #fb7185; }

.kpi-label { 
    font-size: 0.75rem; 
    color: var(--text-muted); 
    text-transform: uppercase; 
    letter-spacing: 0.08em; 
    font-weight: 700; 
    white-space: nowrap; 
}
.kpi-value {
    font-size: clamp(1.4rem, 2.5vw, 2rem);
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.1;
    letter-spacing: -0.02em;
}
.kpi-sub { font-size: 0.8rem; color: var(--text-muted); font-weight: 500; }
.kpi-badge { 
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.72rem; font-weight: 700; padding: 4px 10px; border-radius: 20px;
}
.badge-up   { background: rgba(16,185,129,0.12); color: #34d399; border: 1px solid rgba(16,185,129,0.2); }
.badge-down { background: rgba(239,68,68,0.12);  color: #f87171; border: 1px solid rgba(239,68,68,0.2); }

.chart-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px;
    padding: 24px;
}
.chart-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.chart-title i { color: var(--accent); }

.rodeo-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 16px;
}
.rodeo-item {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.rodeo-item-label { font-size: 0.78rem; color: var(--text-muted); }
.rodeo-item-value { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); }

.calidad-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.calidad-row:last-child { border-bottom: none; }
.calidad-label { font-size: 0.9rem; color: var(--text-muted); }
.calidad-val   { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }

.section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.section-header h2 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}
.section-header i { color: var(--accent); }

@media (max-width: 900px) {
    .tambo-grid-4 { grid-template-columns: 1fr 1fr; }
    .tambo-grid-2 { grid-template-columns: 1fr; }
}
@media (max-width: 540px) {
    .tambo-grid-4 { grid-template-columns: 1fr; }
    .rodeo-grid   { grid-template-columns: 1fr 1fr; }
}
</style>

<!-- ── Banner bienvenida ───────────────────────────────────────────────── -->


<?php if ($dolar_sin_dato): ?>
<div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:12px;padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div style="display:flex;align-items:center;gap:10px;">
        <i class="fas fa-triangle-exclamation" style="color:#fbbf24;font-size:1.1rem;"></i>
        <div>
            <div style="font-weight:700;color:#fbbf24;font-size:0.9rem;">Sin tipo de cambio histórico para <?= date('F Y', strtotime($mes_start)) ?></div>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;">Los cálculos en USD usan el dólar de hoy ($<?= number_format($dolar_cache,2) ?>). Ingresá el TC real de ese mes para cerrar correctamente.</div>
        </div>
    </div>
    <button onclick="document.getElementById('formTCPanel').style.display=document.getElementById('formTCPanel').style.display==='none'?'flex':'none'" style="background:rgba(245,158,11,0.15);border:1px solid rgba(245,158,11,0.35);color:#fbbf24;border-radius:8px;padding:7px 14px;font-size:0.83rem;font-weight:600;cursor:pointer;white-space:nowrap;">
        <i class="fas fa-lock"></i> Fijar TC del mes
    </button>
</div>
<?php endif; ?>

<div style="background: linear-gradient(135deg, rgba(16,185,129,0.08), rgba(6,95,70,0.12)); border: 1px solid rgba(16,185,129,0.2); border-radius: 16px; padding: 20px 24px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <div style="font-size: 0.8rem; color: var(--accent); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 4px;">
            <i class="fas fa-cow"></i> &nbsp;Módulo Tambo
        </div>
        <h2 style="margin: 0; font-size: 1.35rem;">Panel General</h2>
    </div>
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap:wrap;">
        <div style="display:flex; background:rgba(255,255,255,0.1); border-radius:8px; overflow:hidden; border: 1px solid rgba(255,255,255,0.2);">
            <button onclick="toggleAnalisis('ars')" id="btn-ars" style="background:var(--accent); color:white; border:none; padding:6px 14px; font-weight:600; font-size:0.85rem; cursor:pointer;">ARS</button>
            <button onclick="toggleAnalisis('usd')" id="btn-usd" style="background:transparent; color:var(--text-muted); border:none; padding:6px 14px; font-weight:600; font-size:0.85rem; cursor:pointer;">USD</button>
        </div>
        <input type="month" value="<?= $mes_sel ?>" onchange="location.href='tambo.php?mes='+this.value" style="padding: 8px 14px; border-radius: 20px; border: 1px solid var(--accent); background: rgba(16,185,129,0.1); color: white; cursor: pointer; font-weight: 500;">
        <!-- Indicador TC -->
        <div onclick="document.getElementById('formTCPanel').style.display=document.getElementById('formTCPanel').style.display==='none'?'flex':'none'"
             style="display:flex;align-items:center;gap:6px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:20px;padding:6px 12px;cursor:pointer;transition:all .2s;"
             title="Tipo de cambio — click para editar">
            <i class="fas fa-dollar-sign" style="font-size:0.75rem;color:<?= $dolar_sin_dato ? '#fbbf24' : ($dolar_fuente==='manual' ? '#a78bfa' : '#34d399') ?>;"></i>
            <span style="font-size:0.8rem;font-weight:700;color:var(--text-primary);">$<?= number_format($dolar_cache,0) ?></span>
            <span style="font-size:0.68rem;color:var(--text-muted);"><?= $dolar_sin_dato ? 'estimado' : ($dolar_fuente==='manual' ? 'manual' : 'api') ?></span>
            <i class="fas fa-pen" style="font-size:0.65rem;color:var(--text-muted);"></i>
        </div>
    </div>
</div>

<!-- Widget edición TC -->
<div id="formTCPanel" style="display:none; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:12px; padding:16px 20px; margin-bottom:16px; align-items:center; flex-wrap:wrap; gap:12px;">
    <i class="fas fa-lock-open" style="color:#a78bfa;font-size:1rem;"></i>
    <div style="flex:1;min-width:220px;">
        <div style="font-size:0.83rem;font-weight:700;color:var(--text-primary);margin-bottom:2px;">Tipo de cambio para <?= date('F Y', strtotime($mes_start)) ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);">Fijá el dólar mayorista de ese mes para que los cálculos en USD sean históricos y no se recalculen.</div>
    </div>
    <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save_dolar">
        <input type="number" name="dolar_manual" step="0.01" min="1"
               value="<?= $dolar_guardado ? number_format($dolar_guardado,2,'.','') : '' ?>"
               placeholder="Ej: <?= number_format($dolar_cache,2) ?>"
               style="padding:8px 12px;border-radius:8px;border:1px solid rgba(167,139,250,0.4);background:rgba(167,139,250,0.08);color:white;width:150px;font-size:0.9rem;">
        <button type="submit" style="background:#a78bfa;color:white;border:none;border-radius:8px;padding:8px 16px;font-weight:700;font-size:0.85rem;cursor:pointer;">
            <i class="fas fa-save"></i> Guardar TC
        </button>
        <button type="button" onclick="document.getElementById('formTCPanel').style.display='none'" style="background:rgba(255,255,255,0.08);color:var(--text-muted);border:none;border-radius:8px;padding:8px 12px;font-size:0.85rem;cursor:pointer;">Cancelar</button>
    </form>
</div>

<!-- ── KPIs Principales ──────────────────────────────────────────────────── -->
<div class="tambo-grid-4">

    <!-- Card 1: Precios -->
    <div class="kpi-card blue">
        <i class="fas fa-tags icon-bg"></i>
        <div class="kpi-icon blue"><i class="fas fa-tag"></i></div>
        <span class="kpi-label">Precios de Referencia</span>
        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 5px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.85rem; color:var(--text-muted);">Leche:</span>
                <strong style="color: white; font-size:1.1rem;">$<?= number_format($precio_leche_ars, 2) ?></strong>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.85rem; color:var(--text-muted);">Dólar BNA:</span>
                <strong style="color: white; font-size:1.1rem;">$<?= number_format($dolar_cache, 0) ?></strong>
            </div>
            <?php
                $tc_color = $dolar_sin_dato ? '#fbbf24' : ($dolar_fuente==='manual' ? '#a78bfa' : '#34d399');
                $tc_label = $dolar_sin_dato ? 'Estimado' : ($dolar_fuente==='manual' ? 'Manual' : 'En vivo');
                $tc_icon  = $dolar_sin_dato ? 'fa-triangle-exclamation' : ($dolar_fuente==='manual' ? 'fa-user-pen' : 'fa-bolt');
            ?>
            <div style="display:flex; align-items:center; gap:6px; font-size:0.7rem; font-weight:700; color:<?= $tc_color ?>; margin-top:4px;">
                <i class="fas <?= $tc_icon ?>"></i> <?= $tc_label ?>
            </div>
        </div>
    </div>

    <!-- Card 2: Rentabilidad -->
    <div class="kpi-card green">
        <i class="fas fa-chart-line icon-bg"></i>
        <div class="kpi-icon green"><i class="fas fa-sack-dollar"></i></div>
        <span class="kpi-label">Margen Bruto Mes</span>
        <span class="kpi-value">
            <span class="val-ars">$ <?= number_format($margen_bruto_ars, 0, ',', '.') ?></span>
            <span class="val-usd" style="display:none;">U$S <?= number_format($margen_bruto_usd, 0) ?></span>
        </span>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:auto;">
            <span class="kpi-sub">Rent.: <strong style="color:var(--accent);"><?= number_format($pct_margen, 1) ?>%</strong></span>
            <span class="kpi-badge <?= $usd_litro >= 0 ? 'badge-up' : 'badge-down' ?>">
                <span class="val-ars">$ <?= number_format($ars_litro, 2, ',', '.') ?>/L</span>
                <span class="val-usd" style="display:none;">U$S <?= number_format($usd_litro, 3) ?>/L</span>
            </span>
        </div>
    </div>

    <!-- Card 3: Ingresos -->
    <div class="kpi-card amber">
        <i class="fas fa-coins icon-bg"></i>
        <div class="kpi-icon amber"><i class="fas fa-money-bill-trend-up"></i></div>
        <span class="kpi-label">Ingresos Totales</span>
        <span class="kpi-value">
            <span class="val-ars">$ <?= number_format($total_ingresos_ars, 0, ',', '.') ?></span>
            <span class="val-usd" style="display:none;">U$S <?= number_format($total_ingresos_usd, 0) ?></span>
        </span>
        <div style="display:flex; flex-direction:column; gap:8px; margin-top:auto;">
            <div style="display:flex; gap:10px; font-size: 0.75rem; font-weight: 600;">
                <span style="color:#fbbf24;">L: <?= number_format($pct_leche, 0) ?>%</span>
                <span style="color:#34d399;">C: <?= number_format($pct_carne, 0) ?>%</span>
            </div>
            <div style="display:flex; gap:6px;">
                <span class="kpi-badge badge-up" style="background:rgba(56,189,248,0.1); color:#38bdf8; border-color:rgba(56,189,248,0.2);">
                    <i class="fas fa-droplet"></i> <?= number_format($litros_mes, 0) ?> L
                </span>
            </div>
        </div>
    </div>

    <!-- Card 4: Costos -->
    <div class="kpi-card rose">
        <i class="fas fa-file-invoice-dollar icon-bg"></i>
        <div class="kpi-icon rose"><i class="fas fa-arrow-trend-down"></i></div>
        <span class="kpi-label">Costos Totales</span>
        <span class="kpi-value">
            <span class="val-ars">$ <?= number_format($costos_ars_total, 0, ',', '.') ?></span>
            <span class="val-usd" style="display:none;">U$S <?= number_format($costos_usd, 0) ?></span>
        </span>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:auto;">
            <span class="kpi-sub">Egresos registrados</span>
            <span class="kpi-badge badge-down">
                <i class="fas fa-receipt"></i> <?= count($egresos_res) ?> ítems
            </span>
        </div>
    </div>

</div>

<div class="tambo-grid-2">
    <!-- Análisis por Litro -->
    <div class="chart-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
            <h3 class="chart-title" style="margin-bottom:0;"><i class="fas fa-microscope"></i> Análisis por Litro</h3>
        </div>
        
        <?php if (empty($litros_mes)): ?>
            <div style="text-align: center; color: var(--text-muted); padding: 40px;">No hay registros de leche para calcular promedios.</div>
        <?php else: 
            // Cálculos
            $costo_bruto_ars = $costos_ars_total / $litros_mes;
            $recupero_carne_ars = $ingreso_carne_ars / $litros_mes;
            $recupero_otra_ars = $ingreso_otra_leche_ars / $litros_mes;
            $costo_final_ars = $costo_bruto_ars - $recupero_carne_ars - $recupero_otra_ars;
            
            $ingreso_leche_usd_per_lt = ($ingreso_leche_ars / $dolar_cache) / $litros_mes;
            $costo_bruto_usd = $costos_usd / $litros_mes;
            $recupero_carne_usd = $ingreso_carne_usd / $litros_mes;
            $recupero_otra_usd = ($ingreso_otra_leche_ars / $dolar_cache) / $litros_mes;
            $costo_final_usd = $costo_bruto_usd - $recupero_carne_usd - $recupero_otra_usd;
        ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <!-- Ingreso Leche -->
                <div style="background: rgba(16,185,129,0.03); border: 1px solid rgba(16,185,129,0.1); padding: 14px 18px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fas fa-droplet" style="color:#10b981; font-size: 1.1rem;"></i>
                        <span style="font-weight: 600; color: var(--text-primary); font-size: 0.95rem;">Ingreso por Leche</span>
                    </div>
                    <span style="font-weight: 800; color: #10b981; font-size: 1.15rem;">
                        <span class="val-ars">$ <?= number_format($ingreso_leche_ars / $litros_mes, 2, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($ingreso_leche_usd_per_lt, 3, ',', '.') ?></span>
                        <small style="font-weight:600; font-size:0.75rem; opacity: 0.7;">/L</small>
                    </span>
                </div>

                <!-- Costo Bruto -->
                <div style="background: rgba(244,63,94,0.03); border: 1px solid rgba(244,63,94,0.1); padding: 14px 18px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fas fa-arrow-trend-down" style="color:#fb7185; font-size: 1.1rem;"></i>
                        <span style="font-weight: 600; color: var(--text-primary); font-size: 0.95rem;">Costo Bruto</span>
                    </div>
                    <span style="font-weight: 800; color: #fb7185; font-size: 1.15rem;">
                        <span class="val-ars">$ <?= number_format($costo_bruto_ars, 2, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($costo_bruto_usd, 3, ',', '.') ?></span>
                        <small style="font-weight:600; font-size:0.75rem; opacity: 0.7;">/L</small>
                    </span>
                </div>

                <!-- Recuperos Row -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div style="background: rgba(245,158,11,0.03); border: 1px solid rgba(245,158,11,0.1); padding: 12px 14px; border-radius: 12px; display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Rec. Carne</span>
                        <span style="font-weight: 700; color: #fbbf24; font-size: 1.05rem;">
                            <span class="val-ars">$ <?= number_format($recupero_carne_ars, 2, ',', '.') ?></span>
                            <span class="val-usd" style="display:none;">U$S <?= number_format($recupero_carne_usd, 3, ',', '.') ?></span>
                        </span>
                    </div>
                    <div style="background: rgba(59,130,246,0.03); border: 1px solid rgba(59,130,246,0.1); padding: 12px 14px; border-radius: 12px; display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Rec. Otra L.</span>
                        <span style="font-weight: 700; color: #60a5fa; font-size: 1.05rem;">
                            <span class="val-ars">$ <?= number_format($recupero_otra_ars, 2, ',', '.') ?></span>
                            <span class="val-usd" style="display:none;">U$S <?= number_format($recupero_otra_usd, 3, ',', '.') ?></span>
                        </span>
                    </div>
                </div>

                <!-- Costo Final Highlight -->
                <div style="background: linear-gradient(90deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)); border: 1px solid rgba(255,255,255,0.15); padding: 16px 18px; border-radius: 14px; display: flex; align-items: center; justify-content: space-between; margin-top: 5px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width:36px; height:36px; border-radius:10px; background:rgba(255,255,255,0.1); color:white; display:flex; align-items:center; justify-content:center;"><i class="fas fa-check-double"></i></div>
                        <span style="font-weight: 700; color: #fff; font-size: 1.05rem;">Costo Final / Lt</span>
                    </div>
                    <span style="font-weight: 800; color: #fff; font-size: 1.35rem; letter-spacing: -0.02em;">
                        <span class="val-ars">$ <?= number_format($costo_final_ars, 2, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($costo_final_usd, 3, ',', '.') ?></span>
                    </span>
                </div>

                <!-- Rinde de Indiferencia -->
                <?php 
                    $rinde_indiferencia = $precio_leche_ars > 0 ? ($costo_final_ars * $litros_mes) / $precio_leche_ars : 0;
                ?>
                <div style="margin-top: 15px; background: rgba(245,158,11,0.08); border: 1px dashed rgba(245,158,11,0.3); padding: 14px 18px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; border-left: 4px solid #fbbf24;">
                    <div style="display: flex; flex-direction: column;">
                        <span style="font-size: 0.72rem; color: #fbbf24; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Rinde de Indiferencia</span>
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 500;">Producción para salir a raya</span>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-weight: 800; color: #fbbf24; font-size: 1.3rem;"><?= number_format($rinde_indiferencia, 0, ',', '.') ?> <small style="font-size:0.6em; opacity:0.8;">L</small></span>
                        <div style="font-size: 0.65rem; color: var(--text-muted); margin-top: 2px;">
                            <?php if ($litros_mes > 0): ?>
                                <?php $diff = $litros_mes - $rinde_indiferencia; ?>
                                <span style="color: <?= $diff >= 0 ? '#34d399' : '#fb7185' ?>; font-weight: 600;">
                                    <i class="fas <?= $diff >= 0 ? 'fa-caret-up' : 'fa-caret-down' ?>"></i>
                                    <?= number_format(abs($diff), 0, ',', '.') ?> L vs actual
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            function toggleAnalisis(mode) {
                const btnArs = document.getElementById('btn-ars');
                const btnUsd = document.getElementById('btn-usd');
                const valsArs = document.querySelectorAll('.val-ars');
                const valsUsd = document.querySelectorAll('.val-usd');
                
                if (mode === 'ars') {
                    btnArs.style.background = 'var(--accent)';
                    btnArs.style.color = 'white';
                    btnUsd.style.background = 'transparent';
                    btnUsd.style.color = 'var(--text-muted)';
                    valsArs.forEach(el => el.style.display = 'inline');
                    valsUsd.forEach(el => el.style.display = 'none');
                } else {
                    btnUsd.style.background = 'var(--accent)';
                    btnUsd.style.color = 'white';
                    btnArs.style.background = 'transparent';
                    btnArs.style.color = 'var(--text-muted)';
                    valsUsd.forEach(el => el.style.display = 'inline');
                    valsArs.forEach(el => el.style.display = 'none');
                }
            }
            </script>
        <?php endif; ?>
    </div>
    <!-- Ranking de Costos -->
    <div class="chart-card">
        <h3 class="chart-title"><i class="fas fa-chart-column"></i> Ranking de Costos</h3>
        <?php if (empty($ranking_costos)): ?>
            <div style="text-align: center; color: var(--text-muted); padding: 40px;">No hay egresos registrados este mes.</div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 5px;">
                <?php foreach($ranking_costos as $cat => $monto): 
                    $pct = $costos_usd > 0 ? ($monto / $costos_usd) * 100 : 0;
                ?>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); padding: 14px 18px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; transition: background 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='rgba(255,255,255,0.02)'">
                    <div style="display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 0;">
                        <span style="font-weight: 600; color: white; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($cat) ?></span>
                        <div style="width: 100%; max-width: 200px; height: 5px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden;">
                            <div style="height: 100%; width: <?= $pct ?>%; background: linear-gradient(90deg, var(--accent), #34d399); border-radius: 3px; box-shadow: 0 0 8px rgba(16,185,129,0.3);"></div>
                        </div>
                    </div>
                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex-shrink: 0; margin-left: 15px;">
                        <span style="font-weight: 800; color: white; font-size: 1.1rem;">U$S <?= number_format($monto, 0, ',', '.') ?></span>
                        <span style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700;"><?= number_format($pct, 1) ?>%</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Gráficos Visuales (Chart.js) -->
<div class="tambo-grid-2">
    <div class="chart-card">
        <h3 class="chart-title"><i class="fas fa-chart-line"></i> Evolución de Producción (12 meses)</h3>
        <div style="position: relative; height: 300px; width: 100%;">
            <?php if (empty($data_prod)): ?>
                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:var(--text-muted); font-size:0.9rem;">Sin datos suficientes</div>
            <?php endif; ?>
            <canvas id="prodChart"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <h3 class="chart-title"><i class="fas fa-chart-pie"></i> Distribución de Costos (U$S)</h3>
        <div style="position: relative; height: 300px; width: 100%; display: flex; justify-content: center;">
            <?php if (empty($data_costos)): ?>
                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:var(--text-muted); font-size:0.9rem;">Sin egresos este mes</div>
            <?php endif; ?>
            <canvas id="costosChart"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.color = '#9ca3af';
    Chart.defaults.font.family = 'Inter, sans-serif';

    // Gráfico de Producción
    const labelsProd = <?= json_encode($labels_prod) ?>;
    const dataProd = <?= json_encode($data_prod) ?>;
    if (dataProd.length > 0) {
        const ctxProd = document.getElementById('prodChart').getContext('2d');
        new Chart(ctxProd, {
            type: 'bar',
            data: {
                labels: labelsProd,
                datasets: [{
                    label: 'Litros de Leche',
                    data: dataProd,
                    backgroundColor: 'rgba(56, 189, 248, 0.8)',
                    borderColor: '#38bdf8',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { callback: function(val) { return val.toLocaleString('es-AR'); } }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Gráfico de Costos
    const labelsCostos = <?= json_encode($labels_costos) ?>;
    const dataCostos = <?= json_encode($data_costos) ?>;
    if (dataCostos.length > 0) {
        const ctxCostos = document.getElementById('costosChart').getContext('2d');
        new Chart(ctxCostos, {
            type: 'doughnut',
            data: {
                labels: labelsCostos,
                datasets: [{
                    data: dataCostos,
                    backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: window.innerWidth < 600 ? 'bottom' : 'right',
                        labels: { color: '#e5e7eb', padding: 15, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                let label = ctx.label || '';
                                if (label) { label += ': '; }
                                label += 'U$S ' + ctx.parsed.toLocaleString('es-AR');
                                return label;
                            }
                        }
                    }
                },
                cutout: '70%'
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
