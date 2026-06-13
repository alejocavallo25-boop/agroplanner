<?php
require_once 'config/auth.php';
require_once 'config/database.php';
require_tambo();
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Comparativa de Tambo';

// Default values: mes anterior vs mes actual
$mes1_sel = $_GET['mes1'] ?? date('Y-m', strtotime('-1 month'));
$mes2_sel = $_GET['mes2'] ?? date('Y-m');
$cat_sel  = $_GET['cat'] ?? '';

$d1_start = $mes1_sel . '-01';
$d1_end   = date('Y-m-t', strtotime($d1_start));
$d2_start = $mes2_sel . '-01';
$d2_end   = date('Y-m-t', strtotime($d2_start));

// Obtener categorías únicas del usuario
$stmt_cat = $pdo->prepare("SELECT DISTINCT categoria FROM tambo_egresos WHERE usuario_id = ? ORDER BY categoria");
$stmt_cat->execute([$usuario_id]);
$categorias_db = $stmt_cat->fetchAll(PDO::FETCH_COLUMN);

function get_tambo_stats($pdo, $usuario_id, $date_start, $date_end, $filtro_cat) {
    $mes_sel = date('Y-m', strtotime($date_start));
    
    // Obtener dólar guardado/vivo
    $dolar_guardado = null;
    $stmt = $pdo->prepare("SELECT dolar_mayorista FROM tambo_dolar_mes WHERE usuario_id=? AND mes=?");
    $stmt->execute([$usuario_id, $mes_sel]);
    if ($row = $stmt->fetch()) {
        $dolar_guardado = (float)$row['dolar_mayorista'];
    }
    
    $dolar_cache = $dolar_guardado ?: 1000;
    if (!$dolar_guardado && $mes_sel === date('Y-m')) {
        $ctx = stream_context_create(['http'=>['timeout'=>2],'https'=>['timeout'=>2]]);
        $api_resp = @json_decode(@file_get_contents('https://dolarapi.com/v1/dolares/mayorista', false, $ctx), true);
        if ($api_resp && isset($api_resp['venta'])) $dolar_cache = (float)$api_resp['venta'];
    }

    // Producción Leche
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
    $stmt->execute([$usuario_id, $date_start, $date_end]);
    $prod = $stmt->fetch();
    
    $litros = (float)$prod['litros'];
    $ingreso_leche_ars = (float)$prod['ingreso_ars'];
    $ingreso_otra_ars = (float)$prod['ingreso_otra_leche_ars'];
    $precio_leche_ars = (float)$prod['ultimo_precio'];

    // Producción Carne
    $stmt = $pdo->prepare("
        SELECT tipo, SUM(monto_total) as total
        FROM tambo_ventas_carne
        WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?
        GROUP BY tipo
    ");
    $stmt->execute([$usuario_id, $date_start, $date_end]);
    $carne = $stmt->fetchAll();
    
    $ingreso_carne_real_ars = 0;
    $ingreso_dif_inv_ars = 0;

    foreach ($carne as $row) {
        if ($row['tipo'] === 'diferencia_inventario') {
            $ingreso_dif_inv_ars += (float)$row['total'];
        } else {
            $ingreso_carne_real_ars += (float)$row['total'];
        }
    }
    $ingreso_carne_ars = $ingreso_carne_real_ars + $ingreso_dif_inv_ars;

    // Egresos (Costos)
    $sql_egresos = "
        SELECT categoria, subcategoria, moneda, SUM(monto) as total
        FROM tambo_egresos
        WHERE usuario_id = ? AND fecha >= ? AND fecha <= ?
    ";
    $params_egresos = [$usuario_id, $date_start, $date_end];

    if (!empty($filtro_cat)) {
        $sql_egresos .= " AND categoria = ?";
        $params_egresos[] = $filtro_cat;
    }

    $sql_egresos .= " GROUP BY categoria, subcategoria, moneda";

    $stmt = $pdo->prepare($sql_egresos);
    $stmt->execute($params_egresos);
    $egresos = $stmt->fetchAll();
    
    $costos_usd = 0;
    $costos_ars_total = 0;
    
    $costos_cat_ars = [];
    $costos_cat_usd = [];
    $costos_subcat_ars = [];
    $costos_subcat_usd = [];

    foreach ($egresos as $egr) {
        $cat = trim($egr['categoria']) ?: 'Otros';
        $sub = trim($egr['subcategoria']) ?: 'General';
        
        $monto_usd = $egr['moneda'] === 'USD' ? (float)$egr['total'] : ($dolar_cache > 0 ? (float)$egr['total'] / $dolar_cache : 0);
        $costos_usd += $monto_usd;
        
        $monto_ars = $egr['moneda'] === 'ARS' ? (float)$egr['total'] : (float)$egr['total'] * $dolar_cache;
        $costos_ars_total += $monto_ars;
        
        if (!isset($costos_cat_ars[$cat])) $costos_cat_ars[$cat] = 0;
        if (!isset($costos_cat_usd[$cat])) $costos_cat_usd[$cat] = 0;
        $costos_cat_ars[$cat] += $monto_ars;
        $costos_cat_usd[$cat] += $monto_usd;

        if (!isset($costos_subcat_ars[$cat])) {
            $costos_subcat_ars[$cat] = [];
            $costos_subcat_usd[$cat] = [];
        }
        if (!isset($costos_subcat_ars[$cat][$sub])) {
            $costos_subcat_ars[$cat][$sub] = 0;
            $costos_subcat_usd[$cat][$sub] = 0;
        }
        $costos_subcat_ars[$cat][$sub] += $monto_ars;
        $costos_subcat_usd[$cat][$sub] += $monto_usd;
    }

    $total_ingresos_ars = $ingreso_leche_ars + $ingreso_otra_ars + $ingreso_carne_ars;
    $total_ingresos_usd = $dolar_cache > 0 ? $total_ingresos_ars / $dolar_cache : 0;
    
    $margen_bruto_ars = $total_ingresos_ars - $costos_ars_total;
    $margen_bruto_usd = $total_ingresos_usd - $costos_usd;
    
    $rentabilidad = $total_ingresos_ars > 0 ? ($margen_bruto_ars / $total_ingresos_ars) * 100 : 0;

    $costo_bruto_ars = $litros > 0 ? $costos_ars_total / $litros : 0;
    $recupero_carne_ars = $litros > 0 ? $ingreso_carne_ars / $litros : 0;
    $recupero_otra_ars = $litros > 0 ? $ingreso_otra_ars / $litros : 0;
    $costo_final_ars = $costo_bruto_ars - $recupero_carne_ars - $recupero_otra_ars;
    
    $costo_bruto_usd = $litros > 0 ? $costos_usd / $litros : 0;
    $recupero_carne_usd = $litros > 0 ? ($dolar_cache > 0 ? ($ingreso_carne_ars / $dolar_cache) / $litros : 0) : 0;
    $recupero_otra_usd = $litros > 0 ? ($dolar_cache > 0 ? ($ingreso_otra_ars / $dolar_cache) / $litros : 0) : 0;
    $costo_final_usd = $costo_bruto_usd - $recupero_carne_usd - $recupero_otra_usd;
    
    $rinde_indiferencia = $precio_leche_ars > 0 ? ($costo_final_ars * $litros) / $precio_leche_ars : 0;

    return [
        'dolar' => $dolar_cache,
        'litros' => $litros,
        'precio_leche_ars' => $precio_leche_ars,
        'ingreso_leche_ars' => $ingreso_leche_ars,
        'ingreso_leche_usd' => $dolar_cache > 0 ? $ingreso_leche_ars / $dolar_cache : 0,
        'ingreso_carne_real_ars' => $ingreso_carne_real_ars,
        'ingreso_carne_real_usd' => $dolar_cache > 0 ? $ingreso_carne_real_ars / $dolar_cache : 0,
        'ingreso_dif_inv_ars' => $ingreso_dif_inv_ars,
        'ingreso_dif_inv_usd' => $dolar_cache > 0 ? $ingreso_dif_inv_ars / $dolar_cache : 0,
        'total_ingresos_ars' => $total_ingresos_ars,
        'total_ingresos_usd' => $total_ingresos_usd,
        'costos_ars_total' => $costos_ars_total,
        'costos_usd' => $costos_usd,
        'costos_cat_ars' => $costos_cat_ars,
        'costos_cat_usd' => $costos_cat_usd,
        'costos_subcat_ars' => $costos_subcat_ars,
        'costos_subcat_usd' => $costos_subcat_usd,
        'margen_bruto_ars' => $margen_bruto_ars,
        'margen_bruto_usd' => $margen_bruto_usd,
        'rentabilidad' => $rentabilidad,
        'costo_final_ars' => $costo_final_ars,
        'costo_final_usd' => $costo_final_usd,
        'rinde_indiferencia' => $rinde_indiferencia
    ];
}

$m1 = get_tambo_stats($pdo, $usuario_id, $d1_start, $d1_end, $cat_sel);
$m2 = get_tambo_stats($pdo, $usuario_id, $d2_start, $d2_end, $cat_sel);

function format_period($start, $end, $short = false) {
    $y1 = date('Y', strtotime($start));
    $y2 = date('Y', strtotime($end));
    $m1 = date('m', strtotime($start));
    $m2 = date('m', strtotime($end));
    
    if ($y1 === $y2 && $m1 === $m2 && date('d', strtotime($start)) === '01' && date('t', strtotime($start)) === date('d', strtotime($end))) {
        return $short ? date('M y', strtotime($start)) : date('M Y', strtotime($start));
    }
    return date('d/m/y', strtotime($start)) . ' - ' . date('d/m/y', strtotime($end));
}

function calc_var($val1, $val2) {
    if ($val1 == 0) return $val2 > 0 ? 100 : ($val2 < 0 ? -100 : 0);
    return (($val2 - $val1) / abs($val1)) * 100;
}

function render_var_badge($val1, $val2, $invert_colors = false, $display_format = 'badge') {
    $var = calc_var($val1, $val2);
    $class = 'neutral';
    $icon = 'fa-minus';
    
    if ($var > 0) {
        $class = $invert_colors ? 'negative' : 'positive';
        $icon = 'fa-arrow-trend-up';
    } elseif ($var < 0) {
        $class = $invert_colors ? 'positive' : 'negative';
        $icon = 'fa-arrow-trend-down';
    }
    
    if (round($var, 1) == 0) {
        $class = 'neutral';
        $icon = 'fa-minus';
    }

    $sign = $var > 0 ? '+' : '';
    $valStr = $sign.number_format($var, 1).'%';

    if ($display_format === 'text') {
        $color = $class === 'positive' ? '#34d399' : ($class === 'negative' ? '#f87171' : '#9ca3af');
        return '<span style="color: '.$color.'; font-weight: 700; display:flex; align-items:center; justify-content:flex-end; gap: 4px;"><i class="fas '.$icon.'"></i> '.$valStr.'</span>';
    }

    return '<div class="var-badge '.$class.'"><i class="fas '.$icon.'"></i> '.$valStr.'</div>';
}

function render_pct_bar($val, $total, $color = '#38bdf8') {
    $pct = $total > 0 ? ($val / $total) * 100 : 0;
    $pctStr = number_format($pct, 1);
    return '
    <div class="pct-bar-container">
        <div class="bar-bg"><div class="bar-fill" style="width: '.$pct.'%; background: '.$color.';"></div></div>
        <span class="pct-text">'.$pctStr.'%</span>
    </div>';
}

$all_cats = array_unique(array_merge(array_keys($m1['costos_cat_ars']), array_keys($m2['costos_cat_ars'])));
sort($all_cats);

require_once 'includes/header.php';
?>

<style>
/* PREMIUM VISUAL STYLES */
.comp-top-bar {
    background: linear-gradient(135deg, rgba(56,189,248,0.1), rgba(14,165,233,0.15));
    border: 1px solid rgba(56,189,248,0.3);
    border-radius: 20px;
    padding: 24px 32px;
    margin-bottom: 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    backdrop-filter: blur(10px);
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
}

.comp-filters {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    background: rgba(0,0,0,0.25);
    padding: 12px 20px;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.05);
}

