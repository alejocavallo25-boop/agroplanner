/**
 * assets/js/campo.js
 *
 * El modo campo: anotar un gasto parado en el lote, con o sin señal.
 *
 * LA REGLA DE LA QUE DEPENDE TODO
 * Una carga sale de la cola SÓLO cuando el servidor contesta que la guardó, con
 * un JSON que dice ok. Ni un 200 pelado, ni un "redirected", ni un "no dio
 * error": cualquiera de esas tres cosas puede ser la pantalla de login, un aviso
 * de sesión vencida o una página de error del hosting, y borrar la cola con eso
 * es perder el gasto sin que nadie se entere. Ante la duda, la carga se queda.
 *
 * POR QUÉ NO ALCANZA navigator.onLine
 * Dice si el teléfono tiene una interfaz de red conectada, no si hay internet.
 * En el campo, con una rayita que no cursa datos, vale true. Sirve para lo
 * negativo —si dice que no hay, no hay— pero para lo positivo hay que probar.
 *
 * EL NÚMERO DE CADA CARGA
 * Se genera acá, antes de mandar, y viaja en cada reintento. Si la carga llegó y
 * la respuesta se perdió, el servidor la reconoce por ese número y contesta "ya
 * estaba" en vez de guardarla de nuevo. Sin eso, la red mala duplica gastos.
 */
