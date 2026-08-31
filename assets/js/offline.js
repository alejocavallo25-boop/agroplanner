/**
 * assets/js/offline.js
 *
 * El indicador de conexión de las pantallas normales, y el rescate de lo que
 * haya quedado guardado por la versión anterior.
 *
 * QUÉ SE SACÓ DE ACÁ Y POR QUÉ
 *
 * Esta hoja interceptaba TODOS los formularios de la aplicación cuando
 * navigator.onLine daba false, los guardaba en el teléfono y los reenviaba
 * después. Tenía tres problemas serios:
 *
 *   1. Reenviaba a cualquier endpoint, y ninguno estaba preparado para recibir
 *      la misma carga dos veces. Si el envío llegaba y la respuesta se perdía, el
 *      gasto quedaba cargado dos veces. En un cuaderno de costos eso no se nota
 *      hasta que el margen sale mal.
 *
 *   2. Borraba de la cola con `response.ok || response.redirected`. La pantalla
 *      de login es un 200. Un aviso de sesión vencida es un 200. Una página de
 *      error del hosting es un 200. Con cualquiera de las tres, el dato se
 *      borraba del teléfono sin haberse guardado en ningún lado.
 *
 *   3. Agarraba también el formulario de login y el buscador, que no tiene
 *      sentido encolar.
 *
 * Para cargar sin señal está ahora campo.html, que sí tiene un número por carga
 * para no duplicar y sólo borra cuando el servidor confirma. Lo que queda acá es
 * el aviso de estado, y una salida para lo que la versión vieja haya dejado
 * guardado: no se manda solo ni se borra solo, se muestra y decide el productor.
 */
(function () {
    'use strict';

    var DB_VIEJA = 'AgroPlannerOfflineDB';
    var STORE_VIEJO = 'pendingRequests';

    /* ── Estilos del aviso ─────────────────────────────────────────────────── */
    function estilos() {
        if (document.getElementById('offline-styles')) return;
        var s = document.createElement('style');
        s.id = 'offline-styles';
        s.textContent =
            '.offline-badge{display:none;align-items:center;gap:8px;padding:6px 14px;' +
            'border-radius:50px;font-size:0.85rem;font-weight:600;white-space:nowrap;z-index:9000;' +
            'border:1px solid transparent}' +
            '.offline-badge.danger{background:oklch(0.450 0.160 28 / 0.10);color:oklch(0.450 0.160 28);' +
            'border-color:oklch(0.450 0.160 28 / 0.35)}' +
            '.offline-badge.warning{background:oklch(0.470 0.120 70 / 0.12);color:oklch(0.470 0.120 70);' +
            'border-color:oklch(0.470 0.120 70 / 0.35)}' +
            '.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;background:currentColor}' +
            '#rescate-viejo{position:fixed;left:16px;right:16px;bottom:16px;max-width:460px;margin:0 auto;' +
            'background:var(--bg-card,#fff);border:1px solid oklch(0.470 0.120 70 / 0.45);border-radius:12px;' +
            'padding:16px;z-index:9500;box-shadow:0 8px 24px rgba(0,0,0,0.12);font-size:0.9rem}' +
            '#rescate-viejo h3{margin:0 0 6px;font-size:0.95rem}' +
            '#rescate-viejo p{margin:0 0 12px;color:var(--text-muted,#555)}' +
            '#rescate-viejo button{font:inherit;font-weight:600;padding:9px 14px;border-radius:8px;cursor:pointer;' +
            'min-height:40px}' +
            '#rescate-viejo .ir{background:var(--accent,#2d6a4f);color:#fff;border:0}' +
            '#rescate-viejo .no{background:none;border:1px solid var(--border,#ccc);color:var(--text-muted,#555)}';
        document.head.appendChild(s);
    }

    /* ── El aviso de estado ────────────────────────────────────────────────── */
    function pintarEstado() {
        var b = document.getElementById('offline-status-banner');
        if (!b) return;
        if (navigator.onLine === false) {
            b.className = 'offline-badge danger';
            b.innerHTML = '<span class="status-dot"></span> Sin señal';
            b.style.display = 'flex';
        } else {
            b.style.display = 'none';
        }
    }

    /* ── Lo que dejó la versión anterior ───────────────────────────────────── */
    function leerColaVieja() {
        return new Promise(function (ok) {
            if (!window.indexedDB) return ok([]);
            var r = indexedDB.open(DB_VIEJA);
            r.onerror = function () { ok([]); };
            r.onsuccess = function () {
                var db = r.result;
                if (!db.objectStoreNames.contains(STORE_VIEJO)) { db.close(); return ok([]); }
                var req = db.transaction(STORE_VIEJO, 'readonly').objectStore(STORE_VIEJO).getAll();
                req.onsuccess = function () { db.close(); ok(req.result || []); };
                req.onerror = function () { db.close(); ok([]); };
            };
            // Si la base no existe, onupgradeneeded la crearía vacía: se cancela.
            r.onupgradeneeded = function (e) { e.target.transaction.abort(); ok([]); };
        });
    }

    function mostrarRescate(cuantas) {
        if (document.getElementById('rescate-viejo')) return;
        var caja = document.createElement('div');
        caja.id = 'rescate-viejo';
        caja.setAttribute('role', 'alertdialog');
        caja.innerHTML =
            '<h3>Tenés ' + cuantas + (cuantas === 1 ? ' carga guardada' : ' cargas guardadas') +
            ' de una versión anterior</h3>' +
            '<p>Quedaron en el teléfono sin llegar al servidor. No las mando solas porque esa versión ' +
            'no podía saber si ya habían entrado, y podría duplicarlas. Revisá en Operaciones si están, ' +
            'y si no, cargalas de nuevo.</p>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap">' +
            '<button type="button" class="ir">Ver Operaciones</button>' +
            '<button type="button" class="no">Ya las revisé, borralas</button>' +
            '</div>';
        document.body.appendChild(caja);
        caja.querySelector('.ir').addEventListener('click', function () {
            window.location.href = 'operaciones.php';
        });
        caja.querySelector('.no').addEventListener('click', function () {
            indexedDB.deleteDatabase(DB_VIEJA);
            caja.remove();
        });
    }

    function arrancar() {
        estilos();
        pintarEstado();
        window.addEventListener('online', pintarEstado);
        window.addEventListener('offline', pintarEstado);
        leerColaVieja().then(function (cola) {
            if (cola.length) mostrarRescate(cola.length);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
