/*
 * Receipt/screenshot upload helper (C4 and C5 re-upload).
 * Shrinks the picture in the browser (max 1600 px, JPEG 0.8) so the phone sends
 * ~200-500 KB instead of 3-8 MB, and shows a small preview.
 * Falls back to the original file if the browser cannot do it. The server
 * still validates everything.
 */
(function () {
  'use strict';
  var input = document.querySelector('input[type=file][data-compress]');
  if (!input) { return; }

  var box = document.getElementById('receipt-preview');
  var img = document.getElementById('receipt-img');
  var info = document.getElementById('receipt-info');
  var MAX = 1600;

  function kb(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  function show(file, note) {
    if (!box || !img) { return; }
    if (img.dataset.url) { URL.revokeObjectURL(img.dataset.url); }
    var url = URL.createObjectURL(file);
    img.dataset.url = url;
    img.src = url;
    info.textContent = note || kb(file.size);
    box.hidden = false;
  }

  function compress(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var im = new Image();
      im.onload = function () {
        URL.revokeObjectURL(url);
        var scale = Math.min(1, MAX / Math.max(im.naturalWidth, im.naturalHeight));
        var w = Math.max(1, Math.round(im.naturalWidth * scale));
        var h = Math.max(1, Math.round(im.naturalHeight * scale));
        var canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';            // flatten transparent PNGs
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(im, 0, 0, w, h);
        if (canvas.toBlob) { canvas.toBlob(function (b) { resolve(b); }, 'image/jpeg', 0.8); } else { resolve(null); }
      };
      im.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
      im.src = url;
    });
  }

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) { if (box) { box.hidden = true; } return; }
    if (!/^image\//.test(file.type)) { return; }   // server will reject non-images with a clear message

    compress(file).then(function (blob) {
      if (!blob || blob.size >= file.size) { show(file); return; }   // keep the original if it is already smaller
      try {
        var out = new File([blob], 'receipt.jpg', { type: 'image/jpeg' });
        var dt = new DataTransfer();
        dt.items.add(out);
        input.files = dt.files;
        show(out, kb(out.size) + ' (was ' + kb(file.size) + ')');
      } catch (e) {
        show(file);                                                  // old browser: send the original
      }
    });
  });
})();
