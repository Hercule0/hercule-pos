(function () {
  var deferredPrompt = null;
  var registrationReady = false;
  var vapidPublicKeyPromise = null;
  var installButtons = Array.prototype.slice.call(document.querySelectorAll("[data-install-app]"));
  var standalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  var isiOS = /iphone|ipad|ipod/i.test(window.navigator.userAgent);

  function setInstallVisible(visible) {
    installButtons.forEach(function (button) { button.hidden = !visible; });
  }

  function getVapidPublicKey() {
    if (vapidPublicKeyPromise) return vapidPublicKeyPromise;
    vapidPublicKeyPromise = fetch("/public/admin/push_config.php", {
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" }
    }).then(function (response) {
      if (!response.ok) throw new Error("Push configuration returned " + response.status);
      return response.json();
    }).then(function (data) {
      if (!data || !data.ok || !data.publicKey) throw new Error((data && data.error) || "VAPID public key is missing");
      return data.publicKey;
    });
    return vapidPublicKeyPromise;
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = "=".repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  function saveSubscription(subscription) {
    return fetch("/public/admin/push_subscribe.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify(subscription.toJSON ? subscription.toJSON() : subscription)
    }).then(function (response) {
      return response.json().catch(function () {
        throw new Error("Subscription endpoint returned invalid JSON (HTTP " + response.status + ")");
      }).then(function (data) {
        if (!response.ok || !data || !data.ok) {
          throw new Error((data && (data.error || data.message)) || ("Subscription endpoint returned " + response.status));
        }
        return data;
      });
    });
  }

  function ensurePushSubscription(registration) {
    if (!("PushManager" in window) || !("Notification" in window) || Notification.permission !== "granted") {
      return Promise.resolve(null);
    }
    return Promise.all([registration.pushManager.getSubscription(), getVapidPublicKey()]).then(function (values) {
      var subscription = values[0];
      var publicKey = values[1];
      if (subscription) return subscription;
      return registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey)
      });
    }).then(function (subscription) {
      if (!subscription) return null;
      return saveSubscription(subscription).then(function () { return subscription; });
    });
  }

  function registerServiceWorker() {
    if (!("serviceWorker" in navigator) || (location.protocol !== "https:" && location.hostname !== "localhost")) return;
    navigator.serviceWorker.register("/public/admin/sw.js", {
      scope: "/public/admin/",
      updateViaCache: "none"
    }).then(function (registration) {
      registrationReady = true;
      registration.update();
      window.setInterval(function () { registration.update(); }, 60 * 60 * 1000);
      return navigator.serviceWorker.ready;
    }).then(function (registration) {
      registrationReady = true;
      return ensurePushSubscription(registration);
    }).catch(function (error) {
      registrationReady = false;
      console.error("Hercule PWA registration/push sync failed", error);
    });
  }

  window.HerculePush = {
    enable: function () {
      if (!("Notification" in window) || !("serviceWorker" in navigator) || !("PushManager" in window)) {
        return Promise.reject(new Error("Web Push is not supported on this device/browser"));
      }
      return Notification.requestPermission().then(function (permission) {
        if (permission !== "granted") throw new Error("Notification permission was not granted");
        return navigator.serviceWorker.ready;
      }).then(ensurePushSubscription);
    },
    sync: function () {
      if (!("serviceWorker" in navigator)) return Promise.resolve(null);
      return navigator.serviceWorker.ready.then(ensurePushSubscription);
    }
  };

  function showPushToast(title, message, type) {
    var stack = document.getElementById("app-toast-stack");
    if (!stack) {
      window.alert(title + "\n" + message);
      return;
    }
    var toast = document.createElement("div");
    toast.className = "app-toast " + (type || "info");
    toast.innerHTML = '<div class="toast-content"><strong></strong><span></span></div><button type="button" class="toast-close-btn">&times;</button>';
    toast.querySelector("strong").textContent = title;
    toast.querySelector("span").textContent = message;
    toast.querySelector("button").onclick = function () { toast.remove(); };
    stack.appendChild(toast);
    requestAnimationFrame(function () { toast.classList.add("is-visible"); });
    setTimeout(function () { if (toast.parentNode) toast.remove(); }, 9000);
  }

  function wirePushControls() {
    var enableButton = document.getElementById("push-perm-btn");
    if (enableButton) {
      enableButton.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        enableButton.disabled = true;
        window.HerculePush.enable().then(function (subscription) {
          if (!subscription || !subscription.endpoint) throw new Error("No push subscription was created");
          var label = enableButton.querySelector("span");
          if (label) label.textContent = "Alerts Active";
          enableButton.classList.add("is-granted");
          showPushToast("Push Notifications Enabled", "This phone is subscribed and synced with the server.", "success");
        }).catch(function (error) {
          showPushToast("Could not enable alerts", error.message || String(error), "error");
        }).finally(function () {
          enableButton.disabled = false;
        });
      }, true);
    }

    ["fast-test-alert-btn", "sidebar-fast-test-btn"].forEach(function (id) {
      var button = document.getElementById(id);
      if (!button) return;
      button.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        button.disabled = true;
        fetch("/public/admin/test_push.php", {
          method: "POST",
          credentials: "same-origin",
          cache: "no-store",
          headers: { "Accept": "application/json", "X-Hercule-Push-Test": "1" }
        }).then(function (response) {
          return response.json().catch(function () {
            throw new Error("Server returned invalid JSON (HTTP " + response.status + ")");
          }).then(function (data) {
            if (!response.ok || !data || data.ok !== true) {
              throw new Error((data && (data.error || data.message || data.code)) || ("HTTP " + response.status));
            }
            return data;
          });
        }).then(function (data) {
          var sent = data.sent != null ? data.sent : (data.successful != null ? data.successful : 0);
          var failed = data.failed != null ? data.failed : 0;
          showPushToast("Test Push", data.message || ("Delivered: " + sent + ", failed: " + failed), failed ? "warning" : "success");
        }).catch(function (error) {
          showPushToast("Test Push Failed", error.message || String(error), "error");
        }).finally(function () {
          button.disabled = false;
        });
      }, true);
    });
  }

  registerServiceWorker();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wirePushControls);
  } else {
    wirePushControls();
  }

  window.addEventListener("beforeinstallprompt", function (event) {
    event.preventDefault(); deferredPrompt = event; if (!standalone) setInstallVisible(true);
  });
  window.addEventListener("appinstalled", function () { deferredPrompt = null; setInstallVisible(false); });
  installButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      if (deferredPrompt) { deferredPrompt.prompt(); deferredPrompt.userChoice.finally(function () { deferredPrompt = null; }); return; }
      if (isiOS && !standalone) { window.alert("To install Hercule Admin: open this page in Safari, tap Share, then choose Add to Home Screen."); return; }
      if (!standalone) window.alert(registrationReady ? "Chrome is preparing the app. Refresh this page once, then tap Install mobile app again or choose Install app from the Chrome menu." : "The app service worker is not ready. Check your connection, refresh this page, then try again.");
    });
  });
  if (!standalone) setInstallVisible(true);
  document.documentElement.classList.toggle("is-standalone", standalone);
  window.addEventListener("online", function () { document.documentElement.classList.remove("is-offline"); if (window.HerculePush) window.HerculePush.sync().catch(function () {}); });
  window.addEventListener("offline", function () { document.documentElement.classList.add("is-offline"); });
  if (!navigator.onLine) document.documentElement.classList.add("is-offline");
})();
