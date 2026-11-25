// Service worker Prima Management
// - Aset build (/build/*) & ikon: cache-first (nama file sudah ber-hash)
// - Halaman Fokus: network-first, salinan terakhir disimpan agar timer & catat offline tetap bisa dibuka
// - Halaman lain: network-first, jatuh ke halaman offline bila tidak ada koneksi
// - Push: isi notifikasi diambil dari /pengingat/terbaru (push dikirim tanpa payload)
const CACHE = 'prima-management-v3';
const PAGES = 'prima-management-pages-v1';
const OFFLINE_URL = '/offline.html';
const OFFLINE_PAGES = ['/fokus'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png'])).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE && key !== PAGES).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    // Dikirim saat logout supaya halaman berisi data pribadi tidak tertinggal di perangkat.
    if (event.data === 'clear-pages') {
        event.waitUntil(caches.delete(PAGES));
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(request, copy));
                        }
                        return response;
                    }),
            ),
        );
        return;
    }

    if (request.mode === 'navigate' && OFFLINE_PAGES.includes(url.pathname)) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && !response.redirected) {
                        const copy = response.clone();
                        caches.open(PAGES).then((cache) => cache.put(url.pathname, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(url.pathname, { cacheName: PAGES }).then((cached) => cached || caches.match(OFFLINE_URL))),
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    }
});

self.addEventListener('push', (event) => {
    const show = (items) =>
        Promise.all(
            items.map((item) =>
                self.registration.showNotification(item.title, {
                    body: item.body,
                    tag: item.key,
                    icon: '/icons/icon-192.png',
                    badge: '/icons/icon-192.png',
                    data: { url: item.url || '/' },
                }),
            ),
        );

    event.waitUntil(
        fetch('/pengingat/terbaru', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : []))
            .catch(() => [])
            .then((items) =>
                items.length
                    ? show(items)
                    : show([{ key: 'prima-generic', title: 'Prima Management', body: 'Ada pengingat baru untukmu.', url: '/' }]),
            ),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((client) => client.url.startsWith(self.location.origin));
            if (existing) {
                return existing.navigate(target).then((client) => (client || existing).focus());
            }
            return self.clients.openWindow(target);
        }),
    );
});
