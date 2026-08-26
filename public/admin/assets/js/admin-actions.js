(function () {
  "use strict";

  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    var confirmMessage = String(form.dataset.confirm || "").trim();
    if (confirmMessage && !window.confirm(confirmMessage)) {
      event.preventDefault();
      return;
    }

    var passwordPrompt = String(form.dataset.passwordPrompt || "").trim();
    if (!passwordPrompt || form.querySelector('input[name="current_password"]')) return;

    var password = window.prompt(passwordPrompt);
    if (password === null || password === "") {
      event.preventDefault();
      return;
    }

    var input = document.createElement("input");
    input.type = "hidden";
    input.name = "current_password";
    input.value = password;
    form.appendChild(input);
  });

  document.addEventListener("change", function (event) {
    var control = event.target;
    if (!(control instanceof HTMLElement) || !control.matches("[data-submit-on-change]")) return;
    var form = control.closest("form");
    if (form) form.requestSubmit();
  });
})();
