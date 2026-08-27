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

  function start() {
    bindDialog("[data-open-renew-dialog]", "renew-dialog", "[data-close-renew-dialog]");
    bindDialog("[data-open-danger-dialog]", "danger-dialog", "[data-close-danger-dialog]");

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
