(function () {
  "use strict";

  var dialog = document.getElementById("license-dialog");

  document.querySelectorAll("[data-open-license-dialog]").forEach(function (button) {
    button.addEventListener("click", function () {
      if (button.disabled || !dialog) return;
      if (typeof dialog.showModal === "function") dialog.showModal();
      else dialog.setAttribute("open", "");
    });
  });

  document.querySelectorAll("[data-close-license-dialog]").forEach(function (button) {
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

  var plan = document.getElementById("plan-select");
  var customRow = document.getElementById("custom-days-row");
  function syncCustomDays() {
    if (!plan || !customRow) return;
    customRow.hidden = plan.value !== "custom";
    var input = customRow.querySelector("input");
    if (input) input.required = plan.value === "custom";
  }
  if (plan) {
    plan.addEventListener("change", syncCustomDays);
    syncCustomDays();
  }

  document.querySelectorAll("[data-copy-key]").forEach(function (button) {
    button.addEventListener("click", function () {
      var value = String(button.dataset.copyKey || "");
      if (!value || !navigator.clipboard) return;
      navigator.clipboard.writeText(value).then(function () {
        button.classList.add("copied");
        window.setTimeout(function () { button.classList.remove("copied"); }, 1000);
      }).catch(function () {});
    });
  });
})();
