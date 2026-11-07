<?php
/* carga_lotes.php — Carga por lotes: una labor en varios lotes de una vez.
 *
 * Arriba lo que es igual para todos (la labor, el contratista, los insumos con
 * su dosis y precio); abajo una fila por lote con su fecha, sus hectáreas, su
 * cultivo y, si hace falta, su propia dosis. Se guarda un gasto por lote, con
 * la misma lógica que la carga de a uno (includes/carga_lotes.php).
 *
 *   ?carga=<id>    editar una carga entera (reemplaza sus gastos)
 *   ?repetir=<id>  empezar una carga nueva copiando otra, con fecha de hoy
 *   ?op=<id>       lo mismo, desde un gasto suelto
 */
require_once 'config/auth.php';
require_agricultura();
require_once 'config/database.php';
require_once 'includes/carga_lotes.php';

validate_csrf();
$usuario_id = (int)$_SESSION['usuario_id'];

// ─── Lo del usuario ─────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT id, nombre, superficie, campania, cultivo_actual FROM lotes WHERE usuario_id = ? ORDER BY nombre");
$st->execute([$usuario_id]);
$lotes = $st->fetchAll(PDO::FETCH_ASSOC);
$lotes_sup = array_column($lotes, 'superficie', 'id');

$st = $pdo->prepare("SELECT lote_id, ciclo AS campania, nombre AS cultivo FROM cultivos WHERE usuario_id = ? ORDER BY ciclo DESC");
$st->execute([$usuario_id]);
$cultivos = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT id, nombre, unidad_medida, stock_actual, precio_estimado_usd, estado FROM insumos WHERE usuario_id = ? ORDER BY nombre");
$st->execute([$usuario_id]);
$insumos = $st->fetchAll(PDO::FETCH_ASSOC);
$insumos_propios = array_fill_keys(array_map('intval', array_column($insumos, 'id')), true);
$insumos_nombres = array_column($insumos, 'nombre', 'id');

function plantillas_de(PDO $pdo, int $usuario_id): array
{
    try {
        $st = $pdo->prepare("SELECT id, nombre, datos FROM plantillas_labor WHERE usuario_id = ? ORDER BY nombre");
        $st->execute([$usuario_id]);
        return array_map(fn($p) => ['id' => (int)$p['id'], 'nombre' => $p['nombre'], 'datos' => json_decode($p['datos'], true)],
                         $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return [];
    }
}

function responder_json(array $r): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$errores = [];
$inicial = null;
$editar  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion  = $_POST['action'] ?? '';
    $payload = json_decode((string)($_POST['payload'] ?? ''), true);

    // ─── Plantillas (por fetch: no se pierde lo que hay en la planilla) ────
    if ($accion === 'guardar_plantilla') {
        $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 100);
        [$datos, $err] = plantilla_datos(is_array($payload) ? $payload : [], $insumos_propios, $insumos_nombres);
        if ($nombre === '') $err[] = 'Poné un nombre para la plantilla.';
        if ($err) responder_json(['ok' => false, 'msg' => implode(' ', $err)]);
        $pdo->prepare("INSERT INTO plantillas_labor (usuario_id, nombre, datos) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE datos = VALUES(datos)")
            ->execute([$usuario_id, $nombre, json_encode($datos, JSON_UNESCAPED_UNICODE)]);
        responder_json(['ok' => true, 'plantillas' => plantillas_de($pdo, $usuario_id), 'nombre' => $nombre]);
    }
    if ($accion === 'borrar_plantilla') {
        $pdo->prepare("DELETE FROM plantillas_labor WHERE id = ? AND usuario_id = ?")
            ->execute([(int)($_POST['id'] ?? 0), $usuario_id]);
        responder_json(['ok' => true, 'plantillas' => plantillas_de($pdo, $usuario_id)]);
    }

    // ─── Guardar la carga ───────────────────────────────────────────────────
    if ($accion === 'guardar') {
        $editar = (string)($_POST['carga_id'] ?? '');
        $editar = carga_id_valido($editar) && carga_columna_existe($pdo) ? $editar : null;

        if (!is_array($payload)) {
            $errores[] = 'No llegó la planilla. Volvé a intentarlo.';
        } else {
            $norm = carga_normalizar($payload, $lotes_sup, $insumos_propios);
            $errores = $norm['errores'];
            if (!$errores) {
                try {
                    carga_guardar($pdo, $usuario_id, $norm, $editar);
                    $n = count($norm['destinos']);
                    set_flash('success', $editar
                        ? "Carga actualizada: $n " . ($n === 1 ? 'gasto' : 'gastos, uno por lote') . '.'
                        : "Listo: se guardaron $n " . ($n === 1 ? 'gasto' : 'gastos, uno por lote') . '.');
                    header('Location: operaciones.php');
                    exit;
                } catch (Throwable $e) {
                    error_log('[carga_lotes] no se pudo guardar: ' . $e->getMessage());
                    $errores[] = 'No se pudo guardar. No quedó nada a medias: no se guardó ningún lote y el stock quedó como estaba.';
                }
            }
            $inicial = $payload;   // se vuelve a mostrar lo que había cargado
        }
    }
}

