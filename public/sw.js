/*
 * Douffer PhotoSys: PWA-lite Service Worker (PROMPT §5, Scenario T12).
 *
 * Security & Privacy Rule:
 *   - Caches ONLY the static shell (CSS, JS, SVG icon, manifest, and offline.html).
 *   - NEVER caches authenticated HTML pages, client booking status pages, chat messages,
 *     API responses, or private uploads (/admin, /crew, /booking, /chat, /files, /api).
 */
(function () {
  'use strict';

  var CACHE_NAME = 'douffer-static-v1';
  var STATIC_ASSETS = [
    'offline.html',
    'manifest.json',
    'assets/css/app.css',
    'assets/js/app.js',
    'assets/js/pay.js',
    'assets/js/chat.js',
    'assets/js/admin_booking.js',
    'assets/img/icon.svg'
  ];

  self.addEventListener('install', function (event) {
    event.waitUntil(
      caches.open(CACHE_NAME).then(function (cache) {
        return cache.addAll(STATIC_ASSETS);
      }).then(function () {
        return self.skipWaiting();
      })
    );
  });

  self.addEventListener('activate', function (event) {
    event.waitUntil(
      caches.keys().then(function (keys) {
        return Promise.all(
          keys.map(function (key) {
            if (key !== CACHE_NAME) {
              return caches.delete(key);
            }
          })
        );
      }).then(function () {
        return self.clients.claim();
      })
    );
  });

  self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') {
      return;
    }

    var url = new URL(req.url);
    if (url.origin !== self.location.origin) {
      return;
    }

    // Navigation requests: always fetch live from network (never cache HTML pages);
    // if the network is unreachable, show the static offline.html fallback.
    if (req.mode === 'navigate') {
      event.respondWith(
        fetch(req).catch(function () {
          return caches.match('offline.html');
        })
      );
      return;
    }

    // Static shell assets (/assets/*, manifest.json, offline.html): stale-while-revalidate
    if (url.pathname.indexOf('/assets/') !== -1 || url.pathname.endsWith('/manifest.json') || url.pathname.endsWith('/offline.html')) {
      event.respondWith(
        caches.match(req, { ignoreSearch: true }).then(function (cached) {
          var networkFetch = fetch(req).then(function (res) {
            if (res && res.status === 200) {
              var clone = res.clone();
              caches.open(CACHE_NAME).then(function (cache) {
                cache.put(req, clone);
              });
            }
            return res;
          }).catch(function () {
            return cached;
          });
          return cached || networkFetch;
        })
      );
    }
  });
})();
