/**
 * Form handling for the static site.
 *
 * The original Elementor forms posted to WordPress (PHP). There is no backend here, so every
 * <form data-whatsapp="51XXXXXXXXX"> is validated and turned into a WhatsApp message (wa.me)
 * with the visible label of each field. Same approach as the Quantuk Peru site.
 *
 * window.open() is called synchronously inside the submit handler (user gesture), otherwise
 * Safari/Firefox block the popup. Do not move it into a promise.
 *
 * The listener runs in the capture phase and stops propagation so Elementor Pro's own AJAX
 * form handler (which would POST to admin-ajax.php) never runs.
 */
(function () {
  'use strict';

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var PHONE_RE = /^[+]?[\d\s()-]{7,20}$/;

  function showMessage(form, text, ok, linkUrl, linkText) {
    var box = form.querySelector('.site-form-message');
    if (!box) {
      box = document.createElement('div');
      box.setAttribute('role', 'status');
      form.appendChild(box);
    }
    box.className = 'site-form-message elementor-message ' + (ok ? 'elementor-message-success' : 'elementor-message-danger');
    box.textContent = text + ' ';
    if (linkUrl) {
      var a = document.createElement('a');
      a.href = linkUrl; a.target = '_blank'; a.rel = 'noopener'; a.textContent = linkText;
      box.appendChild(a);
    }
  }

  function labelOf(form, control) {
    var l = control.id && form.querySelector('label[for="' + control.id + '"]');
    return l ? l.textContent.replace('*', '').replace(/\s+/g, ' ').trim() : (control.getAttribute('placeholder') || control.name);
  }

  function validate(form) {
    var first = null, msg = '';
    Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select'), function (c) {
      if (c.type === 'hidden' || c.type === 'file' || c.type === 'submit') return;
      var v = (c.value || '').trim(), bad = '';
      if (c.required && !v) bad = 'Completa el campo "' + labelOf(form, c) + '".';
      else if (v && c.type === 'email' && !EMAIL_RE.test(v)) bad = 'Ingresa un correo válido.';
      else if (v && c.type === 'tel' && !PHONE_RE.test(v)) bad = 'Ingresa un teléfono válido.';
      if (bad && !first) { first = c; msg = bad; }
    });
    if (first) first.focus();
    return msg;
  }

  function buildMessage(form) {
    var title = form.getAttribute('data-form-title') || 'Consulta';
    var lines = ['*' + title + ' desde somosescuelacapacita.com*', ''];
    Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select'), function (c) {
      if (c.type === 'hidden' || c.type === 'submit' || c.type === 'file') return;
      var v = (c.value || '').trim();
      if (v) lines.push('*' + labelOf(form, c) + ':* ' + v);
    });
    if (form.querySelector('input[type="file"]')) lines.push('', '(Adjunto mi CV en esta conversación)');
    return lines.join('\n');
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.hasAttribute || !form.hasAttribute('data-whatsapp')) return;
    e.preventDefault();
    e.stopImmediatePropagation();

    var error = validate(form);
    if (error) { showMessage(form, error, false); return; }

    var phone = form.getAttribute('data-whatsapp').replace(/\D/g, '');
    var url = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(buildMessage(form));
    var win = window.open(url, '_blank');
    if (win) {
      win.opener = null;
      showMessage(form, 'Abrimos WhatsApp con tu mensaje listo. Solo falta presionar enviar en la conversación.', true);
      form.reset();
    } else {
      showMessage(form, 'Tu navegador bloqueó la ventana emergente.', false, url, 'Abrir WhatsApp');
    }
  }, true);
})();