// ─── Con qué arranca la planilla ────────────────────────────────────────────
$titulo_modo = 'Nueva carga';
// Sin la migración no hay cargas agrupadas: sólo se puede repetir un gasto suelto.
$hay_cargas = carga_columna_existe($pdo);
if ($inicial === null) {
    if (!$hay_cargas && (!empty($_GET['carga']) || !empty($_GET['repetir']))) {
        $inicial = null;
    } elseif (!empty($_GET['carga']) && carga_id_valido((string)$_GET['carga'])) {
        $inicial = carga_leer($pdo, $usuario_id, (string)$_GET['carga']);
        if ($inicial) { $editar = (string)$_GET['carga']; }
    } elseif (!empty($_GET['repetir']) && carga_id_valido((string)$_GET['repetir'])) {
        $inicial = carga_leer($pdo, $usuario_id, (string)$_GET['repetir']);
    } elseif (!empty($_GET['op'])) {
        $inicial = carga_leer($pdo, $usuario_id, null, (int)$_GET['op']);
    }
    // Repetir: todo con fecha de hoy, cada lote sin fecha propia.
    if ($inicial && !$editar) {
        $inicial['fecha'] = date('Y-m-d');
        foreach ($inicial['lotes'] as &$l) $l['fecha'] = '';
        unset($l);
        $titulo_modo = 'Repetir una carga';
    }
}
if ($editar) $titulo_modo = 'Editar carga';

$page_title = 'Carga por lotes';
require_once 'includes/header.php';
$J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE;
?>

<?php foreach ($errores as $e): ?>
<div class="cl-error" role="alert"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<?php if (!$lotes): ?>
<div class="glass-panel">
    <p>Primero cargá tus lotes con su superficie en <a href="lotes.php">Lotes y Cultivos</a>.</p>
</div>
<?php else: ?>

