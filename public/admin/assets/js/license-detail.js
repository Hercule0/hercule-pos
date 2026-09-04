(function () {
  "use strict";

  function bindDialog(openSelector, dialogId, closeSelector) {
    var dialog = document.getElementById(dialogId);
    var opener = document.querySelector(openSelector);
    if (opener && dialog) {
      opener.addEventListener("click", function () {
        if (typeof dialog.showModal === "function") dialog.showModal();
        else dialog.setAttribute("open", "");
      });
    }

    document.querySelectorAll(closeSelector).forEach(function (button) {
      button.addEventListener("click", function () {
        if (!dialog) return;
        if (typeof dialog.close === "function") dialog.close();
        else dialog.removeAttribute("open");
      });
    });

    if (dialog) {
      dialog.addEventListener("click", function (event) {
        if (event.target !== dialog) return;
        if (typeof dialog.close === "function") dialog.close();
        else dialog.removeAttribute("open");
      });
    }
  }

  function exposeLifecycleAction() {
    var bar = document.querySelector(".license-action-bar");
    if (!bar || bar.querySelector("[data-license-lifecycle-link]")) return;
    var id = new URLSearchParams(window.location.search).get("id");
    if (!id || !/^\d+$/.test(id)) return;

    var link = document.createElement("a");
    link.className = "detail-action";
    link.setAttribute("data-license-lifecycle-link", "");
    link.href = "/public/admin/license_lifecycle.php?id=" + encodeURIComponent(id);
    link.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M7 4v6M17 4v6M6 14h4M14 14h4M6 18h4M14 18h4"/></svg><span>Lifecycle & Multi</span>';
    bar.appendChild(link);
  }

  function normalizeV2VerificationRows() {
    document.querySelectorAll(".verification-row").forEach(function (row) {
      var strong = row.querySelector("strong");
      var status = row.querySelector(".activity-status");
      if (!strong || !status) return;
      var label = String(strong.textContent || "").trim().toLowerCase();
      if (label !== "ok v2") return;
      strong.textContent = "Successful verification";
      status.textContent = "✓";
      status.classList.remove("failed");
      status.classList.add("ok");
    });
  }

  function start() {
    bindDialog("[data-open-renew-dialog]", "renew-dialog", "[data-close-renew-dialog]");
    bindDialog("[data-open-danger-dialog]", "danger-dialog", "[data-close-danger-dialog]");
    exposeLifecycleAction();
    normalizeV2VerificationRows();

    var plan = document.getElementById("renew-plan");
    var custom = document.getElementById("renew-custom-days-row");
    function syncCustom() {
      if (!plan || !custom) return;
      var isCustom = plan.value === "custom";
      custom.hidden = !isCustom;
      var input = custom.querySelector("input");
      if (input) input.required = isCustom;
    }
    if (plan) {
      plan.addEventListener("change", syncCustom);
      syncCustom();
    }

    document.querySelectorAll("[data-copy-value]").forEach(function (button) {
      button.addEventListener("click", function () {
        if (!navigator.clipboard || !navigator.clipboard.writeText) return;
        navigator.clipboard.writeText(String(button.dataset.copyValue || "")).then(function () {
          button.classList.add("copied");
          window.setTimeout(function () { button.classList.remove("copied"); }, 1200);
        }).catch(function () {
          // Clipboard access can be denied by browser policy. Leave the key visible.
        });
      });
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
