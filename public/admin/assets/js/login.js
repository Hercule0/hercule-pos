(function () {
  "use strict";

  document.querySelectorAll("[data-toggle-password]").forEach(function (button) {
    button.addEventListener("click", function () {
      var input = document.getElementById(button.dataset.togglePassword);
      if (!input) return;
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      button.setAttribute("aria-label", show ? "Hide password" : "Show password");
      button.classList.toggle("is-active", show);
      input.focus({ preventScroll: true });
    });
  });

  var form = document.getElementById("login-form");
  var submit = document.getElementById("login-submit");
  if (form && submit) {
    form.addEventListener("submit", function () {
      if (!form.checkValidity()) return;
      submit.disabled = true;
      submit.classList.add("is-loading");
      var label = submit.querySelector("[data-submit-label]");
      if (label) label.textContent = String(submit.dataset.loadingLabel || "Signing in…");
    });
  }
})();
