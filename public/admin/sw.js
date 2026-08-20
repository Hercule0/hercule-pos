const CACHE_VERSION = "hercule-admin-shell-v11-push";
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

  if (url.pathname.endsWith(".css") || url.pathname.endsWith(".js") || request.mode === "navigate") {
    event.respondWith(fetch(request).catch(function () {
      return caches.match(request).then(function (cached) {
        if (cached) return cached;
        if (request.mode === "navigate") return caches.match("/public/admin/offline.html");
        return new Response("", { status: 408 });
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
      data.title = incoming.title || data.title;
      // PushNotifier historically sends message/actionUrl; also support body/url.
      data.body = incoming.body || incoming.message || data.body;
      data.url = incoming.url || incoming.actionUrl || data.url;
      data.tag = incoming.tag;
    } catch (e) {
      data.body = event.data.text() || data.body;
    }
  }

  var options = {
    body: data.body,
    icon: "/public/admin/assets/icons/app-icon-192.png",
    badge: "/public/admin/assets/icons/app-icon-192.png",
    vibrate: [200, 100, 200, 100, 200],
    tag: data.tag || "hercule-push-" + Date.now(),
    renotify: true,
    data: { url: data.url }
  };

  event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener("notificationclick", function (event) {
  event.notification.close();
  var targetUrl = (event.notification.data && event.notification.data.url) || "/public/admin/index.php";
  var absoluteTarget = new URL(targetUrl, self.location.origin).href;

  event.waitUntil(clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (clientList) {
    for (var i = 0; i < clientList.length; i++) {
      var client = clientList[i];
      if (client.url.indexOf("/public/admin/") !== -1 && "focus" in client) {
        return client.focus().then(function (focusedClient) {
          if (focusedClient && "navigate" in focusedClient) return focusedClient.navigate(absoluteTarget);
          return focusedClient;
        });
      }
    }
    if (clients.openWindow) return clients.openWindow(absoluteTarget);
  }));
});
