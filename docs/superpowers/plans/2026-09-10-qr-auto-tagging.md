# QR Auto-Tagging for Partner Agencies — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make scanning (or manually entering) an applicant's QR code automatically associate that applicant with the scanning Partner Agency's account — camera scan tags instantly, manual entry asks for confirmation first — while leaving the existing manual "Tag for Review" button unchanged as the fallback for when no QR code is available.

**Architecture:** Extract the existing "Tag for Review" insert/duplicate-check/audit logic in `applicant-view.php` into one shared function in `includes/functions.php`. Add a new CSRF-protected AJAX endpoint (`public/api/qr-tag.php`, Partner-Agency-only) that calls the same shared function. Update the shared QR scanner modal's JS (`qrScanner()` in `app.js`) to call that endpoint before navigating — instantly for camera decodes, behind an in-app confirm dialog for manual code entry — and to navigate straight to the resolved applicant afterward.

**Tech Stack:** PHP 8 (no framework), PDO/MySQL, Alpine.js, vanilla `fetch()`. No build step required for these changes (no new Tailwind classes beyond ones already in the compiled CSS — verify in Task 2).

**Spec:** `docs/superpowers/specs/2026-09-10-qr-auto-tagging-design.md`

## Global Constraints

- Agency identity for any tagging action comes from `current_agency_id($pdo)` (session-derived via `care_jf_users.agency_id`) — **never** from a request body/POST/GET parameter.
- Every state-changing POST handler calls `csrf_require()` (from `includes/csrf.php`); the new endpoint is a state-changing POST handler.
- All SQL goes through PDO prepared statements — never string-concatenated SQL.
- Every dynamic value echoed into HTML goes through `e()`.
- Every create/status-change action calls `audit_log($pdo, $userId, $action, $table, $recordId, $description)`.
- The QR payload stays exactly the applicant code string — this feature does not touch QR generation/content.
- Auto-tagging must never set `is_current = 1`, write `date_hired`, touch `care_jf_job_vacancies`, or set `employment_status` to anything other than `'For Review'`. Hiring stays a separate, existing, manual workflow.
- No new database migration — reuse `care_jf_employment_records` and its existing `'For Review'` status as-is.
- No modal in this app is dismissed by clicking outside it — only explicit buttons or Escape close a modal. The new confirm dialog follows this rule too.
- This is a PHP 8 project with no autoloader/framework and no automated test suite (per `CLAUDE.md`) — every verification step below is a `php -l` lint, a `curl`/SQL check, or an actual browser exercise against the real dev database, never a unit test file.

---

### Task 1: Shared tagging function + refactor the manual "Tag for Review" handler

**Files:**
- Modify: `includes/functions.php` (add new function; insert after the existing `is_applicant_hired()` function, currently at lines 287-294)
- Modify: `public/applicant-view.php:94-151` (the `tag_for_review` POST branch)

**Interfaces:**
- Produces: `tag_applicant_for_agency(PDO $pdo, int $applicantId, string $applicantCode, int $agencyId, int $actingUserId, string $source): string` — `$source` is `'manual'` or `'qr_scan'`. Returns one of `'created'`, `'duplicate'`, `'already_hired'`, `'agency_invalid'`. This is consumed by Task 3's new endpoint as well as by this task's refactor.

- [ ] **Step 1: Read the current handler to confirm the baseline you're preserving**

Re-read `public/applicant-view.php` lines 94-151 (the `tag_for_review` branch) in your editor. Note today's exact behavior, which Step 3 must preserve byte-for-byte in user-facing terms:
- `!can_manage_employment() && !is_partner_agency()` → 403 HTML.
- `is_applicant_hired($pdo, $id)` → flash `error` "This applicant has already been hired." + redirect.
- Partner Agency → `$reviewAgencyId = current_agency_id($pdo)`; staff → `$reviewAgencyId` from `$_POST['agency_id']` or null.
- No `$reviewAgencyId` → flash `error` "Select a Partner Agency to tag for review." + redirect.
- Agency not found/not Active → flash `error` "Selected Partner Agency was not found." + redirect.
- Duplicate open `For Review` from that same agency → flash `error` "This agency has already tagged this applicant for review." + redirect.
- Otherwise: insert the row, `audit_log(..., 'APPLICANT_TAGGED_FOR_REVIEW', ...)`, flash `success` "Applicant tagged for review." + redirect.

