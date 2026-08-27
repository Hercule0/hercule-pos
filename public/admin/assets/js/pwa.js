(function () {
  "use strict";

  var deferredPrompt = null;
  var registrationReady = false;
  var pushConfigPromise = null;
  var installButtons = Array.prototype.slice.call(document.querySelectorAll("[data-install-app]"));
  var standalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  var isiOS = /iphone|ipad|ipod/i.test(window.navigator.userAgent);

  function setInstallVisible(visible) {
    installButtons.forEach(function (button) { button.hidden = !visible; });
  }

  function getPushDeviceId() {
    var key = "hercule_push_device_id";
    try {
      var existing = window.localStorage.getItem(key);
      if (existing && /^[A-Za-z0-9_-]{16,64}$/.test(existing)) return existing;
      var generated;
      if (window.crypto && typeof window.crypto.randomUUID === "function") {
        generated = window.crypto.randomUUID().replace(/-/g, "");
      } else if (window.crypto && typeof window.crypto.getRandomValues === "function") {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        generated = Array.prototype.map.call(bytes, function (byte) { return byte.toString(16).padStart(2, "0"); }).join("");
      } else {
        generated = (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).slice(0, 32);
      }
      window.localStorage.setItem(key, generated);
      return generated;
    } catch (error) {
      return (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).slice(0, 32);
    }
  }

  function getPushConfig() {
    if (pushConfigPromise) return pushConfigPromise;
    pushConfigPromise = fetch("/public/admin/push_config.php", {
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" }
    }).then(function (response) {
      return response.json().catch(function () {
        throw new Error("Push configuration returned invalid JSON (HTTP " + response.status + ")");
      }).then(function (data) {
        if (!response.ok || !data || !data.ok || !data.publicKey || !data.csrfToken) {
          throw new Error((data && (data.error || data.message)) || ("Push configuration returned " + response.status));
        }
        if (!/^[A-Za-z0-9_-]+$/.test(String(data.publicKey)) || !/^[a-f0-9]{64}$/i.test(String(data.csrfToken))) {
          throw new Error("Push configuration response is invalid");
        }
        return {
          publicKey: String(data.publicKey),
          csrfToken: String(data.csrfToken)
        };
      });
    }).catch(function (error) {
      pushConfigPromise = null;
      throw error;
    });
    return pushConfigPromise;
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = "=".repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/\-/g, "+").replace(/_/g, "/");
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  function saveSubscription(subscription) {
    var payload = subscription.toJSON ? subscription.toJSON() : subscription;
    payload.device_id = getPushDeviceId();
    payload.user_agent = String(window.navigator.userAgent || "").slice(0, 255);

    return getPushConfig().then(function (config) {
      return fetch("/public/admin/api.php?action=push_subscribe", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-Token": config.csrfToken
        },
        body: JSON.stringify(payload)
      });
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

    return Promise.all([registration.pushManager.getSubscription(), getPushConfig()]).then(function (values) {
      var subscription = values[0];
      var config = values[1];
      if (subscription) return subscription;
      return registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(config.publicKey)
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
    if (typeof window.HerculeAdminToast === "function") {
      window.HerculeAdminToast({ title: title, message: message, type: type || "info" });
      return;
    }

    var stack = document.getElementById("app-toast-stack");
    if (!stack) {
      window.alert(title + "\n" + message);
      return;
    }

    var toast = document.createElement("div");
    toast.className = "app-toast " + (/^(success|warning|error|info)$/.test(type || "") ? type : "info");

    var content = document.createElement("div");
    content.className = "toast-content";
    var heading = document.createElement("strong");
    heading.textContent = String(title || "Notification");
    var body = document.createElement("span");
    body.textContent = String(message || "");
    content.appendChild(heading);
    content.appendChild(body);

    var close = document.createElement("button");
    close.type = "button";
    close.className = "toast-close-btn";
    close.setAttribute("aria-label", "Dismiss");
    close.textContent = "×";
    close.addEventListener("click", function () { toast.remove(); });

    toast.appendChild(content);
    toast.appendChild(close);
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
          showPushToast("Push Notifications Enabled", "This browser is subscribed and synced with the server.", "success");
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

        getPushConfig().then(function (config) {
          return fetch("/public/admin/test_push.php", {
            method: "POST",
            credentials: "same-origin",
            cache: "no-store",
            headers: {
              "Accept": "application/json",
              "X-Hercule-Push-Test": "1",
              "X-CSRF-Token": config.csrfToken
            }
          });
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
          var sent = data.dispatched != null ? data.dispatched : (data.sent != null ? data.sent : 0);
          var failed = data.failed != null ? data.failed : 0;
          showPushToast("Test Push", data.message || ("Delivered endpoints: " + sent + ", failed: " + failed), failed ? "warning" : "success");
        }).catch(function (error) {
          showPushToast("Test Push Failed", error.message || String(error), "error");
        }).finally(function () {
          button.disabled = false;
        });
      }, true);
    });
  }

  function createToolsLink(href, className, current) {
    var link = document.createElement("a");
    link.href = href;
    link.className = className + (current ? " active" : "");

    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    var path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", "M4 7h16M4 12h16M4 17h16");
    svg.appendChild(path);

    var label = document.createElement("span");
    label.textContent = "Admin Tools";
    link.appendChild(svg);
    link.appendChild(label);
    return link;
  }

  function wireAdminToolsNavigation() {
    var href = "/public/admin/tools.php";
    var current = window.location.pathname === href;
    var sidebarNav = document.querySelector(".sidebar-nav");
    if (sidebarNav && !sidebarNav.querySelector('[href="' + href + '"]')) {
      var link = createToolsLink(href, "sidebar-link", current);
      var labels = sidebarNav.querySelectorAll(".sidebar-section-label");
      var systemLabel = labels.length > 1 ? labels[1] : null;
      if (systemLabel && systemLabel.nextSibling) {
        sidebarNav.insertBefore(link, systemLabel.nextSibling);
      } else {
        sidebarNav.appendChild(link);
      }
    }

    var dropdown = document.getElementById("user-dropdown-menu");
    if (dropdown && !dropdown.querySelector('[href="' + href + '"]')) {
      var item = createToolsLink(href, "dropdown-item", current);
      var divider = dropdown.querySelector(".dropdown-divider");
      if (divider && divider.nextSibling) {
        dropdown.insertBefore(item, divider.nextSibling);
      } else {
        dropdown.appendChild(item);
      }
    }
  }

  registerServiceWorker();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      wirePushControls();
      wireAdminToolsNavigation();
    });
  } else {
    wirePushControls();
    wireAdminToolsNavigation();
  }

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
