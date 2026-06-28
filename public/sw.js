const CACHE = 'fm-v1';

// On install: cache the app shell pages so they open offline
self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(['/', '/songs', '/queue']))
    );
    self.skipWaiting();
});

self.addEventListener('activate', () => self.clients.claim());

self.addEventListener('fetch', (e) => {
    const { request } = e;

    // SSE and API calls always go to the network
    if (request.url.includes('/api/')) return;

    // Navigation requests: network first, fall back to cached shell
    if (request.mode === 'navigate') {
        e.respondWith(
            fetch(request).catch(() => caches.match('/'))
        );
        return;
    }

    // Static assets: network first, cache as fallback
    e.respondWith(
        fetch(request)
            .then(res => {
                if (res.ok) {
                    caches.open(CACHE).then(c => c.put(request, res.clone()));
                }
                return res;
            })
            .catch(() => caches.match(request))
    );
});
