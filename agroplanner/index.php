<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Tablero de Control Agrícola';

// Validación CSRF para todas las peticiones POST (ej: actualización de pizarra)
validate_csrf();

require_once 'controllers/DashboardController.php';

$controller = new DashboardController($pdo, $usuario_id);





// ── Precios SIO-Granos (última cotización disponible) ─────────────────────────
$sio_precios = [];
$sio_fecha   = null;
try {
    $sio_stmt = $pdo->query("
        SELECT cultivo, precio_promedio, precio_minimo, precio_maximo, fecha, zona
        FROM cotizaciones_siogranos
        WHERE fecha = (SELECT MAX(fecha) FROM cotizaciones_siogranos)
          AND cultivo IN ('Soja Cámara', 'Maíz', 'Trigo Cámara', 'Girasol Cámara', 'Sorgo')
        ORDER BY cultivo
    ");
    $sio_rows = $sio_stmt->fetchAll();
    foreach ($sio_rows as $row) {
        $sio_precios[$row['cultivo']] = $row;
        $sio_fecha = $row['fecha'];
    }
} catch (\Exception $e) {
    // Tabla aún no existe o sin datos — no mostramos sección SIO
}
$ciclos = $controller->getCiclos();
$ciclo_sel = $_GET['ciclo'] ?? ($ciclos[0] ?? null);

// Obtener datos globales limpiamente
$stats = $controller->getGlobalStats($ciclo_sel);
$ingresos_global = $stats['ingresos'];
$costos_directos_global = $stats['costos_directos'];
$costos_alquiler_global = $stats['costos_alquiler'];
$hectareas_ciclo = $stats['hectareas'];
$kg_total = $stats['kg'];
$margen_neto_global = $stats['margen_neto'];
$rendimiento_ha = $stats['rendimiento_ha'];

// Pestañas por Especie
$cultivos_data = $controller->getCultivosData($ciclo_sel);

// ── Datos para gráficos ───────────────────────────────────────────
// Dona: desglose de costos globales
$total_labores_g = 0; $total_insumos_g = 0;
foreach ($cultivos_data as $esp => $data) {
    foreach ($data['lotes'] as $lote) {
        $total_labores_g += $lote['labores'];
        $total_insumos_g += $lote['insumos'];
    }
}
$chart_dona = json_encode([
    'labores'  => round($total_labores_g, 2),
    'insumos'  => round($total_insumos_g, 2),
    'alquiler' => round($costos_alquiler_global, 2),
]);

// Barras: ingresos, costos y margen por lote (todos los lotes de todos los cultivos)
$chart_lotes_labels = [];
$chart_lotes_ing    = [];
$chart_lotes_cos    = [];
$chart_lotes_mar    = [];
$lotes_vistos = [];
foreach ($cultivos_data as $esp => $data) {
    foreach ($data['lotes'] as $lote) {
        $key = $lote['nombre'];
        if (!in_array($key, $lotes_vistos)) {
            $lotes_vistos[]       = $key;
            $chart_lotes_labels[] = $lote['nombre'];
            $chart_lotes_ing[]    = round($lote['ingreso'], 2);
            $costo_tot            = $lote['costo_dir'] + $lote['alquiler'];
            $chart_lotes_cos[]    = round($costo_tot, 2);
            $chart_lotes_mar[]    = round($lote['ingreso'] - $costo_tot, 2);
        }
    }
}
$chart_bars = json_encode([
    'labels'   => $chart_lotes_labels,
    'ingresos' => $chart_lotes_ing,
    'costos'   => $chart_lotes_cos,
    'margen'   => $chart_lotes_mar,
]);

require_once 'includes/header.php';
?>

<style>
    .tab-nav { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 10px; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
    .tab-nav::-webkit-scrollbar { display: none; }
    .tab-btn { padding: 8px 18px; border-radius: 20px; background: rgba(255,255,255,0.05); color: var(--text-muted); cursor: pointer; border: 1px solid transparent; white-space: nowrap; transition: all 0.3s; flex-shrink: 0; font-size: 0.9rem; }
    .tab-btn.active { background: var(--accent); color: white; }
    .cultivo-panel { display: none; }
    .cultivo-panel.active { display: block; }
    
    /* Lote Card Refined */
    .lote-card { 
        background: rgba(255,255,255,0.02); 
        border: 1px solid var(--border); 
        border-radius: 20px; 
        padding: 24px; 
        transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
        display: flex;
        flex-direction: column;
        position: relative;
        overflow: hidden;
    }
    .lote-card:hover { 
        transform: translateY(-8px); 
        background: rgba(255,255,255,0.05); 
        border-color: rgba(16, 185, 129, 0.4);
        box-shadow: 0 20px 40px -15px rgba(0,0,0,0.6);
    }
    .lote-card::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--accent);
        opacity: 0.3;
    }
    .lote-card:hover::after {
        opacity: 1;
    }
    
    .lote-detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255,255,255,0.04);
        font-size: 0.88rem;
    }
    .lote-detail-row:last-child { border-bottom: none; }
    .lote-detail-row .label { color: var(--text-muted); display: flex; align-items: center; gap: 10px; }
    .lote-detail-row .label i { width: 18px; text-align: center; opacity: 0.7; }
    .lote-detail-row .value { font-weight: 500; color: var(--text-primary); }

    @media (max-width: 768px) {
        .tab-btn { padding: 7px 14px; font-size: 0.82rem; }
        .lote-card { padding: 16px; }
    }
    
    /* Stat cards explicit styling */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
    }

    a.stat-card {
        position: relative;
        text-decoration: none !important;
        color: inherit !important;
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        overflow: hidden;
        z-index: 1;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    
    a.stat-card::before {
        content: "";
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.03) 0%, transparent 70%);
        opacity: 0;
        transition: opacity 0.4s;
        z-index: -1;
    }

    a.stat-card:hover::before {
        opacity: 1;
    }

    .stat-card .icon-bg {
        position: absolute;
        bottom: -15px;
        right: -10px;
        font-size: 5rem;
        opacity: 0.03;
        color: white;
        transition: all 0.4s;
    }

    a.stat-card:hover .icon-bg {
        transform: scale(1.1) rotate(-10deg);
        opacity: 0.06;
    }

    .stat-card .title {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--text-muted);
    }

    .stat-card .value {
        font-size: 1.85rem;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .stat-card .trend {
        color: var(--text-muted);
        font-size: 0.8rem;
        margin-top: auto;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: color 0.3s;
    }
    a.stat-card:hover .trend {
        color: var(--text-primary);
    }
    a.stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 16px 40px -4px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,0.06);
    }

    .currency-toggle-container {
        display: inline-flex;
        background: rgba(0, 0, 0, 0.2);
        padding: 4px;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.05);
    }
    .btn-currency {
        border: none;
        background: transparent;
        color: var(--text-muted);
        padding: 6px 14px;
        border-radius: 7px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .btn-currency:hover {
        color: var(--text-primary);
        background: rgba(255, 255, 255, 0.05);
    }
    .btn-currency.active {
        background: var(--accent);
        color: white !important;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
    }
</style>

<!-- Ticker de Mercado -->
<div class="glass-panel" style="margin-bottom: 24px; padding: 16px 20px;">
    <div class="ticker-bar">
        <div style="display:flex; align-items:center; gap:20px; flex-wrap: wrap; width: 100%;">

            <?php if (!empty($sio_precios)): ?>
            <!-- Precios SIO-Granos (ARS) -->
            <div style="display:flex; align-items:center; gap:10px; flex-wrap: wrap; width: 100%;">
                <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                    <span class="badge" style="background:rgba(16,185,129,0.1); color:var(--accent); border:1px solid var(--accent-glow); padding:4px 10px; font-size:0.7rem; white-space:nowrap;">
                        <i class="fas fa-chart-line"></i> MERCADO SIO-GRANOS
                    </span>
                    <?php if ($sio_fecha): ?>
                    <span style="font-size:0.7rem; color:var(--text-muted); white-space:nowrap;">
                        <i class="fas fa-clock" style="margin-right:3px;"></i>
                        <?= date('d/m/Y', strtotime($sio_fecha)) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <div id="sioTickerPrices" style="display:flex; align-items:center; gap:18px; flex-wrap:wrap; overflow-x:auto; padding-bottom:2px;">
                    <?php
                    $sio_orden = ['Soja Cámara','Maíz','Trigo Cámara','Girasol Cámara','Sorgo'];
                    $sio_iconos = [
                        'Soja Cámara'    => ['icon'=>'fa-seedling',  'color'=>'#10b981'],
                        'Maíz'           => ['icon'=>'fa-leaf',      'color'=>'#fbbf24'],
                        'Trigo Cámara'   => ['icon'=>'fa-wheat-awn', 'color'=>'#f59e0b'],
                        'Girasol Cámara' => ['icon'=>'fa-sun',       'color'=>'#fb923c'],
                        'Sorgo'          => ['icon'=>'fa-spa',       'color'=>'#a78bfa'],
                    ];
                    foreach ($sio_orden as $cultivo):
                        if (!isset($sio_precios[$cultivo])) continue;
                        $p = $sio_precios[$cultivo];
                        $ic = $sio_iconos[$cultivo] ?? ['icon'=>'fa-circle','color'=>'#94a3b8'];
                        $label_corto = str_replace([' Cámara'], [''], $cultivo);
                    ?>
                    <div style="display:flex; flex-direction:column; min-width:90px;">
                        <span style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; display:flex; align-items:center; gap:4px; white-space:nowrap;">
                            <i class="fas <?= $ic['icon'] ?>" style="color:<?= $ic['color'] ?>; font-size:0.75em;"></i>
                            <?= htmlspecialchars($label_corto) ?>
                        </span>
                        <span class="sio-price-container" style="font-size:1.05rem; font-weight:700; color:white; white-space:nowrap;">
                            <span class="sio-val" data-ars="<?= (float)$p['precio_promedio'] ?>">$<?= number_format((float)$p['precio_promedio'], 0, ',', '.') ?></span>
                            <small class="sio-unit" style="font-size:0.62rem; color:var(--text-muted); font-weight:400;">ARS/ton</small>
                        </span>
                        <span style="font-size:0.65rem; color:var(--text-muted);">
                            <span class="sio-minmax" data-min="<?= (float)$p['precio_minimo'] ?>" data-max="<?= (float)$p['precio_maximo'] ?>">
                                <?= number_format((float)$p['precio_minimo'],0,',','.') ?> – <?= number_format((float)$p['precio_maximo'],0,',','.') ?>
                            </span>
                        </span>
                    </div>
                    <?php if ($cultivo !== end($sio_orden) || true): // Always show divider for the dollar block ?>
                    <div style="width:1px; background:rgba(255,255,255,0.08); height:36px; flex-shrink:0;"></div>
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- Bloque Dólar Mayorista -->
                    <div style="display:flex; flex-direction:column; min-width:100px; border-left: 1px solid rgba(16,185,129,0.2); padding-left: 15px;">
                        <span style="font-size:0.68rem; color:var(--accent); text-transform:uppercase; font-weight:700; letter-spacing:.04em; display:flex; align-items:center; gap:4px; white-space:nowrap;">
                            <i class="fas fa-landmark" style="font-size:0.75em;"></i> DÓLAR BNA
                        </span>
                        <span style="font-size:1.05rem; font-weight:700; color:white; white-space:nowrap;">
                            <span id="dolar-ticker">...</span>
                            <small style="font-size:0.62rem; color:var(--text-muted); font-weight:400;">Mayorista</small>
                        </span>
                        <span style="font-size:0.65rem; color:var(--text-muted);">Cotización del día</span>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- Sin datos SIO-Granos disponibles -->
            <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap; width:100%;">
                <span class="badge" style="background:rgba(16,185,129,0.1); color:var(--accent); border:1px solid var(--accent-glow); padding:4px 10px; font-size:0.7rem;">
                    <i class="fas fa-chart-line"></i> MERCADO SIO-GRANOS
                </span>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Los precios de mercado no están disponibles en este momento.</span>
            </div>
            <?php endif; ?>

        </div>

        <div class="ticker-actions" style="flex-shrink:0; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <!-- Selector de Moneda -->
            <div class="currency-toggle-container">
                <button type="button" class="btn-currency active" id="btnCurrencyARS" onclick="setTickerCurrency('ARS')">ARS</button>
                <button type="button" class="btn-currency" id="btnCurrencyUSD" onclick="setTickerCurrency('USD')">USD</button>
            </div>            <!-- Filtro de Campaña -->
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="fas fa-filter" style="color:var(--text-muted); flex-shrink:0;"></i>
                <select onchange="location.href='index.php?ciclo=' + this.value" style="padding:8px 14px; border-radius:20px; border:1px solid var(--accent); background:rgba(16,185,129,0.1); color:white; cursor:pointer; font-weight:500; min-width:0; max-width:180px;">
                    <?php foreach($ciclos as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $c == $ciclo_sel ? 'selected' : '' ?>>Campaña <?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                    <?php if(empty($ciclos)): ?>
                        <option>Sin Campañas</option>
                    <?php endif; ?>
                </select>
            </div>
        </div>
    </div>
</div>



<?php if(!$ciclo_sel): ?>
    <div class="glass-panel" style="text-align: center; padding: 50px;">
        <i class="fas fa-seedling" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 20px;"></i>
        <h2>Comienza tu Planificación</h2>
        <p style="color: var(--text-muted);">Para ver el tablero, registra tu primer lote y crea una campaña/cultivo con su ciclo comercial.</p>
        <a href="lotes.php" class="btn btn-primary" style="margin-top: 20px; display: inline-block;">Ir a Lotes y Cultivos</a>
    </div>
<?php else: ?>

    <!-- Resumen Global del Ciclo (cards clicables) -->
    <div class="dashboard-grid" style="margin-bottom: 30px;">
        <a href="produccion.php" class="glass-panel stat-card" title="Ver Producción y Ventas">
            <i class="fas fa-hand-holding-dollar icon-bg"></i>
            <span class="title">Margen Neto Ciclo</span>
            <span class="value" style="<?= $margen_neto_global >= 0 ? 'color: var(--accent);' : 'color: var(--danger);' ?>">
                $<?= number_format($margen_neto_global, 2, ',', '.') ?>
            </span>
            <span class="trend">Neto tras Alquileres e Insumos <i class="fas fa-arrow-right"></i></span>
        </a>
        <a href="produccion.php" class="glass-panel stat-card" title="Ver Producción">
            <i class="fas fa-seedling icon-bg"></i>
            <span class="title">Rinde Promedio</span>
            <span class="value"><?= number_format($rendimiento_ha, 2, ',', '.') ?> <small style="font-size: 0.8rem; opacity: 0.7;">kg/Ha</small></span>
            <span class="trend">En <?= number_format($hectareas_ciclo, 1, ',', '.') ?> ha trabajadas <i class="fas fa-arrow-right"></i></span>
        </a>
        <a href="operaciones.php" class="glass-panel stat-card" title="Ver Costos y Labores">
            <i class="fas fa-tractor icon-bg"></i>
            <span class="title">Costos de Laboreo</span>
            <span class="value" style="color: #ffb152;">-$<?= number_format($costos_directos_global, 2, ',', '.') ?></span>
            <span class="trend">Insumos + Labores Directas <i class="fas fa-arrow-right"></i></span>
        </a>
        <a href="alquileres.php" class="glass-panel stat-card" style="border-top: 3px solid var(--danger);" title="Ver Alquileres">
            <i class="fas fa-file-invoice-dollar icon-bg"></i>
            <span class="title">Alquileres Pagados</span>
            <span class="value" style="color: #ff7b72;">-$<?= number_format($costos_alquiler_global, 2, ',', '.') ?></span>
            <span class="trend">Pagos reales registrados <i class="fas fa-arrow-right"></i></span>
        </a>
        <a href="operaciones.php" class="glass-panel stat-card" style="border-top: 3px solid #818cf8;" title="Ver Operaciones">
            <i class="fas fa-scale-balanced icon-bg"></i>
            <span class="title">Costo / ha</span>
            <?php if ($stats['costo_por_ha'] > 0): ?>
                <span class="value" style="color: #818cf8;">$<?= number_format($stats['costo_por_ha'], 2, ',', '.') ?></span>
                <span class="trend">Costo global por superficie <i class="fas fa-arrow-right"></i></span>
            <?php else: ?>
                <span class="value" style="color: var(--text-muted); font-size: 1.5rem;">—</span>
                <span class="trend">Sin hectáreas registradas <i class="fas fa-arrow-right"></i></span>
            <?php endif; ?>
        </a>
        <a href="produccion.php" class="glass-panel stat-card" style="border-top: 3px solid #f59e0b;" title="Punto de Equilibrio">
            <i class="fas fa-balance-scale icon-bg"></i>
            <span class="title">Rinde Indiferencia</span>
            <?php if ($stats['punto_equilibrio_kg_ha'] > 0): ?>
                <span class="value" style="color: #f59e0b;"><?= number_format($stats['punto_equilibrio_kg_ha'], 0, ',', '.') ?> <small style="font-size: 0.8rem; opacity: 0.7;">kg/Ha</small></span>
                <span class="trend">Para cubrir gastos globales <i class="fas fa-arrow-right"></i></span>
            <?php else: ?>
                <span class="value" style="color: var(--text-muted); font-size: 1.5rem;">—</span>
                <span class="trend">Requiere registrar ventas <i class="fas fa-arrow-right"></i></span>
            <?php endif; ?>
        </a>
    </div> <!-- /.dashboard-grid -->

    <!-- ===== GRÁFICOS ===== -->
    <div style="display:flex; flex-wrap: wrap; gap:20px; margin-bottom:28px;" id="chartsRow">

        <!-- Dona: Desglose de costos -->
        <div class="glass-panel" style="padding:20px; flex: 1 1 300px; min-width: 0;">
            <h3 style="font-size:0.95rem; font-weight:600; margin-bottom:14px; color:var(--text-muted);">
                <i class="fas fa-chart-pie" style="color:var(--accent); margin-right:6px;"></i>
                Desglose de Costos
            </h3>
            <div class="chart-container-dona" style="position:relative; max-width:220px; margin:0 auto;">
                <canvas id="chartDona" height="220"></canvas>
                <div id="donaCentro" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center; pointer-events:none;">
                    <div style="font-size:0.7rem; color:var(--text-muted);">Total</div>
                    <div id="donaCentroVal" class="dona-center-val" style="font-size:1.1rem; font-weight:800;">$0</div>
                </div>
            </div>
            <div id="donaLeyenda" style="margin-top:14px; display:flex; flex-direction:column; gap:6px; font-size:0.8rem;"></div>
        </div>

        <!-- Barras: Ingresos vs Costos por Lote -->
        <div class="glass-panel" style="padding:20px; flex: 2 1 450px; min-width: 0;">
            <h3 style="font-size:0.95rem; font-weight:600; margin-bottom:14px; color:var(--text-muted);">
                <i class="fas fa-chart-bar" style="color:#818cf8; margin-right:6px;"></i>
                Ingresos vs Costos por Lote
            </h3>
            <div class="chart-container-bar" style="position:relative; height:200px;">
                <canvas id="chartBarras"></canvas>
            </div>
        </div>
    </div>

    <style>
    @media (max-width: 768px) {
        #chartsRow { gap: 16px !important; }
        #chartsRow > div { flex: 1 1 100% !important; }
        .chart-container-bar { height: 320px !important; }
        .chart-container-dona { max-width: 180px !important; }
        .dona-center-val { font-size: 0.95rem !important; }
        .ticker-bar > div:first-child { flex-direction: column; align-items: flex-start !important; gap: 12px !important; }
        #tickerPrices { gap: 12px !important; justify-content: flex-start !important; width: 100%; }
    }
    </style>

    <div class="tab-nav">
        <?php $idx = 0; foreach($cultivos_data as $especie => $data): ?>
            <button class="tab-btn <?= $idx === 0 ? 'active' : '' ?>" onclick="showTab('tab-<?= $idx ?>', this)">
                <?= $especie ?> <small style="margin-left:5px;opacity:0.7;">(<?= number_format($data['total_ingreso'] - $data['total_costo'] - $data['total_alq'], 0, ',', '.') ?> USD)</small>
            </button>
        <?php $idx++; endforeach; ?>
    </div>



    <!-- Paneles de Cultivos (Nivel 3 y 4) -->
    <?php $idx = 0; foreach($cultivos_data as $especie => $data): ?>
        <div id="tab-<?= $idx ?>" class="cultivo-panel <?= $idx === 0 ? 'active' : '' ?>">
            <div class="lotes-grid">
                <?php foreach($data['lotes'] as $lote): 
                    $margen_lote = $lote['ingreso'] - $lote['costo_dir'] - $lote['alquiler'];
                    $costo_total_lote = $lote['costo_dir'] + $lote['alquiler'];
                    $roi = $costo_total_lote > 0 ? ($margen_lote / $costo_total_lote) * 100 : ($margen_lote > 0 ? 100 : 0);
                ?>
                    <div class="lote-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                            <div>
                                <h3 style="font-size: 1.25rem; font-weight: 800; margin: 0; letter-spacing: -0.03em; color: white;"><?= htmlspecialchars($lote['nombre']) ?></h3>
                                <div style="display:flex; align-items:center; gap:6px; margin-top:4px;">
                                    <i class="fas fa-vector-square" style="font-size:0.75rem; color:var(--text-muted);"></i>
                                    <span style="color: var(--text-muted); font-size: 0.85rem; font-weight:500;"><?= number_format($lote['sup'], 1, ',', '.') ?> ha</span>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div class="badge" style="background: <?= $margen_lote >= 0 ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)' ?>; color: <?= $margen_lote >= 0 ? 'var(--accent)' : 'var(--danger)' ?>; border: 1px solid <?= $margen_lote >= 0 ? 'rgba(16,185,129,0.2)' : 'rgba(239,68,68,0.2)' ?>; font-weight:700; padding: 6px 12px; border-radius: 10px;">
                                    $<?= number_format($margen_lote, 0, ',', '.') ?>
                                </div>
                                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 4px; font-weight: 600;">Margen Neto</div>
                            </div>
                        </div>

                        <!-- Progress Bar / ROI Indicator -->
                        <div style="margin-bottom: 20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Retorno (ROI)</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: <?= $roi >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;"><?= number_format($roi, 1, ',', '.') ?>%</span>
                            </div>
                            <div style="height: 6px; background: rgba(255,255,255,0.05); border-radius: 3px; overflow: hidden;">
                                <div style="height: 100%; width: <?= min(max($roi, 0), 100) ?>%; background: <?= $roi >= 50 ? 'var(--accent)' : ($roi > 0 ? '#fbbf24' : 'var(--danger)') ?>; box-shadow: 0 0 10px <?= $roi >= 50 ? 'rgba(16,185,129,0.4)' : 'rgba(251,191,36,0.4)' ?>;"></div>
                            </div>
                        </div>

                        <div class="lote-details" style="display: flex; flex-direction: column; gap: 4px;">
                            <div class="lote-detail-row">
                                <span class="label"><i class="fas fa-coins" style="color: #fbbf24;"></i> Ingresos</span>
                                <span class="value" style="color: white;">$<?= number_format($lote['ingreso'], 0, ',', '.') ?></span>
                            </div>
                            
                            <div class="lote-detail-row">
                                <span class="label"><i class="fas fa-person-digging"></i> Labores</span>
                                <span class="value">-$<?= number_format($lote['labores'], 0, ',', '.') ?></span>
                            </div>

                            <div class="lote-detail-row">
                                <span class="label"><i class="fas fa-vial"></i> Insumos</span>
                                <span class="value">-$<?= number_format($lote['insumos'], 0, ',', '.') ?></span>
                            </div>

                            <div class="lote-detail-row">
                                <span class="label"><i class="fas fa-receipt"></i> Alquiler</span>
                                <span class="value">-$<?= number_format($lote['alquiler'], 0, ',', '.') ?></span>
                            </div>
                            
                            <?php
                                $precio_promedio_lote = $lote['kgs'] > 0 ? $lote['ingreso'] / $lote['kgs'] : 0;
                                $pe_lote_kg_ha = ($precio_promedio_lote > 0 && $lote['sup'] > 0) ? ($costo_total_lote / $precio_promedio_lote) / $lote['sup'] : 0;
                            ?>
                            <div class="lote-detail-row" style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed rgba(255,255,255,0.1); border-bottom: none;">
                                <span class="label" style="font-size: 0.78rem;"><i class="fas fa-bullseye"></i> Indiferencia</span>
                                <span class="value" style="font-size: 0.85rem; color: #818cf8;"><?= number_format($pe_lote_kg_ha, 0, ',', '.') ?> <small>kg/ha</small></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php $idx++; endforeach; ?>

<?php endif; ?>

<!-- Chart.js CDN (debe ir antes del bloque script que lo usa) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<script>
    function showTab(tabId, btn) {
        document.querySelectorAll('.cultivo-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        btn.classList.add('active');
    }

    let dolarVenta = 0;
    let currentCurrency = 'ARS';

    function setTickerCurrency(cur) {
        currentCurrency = cur;
        document.getElementById('btnCurrencyARS').classList.toggle('active', cur === 'ARS');
        document.getElementById('btnCurrencyUSD').classList.toggle('active', cur === 'USD');

        if (dolarVenta === 0) return; // Wait for dollar fetch

        const formatterARS = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        const formatterUSD = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        document.querySelectorAll('.sio-val').forEach(el => {
            const ars = parseFloat(el.dataset.ars) || 0;
            if (cur === 'USD') {
                el.innerText = '$' + formatterUSD.format(ars / dolarVenta);
            } else {
                el.innerText = '$' + formatterARS.format(ars);
            }
        });

        document.querySelectorAll('.sio-minmax').forEach(el => {
            const min = parseFloat(el.dataset.min) || 0;
            const max = parseFloat(el.dataset.max) || 0;
            if (cur === 'USD') {
                el.innerText = formatterUSD.format(min / dolarVenta) + ' – ' + formatterUSD.format(max / dolarVenta);
            } else {
                el.innerText = formatterARS.format(min) + ' – ' + formatterARS.format(max);
            }
        });

        document.querySelectorAll('.sio-unit').forEach(el => {
            el.innerText = cur + '/ton';
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        // ── Ticker dólar ──
        fetch('https://dolarapi.com/v1/dolares/mayorista')
            .then(res => res.json())
            .then(data => {
                if (data.venta) {
                    dolarVenta = parseFloat(data.venta);
                    const ticker = document.getElementById('dolar-ticker');
                    if (ticker) ticker.innerText = '$' + data.venta.toLocaleString('es-AR');
                }
            });

        // ── Inicializar gráficos ──
        <?php if($ciclo_sel): ?>
        const dona  = <?= $chart_dona ?>;
        const bars  = <?= $chart_bars ?>;

        // ── Gráfico Dona ──
        const totalCostos = dona.labores + dona.insumos + dona.alquiler;
        document.getElementById('donaCentroVal').textContent = '$' + totalCostos.toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:0});

        const ctxDona = document.getElementById('chartDona').getContext('2d');
        new Chart(ctxDona, {
            type: 'doughnut',
            data: {
                labels: ['Labores', 'Insumos', 'Alquileres'],
                datasets: [{
                    data: [dona.labores, dona.insumos, dona.alquiler],
                    backgroundColor: ['rgba(129,140,248,0.85)', 'rgba(96,165,250,0.85)', 'rgba(255,123,114,0.85)'],
                    borderColor: ['rgba(129,140,248,1)', 'rgba(96,165,250,1)', 'rgba(255,123,114,1)'],
                    borderWidth: 1.5,
                    hoverOffset: 8
                }]
            },
            options: {
                cutout: '68%',
                plugins: { legend: { display: false }, tooltip: {
                    callbacks: { label: ctx => ' $' + ctx.raw.toLocaleString('es-AR', {minimumFractionDigits:2}) }
                }},
                animation: { animateScale: true }
            }
        });

        // Leyenda manual dona
        const donaColors = ['#818cf8','#60a5fa','#ff7b72'];
        const donaLabels = ['Labores','Insumos','Alquileres'];
        const donaVals   = [dona.labores, dona.insumos, dona.alquiler];
        const leyenda    = document.getElementById('donaLeyenda');
        donaLabels.forEach((l, i) => {
            const pct = totalCostos > 0 ? ((donaVals[i]/totalCostos)*100).toFixed(1) : 0;
            leyenda.innerHTML += `
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${donaColors[i]};margin-right:6px;"></span>${l}</span>
                    <span style="color:${donaColors[i]};font-weight:700;">$${donaVals[i].toLocaleString('es-AR',{minimumFractionDigits:0})} <small style="opacity:0.6;">(${pct}%)</small></span>
                </div>`;
        });

        // ── Gráfico Barras ──
        const ctxBar = document.getElementById('chartBarras').getContext('2d');
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: bars.labels,
                datasets: [
                    { label: 'Ingresos',  data: bars.ingresos, backgroundColor: 'rgba(16,185,129,0.7)',  borderRadius: 5 },
                    { label: 'Costos',    data: bars.costos,   backgroundColor: 'rgba(255,123,114,0.7)', borderRadius: 5 },
                    { label: 'Margen',    data: bars.margen,   backgroundColor: 'rgba(129,140,248,0.7)', borderRadius: 5 },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#94a3b8', font: { size: 11 } } },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: $${ctx.raw.toLocaleString('es-AR', {minimumFractionDigits:2})}` }}
                },
                scales: {
                    x: { 
                        ticks: { 
                            color: '#94a3b8', 
                            font: { size: 10 },
                            maxRotation: 45,
                            minRotation: 0,
                            autoSkip: true
                        }, 
                        grid: { display: false } 
                    },
                    y: { 
                        ticks: { 
                            color: '#94a3b8', 
                            font: { size: 10 }, 
                            callback: v => '$' + v.toLocaleString('es-AR') 
                        }, 
                        grid: { color: 'rgba(255,255,255,0.06)' } 
                    }
                }
            }
        });
        <?php endif; ?>
    });
</script>

<?php require_once 'includes/footer.php'; ?>



