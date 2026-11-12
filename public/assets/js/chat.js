/*
 * Douffer PhotoSys: in-app chat behaviour (Screen C6 & Screen A19, PROMPT §8.3).
 *  - Light adaptive polling (4.5s visible, 20s hidden/idle, paused offline)
 *  - Optimistic AJAX sending with retry on failure
 *  - Quick-reply chip insertion
 *  - Sent / Seen live indicator updates
 *  - Zero innerHTML with user strings (strict XSS & CSP safety)
 */
(function () {
  'use strict';

  var DPS = window.DPS = window.DPS || {};
  var thread = document.querySelector('[data-chat-thread]');
  if (!thread) { return; }

  var form = document.querySelector('[data-chat-form]');
  var bodyInput = form ? form.querySelector('textarea[name="body"]') : null;
  var fileInput = form ? form.querySelector('[data-chat-file]') : null;
  var previewBox = document.getElementById('receipt-preview');

  var convId = parseInt(thread.getAttribute('data-conv-id') || '0', 10);
  var side = thread.getAttribute('data-side') || 'client';
  var lastId = parseInt(thread.getAttribute('data-last-id') || '0', 10);
  var seenId = parseInt(thread.getAttribute('data-seen-id') || '0', 10);
  var token = thread.getAttribute('data-token') || '';
  var baseTitle = document.title.replace(/^\(\d+\)\s*(New message\s*·\s*)?/, '');
  var hiddenNewCount = 0;

  var emptyPolls = 0;
  var pollTimer = null;
  var isPolling = false;

  function scrollToBottom() {
    thread.scrollTop = thread.scrollHeight;
  }
  scrollToBottom();

  function removeEmptyState() {
    var empty = thread.querySelector('[data-chat-empty]');
    if (empty && empty.parentNode) {
      empty.parentNode.removeChild(empty);
    }
  }

  function createSystemNode(m) {
    var div = document.createElement('div');
    div.className = 'chat-system';
    div.setAttribute('data-msg-id', String(m.id));
    var span = document.createElement('span');
    span.textContent = m.body || '';
    var time = document.createElement('time');
    time.className = 'chat-time';
    time.textContent = m.created_label || m.created_at || '';
    div.appendChild(span);
    div.appendChild(time);
    return div;
  }

  function createMessageNode(m) {
    if (m.sender_type === 'system') {
      return createSystemNode(m);
    }
    var isOwn = (m.sender_type === side);
    var article = document.createElement('article');
    article.className = 'chat-msg ' + (isOwn ? 'is-own' : 'is-other');
    if (m.id) {
      article.setAttribute('data-msg-id', String(m.id));
    }
    article.setAttribute('data-sender', m.sender_type || '');

    var bubble = document.createElement('div');
    bubble.className = 'chat-bubble';

    var sender = document.createElement('div');
    sender.className = 'chat-sender';
    sender.textContent = isOwn ? (side === 'client' ? 'You' : (m.sender_label || 'Studio')) : (m.sender_label || 'Client');
    bubble.appendChild(sender);

    if (m.body && m.body !== '') {
      var p = document.createElement('p');
      p.className = 'chat-text';
      p.textContent = m.body;
      bubble.appendChild(p);
    }

    if (m.attachment_url) {
      var attUrl = m.attachment_url;
      if (token && attUrl.indexOf('token=') === -1) {
        attUrl += (attUrl.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(token);
      }
      var link = document.createElement('a');
      link.className = 'chat-att-link';
      link.href = attUrl;
      link.target = '_blank';
      link.rel = 'noopener';
      var img = document.createElement('img');
      img.className = 'chat-att-img';
      img.src = attUrl;
      img.alt = 'Chat attachment';
      img.loading = 'lazy';
      link.appendChild(img);
      bubble.appendChild(link);
    }

    var meta = document.createElement('div');
    meta.className = 'chat-meta';
    var time = document.createElement('time');
    time.className = 'chat-time';
    time.textContent = m.created_label || m.created_at || 'Just now';
    meta.appendChild(time);

    if (isOwn) {
      var receipt = document.createElement('span');
      receipt.className = 'chat-receipt';
      if (m.id) {
        receipt.setAttribute('data-msg-status', String(m.id));
      }
      receipt.textContent = m.optimistic ? ' · Sending…' : (m.read_at ? ' · Seen' : ' · Sent');
      meta.appendChild(receipt);
    }

    bubble.appendChild(meta);
    article.appendChild(bubble);
    return article;
  }

  function appendMessage(m) {
    if (m.id && thread.querySelector('[data-msg-id="' + m.id + '"]')) {
      return;
    }
    removeEmptyState();
    var node = createMessageNode(m);
    thread.appendChild(node);
    if (m.id && m.id > lastId) {
      lastId = m.id;
      thread.setAttribute('data-last-id', String(lastId));
    }
    scrollToBottom();
  }

  function updateSeenReceipts(upToId) {
    if (!upToId || upToId <= seenId) { return; }
    seenId = upToId;
    thread.setAttribute('data-seen-id', String(seenId));
    thread.querySelectorAll('[data-msg-status]').forEach(function (el) {
      var mid = parseInt(el.getAttribute('data-msg-status') || '0', 10);
      if (mid > 0 && mid <= seenId) {
        el.textContent = ' · Seen';
      }
    });
  }

  // ---- Adaptive Polling --------------------------------------------------
  function nextIntervalMs() {
    if (document.visibilityState === 'hidden' || emptyPolls >= 6) {
      return 20000;
    }
    return 4500;
  }

  function scheduleNextPoll() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    if (navigator.onLine === false) { return; }
    pollTimer = setTimeout(runPoll, nextIntervalMs());
  }

  function runPoll() {
    if (isPolling || navigator.onLine === false || convId <= 0) {
      scheduleNextPoll();
      return;
    }
    isPolling = true;
    var q = '?since_id=' + encodeURIComponent(String(lastId)) + '&as=' + encodeURIComponent(side);
    if (token) {
      q += '&token=' + encodeURIComponent(token);
    }

    DPS.getJSON('/api/conversations/' + convId + '/messages' + q)
      .then(function (res) {
        isPolling = false;
        if (!res || !res.ok) {
          scheduleNextPoll();
          return;
        }
        if (typeof res.seen_up_to_id === 'number') {
          updateSeenReceipts(res.seen_up_to_id);
        }
        if (Array.isArray(res.messages) && res.messages.length > 0) {
          emptyPolls = 0;
          res.messages.forEach(function (m) {
            appendMessage(m);
            if (m.sender_type !== side && document.visibilityState === 'hidden') {
              hiddenNewCount++;
              document.title = '(' + hiddenNewCount + ') New message · ' + baseTitle;
            }
          });
        } else {
          emptyPolls++;
        }
        scheduleNextPoll();
      })
      .catch(function () {
        isPolling = false;
        emptyPolls++;
        scheduleNextPoll();
      });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      hiddenNewCount = 0;
      document.title = baseTitle;
      emptyPolls = 0;
      runPoll();
    }
  });

  window.addEventListener('online', function () {
    emptyPolls = 0;
    runPoll();
  });

  window.addEventListener('offline', function () {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  });

  scheduleNextPoll();

  // ---- Quick Replies -----------------------------------------------------
  document.addEventListener('click', function (ev) {
    var qrBtn = ev.target.closest('[data-quick-reply]');
    if (qrBtn && bodyInput) {
      ev.preventDefault();
      bodyInput.value = qrBtn.getAttribute('data-quick-reply') || '';
      bodyInput.focus();
    }
  });

  // ---- Optimistic AJAX Composer ------------------------------------------
  if (form && bodyInput) {
    bodyInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey && window.innerWidth >= 768) {
        ev.preventDefault();
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
      }
    });

    form.addEventListener('submit', function (ev) {
      if (!window.fetch || !window.FormData) { return; }
      var text = (bodyInput.value || '').trim();
      var hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
      if (text === '' && !hasFile) {
        ev.preventDefault();
        DPS.toast('Please write a message or attach a photo.', 'error');
        bodyInput.focus();
        return;
      }

      ev.preventDefault();
      var fd = new FormData(form);

      removeEmptyState();
      var optNode = createMessageNode({
        id: 0,
        sender_type: side,
        sender_label: side === 'client' ? 'You' : 'Studio',
        body: text || (hasFile ? '[Uploading photo…]' : ''),
        created_label: 'Just now',
        optimistic: true
      });
      thread.appendChild(optNode);
      scrollToBottom();

      bodyInput.value = '';
      if (fileInput) { fileInput.value = ''; }
      if (previewBox) { previewBox.hidden = true; }

      function sendPayload(payload) {
        fetch(DPS.url('/api/conversations/' + convId + '/messages'), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': DPS.csrf, 'Accept': 'application/json' },
          body: payload
        })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (optNode.parentNode) { optNode.parentNode.removeChild(optNode); }
            if (res && res.ok && res.message) {
              emptyPolls = 0;
              appendMessage(res.message);
            } else {
              var errMsg = (res && res.error) ? res.error : 'Could not send message. Please try again.';
              DPS.toast(errMsg, 'error');
              bodyInput.value = text;
            }
          })
          .catch(function () {
            var receipt = optNode.querySelector('.chat-receipt');
            if (receipt) {
              receipt.textContent = ' · Failed to send ';
              var retryBtn = document.createElement('button');
              retryBtn.type = 'button';
              retryBtn.className = 'btn btn-outline btn-small';
              retryBtn.textContent = 'Retry';
              retryBtn.addEventListener('click', function () {
                receipt.textContent = ' · Sending…';
                sendPayload(payload);
              });
              receipt.appendChild(retryBtn);
            }
          });
      }

      sendPayload(fd);
    });
  }
})();