- [ ] **Step 2: Add the shared function to `includes/functions.php`**

Insert this immediately after the existing `is_applicant_hired()` function (after line 294):

```php
/**
 * Associates an applicant with a Partner Agency by inserting a 'For
 * Review' employment record, reusing the exact duplicate-prevention
 * and hired-applicant guard the manual "Tag for Review" flow already
 * used. $source is 'manual' (the existing button) or 'qr_scan' (QR
 * auto-tag) — it only changes which audit action string is recorded.
 */
function tag_applicant_for_agency(
    PDO $pdo, int $applicantId, string $applicantCode,
    int $agencyId, int $actingUserId, string $source
): string {
    if (is_applicant_hired($pdo, $applicantId)) {
        return 'already_hired';
    }

    $agStmt = $pdo->prepare("SELECT agency_name, address FROM care_jf_partner_agencies WHERE id = :id AND status = 'Active'");
    $agStmt->execute([':id' => $agencyId]);
    $agencyRow = $agStmt->fetch();
    if (!$agencyRow) {
        return 'agency_invalid';
    }

    // No duplicate concurrent tags from the same agency — re-tagging is
    // fine once a prior tag has moved to Withdrawn/Superseded/Hired,
    // since none of those match this check.
    $dupStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND agency_id = :agid AND employment_status = 'For Review'"
    );
    $dupStmt->execute([':id' => $applicantId, ':agid' => $agencyId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        return 'duplicate';
    }

    $tagStmt = $pdo->prepare(
        "INSERT INTO care_jf_employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
         VALUES (:aid, :agid, :agency, :address, NULL, 'For Review', 0, 'Active')"
    );
    $tagStmt->execute([
        ':aid' => $applicantId, ':agid' => $agencyId,
        ':agency' => $agencyRow['agency_name'], ':address' => $agencyRow['address'],
    ]);
    $newReviewId = (int)$pdo->lastInsertId();

    $actionCode = $source === 'qr_scan' ? 'APPLICANT_AUTO_TAGGED_QR' : 'APPLICANT_TAGGED_FOR_REVIEW';
    $verb = $source === 'qr_scan' ? 'auto-tagged via QR scan by' : 'tagged For Review by';
    audit_log($pdo, $actingUserId, $actionCode, 'care_jf_employment_records', $newReviewId,
        "Applicant {$applicantCode} {$verb} {$agencyRow['agency_name']}");

    return 'created';
}
```

- [ ] **Step 3: Lint the file**

Run: `php -l "includes/functions.php"`
Expected: `No syntax errors detected in includes/functions.php`

- [ ] **Step 4: Refactor `public/applicant-view.php`'s `tag_for_review` branch to call the shared function**

Replace lines 94-151 (from `} elseif ($action === 'tag_for_review') {` through its closing `redirect('applicant-view.php?id=' . $id);`) with:

```php
    } elseif ($action === 'tag_for_review') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        // Agency identity is always server-derived for a Partner Agency —
        // never trusted from the request. Administrator/Employee explicitly
        // choose which agency to tag on behalf of.
        if (is_partner_agency()) {
            $reviewAgencyId = current_agency_id($pdo);
        } else {
            $reviewAgencyId = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
        }

        if (!$reviewAgencyId) {
            flash_set('error', 'Select a Partner Agency to tag for review.');
            redirect('applicant-view.php?id=' . $id);
        }

        $tagResult = tag_applicant_for_agency(
            $pdo, $id, $applicant['applicant_code'], $reviewAgencyId, (int)current_user()['id'], 'manual'
        );

        $tagResultMessages = [
            'already_hired' => ['error', 'This applicant has already been hired.'],
            'agency_invalid' => ['error', 'Selected Partner Agency was not found.'],
            'duplicate' => ['error', 'This agency has already tagged this applicant for review.'],
            'created' => ['success', 'Applicant tagged for review.'],
        ];
        [$flashType, $flashMessage] = $tagResultMessages[$tagResult];
        flash_set($flashType, $flashMessage);
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'confirm_hired') {
```