<form method="POST" id="clForm" onsubmit="return clEnviar()">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="guardar">
    <input type="hidden" name="payload" id="clPayload">
    <?php if ($editar): ?><input type="hidden" name="carga_id" value="<?= htmlspecialchars($editar) ?>"><?php endif; ?>

    <div class="glass-panel cl-panel">
        <div class="cl-cabecera">
            <div>
                <h2 class="cl-h2"><?= htmlspecialchars($titulo_modo) ?></h2>
                <p class="cl-ayuda">
                    <?php if ($editar): ?>
                        Al guardar se reemplazan los gastos de esta carga y se recalcula el stock.
                    <?php else: ?>
                        Cargá una vez lo que se hizo y tildá los lotes. Se guarda un gasto por lote.
                    <?php endif; ?>
                </p>
            </div>
            <div class="cl-plantillas">
                <label for="clPlantilla" class="cl-mini">Plantilla</label>
                <div class="cl-fila">
                    <select id="clPlantilla" onchange="clUsarPlantilla(this.value)"></select>
                    <button type="button" class="btn cl-btn-sec" onclick="clGuardarPlantilla()" title="Guardar lo de arriba como plantilla"><i class="fas fa-bookmark"></i> Guardar</button>
                    <button type="button" class="btn cl-btn-sec" id="clBorrarPlantilla" onclick="clBorrarPlantilla()" title="Borrar la plantilla elegida" hidden><i class="fas fa-trash"></i></button>
                </div>
            </div>
        </div>

        <div class="cl-grid">
            <div class="cl-campo">
                <label for="clGrupo">Labor</label>
                <select id="clGrupo" data-campo="grupo">
                    <option value="siembra">Siembra</option>
                    <option value="fertilizacion">Fertilización</option>
                    <option value="pulverizacion">Pulverización</option>
                    <option value="cosecha">Cosecha</option>
                    <option value="otros">Otro gasto</option>
                </select>
            </div>
            <div class="cl-campo" id="clGrupoDescWrap" hidden>
                <label for="clGrupoDesc">¿Cuál?</label>
                <input type="text" id="clGrupoDesc" data-campo="grupo_desc" maxlength="255" placeholder="Ej: fletes, análisis de suelo">
            </div>
            <div class="cl-campo">
                <label for="clTipo">Qué se carga</label>
                <select id="clTipo" data-campo="tipo">
                    <option value="receta_labor">Labor + insumos</option>
                    <option value="insumo">Sólo insumos</option>
                    <option value="labor">Sólo labor</option>
                </select>
            </div>
            <div class="cl-campo">
                <label for="clFecha">Fecha <span class="cl-mini">(de todos los lotes)</span></label>
                <input type="date" id="clFecha" data-campo="fecha" required>
            </div>
            <div class="cl-campo">
                <label for="clMoneda">Moneda</label>
                <select id="clMoneda" data-campo="moneda">
                    <option value="ARS">$ Pesos</option>
                    <option value="USD">US$ Dólares</option>
                </select>
            </div>
        </div>

        <div id="clLabor" class="cl-bloque">
            <div class="cl-bloque-tit">Labor</div>
            <div class="cl-grid">
                <div class="cl-campo">
                    <label for="clProveedor">Contratista</label>
                    <input type="text" id="clProveedor" data-campo="proveedor" maxlength="255" placeholder="Ej: Contratista Juan">
                </div>
                <div class="cl-campo">
                    <label for="clPrecio">Precio por ha</label>
                    <input type="text" inputmode="decimal" id="clPrecio" data-campo="precio" placeholder="0">
                </div>
            </div>
            <small class="cl-mini" id="clNotaReceta">Con insumos, la labor se cobra sobre la superficie total del lote, como en la receta.</small>
        </div>

        <div id="clInsumos" class="cl-bloque">
            <div class="cl-bloque-tit">Insumos <span class="cl-mini">— la dosis de acá va a todos los lotes; abajo se cambia para uno</span></div>
            <div class="table-container cl-tabla-ins">
                <table>
                    <thead><tr><th>Insumo</th><th>Dosis por ha</th><th>Precio unitario</th><th></th></tr></thead>
                    <tbody id="clInsumosFilas"></tbody>
                </table>
            </div>
            <button type="button" class="btn cl-btn-sec" onclick="clAgregarInsumo()"><i class="fas fa-plus"></i> Agregar insumo</button>
            <small class="cl-aviso" id="clAvisoUsd" hidden><i class="fas fa-triangle-exclamation"></i> El precio que trae el stock está en dólares y este gasto está en pesos. Revisalo.</small>
        </div>
    </div>

    <div class="glass-panel cl-panel">
        <div class="cl-cabecera">
            <h2 class="cl-h2">Lotes</h2>
            <div class="cl-fila">
                <input type="search" id="clBuscarLote" placeholder="Buscar lote…" aria-label="Buscar lote" oninput="clFiltrarLotes()">
                <button type="button" class="btn cl-btn-sec" onclick="clMarcar(true)">Todos</button>
                <button type="button" class="btn cl-btn-sec" onclick="clMarcar(false)">Ninguno</button>
            </div>
        </div>
        <p class="cl-ayuda">Hectáreas vacías es el lote entero. Una dosis vacía usa la de arriba; con 0, ese insumo no va en ese lote.</p>
        <div class="table-container">
            <table class="cl-tabla-lotes">
                <thead id="clLotesCab"></thead>
                <tbody id="clLotesFilas"></tbody>
            </table>
        </div>
        <div id="clStock" class="cl-stock"></div>
    </div>

    <div class="cl-barra">
        <div class="cl-resumen" id="clResumen" aria-live="polite"></div>
        <a href="operaciones.php" class="btn cl-btn-sec">Cancelar</a>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> <?= $editar ? 'Guardar cambios' : 'Guardar carga' ?></button>
    </div>
</form>

<?php if ($editar): ?>
<?php /* Fuera del formulario de arriba: un formulario no puede ir dentro de otro. */ ?>
<form method="POST" action="operaciones.php" class="cl-borrar"
      onsubmit="return confirm('¿Borrar los gastos de esta carga en todos sus lotes? El stock de los insumos vuelve a como estaba.');">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="delete_carga">
    <input type="hidden" name="carga_id" value="<?= htmlspecialchars($editar) ?>">
    <button type="submit" class="btn cl-btn-sec" style="color: var(--danger);"><i class="fas fa-trash"></i> Borrar la carga entera</button>
</form>
<?php endif; ?>

