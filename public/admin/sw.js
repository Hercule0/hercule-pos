const CACHE_VERSION = "hercule-admin-shell-v10-live";
const STATIC_ASSETS = [
  "/public/admin/offline.html",
  "/public/admin/manifest.json",
  "/public/admin/assets/icons/app-icon.svg",
  "/public/admin/assets/icons/app-icon-192.png",
  "/public/admin/assets/icons/app-icon-512.png",
  "/public/admin/assets/icons/apple-touch-icon.png"
];

self.addEventListener("install", function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_VERSION).then(function (cache) {
      return Promise.allSettled(STATIC_ASSETS.map(function (asset) {
        return cache.add(asset);
      }));
    })
  );
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        if (key !== CACHE_VERSION) {
          return caches.delete(key);
        }
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener("fetch", function (event) {
  var request = event.request;
  if (request.method !== "GET") return;

  var url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Always use Network-first for CSS, JS, and live pages so updates reflect instantly
  if (url.pathname.endsWith(".css") || url.pathname.endsWith(".js") || request.mode === "navigate") {
    event.respondWith(
      fetch(request).catch(function () {
        return caches.match(request).then(function (cached) {
          if (cached) return cached;
          if (request.mode === "navigate") {
            return caches.match("/public/admin/offline.html");
          }
          return new Response("", { status: 408 });
        });
      })
    );
    return;
  }

  // Cache static image assets
  event.respondWith(
    caches.match(request).then(function (cached) {
      if (cached) return cached;
      return fetch(request).then(function (response) {
        if (response.ok) {
          var copy = response.clone();
          caches.open(CACHE_VERSION).then(function (cache) { cache.put(request, copy); });
        }
        return response;
      });
    })
  );
});
