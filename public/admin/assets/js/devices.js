(function () {
  "use strict";

  function openDialog(id) {
    var dialog = document.getElementById(id);
    if (!dialog) return;
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
  }

  function closeDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === "function") dialog.close();
    else dialog.removeAttribute("open");
  }

  function start() {
    document.querySelectorAll("[data-open-device-dialog]").forEach(function (button) {
      button.addEventListener("click", function () {
        openDialog(String(button.dataset.openDeviceDialog || ""));
      });
    });

    document.querySelectorAll("[data-close-device-dialog]").forEach(function (button) {
      button.addEventListener("click", function () {
        closeDialog(button.closest("dialog"));
      });
    });

    document.querySelectorAll(".device-dialog").forEach(function (dialog) {
      dialog.addEventListener("click", function (event) {
        if (event.target === dialog) closeDialog(dialog);
      });
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
