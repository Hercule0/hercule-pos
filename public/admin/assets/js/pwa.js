(function () {
  var deferredPrompt = null;
  var registrationReady = false;
  var installButtons = Array.prototype.slice.call(document.querySelectorAll("[data-install-app]"));
  var standalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  var isiOS = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
  var VAPID_PUBLIC_KEY = "BKraEuulwXx3knDp50hkOAI1QaJBnFxTngjhnfi48WkMMKcDSBCwxn4WePT0RSrEnJWEmgX-DpG9WiVgK_rNAAY";

  function setInstallVisible(visible) {
    installButtons.forEach(function (button) { button.hidden = !visible; });
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
      if (!response.ok) throw new Error("Subscription endpoint returned " + response.status);
      return response.json();
    }).then(function (data) {
      if (!data || !data.ok) throw new Error((data && data.error) || "Subscription was not saved");
      return data;
    });
  }

  function ensurePushSubscription(registration) {
    if (!("PushManager" in window) || !("Notification" in window) || Notification.permission !== "granted") {
      return Promise.resolve(null);
    }
    return registration.pushManager.getSubscription().then(function (subscription) {
      if (subscription) return subscription;
      return registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
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
      // Repair an existing granted installation automatically. This is important
      // for already-installed PWAs whose browser permission survived an app update.
      return ensurePushSubscription(registration);
    }).catch(function (error) {
      registrationReady = false;
      console.error("Hercule PWA registration/push sync failed", error);
    });
  }

  // Shared API used by the admin header button. Keeps one SW and one subscription flow.
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

  registerServiceWorker();

  window.addEventListener("beforeinstallprompt", function (event) {
    event.preventDefault();
    deferredPrompt = event;
    if (!standalone) setInstallVisible(true);
  });

  window.addEventListener("appinstalled", function () {
    deferredPrompt = null;
    setInstallVisible(false);
  });

  installButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.finally(function () { deferredPrompt = null; });
        return;
      }
      if (isiOS && !standalone) {
        window.alert("To install Hercule Admin: open this page in Safari, tap Share, then choose Add to Home Screen.");
        return;
      }
      if (!standalone) {
        window.alert(registrationReady
          ? "Chrome is preparing the app. Refresh this page once, then tap Install mobile app again or choose Install app from the Chrome menu."
          : "The app service worker is not ready. Check your connection, refresh this page, then try again.");
      }
    });
  });

  if (!standalone) setInstallVisible(true);
  document.documentElement.classList.toggle("is-standalone", standalone);
  window.addEventListener("online", function () {
    document.documentElement.classList.remove("is-offline");
    if (window.HerculePush) window.HerculePush.sync().catch(function () {});
  });
  window.addEventListener("offline", function () { document.documentElement.classList.add("is-offline"); });
  if (!navigator.onLine) document.documentElement.classList.add("is-offline");
})();
