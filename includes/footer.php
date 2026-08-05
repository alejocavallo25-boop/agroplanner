    <footer style="margin-top: auto; width: 100%; padding-top: 40px; padding-bottom: 20px; display: flex; justify-content: center; align-items: center; text-align: center;">
        <div style="background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); padding: 10px 24px; border-radius: 30px; display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; color: var(--text-muted); backdrop-filter: blur(12px); box-shadow: 0 4px 16px rgba(0,0,0,0.15); transition: transform 0.2s; flex-wrap: wrap; justify-content: center;">
            <i class="fas fa-code" style="color: var(--accent); font-size: 0.9em;"></i>
            <span>Plataforma desarrollada por</span>
            <a href="https://cafra.site/" target="_blank" style="color: var(--text-primary); text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; padding: 2px 8px; background: rgba(59, 130, 246, 0.15); border-radius: 12px; border: 1px solid rgba(59, 130, 246, 0.3); transition: all 0.2s;">
                CaFra <i class="fas fa-external-link-alt" style="color: #3b82f6; font-size: 1.0em; filter: drop-shadow(0 0 4px rgba(59, 130, 246, 0.4));"></i>
            </a>
            <span style="margin: 0 4px; color: rgba(255,255,255,0.15);">|</span>
            <a href="terminos.php" style="color: var(--text-muted); text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">Términos</a>
            <span style="margin: 0 2px; color: rgba(255,255,255,0.15);">-</span>
            <a href="privacidad.php" style="color: var(--text-muted); text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">Privacidad</a>
        </div>
    </footer>
</main> <!-- Cierra .main-content -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- ===== Buscador universal (server-side) ===== -->
<style>
.ap-search-box{position:relative;display:flex;align-items:center;min-width:240px;flex:1 1 240px;max-width:420px;}
.ap-search-box .fa-search{position:absolute;left:12px;color:var(--text-muted);font-size:0.85rem;pointer-events:none;}
.ap-search-box input{width:100%;padding:9px 34px 9px 34px;border-radius:8px;border:1px solid var(--border,rgba(255,255,255,0.1));background:rgba(0,0,0,0.2);color:var(--text-primary,#fff);font-size:0.9rem;outline:none;transition:border-color .2s;}
.ap-search-box input:focus{border-color:var(--accent,#3b82f6);}
.ap-search-box .ap-search-clear{position:absolute;right:8px;background:transparent;border:none;color:var(--text-muted);cursor:pointer;font-size:0.9rem;padding:4px;display:none;}
.ap-search-box.has-value .ap-search-clear{display:inline-flex;}
</style>
<script>
// Buscador universal: actualiza el parámetro ?q= preservando el resto de filtros.
(function(){
    let _apTimer;
    function _apGo(value){
        const url = new URL(window.location);
        const v = (value || '').trim();
        if (v) url.searchParams.set('q', v); else url.searchParams.delete('q');
        url.searchParams.set('page', 1);
        window.location.href = url.href;
    }
    window.apBuscar = function(input){
        const box = input.closest('.ap-search-box');
        if (box) box.classList.toggle('has-value', input.value.trim() !== '');
        // Si la caja está marcada "solo Enter", no buscar mientras se escribe.
        if (input.dataset.searchOnEnter === '1') return;
        clearTimeout(_apTimer);
        _apTimer = setTimeout(() => _apGo(input.value), 450);
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

        // Se cuelga del <body> a propósito. Los .glass-panel llevan backdrop-filter,
        // y eso los convierte en bloque contenedor de sus descendientes
        // position:fixed: dejándolo adentro, las coordenadas de pantalla se
        // interpretan contra la esquina del panel y el menú aparece corrido.
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
