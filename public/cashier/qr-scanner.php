<?php

/**
 * cashier/qr-scanner.php
 */
require_once __DIR__ . '/../../config/init.php';
requireRole(ROLE_CASHIER);
layoutHeader('QR Scanner', '');
?>

<style>
  .scanner-wrap {
    max-width: 560px;
    margin: 0 auto;
  }

  .video-box {
    position: relative;
    border-radius: var(--radius-md);
    overflow: hidden;
    background: #111;
    box-shadow: var(--shadow-lg, 0 8px 32px rgba(0, 0, 0, 0.25));
    border: 2px solid var(--border-color);
  }

  #qrVideo {
    width: 100%;
    display: block;
    /* DO NOT set height — let it size naturally from the stream */
  }

  /* Scanning overlay */
  .scan-overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .scan-frame {
    width: 55%;
    height: 55%;
    position: relative;
  }

  .scan-frame::before,
  .scan-frame::after,
  .scan-corner-br,
  .scan-corner-bl {
    content: '';
    position: absolute;
    width: 28px;
    height: 28px;
    border-color: rgba(255, 255, 255, 0.9);
    border-style: solid;
  }

  .scan-frame::before {
    top: 0;
    left: 0;
    border-width: 3px 0 0 3px;
    border-radius: 4px 0 0 0;
  }

  .scan-frame::after {
    top: 0;
    right: 0;
    border-width: 3px 3px 0 0;
    border-radius: 0 4px 0 0;
  }

  .scan-corner-br {
    bottom: 0;
    right: 0;
    border-width: 0 3px 3px 0;
    border-radius: 0 0 4px 0;
  }

  .scan-corner-bl {
    bottom: 0;
    left: 0;
    border-width: 0 0 3px 3px;
    border-radius: 0 0 0 4px;
  }

  .scan-line {
    position: absolute;
    left: 5%;
    right: 5%;
    height: 2px;
    background: linear-gradient(90deg, transparent, #4ade80, transparent);
    box-shadow: 0 0 8px rgba(74, 222, 128, 0.8);
    animation: scanline 1.8s ease-in-out infinite;
    display: none;
  }

  @keyframes scanline {
    0% {
      top: 5%;
      opacity: 1;
    }

    50% {
      top: 90%;
      opacity: 1;
    }

    100% {
      top: 5%;
      opacity: 1;
    }
  }

  .camera-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 320px;
    color: #666;
    gap: 12px;
    font-size: 0.85rem;
  }

  .scan-status {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.84rem;
    font-weight: 600;
    margin-top: 12px;
    border: 1px solid var(--border-color);
    background: var(--surface-raised);
    color: var(--text-muted);
    transition: all 0.25s;
  }

  .scan-status.success {
    background: var(--color-success-bg);
    border-color: var(--color-success-border);
    color: var(--color-success);
  }

  .scan-status.error {
    background: var(--color-error-bg);
    border-color: var(--color-error-border);
    color: var(--color-error);
  }

  .scan-status.active {
    background: var(--color-info-bg);
    border-color: var(--color-info-border);
    color: var(--color-info);
  }

  .camera-controls {
    display: flex;
    gap: 8px;
    margin-top: 10px;
  }

  /* Debug panel */
  #debugPanel {
    background: #1a1a1a;
    color: #0f0;
    font-family: monospace;
    font-size: 0.72rem;
    padding: 10px 12px;
    border-radius: 8px;
    margin-top: 10px;
    max-height: 120px;
    overflow-y: auto;
    display: none;
  }

  /* Result card */
  .result-card {
    display: none;
    margin-top: 16px;
    background: var(--surface-color);
    border: 2px solid var(--color-success-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    box-shadow: var(--shadow-md);
  }

  .result-card.show {
    display: block;
  }

  .result-header {
    background: var(--color-success-bg);
    border-bottom: 1px solid var(--color-success-border);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .result-icon {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-full);
    background: var(--color-success);
    color: var(--text-on-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
  }

  .result-body {
    padding: 16px;
  }

  .result-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.84rem;
  }

  .result-row:last-child {
    border-bottom: none;
  }

  .result-row .lbl {
    color: var(--text-muted);
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    flex-shrink: 0;
  }

  .result-row .val {
    font-weight: 700;
    text-align: right;
  }

  .result-total {
    font-size: 1.2rem;
    color: var(--primary-color);
  }

  /* Error card */
  .error-card {
    display: none;
    margin-top: 16px;
    background: var(--color-error-bg);
    border: 2px solid var(--color-error-border);
    border-radius: var(--radius-md);
    padding: 20px;
    text-align: center;
    color: var(--color-error);
  }

  .error-card.show {
    display: block;
  }
</style>

<div class="page-header">
  <div>
    <div class="page-header-title"><i class="fa-solid fa-qrcode"></i> QR Order Scanner</div>
    <div class="page-header-sub">Scan a customer's QR code to claim their order</div>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/cashier/preorders.php" class="btn btn-ghost btn-sm">
      <i class="fa-solid fa-list"></i> View Queue
    </a>
  </div>
</div>

<?php showFlash('global'); ?>

<div class="scanner-wrap">

  <!-- Video box -->
  <div class="video-box" id="videoBox">
    <div class="camera-placeholder" id="placeholder">
      <i class="fa-solid fa-camera" style="font-size:3rem;opacity:.4"></i>
      <span>Press <strong>Start Camera</strong> below</span>
    </div>
    <video id="qrVideo" autoplay playsinline muted style="display:none"></video>
    <canvas id="qrCanvas" style="display:none"></canvas>
    <div class="scan-overlay" id="scanOverlay" style="display:none">
      <div class="scan-frame">
        <div class="scan-corner-br"></div>
        <div class="scan-corner-bl"></div>
        <div class="scan-line" id="scanLine"></div>
      </div>
    </div>
  </div>

  <!-- Status -->
  <div class="scan-status" id="scanStatus">
    <i class="fa-solid fa-circle-dot" id="statusIcon"></i>
    <span id="statusText">Camera not started</span>
  </div>

  <!-- Controls -->
  <div class="camera-controls">
    <button class="btn btn-primary flex-1" id="btnStart" onclick="startCamera()">
      <i class="fa-solid fa-camera"></i> Start Camera
    </button>
    <button class="btn btn-ghost flex-1" id="btnStop" onclick="stopCamera()" style="display:none">
      <i class="fa-solid fa-stop"></i> Stop
    </button>
    <button class="btn btn-ghost btn-sm" onclick="toggleDebug()" title="Debug">
      <i class="fa-solid fa-bug"></i>
    </button>
  </div>

  <!-- Debug panel -->
  <div id="debugPanel"></div>

  <!-- Success result -->
  <div class="result-card" id="resultCard">
    <div class="result-header">
      <div class="result-icon"><i class="fa-solid fa-circle-check"></i></div>
      <div>
        <div style="font-weight:800;font-size:1rem">Order Claimed ✓</div>
        <div style="font-size:0.74rem;color:#166534" id="resultOrderNum"></div>
      </div>
    </div>
    <div class="result-body">
      <div class="result-row"><span class="lbl">Customer</span><span class="val" id="rCustomer"></span></div>
      <div class="result-row"><span class="lbl">ID No.</span><span class="val" id="rCustomerId"></span></div>
      <div class="result-row"><span class="lbl">Items</span><span class="val" id="rItems"></span></div>
      <div class="result-row"><span class="lbl">Total</span><span class="val result-total" id="rTotal"></span></div>
    </div>
    <div style="padding:0 16px 16px">
      <button class="btn btn-primary" style="width:100%" onclick="scanAgain()">
        <i class="fa-solid fa-qrcode"></i> Scan Next Order
      </button>
    </div>
  </div>

  <!-- Error -->
  <div class="error-card" id="errorCard">
    <i class="fa-solid fa-circle-xmark" style="font-size:2rem;margin-bottom:8px;display:block"></i>
    <div style="font-weight:700;margin-bottom:4px" id="errTitle">Error</div>
    <div style="font-size:0.78rem;opacity:.8;margin-bottom:16px" id="errDetail"></div>
    <button class="btn btn-primary" onclick="scanAgain()">
      <i class="fa-solid fa-rotate"></i> Try Again
    </button>
  </div>

</div><!-- /scanner-wrap -->

<!-- jsQR from CDN -->
<script src="<?= APP_URL ?>/../assets/js/jsQR.min.js"></script>
<script>
  const video = document.getElementById('qrVideo');
  const canvas = document.getElementById('qrCanvas');
  const ctx = canvas.getContext('2d', {
    willReadFrequently: true
  });
  const debugPanel = document.getElementById('debugPanel');

  let stream = null;
  let rafId = null;
  let isProcessing = false;
  let lastData = null;
  let cooldown = false;
  let frameCount = 0;
  let debugVisible = false;

  // ── Debug logger ──────────────────────────────────────────────────────────────
  function dbg(msg) {
    if (!debugVisible) return;
    const line = document.createElement('div');
    line.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
    debugPanel.appendChild(line);
    debugPanel.scrollTop = debugPanel.scrollHeight;
    // Keep max 50 lines
    while (debugPanel.children.length > 50) debugPanel.removeChild(debugPanel.firstChild);
  }

  function toggleDebug() {
    debugVisible = !debugVisible;
    debugPanel.style.display = debugVisible ? 'block' : 'none';
  }

  // ── Status helper ─────────────────────────────────────────────────────────────
  function setStatus(type, icon, text) {
    const el = document.getElementById('scanStatus');
    el.className = `scan-status ${type}`;
    document.getElementById('statusIcon').className = `fa-solid ${icon}`;
    document.getElementById('statusText').innerHTML = text;
  }

  // ── Start camera ──────────────────────────────────────────────────────────────
  async function startCamera() {
    setStatus('active', 'fa-spinner fa-spin', 'Requesting camera access…');

    try {
      // Try rear camera first, fall back to any camera
      let constraints = {
        video: {
          facingMode: {
            ideal: 'environment'
          },
          width: {
            ideal: 1280
          }
        }
      };
      try {
        stream = await navigator.mediaDevices.getUserMedia(constraints);
      } catch {
        dbg('Rear camera failed, trying any camera');
        stream = await navigator.mediaDevices.getUserMedia({
          video: true
        });
      }

      video.srcObject = stream;

      // Wait for video metadata + enough data to start playing
      await new Promise((resolve, reject) => {
        video.onloadedmetadata = () => {
          dbg(`Video metadata: ${video.videoWidth}x${video.videoHeight}`);
          video.play().then(resolve).catch(reject);
        };
        video.onerror = reject;
        setTimeout(reject, 10000); // 10s timeout
      });

      // Extra wait to make sure frames are flowing
      await new Promise(r => setTimeout(r, 500));

      dbg(`Video playing: ${video.videoWidth}x${video.videoHeight}, readyState=${video.readyState}`);

      // Show video, hide placeholder
      document.getElementById('placeholder').style.display = 'none';
      video.style.display = 'block';
      document.getElementById('scanOverlay').style.display = 'flex';
      document.getElementById('scanLine').style.display = 'block';
      document.getElementById('btnStart').style.display = 'none';
      document.getElementById('btnStop').style.display = 'inline-flex';

      setStatus('active', 'fa-circle-dot', 'Scanning… point camera at QR code');

      // Start scan loop
      frameCount = 0;
      rafId = requestAnimationFrame(tick);

    } catch (err) {
      dbg(`Camera error: ${err.name} - ${err.message}`);
      let msg = 'Camera access denied. Please allow camera permissions and reload.';
      if (err.name === 'NotFoundError') msg = 'No camera found on this device.';
      if (err.name === 'NotAllowedError') msg = 'Camera permission denied. Allow it in browser settings.';
      if (err.name === 'NotReadableError') msg = 'Camera is in use by another app.';
      setStatus('error', 'fa-camera-slash', msg);
    }
  }

  // ── Stop camera ───────────────────────────────────────────────────────────────
  function stopCamera() {
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = null;
    }
    if (stream) {
      stream.getTracks().forEach(t => t.stop());
      stream = null;
    }

    video.style.display = 'none';
    video.srcObject = null;
    document.getElementById('placeholder').style.display = 'flex';
    document.getElementById('scanOverlay').style.display = 'none';
    document.getElementById('scanLine').style.display = 'none';
    document.getElementById('btnStart').style.display = 'inline-flex';
    document.getElementById('btnStop').style.display = 'none';

    setStatus('', 'fa-circle-dot', 'Camera stopped.');
  }

  // ── Scan tick ─────────────────────────────────────────────────────────────────
  function tick() {
    if (!stream) return;

    frameCount++;

    // Log every 60 frames (~1s) for debug
    if (frameCount % 60 === 0) {
      dbg(`Frame ${frameCount} | readyState=${video.readyState} | size=${video.videoWidth}x${video.videoHeight}`);
    }

    // Need readyState >= 2 (HAVE_CURRENT_DATA) and actual dimensions
    if (video.readyState < 2 || video.videoWidth === 0 || video.videoHeight === 0) {
      rafId = requestAnimationFrame(tick);
      return;
    }

    // Match canvas to video dimensions
    if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) {
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      dbg(`Canvas resized to ${canvas.width}x${canvas.height}`);
    }

    // Draw current frame
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    // Only scan if not already processing
    if (!isProcessing && !cooldown) {
      try {
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const code = jsQR(imageData.data, imageData.width, imageData.height, {
          inversionAttempts: 'attemptBoth' // try both normal and inverted
        });

        if (code && code.data) {
          dbg(`QR FOUND: ${code.data.substring(0, 40)}…`);
          handleScan(code.data);
        }
      } catch (e) {
        dbg(`jsQR error: ${e.message}`);
      }
    }

    rafId = requestAnimationFrame(tick);
  }

  // ── Handle scan ───────────────────────────────────────────────────────────────
  async function handleScan(qrData) {
    if (isProcessing || cooldown) return;
    if (qrData === lastData) return;

    isProcessing = true;
    lastData = qrData;
    cooldown = true;

    setStatus('active', 'fa-spinner fa-spin', 'QR detected — verifying…');
    playBeep('scan');

    try {
      const fd = new FormData();
      fd.append('qr_data', qrData);

      const csrfInput = document.querySelector('input[name="csrf_token"]');
      if (csrfInput) fd.append('csrf_token', csrfInput.value);

      const res = await fetch('<?= APP_URL ?>/api/qr-claim.php', {
        method: 'POST',
        body: fd
      });
      const text = await res.text();
      dbg(`Response: ${text.substring(0, 100)}`);

      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error('Server returned non-JSON: ' + text.substring(0, 80));
      }

      if (data.success) {
        stopCamera();
        showSuccess(data.order);
        playBeep('success');
      } else {
        setStatus('error', 'fa-circle-xmark', data.message || 'Could not claim order');
        showError(data.message || 'Could not claim order', '');
        playBeep('error');
        // Allow retry after 4 seconds
        setTimeout(() => {
          isProcessing = false;
          cooldown = false;
          lastData = null;
          setStatus('active', 'fa-circle-dot', 'Ready to scan…');
          document.getElementById('errorCard').classList.remove('show');
        }, 4000);
      }
    } catch (err) {
      dbg(`Fetch error: ${err.message}`);
      showError('Network Error', err.message);
      setTimeout(() => {
        isProcessing = false;
        cooldown = false;
        lastData = null;
      }, 4000);
    }
  }

  // ── UI helpers ────────────────────────────────────────────────────────────────
  function showSuccess(order) {
    document.getElementById('resultCard').classList.add('show');
    document.getElementById('errorCard').classList.remove('show');
    document.getElementById('resultOrderNum').textContent = order.order_number;
    document.getElementById('rCustomer').textContent = order.customer_name;
    document.getElementById('rCustomerId').textContent = order.customer_id_no;
    document.getElementById('rItems').textContent = order.items;
    document.getElementById('rTotal').textContent = '₱' + parseFloat(order.total_amount).toLocaleString('en-PH', {
      minimumFractionDigits: 2
    });
    setStatus('success', 'fa-circle-check', `<strong>${order.order_number}</strong> claimed successfully!`);
  }

  function showError(title, detail) {
    document.getElementById('errorCard').classList.add('show');
    document.getElementById('resultCard').classList.remove('show');
    document.getElementById('errTitle').textContent = title;
    document.getElementById('errDetail').textContent = detail;
  }

  function scanAgain() {
    isProcessing = false;
    cooldown = false;
    lastData = null;
    document.getElementById('resultCard').classList.remove('show');
    document.getElementById('errorCard').classList.remove('show');
    startCamera();
  }

  // Beep
  function playBeep(type) {
    try {
      const ac = new(window.AudioContext || window.webkitAudioContext)();
      const osc = ac.createOscillator();
      const g = ac.createGain();
      osc.connect(g);
      g.connect(ac.destination);
      osc.frequency.value = type === 'success' ? 880 : type === 'scan' ? 660 : 330;
      osc.type = 'sine';
      g.gain.setValueAtTime(0.25, ac.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 0.35);
      osc.start();
      osc.stop(ac.currentTime + 0.35);
    } catch (e) {}
  }

  window.addEventListener('beforeunload', stopCamera);
</script>

<?php layoutFooter(); ?>