<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Tablero de Control Agrícola';

// Validación CSRF para todas las peticiones POST (ej: actualización de pizarra)
validate_csrf();

require_once 'controllers/DashboardController.php';
require_once 'includes/frescura.php';
require_once 'includes/exportar.php';

$controller = new DashboardController($pdo, $usuario_id);





// ── Precios SIO-Granos (última cotización disponible) ─────────────────────────
$sio_precios = [];
$sio_fecha   = null;
try {
    // Se toma la última fila DE CADA CULTIVO, no la fecha máxima global: no todos
    // los granos cotizan el mismo día, y con el filtro global el girasol o el
    // sorgo desaparecían del ticker los días que no operaron.
    $sio_stmt = $pdo->query("
        SELECT c.cultivo, c.precio_promedio, c.precio_minimo, c.precio_maximo, c.fecha, c.zona
        FROM cotizaciones_siogranos c
        INNER JOIN (
            SELECT cultivo, MAX(fecha) AS fecha
            FROM cotizaciones_siogranos
            WHERE cultivo IN ('Soja Cámara', 'Maíz', 'Trigo Cámara', 'Girasol Cámara', 'Sorgo')
            GROUP BY cultivo
        ) ult ON ult.cultivo = c.cultivo AND ult.fecha = c.fecha
        WHERE c.cultivo IN ('Soja Cámara', 'Maíz', 'Trigo Cámara', 'Girasol Cámara', 'Sorgo')
        ORDER BY c.cultivo, c.fecha_actualizacion
    ");
    $sio_rows = $sio_stmt->fetchAll();
    foreach ($sio_rows as $row) {
        // Si hubiera más de una fila para el mismo cultivo y fecha (por ejemplo
        // una vieja de SIO-Granos y una nueva de la pizarra), gana la más
        // recientemente actualizada: el ORDER BY las deja al final.
        $sio_precios[$row['cultivo']] = $row;
        if ($sio_fecha === null || $row['fecha'] > $sio_fecha) {
            $sio_fecha = $row['fecha'];
        }
    }
} catch (\Exception $e) {
    // Tabla aún no existe o sin datos — no mostramos sección SIO
}
$ciclos = $controller->getCiclos();
$ciclo_sel = $_GET['ciclo'] ?? ($ciclos[0] ?? null);

// Filtros adicionales del panel: por lote y por cultivo (dependen de la campaña elegida)
$lotes_filtro    = $controller->getLotesDelCiclo($ciclo_sel);
$cultivos_filtro = $controller->getCultivosDelCiclo($ciclo_sel);

$lote_sel    = isset($_GET['lote']) && $_GET['lote'] !== '' ? (int)$_GET['lote'] : null;
$cultivo_sel = isset($_GET['cultivo']) && $_GET['cultivo'] !== '' ? $_GET['cultivo'] : null;

// Validar que los filtros recibidos pertenezcan a la campaña actual (evita estados inconsistentes)
if ($lote_sel !== null && !in_array($lote_sel, array_map(fn($l) => (int)$l['id'], $lotes_filtro), true)) {
    $lote_sel = null;
}
if ($cultivo_sel !== null && !in_array($cultivo_sel, $cultivos_filtro, true)) {
    $cultivo_sel = null;
}

/* Moneda en que se MUESTRA todo el panel. No cambia lo que se guardó: cada
   movimiento conserva la suya y se convierte con la cotización de su propio mes.
   Es el mismo botón que ya convertía la pizarra de la Cámara; ahora convierte
   también los números propios, que era lo que faltaba para poder compararlos. */
require_once 'includes/moneda.php';
$moneda_sel = moneda_actual();

// Obtener datos globales limpiamente
$stats = $controller->getGlobalStats($ciclo_sel, $lote_sel, $cultivo_sel, null, $moneda_sel);
$ingresos_global = $stats['ingresos'];
$costos_directos_global = $stats['costos_directos'];
$costos_alquiler_global = $stats['costos_alquiler'];
$hectareas_ciclo = $stats['hectareas'];
$kg_total = $stats['kg'];
$margen_neto_global = $stats['margen_neto'];
$rendimiento_ha = $stats['rendimiento_ha'];

// ap_simbolo(), ap_plata() y ap_egreso() viven en includes/moneda.php, que es el
// mismo que usan las otras pantallas de Agricultura para decir lo mismo.

// Con qué tipo de cambio se convirtieron a USD los alquileres pagados en pesos.
// Si el productor nunca cargó ninguna cotización, el margen sale de un valor fijo
// del código y hay que decirlo: es la diferencia entre un número y una suposición.
$dolar_info = $controller->getDolarInfo();

// Pestañas por Especie
$cultivos_data = $controller->getCultivosData($ciclo_sel, $lote_sel, $cultivo_sel, $moneda_sel);

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
    .tab-btn { padding: 9px 18px; border-radius: 8px; background: var(--n-0); color: var(--text-muted); cursor: pointer; border: 1px solid var(--border); white-space: nowrap; transition: all 0.2s; flex-shrink: 0; font-size: 0.9rem; min-height: 40px; }
    .tab-btn:hover { background: var(--n-100); color: var(--text-primary); }
    /* Blanco sobre el verde de campo = 8:1. Con el emerald anterior daba 2,54:1. */
    .tab-btn.active { background: var(--accent); color: var(--on-accent); border-color: var(--accent); font-weight: 600; }
    .cultivo-panel { display: none; }
    .cultivo-panel.active { display: block; }

    /* =====================
       ESTADO "ACTUALIZANDO"

       Nada de spinner en el medio del contenido: los números que ya están se
       atenúan y dejan de responder al mouse mientras llega el reemplazo. Se ve
       que algo está pasando sin perder de vista lo que había, que es justo lo
       que la recarga completa destruía.
       ===================== */
    #panel-filtros, #panel-contenido { transition: opacity 0.18s ease; }
    .ap-cargando { opacity: 0.45; pointer-events: none; }

    @media (prefers-reduced-motion: reduce) {
        #panel-filtros, #panel-contenido { transition: none; }
    }

    <?php /* .ap-solo-lectores se mudó a assets/css/style.css: la usa también el
             chat flotante, que ahora vive en las seis pantallas de Agricultura.
             Definida acá, en las otras páginas la clase no existía y las etiquetas
             para lectores de pantalla —"Escribí tu pregunta", "Enviar"— se veían
             como texto suelto al lado de los botones. */ ?>

    /* Lote Card Refined */
    .lote-card {
        background: var(--n-0);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 24px;
        transition: border-color 0.2s ease;
        display: flex;
        flex-direction: column;
        position: relative;
        overflow: hidden;
    }
    .lote-card:hover {
        border-color: var(--accent);
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
        border-bottom: 1px solid var(--border);
        font-size: 0.9rem;
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
        transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.25s cubic-bezier(0.22, 1, 0.36, 1);
    }
    
    .stat-card .title {
        font-size: 0.88rem;
        font-weight: 600;
        color: var(--text-muted);
    }

    .stat-card .value {
        font-size: 1.9rem;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    .stat-card .trend {
        color: var(--text-muted);
        font-size: 0.85rem;
        margin-top: auto;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: color 0.2s;
    }
    a.stat-card:hover .trend {
        color: var(--accent);
    }
    /* El hover mueve el borde, no la tarjeta: en una grilla de seis, seis
       tarjetas que se levantan es ruido, y el salto de 4px arrastra la vista. */
    a.stat-card:hover {
        border-color: var(--accent);
    }

    <?php /* El estilo del selector de moneda se mudó a assets/css/style.css como
             .ap-moneda: lo usan las seis pantallas de Agricultura y tenerlo acá
             obligaba a copiarlo en cada una —que fue exactamente lo que pasó, y por
             eso en tres pantallas salía como un enlace subrayado—. */ ?>
</style>

<!-- Ticker de Mercado -->
<div class="glass-panel" style="margin-bottom: 24px; padding: 16px 20px;">
    <div class="ticker-bar">
        <div style="display:flex; align-items:center; gap:20px; flex-wrap: wrap; width: 100%;">

            <?php if (!empty($sio_precios)): ?>
            <!-- Precios SIO-Granos (ARS) -->
            <div style="display:flex; align-items:center; gap:10px; flex-wrap: wrap; width: 100%;">
                <?php /* flex-wrap y no flex-shrink:0: los dos hijos son nowrap, así
                         que en un teléfono el aviso de "desactualizado" se cortaba
                         contra el borde. Un aviso a medio leer no avisa nada. */ ?>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; min-width:0;">
                    <span class="badge" style="background:var(--accent-soft); color:var(--accent); border:1px solid oklch(0.520 0.100 150 / 0.35); padding:5px 11px; font-size:0.8rem; white-space:nowrap;">
                        <i class="fas fa-chart-line"></i> MERCADO SIO-GRANOS
                    </span>
                    <?php
                    // La fecha sola no alcanza: un precio de hace una semana se
                    // leía igual de confiable que el de hoy. Cuando la fuente deja
                    // de publicar, esto lo dice con todas las letras.
                    $sio_frescura = evaluar_frescura($sio_fecha);
                    ?>
                    <?php if ($sio_fecha): ?>
                        <?php if ($sio_frescura['viejo']): ?>
                        <span class="dato-viejo" title="El último dato publicado por la Cámara es del <?= date('d/m/Y', strtotime($sio_fecha)) ?>. Los precios que ves son de esa fecha.">
                            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                            <?= htmlspecialchars($sio_frescura['texto']) ?>
                        </span>
                        <?php else: ?>
                        <span style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;">
                            <i class="fas fa-clock" style="margin-right:3px;"></i>
                            <?= htmlspecialchars($sio_frescura['texto']) ?>
                        </span>
                        <?php endif; ?>
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
                        'Sorgo'          => ['icon'=>'fa-spa',       'color'=>'var(--accent)'],
                    ];
                    /* Sólo los que la Cámara publicó hoy, en orden. Se filtra antes
                       del bucle y no adentro, porque hace falta saber cuál es el
                       último REAL para no ponerle separador. */
                    $sio_visibles = array_values(array_filter($sio_orden, fn($c) => isset($sio_precios[$c])));
                    foreach ($sio_visibles as $cultivo):
                        $p = $sio_precios[$cultivo];
                        $ic = $sio_iconos[$cultivo] ?? ['icon'=>'fa-circle','color'=>'var(--text-muted)'];
                        $label_corto = str_replace([' Cámara'], [''], $cultivo);
                    ?>
                    <div style="display:flex; flex-direction:column; min-width:90px;">
                        <span style="font-size:0.82rem; color:var(--text-muted); font-weight:600; display:flex; align-items:center; gap:5px; white-space:nowrap;">
                            <i class="fas <?= $ic['icon'] ?>" style="color:<?= $ic['color'] ?>; font-size:0.75em;"></i>
                            <?= htmlspecialchars($label_corto) ?>
                        </span>
                        <span class="sio-price-container" style="font-size:1.05rem; font-weight:700; color:var(--text-primary); white-space:nowrap;">
                            <span class="sio-val" data-ars="<?= (float)$p['precio_promedio'] ?>">$<?= number_format((float)$p['precio_promedio'], 0, ',', '.') ?></span>
                            <small class="sio-unit" style="font-size:0.8rem; color:var(--text-muted); font-weight:400;">ARS/ton</small>
                        </span>
                        <?php
                        // La pizarra de la Cámara publica un precio único, sin rango.
                        // Cuando no hay mínimo/máximo (o son iguales) se muestra la fecha
                        // de esa cotización, que además puede diferir entre cultivos.
                        $tiene_rango = $p['precio_minimo'] !== null && $p['precio_maximo'] !== null
                                    && (float)$p['precio_minimo'] !== (float)$p['precio_maximo'];
                        ?>
                        <span style="font-size:0.8rem; color:var(--text-muted);">
                            <?php if ($tiene_rango): ?>
                            <span class="sio-minmax" data-min="<?= (float)$p['precio_minimo'] ?>" data-max="<?= (float)$p['precio_maximo'] ?>">
                                <?= number_format((float)$p['precio_minimo'],0,',','.') ?> – <?= number_format((float)$p['precio_maximo'],0,',','.') ?>
                            </span>
                            <?php else: ?>
                            <?= date('d/m/Y', strtotime($p['fecha'])) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php /* Separador ENTRE precios. El último no lleva: el bloque
                             del dólar que viene después trae su propia línea, y
                             las dos juntas se veían como un borde doble. */ ?>
                    <?php if ($cultivo !== end($sio_visibles)): ?>
                    <div style="width:1px; background:var(--border); height:36px; flex-shrink:0;"></div>
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- Bloque Dólar Mayorista -->
                    <div style="display:flex; flex-direction:column; min-width:100px; border-left: 1px solid var(--border); padding-left: 15px;">
                        <span style="font-size:0.82rem; color:var(--accent); font-weight:700; display:flex; align-items:center; gap:5px; white-space:nowrap;">
                            <i class="fas fa-landmark" style="font-size:0.75em;"></i> DÓLAR BNA
                        </span>
                        <span style="font-size:1.05rem; font-weight:700; color:var(--text-primary); white-space:nowrap;">
                            <span id="dolar-ticker">...</span>
                            <small style="font-size:0.8rem; color:var(--text-muted); font-weight:400;">Mayorista</small>
                        </span>
                        <span style="font-size:0.8rem; color:var(--text-muted);">Cotización del día</span>
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
            <?php /* Mismo componente que las otras pantallas, pero con <button> en
                     vez de <a>: acá el control además convierte la pizarra de la
                     Cámara por JavaScript, así que no puede ser una navegación.
                     El estado activo se renderiza desde el servidor porque el panel
                     ya viene calculado en esa moneda: si lo pusiera el JavaScript,
                     al entrar con ?moneda=USD diría ARS un instante sobre números
                     que ya son dólares. */ ?>
            <div class="ap-moneda">
                <span class="ap-moneda__etiqueta" id="ap-moneda-lbl">Ver en</span>
                <div class="ap-moneda__grupo" role="group" aria-labelledby="ap-moneda-lbl">
                    <button type="button" class="ap-moneda__op" id="btnCurrencyARS"
                            aria-current="<?= $moneda_sel === 'ARS' ? 'true' : 'false' ?>"
                            title="Ver los importes en pesos"
                            onclick="setTickerCurrency('ARS')">$ ARS</button>
                    <button type="button" class="ap-moneda__op" id="btnCurrencyUSD"
                            aria-current="<?= $moneda_sel === 'USD' ? 'true' : 'false' ?>"
                            title="Ver los importes en dólares"
                            onclick="setTickerCurrency('USD')">US$ USD</button>
                </div>
            </div>            <!-- Filtros del Panel -->
            <?php /* id="panel-filtros": es una de las dos regiones que se reemplazan
                     al filtrar sin recargar. Las opciones de lote y cultivo dependen
                     de la campaña, así que los selects también se renuevan. */ ?>
            <div id="panel-filtros" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <i class="fas fa-filter" style="color:var(--text-muted); flex-shrink:0;"></i>
                <!-- Campaña -->
                <?php /* aria-label porque no hay etiqueta visible: al lado sólo hay un
                         ícono de embudo, que un lector de pantalla no lee. Sin esto se
                         anuncian tres listas desplegables sin decir de qué son. */ ?>
                <select aria-label="Filtrar por campaña" onchange="navFiltro('ciclo', this.value)" style="padding:9px 14px; border-radius:8px; border:1px solid var(--accent); background:var(--accent-soft); color:var(--text-primary); cursor:pointer; font-weight:600; min-width:0; max-width:180px; min-height:40px;">
                    <?php foreach($ciclos as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $c == $ciclo_sel ? 'selected' : '' ?>>Campaña <?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                    <?php if(empty($ciclos)): ?>
                        <option>Sin Campañas</option>
                    <?php endif; ?>
                </select>

                <?php if($ciclo_sel && !empty($lotes_filtro)): ?>
                <!-- Lote -->
                <select aria-label="Filtrar por lote" onchange="navFiltro('lote', this.value)" style="padding:9px 14px; border-radius:8px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary); cursor:pointer; font-weight:500; min-width:0; max-width:180px; min-height:40px;">
                    <option value="">Todos los lotes</option>
                    <?php foreach($lotes_filtro as $lf): ?>
                        <option value="<?= (int)$lf['id'] ?>" <?= ((int)$lf['id'] === $lote_sel) ? 'selected' : '' ?>><?= htmlspecialchars($lf['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <?php if($ciclo_sel && !empty($cultivos_filtro)): ?>
                <!-- Cultivo -->
                <select aria-label="Filtrar por cultivo" onchange="navFiltro('cultivo', this.value)" style="padding:9px 14px; border-radius:8px; border:1px solid var(--border); background:var(--n-0); color:var(--text-primary); cursor:pointer; font-weight:500; min-width:0; max-width:180px; min-height:40px;">
                    <option value="">Todos los cultivos</option>
                    <?php foreach($cultivos_filtro as $cf): ?>
                        <option value="<?= htmlspecialchars($cf) ?>" <?= ($cf === $cultivo_sel) ? 'selected' : '' ?>><?= htmlspecialchars($cf) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>



<?php /* La otra región dinámica: todo lo que depende de los filtros. El servidor
         la sigue renderizando igual que siempre; al filtrar, el cliente pide esta
         misma URL y reemplaza sólo este bloque. Una sola fuente de verdad para el
         markup, y si el JS falla los selects siguen navegando como antes. */ ?>
<div id="panel-aviso" class="ap-solo-lectores" role="status" aria-live="polite"></div>

<div id="panel-contenido">
<?php if(!$ciclo_sel): ?>
    <div class="glass-panel" style="text-align: center; padding: 50px;">
        <i class="fas fa-seedling" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 20px;"></i>
        <h2>Comienza tu Planificación</h2>
        <p style="color: var(--text-muted);">Para ver el tablero, registra tu primer lote y crea una campaña/cultivo con su ciclo comercial.</p>
        <a href="lotes.php" class="btn btn-primary" style="margin-top: 20px; display: inline-block;">Ir a Lotes y Cultivos</a>
    </div>
<?php else: ?>

    <?php
    // Parámetros del reporte del panel general, preservando los filtros activos.
    // Los comparten el PDF y el Excel: se exporta lo mismo que se está mirando.
    $reporte_params = http_build_query(array_filter([
        'tipo'    => 'dashboard',
        'ciclo'   => $ciclo_sel,
        'lote'    => $lote_sel,
        'cultivo' => $cultivo_sel,
    ], fn($v) => $v !== null && $v !== ''));
    ?>
    <!-- Barra de acciones del panel general -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
        <h2 style="font-size:1.1rem; font-weight:600; color:var(--text-primary); margin:0;">
            <i class="fas fa-chart-pie" style="color:var(--accent); margin-right:8px;"></i>
            Resumen de la Campaña <?= htmlspecialchars($ciclo_sel) ?>
        </h2>
        <?php boton_exportar([
            ['etiqueta' => 'Excel', 'href' => 'api/reporte_excel.php?' . $reporte_params,
             'icono' => 'fa-file-excel', 'color' => '#10b981', 'detalle' => 'Detalle por lote, editable'],
            ['etiqueta' => 'PDF',   'href' => 'api/reporte_pdf.php?' . $reporte_params,
             'icono' => 'fa-file-pdf',   'color' => 'var(--danger)', 'detalle' => 'Listo para imprimir',
             'nueva_pestana' => true],
        ], 'Exportar reporte'); ?>
    </div>

    <?php
    // ── Honestidad del dato: con qué dólar se calculó este margen ──────────
    //
    // Los alquileres pagados en pesos se convierten a USD para poder sumarlos al
    // resto. Si esa conversión salió de un valor supuesto, el margen de arriba es
    // aproximado, y eso tiene que estar dicho donde se lee el número — no en una
    // ayuda escondida. Dos situaciones distintas y con distinta gravedad:
    //
    //   sin_cotizacion : no hay NINGUNA cotización cargada. El margen se calculó
    //                    con un valor fijo del código. Es el caso grave.
    //   sin_mes        : hay cotizaciones, pero algún pago no tiene la de su mes
    //                    y se usó la última disponible. Es una aproximación
    //                    razonable, pero conviene decirla.
    $sin_cotizacion = !empty($dolar_info['estimado']) && $costos_alquiler_global > 0;
    $sin_mes        = !$sin_cotizacion && ($stats['alquiler_sin_cotizacion'] ?? 0) > 0;
    ?>
    <?php if ($sin_cotizacion): ?>
    <div class="aviso-dolar aviso-dolar-fuerte">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
        <div>
            <strong>El margen es aproximado: no hay tipo de cambio cargado.</strong>
            Los alquileres pagados en pesos se convirtieron a dólares usando un valor supuesto
            de $<?= number_format(DOLAR_ULTIMO_RECURSO, 0, ',', '.') ?>, que probablemente no sea el real.
            <a href="alquileres.php#tipo-de-cambio">Cargá el dólar del mes</a> para que el número cierre.
        </div>
    </div>
    <?php elseif ($sin_mes): ?>
    <div class="aviso-dolar">
        <i class="fas fa-circle-info" aria-hidden="true"></i>
        <div>
            <?= (int)$stats['alquiler_sin_cotizacion'] ?>
            pago<?= $stats['alquiler_sin_cotizacion'] > 1 ? 's' : '' ?> en pesos sin cotización de su mes:
            se convirtió con el dólar de <?= htmlspecialchars($dolar_info['mes'] ?? '') ?>
            ($<?= number_format($dolar_info['valor'], 0, ',', '.') ?>).
            <a href="alquileres.php#tipo-de-cambio">Completar los meses faltantes</a>.
        </div>
    </div>
    <?php endif; ?>

    <!-- Resumen Global del Ciclo (cards clicables) -->
    <div class="dashboard-grid" style="margin-bottom: 30px;">
        <?php /* Un solo número en color: el margen, que es el que decide. Los demás
                 van en tinta. Antes había seis colores compitiendo (verde, naranja,
                 rojo, índigo, ámbar) y ninguno significaba nada distinto de los otros,
                 así que el ojo no tenía dónde caer primero. */ ?>
        <a href="produccion.php" class="glass-panel stat-card" title="Ver Producción y Ventas">
            <span class="title">Margen Neto Ciclo</span>
            <span class="value" style="<?= $margen_neto_global >= 0 ? 'color: var(--accent);' : 'color: var(--danger);' ?>">
                <?= ap_plata($margen_neto_global) ?>
            </span>
            <span class="trend">Neto tras Alquileres e Insumos <i class="fas fa-arrow-right"></i></span>
        </a>
        <a href="produccion.php" class="glass-panel stat-card" title="Ver Producción">
            <span class="title">Rinde Promedio</span>
            <span class="value"><?= number_format($rendimiento_ha, 2, ',', '.') ?> <small style="font-size: 0.9rem; font-weight: 500; color: var(--text-muted);">kg/Ha</small></span>
            <span class="trend">En <?= number_format($hectareas_ciclo, 1, ',', '.') ?> ha trabajadas <i class="fas fa-arrow-right"></i></span>
        </a>
        <a href="operaciones.php" class="glass-panel stat-card" title="Ver Costos y Labores">
            <span class="title">Costos de Laboreo</span>
            <span class="value"><?= ap_egreso($costos_directos_global) ?></span>
            <span class="trend">Insumos + Labores Directas <i class="fas fa-arrow-right"></i></span>
        </a>
        <a href="alquileres.php" class="glass-panel stat-card" title="Ver Alquileres">
            <span class="title">Alquileres Pagados</span>
            <span class="value"><?= ap_egreso($costos_alquiler_global) ?></span>
            <span class="trend">Pagos reales registrados <i class="fas fa-arrow-right"></i></span>
        </a>
        <a href="operaciones.php" class="glass-panel stat-card" title="Ver Operaciones">
            <span class="title">Costo / ha</span>
            <?php if ($stats['costo_por_ha'] > 0): ?>
                <span class="value"><?= ap_plata($stats['costo_por_ha']) ?></span>
                <span class="trend">Costo global por superficie <i class="fas fa-arrow-right"></i></span>
            <?php else: ?>
                <span class="value" style="color: var(--text-muted); font-size: 1.5rem;">—</span>
                <span class="trend">Sin hectáreas registradas <i class="fas fa-arrow-right"></i></span>
            <?php endif; ?>
        </a>
        <a href="produccion.php" class="glass-panel stat-card" title="Punto de Equilibrio">
            <span class="title">Rinde Indiferencia</span>
            <?php if ($stats['punto_equilibrio_kg_ha'] > 0): ?>
                <span class="value"><?= number_format($stats['punto_equilibrio_kg_ha'], 0, ',', '.') ?> <small style="font-size: 0.9rem; font-weight: 500; color: var(--text-muted);">kg/Ha</small></span>
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
                    <div style="font-size:0.8rem; color:var(--text-muted);">Total</div>
                    <div id="donaCentroVal" class="dona-center-val" style="font-size:1.15rem; font-weight:700;">$0</div>
                </div>
            </div>
            <div id="donaLeyenda" style="margin-top:14px; display:flex; flex-direction:column; gap:6px; font-size:0.8rem;"></div>
        </div>

        <!-- Barras: Ingresos vs Costos por Lote -->
        <div class="glass-panel" style="padding:20px; flex: 2 1 450px; min-width: 0;">
            <h3 style="font-size:0.95rem; font-weight:600; margin-bottom:14px; color:var(--text-muted);">
                <i class="fas fa-chart-bar" style="color:var(--accent); margin-right:6px;"></i>
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

        /* La pizarra en una sola fila que se corre con el dedo, como una pizarra
           de verdad. Envuelta ocupaba media pantalla de teléfono: había que pasar
           seis precios de mercado para llegar al primer número propio. El último
           precio queda cortado a propósito, que es lo que avisa que hay más. */
        #sioTickerPrices {
            flex-wrap: nowrap !important;
            width: 100%;
            gap: 14px !important;
            padding-bottom: 6px;
            scroll-snap-type: x proximity;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
        }
        #sioTickerPrices > * { flex: 0 0 auto; scroll-snap-align: start; }
    }
    </style>

    <div class="tab-nav">
        <?php $idx = 0; foreach($cultivos_data as $especie => $data): ?>
            <button class="tab-btn <?= $idx === 0 ? 'active' : '' ?>" onclick="showTab('tab-<?= $idx ?>', this)">
<?php /* `inherit` y no --text-muted: dentro del tab activo el fondo es verde
         lleno, y un gris apagado ahí da 1,13:1. Hereda el color del botón y
         funciona en los dos estados. */ ?>
                <?= $especie ?> <small style="margin-left:5px;color:inherit;font-weight:500;">(<?= number_format($data['total_ingreso'] - $data['total_costo'] - $data['total_alq'], 0, ',', '.') ?> USD)</small>
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
                    // Valores por hectárea (se muestran en vez de los totales)
                    $sup_lote     = $lote['sup'] > 0 ? (float)$lote['sup'] : 0;
                    $margen_ha    = $sup_lote > 0 ? $margen_lote / $sup_lote : 0;
                    $ingreso_ha   = $sup_lote > 0 ? $lote['ingreso'] / $sup_lote : 0;
                    $labores_ha   = $sup_lote > 0 ? $lote['labores'] / $sup_lote : 0;
                    $insumos_ha   = $sup_lote > 0 ? $lote['insumos'] / $sup_lote : 0;
                    $alquiler_ha  = $sup_lote > 0 ? $lote['alquiler'] / $sup_lote : 0;
                ?>
                    <div class="lote-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                            <div>
                                <h3 style="font-size: 1.25rem; font-weight: 700; margin: 0; letter-spacing: -0.02em; color: var(--text-primary);"><?= htmlspecialchars($lote['nombre']) ?></h3>
                                <div style="display:flex; align-items:center; gap:6px; margin-top:4px;">
                                    <i class="fas fa-vector-square" style="font-size:0.75rem; color:var(--text-muted);"></i>
                                    <span style="color: var(--text-muted); font-size: 0.85rem; font-weight:500;"><?= number_format($lote['sup'], 1, ',', '.') ?> ha</span>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div class="badge" style="background: <?= $margen_lote >= 0 ? 'var(--accent-soft)' : 'oklch(0.450 0.160 28 / 0.10)' ?>; color: <?= $margen_lote >= 0 ? 'var(--accent)' : 'var(--danger)' ?>; border: 1px solid <?= $margen_lote >= 0 ? 'oklch(0.520 0.100 150 / 0.35)' : 'oklch(0.450 0.160 28 / 0.35)' ?>; font-weight:700; padding: 6px 12px; border-radius: 6px;">
                                    <?= ap_plata($margen_ha, 0) ?> <small style="font-weight:500;">/ha</small>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; font-weight: 600;">Margen Neto / ha</div>
                            </div>
                        </div>

                        <!-- Progress Bar / ROI Indicator -->
                        <div style="margin-bottom: 20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <span style="font-size: 0.82rem; color: var(--text-muted); font-weight: 600;">Retorno (ROI)</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: <?= $roi >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;"><?= number_format($roi, 1, ',', '.') ?>%</span>
                            </div>
                            <div style="height: 6px; background: var(--n-200); border-radius: 3px; overflow: hidden;">
                                <div style="height: 100%; width: <?= min(max($roi, 0), 100) ?>%; background: <?= $roi >= 50 ? 'var(--accent)' : ($roi > 0 ? '#fbbf24' : 'var(--danger)') ?>; box-shadow: 0 0 10px <?= $roi >= 50 ? 'rgba(16,185,129,0.4)' : 'rgba(251,191,36,0.4)' ?>;"></div>
                            </div>
                        </div>

                        <div class="lote-details" style="display: flex; flex-direction: column; gap: 4px;">
                            <div class="lote-detail-row">
                                <span class="label"><i class="fas fa-coins" style="color: #fbbf24;"></i> Ingresos</span>
                                <span class="value" style="color: var(--text-primary);"><?= ap_plata($ingreso_ha, 0) ?> <small style="color:var(--text-muted); font-weight:400;">/ha</small></span>
                            </div>

                            <div class="lote-detail-row">
                                <span class="label"><i class="fas fa-person-digging"></i> Labores</span>
                                <span class="value"><?= ap_egreso($labores_ha, 0) ?> <small style="color:var(--text-muted); font-weight:400;">/ha</small></span>
                            </div>

                            <div class="lote-detail-row">
                                <span class="label"><i class="fas fa-vial"></i> Insumos</span>
                                <span class="value"><?= ap_egreso($insumos_ha, 0) ?> <small style="color:var(--text-muted); font-weight:400;">/ha</small></span>
                            </div>

                            <div class="lote-detail-row">
                                <span class="label"><i class="fas fa-receipt"></i> Alquiler</span>
                                <span class="value"><?= ap_egreso($alquiler_ha, 0) ?> <small style="color:var(--text-muted); font-weight:400;">/ha</small></span>
                            </div>
                            
                            <?php
                                $precio_promedio_lote = $lote['kgs'] > 0 ? $lote['ingreso'] / $lote['kgs'] : 0;
                                $pe_lote_kg_ha = ($precio_promedio_lote > 0 && $lote['sup'] > 0) ? ($costo_total_lote / $precio_promedio_lote) / $lote['sup'] : 0;
                            ?>
                            <div class="lote-detail-row" style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--rule-strong); border-bottom: none;">
                                <span class="label" style="font-size: 0.78rem;"><i class="fas fa-bullseye"></i> Indiferencia</span>
                                <span class="value" style="font-size: 0.9rem; color: var(--text-primary);"><?= number_format($pe_lote_kg_ha, 0, ',', '.') ?> <small>kg/ha</small></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php $idx++; endforeach; ?>

<?php endif; ?>

<?php /* Los datos de los gráficos viajan con la región: al reemplazarla, el JS
         los lee de acá y vuelve a dibujar. Si fueran a parar al <script> del pie
         no llegarían en el intercambio. */ ?>
<?php /* El símbolo viaja acá adentro y no en el script de la página: al cambiar
         de moneda se reemplaza sólo esta región, y el script de arriba no vuelve a
         correr. Si el símbolo viviera allá, los gráficos seguirían diciendo "$"
         mientras las tarjetas de al lado ya dirían "US$". */ ?>
<script type="application/json" id="panel-datos"><?= json_encode([
    'ciclo'   => $ciclo_sel,
    'moneda'  => $moneda_sel,
    'simbolo' => ap_simbolo(),
    'dona'    => $ciclo_sel ? json_decode($chart_dona, true) : null,
    'bars'    => $ciclo_sel ? json_decode($chart_bars, true) : null,
], JSON_UNESCAPED_UNICODE) ?></script>
</div><!-- /#panel-contenido -->

<!-- Chart.js CDN (debe ir antes del bloque script que lo usa) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<script>
    /* =====================================================================
       FILTRADO SIN RECARGA

       Antes cada cambio de filtro era `location.href = url`: recarga completa,
       con el shell, las fuentes y Chart.js volviendo a bajar y a parsear. Tres
       selects, tres recargas, y la página parpadeando entera cada vez.

       Ahora se pide la MISMA url por fetch y se reemplazan sólo las dos regiones
       que dependen de los filtros. El servidor no cambió: sigue renderizando la
       página igual, así que no hay dos versiones del markup que mantener.

       Si el fetch falla, se navega como antes. El panel nunca queda a medias.
       ===================================================================== */
    const AP_REGIONES = ['panel-filtros', 'panel-contenido'];
    let apPeticion = 0;

    function apUrlFiltro(param, value) {
        const url = new URL(window.location.href);
        if (value === '' || value === null) {
            url.searchParams.delete(param);
        } else {
            url.searchParams.set(param, value);
        }
        // Lote y cultivo dependen de la campaña: al cambiarla dejan de tener sentido.
        if (param === 'ciclo') {
            url.searchParams.delete('lote');
            url.searchParams.delete('cultivo');
        }
        return url;
    }

    function apCargando(si) {
        AP_REGIONES.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.classList.toggle('ap-cargando', si);
                el.setAttribute('aria-busy', si ? 'true' : 'false');
            }
        });
    }

    function apAvisar(texto) {
        const av = document.getElementById('panel-aviso');
        if (av) av.textContent = texto;
    }

    async function apAplicar(url, push) {
        const marca = ++apPeticion;
        apCargando(true);
        try {
            const res = await fetch(url.toString(), {
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();

            // Si el usuario cambió otro filtro mientras tanto, esta respuesta ya
            // no es la que corresponde: se descarta en vez de pisar la nueva.
            if (marca !== apPeticion) return;

            const doc = new DOMParser().parseFromString(html, 'text/html');

            // La sesión pudo vencer: ahí el servidor devuelve el login, no el panel.
            if (!doc.getElementById('panel-contenido')) {
                window.location.href = url.toString();
                return;
            }

            AP_REGIONES.forEach(id => {
                const nuevo = doc.getElementById(id);
                const viejo = document.getElementById(id);
                if (nuevo && viejo) viejo.innerHTML = nuevo.innerHTML;
            });

            if (push) history.pushState({ ap: true }, '', url.toString());
            initPanelCharts();

            /* Se anuncia el número de decisión, no "listo". Quien no ve la pantalla
               filtra para saber cuánto gana, igual que el resto: que lo escuche. */
            const partes = [];
            const camp = url.searchParams.get('ciclo');
            if (camp) partes.push('campaña ' + camp);
            const selLote = document.querySelector('#panel-filtros select:nth-of-type(2)');
            if (selLote && selLote.value) partes.push('lote ' + selLote.selectedOptions[0].text);
            const selCult = document.querySelector('#panel-filtros select:nth-of-type(3)');
            if (selCult && selCult.value) partes.push(selCult.selectedOptions[0].text);

            const margen = document.querySelector('#panel-contenido .stat-card .value');
            apAvisar(
                (partes.length ? partes.join(', ') + '. ' : 'Sin filtros. ') +
                (margen ? 'Margen neto ' + margen.innerText.trim() + '.' : 'Panel actualizado.')
            );
        } catch (e) {
            // Ante cualquier problema, el camino de siempre.
            window.location.href = url.toString();
            return;
        } finally {
            if (marca === apPeticion) apCargando(false);
        }
    }

    function navFiltro(param, value) {
        apAplicar(apUrlFiltro(param, value), true);
    }

    // El motor vive ahora en el chat flotante: includes/chat_motor.php.

    // Atrás y adelante del navegador tienen que mover el panel, no salir de él.
    window.addEventListener('popstate', function () {
        apAplicar(new URL(window.location.href), false);
    });

    function showTab(tabId, btn) {
        document.querySelectorAll('.cultivo-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        btn.classList.add('active');
    }

    let dolarVenta = 0;
    // La que trajo el servidor: el panel ya vino renderizado en esta moneda.
    const AP_MONEDA  = <?= json_encode($moneda_sel) ?>;
    let currentCurrency = AP_MONEDA;

    /**
     * Sin cotización no se puede convertir, y hay que decirlo.
     *
     * Antes, si la API del dólar fallaba, el ticker se quedaba en "..." y el botón
     * USD se marcaba activo pero los precios seguían mostrándose en pesos: el
     * usuario creía estar mirando dólares y miraba pesos. Se prefiere apagar el
     * botón y decir "sin dato" antes que convertir con un número inventado.
     */
    function marcarDolarSinDato() {
        const ticker = document.getElementById('dolar-ticker');
        if (ticker) {
            ticker.innerText = 'sin dato';
            ticker.classList.add('dato-viejo');
        }
        const btn = document.getElementById('btnCurrencyUSD');
        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.4';
            btn.style.cursor = 'not-allowed';
            btn.title = 'No se pudo obtener la cotización del dólar, así que no se puede convertir.';
        }
    }

    function setTickerCurrency(cur, recargarPanel = true) {
        currentCurrency = cur;
        // aria-current y no una clase: el estado es "cuál estoy mirando", y así
        // el lector de pantalla lo anuncia además de que se vea.
        document.getElementById('btnCurrencyARS').setAttribute('aria-current', cur === 'ARS' ? 'true' : 'false');
        document.getElementById('btnCurrencyUSD').setAttribute('aria-current', cur === 'USD' ? 'true' : 'false');

        /* El mismo botón cambia la pizarra Y los números propios. Antes convertía
           sólo la pizarra, y quedaba la mitad de arriba en dólares y todo el resto
           en pesos, que es la peor de las dos opciones.
           La pizarra se convierte acá con el dólar de hoy —es un precio de hoy—;
           los movimientos propios los convierte el servidor con la cotización del
           mes de cada uno, así que esa parte va por el mismo camino que los filtros. */
        if (recargarPanel) navFiltro('moneda', cur === 'USD' ? 'USD' : '');

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
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                const venta = parseFloat(data && data.venta);
                if (!isFinite(venta) || venta <= 0) throw new Error('respuesta sin cotizacion');
                dolarVenta = venta;
                const ticker = document.getElementById('dolar-ticker');
                if (ticker) ticker.innerText = '$' + venta.toLocaleString('es-AR');
            })
            .catch(marcarDolarSinDato);

        /* Si se llegó con ?moneda=USD, la pizarra tiene que arrancar convertida:
           el panel ya vino en dólares del servidor. Sin recargar, que para eso
           está el segundo parámetro — si no, entraría en un bucle de fetch. */
        if (AP_MONEDA === 'USD') setTickerCurrency('USD', false);

        initPanelCharts();
    });

    /* Paleta categórica de los gráficos. Validada para daltonismo: el peor par
       adyacente da ΔE 9,9 en deuteranopía (el objetivo es ≥8) y 20,8 en visión
       normal. La anterior — índigo, celeste y salmón — colapsaba: índigo y
       celeste eran casi el mismo color para un ojo deuterano.
       El orden es fijo: una serie no cambia de color porque se filtre otra. */
    const AP_SERIES = ['#348f4f', '#3284d0', '#b13b92'];
    const AP_TINTA  = '#1b211c';   /* texto */
    const AP_MUTED  = '#535a55';   /* ejes y etiquetas */
    const AP_GRID   = '#d9deda';   /* grilla, siempre por detrás */
    const AP_SUP    = '#ffffff';   /* superficie: separa los gajos */

    /* Se llama en la carga inicial y otra vez después de cada filtrado. Los datos
       salen del bloque JSON que viaja dentro de la región reemplazada. Destruye
       las instancias previas: Chart.js deja el canvas tomado y, sin esto, el
       segundo filtrado no dibuja nada. */
    let apCharts = [];
    /**
     * Pone el cartel de "no hay nada" en lugar del gráfico, o lo saca.
     *
     * Devuelve true cuando tapó el gráfico, así quien llama no lo dibuja. Es
     * reversible a propósito: los filtros van y vienen, y un lote sin cargar no
     * puede dejar el canvas escondido para siempre cuando se vuelve a "todos".
     */
    function apGraficoVacio(canvasId, texto) {
        const cv = document.getElementById(canvasId);
        if (!cv) return false;
        const cont = cv.parentElement;
        const previo = cont.querySelector('.ap-sin-datos');
        if (previo) previo.remove();
        cv.style.display = '';
        /* El contenedor tiene alto fijo para que el gráfico no salte al cargar.
           Sin gráfico ese alto son 320px de blanco alrededor de un renglón. */
        cont.classList.remove('esta-vacio');
        if (!texto) return false;
        cont.classList.add('esta-vacio');
        cv.style.display = 'none';
        const p = document.createElement('p');
        p.className = 'ap-sin-datos';
        p.textContent = texto;
        cont.appendChild(p);
        return true;
    }

    function initPanelCharts() {
        apCharts.forEach(c => { try { c.destroy(); } catch (e) {} });
        apCharts = [];

        const island = document.getElementById('panel-datos');
        if (!island) return;

        let datos;
        try { datos = JSON.parse(island.textContent); } catch (e) { return; }
        if (!datos || !datos.ciclo || !datos.dona) return;

        const dona = datos.dona;
        const bars = datos.bars;
        // Se relee en cada dibujo: la isla se reemplaza al cambiar de moneda.
        const AP_SIMBOLO = datos.simbolo || '$';

        // ── Gráfico Dona ──
        const totalCostos = dona.labores + dona.insumos + dona.alquiler;
        const centro = document.getElementById('donaCentroVal');
        if (centro) centro.textContent = AP_SIMBOLO + totalCostos.toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:0});

        /* Sin costos no hay dona, ni centro, ni leyenda de tres ceros: el panel
           entero se reemplaza por una línea que dice qué falta. */
        const donaVacia = apGraficoVacio('chartDona',
            totalCostos > 0 ? null : 'Sin costos cargados en esta campaña.');
        const donaCentro = document.getElementById('donaCentro');
        if (donaCentro) donaCentro.style.display = donaVacia ? 'none' : '';

        const ctxDona = donaVacia ? null : document.getElementById('chartDona').getContext('2d');
        if (ctxDona) apCharts.push(new Chart(ctxDona, {
            type: 'doughnut',
            data: {
                labels: ['Labores', 'Insumos', 'Alquileres'],
                datasets: [{
                    data: [dona.labores, dona.insumos, dona.alquiler],
                    backgroundColor: AP_SERIES,
                    borderColor: AP_SUP,   /* 2px de superficie entre gajos, no un filete de color */
                    borderWidth: 2,
                    hoverOffset: 8
                }]
            },
            options: {
                cutout: '68%',
                plugins: { legend: { display: false }, tooltip: {
                    callbacks: { label: ctx => ' ' + AP_SIMBOLO + ctx.raw.toLocaleString('es-AR', {minimumFractionDigits:2}) }
                }},
                animation: { animateScale: true }
            }
        }));

        // Leyenda manual dona
        const donaColors = AP_SERIES;
        const donaLabels = ['Labores','Insumos','Alquileres'];
        const donaVals   = [dona.labores, dona.insumos, dona.alquiler];
        const leyenda    = document.getElementById('donaLeyenda');
        leyenda.innerHTML = '';   /* si no, al refiltrar se acumulan las leyendas */
        if (!donaVacia) donaLabels.forEach((l, i) => {
            const pct = totalCostos > 0 ? ((donaVals[i]/totalCostos)*100).toFixed(1) : 0;
            leyenda.innerHTML += `
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:${donaColors[i]};margin-right:8px;"></span>${l}</span>
                    <span style="color:var(--text-primary);font-weight:700;">${AP_SIMBOLO}${donaVals[i].toLocaleString('es-AR',{minimumFractionDigits:0})} <small style="color:var(--text-muted);font-weight:500;">(${pct}%)</small></span>
                </div>`;
        });

        // ── Gráfico Barras ──
        /* Todo en cero es lo mismo que nada: sin esto Chart.js arma un eje de
           $0 a $1 con diez marcas, que parecen datos y no lo son. */
        const hayBarras = (bars.labels || []).length > 0 &&
            [].concat(bars.ingresos, bars.costos, bars.margen).some(v => Number(v) !== 0);
        if (!apGraficoVacio('chartBarras',
                hayBarras ? null : 'Sin ingresos ni costos cargados para comparar lotes.')) {
        const ctxBar = document.getElementById('chartBarras').getContext('2d');
        apCharts.push(new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: bars.labels,
                datasets: [
                    { label: 'Ingresos',  data: bars.ingresos, backgroundColor: AP_SERIES[0], borderRadius: 4 },
                    { label: 'Costos',    data: bars.costos,   backgroundColor: AP_SERIES[1], borderRadius: 4 },
                    { label: 'Margen',    data: bars.margen,   backgroundColor: AP_SERIES[2], borderRadius: 4 },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: AP_MUTED, font: { size: 12 }, boxWidth: 12, boxHeight: 12, usePointStyle: false } },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: $${ctx.raw.toLocaleString('es-AR', {minimumFractionDigits:2})}` }}
                },
                scales: {
                    x: {
                        ticks: {
                            color: AP_MUTED,
                            font: { size: 12 },
                            maxRotation: 45,
                            minRotation: 0,
                            autoSkip: true
                        },
                        grid: { display: false },
                        border: { color: AP_GRID }
                    },
                    y: {
                        ticks: {
                            color: AP_MUTED,
                            font: { size: 12 },
                            callback: v => AP_SIMBOLO + v.toLocaleString('es-AR')
                        },
                        grid: { color: AP_GRID },
                        border: { display: false }
                    }
                }
            }
        }));
        }
    }
</script>

<?php /* Va acá, fuera de #panel-contenido: esa región se reemplaza entera al
         filtrar sin recargar y la conversación se perdería en cada filtro. */ ?>
<?php require_once 'includes/chat_motor.php'; ?>

<?php require_once 'includes/footer.php'; ?>