<style>
.cl-error { background: rgba(239,68,68,.1); color: #b91c1c; padding: 12px 18px; border-radius: 10px; border: 1px solid rgba(239,68,68,.3); }
.cl-panel { display: flex; flex-direction: column; gap: 16px; }
.cl-cabecera { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.cl-h2 { font-size: 1.15rem; font-weight: 600; margin: 0; }
.cl-ayuda { color: var(--text-muted); font-size: .88rem; margin: 4px 0 0; }
.cl-mini { color: var(--text-muted); font-size: .78rem; font-weight: 400; }
.cl-fila { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.cl-plantillas select { min-width: 200px; }
.cl-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
.cl-campo { display: flex; flex-direction: column; gap: 5px; }
.cl-campo[hidden] { display: none; }
.cl-bloque { display: flex; flex-direction: column; gap: 10px; padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--n-25); }
.cl-bloque[hidden] { display: none; }
.cl-bloque-tit { font-weight: 600; font-size: .92rem; }
.cl-btn-sec { background: var(--n-100); color: var(--text-primary); border: 1px solid var(--border); padding: 8px 12px; font-size: .85rem; align-self: flex-start; }
.cl-aviso { color: var(--se-warning); }
/* El estilo de los campos de la app vive en .form-group y .modal-panel; esta
   pantalla no usa ninguno de los dos, así que lo repite. */
.cl-panel input:not([type=checkbox]), .cl-panel select {
    width: 100%; padding: 9px 12px; border: 1px solid var(--border); background: var(--input-bg);
    color: var(--text-primary); border-radius: 8px; font-size: 14.5px; font-family: inherit;
}
.cl-panel input:not([type=checkbox]):focus, .cl-panel select:focus {
    border-color: var(--accent); box-shadow: 0 0 0 3px var(--focus-ring); background: var(--n-0); outline: none;
}
#clBuscarLote { width: 200px; }
.cl-tabla-ins table, .cl-tabla-lotes { width: 100%; }
.cl-tabla-ins td, .cl-tabla-lotes td { vertical-align: middle; }
.cl-tabla-ins input, .cl-tabla-ins select { width: 100%; min-width: 110px; }
.cl-tabla-lotes input[type=text], .cl-tabla-lotes input[type=date], .cl-tabla-lotes select { min-width: 0; width: 100%; padding: 6px 8px; }
.cl-tabla-lotes .cl-num { width: 90px; }
.cl-tabla-lotes tr.cl-off td:not(:first-child) { opacity: .35; }
.cl-lote-lbl { display: flex; align-items: center; gap: 10px; cursor: pointer; white-space: nowrap; }
.cl-tabla-lotes input[type=checkbox], .cl-tabla-ins input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--accent); }
.cl-lote-nom { font-weight: 600; }
.cl-costo { text-align: right; white-space: nowrap; font-weight: 600; }
.cl-quitar { background: transparent; color: var(--danger); border: none; padding: 6px 8px; cursor: pointer; }
.cl-stock { display: flex; flex-wrap: wrap; gap: 8px; }
.cl-stock span { font-size: .82rem; padding: 4px 10px; border-radius: 20px; background: var(--n-100); border: 1px solid var(--border); }
.cl-stock span.cl-neg { color: var(--danger); border-color: rgba(239,68,68,.4); }
.cl-barra { position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            padding: 12px 16px; background: var(--bg-card, #fff); border: 1px solid var(--border); border-radius: 12px;
            box-shadow: 0 -6px 20px rgba(0,0,0,.08); }
.cl-resumen { margin-right: auto; font-size: .95rem; }
.cl-resumen b { font-size: 1.05rem; }
/* En el celular cada lote es una tarjeta: una tabla de seis columnas no entra. */
@media (max-width: 768px) {
    /* Insumos: cada campo debajo de su rótulo, a lo ancho. */
    .cl-tabla-ins td { flex-direction: column; align-items: stretch !important; gap: 4px; }
    .cl-tabla-ins td::before { max-width: none !important; }
    .cl-tabla-ins td:last-child { align-items: flex-end !important; }
    #clBuscarLote { width: 100%; }
    .cl-tabla-lotes thead { display: none; }
    .cl-tabla-lotes tr { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 10px; padding: 10px 0; border-bottom: 1px solid var(--border); }
    .cl-tabla-lotes td { display: flex; flex-direction: column; gap: 3px; padding: 0 !important; border: none !important; }
    .cl-tabla-lotes td::before { content: attr(data-label); font-size: .72rem; color: var(--text-muted); }
    .cl-tabla-lotes td.cl-td-lote { grid-column: 1 / -1; flex-direction: row; align-items: center; gap: 10px; }
    .cl-tabla-lotes td.cl-td-lote::before { content: none; }
    .cl-tabla-lotes td.cl-td-cultivo { grid-column: 1 / -1; }
    .cl-tabla-lotes .cl-num { width: 100%; }
    .cl-costo { text-align: left; }
    .cl-tabla-lotes tr.cl-off { grid-template-columns: 1fr; }
    .cl-tabla-lotes tr.cl-off td:not(.cl-td-lote) { display: none; }
}
</style>

<script>
const LOTES      = <?= json_encode($lotes, $J) ?>;
const CULTIVOS   = <?= json_encode($cultivos, $J) ?>;
const INSUMOS    = <?= json_encode($insumos, $J) ?>;
let   PLANTILLAS = <?= json_encode(plantillas_de($pdo, $usuario_id), $J) ?>;
const INICIAL    = <?= json_encode($inicial, $J) ?>;
const EDITANDO   = <?= $editar ? 'true' : 'false' ?>;
const CSRF       = <?= json_encode(get_csrf_token()) ?>;

const num = v => { const n = parseFloat(String(v ?? '').replace(',', '.')); return isNaN(n) ? 0 : n; };
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = (n, d = 2) => n.toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d });
const hoy = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/* El estado de la planilla. Los insumos llevan una clave fija (k) que es la que
   usan las dosis de cada lote: así quitar uno no corre las columnas de los demás. */
let st = { grupo: 'siembra', grupo_desc: '', tipo: 'receta_labor', fecha: hoy(), moneda: 'ARS',
           proveedor: '', precio: '', insumos: [], lotes: {} };
