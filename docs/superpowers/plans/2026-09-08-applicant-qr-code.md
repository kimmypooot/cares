# Applicant QR Code Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every applicant an auto-generated QR code (encoding only
their applicant code, nothing else), shown at registration and on their
profile, plus a camera-or-manual-entry QR lookup on the Dashboard and
Applicants pages that jumps straight to that applicant's profile.

**Architecture:** Entirely client-side generation and decoding — two
vendored JS libraries (`qrcode-generator` for encoding into a
`<canvas>`, `jsQR` for decoding camera frames), wired into the existing
Alpine-driven pages via a couple of shared helper functions in
`app.js` and one new Alpine component (`qrScanner()`) backing a shared
modal partial. The only server-side change is a new `?code=` lookup
path alongside `applicant-view.php`'s existing `?id=` — no new table,
no new column, no new API endpoint.

**Tech Stack:** PHP 8 + PDO/MySQL (unchanged), Alpine.js (existing),
vendored `qrcode-generator` (kazuhikoarase) + `jsQR` (cozmo) — both
UMD builds, no CDN, matching this repo's existing vendoring convention
for Alpine/Chart.js/FontAwesome.

**Spec:** docs/superpowers/specs/2026-09-08-applicant-qr-code-design.md

## Global Constraints

- **No automated test suite** in this repo — verification is `php -l`
  (PHP files) / `node --check` (JS files) + curl-driven HTTP checks +
  a headless Node script that round-trips the two vendored libraries'
  real code (no browser required for that specific check) + explicit
  reasoning about DOM/script load order, since this phase is unusually
  JS-heavy for this codebase.
- **QR payload is always exactly the applicant's code string** — never
  a URL, never any other field. Every call site that renders a QR must
  pass only `applicant_code` (or the PHP variable holding it), never a
  composite string, never any other applicant field. This is verified
  explicitly in Task 6.
- **No CDN at runtime.** Both libraries are vendored locally under
  `public/assets/vendor/`, loaded via local `<script>` tags only,
  matching every existing vendored asset in this codebase.
- **`e()` escaping** on every dynamic value echoed into HTML from PHP,
  per this codebase's standing convention (CLAUDE.md).
- **This phase adds no new state-changing POST handler, no new
  CSRF-protected form, and no new `audit_log()` call.** Every new
  capability in this phase (QR display, QR download, QR scan/manual
  lookup) is either pure client-side rendering or a read-only `GET`
  lookup — there is nothing here for CSRF or the audit trail to cover.
  A task reviewer should not flag "missing csrf_require()" or "missing
  audit_log()" against this phase's new code paths; that would be a
  false positive against a Global Constraint the plan is explicitly
  scoping out, not an oversight.
- **Camera access is a bonus, never a hard dependency.** Every page
  that offers scanning must remain fully usable via manual code entry
  alone, with no camera permission ever granted.
- **Any task that introduces a new Tailwind utility class not already
  used elsewhere in this repo must end with `npm run build` and commit
  the regenerated `public/assets/css/app.build.css`** — this exact
  omission was an Important finding in the prior phase's final review
  (an un-rebuilt CSS bundle shipping with missing classes); this plan
  builds the rebuild into each task that needs it instead of leaving it
  for a final-review catch.
- **Script load order matters and is spelled out per-task below** —
  `header.php`'s vendored library `<script>` tags are not deferred (so
  they're guaranteed available before `footer.php`'s synchronous
  `app.js`), and any inline PHP page must trigger QR rendering via
  Alpine's `x-init` (which runs after `app.js` has executed, since
  Alpine itself loads with `defer` and therefore initializes after any
  earlier non-deferred script in the same document) rather than a bare
  inline `<script>` that runs immediately at parse time, before
  `app.js`'s functions exist.

## Preflight file-touch map

| Task | Files created/modified | Touched by another task? |
|------|------------------------|---------------------------|
| 1 | package.json, public/assets/vendor/qrcode-generator/qrcode.js (new), public/assets/vendor/jsqr/jsQR.js (new), README.md | No |
| 2 | public/assets/js/app.js, includes/header.php | No |
| 3 | public/applicant-view.php | No |
| 4 | public/register-applicant.php | No |
| 5 | includes/qr-scanner-modal.php (new), public/dashboard.php, public/applicants.php | No |
| 6 | none (verification only) | n/a |

