(function () {
  "use strict";

  function byId(id) { return document.getElementById(id); }

  function toggleSidebar(force) {
    var sidebar = byId("app-sidebar");
    var backdrop = byId("sidebar-backdrop");
    var menuToggle = byId("mobile-menu-toggle");
    var moreTrigger = byId("mobile-drawer-trigger");
    if (!sidebar) return;
    var open = typeof force === "boolean" ? force : !sidebar.classList.contains("is-open");
    sidebar.classList.toggle("is-open", open);
    document.body.classList.toggle("sidebar-open", open);
    if (backdrop) backdrop.classList.toggle("is-open", open);
    if (menuToggle) menuToggle.setAttribute("aria-expanded", open ? "true" : "false");
    if (moreTrigger) moreTrigger.setAttribute("aria-expanded", open ? "true" : "false");
  }

  function closeUserMenu(userBtn, userMenu) {
    if (userMenu) userMenu.classList.remove("is-open");
    if (userBtn) userBtn.setAttribute("aria-expanded", "false");
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
        if (!event.target.closest(".user-menu-area")) closeUserMenu(userBtn, userMenu);
      });
    }

    var menuToggle = byId("mobile-menu-toggle");
    var moreTrigger = byId("mobile-drawer-trigger");
    var backdrop = byId("sidebar-backdrop");
    if (menuToggle) {
      menuToggle.setAttribute("aria-expanded", "false");
      menuToggle.setAttribute("aria-controls", "app-sidebar");
      menuToggle.addEventListener("click", function () { toggleSidebar(); });
    }
    if (moreTrigger) {
      moreTrigger.setAttribute("aria-expanded", "false");
      moreTrigger.setAttribute("aria-controls", "app-sidebar");
      moreTrigger.addEventListener("click", function () { toggleSidebar(); });
    }
    if (backdrop) backdrop.addEventListener("click", function () { toggleSidebar(false); });

    var sidebar = byId("app-sidebar");
    if (sidebar) {
      sidebar.addEventListener("click", function (event) {
        if (window.matchMedia("(max-width: 900px)").matches && event.target.closest("a.sidebar-link")) {
          toggleSidebar(false);
        }
      });
    }

    window.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        toggleSidebar(false);
        closeUserMenu(userBtn, userMenu);
      }
    });
  }

  function createDecisionDialog() {
    var dialog = document.createElement("dialog");
    dialog.className = "app-dialog";
    dialog.setAttribute("aria-labelledby", "app-dialog-title");
    dialog.setAttribute("aria-describedby", "app-dialog-message");

    var form = document.createElement("form");
    form.method = "dialog";
    form.className = "app-dialog-shell";
    form.noValidate = true;

    var kicker = document.createElement("p");
    kicker.className = "app-dialog-kicker";
    kicker.textContent = "Confirm action";

    var title = document.createElement("h2");
    title.className = "app-dialog-title";
    title.id = "app-dialog-title";
    title.textContent = "Continue?";

    var message = document.createElement("p");
    message.className = "app-dialog-message";
    message.id = "app-dialog-message";

    var field = document.createElement("div");
    field.className = "app-dialog-field";
    field.dataset.passwordField = "1";
    field.hidden = true;

    var passwordLabel = document.createElement("label");
    passwordLabel.htmlFor = "app-dialog-password";
    passwordLabel.textContent = "Current password";

    var passwordInput = document.createElement("input");
    passwordInput.id = "app-dialog-password";
    passwordInput.type = "password";
    passwordInput.autocomplete = "current-password";
    passwordInput.spellcheck = false;

    field.appendChild(passwordLabel);
    field.appendChild(passwordInput);

    var actions = document.createElement("div");
    actions.className = "app-dialog-actions";

    var cancel = document.createElement("button");
    cancel.className = "app-dialog-btn app-dialog-cancel";
    cancel.value = "cancel";
    cancel.type = "submit";
    cancel.textContent = "Cancel";

    var confirm = document.createElement("button");
    confirm.className = "app-dialog-btn app-dialog-confirm";
    confirm.value = "confirm";
    confirm.type = "submit";
    confirm.textContent = "Continue";

    actions.appendChild(cancel);
    actions.appendChild(confirm);
    form.appendChild(kicker);
    form.appendChild(title);
    form.appendChild(message);
    form.appendChild(field);
    form.appendChild(actions);
    dialog.appendChild(form);
    document.body.appendChild(dialog);
    return dialog;
  }

  var sharedDecisionDialog = null;

  function requestDecision(options) {
    options = options || {};
    if (!sharedDecisionDialog || !sharedDecisionDialog.isConnected) {
      sharedDecisionDialog = createDecisionDialog();
    }

    var dialog = sharedDecisionDialog;
    var title = dialog.querySelector(".app-dialog-title");
    var message = dialog.querySelector(".app-dialog-message");
    var field = dialog.querySelector("[data-password-field]");
    var passwordInput = dialog.querySelector("#app-dialog-password");
    var confirmBtn = dialog.querySelector(".app-dialog-confirm");
    var cancelBtn = dialog.querySelector(".app-dialog-cancel");
    var previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    var needsPassword = Boolean(options.requirePassword);

    dialog.dataset.tone = options.tone === "danger" ? "danger" : "default";
    title.textContent = String(options.title || "Confirm action");
    message.textContent = String(options.message || "Please confirm that you want to continue.");
    field.hidden = !needsPassword;
    passwordInput.value = "";
    confirmBtn.textContent = String(options.confirmLabel || "Continue");
    cancelBtn.textContent = String(options.cancelLabel || "Cancel");

    return new Promise(function (resolve) {
      function finish(result) {
        dialog.removeEventListener("close", handleClose);
        if (previousFocus && previousFocus.isConnected) previousFocus.focus();
        resolve(result);
      }

      function handleClose() {
        var confirmed = dialog.returnValue === "confirm";
        if (!confirmed) {
          finish({ confirmed: false, password: "" });
          return;
        }
        var password = needsPassword ? passwordInput.value : "";
        if (needsPassword && !password) {
          dialog.returnValue = "";
          dialog.showModal();
          passwordInput.focus();
          return;
        }
        finish({ confirmed: true, password: password });
      }

      dialog.addEventListener("close", handleClose);
      dialog.showModal();
      window.requestAnimationFrame(function () {
        if (needsPassword) passwordInput.focus();
        else cancelBtn.focus();
      });
    });
  }

  function wireSafeForms() {
    document.addEventListener("submit", function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) return;

      if (form.dataset.dialogApproved === "1") {
        delete form.dataset.dialogApproved;
        return;
      }

      var submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
      var confirmMessage = String(
        (submitter && submitter.dataset && submitter.dataset.confirm) || form.dataset.confirm || ""
      ).trim();
      var passwordPrompt = String(form.dataset.passwordPrompt || "").trim();
      var alreadyHasPassword = Boolean(form.querySelector('input[name="current_password"]'));
      var needsPassword = Boolean(passwordPrompt && !alreadyHasPassword);

      if (!confirmMessage && !needsPassword) return;
      event.preventDefault();

      var destructive = Boolean(
        (submitter && submitter.dataset && submitter.dataset.tone === "danger") ||
        (submitter && /delete|revoke|disable|remove|reject/i.test(String(submitter.textContent || "")))
      );

      requestDecision({
        title: destructive ? "Confirm sensitive action" : "Confirm action",
        message: confirmMessage || passwordPrompt || "Please confirm that you want to continue.",
        requirePassword: needsPassword,
        tone: destructive ? "danger" : "default",
        confirmLabel: destructive ? "Confirm" : "Continue"
      }).then(function (result) {
        if (!result.confirmed) return;

        if (needsPassword) {
          var password = result.password;
          var input = document.createElement("input");
          input.type = "hidden";
          input.name = "current_password";
          input.value = password;
          form.appendChild(input);
        }

        form.dataset.dialogApproved = "1";
        if (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) {
          form.requestSubmit(submitter);
        } else {
          form.requestSubmit();
        }
      });
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
    toast.setAttribute("role", options.type === "error" ? "alert" : "status");

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
    close.setAttribute("aria-label", "Dismiss notification");
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