let proxK = 0;
let stockInicial = {};   // al editar: lo que la carga ya había descontado, que vuelve al guardar

function opcionesCultivo(loteId) {
    const op = [];
    const l = LOTES.find(x => x.id == loteId);
    if (l && l.campania && l.cultivo_actual) op.push(l.campania + ' | ' + l.cultivo_actual);
    CULTIVOS.forEach(c => {
        const v = c.campania + ' | ' + c.cultivo;
        if (c.lote_id == loteId && c.campania && c.cultivo && !op.includes(v)) op.push(v);
    });
    return op;
}

function cargarComun(d) {
    ['grupo', 'grupo_desc', 'tipo', 'moneda', 'proveedor'].forEach(k => { if (d[k] !== undefined && d[k] !== null) st[k] = d[k]; });
    if (d.precio !== undefined) st.precio = d.precio ? String(d.precio) : '';
    st.insumos = [];
    proxK = 0;
    const lista = Array.isArray(d.insumos) ? d.insumos.map((x, i) => [i, x]) : Object.entries(d.insumos || {});
    const mapa = {};
    lista.forEach(([k, i]) => {
        let id = i.insumo_id ? parseInt(i.insumo_id) : null;
        let nombre = i.nombre_libre || '';
        // Insumo que ya no está en el stock (plantilla vieja): queda como texto.
        if (id && !INSUMOS.some(x => x.id == id)) { id = null; }
        const nk = proxK++;
        mapa[k] = nk;
        st.insumos.push({ k: nk, insumo_id: id, nombre_libre: id ? '' : nombre, dosis: i.dosis ? String(i.dosis) : '', precio: i.precio ? String(i.precio) : '' });
    });
    return mapa;
}

function init() {
    LOTES.forEach(l => {
        st.lotes[l.id] = { on: false, fecha: '', ha: '', cultivo: opcionesCultivo(l.id)[0] || '', dosis: {} };
    });
    if (INICIAL) {
        const mapa = cargarComun(INICIAL);
        if (INICIAL.fecha) st.fecha = INICIAL.fecha;
        (INICIAL.lotes || []).forEach(l => {
            const fila = st.lotes[l.id];
            if (!fila) return;
            fila.on = true;
            fila.fecha = (l.fecha && l.fecha !== st.fecha) ? l.fecha : '';
            const sup = num(LOTES.find(x => x.id == l.id).superficie);
            fila.ha = (l.ha && num(l.ha) !== sup) ? String(l.ha) : '';
            fila.cultivo = l.cultivo ?? fila.cultivo;
            const dosis = Array.isArray(l.dosis) ? l.dosis.map((v, i) => [i, v]) : Object.entries(l.dosis || {});
            dosis.forEach(([k, v]) => { if (mapa[k] !== undefined && v !== '' && v !== null) fila.dosis[mapa[k]] = String(v); });
        });
        if (EDITANDO) stockInicial = consumo();
    }
    if (!st.insumos.length) clAgregarInsumo(false);

    document.querySelectorAll('[data-campo]').forEach(el => {
        el.value = st[el.dataset.campo] ?? '';
        el.addEventListener('input', () => {
            st[el.dataset.campo] = el.value;
            if (el.dataset.campo === 'tipo' || el.dataset.campo === 'grupo') { mostrarBloques(); pintarLotes(); }
            if (el.dataset.campo === 'fecha') pintarLotes();
            recalcular();
        });
    });
    pintarPlantillas();
    mostrarBloques();
    pintarInsumos();
    pintarLotes();
    recalcular();
}

function conLabor()   { return st.tipo !== 'insumo'; }
function conInsumos() { return st.tipo !== 'labor'; }
function insumosUsados() { return conInsumos() ? st.insumos.filter(i => i.insumo_id || i.nombre_libre.trim()) : []; }

function mostrarBloques() {
    document.getElementById('clLabor').hidden = !conLabor();
    document.getElementById('clInsumos').hidden = !conInsumos();
    document.getElementById('clNotaReceta').hidden = st.tipo !== 'receta_labor';
    document.getElementById('clGrupoDescWrap').hidden = st.grupo !== 'otros';
}

/* ─── Insumos ──────────────────────────────────────────────────────────── */
function clAgregarInsumo(pintar = true) {
    st.insumos.push({ k: proxK++, insumo_id: null, nombre_libre: '', dosis: '', precio: '' });
    if (pintar) { pintarInsumos(); pintarLotes(); recalcular(); }
}