No two tasks touch the same file. Interface dependency: Task 1's
vendored libraries are consumed by Task 2's helpers; Task 2's
`renderApplicantQr`/`downloadQrPng`/`qrScanner()` are consumed by
Tasks 3, 4, and 5. Strictly ascending dispatch order 1→6 satisfies
every dependency.

---

### Task 1: Vendor the QR libraries

**Files:**
- Modify: `package.json`
- Create: `public/assets/vendor/qrcode-generator/qrcode.js`
- Create: `public/assets/vendor/jsqr/jsQR.js`
- Modify: `README.md`

**Interfaces:**
- Produces: a global `qrcode(typeNumber, errorCorrectionLevel)` factory
  (from `qrcode-generator`) and a global `jsQR(data, width, height)`
  decode function (from `jsQR`), both loadable via a plain `<script
  src="...">` tag (UMD builds — no bundler/module system needed) and
  also via Node's `require()` for the headless verification in Task 6.
- Consumes: nothing from earlier tasks (this is the first task).

- [ ] **Step 1: Add the two npm dependencies**

Edit `package.json`'s `dependencies` block (alphabetical, matching the
existing entries):

```json
  "dependencies": {
    "@fontsource/inter": "^5.3.0",
    "@fortawesome/fontawesome-free": "^6.6.0",
    "alpinejs": "^3.14.1",
    "chart.js": "^4.4.4",
    "jsqr": "^1.4.0",
    "qrcode-generator": "^1.4.4"
  }
```

- [ ] **Step 2: Install**

```bash
npm install
```

- [ ] **Step 3: Vendor `qrcode-generator`**

```bash
mkdir -p public/assets/vendor/qrcode-generator
cp node_modules/qrcode-generator/qrcode.js public/assets/vendor/qrcode-generator/qrcode.js
```

- [ ] **Step 4: Vendor `jsQR`**

```bash
mkdir -p public/assets/vendor/jsqr
cp node_modules/jsqr/dist/jsQR.js public/assets/vendor/jsqr/jsQR.js
```

- [ ] **Step 5: Sanity-check both vendored files load correctly under Node**