Note: the last line above (`} elseif ($action === 'confirm_hired') {`) already exists immediately after the block you're replacing — it's included here only so you can confirm you've matched the replacement boundary exactly. Do not duplicate it.

- [ ] **Step 5: Lint the file**

Run: `php -l "public/applicant-view.php"`
Expected: `No syntax errors detected in public/applicant-view.php`

- [ ] **Step 6: Verify the refactor preserves behavior — staff path**

In a browser, log in as an Administrator or Employee test account. Open any non-hired applicant's profile (`applicant-view.php?id=<id>`), click "Tag for Review", pick a Partner Agency from the dropdown, submit. Confirm:
- Flash message reads exactly "Applicant tagged for review."
- A new row now exists: run `SELECT id, applicant_id, agency_id, employment_status, is_current, status FROM care_jf_employment_records WHERE applicant_id = <id> ORDER BY id DESC LIMIT 1;` against the dev DB and confirm `employment_status = 'For Review'`, `is_current = 0`, `status = 'Active'`.
- `SELECT action, table_name, record_id, description FROM care_jf_audit_logs ORDER BY id DESC LIMIT 1;` shows `action = 'APPLICANT_TAGGED_FOR_REVIEW'`.
- Submitting "Tag for Review" again for the same applicant/agency now shows "This agency has already tagged this applicant for review." and no second row was inserted (re-run the `SELECT ... COUNT` check or just recount rows for that applicant/agency pair).

- [ ] **Step 7: Verify the refactor preserves behavior — Partner Agency path**

Log in as a Partner Agency test account (create one via Administrator → Partner Agency → an agency → Agency Users → Add User, if none exists yet). Open a different non-hired applicant's profile and click "Tag for Review" (no agency dropdown shown to this role — it tags with their own agency automatically). Confirm the same flash message, DB row, and audit log behavior as Step 6, with `agency_id` matching this account's own agency.

- [ ] **Step 8: Commit**

```bash
git add includes/functions.php public/applicant-view.php
git commit -m "Extract shared tag_applicant_for_agency() from manual Tag for Review handler"
```

---

### Task 2: CSRF token meta tag + scanner modal markup (confirm dialog, inline error, role flag)

**Files:**
- Modify: `includes/header.php` (add one `<meta>` tag inside `<head>`, after line 18)
- Modify: `includes/qr-scanner-modal.php` (whole file — add `isPartnerAgency` init option, a confirm-tag modal, and an inline lookup-error message)

