<?php
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
require_once 'includes/cultivos.php';
$usuario_id = $_SESSION['usuario_id'];
$page_title = 'Gestión de Lotes';

// Validación CSRF para todas las peticiones POST (ej: agregar/editar/eliminar lote)
validate_csrf();

require_once 'controllers/LotesController.php';

$controller = new LotesController($pdo, $usuario_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $controller->addLote($_POST);
        set_flash('success', 'Lote guardado exitosamente.');
    } elseif ($_POST['action'] === 'edit') {
        $controller->editLote($_POST);
        set_flash('success', 'Lote actualizado exitosamente.');
    } elseif ($_POST['action'] === 'delete') {
        if ($controller->deleteLote($_POST['id'])) {
            set_flash('success', 'Lote eliminado exitosamente. Se restituyó el stock de sus operaciones.');
        } else {
            set_flash('error', 'No se pudo eliminar el lote. Intenta nuevamente.');
        }
    } elseif ($_POST['action'] === 'set_campania') {
        $lote_id      = (int)$_POST['lote_id'];
        $campania_new = trim($_POST['campania'] ?? '');
        $cultivo_new  = trim($_POST['cultivo_actual'] ?? '');
        if ($lote_id) {
            $pdo->prepare("UPDATE lotes SET campania=?, cultivo_actual=? WHERE id=? AND usuario_id=?")
                ->execute([$campania_new ?: null, $cultivo_new ?: null, $lote_id, $usuario_id]);
            
            // Registrar el cultivo canónico (find-or-create) si hay datos válidos.
            if ($campania_new && $cultivo_new) {
                cultivo_resolve($pdo, $usuario_id, $lote_id, $cultivo_new, $campania_new);
            }
            
            set_flash('success', 'Campaña/Cultivo asignados exitosamente.');
        }
    }
    header("Location: lotes.php");
    exit;
}

