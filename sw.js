/**
 * MotorLink Service Worker
 * Strategy:
 *   - Cache-first for static shell assets.
 *   - Network-first for GET API reads, with a stale cache fallback so
 *     users can still browse the last loaded listings when offline
 *     (critical in Malawi where signal/load-shedding drops are frequent).
 */

const STATIC_CACHE  = 'motorlink-static-v3';
const API_CACHE     = 'motorlink-api-v3';
const API_CACHE_MAX = 40; // keep the last N GET API responses

// GET actions that are safe to cache offline (read-only data)
const CACHEABLE_API_ACTIONS = new Set([
    'listings',
    'listing',
    'makes',
    'models',
    'locations',
    'stats',
    'dealers',
    'garages',
    'car_hire_companies'
]);

// ── Install: pre-cache the app shell ────────────────────────────────────────
// Paths are resolved relative to the SW scope so this works on both
// localhost development and the /motorlink/ production sub-path.
self.addEventListener('install', event => {
    event.waitUntil(
        (async () => {
            const base = self.registration.scope; // e.g. https://…/motorlink/
            const SHELL_ASSETS = [
                base,
                base + 'index.html',
                base + 'login.html',
                base + 'register.html',
                base + 'manifest.json',
                base + 'css/style.css',
                base + 'css/common.css',
                base + 'css/mobile-enhancements.css',
                base + 'js/mobile-menu.js',
                base + 'assets/images/favicon.svg'
            ];

            const cache = await caches.open(STATIC_CACHE);
            // addAll in one shot; if any asset 404s the install fails — use
            // individual puts so a missing asset never blocks the SW entirely.
            await Promise.allSettled(
                SHELL_ASSETS.map(url =>
                    fetch(url).then(res => {
                        if (res && res.status === 200) cache.put(url, res);
                    }).catch(() => { /* ignore fetch errors during pre-cache */ })
                )
            );
            await self.skipWaiting();
        })()
    );
});

// ── Activate: prune old cache versions ──────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(key => key !== STATIC_CACHE && key !== API_CACHE)
                    .map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

// Keep the API cache bounded so we don't balloon storage on low-end devices.
async function trimApiCache() {
    const cache = await caches.open(API_CACHE);
    const keys = await cache.keys();
    if (keys.length <= API_CACHE_MAX) return;
    // Evict oldest (FIFO — match() order is insertion order)
    const excess = keys.length - API_CACHE_MAX;
    for (let i = 0; i < excess; i++) {
        await cache.delete(keys[i]);
    }
}

// ── Fetch: serve from cache, fall back to network ───────────────────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // 1. Skip non-GET requests and cross-origin requests
    if (request.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;

    // 2. Never intercept image-serving requests — pass straight to network to
    //    avoid infinite-loop / 508 issues with the PHP image proxy.
    const action = (url.searchParams.get('action') || '').toLowerCase();
    if (action === 'image') return;

    // 3. PHP/API requests — network-first with stale cache fallback for read actions
    const isApi = url.pathname.endsWith('.php') || url.pathname.includes('/api');
    if (isApi) {
        const isCacheableRead = CACHEABLE_API_ACTIONS.has(action);

        event.respondWith((async () => {
            try {
                const networkRes = await fetch(request);
                // Only cache successful JSON reads
                if (isCacheableRead && networkRes && networkRes.status === 200) {
                    const clone = networkRes.clone();
                    const cache = await caches.open(API_CACHE);
                    await cache.put(request, clone);
                    trimApiCache(); // fire-and-forget
                }
                return networkRes;
            } catch (err) {
                // Offline: fall back to a previously cached read if we have one
                if (isCacheableRead) {
                    const cached = await caches.match(request);
                    if (cached) return cached;
                }
                return new Response(
                    JSON.stringify({ success: false, offline: true, error: 'You are offline — showing cached data where available.' }),
                    { status: 503, headers: { 'Content-Type': 'application/json' } }
                );
            }
        })());
        return;
    }

    // 3. Static assets: cache-first, background-refresh
    event.respondWith(
        caches.match(request).then(cached => {
            const networkFetch = fetch(request).then(response => {
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(STATIC_CACHE).then(cache => cache.put(request, clone));
                }
                return response;
            });

            return cached || networkFetch;
        }).catch(() => {
            // Full offline fallback: return app shell for navigation requests
            if (request.mode === 'navigate') {
                return caches.match(new URL('index.html', self.registration.scope).href);
            }
        })
    );
});