function pintarInsumos() {
    const usados = new Set(st.insumos.map(i => i.insumo_id).filter(Boolean));
    document.getElementById('clInsumosFilas').innerHTML = st.insumos.map(i => {
        const ops = INSUMOS.filter(x => x.estado === 'activo' || usados.has(parseInt(x.id))).map(x =>
            `<option value="${x.id}" ${x.id == i.insumo_id ? 'selected' : ''}>${esc(x.nombre)} — stock ${fmt(num(x.stock_actual))} ${esc(x.unidad_medida || '')}</option>`).join('');
        const manual = !i.insumo_id;
        return `<tr data-k="${i.k}">
            <td data-label="Insumo">
                <select onchange="clElegirInsumo(${i.k}, this.value)" aria-label="Insumo">
                    <option value="">Escribir a mano (no descuenta stock)</option>${ops}
                </select>
                ${manual ? `<input type="text" style="margin-top:6px" placeholder="Nombre del insumo" value="${esc(i.nombre_libre)}" oninput="clInsumoCampo(${i.k}, 'nombre_libre', this.value, true)" aria-label="Nombre del insumo">` : ''}
            </td>
            <td data-label="Dosis por ha"><input type="text" inputmode="decimal" value="${esc(i.dosis)}" placeholder="0" oninput="clInsumoCampo(${i.k}, 'dosis', this.value)" aria-label="Dosis por ha"></td>
            <td data-label="Precio unitario"><input type="text" inputmode="decimal" value="${esc(i.precio)}" placeholder="0" oninput="clInsumoCampo(${i.k}, 'precio', this.value)" aria-label="Precio unitario"></td>
            <td><button type="button" class="cl-quitar" onclick="clQuitarInsumo(${i.k})" title="Quitar" aria-label="Quitar insumo"><i class="fas fa-xmark"></i></button></td>
        </tr>`;
    }).join('');
}

function clElegirInsumo(k, valor) {
    const i = st.insumos.find(x => x.k === k);
    i.insumo_id = valor ? parseInt(valor) : null;
    if (i.insumo_id) {
        i.nombre_libre = '';
        const x = INSUMOS.find(z => z.id == i.insumo_id);
        // El precio del catálogo está en dólares: se trae sólo si no había uno.
        if (x && !num(i.precio) && num(x.precio_estimado_usd)) { i.precio = String(num(x.precio_estimado_usd)); i.delStock = true; }
    }
    pintarInsumos(); pintarLotes(); recalcular();
}

function clInsumoCampo(k, campo, valor, repintarLotes) {
    const i = st.insumos.find(x => x.k === k);
    i[campo] = valor;
    if (campo === 'precio') i.delStock = false;
    // El nombre a mano cambia la columna de ese insumo en la tabla de lotes (o la
    // crea, si estaba vacío): hay que repintar filas y encabezado juntos.
    if (repintarLotes) pintarLotes();
    pintarPlaceholders();
    recalcular();
}

function clQuitarInsumo(k) {
    st.insumos = st.insumos.filter(x => x.k !== k);
    Object.values(st.lotes).forEach(l => delete l.dosis[k]);
    if (!st.insumos.length) clAgregarInsumo(false);
    pintarInsumos(); pintarLotes(); recalcular();
}

function nombreInsumo(i) {
    if (i.insumo_id) { const x = INSUMOS.find(z => z.id == i.insumo_id); return x ? x.nombre : 'Insumo'; }
    return i.nombre_libre.trim() || 'Insumo';
}
function unidadInsumo(i) {
    const x = i.insumo_id ? INSUMOS.find(z => z.id == i.insumo_id) : null;
    return x ? (x.unidad_medida || '') : '';
}

/* ─── Lotes ────────────────────────────────────────────────────────────── */
function pintarCabecera() {
    const ins = insumosUsados();
    document.getElementById('clLotesCab').innerHTML = `<tr>
        <th>Lote</th><th>Fecha</th><th>Hectáreas</th><th>Campaña / cultivo</th>
        ${ins.map(i => `<th>${esc(nombreInsumo(i))} <span class="cl-mini">/ha</span></th>`).join('')}
        <th style="text-align:right">Costo</th></tr>`;
}

function pintarLotes() {
    pintarCabecera();
    const ins = insumosUsados();
    document.getElementById('clLotesFilas').innerHTML = LOTES.map(l => {
        const f = st.lotes[l.id];
        const sup = num(l.superficie);
        const cults = opcionesCultivo(l.id);
        if (f.cultivo && !cults.includes(f.cultivo)) cults.push(f.cultivo);
        const dosis = ins.map(i => `<td data-label="${esc(nombreInsumo(i))} por ha">
            <input type="text" inputmode="decimal" class="cl-num cl-dosis" data-k="${i.k}" value="${esc(f.dosis[i.k] ?? '')}"
                   placeholder="${esc(i.dosis || '0')}" oninput="clLoteDosis(${l.id}, ${i.k}, this.value)" aria-label="Dosis de ${esc(nombreInsumo(i))} en ${esc(l.nombre)}"></td>`).join('');
        return `<tr data-lote="${l.id}" data-nombre="${esc(l.nombre.toLowerCase())}" class="${f.on ? '' : 'cl-off'}">
            <td class="cl-td-lote"><label class="cl-lote-lbl">
                <input type="checkbox" ${f.on ? 'checked' : ''} onchange="clLoteOn(${l.id}, this.checked)">
                <span><span class="cl-lote-nom">${esc(l.nombre)}</span> <span class="cl-mini">${fmt(sup)} ha</span></span>
            </label></td>
            <td data-label="Fecha"><input type="date" value="${esc(f.fecha || st.fecha)}" oninput="clLoteCampo(${l.id}, 'fecha', this.value)" aria-label="Fecha en ${esc(l.nombre)}"></td>
            <td data-label="Hectáreas"><input type="text" inputmode="decimal" class="cl-num" value="${esc(f.ha)}" placeholder="${fmt(sup)}" oninput="clLoteCampo(${l.id}, 'ha', this.value)" aria-label="Hectáreas en ${esc(l.nombre)}"></td>
            <td class="cl-td-cultivo" data-label="Campaña / cultivo"><select onchange="clLoteCampo(${l.id}, 'cultivo', this.value)" aria-label="Cultivo en ${esc(l.nombre)}">
                <option value="">Sin cultivo</option>
                ${cults.map(c => `<option value="${esc(c)}" ${c === f.cultivo ? 'selected' : ''}>${esc(c)}</option>`).join('')}
            </select></td>
            ${dosis}
            <td class="cl-costo" data-label="Costo" id="clCosto${l.id}"></td>
        </tr>`;
    }).join('');
    clFiltrarLotes();
}

