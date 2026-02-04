const CACHE_NAME = 'gestion-vial-offline-v3'; // subí versión para forzar refresh
const OFFLINE_URL = '/gestion_vial_ui/offline.html';

const URLs_TO_CACHE = [
  '/gestion_vial_ui/',
  '/gestion_vial_ui/login.php',
  '/gestion_vial_ui/index.php',
  '/gestion_vial_ui/offline.html',
  '/gestion_vial_ui/js/offline_v2.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(URLs_TO_CACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // 1) NUNCA interceptar requests que no sean GET (POST/PUT/etc)
  if (req.method !== 'GET') {
    return; // deja que el browser vaya a la red normal
  }

  // 2) NUNCA cachear /api/ (evita que tu sync quede “from service worker”)
  if (url.pathname.startsWith('/gestion_vial_ui/api/')) {
    event.respondWith(fetch(req));
    return;
  }

  // 3) Navegación: offline fallback
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  // 4) Para tu JS offline_v2.js: network-first (para que agarre cambios)
  if (url.pathname === '/gestion_vial_ui/js/offline_v2.js') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req))
    );
    return;
  }

  // 5) Para el resto: cache-first simple
  event.respondWith(
    caches.match(req).then((cached) => {
      if (cached) return cached;
      return fetch(req).then((res) => {
        // opcional: cachear sólo si es mismo origen
        if (url.origin === self.location.origin) {
          const copy = res.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, copy));
        }
        return res;
      });
    })
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then(names => Promise.all(
        names.map(n => (n !== CACHE_NAME ? caches.delete(n) : null))
      ))
      .then(() => self.clients.claim())
  );
});
