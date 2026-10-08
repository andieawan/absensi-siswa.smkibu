/* Service worker Absensi Siswa.
 * - Aset statis (CSS/JS/ikon) disimpan di cache agar aplikasi cepat terbuka.
 * - Halaman aplikasi SELALU diambil dari server (data absensi harus terbaru dan
 *   halaman berisi data siswa tidak disimpan di perangkat). Bila offline, tampil offline.html.
 * - Permintaan POST (simpan absensi/nilai) tidak pernah disentuh service worker.
 */
const VERSION = 'absensi-v20';
const BASE = new URL('./', self.location).pathname;
const STATIC = ['offline.html', 'assets/app.css', 'assets/app.js', 'icons/icon-192.png', 'icons/icon-512.png'].map((p) => BASE + p);

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((c) => c.addAll(STATIC)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Navigasi halaman: jaringan dulu; offline → halaman offline.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match(BASE + 'offline.html')));
    return;
  }

  // Aset statis: cache dulu, perbarui di latar belakang (URL aset memakai ?v=versi file).
  if (url.pathname.startsWith(BASE + 'assets/') || url.pathname.startsWith(BASE + 'icons/')) {
    event.respondWith(
      caches.open(VERSION).then((cache) =>
        cache.match(req).then((hit) => {
          const net = fetch(req).then((res) => {
            if (res.ok) cache.put(req, res.clone());
            return res;
          }).catch(() => hit);
          return hit || net;
        })
      )
    );
  }
});
