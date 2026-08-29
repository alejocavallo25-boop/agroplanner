<?php
/**
 * includes/chat_motor.php
 *
 * Chat flotante del motor de consultas.
 *
 * Va como partial y no dentro de index.php a propósito: el motor contesta sobre
 * la campaña, así que sirve igual desde lotes, operaciones o producción. Sumarlo
 * a otra pantalla es un require más.
 *
 * IMPORTANTE: tiene que quedar FUERA de #panel-contenido y #panel-filtros. Esas
 * dos regiones se reemplazan enteras al filtrar sin recargar, y la conversación
 * se perdería en cada filtro.
 */
?>
<style>
/* =====================================================================
   CHAT DEL MOTOR

   Botón flotante y panel. La escala de z-index del proyecto: sidebar 100,
   menú de exportar 900, modales 1000. El chat va en el medio: por encima
   del contenido y del sidebar, por debajo de cualquier modal.
   ===================================================================== */
.mc-fab {
    position: fixed; right: 24px; bottom: 24px;
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--accent); color: var(--on-accent);
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem;
    box-shadow: 0 6px 20px -4px oklch(0.240 0.012 150 / 0.35);
    z-index: 930;
    transition: background 0.2s ease, transform 0.2s ease;
}
.mc-fab:hover { background: var(--accent-hover); transform: translateY(-2px); }
.mc-fab[aria-expanded="true"] { transform: none; }

.mc-backdrop {
    position: fixed; inset: 0;
    background: oklch(0.240 0.012 150 / 0.35);
    z-index: 935; display: none;
}
.mc-backdrop.abierto { display: block; }

.mc-panel {
    position: fixed; right: 24px; bottom: 92px;

    /* El ancho crece con la pantalla, con piso y techo.
       Estaba fijo en 420px, que en una laptop dejaba mucho aire alrededor y a la
       vez partía en dos renglones las líneas del reparto entre lotes
       ("· El bajo (60,0 ha): $126.050,42"), que son las más largas que muestra.
       El techo de 520 es para que no se coma media pantalla en un monitor grande:
       sigue siendo un panel al costado, no una ventana. */
    width: min(clamp(380px, 34vw, 520px), calc(100vw - 48px));

    /* Alto: la conversación es lo que más se agradece ver. El segundo término
       impide que en una ventana baja el panel se salga por arriba de la pantalla. */
    max-height: min(78vh, 780px, calc(100vh - 132px));
    background: var(--n-0);
    border: 1px solid var(--rule-strong);
    border-radius: 14px;
    box-shadow: 0 24px 60px -12px oklch(0.240 0.012 150 / 0.30);
    z-index: 940;
    display: none; flex-direction: column; overflow: hidden;
}
.mc-panel.abierto { display: flex; }

.mc-cabecera {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--n-25);
    flex-shrink: 0;
}
/* El nombre y, debajo, qué hace. La bajada existe porque "Cafrita" sola no dice
   nada la primera vez: el nombre da confianza, la línea de abajo da la función. */
