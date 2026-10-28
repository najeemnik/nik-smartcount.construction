/**
 * NiK Smart Count — Service Worker
 *
 * استراتژی:
 *   • /api/*            → فقط شبکه (هیچ‌گاه کش نمی‌شود؛ دادهٔ مالی و حساس است)
 *   • /nsc-runtime.php  → فقط شبکه (کش با ETag خود مرورگر مدیریت می‌شود)
 *   • ناوبری صفحه‌ها     → شبکه؛ در قطعی، صفحهٔ «آفلاین» نمایش داده می‌شود
 *   • /assets/*         → stale-while-revalidate (سرعت لود آیکون و نشان)
 *
 * نکتهٔ امنیتی: کش کردن پاسخ‌های API می‌تواند باعث دیدن دادهٔ یک کاربر توسط
 * کاربر دیگر روی همان دستگاه شود؛ بنابراین صریحاً ممنوع شده است.
 */
const STATIC_CACHE = 'nsc-static-v1';
const STATIC_ASSETS = [
  '/assets/brand-full.webp',
  '/assets/favicon-32.png',
  '/assets/install-192.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(STATIC_ASSETS).catch(() => undefined))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== STATIC_CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

function offlinePage() {
  const html = '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>آفلاین · NiK Smart Count</title>'
    + '<body style="margin:0;font-family:Tahoma,sans-serif;background:#eef5f6;color:#102a33;'
    + 'display:grid;place-items:center;min-height:100vh;text-align:center;padding:24px">'
    + '<div><h1 style="font-size:20px">اتصال برقرار نیست</h1>'
    + '<p style="color:#5b7278;font-size:14px;max-width:360px">اینترنت خود را بررسی کنید. '
    + 'تغییرات ثبت‌نشده در همین دستگاه محفوظ است و پس از وصل شدن ارسال می‌شود.</p>'
    + '<button onclick="location.reload()" style="border:0;border-radius:10px;padding:11px 20px;'
    + 'background:#087b61;color:#fff;font:inherit;cursor:pointer">تلاش دوباره</button></div></body></html>';
  return new Response(html, {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }
  });
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // دادهٔ حساس: هرگز کش نشود
  if (url.pathname.startsWith('/api/') || url.pathname === '/nsc-runtime.php') {
    event.respondWith(fetch(request));
    return;
  }

  // ناوبری: شبکه، و در نبود شبکه صفحهٔ آفلاین
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => offlinePage()));
    return;
  }

  // دارایی‌های ثابت: اول کش، هم‌زمان تازه‌سازی
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(request).then((cached) => {
        const network = fetch(request).then((response) => {
          if (response && response.ok) {
            const copy = response.clone();
            caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy)).catch(() => undefined);
          }
          return response;
        }).catch(() => cached);
        return cached || network;
      })
    );
  }
});