$lotes = $controller->getAllLotes();
require_once 'includes/header.php';
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<!-- Leaflet Geocoder -->
<link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
<style>
    #mainMap { height: 400px; width: 100%; border-radius: 8px; margin-bottom: 24px; z-index: 1; border: 1px solid var(--border); }
    #modalMap { height: 300px; width: 100%; border-radius: 8px; margin-bottom: 10px; z-index: 1; border: 1px solid var(--border); background: #222; }
    @media (max-width: 768px) { #modalMap { height: 200px; } }
    .map-helper { font-size: 0.85rem; color: #aaa; margin-top: 5px; margin-bottom: 5px; }
    .map-fullscreen-css { position: fixed !important; top: 0 !important; left: 0 !important; width: 100vw !important; height: 100vh !important; z-index: 9999 !important; border-radius: 0 !important; margin: 0 !important; }

    /* Marcador de ubicación actual - punto pulsante */
    .user-location-marker {
        position: relative;
        width: 18px;
        height: 18px;
    }
    .user-location-dot {
        width: 14px; height: 14px;
        background: #3b82f6;
        border: 2.5px solid #fff;
        border-radius: 50%;
        position: absolute;
        top: 2px; left: 2px;
        box-shadow: 0 0 0 2px rgba(59,130,246,0.5);
        z-index: 2;
    }
    .user-location-pulse {
        width: 36px; height: 36px;
        background: rgba(59,130,246,0.25);
        border-radius: 50%;
        position: absolute;
        top: -9px; left: -9px;
        animation: pulse-location 2s ease-out infinite;
        z-index: 1;
    }
    @keyframes pulse-location {
        0%   { transform: scale(0.5); opacity: 1; }
        100% { transform: scale(1.8); opacity: 0; }
    }
    /* Botón Mi Ubicación */
    .btn-mi-ubicacion {
        background: white; border: 2px solid rgba(0,0,0,0.2);
        border-radius: 4px; padding: 0; width: 34px; height: 34px;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; color: #3b82f6; font-size: 1rem;
        box-shadow: 0 1px 5px rgba(0,0,0,0.4);
        transition: background 0.2s;
    }
    .btn-mi-ubicacion:hover { background: #f4f4f4; }
    .btn-mi-ubicacion.locating { color: #f59e0b; animation: spin 1s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>

<!-- Mapa Principal -->
<div id="mainMap"></div>

<div class="glass-panel" style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="font-size: 1.2rem; font-weight: 500;">Listado de Lotes</h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="cultivos.php" class="btn" style="background:rgba(255,255,255,0.05); color:var(--text-primary); border:1px solid rgba(255,255,255,0.2);">
                <i class="fas fa-list"></i> Ver Campañas
            </a>
            <button class="btn" onclick="abrirModalCampania()"
                style="background:rgba(129,140,248,0.15); border:1px solid rgba(129,140,248,0.35); color:#818cf8;">
                <i class="fas fa-seedling"></i> Nueva Campaña
            </button>
            <button class="btn btn-primary" onclick="openNewLoteModal()">
                <i class="fas fa-plus"></i> Nuevo Lote
            </button>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Lote</th>
                    <th>Campaña & Cultivo</th>
                    <th>Tenencia</th>
                    <th>Ubicación & Suelo</th>
                    <th>Clima Actual</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php $totalSup = 0; foreach($lotes as $lote): $totalSup += $lote['superficie']; ?>
                <tr>
                    <td data-label="Lote">
                        <div style="line-height: 1.2;">
                            <strong style="font-size: 1.05em;"><?= htmlspecialchars($lote['nombre']) ?></strong><br>
                            <span style="font-size: 0.85em; color: var(--text-muted);"><i class="fas fa-vector-square" style="width:14px; text-align:center;"></i> <?= number_format($lote['superficie'], 2, ',', '.') ?> ha</span>
                        </div>
                    </td>
                    <td data-label="Campaña & Cultivo">
                        <div style="line-height: 1.2;">
                            <?php if(!empty($lote['campania']) || !empty($lote['cultivo_actual'])): ?>
                                <strong style="font-size: 0.95em; color: var(--accent);"><?= htmlspecialchars($lote['campania'] ?? '-') ?></strong><br>
                                <span style="font-size: 0.85em; color: rgba(255,255,255,0.8);"><i class="fas fa-leaf" style="width:14px; text-align:center; color:#50c878;"></i> <?= htmlspecialchars($lote['cultivo_actual'] ?? '-') ?></span>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 0.85em;">Sin datos</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Tenencia">
                        <?php if(($lote['tenencia'] ?? 'propio') === 'alquilado'): ?>
                            <?php 
                                $tns = (float)($lote['costo_alquiler_tns_ha'] ?? 0);
                                $kg = $tns * 1000;
                            ?>
                            <span class="badge" style="background: rgba(230,150,20,0.2); color: #ebb62c; border: 1px solid rgba(230,150,20,0.3); font-size:0.8em; padding: 4px 6px;">Alq (<?= number_format($kg, 0, ',', '.') ?> KG/ha)</span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(80,200,120,0.2); color: #50c878; border: 1px solid rgba(80,200,120,0.3); font-size:0.8em; padding: 4px 6px;">Propio</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Ubicación & Suelo">
                        <?php if(!empty($lote['latitud']) && !empty($lote['longitud'])): ?>
                            <div style="font-size: 0.85em; max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 3px; cursor: pointer; color: #60a5fa; transition: color 0.2s;" onclick="focusOnMap(<?= $lote['id'] ?>, <?= $lote['latitud'] ?>, <?= $lote['longitud'] ?>)" title="Ver en mapa: <?= htmlspecialchars($lote['ubicacion']) ?>" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color='#60a5fa'">
                                <i class="fas fa-map-marker-alt" style="color: var(--danger); width: 14px; text-align:center;"></i> <span style="text-decoration: underline;"><?= htmlspecialchars($lote['ubicacion'] ?: 'Sin ubicación') ?></span>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 0.85em; max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 3px;" title="<?= htmlspecialchars($lote['ubicacion']) ?>">
                                <i class="fas fa-map-marker-alt" style="color: var(--danger); width: 14px; text-align:center;"></i> <?= htmlspecialchars($lote['ubicacion'] ?: 'Sin ubicación') ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size: 0.8em; color: var(--text-muted); max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($lote['tipo_suelo']) ?>">
                            <i class="fas fa-seedling" style="color: #a08050; width: 14px; text-align:center;"></i> <?= htmlspecialchars($lote['tipo_suelo'] ?: 'Suelo n/a') ?>
                        </div>
                    </td>
                    <td data-label="Clima Actual">
                        <div id="clima-lote-<?= $lote['id'] ?>">
                            <?php if(!empty($lote['latitud']) && !empty($lote['longitud'])): ?>
                                <span style="color: var(--text-muted); font-size: 0.9em;"><i class="fas fa-spinner fa-spin"></i> Cargando...</span>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 0.9em;">Sin coords.</span>
                            <?php endif; ?>
                        </div>
                        <?php if(!empty($lote['latitud']) && !empty($lote['longitud'])): ?>
                        <div style="margin-top: 8px;">
                            <a href="clima_historico.php?id=<?= $lote['id'] ?>" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background: rgba(59,130,246,0.1); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2);"><i class="fas fa-cloud-rain"></i> Régimen Lluvia</a>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Acciones">
                        <!-- Historial -->
                        <button type="button" class="btn" style="color: #818cf8; background: transparent; padding: 4px;" title="Historial de campañas" onclick="verHistorial(<?= $lote['id'] ?>, '<?= htmlspecialchars($lote['nombre'], ENT_QUOTES) ?>')"><i class="fas fa-history"></i></button>
                        <!-- Editar -->
                        <button type="button" class="btn" style="color: var(--accent); background: transparent; padding: 4px;" onclick='editLote(<?= json_encode($lote, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                        <!-- Eliminar -->
                        <form method="POST" style="display: inline;" onsubmit="if(!confirm('\u00bfEliminar lote?')) return false; const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true; return true;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $lote['id'] ?>">
                            <button type="submit" class="btn" style="color: var(--danger); background: transparent; padding: 4px;"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($lotes) === 0): ?>
                <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No hay lotes registrados</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: rgba(0,0,0,0.2);">
                    <td colspan="3" style="text-align: right; border-bottom: none; font-size:0.9em; padding-right:15px;"><strong>Superficie Total:</strong></td>
                    <td colspan="3" style="border-bottom: none; color:var(--accent);"><strong><?= number_format($totalSup ?? 0, 2, ',', '.') ?> ha</strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="addLotModal" class="modal-wrapper">
    <div class="glass-panel modal-panel">
        <h2 id="modalTitle" style="margin-bottom: 20px;">Registrar Nuevo Lote</h2>
        <form id="lotForm" method="POST" style="display: flex; flex-direction: column; gap: 15px;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" id="actionInput" value="add">
            <input type="hidden" name="id" id="loteIdInput" value="">
            
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Nombre del Lote</label>
                <input type="text" name="nombre" id="nombreInput" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Superficie (Hectáreas)</label>
                <input type="number" step="0.01" name="superficie" id="superficieInput" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Régimen de Tenencia</label>
                <select name="tenencia" id="tenenciaSelect" required onchange="toggleCosto(this)" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-color); color: white;">
                    <option value="propio">Propio</option>
                    <option value="alquilado">Alquilado</option>
                </select>
            </div>
            
            <div id="costoAlquilerContainer" style="display: none; flex-direction: column; gap: 10px; background: rgba(255,255,255,0.03); padding: 12px; border-radius: 8px; border: 1px dashed var(--border);">
                <label style="font-weight: 600; color: var(--accent);"><i class="fas fa-calculator"></i> Costo de Alquiler Anual</label>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 0.85rem; color: var(--text-muted);">KG / ha</label>
                        <input type="number" step="1" id="costoKgInput" placeholder="Ej: 1800" oninput="syncUnitsLote('kg')" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 0.85rem; color: var(--text-muted);">QQ / ha</label>
                        <input type="number" step="0.1" id="costoQqInput" placeholder="Ej: 18" oninput="syncUnitsLote('qq')" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label style="font-size: 0.85rem; color: var(--text-muted);">TNS de Soja / Ha <small>(Valor guardado)</small></label>
                    <input type="number" step="0.001" name="costo_alquiler_tns_ha" id="costoInput" oninput="syncUnitsLote('tns')" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.1); color: #aaa;">
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Ubicación</label>
                <input type="text" name="ubicacion" id="ubicacionInput" placeholder="Ej: Ruta 9 Km 45" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px;">
                <label>Tipo de Suelo</label>
                <input type="text" name="tipo_suelo" id="tipoSueloInput" placeholder="Ej: Franco arcilloso" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: rgba(0,0,0,0.2); color: white;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 5px; margin-top: 5px;">
                <label>Ubicación en Mapa (Opcional)</label>
                <p class="map-helper">Haz clic en el mapa para marcar el centro del lote.</p>
                <div id="modalMap"></div>
                <input type="hidden" name="latitud" id="latInput">
                <input type="hidden" name="longitud" id="lngInput">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                <button type="button" class="btn" onclick="closeModal()" style="background: rgba(255,255,255,0.1); color: white;">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php
