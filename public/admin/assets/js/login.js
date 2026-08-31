(function () {
  "use strict";

  function setFieldError(input, message) {
    if (!input) return;
    var error = document.getElementById(input.id + "-error");
    input.setAttribute("aria-invalid", message ? "true" : "false");
    if (error) {
      error.textContent = message || "";
      error.classList.toggle("is-visible", Boolean(message));
    }
  }

  function validateField(input) {
    if (!input) return true;
    var value = String(input.value || "").trim();
    if (input.required && !value) {
      var label = input.id === "login-username"
        ? "Enter your username."
        : input.id === "login-password"
          ? "Enter your password."
          : "Enter your authenticator or recovery code.";
      setFieldError(input, label);
      return false;
    }
    setFieldError(input, "");
    return true;
  }

  document.querySelectorAll("[data-toggle-password]").forEach(function (button) {
    button.addEventListener("click", function () {
      var input = document.getElementById(button.dataset.togglePassword);
      if (!input) return;
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      button.setAttribute("aria-label", show ? "Hide password" : "Show password");
      button.setAttribute("aria-pressed", show ? "true" : "false");
      button.classList.toggle("is-active", show);
      input.focus({ preventScroll: true });
    });
  });

  var form = document.getElementById("login-form");
  var submit = document.getElementById("login-submit");
  if (form && submit) {
    var fields = Array.prototype.slice.call(form.querySelectorAll("input[required]"));

    fields.forEach(function (input) {
      input.addEventListener("input", function () {
        if (input.getAttribute("aria-invalid") === "true") validateField(input);
      });
      input.addEventListener("blur", function () {
        validateField(input);
      });
    });

    form.addEventListener("submit", function (event) {
      var firstInvalid = null;
      fields.forEach(function (input) {
        if (!validateField(input) && !firstInvalid) firstInvalid = input;
      });

      if (firstInvalid) {
        event.preventDefault();
        firstInvalid.focus({ preventScroll: true });
        firstInvalid.scrollIntoView({ block: "center", behavior: "smooth" });
        return;
      }

      submit.disabled = true;
      submit.setAttribute("aria-busy", "true");
      submit.classList.add("is-loading");
      var label = submit.querySelector("[data-submit-label]");
      if (label) label.textContent = String(submit.dataset.loadingLabel || "Signing in…");
    });
  }
})();
