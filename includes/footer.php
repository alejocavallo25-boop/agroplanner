    <?php /* Esto estaba todo en atributos style="". Se pasó a clases por un motivo
             concreto: un style="" en la etiqueta le gana a CUALQUIER regla de la
             hoja, así que los tres enlaces eran los únicos de la aplicación a los
             que la regla de tamaño para el dedo no podía llegar — medían 27 de
             alto y "Términos" y "Privacidad" quedaban a 2 píxeles uno del otro.
             Se ve igual que antes; lo que cambia es que ahora se puede gobernar
             desde style.css. El hover pasó de onmouseover= a :hover, que además
             funciona sin JavaScript. */ ?>
    <footer class="ap-pie">
        <div class="ap-pie__caja">
            <i class="fas fa-code ap-pie__icono"></i>
            <span>Plataforma desarrollada por</span>
            <a href="https://cafra.site/" target="_blank" class="ap-pie__marca">
                CaFra <i class="fas fa-external-link-alt"></i>
            </a>
            <span class="ap-pie__sep">|</span>
            <a href="terminos.php" class="ap-pie__link">Términos</a>
            <span class="ap-pie__sep">-</span>
            <a href="privacidad.php" class="ap-pie__link">Privacidad</a>
        </div>
    </footer>
</main> <!-- Cierra .main-content -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- ===== Buscador universal (server-side) ===== -->
<style>
.ap-search-box{position:relative;display:flex;align-items:center;min-width:240px;flex:1 1 240px;max-width:420px;}
.ap-search-box .fa-search{position:absolute;left:12px;color:var(--text-muted);font-size:0.85rem;pointer-events:none;}
/* Mismo vocabulario que el resto de los campos: blanco con filete. Antes tenía
   fondo negro al 20% y respaldos del tema oscuro, así que en las tablas quedaba
   un buscador gris al lado de selects blancos. */
.ap-search-box input{width:100%;padding:9px 34px 9px 34px;border-radius:8px;border:1px solid var(--border);background:var(--n-0);color:var(--text-primary);font-size:0.9rem;transition:border-color .2s;}
/* El outline:none que había acá le ganaba al anillo de foco global y dejaba el
   buscador sin indicador visible al navegar con teclado. */
.ap-search-box input:focus-visible{border-color:var(--accent);outline:2px solid var(--accent);outline-offset:2px;}
.ap-search-box .ap-search-clear{position:absolute;right:8px;background:transparent;border:none;color:var(--text-muted);cursor:pointer;font-size:0.9rem;padding:4px;display:none;}
.ap-search-box.has-value .ap-search-clear{display:inline-flex;}
.ap-search-box.ap-buscando .fa-search{color:var(--accent);animation:apLatido 1s ease-in-out infinite;}
@keyframes apLatido{50%{opacity:.35;}}
@media (prefers-reduced-motion: reduce){.ap-search-box.ap-buscando .fa-search{animation:none;}}

/* Aviso de "guardado / eliminado": al medio de la pantalla, un par de segundos.
   Un cliente sentía que guardaba y no le aparecía: el cartel de arriba de todo
   no se veía si la lista estaba scrolleada o el modal acababa de cerrarse. */
.ap-toast{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%) scale(.96);z-index:10000;
  display:flex;align-items:center;gap:12px;max-width:calc(100vw - 32px);padding:16px 24px;border-radius:14px;
  background:var(--n-0,#fff);color:var(--text-primary,#111);border:1px solid var(--border,#ddd);
  box-shadow:0 18px 50px rgba(0,0,0,.22);font-size:1.02rem;font-weight:600;opacity:0;pointer-events:none;
  transition:opacity .18s ease, transform .18s ease;}
