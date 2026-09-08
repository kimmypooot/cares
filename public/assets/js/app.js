/**
 * public/assets/js/app.js
 * Shared front-end behavior: confirm dialogs, toast helper, debounce util.
 */

function debounce(fn, delay = 350) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

function showToast(message, type = 'success') {
  const el = document.createElement('div');
  el.className =
    'fixed top-4 right-4 z-[100] max-w-sm rounded-lg shadow-lg px-4 py-3 text-sm font-medium flex items-start gap-2 ' +
    (type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
  el.innerHTML =
    `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'} mt-0.5"></i><span>${message}</span>`;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-confirm-delete]');
  if (!btn) return;
  e.preventDefault();
  const name = btn.getAttribute('data-confirm-delete');
  const verb = btn.getAttribute('data-confirm-verb') || 'Delete';
  const form = btn.closest('form');
  if (!form) return;

  if (window.confirm(`${verb} "${name}"?\n\nThis action cannot be undone.`)) {
    form.submit();
  }
});

function validateForm(form) {
  let valid = true;
  form.querySelectorAll('[required]').forEach((field) => {
    if (field.closest('[data-conditional-hidden]')) return; // skip fields hidden by conditional logic
    const errorEl = form.querySelector(`[data-error-for="${field.name}"]`);
    if (!field.value || (field.type === 'checkbox' && !field.checked)) {
      valid = false;
      field.classList.add('border-red-500', 'ring-1', 'ring-red-500');
      if (errorEl) errorEl.classList.remove('hidden');
    } else {
      field.classList.remove('border-red-500', 'ring-1', 'ring-red-500');
      if (errorEl) errorEl.classList.add('hidden');
    }
  });
  return valid;
}

/**
 * Force-uppercase any input/textarea marked with the "uppercase-field"
 * class as the person types, preserving cursor position. The Tailwind
 * "uppercase" class on the same elements handles the instant visual
 * transform; this ensures the actual submitted value is uppercase too
 * (not just its on-screen display).
 */
document.addEventListener('input', (e) => {
  const field = e.target;
  if (!field.classList || !field.classList.contains('uppercase-field')) return;
  const start = field.selectionStart;
  const end = field.selectionEnd;
  field.value = field.value.toUpperCase();
  if (start !== null && end !== null) {
    field.setSelectionRange(start, end);
  }
});

/**
 * Applicant QR Code — client-side generation, decoding, and download.
 * The QR payload is always exactly the applicant's code string (see
 * docs/superpowers/specs/2026-09-08-applicant-qr-code-design.md §8) —
 * callers must never pass any other field into renderApplicantQr.
 */

/**
 * Renders `code` as a QR into the given <canvas>, drawing modules
 * manually (rather than using the library's own image/SVG export) so
 * the canvas size and margin are fully under our control — needed for
 * both on-screen legibility and Print/Download output at a
 * consistent, re-scannable size.
 */
function renderApplicantQr(canvas, code, options = {}) {
  const cellSize = options.cellSize || 6;
  const margin = options.margin ?? cellSize * 2;

  // qrcode-generator requires an explicit type number (QR version) and
  // throws if the data doesn't fit — it does not auto-size. Try
  // increasing versions until one fits this code.
  let qr = null;
  for (let typeNumber = 2; typeNumber <= 10; typeNumber++) {
    try {
      const candidate = qrcode(typeNumber, 'M');
      candidate.addData(code);
      candidate.make();
      qr = candidate;
      break;
    } catch (err) {
      qr = null;
    }
  }
  if (!qr) {
    throw new Error('renderApplicantQr: unable to encode code "' + code + '"');
  }

  const moduleCount = qr.getModuleCount();
  const size = moduleCount * cellSize + margin * 2;
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, size, size);
  ctx.fillStyle = '#000000';
  for (let row = 0; row < moduleCount; row++) {
    for (let col = 0; col < moduleCount; col++) {
      if (qr.isDark(row, col)) {
        ctx.fillRect(margin + col * cellSize, margin + row * cellSize, cellSize, cellSize);
      }
    }
  }
  canvas.setAttribute('role', 'img');
  canvas.setAttribute('aria-label', 'QR code for applicant ' + code);
}

/** Triggers a PNG download of the given canvas's current contents. */
function downloadQrPng(canvas, filename) {
  const link = document.createElement('a');
  link.download = filename;
  link.href = canvas.toDataURL('image/png');
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

/**
 * Alpine component backing the shared "Scan / Look Up Applicant" modal
 * (includes/qr-scanner-modal.php), used on dashboard.php and
 * applicants.php. Manual code entry always works; the camera path is
 * only offered when the browser reports getUserMedia support. A
 * successful scan or manual submit navigates straight to
 * applicant-view.php?code=<value>.
 */
function qrScanner() {
  return {
    open: false,
    manualCode: '',
    cameraAvailable: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
    cameraActive: false,
    cameraError: '',
    _stream: null,
    _rafId: null,

    openModal() {
      this.open = true;
      this.manualCode = '';
      this.cameraError = '';
    },
    closeModal() {
      this.stopCamera();
      this.open = false;
    },
    submitManual() {
      const code = this.manualCode.trim();
      if (code) {
        this.navigateToCode(code);
      }
    },
    navigateToCode(code) {
      window.location.href = 'applicant-view.php?code=' + encodeURIComponent(code);
    },
    async startCamera() {
      if (!this.cameraAvailable) return;
      this.cameraError = '';
      try {
        this._stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        const video = this.$refs.qrVideo;
        video.srcObject = this._stream;
        await video.play();
        this.cameraActive = true;
        this._scanLoop();
      } catch (err) {
        this.cameraError = 'Camera access was denied or unavailable. Use manual entry below.';
        this.cameraActive = false;
      }
    },
    _scanLoop() {
      if (!this.cameraActive) return;
      const video = this.$refs.qrVideo;
      const canvas = this.$refs.qrCanvas;
      if (video.readyState === video.HAVE_ENOUGH_DATA) {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const result = jsQR(imageData.data, imageData.width, imageData.height);
        if (result && result.data) {
          this.stopCamera();
          this.navigateToCode(result.data);
          return;
        }
      }
      this._rafId = requestAnimationFrame(() => this._scanLoop());
    },
    stopCamera() {
      this.cameraActive = false;
      if (this._rafId) {
        cancelAnimationFrame(this._rafId);
        this._rafId = null;
      }
      if (this._stream) {
        this._stream.getTracks().forEach((track) => track.stop());
        this._stream = null;
      }
    },
  };
}
