(function () {
  "use strict";

  document.querySelectorAll("[data-open-recovery-dialog]").forEach(function (button) {
    button.addEventListener("click", function () {
      var dialog = document.getElementById(button.dataset.openRecoveryDialog);
      if (!dialog) return;
      if (typeof dialog.showModal === "function") dialog.showModal();
      else dialog.setAttribute("open", "");
    });
  });

  document.querySelectorAll("[data-close-recovery-dialog]").forEach(function (button) {
    button.addEventListener("click", function () {
      var dialog = button.closest("dialog");
      if (!dialog) return;
      if (typeof dialog.close === "function") dialog.close();
      else dialog.removeAttribute("open");
    });
  });

  document.querySelectorAll(".recovery-dialog").forEach(function (dialog) {
    dialog.addEventListener("click", function (event) {
      if (event.target !== dialog) return;
      if (typeof dialog.close === "function") dialog.close();
      else dialog.removeAttribute("open");
    });
  });

  var requestedId = new URLSearchParams(window.location.search).get("request_id");
  if (requestedId && /^\d+$/.test(requestedId)) {
    var requestedDialog = document.getElementById("recovery-dialog-" + requestedId);
    if (requestedDialog) {
      if (typeof requestedDialog.showModal === "function") requestedDialog.showModal();
      else requestedDialog.setAttribute("open", "");
    }
  }

  var cards = Array.from(document.querySelectorAll("[data-recovery-card]"));
  var search = document.getElementById("recovery-search");
  var filters = Array.from(document.querySelectorAll("[data-recovery-filter]"));
  var resultCount = document.getElementById("recovery-result-count");
  var empty = document.getElementById("recovery-search-empty");
  var activeFilter = "all";

  function applyFilters() {
    var query = search ? search.value.trim().toLocaleLowerCase() : "";
    var visible = 0;
    cards.forEach(function (card) {
      var haystack = String(card.dataset.search || "").toLocaleLowerCase();
      var matchesSearch = haystack.includes(query);
      var matchesStatus = activeFilter === "all" || card.dataset.status === activeFilter;
      var show = matchesSearch && matchesStatus;
      card.hidden = !show;
      if (show) visible++;
    });
    if (resultCount) resultCount.textContent = String(visible);
    if (empty) empty.hidden = visible !== 0;
  }

  if (search) search.addEventListener("input", applyFilters);
  filters.forEach(function (button) {
    button.addEventListener("click", function () {
      activeFilter = String(button.dataset.recoveryFilter || "all");
      filters.forEach(function (item) { item.classList.toggle("active", item === button); });
      applyFilters();
    });
  });

  document.querySelectorAll("[data-copy-value]").forEach(function (button) {
    button.addEventListener("click", function () {
      if (!navigator.clipboard) return;
      var value = String(button.dataset.copyValue || "");
      if (!value) return;
      navigator.clipboard.writeText(value).then(function () {
        button.classList.add("copied");
        window.setTimeout(function () { button.classList.remove("copied"); }, 1200);
      }).catch(function () {});
    });
  });
})();