.mc-nombre { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.mc-cabecera h2 { font-size: 1rem; font-weight: 600; margin: 0; color: var(--text-primary); line-height: 1.2; }
.mc-cabecera .mc-bajada {
    font-size: 0.76rem;
    color: var(--text-muted);
    line-height: 1.2;
}
.mc-cerrar {
    margin-left: auto; background: transparent; border: none; cursor: pointer;
    color: var(--text-muted); width: 40px; height: 40px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.mc-cerrar:hover { background: var(--n-100); color: var(--text-primary); }

/* Chip de contexto: el filtro que la charla viene arrastrando, siempre visible.
   Sin esto uno queda pegado a un lote sin darse cuenta y no entiende los números. */
.mc-contexto {
    display: none; align-items: center; gap: 8px;
    padding: 8px 16px; font-size: 0.82rem;
    background: var(--accent-soft); color: var(--accent);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.mc-contexto.visible { display: flex; }
.mc-contexto button {
    margin-left: auto; background: transparent; border: none; cursor: pointer;
    color: var(--accent); font-weight: 600; font-size: 0.82rem; padding: 4px 8px;
    border-radius: 6px;
}
.mc-contexto button:hover { background: var(--n-0); }

.mc-hilo {
    flex: 1; overflow-y: auto; padding: 16px;
    display: flex; flex-direction: column; gap: 12px;
}

.mc-msg { max-width: 88%; padding: 10px 14px; border-radius: 12px; line-height: 1.5; font-size: 0.92rem; }
.mc-msg-yo {
    align-self: flex-end;
    background: var(--accent); color: var(--on-accent);
    border-bottom-right-radius: 4px;
}
.mc-msg-motor {
    align-self: flex-start;
    background: var(--n-50); color: var(--text-primary);
    border: 1px solid var(--border);
    border-bottom-left-radius: 4px;
}
/* pre-line: el análisis devuelve varias viñetas separadas por saltos de línea, y
   sin esto HTML las colapsa en un párrafo corrido e ilegible. */
.mc-msg-motor .mc-detalle {
    margin-top: 6px; color: var(--text-muted); font-size: 0.86rem;
    white-space: pre-line;
}
.mc-msg-motor a.mc-ver {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 10px;
    font-size: 0.84rem; font-weight: 600; color: var(--accent); text-decoration: none;
}
.mc-msg-motor a.mc-ver:hover { text-decoration: underline; }
.mc-msg-aviso { background: var(--warning-soft); border-color: oklch(0.470 0.120 70 / 0.35); }

/* Los tres puntitos mientras piensa. Es lo que hace que se lea como una charla
   y no como un formulario que tarda. */
.mc-puntos { display: inline-flex; gap: 4px; align-items: center; }
.mc-puntos span {
    width: 6px; height: 6px; border-radius: 50%; background: var(--text-muted);
    animation: mcLatido 1.2s infinite ease-in-out;
}
.mc-puntos span:nth-child(2) { animation-delay: 0.15s; }
.mc-puntos span:nth-child(3) { animation-delay: 0.3s; }
@keyframes mcLatido { 0%, 60%, 100% { opacity: 0.25; } 30% { opacity: 1; } }

.mc-sugeridas { display: flex; gap: 6px; flex-wrap: wrap; padding: 0 16px 12px; flex-shrink: 0; }
.mc-sugeridas button {
    background: var(--n-0); border: 1px solid var(--border); color: var(--text-muted);
    border-radius: 999px; padding: 7px 13px; font-size: 0.82rem; cursor: pointer;
    font-family: inherit; transition: all 0.15s ease;
}
.mc-sugeridas button:hover { border-color: var(--accent); color: var(--accent); }

.mc-pie { display: flex; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--border); flex-shrink: 0; }
.mc-pie input {
    flex: 1; padding: 11px 14px; border-radius: 10px;
    border: 1px solid var(--border); background: var(--n-0);
    color: var(--text-primary); font-size: 0.95rem; font-family: inherit;
}
.mc-pie input:focus { border-color: var(--accent); }
.mc-enviar {
    background: var(--accent); color: var(--on-accent); border: none;
    border-radius: 10px; width: 44px; min-height: 44px; cursor: pointer; font-size: 0.95rem;
}
.mc-enviar:hover { background: var(--accent-hover); }
.mc-enviar:disabled { opacity: 0.5; cursor: not-allowed; }

/* En pantalla grande abre con un alto fijo y no encogido al contenido.
   Con max-height solo, el panel arrancaba en 323px —el tamaño del saludo— y se
   iba estirando con cada respuesta: la conversación saltaba de lugar en cada
   vuelta y el resumen de una carga entre varios lotes no entraba sin scrollear.
   Con alto fijo abre siempre igual y lo que crece es la conversación adentro. */
@media (min-width: 641px) {
    .mc-panel {
        height: min(78vh, 780px, calc(100vh - 132px));
    }
}

/* En el teléfono ocupa casi toda la pantalla: escribir y leer en un panel
   flotante sobre el contenido es incómodo, y acá la charla es la tarea. */
@media (max-width: 640px) {
    .mc-panel {
        right: 0; left: 0; bottom: 0;
        width: 100%; height: auto; max-height: 88vh;
        border-radius: 14px 14px 0 0;
        border-left: none; border-right: none; border-bottom: none;
    }
    .mc-fab { right: 16px; bottom: 16px; }
}

@media (prefers-reduced-motion: reduce) {
    .mc-fab { transition: none; }
    .mc-fab:hover { transform: none; }
    .mc-puntos span { animation: none; opacity: 0.6; }
}
</style>

<button type="button" class="mc-fab" id="mc-fab"
        aria-expanded="false" aria-controls="mc-panel"
        title="Preguntale a Cafrita">
    <i class="fas fa-comment-dots" aria-hidden="true"></i>
    <span class="ap-solo-lectores">Abrir Cafrita</span>
</button>

<div class="mc-backdrop" id="mc-backdrop" hidden></div>

<div class="mc-panel" id="mc-panel" role="dialog" aria-modal="false"
     aria-labelledby="mc-titulo" hidden>
    <div class="mc-cabecera">
        <i class="fas fa-seedling" style="color: var(--accent);" aria-hidden="true"></i>
        <div class="mc-nombre">
            <h2 id="mc-titulo">Cafrita</h2>
            <span class="mc-bajada">La que te lleva las cuentas</span>
        </div>
        <button type="button" class="mc-cerrar" id="mc-cerrar" title="Cerrar">
            <i class="fas fa-xmark" aria-hidden="true"></i>
            <span class="ap-solo-lectores">Cerrar</span>
        </button>
    </div>

    <div class="mc-contexto" id="mc-contexto">
        <i class="fas fa-filter" aria-hidden="true"></i>
        <span id="mc-contexto-txt"></span>
        <button type="button" id="mc-contexto-limpiar">Quitar</button>
    </div>

    <?php /* role=log + aria-live: cada respuesta se anuncia sola, que es como
             tiene que comportarse una conversación para un lector de pantalla. */ ?>
    <div class="mc-hilo" id="mc-hilo" role="log" aria-live="polite" aria-atomic="false"></div>

    <div class="mc-sugeridas" id="mc-sugeridas"></div>

    <form class="mc-pie" id="mc-form" autocomplete="off">
        <label for="mc-q" class="ap-solo-lectores">Escribile a Cafrita</label>
        <?php /* El placeholder sigue diciendo QUÉ se puede hacer y no el nombre:
                 el nombre ya está arriba, y acá lo que hace falta saber es que
                 además de preguntar se puede dictar un gasto. */ ?>
        <input type="text" id="mc-q" placeholder="Preguntá, o dictá un gasto">
        <?php /* Dictado: para el que está en el campo con las manos sucias. Se
                 muestra sólo si el navegador lo soporta — ver el JS de abajo. */ ?>
        <button type="button" class="mc-enviar" id="mc-voz" title="Dictar" hidden
                style="background: var(--n-0); color: var(--accent); border: 1px solid var(--border);">
            <i class="fas fa-microphone" aria-hidden="true"></i>
            <span class="ap-solo-lectores">Dictar</span>
        </button>
        <button type="submit" class="mc-enviar" id="mc-enviar" title="Enviar">
            <i class="fas fa-paper-plane" aria-hidden="true"></i>
            <span class="ap-solo-lectores">Enviar</span>
        </button>
    </form>
<?php /* El token viaja en el HTML y no en JS: es el mismo que usa el resto de los
         formularios, y el guardado por POST lo exige. */ ?>
<input type="hidden" id="mc-csrf" value="<?= htmlspecialchars(get_csrf_token()) ?>">
</div>

<script>
(function () {
    const fab      = document.getElementById('mc-fab');
    const panel    = document.getElementById('mc-panel');
    const backdrop = document.getElementById('mc-backdrop');
    const hilo     = document.getElementById('mc-hilo');
    const form     = document.getElementById('mc-form');
    const input    = document.getElementById('mc-q');
    const enviar   = document.getElementById('mc-enviar');
    const sugCont  = document.getElementById('mc-sugeridas');
    const ctxCaja  = document.getElementById('mc-contexto');
    const ctxTxt   = document.getElementById('mc-contexto-txt');
    if (!fab || !panel) return;

    const EJEMPLOS = [
        '¿Cuál es mi margen neto?',
        '¿Cuánto fue el costo por hectárea?',
        '¿Qué es el rinde de indiferencia?',
    ];

    /* La memoria de la charla. Se guarda en sessionStorage para que sobreviva a
       recargas y a moverse entre pantallas, pero no más allá de la sesión: son
       los números de tu campo y no tienen por qué quedar en el navegador. */
    let contexto = {};
    try { contexto = JSON.parse(sessionStorage.getItem('mcContexto') || '{}'); } catch (e) { contexto = {}; }

    function guardarContexto() {
        try { sessionStorage.setItem('mcContexto', JSON.stringify(contexto)); } catch (e) {}
        pintarContexto();
    }

    function pintarContexto() {
        const partes = [];
        if (contexto.cicloNombre)   partes.push('Campaña ' + contexto.cicloNombre);
        if (contexto.loteNombre)    partes.push(contexto.loteNombre);
        if (contexto.cultivoNombre) partes.push(contexto.cultivoNombre);
        if (partes.length) {
            ctxTxt.textContent = partes.join(' · ');
            ctxCaja.classList.add('visible');
        } else {
            ctxCaja.classList.remove('visible');
        }
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    /* Dos veces a propósito. Las sugerencias se pintan DESPUÉS del mensaje y su
       fila cambia de alto, así que un scroll calculado antes deja el final del
       mensaje fuera de la vista. Se nota con las respuestas largas: el reparto
       entre varios lotes tapaba justo los botones de Confirmar y Cancelar. */
    function alFinal() {
        hilo.scrollTop = hilo.scrollHeight;
        requestAnimationFrame(() => { hilo.scrollTop = hilo.scrollHeight; });
    }

    function agregarYo(texto) {
        const d = document.createElement('div');
        d.className = 'mc-msg mc-msg-yo';
        d.textContent = texto;
        hilo.appendChild(d);
        alFinal();
    }

    function agregarPensando() {
        const d = document.createElement('div');
        d.className = 'mc-msg mc-msg-motor';
        d.id = 'mc-pensando';
        d.innerHTML = '<span class="mc-puntos"><span></span><span></span><span></span></span>';
        hilo.appendChild(d);
        alFinal();
        return d;
    }

    function agregarMotor(r) {
        const d = document.createElement('div');
        d.className = 'mc-msg mc-msg-motor' + (r.ok ? '' : ' mc-msg-aviso');
        let html = '<div>' + esc(r.respuesta) + '</div>';
        if (r.detalle) html += '<div class="mc-detalle">' + esc(r.detalle) + '</div>';
        if (r.link) {
            html += '<a class="mc-ver" href="' + esc(r.link) + '">'
                  + '<i class="fas fa-table-columns" aria-hidden="true"></i> Ver en el panel</a>';
        }
        d.innerHTML = html;

        /* Propuesta de alta: nada se guarda hasta que se toca Confirmar, y lo que
           se manda son los campos que están a la vista, no la frase original. */
        if (r.alta) {
            confirmacionPendiente = true;
            const acciones = document.createElement('div');
            acciones.style.cssText = 'display:flex; gap:8px; margin-top:12px;';

            const ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'mc-enviar';
            ok.style.cssText = 'width:auto; padding:0 16px; font-size:0.86rem; font-weight:600;';
            ok.innerHTML = '<i class="fas fa-check"></i> Confirmar y guardar';

            const no = document.createElement('button');
            no.type = 'button';
            no.style.cssText = 'background:var(--n-0); border:1px solid var(--border); color:var(--text-muted);'
                             + 'border-radius:10px; padding:0 16px; min-height:44px; cursor:pointer; font-size:0.86rem;';
            no.textContent = 'Cancelar';

            ok.addEventListener('click', () => { confirmacionPendiente = false; guardarAlta(r.alta, acciones); });
            no.addEventListener('click', () => {
                confirmacionPendiente = false;
                acciones.remove();
                agregarMotor({ ok: true, respuesta: 'Listo, no guardé nada.' });
                pintarSugeridas([]);
            });

            acciones.appendChild(ok);
            acciones.appendChild(no);
            d.appendChild(acciones);
        }

        hilo.appendChild(d);
        alFinal();
    }

    async function guardarAlta(datos, acciones) {
        acciones.innerHTML = '<span style="color:var(--text-muted); font-size:0.86rem;">Guardando…</span>';

        /* URLSearchParams convierte un arreglo en "13,14" solo, pero dejarlo
           implícito es frágil: el servidor espera exactamente esa forma, así que
           se escribe. Un mismo gasto puede ir a varios lotes de una sola carga. */
        const body = new URLSearchParams({ ...datos, lotes: (datos.lotes || []).join(',') });
        body.set('csrf_token', document.getElementById('mc-csrf').value);

        /* Cuatro altas, cuatro puertas. El motor dice cuál en 'que': un gasto, un
           insumo del catálogo, un pago de alquiler y una entrega vendida no
           comparten campos ni moneda, y cada endpoint valida lo suyo. */
        const PUERTAS = {
            insumo:   'api/registrar_insumo.php',
            alquiler: 'api/registrar_alquiler.php',
            venta:    'api/registrar_venta.php',
        };
        const url = PUERTAS[datos.que] || 'api/registrar.php';

        try {
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString()
            });
            const r = await res.json();
            acciones.remove();
            /* El cierre dice lo que de verdad pasó con cada cosa. Un gasto entra en
               el margen de la campaña; un insumo del catálogo no entra en ningún
               margen —es una ficha de producto, no plata que salió— y decir que sí
               sería mentirle al productor sobre sus propios números. */
            agregarMotor({
                ok: !!r.ok,
                respuesta: r.msg || (r.ok ? 'Guardado.' : 'No se pudo guardar.'),
                /* "en Costos y Labores" y no "al cargar una operación" a secas: el
                   chat carga el gasto por nombre escrito, no eligiéndolo del
                   catálogo. Decir dónde se puede elegir de verdad evita que lo
                   busque en la conversación y no lo encuentre. */
                detalle: !r.ok ? '' : ({
                    insumo:   'Ya lo podés elegir en el formulario de Costos y Labores.',
                    alquiler: 'Ya está descontado en el margen de la campaña.',
                    venta:    'Ya suma al margen y al rinde de la campaña.',
                }[datos.que] || 'Ya está contado en el margen de la campaña.')
            });
            /* Lo que se ve detrás quedó viejo. El panel sabe refrescarse sin
               recargar (apAplicar); las otras pantallas no, y ahí hay que recargar
               o el productor ve la confirmación de que se guardó junto a una lista
               que no lo tiene. Se espera un momento para que alcance a leer el
               mensaje antes de que la página se mueva.
               La recarga preserva la URL, así que no se pierden ni los filtros ni
               la moneda que estaba mirando. */
            if (r.ok) {
                if (typeof apAplicar === 'function') {
                    // En el panel, un insumo del catálogo no se ve: no hay qué refrescar.
                    if (datos.que !== 'insumo') apAplicar(new URL(window.location.href), false);
                } else {
                    setTimeout(() => window.location.reload(), 1400);
                }
            }
        } catch (e) {
            acciones.remove();
            agregarMotor({ ok: false, respuesta: 'No pude guardar: revisá la conexión.' });
        }
    }

    function pintarSugeridas(lista) {
        sugCont.innerHTML = '';
        /* Durante una carga NO se cae a los ejemplos genéricos: ofrecer "¿Cuál es
           mi margen neto?" mientras se está preguntando el monto —o peor, mientras
           espera que confirmen un guardado— invita a tocarlo y perder lo que venía
           cargando. Si el paso no tiene opciones —como el monto— no se muestra
           ninguna y se escribe, que es lo que corresponde. */
        const enCarga = altaPendiente || confirmacionPendiente;
        const base = (lista && lista.length) ? lista : (enCarga ? [] : EJEMPLOS);
        base.forEach(t => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = t;
            b.addEventListener('click', () => preguntar(t));
            sugCont.appendChild(b);
        });
    }

    let enVuelo = false;

    /* Casilleros de la carga guiada. Se mantiene en memoria y no en
       sessionStorage a propósito: si el productor cierra el chat, el formulario
       a medias se descarta. Un alta colgada de la sesión anterior es de esas
       cosas que después nadie entiende de dónde salieron. */
    let altaPendiente = null;

    /* Hay un resumen en pantalla esperando Confirmar o Cancelar. Es distinto de
       altaPendiente —ahí el formulario ya se cerró y los datos están completos—
       pero mientras ese cartel esté vivo tampoco corresponde ofrecer preguntas
       sueltas al costado. */
    let confirmacionPendiente = false;

    /* Igual que preguntar(), pero sin mostrar la pregunta: se usa para el saludo
       de apertura, donde el "hola" lo dispara la app y no el productor. */
    async function preguntarSilencioso(texto) {
        const pensando = agregarPensando();
        try {
            const res = await fetch('api/consulta.php?q=' + encodeURIComponent(texto), { credentials: 'same-origin' });
            const r = await res.json();
            pensando.remove();
            agregarMotor(r);
            pintarSugeridas(r.sugerencias);
        } catch (e) {
            pensando.remove();
            agregarMotor({ ok: true, respuesta: 'Preguntame por los números de tu campaña.' });
            pintarSugeridas(EJEMPLOS);
        }
    }

    async function preguntar(texto) {
        const q = (texto || '').trim();
        if (!q || enVuelo) return;

        enVuelo = true;
        enviar.disabled = true;
        input.value = '';
        agregarYo(q);
        const pensando = agregarPensando();

        const p = new URLSearchParams({ q: q });
        if (contexto.ciclo)   p.set('ciclo', contexto.ciclo);
        if (contexto.lote)    p.set('lote', contexto.lote);
        if (contexto.cultivo) p.set('cultivo', contexto.cultivo);
        if (contexto.metrica) p.set('metrica', contexto.metrica);
        /* La moneda que está mirando el panel, para que el chat conteste en la
           misma. Se lee de la URL y no de una variable propia porque el toggle ya
           la deja ahí, y así no hay dos fuentes que se puedan desincronizar. */
        const monedaPanel = new URL(window.location.href).searchParams.get('moneda');
        if (monedaPanel === 'USD') p.set('moneda', 'USD');
        // Carga guiada a medio llenar: vuelve para que el motor sepa qué preguntó.
        if (altaPendiente)    p.set('alta', JSON.stringify(altaPendiente));

        let r;
        try {
            const res = await fetch('api/consulta.php?' + p.toString(), { credentials: 'same-origin' });
            r = await res.json();
        } catch (e) {
            r = {
                ok: false,
                respuesta: 'No pude consultar tus datos.',
                detalle: 'Revisá la conexión y probá de nuevo.',
                sugerencias: []
            };
        }

        pensando.remove();
        agregarMotor(r);

        // El motor devuelve el formulario actualizado, o null cuando se cerró.
        if (Object.prototype.hasOwnProperty.call(r, 'alta_pendiente')) {
            altaPendiente = r.alta_pendiente;
        }

        // La respuesta define el contexto de la próxima pregunta.
        if (r.filtros) {
            contexto.ciclo   = r.filtros.ciclo   || null;
            contexto.lote    = r.filtros.lote    || null;
            contexto.cultivo = r.filtros.cultivo || null;
            contexto.metrica = r.filtros.metrica || null;
            contexto.cicloNombre   = r.filtros.ciclo || null;
            contexto.cultivoNombre = r.filtros.cultivo || null;
            // El nombre del lote se toma de la frase, que es donde el motor ya lo resolvió.
            const m = (r.respuesta || '').match(/,\s*en\s+(?:el\s+)?([^,]+?),/);
            contexto.loteNombre = contexto.lote && m ? m[1] : null;
            guardarContexto();
        }

        pintarSugeridas(r.sugerencias);
        enVuelo = false;
        enviar.disabled = false;
        input.focus();
    }

    function abrir() {
        panel.hidden = false; backdrop.hidden = false;
        panel.classList.add('abierto');
        backdrop.classList.add('abierto');
        fab.setAttribute('aria-expanded', 'true');
        if (!hilo.children.length) {
            /* El saludo lo arma el servidor y no el cliente: así conoce el nombre
               del productor y puede meter el margen de la campaña adentro. Un
               "hola" con un número sirve; uno vacío es sólo cortesía. */
            preguntarSilencioso('hola');
        }
        pintarContexto();
        input.focus();
    }

    function cerrar() {
        panel.classList.remove('abierto');
        backdrop.classList.remove('abierto');
        panel.hidden = true; backdrop.hidden = true;
        fab.setAttribute('aria-expanded', 'false');
        fab.focus();   // el foco vuelve de donde salió
    }

    fab.addEventListener('click', () => {
        fab.getAttribute('aria-expanded') === 'true' ? cerrar() : abrir();
    });
    document.getElementById('mc-cerrar').addEventListener('click', cerrar);
    backdrop.addEventListener('click', cerrar);

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && fab.getAttribute('aria-expanded') === 'true') cerrar();
    });

    document.getElementById('mc-contexto-limpiar').addEventListener('click', () => {
        contexto = {};
        guardarContexto();
        input.focus();
    });

    form.addEventListener('submit', e => {
        e.preventDefault();
        preguntar(input.value);
    });

    /* ── Dictado ─────────────────────────────────────────────────────────────
       Para el que está arriba de la camioneta con las manos sucias, que es el
       escenario donde escribir en el teléfono directamente no pasa.

       El botón sólo aparece si el navegador lo soporta: mostrarlo y que no ande
       es peor que no ofrecerlo. No se envía solo — el texto queda en el campo
       para poder corregirlo, porque el reconocimiento se equivoca con los
       nombres de lote y sería el peor lugar para no revisar. */
    const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;
    const btnVoz = document.getElementById('mc-voz');

    if (Reconocimiento && btnVoz) {
        btnVoz.hidden = false;
        const rec = new Reconocimiento();
        rec.lang = 'es-AR';
        rec.interimResults = false;
        rec.maxAlternatives = 1;
        let escuchando = false;

        btnVoz.addEventListener('click', () => {
            if (escuchando) { rec.stop(); return; }
            try { rec.start(); } catch (e) { return; }
        });

        rec.addEventListener('start', () => {
            escuchando = true;
            btnVoz.style.background = 'var(--accent)';
            btnVoz.style.color = 'var(--on-accent)';
            btnVoz.title = 'Escuchando… tocá para parar';
            input.placeholder = 'Escuchando…';
        });

        const fin = () => {
            escuchando = false;
            btnVoz.style.background = 'var(--n-0)';
            btnVoz.style.color = 'var(--accent)';
            btnVoz.title = 'Dictar';
            input.placeholder = 'Preguntá, o dictá un gasto';
        };
        rec.addEventListener('end', fin);
        rec.addEventListener('error', fin);

        rec.addEventListener('result', ev => {
            const txt = ev.results[0][0].transcript;
            input.value = txt;
            input.focus();
        });
    }

    pintarContexto();
})();
</script>