$lotes_json = json_encode(array_values(array_map(function($l) {
    return [
        'id' => $l['id'],
        'nombre' => $l['nombre'],
        'superficie' => $l['superficie'],
        'latitud' => $l['latitud'],
        'longitud' => $l['longitud']
    ];
}, array_filter($lotes, function($l) {
    return !empty($l['latitud']) && !empty($l['longitud']);
}))));
?>
<script>
    // Imágenes satelitales de Esri (resolución similar a Google Maps)
    const tileLayerUrl = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    const tileLayerAttribution = 'Tiles &copy; Esri';

    document.addEventListener('DOMContentLoaded', function() {
        const lotes = <?= $lotes_json ?> || [];
        
        let centerLat = -34.6037; // Default: BsAs
        let centerLng = -58.3816;
        let zoom = 5;

        if (lotes.length > 0) {
            centerLat = parseFloat(lotes[0].latitud);
            centerLng = parseFloat(lotes[0].longitud);
            zoom = 12;
        }

        const mainMap = L.map('mainMap').setView([centerLat, centerLng], zoom);
        
        const satLayer = L.tileLayer(tileLayerUrl, { attribution: tileLayerAttribution, maxZoom: 18 });
        satLayer.addTo(mainMap);

        const baseMaps = { "Satelital": satLayer };
        const overlayMaps = {};
        const layerControl = L.control.layers(baseMaps, overlayMaps, { collapsed: false }).addTo(mainMap);

        // Botón Fullscreen custom
        const FullscreenControl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function() {
                const btn = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                btn.innerHTML = '<a href="#" style="display:flex; align-items:center; justify-content:center; width:34px; height:34px; color:#333; text-decoration:none;" title="Pantalla Completa"><i class="fas fa-expand"></i></a>';
                
                btn.onclick = function(e) {
                    e.preventDefault();
                    const mapDiv = document.getElementById('mainMap');
                    const icon = btn.querySelector('i');
                    
                    if (!document.fullscreenElement && !mapDiv.classList.contains('map-fullscreen-css')) {
                        if (mapDiv.requestFullscreen) mapDiv.requestFullscreen();
                        else if (mapDiv.webkitRequestFullscreen) mapDiv.webkitRequestFullscreen();
                        else {
                            mapDiv.classList.add('map-fullscreen-css');
                            icon.classList.replace('fa-expand', 'fa-compress');
                        }
                    } else {
                        if (document.fullscreenElement) {
                            if (document.exitFullscreen) document.exitFullscreen();
                            else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
                        } else {
                            mapDiv.classList.remove('map-fullscreen-css');
                            icon.classList.replace('fa-compress', 'fa-expand');
                        }
                    }
                    setTimeout(() => mainMap.invalidateSize(), 200);
                };
                return btn;
            }
        });
        new FullscreenControl().addTo(mainMap);

        document.addEventListener('fullscreenchange', () => {
            const btnIcon = document.querySelector('.fa-expand, .fa-compress');
            if (btnIcon) {
                if (document.fullscreenElement) btnIcon.classList.replace('fa-expand', 'fa-compress');
                else btnIcon.classList.replace('fa-compress', 'fa-expand');
            }
            setTimeout(() => mainMap.invalidateSize(), 200);
        });

        // Radar de Lluvia (RainViewer API)
        fetch('https://api.rainviewer.com/public/weather-maps.json')
            .then(res => res.json())
            .then(data => {
                if (data && data.radar && data.radar.past && data.radar.past.length > 0) {
                    const past = data.radar.past;
                    const latestItem = past[past.length - 1]; // Objeto más reciente
                    
                    const radarLayer = L.tileLayer(`${data.host}${latestItem.path}/256/{z}/{x}/{y}/2/1_1.png`, {
                        opacity: 0.65,
                        zIndex: 10,
                        attribution: '<a href="https://www.rainviewer.com" target="_blank">RainViewer</a>'
                    });
                    
                    const satPlusRadar = L.layerGroup([
                        L.tileLayer(tileLayerUrl, { attribution: tileLayerAttribution, maxZoom: 18 }),
                        radarLayer
                    ]);
                    
                    layerControl.addBaseLayer(satPlusRadar, "Radar");
                }
            })
            .catch(e => console.error("Error cargando radar:", e));

        const bounds = [];
        const markers = {};

        // Mapeador de Códigos WMO
        const getWeatherIcon = (code) => {
            if (code === 0) return '☀️ Despejado';
            if (code >= 1 && code <= 3) return '☁️ Nublado';
            if (code === 45 || code === 48) return '🌫️ Niebla';
            if (code >= 51 && code <= 55) return '🌦️ Llovizna';
            if (code >= 61 && code <= 65) return '🌧️ Lluvia';
            if (code >= 71 && code <= 77) return '❄️ Nieve';
            if (code >= 80 && code <= 82) return '🌧️ Chaparrones';
            if (code >= 95) return '⛈️ Tormenta';
            return '☁️ Variable';
        };

        lotes.forEach(lote => {
            const lat = parseFloat(lote.latitud);
            const lng = parseFloat(lote.longitud);
            const marker = L.marker([lat, lng]).addTo(mainMap);
            
            // Popup temporal
            const basePopup = `<b>${lote.nombre}</b><br>Superficie: ${lote.superficie} ha<br><div style="margin-top:8px; font-size:0.85em; color:#fff; background:rgba(0,0,0,0.5); padding:4px 8px; border-radius:4px; display:inline-block;"><i class="fas fa-spinner fa-spin"></i> Cargando clima...</div>`;
            marker.bindPopup(basePopup);
            
            markers[lote.id] = marker;
            bounds.push([lat, lng]);

            // Consulta API Weather en Vivo
            fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,relative_humidity_2m,weather_code&timezone=auto`)
                .then(res => res.json())
                .then(data => {
                    if(!data.current) return;
                    const t = data.current.temperature_2m;
                    const h = data.current.relative_humidity_2m;
                    const icon = getWeatherIcon(data.current.weather_code);
                    
                    const cellHtml = `<div style="line-height:1.2;"><b>${icon}</b><br><span style="font-size:0.85em;">${t}°C | 💧 ${h}%</span></div>`;
                    const popupHtml = `<b>${lote.nombre}</b><br>Superficie: ${lote.superficie} ha<br><div style="margin-top:8px; font-size:0.9em; color:#fff; background:rgba(10,130,230,0.8); padding:4px 8px; border-radius:4px; display:inline-block;"><b>${icon}</b> | ${t}°C | 💧 ${h}%</div>`;

                    // Tabla
                    const cell = document.getElementById(`clima-lote-${lote.id}`);
                    if (cell) cell.innerHTML = cellHtml;

                    // Mapa
                    if (markers[lote.id]) markers[lote.id].getPopup().setContent(popupHtml);
                })
                .catch(e => {
                    console.error("Open-Meteo error:", e);
                    const cell = document.getElementById(`clima-lote-${lote.id}`);
                    if (cell) cell.innerHTML = `<span style="color:var(--text-muted); font-size:0.8em;">No disp.</span>`;
                });
        });

        if (bounds.length > 1) {
            mainMap.fitBounds(bounds, { padding: [30, 30] });
        }

        // ── Botón "Mi Ubicación" en el mapa principal ────────────────────────
        const MiUbicacionControl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function() {
                const btn = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                btn.innerHTML = '<button class="btn-mi-ubicacion" id="btnMiUbicacion" title="Mi ubicación actual"><i class="fas fa-location-arrow"></i></button>';
                L.DomEvent.disableClickPropagation(btn);

                btn.querySelector('button').addEventListener('click', function() {
                    localizarUsuario();
                });
                return btn;
            }
        });
        new MiUbicacionControl().addTo(mainMap);

        let userMarker = null;
        let userCircle = null;

        function localizarUsuario() {
            if (!navigator.geolocation) {
                alert('Tu navegador no soporta geolocalización.');
                return;
            }
            const btn = document.getElementById('btnMiUbicacion');
            if (btn) { btn.classList.add('locating'); btn.innerHTML = '<i class="fas fa-circle-notch"></i>'; }

            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    const acc = pos.coords.accuracy; // metros

                    // Remover marcador anterior si existe
                    if (userMarker) mainMap.removeLayer(userMarker);
                    if (userCircle) mainMap.removeLayer(userCircle);

                    // Círculo de precisión
                    userCircle = L.circle([lat, lng], {
                        radius: acc,
                        color: '#3b82f6',
                        fillColor: '#3b82f6',
                        fillOpacity: 0.08,
                        weight: 1,
                        dashArray: '4 4'
                    }).addTo(mainMap);

                    // Marcador pulsante con ícono personalizado
                    const pulseIcon = L.divIcon({
                        className: '',
                        html: '<div class="user-location-marker"><div class="user-location-pulse"></div><div class="user-location-dot"></div></div>',
                        iconSize: [18, 18],
                        iconAnchor: [9, 9]
                    });
                    userMarker = L.marker([lat, lng], { icon: pulseIcon, zIndexOffset: 1000 })
                        .addTo(mainMap)
                        .bindPopup(`<b>📍 Tu ubicación actual</b><br><small>Precisión: ±${Math.round(acc)} m</small>`);

                    mainMap.setView([lat, lng], 14);

                    // Restaurar botón
                    if (btn) { btn.classList.remove('locating'); btn.innerHTML = '<i class="fas fa-location-arrow" style="color:#3b82f6;"></i>'; }
                },
                function(err) {
                    if (btn) { btn.classList.remove('locating'); btn.innerHTML = '<i class="fas fa-location-arrow"></i>'; }
                    const msgs = {
                        1: 'Permiso de ubicación denegado. Habilitalo en la configuración del navegador.',
                        2: 'No se pudo determinar tu posición.',
                        3: 'Tiempo de espera agotado.'
                    };
                    alert(msgs[err.code] || 'Error de geolocalización.');
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
            );
        }

        // Auto-localizar si no hay lotes con coordenadas
        if (lotes.length === 0) {
            setTimeout(localizarUsuario, 800);
        }

        let modalMap = null;
        let modalMarker = null;
        const openModalBtn = document.querySelector('button[onclick*="addLotModal"]');
        const modalElement = document.getElementById('addLotModal');

        const initModalMap = function() {
            if (!modalMap) {
                modalMap = L.map('modalMap').setView([centerLat, centerLng], zoom);
                L.tileLayer(tileLayerUrl, { attribution: tileLayerAttribution, maxZoom: 18 }).addTo(modalMap);
                
                // Agregar Buscador
                L.Control.geocoder({
                    defaultMarkGeocode: false,
                    placeholder: "Buscar ciudad, ruta o paraje...",
                })
                .on('markgeocode', function(e) {
                    const latlng = e.geocode.center;
                    modalMap.setView(latlng, 15);
                    
                    if (modalMarker) {
                        modalMarker.setLatLng(latlng);
                    } else {
                        modalMarker = L.marker(latlng).addTo(modalMap);
                    }
                    
                    document.getElementById('latInput').value = latlng.lat.toFixed(8);
                    document.getElementById('lngInput').value = latlng.lng.toFixed(8);
                    
                    const ubicacionInput = document.getElementById('ubicacionInput');
                    if (ubicacionInput && !ubicacionInput.value) {
                        ubicacionInput.value = e.geocode.name.split(',')[0];
                    }
                })
                .addTo(modalMap);

                modalMap.on('click', function(e) {
                    const lat = e.latlng.lat;
                    const lng = e.latlng.lng;
                    if (modalMarker) {
                        modalMarker.setLatLng(e.latlng);
                    } else {
                        modalMarker = L.marker(e.latlng).addTo(modalMap);
                    }
                    document.getElementById('latInput').value = lat.toFixed(8);
                    document.getElementById('lngInput').value = lng.toFixed(8);
                });

                if (navigator.geolocation && lotes.length === 0) {
                    navigator.geolocation.getCurrentPosition(position => {
                        const userLat = position.coords.latitude;
                        const userLng = position.coords.longitude;
                        modalMap.setView([userLat, userLng], 13);
                        mainMap.setView([userLat, userLng], 13);
                    }, () => {}, {timeout: 5000});
                }

                // ── Botón "Mi Ubicación" en el modal ─────────────────────────
                if (!modalMap.miUbicacionAdded) {
                    const ModalUbicacionControl = L.Control.extend({
                        options: { position: 'topleft' },
                        onAdd: function() {
                            const btn = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                            btn.innerHTML = '<button type="button" class="btn-mi-ubicacion" title="Mi ubicación actual"><i class="fas fa-location-arrow"></i></button>';
                            L.DomEvent.disableClickPropagation(btn);
                            btn.querySelector('button').addEventListener('click', function(e) {
                                e.preventDefault(); // Evitar submit accidental del form
                                localizarUsuarioModal();
                            });
                            return btn;
                        }
                    });
                    new ModalUbicacionControl().addTo(modalMap);
                    modalMap.miUbicacionAdded = true;
                }

                // Observador universal para arreglar problemas de renderizado de Leaflet dentro de Modales/Contenedores que cambian de tamaño
                if (window.ResizeObserver) {
                    new ResizeObserver(() => {
                        if (modalMap) modalMap.invalidateSize();
                    }).observe(document.getElementById('modalMap'));
                }
            }
            
            // Ejecutar recalculo antes y después de que termine la animación fadeInSlideUp (0.3s)
            setTimeout(() => { if (modalMap) modalMap.invalidateSize(); }, 10);
            setTimeout(() => { if (modalMap) modalMap.invalidateSize(); }, 400);
        };

        window.focusOnMap = function(loteId, lat, lng) {
            document.getElementById('mainMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
            mainMap.setView([lat, lng], 15);
            if (markers[loteId]) {
                setTimeout(() => markers[loteId].openPopup(), 400); // Wait for scroll/pan animation
            }
        };

        function localizarUsuarioModal() {
            if (!navigator.geolocation) {
                alert("Tu navegador no soporta geolocalización.");
                return;
            }
            const btn = document.querySelector('#modalMap .btn-mi-ubicacion');
            if(btn) btn.classList.add('locating');
            
            navigator.geolocation.getCurrentPosition(position => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                modalMap.setView([lat, lng], 15);
                if (modalMarker) {
                    modalMarker.setLatLng([lat, lng]);
                } else {
                    modalMarker = L.marker([lat, lng]).addTo(modalMap);
                }
                document.getElementById('latInput').value = lat.toFixed(8);
                document.getElementById('lngInput').value = lng.toFixed(8);
                
                if(btn) btn.classList.remove('locating');
            }, error => {
                console.error("Error obteniendo ubicación:", error);
                alert("No se pudo obtener la ubicación. Verifica los permisos de tu navegador.");
                if(btn) btn.classList.remove('locating');
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
        }

        window.openNewLoteModal = function() {
            document.getElementById('modalTitle').innerText = 'Registrar Nuevo Lote';
            document.getElementById('actionInput').value = 'add';
            document.getElementById('lotForm').reset();
            document.getElementById('loteIdInput').value = '';
            
            // Reset rental units
            document.getElementById('costoKgInput').value = '';
            document.getElementById('costoQqInput').value = '';
            document.getElementById('costoInput').value = '';

            toggleCosto(document.getElementById('tenenciaSelect'));
            if(modalMarker && modalMap) modalMap.removeLayer(modalMarker);
            modalMarker = null;
            document.getElementById('latInput').value = '';
            document.getElementById('lngInput').value = '';
            modalElement.style.display = 'flex';
            document.body.classList.add('modal-open');
            initModalMap();
        };

        window.editLote = function(lote) {
            document.getElementById('modalTitle').innerText = 'Editar Lote';
            document.getElementById('actionInput').value = 'edit';
            document.getElementById('loteIdInput').value = lote.id;
            
            document.getElementById('nombreInput').value = lote.nombre;
            document.getElementById('superficieInput').value = lote.superficie;
            
            const tenencia = document.getElementById('tenenciaSelect');
            tenencia.value = lote.tenencia || 'propio';
            toggleCosto(tenencia);
            
            const tns = parseFloat(lote.costo_alquiler_tns_ha) || 0;
            document.getElementById('costoInput').value = tns || '';
            if (tns > 0) {
                document.getElementById('costoKgInput').value = (tns * 1000).toFixed(0);
                document.getElementById('costoQqInput').value = (tns * 10).toFixed(1);
            } else {
                document.getElementById('costoKgInput').value = '';
                document.getElementById('costoQqInput').value = '';
            }

            document.getElementById('ubicacionInput').value = lote.ubicacion || '';
            document.getElementById('tipoSueloInput').value = lote.tipo_suelo || '';
            document.getElementById('latInput').value = lote.latitud || '';
            document.getElementById('lngInput').value = lote.longitud || '';

            modalElement.style.display = 'flex';
            document.body.classList.add('modal-open');
            
            initModalMap();
            if(lote.latitud && lote.longitud) {
                const l = [parseFloat(lote.latitud), parseFloat(lote.longitud)];
                if(modalMarker) {
                    modalMarker.setLatLng(l);
                } else {
                    modalMarker = L.marker(l).addTo(modalMap);
                }
                // Usar timeout solo para el setView si el modal tiene transición CSS
                setTimeout(() => modalMap.setView(l, 14), 10);
            } else {
                if(modalMarker && modalMap) modalMap.removeLayer(modalMarker);
                modalMarker = null;
            }
        };
        
        window.closeModal = function() {
            modalElement.style.display = 'none';
            document.body.classList.remove('modal-open');
        };
    });

    function syncUnitsLote(origin) {
        const kgInput = document.getElementById('costoKgInput');
        const qqInput = document.getElementById('costoQqInput');
        const tnsInput = document.getElementById('costoInput');

        if (origin === 'kg') {
            const kg = parseFloat(kgInput.value) || 0;
            qqInput.value = kg > 0 ? (kg / 100).toFixed(1) : '';
            tnsInput.value = kg > 0 ? (kg / 1000).toFixed(3) : '';
        } else if (origin === 'qq') {
            const qq = parseFloat(qqInput.value) || 0;
            kgInput.value = qq > 0 ? (qq * 100).toFixed(0) : '';
            tnsInput.value = qq > 0 ? (qq / 10).toFixed(2) : '';
        } else if (origin === 'tns') {
            const tns = parseFloat(tnsInput.value) || 0;
            kgInput.value = tns > 0 ? (tns * 1000).toFixed(0) : '';
            qqInput.value = tns > 0 ? (tns * 10).toFixed(1) : '';
        }
    }

    function toggleCosto(select) {
        if(select.value === 'alquilado') {
            document.getElementById('costoAlquilerContainer').style.display = 'flex';
            document.getElementById('costoKgInput').required = true;
        } else {
            document.getElementById('costoAlquilerContainer').style.display = 'none';
            document.getElementById('costoKgInput').required = false;
        }
    }
</script>

<!-- ===== MODAL: Asignar / Cambiar Campaña ===== -->
<div id="campaniaModal" class="modal-wrapper" style="display:none;">
    <div class="glass-panel modal-panel" style="max-width: 440px;">
        <h2 id="campModalTitle" style="margin-bottom:18px; font-size:1.1rem;">
            <i class="fas fa-seedling" style="color:#818cf8; margin-right:8px;"></i>
            Iniciar Nueva Campaña
        </h2>
        <form method="POST" style="display:flex; flex-direction:column; gap:14px;" onsubmit="const b=this.querySelector('button[type=submit]'); if(b) b.disabled=true;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action"  value="set_campania">

            <div style="display:flex; flex-direction:column; gap:5px;">
                <label>Lote</label>
                <select name="lote_id" id="campLoteId" required
                    style="padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-color); color:white;">
                    <option value="">-- Seleccionar Lote --</option>
                    <?php foreach ($lotes as $l): ?>
                        <option value="<?= $l['id'] ?>"<?= !empty($l['campania']) ? ' style="color:var(--text-muted);"' : '' ?>>
                            <?= htmlspecialchars($l['nombre']) ?> (<?= $l['superficie'] ?> ha)<?= !empty($l['campania']) ? ' — Campaña activa: '.$l['campania'] : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label>Año de Campaña</label>
                    <select name="campania" id="campInput" required
                        style="padding:10px; border-radius:6px; border:1px solid rgba(129,140,248,0.4); background:var(--input-bg); color:var(--text-primary);">
                        <option value="">-- Seleccionar --</option>
                        <option value="23/24">23/24</option>
                        <option value="24/25">24/25</option>
                        <option value="25/26">25/26</option>
                        <option value="26/27">26/27</option>
                    </select>
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label>Cultivo</label>
                    <select name="cultivo_actual" id="campCultivo" required
                        style="padding:10px; border-radius:6px; border:1px solid rgba(129,140,248,0.4); background:var(--input-bg); color:var(--text-primary);">
                        <option value="">-- Seleccionar --</option>
                        <option value="Soja (1ra)">Soja (1ra)</option>
                        <option value="Soja (2da)">Soja (2da)</option>
                        <option value="Maíz (Temprano)">Maíz (Temprano)</option>
                        <option value="Maíz (Tardío)">Maíz (Tardío)</option>
                        <option value="Trigo">Trigo</option>
                        <option value="Girasol">Girasol</option>
                        <option value="Sorgo">Sorgo</option>
                        <option value="Cebada">Cebada</option>
                        <option value="Maní">Maní</option>
                    </select>
                </div>
            </div>

            <p style="font-size:0.82rem; color:var(--text-muted); margin:0;">
                <i class="fas fa-info-circle"></i>
                Esto asigna la campaña al lote seleccionado y lo habilita para registrar entregas.
            </p>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:4px;">
                <button type="button" class="btn" onclick="cerrarModalCampania()" style="background:rgba(255,255,255,0.1); color:white;">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#818cf8,#6366f1); border:none;">
                    <i class="fas fa-check"></i> Iniciar Campaña
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalCampania(loteId = null) {
    document.getElementById('campInput').value     = '';
    document.getElementById('campCultivo').value   = '';
    const loteSelect = document.getElementById('campLoteId');
    if(loteId) {
        loteSelect.value = loteId;
    } else {
        loteSelect.value = '';
    }
    document.getElementById('campaniaModal').style.display = 'flex';
    document.body.classList.add('modal-open');
}
function cerrarModalCampania() {
    document.getElementById('campaniaModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}

// Auto-abrir modal si se acaba de cerrar una campaña (viene redirigido)
(function() {
    const params = new URLSearchParams(window.location.search);
    const loteId = params.get('nueva_campania');
    if (loteId) {
        // Limpiar la URL sin recargar
        history.replaceState({}, '', 'lotes.php');
        // Abrir modal con el lote ya seleccionado
        setTimeout(() => abrirModalCampania(loteId), 300);
    }
})();
</script>

<!-- ===== MODAL: Historial de Campañas ===== -->
<div id="historialModal" class="modal-wrapper" style="display:none;">
    <div class="glass-panel modal-panel" style="max-width: 620px; width:100%;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h2 style="margin:0; font-size:1.1rem;">
                <i class="fas fa-history" style="color:#818cf8; margin-right:8px;"></i>
                Historial de Campañas: <span id="histLoteNombre" style="color:#818cf8;"></span>
            </h2>
            <button onclick="cerrarHistorial()" class="btn" style="background:rgba(255,255,255,0.1); color:white; padding:4px 10px;"><i class="fas fa-times"></i></button>
        </div>
        <div id="historialContent">
            <div style="text-align:center; padding:30px; color:var(--text-muted);">
                <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i><br>
                Cargando historial...
            </div>
        </div>
    </div>
</div>

<script>
function verHistorial(loteId, loteNombre) {
    document.getElementById('histLoteNombre').textContent = loteNombre;
    document.getElementById('historialContent').innerHTML = `
        <div style="text-align:center; padding:30px; color:var(--text-muted);">
            <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i><br>Cargando...
        </div>`;
    document.getElementById('historialModal').style.display = 'flex';
    document.body.classList.add('modal-open');

    fetch(`api/historial_lote.php?lote_id=${loteId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.length) {
                document.getElementById('historialContent').innerHTML = `
                    <div style="text-align:center; padding:30px; color:var(--text-muted);">
                        <i class="fas fa-leaf" style="font-size:2rem; opacity:0.2; display:block; margin-bottom:10px;"></i>
                        No hay campañas cerradas registradas para este lote.
                        <br><small style="font-size:0.82rem; margin-top:8px; display:block;">Las campañas aparecen aqui al usar el botón "Cerrar Campaña" en Producción y Ventas.</small>
                    </div>`;
                return;
            }
            let html = `<div class="table-container"><table><thead><tr>
                <th>Campaña</th><th>Cultivo</th><th>Fecha Cierre</th><th>Total kg</th><th>Ingreso</th>
            </tr></thead><tbody>`;
            data.forEach(h => {
                const fecha = new Date(h.fecha_cierre + 'T00:00:00').toLocaleDateString('es-AR');
                const ing   = parseFloat(h.ingreso_total).toLocaleString('es-AR', {minimumFractionDigits:2});
                const kgs   = parseFloat(h.kg_total).toLocaleString('es-AR', {minimumFractionDigits:2});
                html += `<tr>
                    <td><strong style="color:var(--accent);">${h.campania || '—'}</strong></td>
                    <td>${h.cultivo || '—'}</td>
                    <td>${fecha}</td>
                    <td><strong>${kgs} kg</strong></td>
                    <td style="color:var(--accent); font-weight:600;">$${ing}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            document.getElementById('historialContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('historialContent').innerHTML = `
                <div style="text-align:center; padding:20px; color:var(--danger);">
                    Error al cargar el historial.
                </div>`;
        });
}

function cerrarHistorial() {
    document.getElementById('historialModal').style.display = 'none';
    document.body.classList.remove('modal-open');
}
</script>

<?php require_once 'includes/footer.php'; ?>
