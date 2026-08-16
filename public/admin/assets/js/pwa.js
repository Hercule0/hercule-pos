(function () {
  var deferredPrompt = null;
  var registrationReady = false;
  var installButtons = Array.prototype.slice.call(document.querySelectorAll("[data-install-app]"));
  var standalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  var isiOS = /iphone|ipad|ipod/i.test(window.navigator.userAgent);

  function setInstallVisible(visible) {
    installButtons.forEach(function (button) { button.hidden = !visible; });
  }

  function registerServiceWorker() {
    if (!("serviceWorker" in navigator) || (location.protocol !== "https:" && location.hostname !== "localhost")) {
      return;
    }

    navigator.serviceWorker.register("/public/admin/sw.js", {
      scope: "/public/admin/",
      updateViaCache: "none"
    }).then(function (registration) {
      registrationReady = true;
      registration.update();
      window.setInterval(function () { registration.update(); }, 60 * 60 * 1000);
      return navigator.serviceWorker.ready;
    }).then(function () {
      registrationReady = true;
    }).catch(function () {
      registrationReady = false;
    });
  }

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
        deferredPrompt.userChoice.finally(function () {
          deferredPrompt = null;
        });
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
  window.addEventListener("online", function () { document.documentElement.classList.remove("is-offline"); });
  window.addEventListener("offline", function () { document.documentElement.classList.add("is-offline"); });
  if (!navigator.onLine) document.documentElement.classList.add("is-offline");
})();
