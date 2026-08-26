const CACHE_VERSION = "hercule-admin-shell-v14-push-url-guard";
const STATIC_ASSETS = [
  "/public/admin/offline.html",
  "/public/admin/manifest.json",
  "/public/admin/assets/icons/app-icon.svg",
  "/public/admin/assets/icons/app-icon-192.png",
  "/public/admin/assets/icons/app-icon-512.png",
  "/public/admin/assets/icons/apple-touch-icon.png"
];

function safeAdminUrl(candidate) {
  try {
    var parsed = new URL(String(candidate || "/public/admin/index.php"), self.location.origin);
    if (parsed.origin !== self.location.origin || !parsed.pathname.startsWith("/public/admin/")) {
      throw new Error("push target outside admin scope");
    }
    return parsed.href;
  } catch (error) {
    return new URL("/public/admin/index.php", self.location.origin).href;
  }
}

self.addEventListener("install", function (event) {
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE_VERSION).then(function (cache) {
    return Promise.allSettled(STATIC_ASSETS.map(function (asset) { return cache.add(asset); }));
  }));
});

self.addEventListener("activate", function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.map(function (key) { if (key !== CACHE_VERSION) return caches.delete(key); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener("fetch", function (event) {
  var request = event.request;
  if (request.method !== "GET") return;
  var url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Dynamic PHP should never be cached. For top-level PHP navigation, provide the
  // offline page on a genuine network failure. For API/subresource PHP requests,
  // do not intercept at all so a rejected fetch cannot become an unhandled SW promise.
  if (url.pathname.endsWith(".php")) {
    if (request.mode === "navigate") {
      event.respondWith(fetch(request, { cache: "no-store" }).catch(function () {
        return caches.match("/public/admin/offline.html").then(function (offline) {
          return offline || new Response("Offline", { status: 503, headers: { "Content-Type": "text/plain" } });
        });
      }));
    }
    return;
  }

  if (url.pathname.endsWith(".css") || url.pathname.endsWith(".js")) {
    event.respondWith(fetch(request, { cache: "no-store" }).catch(function () {
      return caches.match(request).then(function (cached) {
        return cached || new Response("", { status: 408 });
      });
    }));
    return;
  }

  if (request.mode === "navigate") {
    event.respondWith(fetch(request, { cache: "no-store" }).catch(function () {
      return caches.match("/public/admin/offline.html").then(function (offline) {
        return offline || new Response("Offline", { status: 503, headers: { "Content-Type": "text/plain" } });
      });
    }));
    return;
  }

  event.respondWith(caches.match(request).then(function (cached) {
    if (cached) return cached;
    return fetch(request).then(function (response) {
      if (response.ok) {
        var copy = response.clone();
        caches.open(CACHE_VERSION).then(function (cache) { cache.put(request, copy); });
      }
      return response;
    });
  }));
});

self.addEventListener("push", function (event) {
  var data = {
    title: "Hercule POS Alert",
    body: "New real-time activity on license server.",
    url: "/public/admin/index.php"
  };

  if (event.data) {
    try {
      var incoming = event.data.json();
      data.title = String(incoming.title || data.title).slice(0, 120);
      data.body = String(incoming.body || incoming.message || data.body).slice(0, 500);
      data.url = incoming.url || incoming.actionUrl || data.url;
      data.tag = incoming.tag ? String(incoming.tag).slice(0, 120) : undefined;
    } catch (e) {
      data.body = String(event.data.text() || data.body).slice(0, 500);
    }
  }

  var options = {
    body: data.body,
    icon: "/public/admin/assets/icons/app-icon-192.png",
    badge: "/public/admin/assets/icons/app-icon-192.png",
    vibrate: [200, 100, 200, 100, 200],
    tag: data.tag || "hercule-push-" + Date.now(),
    renotify: true,
    data: { url: safeAdminUrl(data.url) }
  };

  event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener("notificationclick", function (event) {
  event.notification.close();
  var targetUrl = safeAdminUrl(event.notification.data && event.notification.data.url);

  event.waitUntil(clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (clientList) {
    for (var i = 0; i < clientList.length; i++) {
      var client = clientList[i];
      if (client.url.indexOf("/public/admin/") !== -1 && "focus" in client) {
        return client.focus().then(function (focusedClient) {
          if (focusedClient && "navigate" in focusedClient) return focusedClient.navigate(targetUrl);
          return focusedClient;
        });
      }
    }
    if (clients.openWindow) return clients.openWindow(targetUrl);
  }));
});
