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
.fl-card { background: var(--n-25); border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; transition: transform 0.2s; }
.fl-card:hover { transform: translateY(-3px); border-color: var(--border); }
.fl-card-title { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 10px; }

/* La tarjeta por defecto era roja. En el resto de la app el rojo borra cosas y
   marca pérdidas, así que entrar a Ganadería y ver todo en rojo se leía como
   "acá hay un problema". Pasa al violeta del módulo. */
.fl-summary-box { background: var(--gana-soft); border: 1px solid oklch(0.520 0.100 300 / 0.30); border-radius: 10px; padding: 20px; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; }
.fl-summary-box.green { background: var(--accent-soft); border-color: var(--accent-soft); }
.fl-summary-box.orange { background: var(--warning-soft); border-color: var(--warning-soft); }
.fl-summary-box.blue { background: var(--gana-soft); border-color: var(--gana-soft); }

.fl-summary-val { font-size: 2.2rem; font-weight: bold; margin-bottom: 5px; line-height: 1; }
.fl-summary-label { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

/* Acción, no destrucción: verde como en Agricultura y Tambo. El color de la
   acción primaria es transversal a los tres módulos; lo que cambia por módulo
   es la identidad, no el botón que ejecuta. */
.fl-btn-action { display: inline-block; width: 100%; padding: 11px; background: var(--accent); color: var(--on-accent); border: 1px solid var(--accent); border-radius: 8px; text-align: center; font-weight: 600; text-decoration: none; margin-top: auto; transition: background 0.2s; min-height: 44px; }
.fl-btn-action:hover { background: var(--accent-hover); border-color: var(--accent-hover); }

.lote-stat { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; }
.lote-stat span:first-child { color: var(--text-muted); }
.lote-stat span:last-child { font-weight: bold; color: var(--text-primary); }

.currency-toggle-container { display: inline-flex; background: var(--n-100); padding: 4px; border-radius: 10px; border: 1px solid var(--border); }
.btn-currency { border: none; background: transparent; color: var(--text-muted); padding: 6px 14px; border-radius: 7px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
.btn-currency:hover { color: var(--text-primary); background: var(--n-100); }
.btn-currency.active { background: var(--accent); color: var(--on-accent) !important; box-shadow: 0 2px 8px rgba(139, 92, 246, 0.4); }
</style>

<div class="fl-header">
    <div>
        <div style="font-size: 0.85rem; color: var(--mod-gana); font-weight: 700; margin-bottom: 4px;">
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
        <div style="color: var(--text-muted); font-size: 0.9rem; background: var(--n-100); padding: 8px 15px; border-radius: 20px; border: 1px solid var(--border);">
            Hoy: <?= date('d / m / Y') ?>
        </div>
    </div>
</div>

<!-- ── KPIs Globales ─────────────────────────────────────────────────────── -->
<div class="fl-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="fl-summary-box blue">
        <div class="fl-summary-val" style="color: var(--mod-gana);"><?= $total_lotes ?></div>
        <div class="fl-summary-label">Lotes Activos</div>
    </div>
    
    <div class="fl-summary-box orange">
        <div class="fl-summary-val" style="color: var(--se-warning);"><?= number_format($total_cabezas) ?></div>
        <div class="fl-summary-label">Cabezas Simuladas</div>
    </div>

    <div class="fl-summary-box">
        <?php /* Una inversión proyectada no es una pérdida: va en tinta, no en rojo. */ ?>
        <div class="fl-summary-val gan-money" data-ars="<?= $inversion_global ?>" style="color: var(--text-primary); font-size: 1.6rem;">$<?= number_format($inversion_global, 0, ',', '.') ?></div>
        <div class="fl-summary-label">Inversión Compra Proyectada</div>
    </div>
    
    <div class="fl-summary-box green">
        <div class="fl-summary-val gan-money" data-ars="<?= $ingreso_global ?>" style="color: var(--accent); font-size: 1.6rem;">$<?= number_format($ingreso_global, 0, ',', '.') ?></div>
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
                /* Hex literal, no var(): Chart.js pinta sobre canvas y no resuelve
                   variables CSS — una var() acá sale negra. Mismos dos colores que
                   usa el panel de Agricultura para ingresos y costos. */
                {
                    label: 'Inversión Inicial',
                    data: chartDataInversion,
                    backgroundColor: '#3284d0',
                    borderWidth: 0,
                    borderRadius: 4
                },
                {
                    label: 'Ingreso Final Bruto',
                    data: chartDataIngreso,
                    backgroundColor: '#348f4f',
                    borderWidth: 0,
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#535a55', font: { size: 13 }, boxWidth: 12, boxHeight: 12 }
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
                        color: '#535a55',
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

<div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 20px; margin-top: 20px;">
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
            <div class="fl-card" style="border-top: 4px solid var(--accent);">
                <div class="fl-card-title">
                    <span><?= htmlspecialchars($l['nombre']) ?></span>
                    <span style="font-size: 0.8rem; background: var(--accent-soft); color: var(--accent); padding: 3px 8px; border-radius: 12px;"><?= $l['cant_animales'] ?> cabezas</span>
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
                        <span class="gan-money" data-ars="<?= $l['ingreso_calc'] ?>" style="color: var(--accent);">$<?= number_format($l['ingreso_calc'], 0, ',', '.') ?></span>
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
