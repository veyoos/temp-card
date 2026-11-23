// Network-only service worker: private card and session data are never cached.
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET' || event.request.mode !== 'navigate') return;
  event.respondWith(fetch(event.request).catch(() => new Response(
    '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>اتصال برقرار نیست</title><body><p>برای استفاده از سامانه به اینترنت و اتصال به سرور نیاز است.</p></body></html>',
    {status: 503, headers: {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'}}
  )));
});