Run each of these and read the output — they must not throw, and must
report a sensible result (a positive module count; a function/object
export). If either require() call throws or the exported shape isn't
directly callable, inspect the actual file (`node -e
"console.log(require('./public/assets/vendor/<path>'))"`) and note the
real export shape — some UMD builds export `{ default: fn }` rather
than `fn` directly when required in Node. Whatever the real shape
turns out to be, Task 6's headless verification script must use that
same shape (call this out explicitly in your report so Task 6's
implementer doesn't have to re-derive it).

```bash
node -e "const qrcode = require('./public/assets/vendor/qrcode-generator/qrcode.js'); const qr = qrcode(4, 'M'); qr.addData('TEST'); qr.make(); console.log('qrcode-generator OK, moduleCount=' + qr.getModuleCount());"
node -e "const jsQR = require('./public/assets/vendor/jsqr/jsQR.js'); console.log('jsQR require() type: ' + typeof jsQR + (typeof jsQR === 'object' && jsQR !== null ? ' keys=' + Object.keys(jsQR).join(',') : ''));"
```

- [ ] **Step 6: Update README.md — vendoring refresh commands**

Find this block (§4, "To refresh the vendored copies..."):

```
To refresh the vendored copies of Font Awesome / Alpine.js / Chart.js after
`npm install`:
```bash
cp node_modules/@fortawesome/fontawesome-free/css/all.min.css public/assets/vendor/fontawesome/all.min.css
cp -r node_modules/@fortawesome/fontawesome-free/webfonts public/assets/vendor/fontawesome/webfonts
cp node_modules/alpinejs/dist/cdn.min.js public/assets/vendor/alpine/alpine.min.js
cp node_modules/chart.js/dist/chart.umd.js public/assets/vendor/chart/chart.min.js
```
```

Replace with:

```
To refresh the vendored copies of Font Awesome / Alpine.js / Chart.js /
QR libraries after `npm install`:
```bash
cp node_modules/@fortawesome/fontawesome-free/css/all.min.css public/assets/vendor/fontawesome/all.min.css
cp -r node_modules/@fortawesome/fontawesome-free/webfonts public/assets/vendor/fontawesome/webfonts
cp node_modules/alpinejs/dist/cdn.min.js public/assets/vendor/alpine/alpine.min.js
cp node_modules/chart.js/dist/chart.umd.js public/assets/vendor/chart/chart.min.js
cp node_modules/qrcode-generator/qrcode.js public/assets/vendor/qrcode-generator/qrcode.js
cp node_modules/jsqr/dist/jsQR.js public/assets/vendor/jsqr/jsQR.js
```
```

- [ ] **Step 7: Update README.md — project structure listing**

Find this line in the "Project structure" section:

```
│   └── assets/                    Built CSS + vendored Font Awesome/Alpine/Chart.js
```

Replace with:

```
│   └── assets/                    Built CSS + vendored Font Awesome/Alpine/Chart.js/QR libraries
```

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json public/assets/vendor/qrcode-generator public/assets/vendor/jsqr README.md
git commit -m "feat: vendor qrcode-generator and jsQR libraries for Applicant QR Code"
```

---

### Task 2: QR render/download helpers + scanner Alpine component

**Files:**
- Modify: `public/assets/js/app.js`
- Modify: `includes/header.php`

**Interfaces:**
- Consumes: global `qrcode(...)` and `jsQR(...)` from Task 1's vendored
  files.
- Produces: `renderApplicantQr(canvas, code, options = {})`,
  `downloadQrPng(canvas, filename)`, and the `qrScanner()` Alpine
  component factory — all consumed by Tasks 3, 4, and 5.

- [ ] **Step 1: Load the vendored libraries in `includes/header.php`**

Find (in the `<head>` block):

```php
<link rel="icon" type="image/png" href="assets/images/csc-logo.png">
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<script defer src="assets/vendor/alpine/alpine.min.js"></script>
<script src="assets/vendor/chart/chart.min.js"></script>
</head>
```

Replace with:

```php
<link rel="icon" type="image/png" href="assets/images/csc-logo.png">
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<script defer src="assets/vendor/alpine/alpine.min.js"></script>
<script src="assets/vendor/chart/chart.min.js"></script>
<script src="assets/vendor/qrcode-generator/qrcode.js"></script>
<script src="assets/vendor/jsqr/jsQR.js"></script>
</head>
```

This mirrors `chart.min.js`'s existing placement (non-deferred, loaded
in `<head>`) — both new libraries are guaranteed available before
`footer.php`'s `app.js` executes on every logged-in page.

- [ ] **Step 2: Append the QR helpers and scanner component to `public/assets/js/app.js`**

Append this at the end of the existing file (after the final
`document.addEventListener('input', ...)` block):

```js

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
```

- [ ] **Step 3: Verify**

```bash
node --check public/assets/js/app.js
/c/xampp/php/php.exe -l includes/header.php
```
Expected: both report no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/app.js includes/header.php
git commit -m "feat: add QR render/download helpers and scanner Alpine component"
```

---

### Task 3: Applicant profile — `?code=` lookup, QR display, Download QR

**Files:**
- Modify: `public/applicant-view.php`

**Interfaces:**
- Consumes: `renderApplicantQr(canvas, code)` and `downloadQrPng(canvas,
  filename)` from Task 2.
- Produces: `applicant-view.php?code=<applicant_code>` as a working
  alternate entry point, consumed by Task 5's scan modal (which
  navigates to this URL) and independently useful on its own (anyone
  who already has an applicant's code, e.g. from the registration
  popup, can jump straight to their profile).

- [ ] **Step 1: Extend the id/code resolution at the top of the file**