// Las dosis vacías muestran la de arriba en gris; se actualizan sin repintar la tabla.
function pintarPlaceholders() {
    document.querySelectorAll('.cl-dosis').forEach(inp => {
        const i = st.insumos.find(x => x.k == inp.dataset.k);
        if (i) inp.placeholder = i.dosis || '0';
    });
}

function clLoteOn(id, on) {
    st.lotes[id].on = on;
    document.querySelector(`tr[data-lote="${id}"]`).classList.toggle('cl-off', !on);
    recalcular();
}
function clLoteCampo(id, campo, valor) {
    // Una fecha igual a la de arriba no es "propia": si después cambia la de arriba, la sigue.
    st.lotes[id][campo] = (campo === 'fecha' && valor === st.fecha) ? '' : valor;
    recalcular();
}
function clLoteDosis(id, k, valor) { st.lotes[id].dosis[k] = valor; recalcular(); }

function clMarcar(on) {
    document.querySelectorAll('#clLotesFilas tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        st.lotes[tr.dataset.lote].on = on;
    });
    pintarLotes(); recalcular();
}

function clFiltrarLotes() {
    const q = document.getElementById('clBuscarLote').value.trim().toLowerCase();
    document.querySelectorAll('#clLotesFilas tr').forEach(tr => {
        tr.style.display = !q || tr.dataset.nombre.includes(q) ? '' : 'none';
    });
}

/* ─── Cuentas: las mismas que operacion_costo() en includes/carga_lotes.php ─ */
function dosisDe(fila, i) {
    const propia = fila.dosis[i.k];
    return (propia === undefined || propia === '') ? num(i.dosis) : num(propia);
}

function calcular() {
    const ins = insumosUsados();
    const precio = conLabor() ? num(st.precio) : 0;
    let total = 0, ha = 0, n = 0;
    const porLote = {};
    LOTES.forEach(l => {
        const f = st.lotes[l.id];
        if (!f.on) return;
        const sup = num(l.superficie);
        const haL = num(f.ha) > 0 ? num(f.ha) : sup;
        let c = precio * (st.tipo === 'receta_labor' ? sup : haL);
        ins.forEach(i => c += dosisDe(f, i) * num(i.precio) * haL);
        porLote[l.id] = c;
        total += c; ha += haL; n++;
    });
    return { total, ha, n, porLote };
}

function consumo() {
    const usado = {};
    insumosUsados().forEach(i => {
        if (!i.insumo_id) return;
        LOTES.forEach(l => {
            const f = st.lotes[l.id];
            if (!f.on) return;
            const haL = num(f.ha) > 0 ? num(f.ha) : num(l.superficie);
            usado[i.insumo_id] = (usado[i.insumo_id] || 0) + dosisDe(f, i) * haL;
        });
    });
    return usado;
}

function recalcular() {
    const sim = st.moneda === 'USD' ? 'US$' : '$';
    const r = calcular();
    LOTES.forEach(l => {
        const td = document.getElementById('clCosto' + l.id);
        if (td) td.textContent = r.porLote[l.id] !== undefined ? sim + ' ' + fmt(r.porLote[l.id]) : '';
    });
    document.getElementById('clResumen').innerHTML = r.n
        ? `<b>${r.n} ${r.n === 1 ? 'lote' : 'lotes'}</b> · ${fmt(r.ha)} ha · <b>${sim} ${fmt(r.total)}</b>`
        : '<span class="cl-mini">Tildá los lotes donde se hizo</span>';

    // Cuánto sale de cada insumo y cómo queda el stock.
    const usado = consumo();
    document.getElementById('clStock').innerHTML = Object.entries(usado).map(([id, cant]) => {
        const x = INSUMOS.find(z => z.id == id);
        const antes = num(x.stock_actual) + (stockInicial[id] || 0);
        const queda = antes - cant;
        return `<span class="${queda < 0 ? 'cl-neg' : ''}">${esc(x.nombre)}: ${fmt(cant)} ${esc(x.unidad_medida || '')} · stock ${fmt(antes)} → ${fmt(queda)}</span>`;
    }).join('');

    document.getElementById('clAvisoUsd').hidden = !(st.moneda === 'ARS' && st.insumos.some(i => i.delStock));
}

