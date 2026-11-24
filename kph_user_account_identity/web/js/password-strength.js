// ==========================================================
// KameraPH UI Kit  |  password-strength.js
// Shows requirement checklist + Weak/Medium/Strong meter for NEW password
// fields marked [data-password-strength], and a match check for
// [data-password-confirm="<selector>"] fields.
// The PHP backend (Validator::password) is the final authority.
// ==========================================================
(function () {
  function analyze(v) {
    var checks = {
      length: v.length >= 8,
      upper: /[A-Z]/.test(v),
      lower: /[a-z]/.test(v),
      number: /\d/.test(v),
      special: /[^A-Za-z0-9]/.test(v)
    };
    var score = 0;
    for (var k in checks) if (checks[k]) score++;
    var level = score <= 2 ? "Weak" : (score <= 4 ? "Medium" : "Strong");
    return { checks: checks, score: score, level: level };
  }

  function setupStrength(input) {
    var field = input.closest(".field") || input.parentNode;
    var meter = field.querySelector(".pw-meter");
    var label = field.querySelector(".pw-strength-label");
    var reqs = field.querySelectorAll(".pw-requirements li[data-req]");
    if (!meter) return;

    function update() {
      var a = analyze(input.value);
      var bars = meter.querySelectorAll("span");
      var lit = Math.ceil((a.score / 5) * bars.length);
      for (var i = 0; i < bars.length; i++) {
        bars[i].className = i < lit ? "on " + a.level.toLowerCase() : "";
      }
      if (label) label.textContent = input.value ? a.level : "";
      for (var r = 0; r < reqs.length; r++) {
        reqs[r].classList.toggle("ok", !!a.checks[reqs[r].getAttribute("data-req")]);
      }
    }

    input.addEventListener("input", update);
    update();
  }

  function setupConfirm(conf) {
    var sel = conf.getAttribute("data-password-confirm");
    if (!sel) return;
    var target = document.querySelector(sel);
    if (!target) return;
    function check() {
      conf.setCustomValidity(conf.value && conf.value !== target.value ? "Passwords do not match." : "");
    }
    conf.addEventListener("input", check);
    target.addEventListener("input", check);
  }

  function init() {
    var inputs = document.querySelectorAll("[data-password-strength]");
    for (var i = 0; i < inputs.length; i++) setupStrength(inputs[i]);
    var confirms = document.querySelectorAll("[data-password-confirm]");
    for (var j = 0; j < confirms.length; j++) setupConfirm(confirms[j]);
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();