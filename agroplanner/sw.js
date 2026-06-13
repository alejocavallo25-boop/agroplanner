const CACHE_NAME = 'agroplanner-v6';
const urlsToCache = [
  './assets/css/style.css',
  // Solo assets estáticos reales
];

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});

// Función de Fetch con Timeout (evita que se quede cargando 30segs cuando no hay señal clara)
const fetchWithTimeout = (request, timeout = 2500) => {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Timeout de Red')), timeout);
    fetch(request).then(response => {
      clearTimeout(timer);
      resolve(response);
    }).catch(err => {
      clearTimeout(timer);
      reject(err);
    });
  });
};

self.addEventListener('fetch', event => {
  // Ignorar peticiones que no sean GET (POST lo maneja offline.js)
  if (event.request.method !== 'GET') return;

  // Si el navegador sabe con certeza que estamos offline, servir desde caché instantáneamente
  if (!navigator.onLine) {
    event.respondWith(
      caches.match(event.request).then(response => {
        // Retorna caché, o genera un response vacío/error si no existe
        return response || new Response('Offline', { status: 503, statusText: 'Offline' });
      })
    );
    return;
  }

  const url = new URL(event.request.url);

  // Estrategia Network First (con Timeout)
  event.respondWith(
    fetchWithTimeout(event.request, 2500)
      .then(response => {
        // Cachear respuestas válidas (ahora permitimos CORS 'cors' para cachear CDNs como FontAwesome)
        if (!response || response.status !== 200 || (response.type !== 'basic' && response.type !== 'cors')) {
          return response;
        }
        
        // Evitar cachear archivos dinámicos PHP para no romper tokens CSRF ni sesiones
        if (url.pathname.endsWith('.php') || url.pathname === '/' || url.pathname.includes('/api/')) {
            return response;
        }

        let responseToCache = response.clone();
        caches.open(CACHE_NAME).then(cache => {
          cache.put(event.request, responseToCache);
        });
        
        return response;
      })
      .catch(() => {
        // Si no hay red o superó el timeout de 2.5s, buscar en caché rápidamente
        return caches.match(event.request);
      })
  );
});
