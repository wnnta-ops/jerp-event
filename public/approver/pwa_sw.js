'use strict';

// Service worker PWA J-ERP Approver.
// Strategi network-first: selalu ambil versi terbaru kalau online, pakai cache
// hanya saat offline. Request ke API (origin lain / /api/) tidak pernah di-cache.
// cache: 'no-cache' — selalu tanya server (ETag, murah) supaya tidak tertahan cache
// browser 4 jam dari Cloudflare (max-age=14400) setelah deploy baru.
const CACHE = 'jerp-pwa-v9';
const SHELL = ['./', 'index.html', 'kunci.js', 'push.js', 'manifest.json', 'favicon.png', 'icons/Icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin || url.pathname.includes('/api/')) {
    return;
  }
  event.respondWith(
    fetch(req.mode === 'navigate' ? req : new Request(req, { cache: 'no-cache' }))
      .then((res) => {
        if (res.ok) {
          const salinan = res.clone();
          caches.open(CACHE).then((c) => c.put(req, salinan));
        }
        return res;
      })
      .catch(() => caches.match(req).then((r) => r || caches.match('index.html'))),
  );
});

// ── Notifikasi push (dikirim FO: php artisan pwa:push) ───────────────────────────
self.addEventListener('push', (event) => {
  let d = {};
  try {
    d = event.data ? event.data.json() : {};
  } catch (e) {
    d = { body: event.data ? event.data.text() : '' };
  }
  event.waitUntil(self.registration.showNotification(d.title || 'J-ERP Approver', {
    body: d.body || '',
    tag: d.tag,
    icon: 'icons/Icon-192.png',
    badge: 'icons/Icon-192.png',
    data: { url: d.url || './' },
  }));
});

// Ketuk notifikasi → fokus ke app yang sudah terbuka (atau buka baru) di halaman dokumennya (?buka=ID).
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = new URL((event.notification.data && event.notification.data.url) || './', self.registration.scope).href;
  event.waitUntil((async () => {
    const jendela = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of jendela) {
      if (c.url.startsWith(self.registration.scope)) {
        await c.focus();
        return 'navigate' in c ? c.navigate(url) : undefined;
      }
    }
    return self.clients.openWindow(url);
  })());
});
