(function () {
  "use strict";

  function getDialog() {
    return document.getElementById("customer-dialog");
  }

  function openDialog() {
    var dialog = getDialog();
    if (!dialog) return;
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
  }

  function closeDialog() {
    var dialog = getDialog();
    if (!dialog) return;
    if (typeof dialog.close === "function") dialog.close();
    else dialog.removeAttribute("open");
  }

  function start() {
    document.querySelectorAll("[data-open-customer-dialog]").forEach(function (button) {
      button.addEventListener("click", openDialog);
    });
    document.querySelectorAll("[data-close-customer-dialog]").forEach(function (button) {
      button.addEventListener("click", closeDialog);
    });

    var dialog = getDialog();
    if (dialog) {
      dialog.addEventListener("click", function (event) {
        if (event.target === dialog) closeDialog();
      });
    }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