**Interfaces:**
- Produces (markup contract Task 4's JS will bind to): the Alpine component instantiated as `qrScanner({ isPartnerAgency: true|false })`; new template bindings `x-show="showConfirmTag"`, `x-text="pendingCode"`, `@click="cancelConfirmTag()"`, `@click="confirmTag()"`, and `x-show="lookupError"` / `x-text="lookupError"`. A `document.querySelector('meta[name="csrf-token"]').getAttribute('content')` reads the new meta tag.

- [ ] **Step 1: Add the CSRF meta tag to `includes/header.php`**

In `includes/header.php`, insert this line right after line 18 (`<meta name="viewport" ...>`) and before line 19 (`<title>...`):

```html
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
```

- [ ] **Step 2: Lint the file**

Run: `php -l "includes/header.php"`
Expected: `No syntax errors detected in includes/header.php`

- [ ] **Step 3: View source to confirm the meta tag renders**

Load any logged-in page (e.g. `dashboard.php`) in a browser and view page source. Confirm `<meta name="csrf-token" content="...">` appears in `<head>` with a non-empty hex-looking value, and that it is a *different* value than any other session's token (open a private/incognito window with a different logged-in account to compare) — confirming it's the real per-session token, not a static placeholder.

- [ ] **Step 4: Rewrite `includes/qr-scanner-modal.php`**

Replace the entire file with:

```php
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
       @keydown.escape.window="closeModal()">
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
       class="fixed inset-0 bg-black/40 z-[96] flex items-center justify-center p-4"
       @keydown.escape.window="cancelConfirmTag()">
    <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
      <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-triangle-exclamation text-amber-500 mr-1"></i> Confirm Association</h3>
      <p class="text-sm text-slate-600 mb-4">Associate applicant code <span class="font-semibold" x-text="pendingCode"></span> with your agency?</p>
      <div class="flex justify-end gap-2">
        <button type="button" @click="cancelConfirmTag()" class="px-4 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-50">Cancel</button>
        <button type="button" @click="confirmTag()" class="px-4 py-2 text-sm rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-semibold">Associate</button>
      </div>
    </div>
  </div>
</div>
```

Note: neither modal `<div>` above binds any `@click` handler to its own backdrop element — both are dismissed only by their explicit buttons or the `@keydown.escape.window` handler, matching this codebase's existing modal convention (verified in the design spec: no modal anywhere in this app currently closes on a backdrop/outside click).

- [ ] **Step 5: Lint the file**

Run: `php -l "includes/qr-scanner-modal.php"`
Expected: `No syntax errors detected in includes/qr-scanner-modal.php`

- [ ] **Step 6: View source as both role types**

Log in as a Partner Agency account, open `dashboard.php`, view source, confirm `qrScanner({ isPartnerAgency: true })` appears. Log in as an Administrator/Employee/Viewer account, open the same page, confirm `qrScanner({ isPartnerAgency: false })` appears instead. Confirm the page has no PHP warnings/notices in either case (check the browser console and, if `display_errors` is on in the dev environment, the rendered HTML for stray warning text).

- [ ] **Step 7: Commit**

```bash
git add includes/header.php includes/qr-scanner-modal.php
git commit -m "Add CSRF meta tag and confirm-tag/error UI to the QR scanner modal"
```

---

### Task 3: New endpoint `public/api/qr-tag.php`

**Files:**
- Create: `public/api/qr-tag.php`

**Interfaces:**
- Consumes: `tag_applicant_for_agency()` from Task 1; `current_agency_id($pdo)`, `is_partner_agency()`, `is_logged_in()`, `clean()`, `csrf_require()`, `flash_set()` (all pre-existing in `includes/auth.php`/`includes/functions.php`/`includes/csrf.php`).
- Produces: `POST /api/qr-tag.php` accepting JSON body `{"code": "<applicant code>"}` (also accepts a form-encoded `code` field), returning JSON `{"ok": true, "status": "created"|"duplicate"|"already_hired", "applicant_id": <int>}` on success or `{"ok": false, "status": "not_found"|"agency_invalid"|"unauthorized"|"forbidden"|"method_not_allowed"}` on failure. This exact response shape is what Task 4's JS parses.

- [ ] **Step 1: Create `public/api/qr-tag.php`**

```php
<?php
/**
 * api/qr-tag.php — Partner Agency QR auto-tag endpoint.
 * Called by the scanner modal's JS before navigating to an applicant's
 * profile: associates the applicant with the logged-in agency (never
 * with an agency named in the request). See
 * docs/superpowers/specs/2026-09-10-qr-auto-tagging-design.md.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'status' => 'unauthorized']);
    exit;
}

if (!is_partner_agency()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'status' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'status' => 'method_not_allowed']);
    exit;
}

csrf_require();

$pdo = Database::getConnection();

$rawInput = file_get_contents('php://input');
$jsonInput = $rawInput !== '' ? json_decode($rawInput, true) : null;
$code = clean((string)(($jsonInput['code'] ?? null) ?? ($_POST['code'] ?? '')));

if ($code === '') {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, applicant_code FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0");
$stmt->execute([':code' => $code]);
$applicant = $stmt->fetch();

if (!$applicant) {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$applicantId = (int)$applicant['id'];
$agencyId = current_agency_id($pdo);

if (!$agencyId) {
    echo json_encode(['ok' => false, 'status' => 'agency_invalid']);
    exit;
}

$result = tag_applicant_for_agency(
    $pdo, $applicantId, $applicant['applicant_code'], $agencyId, (int)current_user()['id'], 'qr_scan'
);

if ($result === 'created') {
    flash_set('success', 'Applicant successfully associated with your agency.');
} elseif ($result === 'duplicate') {
    flash_set('success', 'Applicant is already associated with your agency.');
}

if (in_array($result, ['created', 'duplicate', 'already_hired'], true)) {
    echo json_encode(['ok' => true, 'status' => $result, 'applicant_id' => $applicantId]);
} else {
    echo json_encode(['ok' => false, 'status' => $result]);
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l "public/api/qr-tag.php"`
Expected: `No syntax errors detected in public/api/qr-tag.php`

- [ ] **Step 3: Establish curl test fixtures**

You need the applicant code of a non-hired test applicant (`SELECT applicant_code FROM care_jf_applicants WHERE is_deleted = 0 LIMIT 1;`) and the username/password of a Partner Agency test account (create one via the app UI if none exists — Administrator → Partner Agency → an agency → Agency Users → Add User). Note the base URL your local XAMPP vhost serves this app on (this plan assumes `http://localhost/applicant-system/public/` — adjust every command below if yours differs).

- [ ] **Step 4: Log in via curl and capture a valid session + CSRF token**

```bash
BASE="http://localhost/applicant-system/public"
COOKIES="/tmp/qr-tag-test-cookies.txt"

# Load the login page to establish a session and grab its CSRF token
curl -s -c "$COOKIES" "$BASE/login.php" -o /tmp/login.html
LOGIN_TOKEN=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/login.html)

# Submit login as the Partner Agency test account
curl -s -b "$COOKIES" -c "$COOKIES" -X POST "$BASE/login.php" \
  --data-urlencode "csrf_token=$LOGIN_TOKEN" \
  --data-urlencode "username=<agency_test_username>" \
  --data-urlencode "password=<agency_test_password>" \
  -o /tmp/after_login.html -w "%{http_code}\n"
# Expected: a redirect status (302) or 200, and NOT the login form again —
# open /tmp/after_login.html if unsure whether login succeeded.

# Fetch an authenticated page to read the fresh session's CSRF token from
# the new <meta name="csrf-token"> tag added in Task 2
curl -s -b "$COOKIES" "$BASE/dashboard.php" -o /tmp/dashboard.html
API_TOKEN=$(grep -oP 'name="csrf-token" content="\K[^"]+' /tmp/dashboard.html)
echo "Token: $API_TOKEN"
```

Expected: `API_TOKEN` is a non-empty 64-character hex string.

- [ ] **Step 5: Call the endpoint for a fresh (not-yet-tagged-by-this-agency) applicant**

```bash
curl -s -b "$COOKIES" -X POST "$BASE/api/qr-tag.php" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $API_TOKEN" \
  -d '{"code":"<a non-hired applicant code not yet tagged by this agency>"}'
```

Expected JSON: `{"ok":true,"status":"created","applicant_id":<id>}`. Then run `SELECT employment_status, is_current, status, agency_id FROM care_jf_employment_records WHERE applicant_id = <id> ORDER BY id DESC LIMIT 1;` and confirm `employment_status = 'For Review'`, `agency_id` matches the test account's agency. Run `SELECT action FROM care_jf_audit_logs ORDER BY id DESC LIMIT 1;` and confirm `APPLICANT_AUTO_TAGGED_QR`.

- [ ] **Step 6: Repeat the exact same call (idempotency check)**

Re-run the Step 5 curl command unchanged.
Expected JSON: `{"ok":true,"status":"duplicate","applicant_id":<id>}`, and re-running the row-count SQL confirms still exactly one `For Review` row for that `(applicant_id, agency_id)` pair — no second row was inserted.

- [ ] **Step 7: Call it for an already-hired applicant**

Find an applicant currently hired (`SELECT applicant_id FROM care_jf_employment_records WHERE is_current = 1 AND status = 'Active' LIMIT 1;`, then look up that applicant's code) and repeat Step 5's call with that code.
Expected JSON: `{"ok":true,"status":"already_hired","applicant_id":<id>}`, and confirm no new row was inserted for this test agency (`SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = <id> AND agency_id = <this test agency's id>;` should be 0, or unchanged from before this step if it was already non-zero for an unrelated reason).

- [ ] **Step 8: Call it with a garbage code**

```bash
curl -s -b "$COOKIES" -X POST "$BASE/api/qr-tag.php" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $API_TOKEN" \
  -d '{"code":"NOT-A-REAL-CODE"}'
```

Expected JSON: `{"ok":false,"status":"not_found"}`.

- [ ] **Step 9: Call it as a non-Partner-Agency account**

Repeat Steps 3-5's login flow using an Administrator or Employee test account instead, then call the same endpoint.
Expected: HTTP 403, JSON `{"ok":false,"status":"forbidden"}`.

- [ ] **Step 10: Call it without a valid CSRF token**

Repeat Step 5's call but omit the `X-CSRF-Token` header (or pass an obviously wrong value).
Expected: the request is rejected (per `csrf_require()`'s existing behavior — check via `curl -i` that the HTTP status is not 200 with a `created`/`duplicate` JSON body; the response body may be the shared HTML 403 `csrf_require()` already produces for every other handler in this app, not JSON — that's expected and consistent with the rest of the codebase).

- [ ] **Step 11: Commit**

```bash
git add public/api/qr-tag.php
git commit -m "Add QR auto-tag endpoint for Partner Agency scans"
```

---

### Task 4: Wire the scanner modal's JS to the new endpoint

**Files:**
- Modify: `public/assets/js/app.js:154-256` (the `qrScanner()` function)

**Interfaces:**
- Consumes: the endpoint contract from Task 3; the markup/state contract from Task 2 (`isPartnerAgency` init option, `showConfirmTag`, `pendingCode`, `lookupError`, `tagging` reactive properties referenced by the modal markup).

- [ ] **Step 1: Replace the `qrScanner()` function**

In `public/assets/js/app.js`, replace the entire function currently at lines 154-256 (from `function qrScanner() {` through its closing `}`) with:

```javascript
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
    cameraActive: false,
    cameraError: '',
    lookupError: '',
    tagging: false,
    showConfirmTag: false,
    pendingCode: '',
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
    submitManual() {
      const code = this.manualCode.trim();
      if (!code) return;
      if (this.isPartnerAgency) {
        this.pendingCode = code;
        this.showConfirmTag = true;
      } else {
        this.navigateToCode(code);
      }
    },
    cancelConfirmTag() {
      const code = this.pendingCode;
      this.showConfirmTag = false;
      this.pendingCode = '';
      this.navigateToCode(code);
    },
    confirmTag() {
      const code = this.pendingCode;
      this.showConfirmTag = false;
      this.pendingCode = '';
      this.tagAndNavigate(code);
    },
    navigateToCode(code) {
      window.location.href = 'applicant-view.php?code=' + encodeURIComponent(code);
    },
    async tagAndNavigate(code) {
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
          body: JSON.stringify({ code }),
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
        data = null;
      }

      this.tagging = false;
      if (data && data.ok) {
        window.location.href = 'applicant-view.php?id=' + encodeURIComponent(data.applicant_id);
        return;
      }
      if (data && data.status === 'agency_invalid') {
        this.lookupError = 'Your Partner Agency account is not currently active. Contact an administrator.';
      } else {
        this.lookupError = 'Applicant not found.';
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
            this.tagAndNavigate(result.data);
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
```

- [ ] **Step 2: Rebuild/refresh — confirm no build step is required**

This file is served directly (no bundler per `CLAUDE.md`). Just reload the page in the browser with cache disabled (or hard-refresh) to pick up the change.

- [ ] **Step 3: Browser test — Partner Agency, manual entry, confirm accepted**

Log in as the Partner Agency test account, open the scanner modal (Dashboard or Applicants page), type a non-hired applicant's code not yet tagged by this agency into "Applicant Code", click Go. Confirm:
- The confirm dialog appears with the typed code shown, and clicking the dialog's backdrop does **not** close it (only Cancel/Associate/Escape do).
- Clicking "Associate" navigates to `applicant-view.php?id=<id>` and shows the green flash toast "Applicant successfully associated with your agency."
- The applicant's profile now shows this agency's open review (re-run the DB check from Task 3 Step 5 if you want to double-check).

- [ ] **Step 4: Browser test — Partner Agency, manual entry, confirm cancelled**

Repeat with a different not-yet-tagged applicant, but click "Cancel" in the confirm dialog. Confirm it navigates to the profile via `?code=` (view only — check the address bar) with **no** new "For Review" row created for this agency (re-check via SQL) and no flash toast about association.

- [ ] **Step 5: Browser test — non-Partner-Agency account unaffected**

Log in as Administrator/Employee/Viewer, use the scanner modal's manual entry on any code. Confirm it navigates straight to the profile with no confirm dialog and no tagging (unchanged from before this whole plan).

- [ ] **Step 6: Browser test — not-found code stays in the modal**

As the Partner Agency test account, type an obviously invalid code into manual entry, confirm the dialog, click Associate. Confirm the modal stays open and shows "Applicant not found." inline, with no navigation away from the current page.

- [ ] **Step 7: Commit**

```bash
git add public/assets/js/app.js
git commit -m "Wire QR scanner modal to auto-tag endpoint for Partner Agency accounts"
```

---

### Task 5: End-to-end scenario verification (multi-agency, camera path, regression)

No code changes in this task — it verifies the fully-wired feature against every scenario in the design spec's Testing section (§9) that Tasks 1-4's per-task checks didn't already cover, plus a regression pass. Camera scanning is exercised for real (a real camera and a printed/displayed QR code are needed for this task; if genuinely unavailable in your environment, simulate the camera-decode path with the same curl approach as Task 3 Step 5, which exercises the identical server-side code path a camera decode would hit — note in your final report if you had to fall back to this).

**Files:** none.

- [ ] **Step 1: Two agencies tagging the same applicant**

Create or identify a second Partner Agency test account (a different agency than Task 3/4's). Using Agency A's account, tag Applicant X (via either path). Using Agency B's account, tag the same Applicant X. Confirm via SQL (`SELECT id, agency_id, employment_status FROM care_jf_employment_records WHERE applicant_id = <X> AND employment_status = 'For Review';`) that there are now two rows, one per agency. Then, logged in as Agency A, open Applicant X's profile and confirm Agency A cannot see or act on Agency B's review row (per the existing `applicant-view.php` visibility filter) — and vice versa for Agency B.

- [ ] **Step 2: Real camera scan (or simulated equivalent)**

Using a physical device or emulator with camera access, log in as a Partner Agency account, open the scanner modal, click "Use Camera", and scan a printed/displayed QR code for a fresh (not-yet-tagged-by-this-agency) applicant. Confirm it tags **instantly with no confirm dialog** and navigates to the profile with the "successfully associated" flash. If a camera isn't available, instead repeat Task 3 Step 5's curl call (which is exactly what the camera path calls) and note in your final report that this step was verified via the equivalent server-side call rather than an actual camera.

- [ ] **Step 3: Full regression pass**

Exercise, as each of the four roles where applicable (Administrator, Employee, Viewer, Partner Agency): Login, Logout, Dashboard, Applicants list, Applicant registration (public form), Applicant editing, Applicant viewing, QR generation (Download QR button still works, still encodes only the applicant code), the manual "Tag for Review" button (already re-verified in Task 1, spot-check once more here), Partner Agency page, Job Vacancies, Employment records, Reports, User Management, Audit Logs. Confirm no PHP errors/warnings, no SQL errors, no JavaScript console errors, and no broken navigation anywhere in this list. Pay particular attention to `audit-logs.php` rendering the new `APPLICANT_AUTO_TAGGED_QR` action string correctly (no missing-label blank cell, no crash).

- [ ] **Step 4: Confirm backward compatibility**

Pick an applicant registered before this plan's changes (an older `created_at`) and confirm their existing QR code (generated client-side from their unchanged `applicant_code`) still resolves correctly through both the `?code=` lookup and the new auto-tag endpoint — i.e., nothing about existing applicant codes, QR payloads, or prior employment records was invalidated by this work.

- [ ] **Step 5: Write the final summary**

Produce the "UPDATE SUMMARY" content for this sub-project only (QR auto-tagging), covering: workflow description, duplicate prevention, manual Tag for Review retained, multi-agency support confirmed, security/authorization confirmed, files changed, files created, and exactly which tests in this plan passed vs. which had to be simulated (e.g. camera scanning) vs. any that could not be completed — per this project's "do not claim something was tested if it was not actually tested" rule.