.ap-toast.visible{opacity:1;transform:translate(-50%,-50%) scale(1);}
.ap-toast i{font-size:1.5rem;color:var(--accent,#10b981);}
.ap-toast.error i{color:#ef4444;}
@media (prefers-reduced-motion: reduce){.ap-toast{transition:none;}}
</style>
<script>
/* Buscador universal: actualiza el parámetro ?q= preservando el resto de filtros.
 *
 * Antes recargaba la página a los 450 ms de dejar de tipear: el cursor se iba
 * del cuadro y lo que se seguía escribiendo se perdía ("me va sacando"). Ahora:
 *   - Si la pantalla marcó sus zonas de resultados con data-ap-vivo (cada una
 *     con id), se pide la página nueva por detrás y se cambian sólo esas zonas.
 *     El cuadro de búsqueda no se toca: se sigue escribiendo sin cortes.
 *   - Si no las marcó (o la respuesta no las trae: sesión vencida), recarga como
 *     antes, pero con más espera y devolviendo el cursor al cuadro al volver.
 */
(function(){
    let _apTimer, _apPedido = 0;
    const CLAVE_FOCO = 'ap_buscador_foco';

    function _apUrl(value){
        const url = new URL(window.location);
        const v = (value || '').trim();
        if (v) url.searchParams.set('q', v); else url.searchParams.delete('q');
        url.searchParams.set('page', 1);
        return url;
    }
    function _apRecargar(url){
        try { sessionStorage.setItem(CLAVE_FOCO, location.pathname); } catch (e) {}
        window.location.replace(url.href);
    }
    function _apGo(value){
        const url = _apUrl(value);
        const zonas = [...document.querySelectorAll('[data-ap-vivo][id]')];
        if (!zonas.length || !window.fetch || !window.DOMParser) { _apRecargar(url); return; }
        const n = ++_apPedido;
        document.querySelectorAll('.ap-search-box').forEach(b => b.classList.add('ap-buscando'));
        fetch(url.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'ap-buscador' } })
            .then(r => { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
            .then(html => {
                if (n !== _apPedido) return;   // llegó una búsqueda más nueva
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nuevas = zonas.map(z => doc.getElementById(z.id));
                if (nuevas.some(x => !x)) { _apRecargar(url); return; }
                zonas.forEach((z, i) => z.replaceWith(document.importNode(nuevas[i], true)));
                history.replaceState(history.state, '', url.href);
                if (typeof window.apTrasBuscar === 'function') window.apTrasBuscar();
            })
            .catch(() => { if (n === _apPedido) _apRecargar(url); })
            .finally(() => {
                if (n === _apPedido) document.querySelectorAll('.ap-search-box').forEach(b => b.classList.remove('ap-buscando'));
            });
    }
    // Si recargó, el cursor vuelve al cuadro, al final de lo escrito.
    document.addEventListener('DOMContentLoaded', function(){
        let p = null;
        try { p = sessionStorage.getItem(CLAVE_FOCO); sessionStorage.removeItem(CLAVE_FOCO); } catch (e) {}
        if (p !== location.pathname) return;
        const input = document.querySelector('.ap-search-box input');
        if (!input) return;
        input.focus();
        const fin = input.value.length;
        try { input.setSelectionRange(fin, fin); } catch (e) {}
    });
    window.apBuscar = function(input){
        const box = input.closest('.ap-search-box');
        if (box) box.classList.toggle('has-value', input.value.trim() !== '');
        // Si la caja está marcada "solo Enter", no buscar mientras se escribe.
        if (input.dataset.searchOnEnter === '1') return;
        clearTimeout(_apTimer);
        // En vivo alcanza con una pausa corta; si hay que recargar, se espera más.
        const enVivo = !!document.querySelector('[data-ap-vivo][id]');
        _apTimer = setTimeout(() => _apGo(input.value), enVivo ? 400 : 1000);
    };
    window.apBuscarEnter = function(input, ev){
        if (ev.key === 'Enter'){ ev.preventDefault(); clearTimeout(_apTimer); _apGo(input.value); }
    };
    window.apBuscarLimpiar = function(btn){
        const box = btn.closest('.ap-search-box');
        const input = box ? box.querySelector('input') : null;
        if (input){ input.value=''; } _apGo('');
    };
})();

/* ───── Aviso al medio: apAviso('Gasto guardado.') ─────
   Lo usa el flash de éxito de header.php y lo puede llamar cualquier pantalla. */
window.apAviso = function(texto, tipo){
    const t = document.createElement('div');
    t.className = 'ap-toast' + (tipo === 'error' ? ' error' : '');
    t.setAttribute('role', 'status');
    t.setAttribute('aria-live', 'polite');
    t.innerHTML = '<i class="fas ' + (tipo === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle') + '"></i><span></span>';
    t.querySelector('span').textContent = texto;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('visible'));
    setTimeout(() => { t.classList.remove('visible'); setTimeout(() => t.remove(), 250); }, 1800);
};
document.addEventListener('DOMContentLoaded', function(){
    const f = document.getElementById('apFlashAviso');
    if (f) window.apAviso(f.dataset.texto || '', 'ok');
});

/* ───── Formularios en modales: no cerrarlos sin querer ─────
   Tocar afuera de un modal lo cerraba y se perdía lo cargado. Ahora:
   - si se tocó algo adentro (data-sucio, sólo con eventos del usuario: los que
     dispara el código al abrir no cuentan), pregunta antes de cerrar;
   - si el clic empezó adentro y terminó afuera (seleccionar texto arrastrando,
     muy común en el celular), no cierra.
   El estado se limpia al cerrarse el modal. Uso: if (apPuedeCerrar(modal)) cerrar(); */
(function(){
    let bajoEn = null;
    document.addEventListener('pointerdown', e => { bajoEn = e.target; }, true);
    function marcar(e){
        if (!e.isTrusted || !e.target.closest) return;
        const m = e.target.closest('.modal-wrapper');
        if (m) m.dataset.sucio = '1';
    }
    document.addEventListener('input', marcar, true);
    document.addEventListener('change', marcar, true);
    window.apPuedeCerrar = function(modal){
        if (!modal) return true;
        if (bajoEn && bajoEn !== modal) return false;
        if (!modal.dataset.sucio) return true;
        return confirm('¿Seguro que querés cerrar? Lo que cargaste en el formulario se pierde.');
    };
    function visible(m){ return m.style.display !== 'none' && getComputedStyle(m).display !== 'none'; }
    const obs = new MutationObserver(lista => {
        lista.forEach(r => { if (!visible(r.target)) delete r.target.dataset.sucio; });
    });
    document.querySelectorAll('.modal-wrapper').forEach(m =>
        obs.observe(m, { attributes: true, attributeFilter: ['style', 'class'] }));
})();
</script>
<?php if(isset($scripts)) echo $scripts; ?>
<script>
/* ───── Botón "Exportar" (includes/exportar.php) ─────
   Un solo listener delegado para todos los menús de la página: no hace falta
   inicializar nada por instancia ni volver a enganchar si el HTML se rearma. */
(function () {
    // abierto = { wrap, btn, menu } o null. Se guardan las tres referencias porque
    // mientras está abierto el menú NO cuelga del wrap: se muda al <body>.
    let abierto = null;

    function ubicar() {
        if (!abierto) return;
        const r = abierto.btn.getBoundingClientRect();
        const m = abierto.menu.getBoundingClientRect();
        const margen = 8;

        // Alineado al borde derecho del botón, sin salirse de la pantalla.
        const izq = Math.min(
            Math.max(margen, r.right - m.width),
            Math.max(margen, window.innerWidth - m.width - margen)
        );

        // Abre hacia abajo salvo que no entre y arriba sí haya lugar.
        const entraAbajo = r.bottom + margen + m.height <= window.innerHeight;
        const entraArriba = r.top - margen - m.height >= 0;
        const top = (entraAbajo || !entraArriba) ? (r.bottom + margen) : (r.top - margen - m.height);

        abierto.menu.style.left = izq + 'px';
        abierto.menu.style.top  = top + 'px';
    }

    function cerrar() {
        if (!abierto) return;
        abierto.menu.hidden = true;
        abierto.wrap.appendChild(abierto.menu);   // vuelve a su lugar en el DOM
        abierto.btn.setAttribute('aria-expanded', 'false');
        abierto = null;
    }

    function abrir(wrap) {
        cerrar();
        const btn  = wrap.querySelector('.exp-btn');
        const menu = wrap.querySelector('.exp-menu');

        // Se cuelga del <body> a propósito. Nació por el backdrop-filter de los
        // .glass-panel, que los convertía en bloque contenedor de sus descendientes
        // position:fixed y dejaba el menú corrido. El vidrio ya no está, pero esto
        // se mantiene: también evita que un overflow de la tabla lo recorte.
        document.body.appendChild(menu);
        menu.hidden = false;
        btn.setAttribute('aria-expanded', 'true');

        abierto = { wrap: wrap, btn: btn, menu: menu };
        ubicar();   // se mide con el menú ya visible, si no no tiene tamaño

        // Fuera del wrap el menú deja de estar en el orden de tabulación natural,
        // así que se lleva el foco a mano. Con :focus-visible el anillo sólo se ve
        // si se llegó por teclado, no al abrirlo con el mouse.
        const primera = menu.querySelector('.exp-item');
        if (primera) primera.focus();
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;

        const btn = e.target.closest('.exp-btn');
        // El botón de un solo formato es un <a> con la misma clase: se deja pasar.
        if (btn && btn.tagName === 'BUTTON') {
            e.preventDefault();
            const wrap = btn.closest('.exp-wrap');
            if (abierto && abierto.wrap === wrap) cerrar(); else abrir(wrap);
            return;
        }

        // Al elegir una opción se cierra recién en el próximo tick: mover el <a>
        // de lugar en pleno evento de clic puede cancelarle la navegación.
        if (e.target.closest('.exp-item')) {
            setTimeout(cerrar, 0);
            return;
        }

        cerrar();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && abierto) {
            const btn = abierto.btn;
            cerrar();
            btn.focus();
        }
    });

    // Si la página se mueve debajo del menú, lo seguimos en vez de dejarlo flotando.
    window.addEventListener('resize', ubicar);
    window.addEventListener('scroll', ubicar, true);

    // Las tipografías vienen de un CDN: si terminan de cargar con el menú abierto,
    // el botón se corre unos píxeles y el menú quedaría desalineado.
    window.addEventListener('load', ubicar);
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(ubicar);
    }
})();

document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('overlay');

    if (menuToggle && sidebar && overlay) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.add('active');
            overlay.classList.add('active');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    }
});
</script>
</body>
</html>