/* ─── Guardar ──────────────────────────────────────────────────────────── */
function armarPayload(soloComun) {
    const insumos = {};
    insumosUsados().forEach(i => insumos[i.k] = { insumo_id: i.insumo_id, nombre_libre: i.nombre_libre, dosis: i.dosis, precio: i.precio });
    const p = { grupo: st.grupo, grupo_desc: st.grupo_desc, tipo: st.tipo, moneda: st.moneda,
                proveedor: st.proveedor, precio: st.precio, fecha: st.fecha, insumos };
    if (soloComun) return p;
    p.lotes = LOTES.filter(l => st.lotes[l.id].on).map(l => {
        const f = st.lotes[l.id];
        return { id: l.id, fecha: f.fecha || st.fecha, ha: f.ha, cultivo: f.cultivo, dosis: f.dosis };
    });
    return p;
}

function clEnviar() {
    const r = calcular();
    if (!r.n) { alert('Tildá al menos un lote.'); return false; }
    const sim = st.moneda === 'USD' ? 'US$' : '$';
    let msg = `Se van a guardar ${r.n} ${r.n === 1 ? 'gasto' : 'gastos (uno por lote)'} por ${sim} ${fmt(r.total)} en total.`;
    if (EDITANDO) msg += '\n\nReemplaza los gastos que tenía esta carga.';
    if (!confirm(msg)) return false;
    document.getElementById('clPayload').value = JSON.stringify(armarPayload(false));
    return true;
}

/* ─── Plantillas ───────────────────────────────────────────────────────── */
function pintarPlantillas(elegida) {
    const sel = document.getElementById('clPlantilla');
    sel.innerHTML = '<option value="">' + (PLANTILLAS.length ? 'Elegir una plantilla…' : 'Todavía no hay plantillas') + '</option>' +
        PLANTILLAS.map(p => `<option value="${p.id}" ${p.nombre === elegida ? 'selected' : ''}>${esc(p.nombre)}</option>`).join('');
    document.getElementById('clBorrarPlantilla').hidden = !sel.value;
}

function clUsarPlantilla(id) {
    document.getElementById('clBorrarPlantilla').hidden = !id;
    const p = PLANTILLAS.find(x => x.id == id);
    if (!p) return;
    const fecha = st.fecha;
    cargarComun(p.datos || {});
    st.fecha = fecha;
    // Las dosis propias de cada lote eran de otros insumos: se limpian.
    Object.values(st.lotes).forEach(l => l.dosis = {});
    if (!st.insumos.length) clAgregarInsumo(false);
    document.querySelectorAll('[data-campo]').forEach(el => el.value = st[el.dataset.campo] ?? '');
    mostrarBloques(); pintarInsumos(); pintarLotes(); recalcular();
}

async function plantillaPost(datos) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    Object.entries(datos).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch('carga_lotes.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return r.json();
}

async function clGuardarPlantilla() {
    const actual = PLANTILLAS.find(x => x.id == document.getElementById('clPlantilla').value);
    const nombre = prompt('Nombre de la plantilla (ej: Siembra soja):', actual ? actual.nombre : '');
    if (!nombre || !nombre.trim()) return;
    if (PLANTILLAS.some(p => p.nombre.toLowerCase() === nombre.trim().toLowerCase()) && !confirm('Ya hay una plantilla con ese nombre. ¿Reemplazarla?')) return;
    try {
        const r = await plantillaPost({ action: 'guardar_plantilla', nombre: nombre.trim(), payload: JSON.stringify(armarPayload(true)) });
        if (!r.ok) { alert(r.msg || 'No se pudo guardar la plantilla.'); return; }
        PLANTILLAS = r.plantillas;
        pintarPlantillas(r.nombre);
    } catch (e) { alert('No se pudo guardar la plantilla. Revisá la conexión.'); }
}

async function clBorrarPlantilla() {
    const id = document.getElementById('clPlantilla').value;
    const p = PLANTILLAS.find(x => x.id == id);
    if (!p || !confirm(`¿Borrar la plantilla "${p.nombre}"? Los gastos ya cargados no cambian.`)) return;
    try {
        const r = await plantillaPost({ action: 'borrar_plantilla', id });
        if (r.ok) { PLANTILLAS = r.plantillas; pintarPlantillas(); }
        else alert(r.msg || 'No se pudo borrar.');
    } catch (e) { alert('No se pudo borrar la plantilla. Revisá la conexión.'); }
}

init();
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
