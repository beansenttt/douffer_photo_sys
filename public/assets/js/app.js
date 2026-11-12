/*
 * Douffer PhotoSys: core UI behaviour (vanilla JS, no libraries).
 * Everything here is progressive enhancement: pages still work without it.
 *  - toasts (auto-hide, dismiss), DPS.toast(msg)
 *  - bottom sheets  (data-sheet-open="id", data-sheet-close)
 *  - show/hide password (data-toggle-password="inputId")
 *  - copy to clipboard (data-copy="elementId")
 *  - DPS.post(url, data): fetch POST with the CSRF token
 */
(function () {
  'use strict';

  var DPS = window.DPS = window.DPS || {};
  var meta = function (name) {
    var m = document.querySelector('meta[name="' + name + '"]');
    return m ? m.getAttribute('content') : '';
  };
  DPS.base = meta('base-path');
  DPS.csrf = meta('csrf-token');
  DPS.url = function (path) { return DPS.base + path; };

  // ---- Toasts ----------------------------------------------------------
  function hideLater(el, ms) {
    setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, ms);
  }
  DPS.toast = function (message, type) {
    var region = document.getElementById('toast-region');
    if (!region) { return; }
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    var s = document.createElement('span');
    s.textContent = message; // textContent: never inject HTML
    t.appendChild(s);
    region.appendChild(t);
    hideLater(t, 4000);
  };
  document.querySelectorAll('.toast').forEach(function (t) {
    if (!t.classList.contains('toast-error')) { hideLater(t, 5000); }
  });

  // ---- Sheets ----------------------------------------------------------
  var lastFocus = null;
  function openSheet(id) {
    var sheet = document.getElementById(id);
    if (!sheet) { return; }
    lastFocus = document.activeElement;
    sheet.classList.add('open');
    var focusable = sheet.querySelector('input, select, textarea, button, a[href]:not(.sheet-backdrop)');
    if (focusable) { focusable.focus(); }
  }
  function closeSheets() {
    document.querySelectorAll('.sheet.open').forEach(function (s) { s.classList.remove('open'); });
    if (document.querySelector('.sheet:target')) {
      history.replaceState(null, '', location.pathname + location.search);
    }
    if (lastFocus) { lastFocus.focus(); lastFocus = null; }
  }
  DPS.openSheet = openSheet;
  DPS.closeSheets = closeSheets;

  // ---- One delegated click handler --------------------------------------
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-sheet-open], [data-sheet-close], [data-toggle-password], [data-copy], [data-dismiss], [data-print]');
    if (!el) { return; }

    if (el.hasAttribute('data-sheet-open')) {
      ev.preventDefault();
      openSheet(el.getAttribute('data-sheet-open'));
    } else if (el.hasAttribute('data-sheet-close')) {
      ev.preventDefault();
      closeSheets();
    } else if (el.hasAttribute('data-toggle-password')) {
      var input = document.getElementById(el.getAttribute('data-toggle-password'));
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      el.textContent = show ? 'Hide' : 'Show';
      el.setAttribute('aria-pressed', show ? 'true' : 'false');
    } else if (el.hasAttribute('data-copy')) {
      var src = document.getElementById(el.getAttribute('data-copy'));
      if (src) { copyText(src.value !== undefined && src.tagName !== 'DIV' ? src.value : src.innerText); }
    } else if (el.hasAttribute('data-dismiss')) {
      var toast = el.closest('.toast');
      if (toast) { toast.parentNode.removeChild(toast); }
    } else if (el.hasAttribute('data-print')) {
      ev.preventDefault();
      window.print();
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { closeSheets(); }
  });

  // ---- Clipboard (with fallback for older phones) -----------------------
  function copyText(text) {
    function done() { DPS.toast('Copied ✓'); }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else {
      fallback();
    }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { DPS.toast('Please copy the text manually.', 'error'); }
      document.body.removeChild(ta);
    }
  }
  DPS.copyText = copyText;

  // ---- Prevent double submit: <form data-once> disables its buttons after the first submit
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.hasAttribute || !form.hasAttribute('data-once') || ev.defaultPrevented) { return; }
    setTimeout(function () {
      form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; b.textContent = 'Please wait…'; });
    }, 0);
  });
  // Back/forward cache: re-enable buttons when the page is shown again
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) { return; }
    document.querySelectorAll('form[data-once] button[type=submit]').forEach(function (b) { b.disabled = false; });
  });

  // ---- fetch helper ------------------------------------------------------
  DPS.post = function (path, data) {
    var body = data instanceof FormData ? data : new URLSearchParams(data || {});
    return fetch(DPS.url(path), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': DPS.csrf, 'Accept': 'application/json' },
      body: body
    }).then(function (r) { return r.json(); });
  };
  DPS.getJSON = function (path) {
    return fetch(DPS.url(path), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); });
  };

  // ---- Global Admin Unread Badge Polling (§8.3) --------------------------
  if (document.body && document.body.hasAttribute('data-poll-unread')) {
    var baseDocTitle = document.title.replace(/^\(\d+\)\s*/, '');
    var pollUnread = function () {
      if (document.visibilityState === 'hidden' || navigator.onLine === false) { return; }
      DPS.getJSON('/api/unread').then(function (res) {
        if (!res || !res.ok) { return; }
        var n = parseInt(res.unread || 0, 10);
        document.querySelectorAll('[data-unread-badge]').forEach(function (b) {
          b.textContent = String(n);
          b.hidden = !(n > 0);
        });
        if (!document.querySelector('[data-chat-thread]')) {
          document.title = n > 0 ? '(' + n + ') ' + baseDocTitle : baseDocTitle;
        }
      }).catch(function () {});
    };
    setInterval(pollUnread, 25000);
  }

  // ---- PWA-lite Service Worker Registration (§5, Scenario T12) -----------
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(DPS.url('/sw.js')).catch(function () {});
    });
  }
})();