.kpi-top-card {
    background: linear-gradient(145deg, rgba(30,41,59,0.7), rgba(15,23,42,0.8));
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 24px;
    padding: 28px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    display: flex;
    flex-direction: column;
    gap: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}
.kpi-top-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.3);
    border-color: rgba(255,255,255,0.15);
}
.kpi-icon-wrap {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
}
.kpi-val {
    font-size: 2.2rem;
    font-weight: 800;
    color: white;
    line-height: 1.1;
}
.kpi-label {
    font-size: 0.9rem;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

.var-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.var-badge.positive { background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.3); color: #34d399; }
.var-badge.negative { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #f87171; }
.var-badge.neutral { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #9ca3af; }

.section-card {
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 24px;
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.section-header {
    padding: 20px 28px;
    background: rgba(0,0,0,0.25);
    border-bottom: 1px solid rgba(255,255,255,0.05);
    display: flex;
    align-items: center;
    gap: 14px;
}
.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: white;
    margin: 0;
}

.table-modern {
    width: 100%;
    border-collapse: collapse;
}
.table-modern th, .table-modern td {
    padding: 16px 28px;
    border-bottom: 1px solid rgba(255,255,255,0.03);
}
.table-modern th {
    text-align: right;
    font-size: 0.8rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    background: rgba(255,255,255,0.01);
}
.table-modern th:first-child { text-align: left; }
.table-modern td {
    text-align: right;
    font-size: 1.05rem;
    font-weight: 600;
    color: #e5e7eb;
}
.table-modern td:first-child {
    text-align: left;
    font-size: 0.95rem;
    font-weight: 500;
    color: #9ca3af;
}
.table-modern tr:last-child td { border-bottom: none; }
.table-modern tr:hover td { background: rgba(255,255,255,0.02); }

.row-total td {
    background: rgba(255,255,255,0.02);
    font-weight: 700 !important;
    color: white !important;
    font-size: 1.1rem !important;
}

.row-subcat td {
    padding: 8px 28px !important;
    background: rgba(0,0,0,0.15);
    font-size: 0.9rem !important;
    color: #9ca3af !important;
}
.row-subcat td:first-child {
    padding-left: 50px !important;
}

.pct-bar-container {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 6px;
}
.bar-bg {
    width: 60px;
    height: 6px;
    background: rgba(255,255,255,0.1);
    border-radius: 4px;
    overflow: hidden;
}
.bar-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.5s ease;
}
.pct-text {
    font-size: 0.75rem;
    color: #6b7280;
    font-weight: 600;
    min-width: 35px;
}

.currency-toggle-container {
    display: inline-flex;
    background: rgba(0, 0, 0, 0.3);
    padding: 6px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
}
.btn-currency {
    border: none;
    background: transparent;
    color: #9ca3af;
    padding: 8px 18px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}
.btn-currency.active {
    background: var(--accent);
    color: white !important;
    box-shadow: 0 4px 12px rgba(56, 189, 248, 0.4);
}
</style>

<!-- TOP BAR -->
<div class="comp-top-bar">
    <div>
        <div style="font-size: 0.85rem; color: #38bdf8; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 6px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-cow"></i> Inteligencia de Negocio
        </div>
        <h2 style="margin: 0; font-size: 1.6rem; font-weight: 800; color: white;">Comparativa Mensual</h2>
    </div>
    
    <div style="display:flex; align-items:center; gap: 24px; flex-wrap: wrap;">
        <form id="compareForm" method="GET" style="display:flex; align-items:center; gap: 16px; flex-wrap: wrap;">
            <div style="display:flex; align-items:center; background:rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); border-radius:12px; padding: 8px 16px; gap: 16px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);">
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <span style="font-size:0.65rem; color:#9ca3af; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Mes Base</span>
                    <input type="month" name="mes1" value="<?= $mes1_sel ?>" onchange="this.form.submit()" style="background:transparent; border:none; color:white; font-size:0.95rem; font-weight:600; outline:none; color-scheme:dark; cursor:pointer;">
                </div>
                
                <div style="background:var(--accent); color:white; font-size:0.75rem; font-weight:bold; padding:4px 10px; border-radius:20px; box-shadow: 0 2px 8px rgba(56,189,248,0.3);">VS</div>
                
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <span style="font-size:0.65rem; color:#9ca3af; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Mes Analizado</span>
                    <input type="month" name="mes2" value="<?= $mes2_sel ?>" onchange="this.form.submit()" style="background:transparent; border:none; color:white; font-size:0.95rem; font-weight:600; outline:none; color-scheme:dark; cursor:pointer;">
                </div>
            </div>

            <div style="display:flex; align-items:center; background:rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); border-radius:12px; padding: 8px 16px; gap: 12px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);">
                <div style="background:rgba(56,189,248,0.1); width: 32px; height: 32px; border-radius: 8px; display:flex; align-items:center; justify-content:center;">
                    <i class="fas fa-filter" style="color:var(--accent); font-size:0.9rem;"></i>
                </div>
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <span style="font-size:0.65rem; color:#9ca3af; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Filtrar por Categoría</span>
                    <select name="cat" onchange="this.form.submit()" style="background:transparent; border:none; color:white; font-size:0.95rem; font-weight:600; outline:none; cursor:pointer;">
                        <option value="" style="color:#1f2937;">Todas las Categorías</option>
                        <?php foreach($categorias_db as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $cat_sel === $c ? 'selected' : '' ?> style="color:#1f2937;"><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>

        <div class="currency-toggle-container">
            <button type="button" class="btn-currency active" id="btn-ars" onclick="toggleCurrency('ars')" title="Ver en ARS">ARS</button>
            <button type="button" class="btn-currency" id="btn-usd" onclick="toggleCurrency('usd')" title="Ver en USD">USD</button>
        </div>
    </div>
</div>

<!-- TOP 3 KPIs -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 32px;">
    <!-- Margen Bruto -->
    <div class="kpi-top-card">
        <div style="display:flex; justify-content: space-between; align-items: flex-start;">
            <div class="kpi-icon-wrap" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);">
                <i class="fas fa-chart-line"></i>
            </div>
            <?= render_var_badge($m1['margen_bruto_ars'], $m2['margen_bruto_ars']) ?>
        </div>
        <div>
            <div class="kpi-label">Margen Bruto (<?= format_period($d2_start, $d2_end) ?>)</div>
            <div class="kpi-val">
                <span class="val-ars">$<?= number_format($m2['margen_bruto_ars'], 0, ',', '.') ?></span>
                <span class="val-usd" style="display:none;">U$S <?= number_format($m2['margen_bruto_usd'], 0, ',', '.') ?></span>
            </div>
            <div style="font-size:0.85rem; color:#6b7280; margin-top:6px; font-weight:500;">
                Base (<?= format_period($d1_start, $d1_end, true) ?>): 
                <span class="val-ars">$<?= number_format($m1['margen_bruto_ars'],0,',','.') ?></span>
                <span class="val-usd" style="display:none;">U$S <?= number_format($m1['margen_bruto_usd'],0,',','.') ?></span>
            </div>
        </div>
    </div>

    <!-- Total Ingresos -->
    <div class="kpi-top-card">
        <div style="display:flex; justify-content: space-between; align-items: flex-start;">
            <div class="kpi-icon-wrap" style="background: rgba(56,189,248,0.15); color: #38bdf8; border: 1px solid rgba(56,189,248,0.3);">
                <i class="fas fa-arrow-turn-down" style="transform: rotate(180deg) scaleX(-1);"></i>
            </div>
            <?= render_var_badge($m1['total_ingresos_ars'], $m2['total_ingresos_ars']) ?>
        </div>
        <div>
            <div class="kpi-label">Ingresos Totales (<?= format_period($d2_start, $d2_end) ?>)</div>
            <div class="kpi-val">
                <span class="val-ars">$<?= number_format($m2['total_ingresos_ars'], 0, ',', '.') ?></span>
                <span class="val-usd" style="display:none;">U$S <?= number_format($m2['total_ingresos_usd'], 0, ',', '.') ?></span>
            </div>
            <div style="font-size:0.85rem; color:#6b7280; margin-top:6px; font-weight:500;">
                Base (<?= format_period($d1_start, $d1_end, true) ?>): 
                <span class="val-ars">$<?= number_format($m1['total_ingresos_ars'],0,',','.') ?></span>
                <span class="val-usd" style="display:none;">U$S <?= number_format($m1['total_ingresos_usd'],0,',','.') ?></span>
            </div>
        </div>
    </div>

    <!-- Total Costos -->
    <div class="kpi-top-card">
        <div style="display:flex; justify-content: space-between; align-items: flex-start;">
            <div class="kpi-icon-wrap" style="background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3);">
                <i class="fas fa-arrow-turn-down"></i>
            </div>
            <?= render_var_badge($m1['costos_ars_total'], $m2['costos_ars_total'], true) ?>
        </div>
        <div>
            <div class="kpi-label">Costos Operativos (<?= format_period($d2_start, $d2_end) ?>)</div>
            <div class="kpi-val">
                <span class="val-ars">$<?= number_format($m2['costos_ars_total'], 0, ',', '.') ?></span>
                <span class="val-usd" style="display:none;">U$S <?= number_format($m2['costos_usd'], 0, ',', '.') ?></span>
            </div>
            <div style="font-size:0.85rem; color:#6b7280; margin-top:6px; font-weight:500;">
                Base (<?= format_period($d1_start, $d1_end, true) ?>): 
                <span class="val-ars">$<?= number_format($m1['costos_ars_total'],0,',','.') ?></span>
                <span class="val-usd" style="display:none;">U$S <?= number_format($m1['costos_usd'],0,',','.') ?></span>
            </div>
        </div>
    </div>
</div>

<!-- SECTION GRID 1: Producción y Eficiencia -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 24px; margin-bottom: 24px;">
    
    <div class="section-card">
        <div class="section-header">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(56,189,248,0.1); color:#38bdf8; display:flex; align-items:center; justify-content:center; font-size:1.1rem;"><i class="fas fa-droplet"></i></div>
            <h3 class="section-title">Producción Física</h3>
        </div>
        <div style="overflow-x:auto;">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Indicador</th>
                        <th><?= format_period($d1_start, $d1_end, true) ?></th>
                        <th style="color:white;"><?= format_period($d2_start, $d2_end, true) ?></th>
                        <th>Var.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-jug-detergent" style="color:#6b7280;"></i> Litros Producidos</td>
                        <td><?= number_format($m1['litros'], 0, ',', '.') ?> L</td>
                        <td><?= number_format($m2['litros'], 0, ',', '.') ?> L</td>
                        <td><?= render_var_badge($m1['litros'], $m2['litros'], false, 'text') ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-tag" style="color:#6b7280;"></i> Precio Leche (ARS)</td>
                        <td>$<?= number_format($m1['precio_leche_ars'], 2, ',', '.') ?></td>
                        <td>$<?= number_format($m2['precio_leche_ars'], 2, ',', '.') ?></td>
                        <td><?= render_var_badge($m1['precio_leche_ars'], $m2['precio_leche_ars'], false, 'text') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section-card">
        <div class="section-header">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(168,85,247,0.1); color:#a855f7; display:flex; align-items:center; justify-content:center; font-size:1.1rem;"><i class="fas fa-microscope"></i></div>
            <h3 class="section-title">Análisis de Eficiencia</h3>
        </div>
        <div style="overflow-x:auto;">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Indicador</th>
                        <th><?= format_period($d1_start, $d1_end, true) ?></th>
                        <th style="color:white;"><?= format_period($d2_start, $d2_end, true) ?></th>
                        <th>Var.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-glass-water" style="color:#6b7280;"></i> Costo Final / Lt</td>
                        <td>
                            <span class="val-ars">$<?= number_format($m1['costo_final_ars'], 2, ',', '.') ?></span>
                            <span class="val-usd" style="display:none;">U$S <?= number_format($m1['costo_final_usd'], 3, ',', '.') ?></span>
                        </td>
                        <td>
                            <span class="val-ars">$<?= number_format($m2['costo_final_ars'], 2, ',', '.') ?></span>
                            <span class="val-usd" style="display:none;">U$S <?= number_format($m2['costo_final_usd'], 3, ',', '.') ?></span>
                        </td>
                        <td>
                            <span class="val-ars"><?= render_var_badge($m1['costo_final_ars'], $m2['costo_final_ars'], true, 'text') ?></span>
                            <span class="val-usd" style="display:none;"><?= render_var_badge($m1['costo_final_usd'], $m2['costo_final_usd'], true, 'text') ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-scale-balanced" style="color:#6b7280;"></i> Rinde Indiferencia</td>
                        <td><?= number_format($m1['rinde_indiferencia'], 0, ',', '.') ?> L</td>
                        <td><?= number_format($m2['rinde_indiferencia'], 0, ',', '.') ?> L</td>
                        <td><?= render_var_badge($m1['rinde_indiferencia'], $m2['rinde_indiferencia'], true, 'text') ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-percent" style="color:#6b7280;"></i> Rentabilidad General</td>
                        <td><?= number_format($m1['rentabilidad'], 1) ?>%</td>
                        <td><?= number_format($m2['rentabilidad'], 1) ?>%</td>
                        <td><?= render_var_badge($m1['rentabilidad'], $m2['rentabilidad'], false, 'text') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SECTION: INGRESOS -->
<div class="section-card">
    <div class="section-header" style="background: rgba(251,191,36,0.05); border-bottom-color: rgba(251,191,36,0.15);">
        <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(251,191,36,0.15); color:#fbbf24; display:flex; align-items:center; justify-content:center; font-size:1.1rem;"><i class="fas fa-coins"></i></div>
        <h3 class="section-title">Detalle de Ingresos</h3>
    </div>
    <div style="overflow-x:auto;">
        <table class="table-modern">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th><?= format_period($d1_start, $d1_end, true) ?></th>
                    <th style="color:white;"><?= format_period($d2_start, $d2_end, true) ?></th>
                    <th>Variación</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Ingreso por Leche</td>
                    <td>
                        <span class="val-ars">$<?= number_format($m1['ingreso_leche_ars'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m1['ingreso_leche_usd'], 0, ',', '.') ?></span>
                        <?= render_pct_bar($m1['ingreso_leche_ars'], $m1['total_ingresos_ars'], '#fbbf24') ?>
                    </td>
                    <td>
                        <span class="val-ars">$<?= number_format($m2['ingreso_leche_ars'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m2['ingreso_leche_usd'], 0, ',', '.') ?></span>
                        <?= render_pct_bar($m2['ingreso_leche_ars'], $m2['total_ingresos_ars'], '#fbbf24') ?>
                    </td>
                    <td>
                        <span class="val-ars"><?= render_var_badge($m1['ingreso_leche_ars'], $m2['ingreso_leche_ars']) ?></span>
                        <span class="val-usd" style="display:none;"><?= render_var_badge($m1['ingreso_leche_usd'], $m2['ingreso_leche_usd']) ?></span>
                    </td>
                </tr>
                <tr>
                    <td>Venta de Carne (Real)</td>
                    <td>
                        <span class="val-ars">$<?= number_format($m1['ingreso_carne_real_ars'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m1['ingreso_carne_real_usd'], 0, ',', '.') ?></span>
                        <?= render_pct_bar($m1['ingreso_carne_real_ars'], $m1['total_ingresos_ars'], '#f59e0b') ?>
                    </td>
                    <td>
                        <span class="val-ars">$<?= number_format($m2['ingreso_carne_real_ars'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m2['ingreso_carne_real_usd'], 0, ',', '.') ?></span>
                        <?= render_pct_bar($m2['ingreso_carne_real_ars'], $m2['total_ingresos_ars'], '#f59e0b') ?>
                    </td>
                    <td>
                        <span class="val-ars"><?= render_var_badge($m1['ingreso_carne_real_ars'], $m2['ingreso_carne_real_ars']) ?></span>
                        <span class="val-usd" style="display:none;"><?= render_var_badge($m1['ingreso_carne_real_usd'], $m2['ingreso_carne_real_usd']) ?></span>
                    </td>
                </tr>
                <tr>
                    <td>Diferencia de Inventario</td>
                    <td>
                        <span class="val-ars">$<?= number_format($m1['ingreso_dif_inv_ars'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m1['ingreso_dif_inv_usd'], 0, ',', '.') ?></span>
                        <?= render_pct_bar($m1['ingreso_dif_inv_ars'], $m1['total_ingresos_ars'], '#d97706') ?>
                    </td>
                    <td>
                        <span class="val-ars">$<?= number_format($m2['ingreso_dif_inv_ars'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m2['ingreso_dif_inv_usd'], 0, ',', '.') ?></span>
                        <?= render_pct_bar($m2['ingreso_dif_inv_ars'], $m2['total_ingresos_ars'], '#d97706') ?>
                    </td>
                    <td>
                        <span class="val-ars"><?= render_var_badge($m1['ingreso_dif_inv_ars'], $m2['ingreso_dif_inv_ars']) ?></span>
                        <span class="val-usd" style="display:none;"><?= render_var_badge($m1['ingreso_dif_inv_usd'], $m2['ingreso_dif_inv_usd']) ?></span>
                    </td>
                </tr>
                <tr class="row-total">
                    <td><i class="fas fa-sack-dollar" style="color:#fbbf24; margin-right:8px;"></i> Total Ingresos</td>
                    <td>
                        <span class="val-ars">$<?= number_format($m1['total_ingresos_ars'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m1['total_ingresos_usd'], 0, ',', '.') ?></span>
                    </td>
                    <td>
                        <span class="val-ars">$<?= number_format($m2['total_ingresos_ars'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m2['total_ingresos_usd'], 0, ',', '.') ?></span>
                    </td>
                    <td>
                        <span class="val-ars"><?= render_var_badge($m1['total_ingresos_ars'], $m2['total_ingresos_ars']) ?></span>
                        <span class="val-usd" style="display:none;"><?= render_var_badge($m1['total_ingresos_usd'], $m2['total_ingresos_usd']) ?></span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- SECTION: EGRESOS -->
<div class="section-card">
    <div class="section-header" style="background: rgba(239,68,68,0.05); border-bottom-color: rgba(239,68,68,0.15);">
        <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(239,68,68,0.15); color:#f87171; display:flex; align-items:center; justify-content:center; font-size:1.1rem;"><i class="fas fa-file-invoice-dollar"></i></div>
        <h3 class="section-title">Estructura de Costos Detallada</h3>
    </div>
    <div style="overflow-x:auto;">
        <table class="table-modern">
            <thead>
                <tr>
                    <th>Categoría / Subcategoría</th>
                    <th><?= format_period($d1_start, $d1_end, true) ?></th>
                    <th style="color:white;"><?= format_period($d2_start, $d2_end, true) ?></th>
                    <th>Variación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_cats as $cat): 
                    $cat1_ars = $m1['costos_cat_ars'][$cat] ?? 0;
                    $cat1_usd = $m1['costos_cat_usd'][$cat] ?? 0;
                    $cat2_ars = $m2['costos_cat_ars'][$cat] ?? 0;
                    $cat2_usd = $m2['costos_cat_usd'][$cat] ?? 0;
                    
                    // Subcategorias combination
                    $subs1 = array_keys($m1['costos_subcat_ars'][$cat] ?? []);
                    $subs2 = array_keys($m2['costos_subcat_ars'][$cat] ?? []);
                    $all_subs = array_unique(array_merge($subs1, $subs2));
                    sort($all_subs);
                ?>
                <!-- Main Category Row -->
                <tr>
                    <td style="font-weight: 700; color: white;"><i class="fas fa-tag" style="color:#f87171; font-size:0.8rem; margin-right: 6px;"></i> <?= htmlspecialchars($cat) ?></td>
                    <td>
                        <span class="val-ars" style="font-weight: 700;">$<?= number_format($cat1_ars, 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none; font-weight: 700;">U$S <?= number_format($cat1_usd, 0, ',', '.') ?></span>
                        <?= render_pct_bar($cat1_ars, $m1['costos_ars_total'], '#f87171') ?>
                    </td>
                    <td>
                        <span class="val-ars" style="font-weight: 700;">$<?= number_format($cat2_ars, 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none; font-weight: 700;">U$S <?= number_format($cat2_usd, 0, ',', '.') ?></span>
                        <?= render_pct_bar($cat2_ars, $m2['costos_ars_total'], '#f87171') ?>
                    </td>
                    <td>
                        <span class="val-ars"><?= render_var_badge($cat1_ars, $cat2_ars, true) ?></span>
                        <span class="val-usd" style="display:none;"><?= render_var_badge($cat1_usd, $cat2_usd, true) ?></span>
                    </td>
                </tr>
                
                <!-- Subcategories Rows -->
                <?php foreach ($all_subs as $sub): 
                    $sub1_ars = $m1['costos_subcat_ars'][$cat][$sub] ?? 0;
                    $sub1_usd = $m1['costos_subcat_usd'][$cat][$sub] ?? 0;
                    $sub2_ars = $m2['costos_subcat_ars'][$cat][$sub] ?? 0;
                    $sub2_usd = $m2['costos_subcat_usd'][$cat][$sub] ?? 0;
                ?>
                <tr class="row-subcat">
                    <td>↳ <?= htmlspecialchars($sub) ?></td>
                    <td>
                        <span class="val-ars">$<?= number_format($sub1_ars, 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($sub1_usd, 0, ',', '.') ?></span>
                    </td>
                    <td>
                        <span class="val-ars">$<?= number_format($sub2_ars, 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($sub2_usd, 0, ',', '.') ?></span>
                    </td>
                    <td>
                        <span class="val-ars"><?= render_var_badge($sub1_ars, $sub2_ars, true, 'text') ?></span>
                        <span class="val-usd" style="display:none;"><?= render_var_badge($sub1_usd, $sub2_usd, true, 'text') ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
                
                <tr class="row-total">
                    <td><i class="fas fa-wallet" style="color:#f87171; margin-right:8px;"></i> Total Costos</td>
                    <td>
                        <span class="val-ars">$<?= number_format($m1['costos_ars_total'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m1['costos_usd'], 0, ',', '.') ?></span>
                    </td>
                    <td>
                        <span class="val-ars">$<?= number_format($m2['costos_ars_total'], 0, ',', '.') ?></span>
                        <span class="val-usd" style="display:none;">U$S <?= number_format($m2['costos_usd'], 0, ',', '.') ?></span>
                    </td>
                    <td>
                        <span class="val-ars"><?= render_var_badge($m1['costos_ars_total'], $m2['costos_ars_total'], true) ?></span>
                        <span class="val-usd" style="display:none;"><?= render_var_badge($m1['costos_usd'], $m2['costos_usd'], true) ?></span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleCurrency(mode) {
    const btnArs = document.getElementById('btn-ars');
    const btnUsd = document.getElementById('btn-usd');
    const valsArs = document.querySelectorAll('.val-ars');
    const valsUsd = document.querySelectorAll('.val-usd');
    
    if (mode === 'ars') {
        btnArs.classList.add('active');
        btnUsd.classList.remove('active');
        valsArs.forEach(el => el.style.display = 'inline');
        valsUsd.forEach(el => el.style.display = 'none');
    } else {
        btnUsd.classList.add('active');
        btnArs.classList.remove('active');
        valsUsd.forEach(el => el.style.display = 'inline');
        valsArs.forEach(el => el.style.display = 'none');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>