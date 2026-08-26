(function () {
  "use strict";

  function byId(id) { return document.getElementById(id); }

  function toggleSidebar(force) {
    var sidebar = byId("app-sidebar");
    var backdrop = byId("sidebar-backdrop");
    if (!sidebar) return;
    var open = typeof force === "boolean" ? force : !sidebar.classList.contains("is-open");
    sidebar.classList.toggle("is-open", open);
    document.body.classList.toggle("sidebar-open", open);
    if (backdrop) backdrop.classList.toggle("is-open", open);
  }

  function wireNavigation() {
    var userBtn = byId("user-menu-btn");
    var userMenu = byId("user-dropdown-menu");
    if (userBtn && userMenu) {
      userBtn.addEventListener("click", function (event) {
        event.stopPropagation();
        var open = userMenu.classList.toggle("is-open");
        userBtn.setAttribute("aria-expanded", open ? "true" : "false");
      });
      document.addEventListener("click", function (event) {
        if (!event.target.closest(".user-menu-area")) {
          userMenu.classList.remove("is-open");
          userBtn.setAttribute("aria-expanded", "false");
        }
      });
    }

    var menuToggle = byId("mobile-menu-toggle");
    var moreTrigger = byId("mobile-drawer-trigger");
    var backdrop = byId("sidebar-backdrop");
    if (menuToggle) menuToggle.addEventListener("click", function () { toggleSidebar(); });
    if (moreTrigger) moreTrigger.addEventListener("click", function () { toggleSidebar(); });
    if (backdrop) backdrop.addEventListener("click", function () { toggleSidebar(false); });
    window.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        toggleSidebar(false);
        if (userMenu) userMenu.classList.remove("is-open");
        if (userBtn) userBtn.setAttribute("aria-expanded", "false");
      }
    });
  }

  function wireSafeForms() {
    document.addEventListener("submit", function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) return;

      var submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
      var confirmMessage = String(
        (submitter && submitter.dataset && submitter.dataset.confirm) || form.dataset.confirm || ""
      ).trim();
      if (confirmMessage && !window.confirm(confirmMessage)) {
        event.preventDefault();
        return;
      }

      var passwordPrompt = String(form.dataset.passwordPrompt || "").trim();
      if (!passwordPrompt || form.querySelector('input[name="current_password"]')) return;

      var password = window.prompt(passwordPrompt);
      if (password === null || password === "") {
        event.preventDefault();
        return;
      }

      var input = document.createElement("input");
      input.type = "hidden";
      input.name = "current_password";
      input.value = password;
      form.appendChild(input);
    });

    document.addEventListener("change", function (event) {
      var control = event.target;
      if (!(control instanceof HTMLElement) || !control.matches("[data-submit-on-change]")) return;
      var form = control.closest("form");
      if (form) form.requestSubmit();
    });
  }

  function safeAdminUrl(value) {
    if (typeof value !== "string" || !value) return null;
    try {
      var url = new URL(value, window.location.origin);
      if (url.origin !== window.location.origin) return null;
      if (url.pathname.indexOf("/public/admin/") !== 0) return null;
      return url.pathname + url.search + url.hash;
    } catch (_) {
      return null;
    }
  }

  function showToast(options) {
    options = options || {};
    var stack = byId("app-toast-stack");
    if (!stack) return;

    var toast = document.createElement("div");
    toast.className = "app-toast " + (/^(success|warning|error|info)$/.test(options.type || "") ? options.type : "info");

    var icon = document.createElement("div");
    icon.className = "toast-icon-wrap";
    icon.textContent = options.type === "warning" ? "!" : "•";

    var content = document.createElement("div");
    content.className = "toast-content";
    var title = document.createElement("strong");
    title.textContent = String(options.title || "Notification");
    var message = document.createElement("span");
    message.textContent = String(options.message || "");
    content.appendChild(title);
    content.appendChild(message);

    var actionUrl = safeAdminUrl(options.actionUrl);
    if (actionUrl) {
      var action = document.createElement("a");
      action.className = "toast-action-btn";
      action.href = actionUrl;
      action.textContent = String(options.actionLabel || "View");
      content.appendChild(action);
    }

    var close = document.createElement("button");
    close.type = "button";
    close.className = "toast-close-btn";
    close.setAttribute("aria-label", "Dismiss");
    close.textContent = "×";
    close.addEventListener("click", function () { toast.remove(); });

    toast.appendChild(icon);
    toast.appendChild(content);
    toast.appendChild(close);
    stack.appendChild(toast);
    requestAnimationFrame(function () { toast.classList.add("is-visible"); });
    window.setTimeout(function () { if (toast.parentNode) toast.remove(); }, Math.max(2500, Number(options.duration) || 8000));
  }

  window.HerculeAdminToast = showToast;

  function wireRecoveryPolling() {
    var recoveryBadge = byId("recovery-notification-count");
    var sidebarBadge = byId("sidebar-recovery-badge");
    var mobileBadge = byId("mobile-recovery-badge");
    if (!recoveryBadge && !sidebarBadge && !mobileBadge) return;

    var lastSeenId = 0;
    try { lastSeenId = Math.max(0, Number(localStorage.getItem("herculeRecoveryLastSeenId") || 0)); } catch (_) {}

    function updateCounters(count) {
      var n = Math.max(0, Number(count) || 0);
      var text = n > 99 ? "99+" : String(n);
      [recoveryBadge, sidebarBadge, mobileBadge].forEach(function (badge) {
        if (!badge) return;
        badge.textContent = text;
        badge.hidden = n === 0;
      });
    }

    function poll() {
      fetch("/public/admin/recovery_notifications.php?after_id=" + encodeURIComponent(String(lastSeenId)), {
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      }).then(function (response) {
        if (!response.ok) throw new Error("recovery HTTP " + response.status);
        return response.json();
      }).then(function (data) {
        if (!data || data.ok !== true) return;
        updateCounters(data.pending_count);
        if (!Array.isArray(data.requests) || data.requests.length === 0) return;
        var request = data.requests[0] || {};
        showToast({
          title: "Password Recovery Request",
          message: "User " + String(request.username || "") + " submitted a reset request.",
          actionUrl: request.url,
          actionLabel: "Review Request",
          type: "warning"
        });
        lastSeenId = Math.max(lastSeenId, Number(data.latest_id) || 0);
        try { localStorage.setItem("herculeRecoveryLastSeenId", String(lastSeenId)); } catch (_) {}
      }).catch(function () {
        // Polling is best effort. Session expiry and temporary network errors are silent.
      }).finally(function () {
        window.setTimeout(poll, document.hidden ? 30000 : 15000);
      });
    }

    window.setTimeout(poll, 800);
  }

  function start() {
    wireNavigation();
    wireSafeForms();
    wireRecoveryPolling();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
