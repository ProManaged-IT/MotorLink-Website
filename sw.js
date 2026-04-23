/**
 * MotorLink Service Worker
 * Strategy: Cache-first for static assets, network-first for dynamic/API requests.
 * Provides offline support for the app shell.
 */

const CACHE_VERSION = 'motorlink-v1';

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

            const cache = await caches.open(CACHE_VERSION);
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
                    .filter(key => key !== CACHE_VERSION)
                    .map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

// ── Fetch: serve from cache, fall back to network ───────────────────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // 1. Skip non-GET requests and cross-origin requests
    if (request.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;

    // 2. Skip PHP API calls — always go network-first so data is live
    if (url.pathname.includes('.php') || url.pathname.includes('/api')) {
        event.respondWith(
            fetch(request).catch(() => new Response(
                JSON.stringify({ error: 'Offline — please reconnect.' }),
                { headers: { 'Content-Type': 'application/json' } }
            ))
        );
        return;
    }

    // 3. For everything else: cache-first, update cache in background
    event.respondWith(
        caches.match(request).then(cached => {
            const networkFetch = fetch(request).then(response => {
                // Cache successful responses for static assets
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(CACHE_VERSION).then(cache => cache.put(request, clone));
                }
                return response;
            });

            // Return cache immediately if available; background-refresh
            return cached || networkFetch;
        }).catch(() => {
            // Full offline fallback: return app shell for navigation requests
            if (request.mode === 'navigate') {
                return caches.match('/motorlink/index.html');
            }
        })
    );
});
