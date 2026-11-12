/*
 * Douffer PhotoSys: Admin Booking Client Module (A5, A13).
 * Strict Content Security Policy compliant (no inline scripts, no inline styles).
 */
(function () {
  'use strict';

  var DPS = window.DPS = window.DPS || {};

  function initAvailabilityCheck() {
    var inputs = document.querySelectorAll('[data-availability-check]');
    inputs.forEach(function (input) {
      var targetId = input.getAttribute('data-target');
      var excludeId = input.getAttribute('data-exclude');
      var target = document.getElementById(targetId);
      if (!target) return;

      function check() {
        var dateVal = input.value.trim();
        if (!dateVal) {
          target.innerHTML = '';
          return;
        }

        var url = (DPS.url ? DPS.url('/api/availability/check') : ((DPS.base || '') + '/api/availability/check')) + '?date=' + encodeURIComponent(dateVal);
        if (excludeId) {
          url += '&exclude=' + encodeURIComponent(excludeId);
        }

        target.textContent = 'Checking availability...';
        target.className = 'small muted';

        fetch(url)
          .then(function (res) { return res.json(); })
          .then(function (data) {
            target.className = '';
            if (data.ok && data.available) {
              target.className = 'alert alert-success';
              target.textContent = data.message || 'Date is open ✓';
            } else {
              target.className = 'alert alert-warning';
              target.textContent = data.message || 'That date is not open. Please choose another date.';
            }
          })
          .catch(function () {
            target.className = 'small field-error';
            target.textContent = 'Unable to check availability right now.';
          });
      }

      input.addEventListener('change', check);
      if (input.value) {
        check();
      }
    });
  }

  function initDownPaymentToggle() {
    var toggle = document.querySelector('[data-toggle-down-payment]');
    var container = document.getElementById('down-payment-details');
    if (!toggle || !container) return;

    function sync() {
      container.hidden = !toggle.checked;
      var amt = container.querySelector('#new-payment-amount');
      if (amt) {
        if (toggle.checked) {
          amt.setAttribute('required', 'required');
        } else {
          amt.removeAttribute('required');
        }
      }
    }

    toggle.addEventListener('change', sync);
    sync();
  }

  function initChangeModeToggle() {
    var buttons = document.querySelectorAll('[data-change-mode]');
    var reschedulePanel = document.getElementById('panel-reschedule');
    var cancelPanel = document.getElementById('panel-cancel');
    if (!buttons.length || !reschedulePanel || !cancelPanel) return;

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var mode = btn.getAttribute('data-change-mode');
        buttons.forEach(function (b) {
          var active = (b === btn);
          b.classList.toggle('is-active', active);
          b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        if (mode === 'reschedule') {
          reschedulePanel.hidden = false;
          cancelPanel.hidden = true;
        } else {
          reschedulePanel.hidden = true;
          cancelPanel.hidden = false;
        }
      });
    });
  }

  function initPackageSelect() {
    var sel = document.querySelector('[data-package-select]');
    var amountInput = document.getElementById('new-payment-amount');
    if (!sel || !amountInput) return;

    sel.addEventListener('change', function () {
      var opt = sel.options[sel.selectedIndex];
      if (!opt) return;

      var price = parseFloat(opt.getAttribute('data-price') || '0');
      var override = parseFloat(opt.getAttribute('data-override') || '0');

      var down = 0;
      if (override > 0) {
        down = override;
      } else if (price > 0) {
        down = Math.ceil((price * 0.30) / 50) * 50;
      }

      if (down > 0 && (!amountInput.value || parseFloat(amountInput.value) === 0)) {
        amountInput.value = down.toFixed(2);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initAvailabilityCheck();
    initDownPaymentToggle();
    initChangeModeToggle();
    initPackageSelect();
  });
})();
