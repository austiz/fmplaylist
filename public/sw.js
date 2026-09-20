const CACHE = 'fm-v2';
const SHELL = ['/', '/songs', '/queue'];

// On install: cache the app shell pages so they open offline.
self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE).then((c) =>
            // One page at a time, not addAll: addAll rejects as a unit, so a
            // single route that 503s -- a deploy running, the app in
            // maintenance mode -- would abort the whole install and leave the
            // site with no worker at all.
            Promise.all(SHELL.map((url) => c.add(url).catch(() => undefined))),
        ),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((k) => k !== CACHE)
                        .map((k) => caches.delete(k)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (e) => {
    const { request } = e;

    // SSE and API calls always go to the network.
    if (request.url.includes('/api/')) return;

    // Anything that is not a GET -- requesting a song, reordering the queue,
    // posting to chat -- is left to the network untouched. Cache.put throws on
    // a POST, and the rejection surfaces as an unhandled error in the console.
    if (request.method !== 'GET') return;

    // Navigation requests: network first, fall back to cached shell.
    if (request.mode === 'navigate') {
        e.respondWith(
            fetch(request).catch(async () => {
                // `??`, not `||`: caches.match resolves to undefined on a miss,
                // and responding with undefined is a network error, not a
                // fallback.
                const hit = await caches.match(request);

                return hit ?? (await caches.match('/'));
            }),
        );
        return;
    }

    // Static assets: network first, cache as fallback.
    e.respondWith(
        fetch(request)
            .then((res) => {
                if (res.ok) {
                    // Cloned here and not inside the then() below: the clone
                    // has to be taken before the body is handed back to the
                    // page, or there is nothing left to copy and it throws
                    // "Response body is already used".
                    const copy = res.clone();

                    // waitUntil so the worker is not terminated before the
                    // write lands, and the catch is load-bearing: the page
                    // asks for most assets twice -- once as a modulepreload,
                    // once as the import itself -- so two puts of one key are
                    // in flight together and Chrome rejects the second with
                    // "Entry already exists". The entry is cached either way;
                    // unhandled, the rejection was 40-odd console errors a
                    // page load.
                    e.waitUntil(
                        caches
                            .open(CACHE)
                            .then((c) => c.put(request, copy))
                            .catch(() => undefined),
                    );
                }

                return res;
            })
            .catch(async () => {
                // Same trap as the navigate branch above: a miss resolves to
                // undefined, and respondWith(undefined) is a TypeError rather
                // than the network error the caller should see.
                const hit = await caches.match(request);

                return hit ?? Response.error();
            }),
    );
});
