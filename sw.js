/**
 * sw.js — el que hace que la aplicación abra sin señal.
 *
 * ANTES NO ABRÍA. La versión anterior guardaba un solo archivo (la hoja de
 * estilos) y evitaba a propósito guardar las páginas .php para no romper la
 * sesión ni el token. La intención era correcta, pero el resultado era que sin
 * conexión no había NADA que servir: index.php devolvía un 503 con la palabra
 * "Offline" y el resto ni siquiera cargaba. Probado en el navegador antes de
 * tocar esto.
 *
 * LA SALIDA NO ES GUARDAR LAS PÁGINAS
 * Una página .php lleva adentro los datos del productor y un token atado a su
 * sesión. Guardarla significa mostrar números viejos como si fueran de hoy, y
 * dejar datos de una persona en un archivo que sobrevive al cierre de sesión.
 *
 * Así que sin señal no se sirve la aplicación entera: se sirve LA PANTALLA DE
 * CAMPO, que es la única que tiene sentido ahí y que no trae ningún dato adentro
 * —los lotes y los insumos viven en la base del teléfono, no acá—. Es la
 * diferencia entre "no abre" y "abre en lo único que se puede hacer sin señal".
 *
 * QUÉ NO SE TOCA
 *   · Nada que no sea GET. Un POST jamás sale del caché.
 *   · Nada de /api/: son datos que cambian, y contestar uno viejo es peor que no
 *     contestar. campo_datos.php ya viene con no-store por las dudas.
 *   · Las páginas .php no se guardan nunca, por lo de arriba.
 */

const CACHE = 'agroplanner-campo-v1';

/* Lo mínimo para que el modo campo funcione solo. Va todo junto: si falta uno,
   la pantalla no sirve, así que es preferible que la instalación falle y se
   reintente antes que quedar a medias. */
const ESENCIALES = [
    './campo.html',
    './assets/js/campo.js',
    './manifest.json',
];

self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE).then(cache => cache.addAll(ESENCIALES))
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(nombres => Promise.all(
                nombres.filter(n => n !== CACHE).map(n => caches.delete(n))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;   // fuentes, mapas, CDNs: no es asunto nuestro

    /* Una navegación: alguien abrió la aplicación o tocó un link. Se intenta la
       red, y si no hay, se abre el modo campo. Sin esto, sin señal, el productor
       ve la pantalla de error del navegador. */
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match('./campo.html'))
        );
        return;
    }

    // Los datos nunca salen del caché: viejo y con cara de nuevo es lo peor.
    if (url.pathname.includes('/api/') || url.pathname.endsWith('.php')) return;

    /* El resto —la hoja de estilos, el javascript, los íconos— desde la red
       cuando hay, y desde el caché cuando no. Se guarda al pasar para que la
       próxima vez sin señal esté. */
    event.respondWith(
        fetch(req).then(resp => {
            if (resp && resp.status === 200 && resp.type === 'basic') {
                const copia = resp.clone();
                caches.open(CACHE).then(c => c.put(req, copia));
            }
            return resp;
        }).catch(() => caches.match(req))
    );
});
