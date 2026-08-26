(function () {
  "use strict";

  function start() {
    var plan = document.getElementById("lifecycle-plan");
    var custom = document.getElementById("lifecycle-custom-days");
    if (!plan || !custom) return;

    function sync() {
      var isCustom = plan.value === "custom";
      custom.hidden = !isCustom;
      var input = custom.querySelector("input");
      if (input) input.required = isCustom;
    }

    plan.addEventListener("change", sync);
    sync();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
