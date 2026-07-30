<?php
require_once 'config/auth.php';
require_once 'config/database.php';
require_ganaderia();
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Tablero Simulador Ganadero';
require_once 'includes/header.php';

// ─── Extraer Datos del Simulador ─────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM feedlot_lotes WHERE usuario_id = ? ORDER BY created_at DESC");
$stmt->execute([$usuario_id]);
$lotes = $stmt->fetchAll();

$total_lotes = count($lotes);
$total_cabezas = 0;
$inversion_global = 0;
$ingreso_global = 0;

$chart_labels = [];
$chart_inversion = [];
$chart_ingreso = [];

foreach ($lotes as &$l) {
    $q = $l['cant_animales'];
    $total_cabezas += $q;
    
    // Inversion inicial (Costo de compra de Invernada)
    $costo_compra_inv_animal = $l['kg_entrada_inv'] * $l['precio_compra'] * (1 + ($l['flete_compra_pct']/100));
    $inversion_lote = $q * $costo_compra_inv_animal;
    $inversion_global += $inversion_lote;
    
    // Ingreso por venta (Salida final de Engorde)
    $ingreso_venta_eng_animal = $l['kg_salida_eng'] * $l['precio_venta_eng'] * (1 - ($l['flete_venta_pct']/100));
    $ingreso_lote = $q * $ingreso_venta_eng_animal;
    $ingreso_global += $ingreso_lote;
    
    $l['inversion_calc'] = $inversion_lote;
    $l['ingreso_calc'] = $ingreso_lote;
    
    $chart_labels[] = $l['nombre'];
    $chart_inversion[] = $inversion_lote;
    $chart_ingreso[] = $ingreso_lote;
}
unset($l);
?>

<style>
/* Estilos unificados (fl-cards) */
.fl-header { display: flex; flex-wrap: wrap; gap: 15px; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.fl-header h2 { margin: 0; font-size: 1.5rem; color: var(--text-primary); }

.fl-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 20px; }
.fl-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; transition: transform 0.2s; }
.fl-card:hover { transform: translateY(-3px); border-color: rgba(255,255,255,0.15); }
.fl-card-title { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 10px; }

.fl-summary-box { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 12px; padding: 20px; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; }
.fl-summary-box.green { background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3); }
.fl-summary-box.orange { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.3); }
.fl-summary-box.blue { background: rgba(14, 165, 233, 0.1); border-color: rgba(14, 165, 233, 0.3); }

