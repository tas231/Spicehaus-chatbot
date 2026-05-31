(function () {
  'use strict';

  var cfg      = window.SpicehausChatbot || {};
  var ajaxUrl  = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
  var nonce    = cfg.nonce   || '';
  var i18n     = cfg.i18n    || {};

  var history   = [];
  var isOpen    = false;
  var isLoading = false;

  /* ── Scanner state ──────────────────────────────────────────── */

  var scanActive   = false;
  var scanStream   = null;
  var scanDetector = null;
  var scanTimer    = null;

  /* ── Widget HTML ────────────────────────────────────────────── */

  function buildWidget() {
    var root = document.getElementById('spicehaus-chatbot-root');
    if (!root) return;

    root.innerHTML =
      '<button class="sc-toggle" id="sc-toggle" aria-label="Open recipe chatbot">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' +
        '</svg>' +
      '</button>' +

      '<div class="sc-window" id="sc-window" aria-hidden="true" role="dialog" aria-label="Recipe chatbot">' +
        '<div class="sc-header">' +
          '<div class="sc-header-info">' +
            '<span class="sc-header-icon">🌶️</span>' +
            '<div>' +
              '<strong class="sc-header-title">' + esc(i18n.title    || 'Recipe Assistant')             + '</strong>' +
              '<span  class="sc-header-sub">'   + esc(i18n.subtitle || 'Recipes with our spices')      + '</span>' +
            '</div>' +
          '</div>' +
          '<button class="sc-close" id="sc-close" aria-label="Close chatbot">✕</button>' +
        '</div>' +

        '<div class="sc-messages" id="sc-messages">' +
          '<div class="sc-msg sc-msg--bot">' +
            '<div class="sc-bubble">' +
              esc(i18n.greeting || 'Hello! Ask me for a recipe or scan a barcode 📷 to get ideas using our Spicehaus products. 🌿') +
            '</div>' +
          '</div>' +
        '</div>' +

        '<div class="sc-composer">' +
          '<button class="sc-scan-btn" id="sc-scan-btn" aria-label="Scan barcode" title="Scan product barcode">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
              '<rect x="3" y="7" width="5" height="10"/>' +
              '<rect x="10" y="7" width="2" height="10"/>' +
              '<rect x="14" y="7" width="1" height="10"/>' +
              '<rect x="17" y="7" width="4" height="10"/>' +
              '<line x1="1" y1="5" x2="1" y2="2"/><line x1="1" y1="2" x2="4" y2="2"/>' +
              '<line x1="23" y1="5" x2="23" y2="2"/><line x1="23" y1="2" x2="20" y2="2"/>' +
              '<line x1="1" y1="19" x2="1" y2="22"/><line x1="1" y1="22" x2="4" y2="22"/>' +
              '<line x1="23" y1="19" x2="23" y2="22"/><line x1="23" y1="22" x2="20" y2="22"/>' +
            '</svg>' +
          '</button>' +
          '<textarea id="sc-input" class="sc-input" rows="1" ' +
            'placeholder="' + esc(i18n.placeholder || 'e.g. "What can I make with turmeric?"') + '" ' +
            'aria-label="Type your message"></textarea>' +
          '<button class="sc-send" id="sc-send" aria-label="Send message">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
              '<line x1="22" y1="2" x2="11" y2="13"/>' +
              '<polygon points="22 2 15 22 11 13 2 9 22 2"/>' +
            '</svg>' +
          '</button>' +
        '</div>' +
      '</div>';

    bindEvents();
  }

  /* ── Events ─────────────────────────────────────────────────── */

  function bindEvents() {
    document.getElementById('sc-toggle').addEventListener('click', toggleWindow);
    document.getElementById('sc-close').addEventListener('click', closeWindow);
    document.getElementById('sc-send').addEventListener('click', sendMessage);
    document.getElementById('sc-scan-btn').addEventListener('click', openScanner);

    var input = document.getElementById('sc-input');
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    input.addEventListener('input', autoResize);
  }

  function toggleWindow() { isOpen ? closeWindow() : openWindow(); }

  function openWindow() {
    isOpen = true;
    document.getElementById('sc-window').classList.add('sc-window--open');
    document.getElementById('sc-window').setAttribute('aria-hidden', 'false');
    document.getElementById('sc-toggle').classList.add('sc-toggle--open');
    setTimeout(function () { document.getElementById('sc-input').focus(); }, 150);
  }

  function closeWindow() {
    closeScanner();
    isOpen = false;
    document.getElementById('sc-window').classList.remove('sc-window--open');
    document.getElementById('sc-window').setAttribute('aria-hidden', 'true');
    document.getElementById('sc-toggle').classList.remove('sc-toggle--open');
  }

  function autoResize() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
  }

  /* ── Chat send / receive ────────────────────────────────────── */

  function sendMessage() {
    if (isLoading) return;
    var input   = document.getElementById('sc-input');
    var message = input.value.trim();
    if (!message) return;

    input.value        = '';
    input.style.height = 'auto';

    dispatchMessage(message, null);
  }

  // displayText is what appears in the user bubble (null = same as message)
  function dispatchMessage(message, displayText) {
    appendMessage('user', displayText !== null ? displayText : message);
    history.push({ role: 'user', content: message });

    var loadingEl = appendLoading();
    isLoading = true;
    setSend(false);

    var fd = new FormData();
    fd.append('action',  'spicehaus_chat');
    fd.append('nonce',   nonce);
    fd.append('message', message);
    fd.append('history', JSON.stringify(history.slice(0, -1)));

    fetch(ajaxUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        loadingEl.remove();
        if (data.success) {
          var reply = data.data.reply;
          appendMessage('bot', reply);
          history.push({ role: 'assistant', content: reply });
          if (history.length > 20) history = history.slice(-20);
        } else {
          appendMessage('bot', i18n.error || 'Something went wrong. Please try again.');
        }
      })
      .catch(function () {
        loadingEl.remove();
        appendMessage('bot', i18n.error || 'Something went wrong. Please try again.');
      })
      .finally(function () {
        isLoading = false;
        setSend(true);
        var inp = document.getElementById('sc-input');
        if (inp) inp.focus();
      });
  }

  /* ── Barcode scanner ────────────────────────────────────────── */

  function canScan() {
    return typeof BarcodeDetector !== 'undefined' && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }

  function openScanner() {
    if (scanActive) return;
    scanActive = true;

    var win = document.getElementById('sc-window');

    var overlay = document.createElement('div');
    overlay.id        = 'sc-scanner';
    overlay.className = 'sc-scanner';
    overlay.innerHTML =
      '<div class="sc-scanner-hd">' +
        '<span>' + esc(i18n.scanTitle || 'Scan a barcode') + '</span>' +
        '<button class="sc-scanner-x" id="sc-scanner-x" aria-label="Close scanner">✕</button>' +
      '</div>' +
      '<div class="sc-scanner-cam" id="sc-scanner-cam">' +
        '<video id="sc-scanner-vid" playsinline autoplay muted></video>' +
        '<div class="sc-scanner-aim"></div>' +
        '<p class="sc-scanner-hint" id="sc-scanner-hint">' + esc(i18n.scanHint || 'Point camera at a barcode...') + '</p>' +
      '</div>' +
      '<div class="sc-scanner-foot">' +
        '<span class="sc-scanner-or">' + esc(i18n.scanManual || 'Or enter barcode manually:') + '</span>' +
        '<div class="sc-scanner-row">' +
          '<input id="sc-barcode-input" class="sc-barcode-input" type="text" inputmode="numeric" ' +
            'placeholder="' + esc(i18n.scanPlaceholder || '4000417025005') + '" />' +
          '<button class="sc-barcode-go" id="sc-barcode-go">→</button>' +
        '</div>' +
      '</div>';

    win.appendChild(overlay);

    document.getElementById('sc-scanner-x').addEventListener('click', closeScanner);
    document.getElementById('sc-barcode-go').addEventListener('click', function () {
      var val = document.getElementById('sc-barcode-input').value.trim();
      if (val) handleBarcode(val);
    });
    document.getElementById('sc-barcode-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        var val = this.value.trim();
        if (val) handleBarcode(val);
      }
    });

    if (!canScan()) {
      document.getElementById('sc-scanner-cam').style.display = 'none';
      setHint(i18n.scanNoSupport || 'Live scanning requires Chrome or Safari 17.4+. Enter the barcode below.');
      return;
    }

    startCamera();
  }

  function startCamera() {
    navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
    }).then(function (stream) {
      if (!scanActive) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }

      scanStream = stream;
      var vid = document.getElementById('sc-scanner-vid');
      if (!vid) return;
      vid.srcObject = stream;

      vid.addEventListener('loadeddata', function () {
        setHint(i18n.scanHint || 'Point camera at a barcode...');
        startDetection(vid);
      });

    }).catch(function () {
      setHint(i18n.scanNoCam || 'Camera access denied. Enter the barcode manually.');
      var cam = document.getElementById('sc-scanner-cam');
      if (cam) cam.style.display = 'none';
    });
  }

  function startDetection(vid) {
    try {
      scanDetector = new BarcodeDetector({
        formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39']
      });
    } catch (e) {
      setHint(i18n.scanNoSupport || 'Barcode detection not available. Enter manually.');
      return;
    }

    scanTimer = setInterval(function () {
      if (!scanActive || !vid || vid.readyState < 2) return;
      scanDetector.detect(vid).then(function (results) {
        if (results.length > 0) {
          handleBarcode(results[0].rawValue);
        }
      }).catch(function () {});
    }, 400);
  }

  function closeScanner() {
    if (!scanActive) return;
    scanActive = false;

    if (scanTimer)  { clearInterval(scanTimer); scanTimer = null; }
    if (scanStream) { scanStream.getTracks().forEach(function (t) { t.stop(); }); scanStream = null; }
    scanDetector = null;

    var el = document.getElementById('sc-scanner');
    if (el) el.remove();
  }

  function handleBarcode(barcode) {
    closeScanner();

    var lookupEl = appendMessage('bot', i18n.scanLookingUp || '🔍 Looking up product...');

    var fd = new FormData();
    fd.append('action',  'spicehaus_barcode');
    fd.append('nonce',   nonce);
    fd.append('barcode', barcode);

    fetch(ajaxUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        lookupEl.remove();

        var productName, displayText, fullMessage;

        if (data.success && data.data.name) {
          productName  = data.data.name;
          displayText  = (i18n.scanFound    || '📦 Scanned: ') + productName;
          fullMessage  = 'Scanned: ' + productName + '. Please suggest a recipe featuring this as the main ingredient and using Spicehaus spices and products from the catalog.';
        } else {
          displayText  = (i18n.scanNotFound || '📦 Scanned barcode: ') + barcode;
          fullMessage  = 'Scanned barcode: ' + barcode + '. I\'m not sure what this product is, but please suggest a recipe I could make with it using Spicehaus spices and products.';
        }

        dispatchMessage(fullMessage, displayText);
      })
      .catch(function () {
        lookupEl.remove();
        var displayText = (i18n.scanNotFound || '📦 Scanned barcode: ') + barcode;
        var fullMessage = 'Scanned barcode: ' + barcode + '. Please suggest a recipe using Spicehaus spices and products.';
        dispatchMessage(fullMessage, displayText);
      });
  }

  function setHint(text) {
    var el = document.getElementById('sc-scanner-hint');
    if (el) el.textContent = text;
  }

  /* ── DOM helpers ────────────────────────────────────────────── */

  function appendMessage(role, text) {
    var wrap   = document.createElement('div');
    wrap.className = 'sc-msg sc-msg--' + (role === 'user' ? 'user' : 'bot');

    var bubble = document.createElement('div');
    bubble.className = 'sc-bubble';

    if (role === 'user') {
      bubble.textContent = text;
    } else {
      bubble.innerHTML = formatBot(text);
    }

    wrap.appendChild(bubble);
    msgArea().appendChild(wrap);
    scrollBottom();
    return wrap;
  }

  function appendLoading() {
    var wrap = document.createElement('div');
    wrap.className = 'sc-msg sc-msg--bot';
    wrap.innerHTML = '<div class="sc-bubble sc-bubble--loading"><span></span><span></span><span></span></div>';
    msgArea().appendChild(wrap);
    scrollBottom();
    return wrap;
  }

  function msgArea()    { return document.getElementById('sc-messages'); }
  function scrollBottom() { var el = msgArea(); if (el) el.scrollTop = el.scrollHeight; }
  function setSend(on)  { var b = document.getElementById('sc-send'); if (b) b.disabled = !on; }

  /* ── Text formatting ────────────────────────────────────────── */

  function formatBot(text) {
    var html = esc(text);

    // **bold**
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    // _italic_
    html = html.replace(/\b_(.+?)_\b/g, '<em>$1</em>');

    // "Product Name: https://..." → clickable link
    html = html.replace(
      /([^:\n<]+):\s*(https?:\/\/[^\s<&"]+)/g,
      '$1: <a href="$2" target="_blank" rel="noopener noreferrer">$2</a>'
    );

    // Bare URLs not already linked
    html = html.replace(
      /(?<![="'(])https?:\/\/([^\s<&"]+)/g,
      '<a href="https://$1" target="_blank" rel="noopener noreferrer">https://$1</a>'
    );

    // Numbered list items
    html = html.replace(/^(\d+\.\s)/gm, '<br>$1');

    // Bullet items
    html = html.replace(/^[-•★]\s/gm, '<br>• ');

    // Paragraph breaks
    html = html.replace(/\n\n+/g, '</p><p>');
    html = html.replace(/\n/g,    '<br>');

    return '<p>' + html + '</p>';
  }

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* ── Init ───────────────────────────────────────────────────── */

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buildWidget);
  } else {
    buildWidget();
  }

})();
