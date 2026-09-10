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
  // Built via DOM APIs rather than innerHTML template interpolation so a
  // future caller passing a non-literal (e.g. server- or user-derived)
  // message can never be interpreted as HTML.
  const icon = document.createElement('i');
  icon.className = 'fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation') + ' mt-0.5';
  const span = document.createElement('span');
  span.textContent = message;
  el.append(icon, span);
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
 * only offered when the browser reports getUserMedia support.
 *
 * For a Partner Agency account (isPartnerAgency: true), a resolved
 * code is auto-associated with the logged-in agency before navigating
 * to the applicant's profile: instantly for a camera decode, behind a
 * confirm dialog for manual code entry. See
 * docs/superpowers/specs/2026-09-10-qr-auto-tagging-design.md. For any
 * other role, behavior is unchanged: navigate straight to
 * applicant-view.php?code=<value>, no tagging.
 */
function qrScanner(options = {}) {
  return {
    isPartnerAgency: !!options.isPartnerAgency,
    open: false,
    manualCode: '',
    cameraAvailable: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
    // Browsers only expose navigator.mediaDevices in a secure context
    // (https://, or http://localhost/127.0.0.1) — a plain http:// page
    // loaded from another machine on the LAN never gets the API at all,
    // regardless of whether a camera is actually attached (this is why
    // cameraAvailable above is false in that case). Surfaced separately
    // so the UI can explain *why* the camera option is missing instead
    // of silently omitting it.
    cameraBlockedByInsecureOrigin: !window.isSecureContext,
    cameraActive: false,
    cameraError: '',
    lookupError: '',
    tagging: false,
    lookingUp: false,
    showConfirmTag: false,
    pendingCode: '',
    pendingName: '',
    showServiceModal: false,
    serviceClientName: '',
    serviceApplicantId: null,
    serviceOptions: [],
    selectedServiceId: '',
    customServiceName: '',
    serviceModalError: '',
    confirmingService: false,
    _stream: null,
    _rafId: null,
    _generation: 0,

    openModal() {
      this.open = true;
      this.manualCode = '';
      this.cameraError = '';
      this.lookupError = '';
    },
    closeModal() {
      this.stopCamera();
      this.showConfirmTag = false;
      this.open = false;
    },
    async submitManual() {
      const code = this.manualCode.trim();
      if (!code) return;
      if (!this.isPartnerAgency) {
        this.navigateToCode(code);
        return;
      }

      // Look up the applicant's name first so the confirm dialog reads
      // "Associate <Full Name> (<code>)" instead of just the bare code —
      // this is a read-only lookup, no tag is created until Associate is
      // clicked, which still goes through the real tagging endpoint.
      if (this.lookingUp) return;
      this.lookingUp = true;
      this.lookupError = '';
      let data = null;
      try {
        const response = await fetch('api/applicant-lookup.php?code=' + encodeURIComponent(code));
        data = await response.json();
      } catch (err) {
        this.lookingUp = false;
        this.lookupError = 'Could not reach the server. Please try again.';
        return;
      }
      this.lookingUp = false;

      if (!data || !data.ok) {
        this.lookupError = 'Applicant not found.';
        return;
      }

      this.pendingCode = data.applicant_code;
      this.pendingName = data.full_name;
      this.lookupError = '';
      this.showConfirmTag = true;
    },
    cancelConfirmTag() {
      const code = this.pendingCode;
      this.showConfirmTag = false;
      this.pendingCode = '';
      this.pendingName = '';
      this.navigateToCode(code);
    },
    confirmTag() {
      const code = this.pendingCode;
      this.showConfirmTag = false;
      this.pendingCode = '';
      this.pendingName = '';
      this.tagAndNavigate(code, 'manual');
    },
    navigateToCode(code) {
      window.location.href = 'applicant-view.php?code=' + encodeURIComponent(code);
    },
    async tagAndNavigate(code, via) {
      if (this.tagging) return;
      this.tagging = true;
      this.lookupError = '';
      let response;
      try {
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        response = await fetch('api/qr-tag.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': tokenMeta ? tokenMeta.getAttribute('content') : '',
          },
          body: JSON.stringify({ code, via }),
        });
      } catch (err) {
        this.tagging = false;
        this.lookupError = 'Could not reach the server. Please try again.';
        return;
      }

      let data = null;
      try {
        data = await response.json();
      } catch (err) {
        this.tagging = false;
        this.lookupError = 'Something went wrong. Please try again.';
        return;
      }

      this.tagging = false;
      if (data && data.ok) {
        if (data.service_options) {
          this.open = false;
          this.stopCamera();
          this.openServiceModal(data);
          return;
        }
        window.location.href = 'applicant-view.php?id=' + encodeURIComponent(data.applicant_id);
        return;
      }
      if (response.status === 401) {
        this.lookupError = 'Your session has expired. Please refresh the page and sign in again.';
      } else if (response.status === 403) {
        this.lookupError = 'Your account is not permitted to do this.';
      } else if (data && data.status === 'agency_invalid') {
        this.lookupError = 'Your Partner Agency account is not currently active. Contact an administrator.';
      } else {
        this.lookupError = 'Applicant not found.';
      }
    },
    openServiceModal(data) {
      this.serviceClientName = data.full_name || '';
      this.serviceApplicantId = data.applicant_id;
      this.serviceOptions = data.service_options || [];
      this.selectedServiceId = '';
      this.customServiceName = '';
      this.serviceModalError = '';
      this.showServiceModal = true;
    },
    cancelServiceModal() {
      const id = this.serviceApplicantId;
      this.showServiceModal = false;
      this.serviceApplicantId = null;
      window.location.href = 'applicant-view.php?id=' + encodeURIComponent(id);
    },
    async confirmServiceModal() {
      if (this.confirmingService) return;
      if (!this.selectedServiceId) {
        this.serviceModalError = 'Please select a service.';
        return;
      }
      if (this.selectedServiceId === 'others' && !this.customServiceName.trim()) {
        this.serviceModalError = 'Please specify the other service.';
        return;
      }
      this.confirmingService = true;
      this.serviceModalError = '';
      const body = { applicant_id: this.serviceApplicantId };
      if (this.selectedServiceId === 'others') {
        body.service_id = 'others';
        body.custom_service_name = this.customServiceName.trim();
      } else {
        body.service_id = this.selectedServiceId;
      }
      let response, data;
      try {
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        response = await fetch('api/service-availment-confirm.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': tokenMeta ? tokenMeta.getAttribute('content') : '',
          },
          body: JSON.stringify(body),
        });
        data = await response.json();
      } catch (err) {
        this.confirmingService = false;
        this.serviceModalError = 'Could not reach the server. Please try again.';
        return;
      }
      this.confirmingService = false;
      if (data && data.ok) {
        const id = this.serviceApplicantId;
        this.showServiceModal = false;
        window.location.href = 'applicant-view.php?id=' + encodeURIComponent(id);
      } else {
        this.serviceModalError = 'Could not save the selected service. Please try again.';
      }
    },
    async startCamera() {
      if (!this.cameraAvailable) return;
      this.cameraError = '';
      const generation = ++this._generation;
      let stream;
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      } catch (err) {
        if (generation === this._generation) {
          this.cameraError = 'Camera access was denied or unavailable. Use manual entry below.';
          this.cameraActive = false;
        }
        return;
      }
      if (generation !== this._generation) {
        // The modal was closed (or a newer startCamera() call superseded this
        // one) while getUserMedia() was pending. Release the just-acquired
        // camera immediately instead of leaving it running unseen.
        stream.getTracks().forEach((track) => track.stop());
        return;
      }
      this._stream = stream;
      try {
        const video = this.$refs.qrVideo;
        video.srcObject = stream;
        await video.play();
        if (generation !== this._generation) {
          stream.getTracks().forEach((track) => track.stop());
          return;
        }
        this.cameraActive = true;
        this._scanLoop();
      } catch (err) {
        stream.getTracks().forEach((track) => track.stop());
        if (generation === this._generation) {
          this.cameraError = 'Camera access was denied or unavailable. Use manual entry below.';
          this.cameraActive = false;
          this._stream = null;
        }
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
          if (this.isPartnerAgency) {
            this.tagAndNavigate(result.data, 'camera');
          } else {
            this.navigateToCode(result.data);
          }
          return;
        }
      }
      this._rafId = requestAnimationFrame(() => this._scanLoop());
    },
    stopCamera() {
      this._generation++;
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