.fl-summary-val { font-size: 2.2rem; font-weight: bold; margin-bottom: 5px; line-height: 1; }
.fl-summary-label { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

.fl-btn-action { display: inline-block; width: 100%; padding: 10px; background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; text-align: center; font-weight: bold; text-decoration: none; margin-top: auto; transition: all 0.2s; }
.fl-btn-action:hover { background: #ef4444; color: white; }

.lote-stat { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; }
.lote-stat span:first-child { color: var(--text-muted); }
.lote-stat span:last-child { font-weight: bold; color: var(--text-primary); }

.currency-toggle-container { display: inline-flex; background: rgba(0, 0, 0, 0.2); padding: 4px; border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.05); }
.btn-currency { border: none; background: transparent; color: var(--text-muted); padding: 6px 14px; border-radius: 7px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
.btn-currency:hover { color: var(--text-primary); background: rgba(255, 255, 255, 0.05); }
.btn-currency.active { background: var(--accent); color: white !important; box-shadow: 0 2px 8px rgba(139, 92, 246, 0.4); }
</style>

<div class="fl-header">
    <div>
        <div style="font-size: 0.8rem; color: #f87171; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 4px;">
            <i class="fas fa-horse"></i> &nbsp;Centro de Comando
        </div>
        <h2><i class="fas fa-chart-pie"></i> Resumen Global del Simulador</h2>
    </div>
    <div style="display: flex; align-items: center; gap: 15px;">
        <!-- Toggle Moneda -->
        <div class="currency-toggle-container">
            <button type="button" class="btn-currency active" id="btnCurrencyARS" onclick="setGanaderiaCurrency('ARS')">ARS</button>
            <button type="button" class="btn-currency" id="btnCurrencyUSD" onclick="setGanaderiaCurrency('USD')">USD</button>
        </div>
        <div style="color: var(--text-muted); font-size: 0.9rem; background: rgba(0,0,0,0.2); padding: 8px 15px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.05);">
            Hoy: <?= date('d / m / Y') ?>
        </div>
    </div>
</div>

<!-- ── KPIs Globales ─────────────────────────────────────────────────────── -->
<div class="fl-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="fl-summary-box blue">
        <div class="fl-summary-val" style="color: #0ea5e9;"><?= $total_lotes ?></div>
        <div class="fl-summary-label">Lotes Activos</div>
    </div>
    
    <div class="fl-summary-box orange">
        <div class="fl-summary-val" style="color: #f59e0b;"><?= number_format($total_cabezas) ?></div>
        <div class="fl-summary-label">Cabezas Simuladas</div>
    </div>

    <div class="fl-summary-box">
        <div class="fl-summary-val gan-money" data-ars="<?= $inversion_global ?>" style="color: #f87171; font-size: 1.6rem;">$<?= number_format($inversion_global, 0, ',', '.') ?></div>
        <div class="fl-summary-label">Inversión Compra Proyectada</div>
    </div>
    
    <div class="fl-summary-box green">
        <div class="fl-summary-val gan-money" data-ars="<?= $ingreso_global ?>" style="color: #10b981; font-size: 1.6rem;">$<?= number_format($ingreso_global, 0, ',', '.') ?></div>
        <div class="fl-summary-label">Ingreso Bruto Proyectado</div>
    </div>
</div>

<!-- ── Gráfico Comparativo ────────────────────────────────────────────────── -->
<?php if(!empty($lotes)): ?>
<div class="fl-card" style="margin-bottom: 30px;">
    <div class="fl-card-title"><i class="fas fa-chart-bar"></i> Comparativa Económica por Lote</div>
    <div style="position: relative; height: 320px; width: 100%;">
        <canvas id="lotesChart"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
let dolarVenta = 0;
let currentGanaderiaCurrency = 'ARS';
const chartDataInversion = <?= json_encode($chart_inversion) ?>;
const chartDataIngreso = <?= json_encode($chart_ingreso) ?>;

function setGanaderiaCurrency(cur) {
    currentGanaderiaCurrency = cur;
    document.getElementById('btnCurrencyARS').classList.toggle('active', cur === 'ARS');
    document.getElementById('btnCurrencyUSD').classList.toggle('active', cur === 'USD');

    if (dolarVenta === 0 && cur === 'USD') return;

    const formatterARS = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 });
    const formatterUSD = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });

    document.querySelectorAll('.gan-money').forEach(el => {
        const ars = parseFloat(el.dataset.ars) || 0;
        if (cur === 'USD') {
            el.innerText = formatterUSD.format(ars / dolarVenta).replace('US$', 'USD ');
        } else {
            el.innerText = formatterARS.format(ars);
        }
    });

    if (window.lotesChartInstance) {
        const factor = cur === 'USD' ? (1 / dolarVenta) : 1;
        window.lotesChartInstance.data.datasets[0].data = chartDataInversion.map(v => v * factor);
        window.lotesChartInstance.data.datasets[1].data = chartDataIngreso.map(v => v * factor);
        window.lotesChartInstance.update();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // ── Fetch Dólar ──
    fetch('https://dolarapi.com/v1/dolares/mayorista')
        .then(res => res.json())
        .then(data => {
            if (data.venta) {
                dolarVenta = parseFloat(data.venta);
                if (currentGanaderiaCurrency === 'USD') setGanaderiaCurrency('USD');
            }
        });

    // ── Instanciar Chart ──
    const ctx = document.getElementById('lotesChart').getContext('2d');
    window.lotesChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                {
                    label: 'Inversión Inicial',
                    data: chartDataInversion,
                    backgroundColor: 'rgba(239, 68, 68, 0.7)', // Rojo
                    borderColor: '#f87171',
                    borderWidth: 1,
                    borderRadius: 4
                },
                {
                    label: 'Ingreso Final Bruto',
                    data: chartDataIngreso,
                    backgroundColor: 'rgba(16, 185, 129, 0.7)', // Verde
                    borderColor: '#10b981',
                    borderWidth: 1,
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#a1a1aa', font: { size: 13 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            if (context.parsed.y !== null) {
                                let cur = currentGanaderiaCurrency === 'USD' ? 'USD' : 'ARS';
                                label += new Intl.NumberFormat(currentGanaderiaCurrency === 'USD' ? 'en-US' : 'es-AR', { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        color: '#71717a',
                        callback: function(value, index, values) {
                            return (currentGanaderiaCurrency === 'USD' ? 'USD ' : '$') + value.toLocaleString();
                        }
                    },
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    beginAtZero: true
                },
                x: {
                    ticks: { color: '#a1a1aa' },
                    grid: { display: false }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; margin-bottom: 20px; margin-top: 20px;">
    <h3 style="color: var(--text-primary); margin: 0;">Lotes Actuales en Simulación</h3>
    <a href="ganaderia_feedlot.php?nuevo=1" class="fl-btn-action" style="width: auto; padding: 6px 15px; margin: 0;"><i class="fas fa-plus"></i> Crear Lote</a>
</div>

<!-- ── Listado de Lotes ──────────────────────────────────────────────────── -->
<div class="fl-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
    <?php if(empty($lotes)): ?>
        <div class="fl-card" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
            <i class="fas fa-folder-open" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 15px;"></i>
            <h4 style="color: var(--text-primary); margin-bottom: 5px;">No hay lotes creados</h4>
            <p style="color: var(--text-muted); margin-bottom: 20px;">Dirígete al Simulador para crear tu primer lote.</p>
            <a href="ganaderia_feedlot.php" class="fl-btn-action" style="max-width: 250px; margin: 0 auto;">Ir al Simulador</a>
        </div>
    <?php else: ?>
        <?php foreach($lotes as $l): ?>
            <div class="fl-card" style="border-top: 4px solid #10b981;">
                <div class="fl-card-title">
                    <span><?= htmlspecialchars($l['nombre']) ?></span>
                    <span style="font-size: 0.8rem; background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 3px 8px; border-radius: 12px;"><?= $l['cant_animales'] ?> cabezas</span>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <div class="lote-stat">
                        <span><i class="far fa-calendar-alt"></i> Inicio:</span>
                        <span><?= date('d/m/Y', strtotime($l['fecha_inicio'])) ?></span>
                    </div>
                    <div class="lote-stat">
                        <span><i class="fas fa-coins"></i> Inv. Inicial:</span>
                        <span class="gan-money" data-ars="<?= $l['inversion_calc'] ?>">$<?= number_format($l['inversion_calc'], 0, ',', '.') ?></span>
                    </div>
                    <div class="lote-stat">
                        <span><i class="fas fa-hand-holding-usd"></i> Ingreso Final:</span>
                        <span class="gan-money" data-ars="<?= $l['ingreso_calc'] ?>" style="color: #10b981;">$<?= number_format($l['ingreso_calc'], 0, ',', '.') ?></span>
                    </div>
                    <div class="lote-stat">
                        <span><i class="fas fa-clock"></i> Ciclo Invernada:</span>
                        <span><?= $l['dias_invernada'] ?> días</span>
                    </div>
                    <div class="lote-stat">
                        <span><i class="fas fa-clock"></i> Ciclo Engorde:</span>
                        <span><?= $l['dias_engorde'] ?> días</span>
                    </div>
                </div>

                <a href="ganaderia_feedlot.php?lote=<?= $l['id'] ?>" class="fl-btn-action"><i class="fas fa-external-link-alt"></i> Abrir Simulador</a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
