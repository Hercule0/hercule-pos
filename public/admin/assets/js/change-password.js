(function () {
  "use strict";

  document.querySelectorAll("[data-toggle-password]").forEach(function (button) {
    button.addEventListener("click", function () {
      var input = document.getElementById(button.dataset.togglePassword);
      if (!input) return;
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      button.classList.toggle("showing", show);
      button.setAttribute("aria-label", show ? "Hide password" : "Show password");
    });
  });

  var current = document.getElementById("current-password");
  var next = document.getElementById("new-password");
  var confirm = document.getElementById("confirm-password");
  var bar = document.getElementById("strength-bar");
  var label = document.getElementById("strength-label");
  var match = document.getElementById("match-message");
  if (!current || !next || !confirm || !bar || !label || !match) return;

  function updateStrength() {
    var value = next.value;
    var rules = {
      length: value.length >= 12,
      case: /[a-z]/.test(value) && /[A-Z]/.test(value),
      number: /[0-9]/.test(value),
      symbol: /[^A-Za-z0-9]/.test(value),
      different: value.length > 0 && value !== current.value
    };

    Object.keys(rules).forEach(function (name) {
      var item = document.querySelector('[data-rule="' + name + '"]');
      if (item) item.classList.toggle("valid", rules[name]);
    });

    var score = Object.values(rules).filter(Boolean).length;
    bar.dataset.score = String(score);
    label.textContent = value.length === 0
      ? "Enter a new password"
      : ["Very weak", "Weak", "Fair", "Good", "Strong", "Very strong"][score];

    if (confirm.value.length) {
      var matches = confirm.value === value;
      match.textContent = matches ? "Passwords match" : "Passwords do not match";
      match.className = "match-message " + (matches ? "valid" : "invalid");
    } else {
      match.textContent = "";
      match.className = "match-message";
    }
  }

  [current, next, confirm].forEach(function (input) {
    input.addEventListener("input", updateStrength);
  });
  updateStrength();
})();
