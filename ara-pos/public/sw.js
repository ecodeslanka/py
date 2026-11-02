// minimal service worker: makes the app installable; network-first so data is never stale
self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => { if (e.request.method === 'GET' && !e.request.url.includes('/api/')) e.respondWith(fetch(e.request).catch(() => caches.match(e.request))); });
