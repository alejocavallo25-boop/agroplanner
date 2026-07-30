<?php
require_once 'config/auth.php';
require_once 'config/database.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Régimen de Lluvia Histórico';

$lote_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$lote_id) {
    header("Location: lotes.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM lotes WHERE id = ? AND usuario_id = ?");
$stmt->execute([$lote_id, $usuario_id]);
$lote = $stmt->fetch();

if (!$lote || empty($lote['latitud']) || empty($lote['longitud'])) {
    // Si no es un lote válido o no tiene coordenadas, volvemos a lotes
    header("Location: lotes.php");
    exit;
}

require_once 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div style="display: flex; align-items: center; gap: 15px; margin-bottom: 24px;">
    <a href="lotes.php" class="btn" style="background: rgba(255,255,255,0.05); color: var(--text-primary); border: 1px solid var(--border);">
        <i class="fas fa-arrow-left"></i> Volver a Lotes
    </a>
    <div>
        <h2 style="font-size: 1.4rem; font-weight: 600; line-height: 1.2;">
            Régimen de Lluvia
        </h2>
        <span style="color: var(--text-muted); font-size: 0.9rem;">
            Lote: <strong style="color: var(--text-primary);"><?= htmlspecialchars($lote['nombre']) ?></strong> 
            (<?= number_format($lote['superficie'], 2) ?> ha)
        </span>
    </div>
</div>

<div id="loadingIndicator" style="text-align: center; padding: 60px 20px; color: var(--accent);">
    <i class="fas fa-circle-notch fa-spin fa-3x" style="margin-bottom: 15px;"></i>
    <p style="font-size: 1.1rem; color: var(--text-muted);">Consultando registros meteorológicos satelitales (Últimos 5 años)...</p>
</div>

<div id="contentLluvia" style="display: none;">
    
    <div class="charts-grid" style="margin-bottom: 24px; display: block;">
        <div class="glass-panel stat-card" style="margin-bottom: 24px;">
            <div class="panel-header" style="margin-bottom: 15px;">
                <h3 style="font-size: 1.1rem; font-weight: 500;"><i class="fas fa-chart-bar" style="color: var(--accent); margin-right: 8px;"></i> Precipitación Anual Histórica</h3>
            </div>
            <div class="chart-wrapper" style="height: 380px;">
                <canvas id="precipChart"></canvas>
            </div>
        </div>

        <div class="glass-panel">
            <div class="panel-header">
                <h3 style="font-size: 1.1rem; font-weight: 500;"><i class="fas fa-table" style="color: #60a5fa; margin-right: 8px;"></i> Detalle Mensual Histórico (mm)</h3>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th id="thYear1"></th>
                            <th id="thYear2"></th>
                            <th id="thYear3"></th>
                            <th id="thYear4"></th>
                            <th id="thYear5"></th>
                            <th>Promedio</th>
                        </tr>
                    </thead>
                    <tbody id="mensualTbody">
                        <!-- Generado por JS -->
                    </tbody>
                    <tfoot id="mensualTfoot">
                        <!-- Generado por JS -->
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Limpieza robusta de coordenadas
        let latRaw = <?= json_encode($lote['latitud']) ?>;
        let lngRaw = <?= json_encode($lote['longitud']) ?>;
        
        const lat = parseFloat(String(latRaw).replace(',', '.'));
        const lng = parseFloat(String(lngRaw).replace(',', '.'));

        if (isNaN(lat) || isNaN(lng)) {
            document.getElementById('loadingIndicator').innerHTML = `
                <div style="color:var(--danger); padding:20px;">
                    <i class="fas fa-location-dot fa-2x"></i>
                    <p style="margin-top:10px;">Coordenadas inválidas.<br><small>Por favor, editá el lote y asegurate de marcar la ubicación en el mapa.</small></p>
                </div>`;
            return;
        }

        const lote_id = <?= $lote_id ?>;
        const currentYear = new Date().getFullYear();
        const yearsToFetch = 5;
        const startYear = currentYear - yearsToFetch;
        const endYear = currentYear - 1;
        const startDate = `${startYear}-01-01`;
        const endDate = `${endYear}-12-31`;

        const cacheKey = `clima_hist_${lote_id}_${startDate}_${endDate}`;
        const cachedData = localStorage.getItem(cacheKey);

        if (cachedData) {
            try {
                const parsed = JSON.parse(cachedData);
                if (Date.now() - parsed.timestamp < 1000 * 60 * 60 * 24 * 7) {
                    processAndRender(parsed.data);
                    return;
                }
            } catch(e) { localStorage.removeItem(cacheKey); }
        }

        // Usamos el proxy local para evitar bloqueos de CORS o SSL en XAMPP
        fetch(`api/clima_proxy.php?latitude=${lat}&longitude=${lng}&start_date=${startDate}&end_date=${endDate}`)
            .then(res => {
                if (!res.ok) throw new Error('Error en el servidor local (Proxy)');
                return res.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.reason || 'Error en los parámetros de la consulta');
                }
                
                // Guardar en caché
                localStorage.setItem(cacheKey, JSON.stringify({
                    timestamp: Date.now(),
                    data: data
                }));

                processAndRender(data);
            })
            .catch(err => {
                console.error(err);
                document.getElementById('loadingIndicator').innerHTML = `
                    <div style="color:var(--danger); padding: 25px; border: 1px dashed var(--danger); border-radius: 12px; background: rgba(239,68,68,0.05); text-align: center;">
                        <i class="fas fa-satellite-dish fa-3x" style="margin-bottom:15px; opacity:0.5;"></i>
                        <p style="font-weight:700; font-size:1.1rem; margin-bottom:5px;">No se pudo conectar con el servicio meteorológico</p>
                        <p style="font-size:0.85rem; opacity:0.8; margin-bottom:15px;">Esto puede ocurrir por falta de internet o bloqueos de firewall en XAMPP.</p>
                        <div style="background:rgba(0,0,0,0.1); padding:8px; border-radius:6px; font-family:monospace; font-size:0.75rem; margin-bottom:15px;">Detalle: ${err.message}</div>
                        <button onclick="location.reload()" style="padding:8px 20px; border-radius:10px; border:none; background:var(--danger); color:white; font-weight:700; cursor:pointer; transition:all 0.2s;">
                            <i class="fas fa-sync-alt"></i> Reintentar Carga
                        </button>
                    </div>`;
            });

        function processAndRender(data) {
                document.getElementById('loadingIndicator').style.display = 'none';
                document.getElementById('contentLluvia').style.display = 'block';

                if (!data.daily || !data.daily.time || data.daily.time.length === 0) {
                    document.getElementById('loadingIndicator').style.display = 'block';
                    document.getElementById('loadingIndicator').innerHTML = `
                        <div style="color:#fbbf24;">
                            <i class="fas fa-triangle-exclamation fa-2x"></i>
                            <p style="margin-top:10px;">No se encontraron registros para esta ubicación.<br><small>Verificá que las coordenadas del lote sean correctas.</small></p>
                        </div>`;
                    return;
                }

                const yearly = {};
                const monthly = {}; // { year: { month: value } }
                
                // Initialize monthly structures
                for(let y = startYear; y <= endYear; y++) {
                    yearly[y] = 0;
                    monthly[y] = {};
                    for(let m = 1; m <= 12; m++) {
                        monthly[y][m] = 0;
                    }
                }

                data.daily.time.forEach((date, i) => {
                    const y = parseInt(date.substring(0, 4));
                    const m = parseInt(date.substring(5, 7));
                    const p = data.daily.precipitation_sum[i] || 0;

                    if (yearly[y] !== undefined) yearly[y] += p;
                    if (monthly[y] && monthly[y][m] !== undefined) monthly[y][m] += p;
                });

                // Preparar datos para el gráfico
                const labels = Object.keys(yearly);
                const values = labels.map(y => Math.round(yearly[y]));

                // Dibujar Chart
                const ctx = document.getElementById('precipChart').getContext('2d');
                Chart.defaults.color = '#9ca3af';
                Chart.defaults.font.family = 'Inter';

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Lluvia total (mm)',
                            data: values,
                            backgroundColor: 'rgba(59, 130, 246, 0.5)',
                            borderColor: 'rgba(59, 130, 246, 0.8)',
                            borderWidth: 1,
                            borderRadius: 4,
                            hoverBackgroundColor: 'rgba(59, 130, 246, 0.7)'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                title: { display: true, text: 'Milímetros (mm)' }
                            },
                            x: {
                                grid: { display: false }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(ctx) { return ctx.raw + ' mm'; }
                                }
                            }
                        }
                    }
                });

                // Llenar tabla
                const years = Object.keys(yearly).map(Number).sort((a,b)=>a-b);
                years.forEach((y, i) => {
                    const th = document.getElementById(`thYear${i+1}`);
                    if (th) th.textContent = y;
                });

                const monthNames = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
                const tbody = document.getElementById('mensualTbody');
                let html = '';
                
                const avgMonthly = {};

                for (let m = 1; m <= 12; m++) {
                    let totalMonth = 0;
                    html += `<tr>`;
                    html += `<td><strong>${monthNames[m-1]}</strong></td>`;
                    years.forEach(y => {
                        const val = monthly[y][m];
                        totalMonth += val;
                        // Destacar si es > 150mm con un pequeño color para mejor lectura
                        const color = val > 150 ? '#60a5fa' : (val > 50 ? 'var(--text-primary)' : 'var(--text-muted)');
                        html += `<td style="color:${color}">${Math.round(val)}</td>`;
                    });
                    const avg = totalMonth / years.length;
                    avgMonthly[m] = avg;
                    html += `<td style="font-weight: 600; color: var(--accent); background: rgba(0,0,0,0.2)">${Math.round(avg)}</td>`;
                    html += `</tr>`;
                }
                tbody.innerHTML = html;

                // Tfoot Totales
                let tfootHtml = `<tr style="background: rgba(255,255,255,0.05);">`;
                tfootHtml += `<td><strong>Total Anual</strong></td>`;
                let sumAvgs = 0;
                years.forEach(y => {
                    tfootHtml += `<td style="font-weight:700;">${Math.round(yearly[y])} mm</td>`;
                    sumAvgs += yearly[y];
                });
                tfootHtml += `<td style="font-weight: 800; color: var(--accent); background: rgba(0,0,0,0.3)">${Math.round(sumAvgs / years.length)} mm</td>`;
                tfootHtml += `</tr>`;
                document.getElementById('mensualTfoot').innerHTML = tfootHtml;
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>
