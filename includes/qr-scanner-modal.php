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
?>
<div x-data="qrScanner({ isPartnerAgency: <?= is_partner_agency() ? 'true' : 'false' ?> })">
  <button type="button" @click="openModal()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
    <i class="fa-solid fa-qrcode"></i> Scan / Look Up Applicant
  </button>

  <div x-show="open" x-cloak x-transition.opacity
       class="fixed inset-0 bg-black/40 z-[95] flex items-center justify-center p-4"
       @keydown.escape.window="showConfirmTag ? cancelConfirmTag() : (open && closeModal())">
    <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="font-semibold text-slate-800"><i class="fa-solid fa-qrcode text-brand-600 mr-1"></i> Scan / Look Up Applicant</h3>
        <button type="button" @click="closeModal()" class="text-slate-400 hover:text-slate-600" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <template x-if="cameraAvailable">
        <div class="mb-4">
          <button type="button" x-show="!cameraActive" @click="startCamera()" class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50 mb-2">
            <i class="fa-solid fa-camera mr-1"></i> Use Camera
          </button>
          <div x-show="cameraActive" class="relative rounded-lg overflow-hidden bg-black">
            <video x-ref="qrVideo" class="w-full h-48 object-cover" muted playsinline></video>
          </div>
          <canvas x-ref="qrCanvas" class="hidden"></canvas>
          <p x-show="cameraError" x-text="cameraError" class="text-xs text-red-500 mt-1"></p>
        </div>
      </template>

      <div class="border-t border-slate-100 pt-4">
        <label class="block text-sm font-medium text-slate-700 mb-1">Applicant Code</label>
        <form @submit.prevent="submitManual()" class="flex gap-2">
          <input type="text" x-model="manualCode" placeholder="e.g. APP-202609-000001"
                 class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Go</button>
        </form>
        <p x-show="lookupError" x-text="lookupError" class="text-xs text-red-500 mt-2"></p>
        <p x-show="tagging" class="text-xs text-slate-400 mt-2"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Looking up applicant…</p>
      </div>
    </div>
  </div>

  <div x-show="showConfirmTag" x-cloak x-transition.opacity
       class="fixed inset-0 bg-black/40 z-[96] flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
      <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-triangle-exclamation text-amber-500 mr-1"></i> Confirm Association</h3>
      <p class="text-sm text-slate-600 mb-4">Associate applicant code <span class="font-semibold" x-text="pendingCode"></span> with your agency?</p>
      <div class="flex justify-end gap-2">
        <button type="button" @click="cancelConfirmTag()" class="px-4 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Cancel</button>
        <button type="button" @click="confirmTag()" :disabled="tagging" class="px-4 py-2 text-sm rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-semibold">Associate</button>
      </div>
    </div>
  </div>
</div>
