/* ==========================================================================
   Escuela Capacita - reclamaciones.js
   Libro de Reclamaciones: in-browser validation and submission to /api.

   Validation here is a convenience, NOT security: anyone can bypass it from
   the console. The validation that counts lives in api/src/Validator.php.
   ========================================================================== */

(function () {
  "use strict";

  var form = document.getElementById("lr-form");
  if (!form) return;

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var PHONE_RE = /^[+]?[0-9()\s.-]{6,25}$/;
  var SUPPORT_MAIL = form.getAttribute("data-support-mail") || "";

  var sending = false;
  var minWait = 6;
  var loadedAt = Date.now();
  var captchaId = null;
  var captchaKey = "";

  /* hCaptcha is optional: the site key comes from api/token.php (empty = off). */
  function renderCaptcha() {
    var box = document.getElementById("lr-captcha");
    if (!box || captchaId !== null || !captchaKey || !window.hcaptcha) return;
    captchaId = window.hcaptcha.render(box, { sitekey: captchaKey, theme: "light" });
  }
  window.lrCaptchaReady = renderCaptcha;

  function loadCaptchaScript() {
    if (document.getElementById("lr-hcaptcha-js")) return;
    var s = document.createElement("script");
    s.id = "lr-hcaptcha-js";
    s.src = "https://js.hcaptcha.com/1/api.js?render=explicit&onload=lrCaptchaReady";
    s.async = true;
    s.defer = true;
    document.head.appendChild(s);
  }

  function resetCaptcha() {
    if (captchaId !== null && window.hcaptcha) window.hcaptcha.reset(captchaId);
  }

  /* Token: requested on page load; binds the submission to this session. */
  function requestToken() {
    fetch("/api/token.php", { credentials: "same-origin", cache: "no-store" })
      .then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .then(function (d) {
        document.getElementById("lr-token").value = d.token || "";
        if (d.min_wait) minWait = d.min_wait;
        loadedAt = Date.now();
        captchaKey = d.captcha_sitekey || "";
        if (captchaKey) {
          loadCaptchaScript();
          renderCaptcha();
        }
      })
      .catch(function () {
        showStatus(
          "error",
          "No pudimos preparar el formulario. Recarga la página" +
            (SUPPORT_MAIL ? " o escríbenos a " + SUPPORT_MAIL : "") + "."
        );
      });
  }

  /* Guardian fields: only when the consumer is a minor. */
  function initMinor() {
    var check = document.getElementById("lr-minor");
    var block = document.getElementById("lr-guardian");
    if (!check || !block) return;

    function sync() {
      block.hidden = !check.checked;
      Array.prototype.forEach.call(block.querySelectorAll("input"), function (input) {
        if (check.checked) {
          input.setAttribute("required", "required");
        } else {
          input.removeAttribute("required");
          input.value = "";
          clearError(input);
        }
      });
    }
    check.addEventListener("change", sync);
    sync();
  }

  function initCounters() {
    Array.prototype.forEach.call(form.querySelectorAll("[data-counter]"), function (area) {
      var out = document.getElementById(area.getAttribute("data-counter"));
      if (!out) return;
      function update() { out.textContent = String(area.value.length); }
      area.addEventListener("input", update);
      update();
    });
  }

  /* Per-field validation */
  function holder(control) {
    return control.closest(".lr-field") || control.closest(".lr-fieldset");
  }

  function setError(control, message) {
    var box = holder(control);
    if (!box) return;
    box.classList.add("has-error");
    control.setAttribute("aria-invalid", "true");
    var out = box.querySelector("[data-error-text]");
    if (out) out.textContent = message;
  }

  function clearError(control) {
    var box = holder(control);
    if (!box) return;
    box.classList.remove("has-error");
    control.removeAttribute("aria-invalid");
  }

  function validateField(control) {
    if (control.type === "radio" || control.type === "checkbox") return true;
    var v = control.value.trim();
    if (!control.hasAttribute("required") && v === "") return true;

    var message = "";
    if (v === "") {
      message = "Este campo es obligatorio.";
    } else if (control.type === "email" && !EMAIL_RE.test(v)) {
      message = "Ingresa un correo válido.";
    } else if (control.type === "tel" && !PHONE_RE.test(v)) {
      message = "Ingresa un teléfono válido.";
    } else if (control.id === "lr-document-number") {
      var type = document.getElementById("lr-document-type").value;
      var digits = v.replace(/[^A-Za-z0-9]/g, "");
      if (type === "DNI" && !/^\d{8}$/.test(digits)) message = "El DNI debe tener 8 dígitos.";
      else if (type === "RUC" && !/^\d{11}$/.test(digits)) message = "El RUC debe tener 11 dígitos.";
      else if (digits.length < 5) message = "El número de documento es demasiado corto.";
    } else if (control.id === "lr-detail" && v.length < 20) {
      message = "Cuéntanos un poco más, con al menos 20 caracteres.";
    } else if (control.id === "lr-request" && v.length < 10) {
      message = "Indica qué solicitas.";
    } else if (control.id === "lr-amount") {
      var n = parseFloat(v.replace(/[^\d.,]/g, "").replace(",", "."));
      if (isNaN(n) || n < 0) message = "El monto no es válido.";
    } else if (v.length < 2) {
      message = "Este campo es demasiado corto.";
    }

    if (message) {
      setError(control, message);
      return false;
    }
    clearError(control);
    return true;
  }

  function validateGroup(name, message) {
    var checked = form.querySelectorAll('[name="' + name + '"]:checked');
    var box = form.querySelector('[data-error-for="' + name + '"]');
    if (checked.length === 0) {
      if (box) {
        box.classList.add("is-visible");
        var out = box.querySelector("[data-error-text]");
        if (out) out.textContent = message;
      }
      return false;
    }
    if (box) box.classList.remove("is-visible");
    return true;
  }

  /* Status banner */
  function showStatus(kind, message) {
    var box = form.querySelector(".lr-status");
    if (!box) return;
    box.classList.remove("lr-status--ok", "lr-status--error");
    box.classList.add("is-visible", "lr-status--" + kind);
    box.setAttribute("role", kind === "error" ? "alert" : "status");
    box.querySelector("[data-status-text]").textContent = message;
  }

  function hideStatus() {
    var box = form.querySelector(".lr-status");
    if (box) box.classList.remove("is-visible");
  }

  /* Submission */
  function initSubmit() {
    var button = document.getElementById("lr-submit");

    Array.prototype.forEach.call(form.querySelectorAll(".lr-control"), function (c) {
      c.addEventListener("blur", function () { validateField(c); });
      c.addEventListener("input", function () {
        var box = holder(c);
        if (box && box.classList.contains("has-error")) validateField(c);
      });
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (sending) return;
      hideStatus();

      var firstInvalid = null;
      Array.prototype.forEach.call(form.querySelectorAll(".lr-control"), function (c) {
        if (c.closest("[hidden]")) return; // hidden guardian block is not validated
        if (!validateField(c) && !firstInvalid) firstInvalid = c;
      });

      var okType = validateGroup("claim_type", "Elige si es un reclamo o una queja.");
      var okTruth = validateGroup("consent_truth", "Debes confirmar que la información es veraz.");
      var okPrivacy = validateGroup("consent_privacy", "Debes aceptar la política de privacidad.");

      if (firstInvalid) {
        firstInvalid.focus();
        showStatus("error", "Revisa los campos marcados.");
        return;
      }
      if (!okType || !okTruth || !okPrivacy) {
        showStatus("error", "Revisa los campos marcados.");
        return;
      }

      // The server rejects too-fast submissions; warn here before burning the token.
      if ((Date.now() - loadedAt) / 1000 < minWait) {
        showStatus("error", "Un momento, estamos preparando el envío. Vuelve a pulsar en unos segundos.");
        return;
      }

      var data = new FormData(form);
      if (captchaKey) {
        var response = captchaId !== null && window.hcaptcha ? window.hcaptcha.getResponse(captchaId) : "";
        if (!response) {
          showStatus("error", "Marca la casilla de verificación para demostrar que eres una persona.");
          return;
        }
        data.set("h-captcha-response", response);
      }

      sending = true;
      button.setAttribute("aria-busy", "true");
      var label = button.textContent;
      button.textContent = "Registrando…";

      fetch("/api/reclamo.php", { method: "POST", credentials: "same-origin", cache: "no-store", body: data })
        .then(function (r) {
          return r.json().then(function (d) { return { status: r.status, data: d }; });
        })
        .then(function (r) {
          var d = r.data || {};
          if (d.ok) {
            showSuccess(d);
            return;
          }
          if (d.errors) {
            Object.keys(d.errors).forEach(function (field) {
              var control = form.querySelector('[name="' + field + '"]');
              if (control) setError(control, d.errors[field]);
            });
          }
          showStatus("error", d.message || "No pudimos registrar tu reclamo. Inténtalo de nuevo.");
          resetCaptcha(); // hCaptcha tokens are single-use
          if (r.status === 419 || r.status === 403) requestToken();
        })
        .catch(function () {
          resetCaptcha();
          showStatus(
            "error",
            "No pudimos conectar con el servidor. Revisa tu conexión" +
              (SUPPORT_MAIL ? " o escríbenos a " + SUPPORT_MAIL : "") + "."
          );
        })
        .finally(function () {
          sending = false;
          button.removeAttribute("aria-busy");
          button.textContent = label;
        });
    });
  }

  function showSuccess(d) {
    var done = document.getElementById("lr-done");
    if (!done) return;
    document.getElementById("lr-claim-number").textContent = d.claim_number || "";
    document.getElementById("lr-done-text").textContent = d.message || "";
    form.hidden = true;
    done.hidden = false;
    done.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function init() {
    requestToken();
    initMinor();
    initCounters();
    initSubmit();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
