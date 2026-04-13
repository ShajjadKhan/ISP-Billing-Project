const CACHE_NAME = 'isp-customer-v1';
const urlsToCache = [
  '/customer/',
  '/customer/manifest.json',
  '/customer/icon-192.png',
  '/customer/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(urlsToCache);
    })
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request).then(response => {
      return response || fetch(event.request);
    })
  );
});