(function () {
    'use strict';

    var DB = 'AgroPlannerCampo', VERSION = 1;
    var DATOS = 'datos', COLA = 'cola';

    var $ = function (id) { return document.getElementById(id); };
    var datos = null;          // lotes, insumos, campaña y token
    var elegidos = [];         // ids de lote tocados
    var grupo = null;          // etapa
    var tipo = 'labor';
    var mandando = false;
    /* Lo que dio la última prueba de verdad contra el servidor. Se guarda porque
       navigator.onLine no alcanza para decidir qué mostrar: con una rayita que no
       cursa datos dice que hay señal, y el cartel terminaría diciendo "falta
       mandar, tocá para intentar" a alguien que no tiene con qué. */
    var huboRed = null;

    /* ── La base del teléfono ─────────────────────────────────────────────── */

    function abrir() {
        return new Promise(function (ok, mal) {
            var r = indexedDB.open(DB, VERSION);
            r.onupgradeneeded = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains(DATOS)) db.createObjectStore(DATOS);
                if (!db.objectStoreNames.contains(COLA)) db.createObjectStore(COLA, { keyPath: 'id' });
            };
            r.onsuccess = function () { ok(r.result); };
            r.onerror = function () { mal(r.error); };
        });
    }

    function conStore(nombre, modo, fn) {
        return abrir().then(function (db) {
            return new Promise(function (ok, mal) {
                var tx = db.transaction(nombre, modo);
                var res = fn(tx.objectStore(nombre));
                /* Se pregunta si es una petición, NO si trae resultado. Con lo
                   segundo, un get() sobre una base vacía —que devuelve undefined,
                   que es la respuesta correcta— terminaba entregando el objeto de
                   la petición como si fueran los datos, y el resto lo tomaba por
                   bueno hasta reventar al leerle una propiedad. */
                tx.oncomplete = function () { ok(res instanceof IDBRequest ? res.result : res); };
                tx.onerror = function () { mal(tx.error); };
            });
        });
    }

    var guardarDatos = function (d) { return conStore(DATOS, 'readwrite', function (s) { s.put(d, 'v1'); }); };
    var leerDatos    = function ()  { return conStore(DATOS, 'readonly',  function (s) { return s.get('v1'); }); };
    var leerCola     = function ()  { return conStore(COLA,  'readonly',  function (s) { return s.getAll(); }); };
    var ponerEnCola  = function (c) { return conStore(COLA,  'readwrite', function (s) { s.put(c); }); };
    var sacarDeCola  = function (id){ return conStore(COLA,  'readwrite', function (s) { s.delete(id); }); };

    /**
     * El día de hoy según el reloj del teléfono, no según Greenwich.
     *
     * toISOString() devuelve UTC, y acá son tres horas menos: después de las
     * nueve de la noche ya es el día siguiente en UTC. O sea que el formulario
     * proponía MAÑANA, y el servidor lo rechazaba con "la fecha es posterior a
     * hoy" — todas las noches, que es justo cuando uno carga lo que hizo en el
     * día. Se arma con las partes locales y listo.
     */
    function hoyLocal() {
        var d = new Date();
        var m = String(d.getMonth() + 1);
        var dia = String(d.getDate());
        return d.getFullYear() + '-' + (m.length < 2 ? '0' + m : m)
                               + '-' + (dia.length < 2 ? '0' + dia : dia);
    }

    function numeroPropio() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        // Respaldo para navegadores viejos: alcanza con que no se repita.
        return 'c-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2, 10);
    }

    /* ── ¿Hay internet de verdad? ─────────────────────────────────────────── */

    function hayInternet() {
        if (navigator.onLine === false) { huboRed = false; return Promise.resolve(false); }
        // Un pedido chico y con plazo corto. En el campo, esperar treinta segundos
        // a que algo falle es peor que darlo por caído en tres.
        var corte = new AbortController();
        var reloj = setTimeout(function () { corte.abort(); }, 3500);
        return fetch('api/campo_datos.php', {
            credentials: 'same-origin', cache: 'no-store', signal: corte.signal
        }).then(function (r) {
            clearTimeout(reloj);
            huboRed = true;
            return r.ok ? r.json().then(function (j) {
                if (j && j.ok) { datos = j; guardarDatos(j); pintarFormulario(); }
                return true;
            }).catch(function () { return true; }) : true;
        }).catch(function () { clearTimeout(reloj); huboRed = false; return false; });
    }

    /* ── Pintar ───────────────────────────────────────────────────────────── */

    function estado(clase, texto, detalle) {
        $('estado').className = 'estado ' + clase;
        $('estado-txt').innerHTML = texto + (detalle ? '<small>' + detalle + '</small>' : '');
    }

    function avisar(txt) { $('aviso').textContent = txt || ''; }

    function pintarFormulario() {
        // Sin lotes no hay nada que pintar, y si lo guardado quedó a medias es
        // mejor no dibujar que dibujar mal.
        if (!datos || !Array.isArray(datos.lotes)) return;

        var cajaL = $('lotes');
        cajaL.innerHTML = '';
        if (!datos.lotes.length) {
            cajaL.innerHTML = '<p class="vacio">Todavía no tenés lotes cargados.</p>';
        }
        datos.lotes.forEach(function (l) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'lote';
            b.setAttribute('aria-pressed', elegidos.indexOf(l.id) >= 0 ? 'true' : 'false');
            b.innerHTML = escapar(l.nom) + (l.sup > 0
                ? '<small>' + l.sup.toLocaleString('es-AR') + ' ha</small>' : '');
            b.addEventListener('click', function () {
                var i = elegidos.indexOf(l.id);
                if (i >= 0) elegidos.splice(i, 1); else elegidos.push(l.id);
                b.setAttribute('aria-pressed', i >= 0 ? 'false' : 'true');
            });
            cajaL.appendChild(b);
        });

        var cajaG = $('grupos');
        if (!cajaG.children.length) {
            datos.grupos.forEach(function (g) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'lote';
                b.setAttribute('aria-pressed', 'false');
                b.textContent = g.t;
                b.addEventListener('click', function () {
                    grupo = g.v;
                    Array.prototype.forEach.call(cajaG.children, function (o) {
                        o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
                    });
                });
                cajaG.appendChild(b);
            });
        }

        var sel = $('insumo');
        if (!sel.children.length) {
            var libre = new Option('Escribirlo a mano (no descuenta stock)', 'libre');
            datos.insumos.forEach(function (i) {
                sel.appendChild(new Option(
                    i.nom + (i.stock ? ' — quedan ' + i.stock.toLocaleString('es-AR') + ' ' + i.un : ''),
                    String(i.id)));
            });
            sel.appendChild(libre);
            sel.addEventListener('change', alCambiarInsumo);
            alCambiarInsumo();
        }

        if (datos.solo_lectura) {
            avisar('Esta es una cuenta de demostración: podés probar la pantalla, pero lo que cargues no se va a guardar.');
            $('guardar').disabled = true;
        }
    }

    function alCambiarInsumo() {
        var v = $('insumo').value;
        $('caja-insumo-libre').hidden = v !== 'libre';
        var ins = (datos && datos.insumos || []).filter(function (i) { return String(i.id) === v; })[0];
        $('unidad').textContent = ins ? '(' + ins.un + ')' : '';
    }

    function escapar(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function plata(n, moneda) {
        return (moneda === 'USD' ? 'US$' : '$') +
               Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function pintarCola() {
        return leerCola().then(function (cola) {
            var caja = $('cola');
            $('cuenta').textContent = cola.length ? '(' + cola.length + ')' : '';
            if (!cola.length) {
                caja.innerHTML = '<p class="vacio">No hay nada esperando. Todo lo que cargaste ya está en el servidor.</p>';
                return cola;
            }
            caja.innerHTML = '';
            cola.sort(function (a, b) { return a.cuando - b.cuando; }).forEach(function (c) {
                var d = document.createElement('div');
                d.className = 'pendiente';
                var nombres = c.lotes.map(function (id) {
                    var l = (datos && datos.lotes || []).filter(function (x) { return x.id === id; })[0];
                    return l ? l.nom : 'lote ' + id;
                }).join(', ');
                d.innerHTML =
                    '<div class="que"><strong>' + escapar(c.etiqueta) + '</strong>' +
                    '<span>' + escapar(nombres) + ' · ' + escapar(c.fecha) + '</span>' +
                    (c.error ? '<span class="error-envio">' + escapar(c.error) + '</span>' : '') +
                    '</div>' +
                    '<div class="plata">' + plata(c.monto, c.moneda) + '</div>';
                var x = document.createElement('button');
                x.type = 'button'; x.className = 'borrar'; x.innerHTML = '&times;';
                x.title = 'Descartar esta carga';
                x.setAttribute('aria-label', 'Descartar ' + c.etiqueta);
                x.addEventListener('click', function () {
                    if (confirm('¿Descartar esta carga? No se va a guardar en ningún lado.')) {
                        sacarDeCola(c.id).then(pintarCola);
                    }
                });
                d.appendChild(x);
                caja.appendChild(d);
            });
            return cola;
        });
    }

    /* ── Guardar ──────────────────────────────────────────────────────────── */

    function alGuardar(e) {
        e.preventDefault();
        avisar('');

        var monto = parseFloat($('monto').value);
        var fecha = $('fecha').value;
        var faltan = [];
        if (!elegidos.length) faltan.push('el lote');
        if (!grupo) faltan.push('en qué gastaste');
        if (!(monto > 0)) faltan.push('cuánto costó');
        if (!fecha) faltan.push('el día');

        var insumoId = 0, insumoNom = '', cantidad = 0;
        if (tipo === 'insumo') {
            var v = $('insumo').value;
            if (v === 'libre') {
                insumoNom = $('insumo-libre').value.trim();
                if (!insumoNom) faltan.push('el nombre del insumo');
            } else {
                insumoId = parseInt(v, 10) || 0;
                var ins = (datos.insumos || []).filter(function (i) { return i.id === insumoId; })[0];
                insumoNom = ins ? ins.nom : '';
                if (!insumoId) faltan.push('qué insumo');
            }
            cantidad = parseFloat($('cantidad').value);
            if (!(cantidad > 0)) faltan.push('cuánto usaste');
        }

        if (faltan.length) {
            avisar('Me falta ' + faltan.join(', ') + '.');
            return;
        }
        /* La fecha se valida acá porque el servidor la rechaza y, sin señal, ese
           rechazo llegaría recién horas después: la carga quedaría trabada en la
           cola sin que nadie sepa por qué. */
        var hoy = new Date(); hoy.setHours(23, 59, 59, 999);
        if (new Date(fecha + 'T12:00:00') > hoy) {
            avisar('Esa fecha todavía no llegó. Un gasto es plata que ya salió.');
            return;
        }

        var etiquetas = {};
        (datos.grupos || []).forEach(function (g) { etiquetas[g.v] = g.t; });

        var carga = {
            id: numeroPropio(),          // el mismo número que va al servidor
            cuando: Date.now(),
            lotes: elegidos.slice(),
            grupo: grupo,
            tipo: tipo,
            insumo_id: insumoId,
            insumo_nombre: insumoNom,
            cantidad: cantidad,
            monto: monto,
            moneda: $('moneda').value,
            fecha: fecha,
            campania: datos.campania || '',
            etiqueta: tipo === 'insumo' ? insumoNom : (etiquetas[grupo] || grupo),
            intentos: 0,
            error: ''
        };

        ponerEnCola(carga).then(function () {
            // Se limpia lo que cambia entre cargas y se deja lo que se repite: el
            // día y el lote suelen ser los mismos varias veces seguidas.
            $('monto').value = ''; $('cantidad').value = ''; $('insumo-libre').value = '';
            return pintarCola();
        }).then(function () {
            estado('mandando', 'Anotado en el teléfono.', 'Lo mando apenas haya señal.');
            return sincronizar();
        }).catch(function () {
            avisar('No pude guardarlo en el teléfono. Fijate que le quede espacio.');
        });
    }

    /* ── Mandar lo que está esperando ─────────────────────────────────────── */

    function sincronizar() {
        if (mandando) return Promise.resolve();
        mandando = true;

        return leerCola().then(function (cola) {
            if (!cola.length) { mandando = false; return revisarEstado(); }
            return hayInternet().then(function (hay) {
                if (!hay) { mandando = false; return revisarEstado(); }
                estado('mandando', 'Mandando ' + cola.length + (cola.length === 1 ? ' carga…' : ' cargas…'));
                return cola.reduce(function (previa, c) {
                    return previa.then(function () { return mandarUna(c); });
                }, Promise.resolve());
            });
        }).then(function () {
            mandando = false;
            return pintarCola().then(revisarEstado);
        }).catch(function () {
            mandando = false;
            return revisarEstado();
        });
    }

    function mandarUna(c) {
        var cuerpo = new URLSearchParams();
        cuerpo.set('csrf_token', datos && datos.csrf ? datos.csrf : '');
        cuerpo.set('idempotencia', c.id);
        cuerpo.set('lotes', c.lotes.join(','));
        cuerpo.set('grupo_gasto', c.grupo);
        cuerpo.set('tipo_componente', c.tipo);
        cuerpo.set('costo_total', String(c.monto));
        cuerpo.set('moneda', c.moneda);
        cuerpo.set('fecha', c.fecha);
        cuerpo.set('campania', c.campania);
        if (c.tipo === 'insumo') {
            cuerpo.set('insumo_nombre', c.insumo_nombre);
            cuerpo.set('insumo_cantidad', String(c.cantidad));
            if (c.insumo_id) cuerpo.set('insumo_id', String(c.insumo_id));
        }

        return fetch('api/registrar.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                       'X-Requested-With': 'XMLHttpRequest' },
            body: cuerpo.toString()
        }).then(function (r) {
            return r.json().then(function (j) { return { http: r.status, j: j }; })
                    .catch(function () { return { http: r.status, j: null }; });
        }).then(function (res) {
            /* El único caso en que la carga se borra: el servidor contestó JSON y
               dijo que la guardó. 'duplicado' también cuenta — quiere decir que ya
               estaba, que es exactamente lo que este número sirve para saber. */
            if (res.j && res.j.ok === true) return sacarDeCola(c.id);

            if (res.http === 403) {
                /* Token vencido. Se pide uno nuevo y se reintenta una vez. Si
                   tampoco, es que la sesión se cerró: la carga se queda esperando
                   a que el productor vuelva a entrar. */
                return refrescarToken().then(function (pudo) {
                    if (!pudo) {
                        c.error = 'Se cerró la sesión. Entrá de nuevo y se manda solo.';
                        return ponerEnCola(c);
                    }
                    cuerpo.set('csrf_token', datos.csrf);
                    return fetch('api/registrar.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                                   'X-Requested-With': 'XMLHttpRequest' },
                        body: cuerpo.toString()
                    }).then(function (r2) { return r2.json().catch(function () { return null; }); })
                      .then(function (j2) {
                        if (j2 && j2.ok === true) return sacarDeCola(c.id);
                        c.error = (j2 && j2.msg) || 'No se pudo guardar.';
                        return ponerEnCola(c);
                    });
                });
            }

            if (res.http === 422 && res.j && res.j.msg) {
                /* Datos que el servidor nunca va a aceptar. Reintentarla para
                   siempre no la va a arreglar: se muestra el motivo para poder
                   corregirla o descartarla a mano. */
                c.error = res.j.msg;
                return ponerEnCola(c);
            }

            // Cualquier otra cosa (500, la pantalla de login, el hosting caído):
            // se anota el intento y se queda esperando. Nunca se borra.
            c.intentos = (c.intentos || 0) + 1;
            c.error = res.http === 403 ? '' : 'No entró todavía. Lo sigo intentando.';
            return ponerEnCola(c);

        }).catch(function () {
            // Se cortó a mitad de camino. Puede haber llegado o no: por eso existe
            // el número propio. Se reintenta sin miedo a duplicar.
            c.intentos = (c.intentos || 0) + 1;
            return ponerEnCola(c);
        });
    }

    function refrescarToken() {
        return fetch('api/campo_datos.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (j && j.ok && j.csrf) { datos = j; guardarDatos(j); return true; }
                return false;
            }).catch(function () { return false; });
    }

    function revisarEstado() {
        return leerCola().then(function (cola) {
            var sinRed = navigator.onLine === false || huboRed === false;
            if (sinRed) {
                estado('sin-senal', 'Sin señal.', cola.length
                    ? cola.length + (cola.length === 1
                        ? ' carga anotada. Se manda sola cuando vuelva.'
                        : ' cargas anotadas. Se mandan solas cuando vuelva.')
                    : 'Podés anotar igual: se guarda en el teléfono.');
            } else if (cola.length) {
                estado('sin-senal', 'Falta mandar ' + cola.length + '.', 'Tocá acá para intentar ahora.');
            } else {
                estado('con-senal', 'Al día.', 'Todo lo que cargaste está en el servidor.');
            }
        });
    }

    /* ── Arranque ─────────────────────────────────────────────────────────── */

    function arrancar() {
        $('fecha').value = hoyLocal();
        // Que el propio campo no deje elegir un día que todavía no llegó.
        $('fecha').max = hoyLocal();

        $('es-labor').addEventListener('click', function () { cambiarTipo('labor'); });
        $('es-insumo').addEventListener('click', function () { cambiarTipo('insumo'); });
        $('form').addEventListener('submit', alGuardar);
        $('estado').addEventListener('click', sincronizar);

        window.addEventListener('online', sincronizar);
        window.addEventListener('offline', revisarEstado);

        // Primero lo guardado, que anda sin señal. Después, si hay, lo fresco.
        leerDatos().then(function (d) {
            if (d) { datos = d; pintarFormulario(); }
            else {
                estado('sin-senal', 'Todavía no tengo tus lotes.',
                       'Abrí esta pantalla una vez con señal y quedan guardados en el teléfono.');
                $('guardar').disabled = true;
            }
            return pintarCola();
        }).then(function () {
            return hayInternet();
        }).then(function (hay) {
            if (hay && datos) $('guardar').disabled = !!datos.solo_lectura;
            return sincronizar();
        });
    }

    function cambiarTipo(t) {
        tipo = t;
        $('es-labor').setAttribute('aria-pressed', t === 'labor' ? 'true' : 'false');
        $('es-insumo').setAttribute('aria-pressed', t === 'insumo' ? 'true' : 'false');
        $('caja-insumo').hidden = t !== 'insumo';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
