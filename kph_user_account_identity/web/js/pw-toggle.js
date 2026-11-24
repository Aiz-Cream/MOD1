// ==========================================================
// KameraPH UI Kit  |  pw-toggle.js
// Adds a show/hide (eye) button beside every password field.
// Does not modify the entered value. Include on any page with a password input.
// ==========================================================
(function () {
  var EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

  function enhance(input) {
    if (!input || input.type !== "password" || input.dataset.pwToggle === "1") return;
    input.dataset.pwToggle = "1";

    var wrap = document.createElement("div");
    wrap.className = "pw-wrap";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "pw-toggle";
    btn.setAttribute("aria-label", "Show password");
    btn.innerHTML = EYE_OFF;
    btn.addEventListener("click", function () {
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
      btn.innerHTML = show ? EYE : EYE_OFF;
    });
    wrap.appendChild(btn);
  }

  function init() {
    var list = document.querySelectorAll('input[type="password"]');
    for (var i = 0; i < list.length; i++) enhance(list[i]);
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();