Find:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = Database::getConnection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM care_jf_applicants WHERE id = :id AND is_deleted = 0");
$stmt->execute([':id' => $id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash_set('error', 'Applicant not found.');
    redirect('applicants.php');
}
```

Replace with:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = Database::getConnection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$code = clean($_GET['code'] ?? '');

// Two ways to land on this page: the numeric id (every existing link
// in the app) or the applicant code (the QR scan / manual-entry lookup
// — see docs/superpowers/specs/2026-09-08-applicant-qr-code-design.md
// §6). id wins if both are somehow present. $id is normalized from the
// fetched row right below, so every POST handler and query further
// down this same file behaves identically regardless of which lookup
// path was used to land here.
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM care_jf_applicants WHERE id = :id AND is_deleted = 0");
    $stmt->execute([':id' => $id]);
    $applicant = $stmt->fetch();
} elseif ($code !== '') {
    $stmt = $pdo->prepare("SELECT * FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0");
    $stmt->execute([':code' => $code]);
    $applicant = $stmt->fetch();
} else {
    $applicant = false;
}

if (!$applicant) {
    flash_set('error', 'Applicant not found.');
    redirect('applicants.php');
}

$id = (int)$applicant['id'];
```

- [ ] **Step 2: Add the QR card to the profile header**

Find:

```php
    <div class="flex gap-2 flex-wrap print:hidden">
      <?php if (can_edit()): ?>
      <a href="applicant-edit.php?id=<?= (int)$id ?>" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-pen mr-1"></i> Edit Applicant</a>
      <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=add" class="px-3 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium"><i class="fa-solid fa-briefcase mr-1"></i> Add Employment Record</a>
      <?php endif; ?>
      <button onclick="window.print()" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-print mr-1"></i> Print Profile</button>
      <?php if (can_delete()): ?>
      <form method="POST" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <button type="button" data-confirm-delete="<?= e(full_name($applicant)) ?>" class="px-3 py-2 rounded-lg border border-red-200 text-red-600 text-sm font-medium hover:bg-red-50"><i class="fa-solid fa-trash mr-1"></i> Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid md:grid-cols-3 gap-6">
```

Replace with:

```php
    <div class="flex gap-2 flex-wrap print:hidden">
      <?php if (can_edit()): ?>
      <a href="applicant-edit.php?id=<?= (int)$id ?>" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-pen mr-1"></i> Edit Applicant</a>
      <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=add" class="px-3 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium"><i class="fa-solid fa-briefcase mr-1"></i> Add Employment Record</a>
      <?php endif; ?>
      <button onclick="window.print()" class="px-3 py-2 rounded-lg border border-slate-300 text-sm font-medium hover:bg-slate-50"><i class="fa-solid fa-print mr-1"></i> Print Profile</button>
      <?php if (can_delete()): ?>
      <form method="POST" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <button type="button" data-confirm-delete="<?= e(full_name($applicant)) ?>" class="px-3 py-2 rounded-lg border border-red-200 text-red-600 text-sm font-medium hover:bg-red-50"><i class="fa-solid fa-trash mr-1"></i> Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 mb-6 flex items-center gap-4">
    <canvas id="applicantQrCanvas" x-data x-init="renderApplicantQr($el, <?= json_encode($applicant['applicant_code']) ?>)"
            class="rounded-lg border border-slate-200 shrink-0"></canvas>
    <div>
      <p class="text-xs text-slate-500 uppercase tracking-wide">Applicant QR Code</p>
      <p class="text-xs text-slate-400 mb-2">Scan this code for a quick lookup, or present it when following up with the office.</p>
      <button type="button" onclick="downloadQrPng(document.getElementById('applicantQrCanvas'), <?= json_encode($applicant['applicant_code'] . '-qr.png') ?>)"
              class="print:hidden px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-medium hover:bg-slate-50">
        <i class="fa-solid fa-download mr-1"></i> Download QR
      </button>
    </div>
  </div>

  <div class="grid md:grid-cols-3 gap-6">
```

The canvas itself is deliberately not `print:hidden` — it's included in
`window.print()` output — while the Download button, like this page's
other action buttons, is.

The bare `x-data` on the `<canvas>` gives it its own tiny Alpine scope
so `x-init` can run there (this element sits outside the page's other
`x-data` block, which is scoped only to the Employment Status card
further down). `x-init` runs after Alpine finishes loading — and
Alpine loads with `defer`, which always executes after `footer.php`'s
non-deferred `app.js` — so `renderApplicantQr` is guaranteed to exist
by the time this runs. (Global Constraints, "Script load order.")

- [ ] **Step 3: Verify**

```bash
/c/xampp/php/php.exe -l public/applicant-view.php
```
Expected: no syntax errors.

Then, with the built-in PHP server running against the real database
(same pattern as prior phases):

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/qr_task3_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

# Pick a real, non-deleted applicant's code from the database first, e.g.:
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT id, applicant_code FROM care_jf_applicants WHERE is_deleted = 0 LIMIT 1;"
# Then, using that code:
STATUS_BY_ID=$(curl -s -o /tmp/qr_by_id.html -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?id=<that id>")
STATUS_BY_CODE=$(curl -s -o /tmp/qr_by_code.html -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?code=<that code>")
echo "by id: $STATUS_BY_ID, by code: $STATUS_BY_CODE"
diff <(grep -oP '(?<=Applicant ID:)[^<]*' /tmp/qr_by_id.html) <(grep -oP '(?<=Applicant ID:)[^<]*' /tmp/qr_by_code.html) && echo "SAME APPLICANT"
STATUS_BAD_CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?code=NOT-A-REAL-CODE")
echo "bad code: $STATUS_BAD_CODE"
kill %1
```
Expected: both `by id` and `by code` return 200 and render the same
applicant; a garbage code redirects (302) back through the existing
"Applicant not found" flash path, not a fatal error.

- [ ] **Step 4: Rebuild CSS if needed**

```bash
npm run build
```
This card introduces no genuinely new Tailwind utility beyond ones
already used elsewhere in this file/codebase (`shrink-0`, `gap-4`,
`rounded-lg`, `border-slate-200` are all already in use) — run the
build anyway; it's fast and idempotent, and confirms nothing was
missed. If `git diff --stat public/assets/css/app.build.css` shows a
change, include it in this task's commit.

- [ ] **Step 5: Commit**

```bash
git add public/applicant-view.php public/assets/css/app.build.css
git commit -m "feat: applicant-view.php accepts ?code= lookup and displays a QR code"
```

---

### Task 4: Registration success popup — QR display, Download QR

**Files:**
- Modify: `public/register-applicant.php`

**Interfaces:**
- Consumes: `renderApplicantQr(canvas, code)` and `downloadQrPng(canvas,
  filename)` from Task 2.

- [ ] **Step 1: Load `qrcode-generator` in this page's own `<head>`**

This page renders its own standalone `<head>` (it doesn't go through
`includes/header.php`, since no login is required to view it). It does
not need `jsQR` — this page only ever displays a QR, it never scans
one.

Find:

```php
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<script defer src="assets/vendor/alpine/alpine.min.js"></script>
</head>
```

Replace with:

```php
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<script defer src="assets/vendor/alpine/alpine.min.js"></script>
<script src="assets/vendor/qrcode-generator/qrcode.js"></script>
</head>
```

- [ ] **Step 2: Add the QR + Download button to the success popup**

Find:

```php
    <div class="inline-block bg-slate-50 border border-slate-200 rounded-lg px-6 py-3">
      <p class="text-xs text-slate-500 uppercase tracking-wide">Your Applicant ID</p>
      <p class="text-2xl font-bold text-brand-700"><?= e($applicantCode) ?></p>
    </div>
    <p class="text-xs text-slate-500 mt-3">Please keep this ID for your reference. You may present it when following up with the office.</p>
    <button @click="showSuccess = false; showPrivacy = true" class="mt-5 w-full px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">
      Register Another Applicant
    </button>
```

Replace with:

```php
    <div class="inline-block bg-slate-50 border border-slate-200 rounded-lg px-6 py-3">
      <p class="text-xs text-slate-500 uppercase tracking-wide">Your Applicant ID</p>
      <p class="text-2xl font-bold text-brand-700"><?= e($applicantCode) ?></p>
    </div>
    <?php if ($applicantCode): ?>
    <div class="mt-4 flex flex-col items-center">
      <canvas id="regQrCanvas" x-init="renderApplicantQr($el, <?= json_encode($applicantCode) ?>)"
              class="rounded-lg border border-slate-200"></canvas>
      <button type="button" onclick="downloadQrPng(document.getElementById('regQrCanvas'), <?= json_encode($applicantCode . '-qr.png') ?>)"
              class="mt-2 text-xs font-medium text-brand-600 hover:text-brand-800">
        <i class="fa-solid fa-download mr-1"></i> Download QR
      </button>
    </div>
    <?php endif; ?>
    <p class="text-xs text-slate-500 mt-3">Please keep this ID for your reference. You may present it when following up with the office.</p>
    <button @click="showSuccess = false; showPrivacy = true" class="mt-5 w-full px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">
      Register Another Applicant
    </button>
```

The `<canvas>` here doesn't need its own `x-data` — it already sits
inside the page's root `x-data` block (declared on `<body>`), so
`x-init` alone is enough; Alpine treats it as part of that same
component tree. This whole block is only ever rendered when `$success`
is true (it's inside the existing `<?php if ($success): ?>` guard
higher up in the file), so there's no wasted work rendering an
invisible/empty QR on a normal (non-success) page load.

- [ ] **Step 3: Verify**

```bash
/c/xampp/php/php.exe -l public/register-applicant.php
```

Then, with the built-in server running:

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/qr_task4_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/register-applicant.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" -o /tmp/qr_reg_result.html -w "%{http_code}\n" \
  --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "service_job_seeker=1" \
  --data-urlencode "last_name=TESTQR" --data-urlencode "first_name=PHASE2" \
  --data-urlencode "address=TEST ADDRESS" --data-urlencode "sex=MALE" \
  --data-urlencode "civil_status=SINGLE" --data-urlencode "date_of_birth=1990-01-01" \
  --data-urlencode "contact_number=09171234567" --data-urlencode "email_address=qrtest@example.com" \
  "http://localhost:8899/register-applicant.php"
# That POST redirects (302) to register-applicant.php?success=1&code=..., follow it:
CODE_URL=$(curl -s -b "$COOKIE" -c "$COOKIE" -D - -o /dev/null \
  --data-urlencode "csrf_token=$CSRF" --data-urlencode "service_job_seeker=1" \
  --data-urlencode "last_name=TESTQR2" --data-urlencode "first_name=PHASE2" \
  --data-urlencode "address=TEST ADDRESS" --data-urlencode "sex=MALE" \
  --data-urlencode "civil_status=SINGLE" --data-urlencode "date_of_birth=1990-01-01" \
  --data-urlencode "contact_number=09171234568" --data-urlencode "email_address=qrtest2@example.com" \
  "http://localhost:8899/register-applicant.php" | grep -i '^location:')
echo "$CODE_URL"
curl -s -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$(echo "$CODE_URL" | sed 's/location: //I' | tr -d '\r')" | grep -c 'regQrCanvas'
kill %1
```
Expected: the final `grep -c` finds the `regQrCanvas` element on the
success page (confirming the QR block rendered). **This test creates
two real applicant records** (`TESTQR PHASE2` / `TESTQR2 PHASE2`) in
whatever database this runs against — note this clearly in your report
so the controller can decide whether to clean them up (mirroring how a
prior phase's browser test in this same project created real data;
report it, don't silently leave it unmentioned).

- [ ] **Step 4: Rebuild CSS if needed**

```bash
npm run build
```
`flex-col`, `items-center` are already used elsewhere in this codebase
— run the build anyway to confirm; commit `app.build.css` only if it
actually changed.

- [ ] **Step 5: Commit**

```bash
git add public/register-applicant.php
git add public/assets/css/app.build.css   # only if it changed
git commit -m "feat: registration success popup displays and offers download of the applicant's QR code"
```

---

### Task 5: Scan/lookup modal — Dashboard and Applicants pages

**Files:**
- Create: `includes/qr-scanner-modal.php`
- Modify: `public/dashboard.php`
- Modify: `public/applicants.php`

**Interfaces:**
- Consumes: the `qrScanner()` Alpine component from Task 2, and
  `applicant-view.php?code=<value>` from Task 3 as the navigation
  target on a successful scan/submit.

- [ ] **Step 1: Create the shared modal partial**

Create `includes/qr-scanner-modal.php`:

```php
<?php
/**
 * Shared "Scan / Look Up Applicant" button + modal — included on
 * dashboard.php and applicants.php. Backed by the qrScanner() Alpine
 * component in app.js. Manual code entry always works; the camera
 * button only appears when the browser reports getUserMedia support
 * — camera is a bonus, never a hard dependency. See
 * docs/superpowers/specs/2026-09-08-applicant-qr-code-design.md §5.
 */
?>
<div x-data="qrScanner()">
  <button type="button" @click="openModal()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
    <i class="fa-solid fa-qrcode"></i> Scan / Look Up Applicant
  </button>

  <div x-show="open" x-cloak x-transition.opacity
       class="fixed inset-0 bg-black/40 z-[95] flex items-center justify-center p-4"
       @keydown.escape.window="closeModal()">
    <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6" @click.outside="closeModal()">
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
      </div>
    </div>
  </div>
</div>
```

- [ ] **Step 2: Wire into `public/dashboard.php` — Partner Agency branch**

Find:

```php
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-800">My Agency</h1>
      <p class="text-sm text-slate-500"><?= e($agency['agency_name']) ?></p>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
```

Replace with:

```php
    <div class="mb-6 flex items-start justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-800">My Agency</h1>
        <p class="text-sm text-slate-500"><?= e($agency['agency_name']) ?></p>
      </div>
      <?php require __DIR__ . '/../includes/qr-scanner-modal.php'; ?>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
```

- [ ] **Step 3: Wire into `public/dashboard.php` — main (Administrator/Employee/Viewer) branch**

Find:

```php
    <a href="reports.php" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
      <i class="fa-solid fa-chart-column"></i> Generate Report
    </a>
  </div>
</div>
```

Replace with:

```php
    <a href="reports.php" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium px-4 py-2 rounded-lg">
      <i class="fa-solid fa-chart-column"></i> Generate Report
    </a>
    <?php require __DIR__ . '/../includes/qr-scanner-modal.php'; ?>
  </div>
</div>
```

(This is the button row right below the page's `<h1>Dashboard</h1>` —
confirm you're editing the right occurrence of this closing pattern;
the Partner Agency branch above uses `exit;` before this code is ever
reached in that branch's request path, so there's no risk of double
inclusion within a single request either way.)

- [ ] **Step 4: Wire into `public/applicants.php`**

Find:

```php
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Registered Applicants</h1>
      <p class="text-sm text-slate-500">Search, filter, and manage all registered applicants.</p>
    </div>
    <?php if (can_edit()): ?>
    <a href="applicant-create.php" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-user-plus"></i> Register New Applicant
    </a>
    <?php endif; ?>
  </div>
```

Replace with:

```php
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Registered Applicants</h1>
      <p class="text-sm text-slate-500">Search, filter, and manage all registered applicants.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <?php require __DIR__ . '/../includes/qr-scanner-modal.php'; ?>
      <?php if (can_edit()): ?>
      <a href="applicant-create.php" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
        <i class="fa-solid fa-user-plus"></i> Register New Applicant
      </a>
      <?php endif; ?>
    </div>
  </div>
```

- [ ] **Step 5: Verify**

```bash
/c/xampp/php/php.exe -l includes/qr-scanner-modal.php
/c/xampp/php/php.exe -l public/dashboard.php
/c/xampp/php/php.exe -l public/applicants.php
```
Expected: no syntax errors on any of the three.

Then, with the built-in server running, confirm all four roles can
load both pages without a fatal error and that the scan button/modal
markup is present:

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/qr_task5_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null
for PAGE in dashboard.php applicants.php; do
  STATUS=$(curl -s -o /tmp/qr_page.html -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$PAGE")
  COUNT=$(grep -c 'Scan / Look Up Applicant' /tmp/qr_page.html)
  echo "admin: $PAGE -> $STATUS, scan-button-count=$COUNT"
done
grep -i 'warning\|fatal\|error' /tmp/qr_task5_server.log || echo "no server-log warnings/errors"
kill %1
```
Expected: 200 for both pages, exactly one "Scan / Look Up Applicant"
occurrence each, no warnings/fatals in the server log. If you have a
non-Administrator test account available (e.g. a Partner Agency or
Viewer login used in an earlier phase), repeat the `dashboard.php`
check for at least one of those roles too, to confirm the Partner
Agency branch's placement (Step 2) also renders cleanly — note in your
report if no such test account exists rather than skipping silently.

- [ ] **Step 6: Rebuild CSS**

The modal introduces at least one new arbitrary-value Tailwind class
(`z-[95]`) not used elsewhere in this codebase yet — this **will**
require a rebuild, not just a "run it to be safe" check.

```bash
npm run build
git diff --stat public/assets/css/app.build.css
```
Expected: a non-empty diff. If it's empty, stop and investigate before
proceeding — the Tailwind content globs (`./public/**/*.php`,
`./includes/**/*.php` per `tailwind.config.js`) should have picked up
the new partial automatically, so an empty diff here means something
is wrong (e.g. the new file wasn't saved, or `z-[95]` already existed
some other way) rather than something to shrug off.

- [ ] **Step 7: Commit**

```bash
git add includes/qr-scanner-modal.php public/dashboard.php public/applicants.php public/assets/css/app.build.css
git commit -m "feat: add QR scan/manual-lookup modal to Dashboard and Applicants pages"
```

---

### Task 6: End-to-end verification

**Files:** none modified — verification-only task confirming Tasks 1-5
compose correctly, plus the one check no per-task step above fully
covers: an actual round-trip through the real vendored libraries'
code, and an explicit sweep for any QR call site that might leak more
than the applicant code.

**Interfaces:** none.

- [ ] **Step 1: Full `php -l` sweep**

```bash
cd /c/xampp/htdocs/applicant-system
find public includes -name "*.php" -print0 | xargs -0 -n1 /c/xampp/php/php.exe -l 2>&1 | grep -v "No syntax errors detected"
```
Expected: no output (every file clean).

- [ ] **Step 2: Headless round-trip test of the real vendored libraries**

Write a small Node script (adjust the `require()` result handling to
match whatever export shape Task 1's Step 5 actually found —
this script assumes the direct-callable shape; add a `.default ||`
fallback if Task 1's report says otherwise) to a temp file, e.g.
`/tmp/qr-roundtrip.js`:

```js
const qrcode = require('/c/xampp/htdocs/applicant-system/public/assets/vendor/qrcode-generator/qrcode.js');
const jsQR = require('/c/xampp/htdocs/applicant-system/public/assets/vendor/jsqr/jsQR.js');

function renderToImageData(code, cellSize = 6) {
  let qr = null;
  for (let typeNumber = 2; typeNumber <= 10; typeNumber++) {
    try {
      const candidate = qrcode(typeNumber, 'M');
      candidate.addData(code);
      candidate.make();
      qr = candidate;
      break;
    } catch (e) {
      qr = null;
    }
  }
  if (!qr) throw new Error('Could not encode: ' + code);

  const moduleCount = qr.getModuleCount();
  const margin = cellSize * 2;
  const size = moduleCount * cellSize + margin * 2;
  const data = new Uint8ClampedArray(size * size * 4).fill(255);
  for (let row = 0; row < moduleCount; row++) {
    for (let col = 0; col < moduleCount; col++) {
      if (qr.isDark(row, col)) {
        for (let py = 0; py < cellSize; py++) {
          for (let px = 0; px < cellSize; px++) {
            const x = margin + col * cellSize + px;
            const y = margin + row * cellSize + py;
            const idx = (y * size + x) * 4;
            data[idx] = 0; data[idx + 1] = 0; data[idx + 2] = 0; data[idx + 3] = 255;
          }
        }
      }
    }
  }
  return { data, width: size, height: size };
}

const testCodes = ['APP-202609-000001', 'APP-202601-000123', 'APP-202612-999999'];
let allPassed = true;
for (const code of testCodes) {
  const img = renderToImageData(code);
  const result = jsQR(img.data, img.width, img.height);
  const decoded = result ? result.data : null;
  const ok = decoded === code;
  console.log((ok ? 'PASS' : 'FAIL') + ': encoded "' + code + '", decoded "' + decoded + '"');
  if (!ok) allPassed = false;
}
process.exit(allPassed ? 0 : 1);
```

Run it:

```bash
node /tmp/qr-roundtrip.js
```
Expected: three `PASS` lines, exit code 0. This is the strongest
available confirmation of Global Constraints' "QR payload is always
exactly the applicant's code string" requirement, since it exercises
the actual vendored library code, not a reimplementation — a
successful decode that exactly equals the original string proves
there is no truncation, no encoding corruption, and (by construction
of the test input) no extra data silently appended.

- [ ] **Step 3: Grep every QR-rendering call site and confirm each passes only the applicant code**

```bash
grep -rn "renderApplicantQr(" public includes
```
Expected exactly three call sites (one each from Tasks 3, 4, and 5's
wiring — Task 5 itself doesn't call `renderApplicantQr`, only Tasks 3
and 4 do; if `grep` finds a different count, investigate before
proceeding). For each match, read the surrounding PHP to confirm the
second argument is `$applicant['applicant_code']` or `$applicantCode`
(a bare applicant-code variable/column) and never a concatenation with
any other field, never `$applicant` as a whole array, never any name/
contact/address value.

- [ ] **Step 4: Regression smoke test**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/qr_final_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT id, applicant_code FROM care_jf_applicants WHERE is_deleted = 0 LIMIT 1;"
# substitute the id/code found above into the two checks below

for PAGE in dashboard.php applicants.php reports.php partner-agency.php users.php employment-list.php audit-logs.php settings.php vacancies.php register-applicant.php; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$PAGE")
  echo "admin: $PAGE -> $STATUS"
done
STATUS_ID=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?id=<id>")
STATUS_CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?code=<code>")
echo "applicant-view.php?id= -> $STATUS_ID, ?code= -> $STATUS_CODE"
grep -i 'warning\|fatal\|error' /tmp/qr_final_server.log || echo "no server-log warnings/errors"
kill %1
```
Expected: `200` for every page in the list including the pre-existing
ones from Phase 1 (confirming this phase introduced no regression
there), and `200` for both `applicant-view.php` lookup styles.

- [ ] **Step 5: No commit needed** (verification-only task).
