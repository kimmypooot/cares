<?php
/**
 * Shared "Scan / Look Up Applicant" button + modal — included on
 * dashboard.php and applicants.php. Backed by the qrScanner() Alpine
 * component in app.js. Manual code entry always works; the camera
 * button only appears when the browser reports getUserMedia support
 * — camera is a bonus, never a hard dependency. See
 * docs/superpowers/specs/2026-09-08-applicant-qr-code-design.md §5.
 *
 * Partner Agency accounts additionally auto-associate the scanned/
 * looked-up applicant with their own agency — instantly on a camera
 * decode, behind a confirm dialog on manual code entry. See
 * docs/superpowers/specs/2026-09-10-qr-auto-tagging-design.md.
 */

// Not every including page defines $pdo in its own scope before this
// require (applicants.php and clients.php don't), so resolve it locally
// rather than assume a caller-scoped variable — needed below for
// current_agency_name(). auth.php (required by every page before this
// partial) pulls in functions.php, which requires config/database.php,
// so the Database class is always already loaded here.
$pdo = $pdo ?? Database::getConnection();
?>
<div x-data="qrScanner({ isPartnerAgency: <?= is_partner_agency() ? 'true' : 'false' ?> })">
  <button type="button" @click="openModal()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
    <i class="fa-solid fa-qrcode"></i> Scan / Look Up Applicant
  </button>

  <div x-show="open" x-cloak x-transition.opacity
       class="fixed inset-0 bg-black/40 z-[95] flex items-center justify-center p-4"
       @keydown.escape.window="showConfirmTag ? cancelConfirmTag() : (open && closeModal())">
    <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 sm:p-7">
      <div class="flex items-start justify-between mb-6">
        <div class="flex items-center gap-3">
          <span class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-50 text-brand-600 shrink-0">
            <i class="fa-solid fa-qrcode text-lg"></i>
          </span>
          <div>
            <h3 class="font-semibold text-slate-800 leading-tight">Look Up Applicant</h3>
            <p class="text-xs text-slate-500 mt-0.5">Scan a QR code or enter it below</p>
          </div>
        </div>
        <button type="button" @click="closeModal()" class="text-slate-400 hover:text-slate-600 p-1 -mt-1 -mr-1" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <template x-if="cameraBlockedByInsecureOrigin">
        <p class="flex items-start gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 mb-5">
          <i class="fa-solid fa-lock mt-0.5"></i>
          <span>Camera requires a secure (https://) connection. This page was loaded over an unencrypted connection, so the browser won't allow camera access — use manual entry below, or reload this page over https://.</span>
        </p>
      </template>

      <template x-if="cameraAvailable">
        <div class="mb-5">
          <button type="button" x-show="!cameraActive" @click="startCamera()"
                  class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 transition">
            <i class="fa-solid fa-camera"></i> Use Camera
          </button>
          <div x-show="cameraActive" class="relative rounded-xl overflow-hidden bg-slate-900">
            <video x-ref="qrVideo" class="w-full h-52 object-cover opacity-90" muted playsinline></video>
            <span class="pointer-events-none absolute top-3 left-3 w-6 h-6 border-t-2 border-l-2 border-white/70 rounded-tl-md"></span>
            <span class="pointer-events-none absolute top-3 right-3 w-6 h-6 border-t-2 border-r-2 border-white/70 rounded-tr-md"></span>
            <span class="pointer-events-none absolute bottom-3 left-3 w-6 h-6 border-b-2 border-l-2 border-white/70 rounded-bl-md"></span>
            <span class="pointer-events-none absolute bottom-3 right-3 w-6 h-6 border-b-2 border-r-2 border-white/70 rounded-br-md"></span>
            <span class="absolute top-3 left-1/2 -translate-x-1/2 inline-flex items-center gap-1.5 bg-black/50 text-white text-[11px] font-medium px-2.5 py-1 rounded-full">
              <span class="relative flex h-1.5 w-1.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-green-500"></span>
              </span>
              Scanning
            </span>
          </div>
          <canvas x-ref="qrCanvas" class="hidden"></canvas>
          <p x-show="cameraError" x-text="cameraError" class="text-xs text-red-500 mt-2"></p>
        </div>
      </template>

      <div>
        <div class="flex items-center gap-3 mb-3" x-show="cameraAvailable">
          <div class="h-px flex-1 bg-slate-100"></div>
          <span class="text-xs text-slate-400">or enter code manually</span>
          <div class="h-px flex-1 bg-slate-100"></div>
        </div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5" x-show="!cameraAvailable">Applicant Code</label>
        <form @submit.prevent="submitManual()" class="flex gap-2">
          <input type="text" x-model="manualCode" placeholder="APP-202609-000001"
                 class="flex-1 rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono tracking-wide placeholder:font-sans placeholder:tracking-normal placeholder:text-slate-400 focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
          <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold transition">Go</button>
        </form>
        <p x-show="lookupError" x-text="lookupError" class="text-xs text-red-500 mt-2"></p>
        <p x-show="lookingUp || tagging" class="text-xs text-slate-400 mt-2 flex items-center gap-1.5"><i class="fa-solid fa-spinner fa-spin"></i> Looking up applicant…</p>
      </div>
    </div>
  </div>

  <div x-show="showConfirmTag" x-cloak x-transition.opacity
       class="fixed inset-0 bg-black/40 z-[96] flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 sm:p-8 text-center">
      <div class="mx-auto w-12 h-12 rounded-full bg-amber-50 flex items-center justify-center mb-4">
        <i class="fa-solid fa-triangle-exclamation text-xl text-amber-500"></i>
      </div>
      <h3 class="font-semibold text-slate-800 mb-1">Confirm Association</h3>
      <p class="text-sm text-slate-500 mb-4">Associate this applicant with your agency?</p>
      <div class="inline-block max-w-full bg-slate-50 border border-slate-200 rounded-xl px-5 py-3 mb-6">
        <p class="text-sm font-semibold text-slate-800 truncate" x-text="pendingName"></p>
        <p class="text-xs font-mono tracking-wide text-brand-700 mt-0.5" x-text="pendingCode"></p>
      </div>
      <div class="flex gap-2">
        <button type="button" @click="cancelConfirmTag()" class="flex-1 px-4 py-2.5 text-sm rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium transition">Cancel</button>
        <button type="button" @click="confirmTag()" :disabled="tagging" class="flex-1 px-4 py-2.5 text-sm rounded-xl bg-brand-600 hover:bg-brand-700 disabled:opacity-60 text-white font-semibold transition">Associate</button>
      </div>
    </div>
  </div>

  <div x-show="showServiceModal" x-cloak x-transition.opacity
       class="fixed inset-0 bg-black/40 z-[97] flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 sm:p-7 text-center">
      <h2 class="text-xs font-semibold text-brand-600 uppercase tracking-wide mb-1"><?= e(current_agency_name($pdo)) ?></h2>
      <h1 class="text-lg font-bold text-slate-800 mb-5" x-text="serviceClientName"></h1>

      <div class="text-left">
        <label class="block text-sm font-medium text-slate-700 mb-1">Service Availed <span class="text-red-500">*</span></label>
        <select x-model="selectedServiceId" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-3">
          <option value="">Select Service</option>
          <template x-for="opt in serviceOptions" :key="opt.id">
            <option :value="opt.id" x-text="opt.service_name"></option>
          </template>
          <option value="others">OTHERS</option>
        </select>

        <template x-if="selectedServiceId === 'others'">
          <div class="mb-3">
            <label class="block text-sm font-medium text-slate-700 mb-1">Please Specify Other Service <span class="text-red-500">*</span></label>
            <input type="text" x-model="customServiceName" maxlength="200"
                   class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
        </template>

        <p x-show="serviceModalError" x-text="serviceModalError" class="text-xs text-red-500 mb-3"></p>
      </div>

      <div class="flex gap-2 mt-2">
        <button type="button" @click="cancelServiceModal()" class="flex-1 px-4 py-2.5 text-sm rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium transition">Cancel</button>
        <button type="button" @click="confirmServiceModal()" :disabled="confirmingService" class="flex-1 px-4 py-2.5 text-sm rounded-xl bg-brand-600 hover:bg-brand-700 disabled:opacity-60 text-white font-semibold transition">Confirm</button>
      </div>
    </div>
  </div>
</div>
