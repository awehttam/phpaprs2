/**
 * Service Worker for APRS-IS Live Map PWA
 */

const CACHE_NAME = 'aprs-live-v1';
const ASSETS_TO_CACHE = [
    '/index.html',
    '/css/style.css',
    '/js/app.js',
    '/js/map-manager.js',
    '/js/sse-client.js',
    '/icons/icon.svg',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/manifest.json'
];

// Install event - cache assets
self.addEventListener('install', (event) => {
    console.log('[SW] Installing service worker...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Caching app assets');
                return cache.addAll(ASSETS_TO_CACHE);
            })
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating service worker...');
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => name !== CACHE_NAME)
                        .map((name) => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => self.clients.claim())
    );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', (event) => {
    // Skip SSE requests - they need real-time data
    if (event.request.url.includes('sse.php')) {
        return;
    }

    // Skip external CDN requests for Leaflet and Font Awesome
    if (event.request.url.includes('unpkg.com') ||
        event.request.url.includes('cdnjs.cloudflare.com')) {
        return;
    }

    event.respondWith(
        caches.match(event.request)
            .then((response) => {
                // Return cached version or fetch from network
                return response || fetch(event.request)
                    .then((fetchResponse) => {
                        // Cache new resources
                        if (fetchResponse.ok) {
                            return caches.open(CACHE_NAME)
                                .then((cache) => {
                                    cache.put(event.request, fetchResponse.clone());
                                    return fetchResponse;
                                });
                        }
                        return fetchResponse;
                    });
            })
            .catch(() => {
                // Fallback for offline navigation
                if (event.request.mode === 'navigate') {
                    return caches.match('/index.html');
                }
            })
    );
});
