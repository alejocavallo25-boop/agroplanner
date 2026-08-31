/**
 * sw-estaticos.js — guarda los archivos fijos, y nada más.
 *
 * QUÉ HACE Y QUÉ NO
 * Guarda la hoja de estilos, el javascript y las imágenes para que volver a
 * entrar sea más rápido. NO hace que la aplicación ande sin conexión, y no lo
 * finge: cada pantalla se arma en el servidor consultando la base, así que sin
 * conexión no hay nada que mostrar. Sin señal se ve la pantalla de error del
 * navegador, que al menos explica qué pasó y ofrece reintentar.
 *
 * POR QUÉ EXISTE ENTONCES
 * Por el botón "Anclar App" del menú. Chrome sólo ofrece instalar la aplicación
 * si hay un service worker con manejador de fetch; sin esto, ese botón deja de
 * aparecer en Android.
 *
 * LO QUE NO SE GUARDA, Y POR QUÉ
 * Ni las páginas .php ni nada de /api/. Las dos cosas traen adentro los números
 * del productor y un token atado a su sesión. Guardarlas sería mostrar plata
 * vieja con cara de actual, y dejar datos de una persona en un archivo que
 * sobrevive al cierre de sesión. Un número desactualizado que parece de hoy es
 * peor que no tener número.
 *
 * Y no se interceptan las navegaciones. Una versión anterior contestaba a las
 * navegaciones con un 503 que decía "Offline" cuando el navegador creía no tener
 * red: eso tapaba la pantalla del navegador con una peor, sin explicación y sin
 * forma de reintentar.
 */

const CACHE = 'agroplanner-estaticos-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
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

    // Las navegaciones van derecho a la red. Sin conexión fallan, que es la verdad.
    if (req.mode === 'navigate') return;

    // Datos y páginas: nunca del caché.
    if (url.pathname.includes('/api/') || url.pathname.endsWith('.php')) return;

    /* Los archivos fijos: de la red cuando hay, del caché cuando no. Se guardan al
       pasar, así la próxima visita no los baja de nuevo. */
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
