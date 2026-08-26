(function () {
  "use strict";

  function setText(node, text, ok) {
    if (!node) return;
    node.textContent = text;
    node.classList.toggle("text-emerald", !!ok);
    node.classList.toggle("text-sky", !!ok);
    node.classList.toggle("text-danger", !ok);
  }

  function rowValue(labelText) {
    var rows = document.querySelectorAll(".sidebar-health-card .health-metric-row");
    for (var i = 0; i < rows.length; i++) {
      var label = rows[i].querySelector("span:first-child");
      if (label && label.textContent.trim() === labelText) return rows[i].querySelector(".metric-val");
    }
    return null;
  }

  function sync() {
    if (!document.querySelector(".sidebar-health-card")) return;
    fetch("/public/admin/health_status.php", {
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" }
    }).then(function (response) {
      if (!response.ok) throw new Error("health HTTP " + response.status);
      return response.json();
    }).then(function (data) {
      var checks = data && data.checks ? data.checks : {};
      var db = checks.database || {};
      var license = checks.license_signer || {};
      var update = checks.update_signer || {};
      var limiter = checks.rate_limiter || {};
      var storage = checks.release_storage || {};

      setText(rowValue("Database"), db.ok ? (db.latency_ms != null ? "Connected · " + db.latency_ms + " ms" : "Connected") : (db.label || "Unavailable"), !!db.ok);
      setText(rowValue("RSA Signer"), license.ok && update.ok ? "License + Update Ready" : "Signer unavailable", !!(license.ok && update.ok));
      setText(rowValue("Rate Limiter"), limiter.ok && storage.ok ? "Active · Storage OK" : "Service degraded", !!(limiter.ok && storage.ok));

      var pill = document.querySelector(".system-status-pill");
      if (pill) {
        pill.lastChild.textContent = data.ok ? "Live · Verified" : "Service Degraded";
        pill.classList.toggle("is-degraded", !data.ok);
      }
      var dot = document.querySelector(".sidebar-health-card .health-indicator-dot");
      if (dot) dot.classList.toggle("is-degraded", !data.ok);
    }).catch(function () {
      setText(rowValue("Database"), "Unavailable", false);
      setText(rowValue("RSA Signer"), "Unknown", false);
      setText(rowValue("Rate Limiter"), "Unknown", false);
      var pill = document.querySelector(".system-status-pill");
      if (pill) {
        pill.lastChild.textContent = "Health unavailable";
        pill.classList.add("is-degraded");
      }
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", sync);
  else sync();
  window.setInterval(sync, 60000);
})();
