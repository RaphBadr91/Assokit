/**
 * AssoKit Service Worker
 * Stratégie : network-first pour HTML/API, cache-first pour assets statiques.
 * Cache offline : page de fallback quand pas de réseau.
 */
const CACHE_VERSION = 'assokit-v1';
const STATIC_CACHE = 'assokit-static-' + CACHE_VERSION;
const RUNTIME_CACHE = 'assokit-runtime-' + CACHE_VERSION;

const STATIC_ASSETS = [
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/offline.html'
];

// Install : pre-cache statique
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(STATIC_ASSETS).catch(() => {}))
      .then(() => self.skipWaiting())
  );
});

// Activate : nettoyage anciens caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys
        .filter(k => k.startsWith('assokit-') && k !== STATIC_CACHE && k !== RUNTIME_CACHE)
        .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// Fetch : stratégies différenciées
self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Ignorer non-GET et cross-origin
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Ignorer les actions, API qui modifient l'état
  if (url.pathname.startsWith('/action-') || url.pathname.startsWith('/api-')) {
    // Network-only avec fallback erreur silencieuse
    event.respondWith(
      fetch(req).catch(() => new Response(JSON.stringify({ ok: false, error: 'offline' }), {
        headers: { 'Content-Type': 'application/json' }, status: 503
      }))
    );
    return;
  }

  // Assets statiques (icons, fonts, css, js) : cache-first
  if (/\.(png|jpg|jpeg|svg|webp|woff2?|ttf|css|js|ico)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then((cached) => cached ||
        fetch(req).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(RUNTIME_CACHE).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(() => cached)
      )
    );
    return;
  }

  // Pages HTML : network-first avec fallback offline
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(RUNTIME_CACHE).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() =>
        caches.match(req).then((cached) =>
          cached || caches.match('/offline.html').then(off => off || new Response('Hors ligne', { status: 503 }))
        )
      )
  );
});
