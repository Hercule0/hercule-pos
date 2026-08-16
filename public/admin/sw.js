const CACHE_VERSION = "hercule-admin-shell-v2";
const STATIC_ASSETS = [
  "/public/admin/offline.html",
  "/public/admin/assets/css/style.css",
  "/public/admin/assets/icons/app-icon.svg",
  "/public/admin/assets/icons/app-icon-192.png",
  "/public/admin/assets/icons/app-icon-512.png",
  "/public/admin/assets/icons/apple-touch-icon.png"
];

self.addEventListener("install", function (event) {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then(function (cache) { return cache.addAll(STATIC_ASSETS); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(keys.filter(function (key) {
          return key.startsWith("hercule-admin-shell-") && key !== CACHE_VERSION;
        }).map(function (key) { return caches.delete(key); }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener("fetch", function (event) {
  var request = event.request;
  if (request.method !== "GET") return;

  var url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  var isStatic = url.pathname.startsWith("/public/admin/assets/")
    || url.pathname === "/public/admin/offline.html";

  if (isStatic) {
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
    return;
  }

  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request, { cache: "no-store" })
        .catch(function () { return caches.match("/public/admin/offline.html"); })
    );
  }
});

// Authenticated PHP pages and JSON endpoints are deliberately never cached.
