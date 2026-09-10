# Client Service Modal, Standalone Services/Clients Modules, Nav Restructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a service-selection modal (specific service or "OTHERS" + custom text) to the existing QR/manual-code service-availment tagging flow, give Partner Agency accounts their own Clients list, relocate the embedded Services CRUD to a standalone module for both roles, and restructure the sidebar nav with a collapsible Settings group.

**Architecture:** Two-step tagging (Step 1: automatic agency-level create-or-reuse, unchanged from Phase 1; Step 2: a modal sets which service via a new shared function). The existing single scanner component (`qr-scanner-modal.php` + `qrScanner()` in `app.js`) is extended, not duplicated, to open this new modal when appropriate. Services CRUD moves out of the agency-profile pages into a new `services.php` that reuses `vacancies.php`'s established staff-vs-Partner-Agency `agency_id` pattern.

**Tech Stack:** PHP 8, MySQL/MariaDB via PDO, Tailwind CSS (prebuilt), Alpine.js. No test framework — this repo verifies changes via `php -l`, direct `mysql` CLI queries against the live dev database, and manual browser exercise.

**Spec:** `docs/superpowers/specs/2026-09-10-client-service-modal-and-nav-design.md`

## Global Constraints

- Every state-changing POST handler calls `csrf_require()` before touching the database; every form emits `csrf_field()`.
- Authorization is checked both at the top of the page and again inside every POST handler on that page.
- Agency identity for a Partner Agency user is always `current_agency_id($pdo)` — never trusted from request input. `service_id` selections are re-validated server-side against the acting agency even though the UI dropdown is already filtered.
- All dynamic output goes through `e()`. All SQL goes through PDO prepared statements.
- Every create/update/enable/disable calls `audit_log($pdo, $userId, $action, $table, $recordId, $description)`.
- No hard deletes of tracking-style rows — soft-invalidate via a `status` column.
- All user-entered/displayed text fields use uppercase-on-save (`mb_strtoupper($value, 'UTF-8')`) — this phase's new fields (`custom_service_name`, `services.php`'s service name/description) follow the same established pattern, not a new mechanism.
- No automated test suite exists. "Test" steps mean: `php -l` for syntax, a direct `mysql` CLI query or small one-off PHP CLI snippet, and/or a manual browser check — never `pytest`/`phpunit`.
- DB name is `care_job_fair_db`; connect via `"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db` or `"/c/xampp/php/php.exe"` (this environment's paths).
- No outside-click modal dismissal anywhere in this app — only explicit Cancel/Confirm/X buttons and Escape.

---

### Task 1: Database migration — service selection columns

**Files:**
- Create: `database/migrations/add_service_selection_to_service_availments.sql`

**Interfaces:**
- Produces: `care_jf_service_availments.service_id` (nullable INT UNSIGNED, FK to `care_jf_agency_services(id)` ON DELETE SET NULL) and `care_jf_service_availments.custom_service_name` (nullable VARCHAR(200)) — every later task reads/writes these exact column names.

- [ ] **Step 1: Write the migration file**

```sql
-- =====================================================================
-- Migration: add_service_selection_to_service_availments.sql
-- Adds service-selection columns to care_jf_service_availments (Phase 2
-- of the Client / Agency-Service Availment feature — see
-- docs/superpowers/specs/2026-09-10-client-service-modal-and-nav-design.md).
-- A row's agency-level tag (Phase 1) is created before its specific
-- service is chosen (Phase 2's modal), so both new columns are nullable.
-- No changes to any existing column, table, or row.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_service_selection_to_service_availments.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_service_availments
  ADD COLUMN IF NOT EXISTS service_id INT UNSIGNED NULL AFTER agency_id,
  ADD COLUMN IF NOT EXISTS custom_service_name VARCHAR(200) NULL AFTER service_id;

-- Guarded separately: MySQL/MariaDB don't support "ADD CONSTRAINT ... IF
-- NOT EXISTS" the way ADD COLUMN does, so this is idempotent via a
-- pre-check instead — re-running the migration after the FK already
-- exists must not error.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = 'care_job_fair_db'
    AND TABLE_NAME = 'care_jf_service_availments'
    AND CONSTRAINT_NAME = 'fk_availment_service'
);
SET @add_fk_sql = IF(@fk_exists = 0,
  'ALTER TABLE care_jf_service_availments ADD CONSTRAINT fk_availment_service FOREIGN KEY (service_id) REFERENCES care_jf_agency_services(id) ON DELETE SET NULL',
  'SELECT ''fk_availment_service already exists, skipping'' AS status'
);
PREPARE stmt FROM @add_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS total_rows, SUM(service_id IS NOT NULL) AS rows_with_service FROM care_jf_service_availments;
```

- [ ] **Step 2: Run the migration against the live dev database**

Run: `"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db < database/migrations/add_service_selection_to_service_availments.sql`
Expected: prints `Migration complete.` then a row count (0 total rows is fine — the table was emptied in an earlier session), no errors.

- [ ] **Step 3: Verify the new columns and FK exist**

Run: `"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db -e "SHOW COLUMNS FROM care_jf_service_availments; SHOW CREATE TABLE care_jf_service_availments;"`
Expected: `service_id` (nullable int) and `custom_service_name` (nullable varchar(200)) both present; `SHOW CREATE TABLE` output includes `CONSTRAINT \`fk_availment_service\` FOREIGN KEY (\`service_id\`) REFERENCES \`care_jf_agency_services\` (\`id\`) ON DELETE SET NULL`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/add_service_selection_to_service_availments.sql
git commit -m "Add service_id and custom_service_name columns to care_jf_service_availments"
```

---

### Task 2: `set_service_availment_selection()` function + rename `'duplicate'` to `'reused'`

**Files:**
- Modify: `includes/functions.php:391-393` (the `tag_applicant_for_service()` duplicate-check return) and insert a new function immediately after `tag_applicant_for_service()`'s closing `}` (currently line 418, before `is_valid_email()`)

**Interfaces:**
- Consumes: nothing new.
- Produces: `set_service_availment_selection(PDO $pdo, int $applicantId, int $agencyId, ?int $serviceId, ?string $customServiceName, int $actingUserId): string` returning `'updated' | 'service_invalid' | 'not_found'`. Tasks 3, 5, and 6 call this exact signature. `tag_applicant_for_service()` now returns `'reused'` instead of `'duplicate'` (its other two return values, `'agency_invalid'` and `'created'`, are unchanged) — Tasks 3 and 6 update their own callers' flash-message maps accordingly.

- [ ] **Step 1: Rename the duplicate-check return value**

In `includes/functions.php`, change:
```php
    $dupStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM care_jf_service_availments WHERE applicant_id = :id AND agency_id = :agid"
    );
    $dupStmt->execute([':id' => $applicantId, ':agid' => $agencyId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        return 'duplicate';
    }
```
to:
```php
    $dupStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM care_jf_service_availments WHERE applicant_id = :id AND agency_id = :agid"
    );
    $dupStmt->execute([':id' => $applicantId, ':agid' => $agencyId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        // Not an error in Phase 2: re-scanning an already-tagged Client
        // reopens the service-selection modal against their existing row
        // (see set_service_availment_selection() below) instead of being
        // rejected — this row already exists, nothing new to insert.
        return 'reused';
    }
```

- [ ] **Step 2: Add the new function**

Insert immediately after `tag_applicant_for_service()`'s closing `}` (currently line 418), before `is_valid_email()`:

```php
/**
 * Sets which specific service (from the agency's own catalog, or free
 * text when "OTHERS" was chosen) a Client's service-availment row
 * represents. Called only after tag_applicant_for_service() has already
 * created-or-reused that row (Step 1) — this is purely Step 2, an UPDATE,
 * never an INSERT. Shared by the async QR/manual-code scanner flow
 * (api/service-availment-confirm.php) and the same-page "Tag for
 * Service" form on applicant-view.php, so both use identical logic.
 *
 * Exactly one of $serviceId/$customServiceName should be non-null —
 * callers validate this themselves before calling (the caller knows
 * whether "OTHERS" was chosen); this function's own job is to re-verify
 * $serviceId's agency ownership, never to infer caller intent.
 *
 * NOTE: $agencyId is trusted as-is — callers for a Partner Agency user
 * MUST derive it via current_agency_id(), never from request input.
 */
function set_service_availment_selection(
    PDO $pdo, int $applicantId, int $agencyId,
    ?int $serviceId, ?string $customServiceName, int $actingUserId
): string {
    $resolvedName = $customServiceName;
    if ($serviceId !== null) {
        $svcStmt = $pdo->prepare(
            "SELECT service_name FROM care_jf_agency_services WHERE id = :id AND agency_id = :agid AND status = 'Active'"
        );
        $svcStmt->execute([':id' => $serviceId, ':agid' => $agencyId]);
        $resolvedName = $svcStmt->fetchColumn();
        if ($resolvedName === false) {
            return 'service_invalid';
        }
    }

    $updStmt = $pdo->prepare(
        "UPDATE care_jf_service_availments SET service_id = :sid, custom_service_name = :csn
         WHERE applicant_id = :aid AND agency_id = :agid"
    );
    $updStmt->execute([
        ':sid' => $serviceId, ':csn' => $customServiceName,
        ':aid' => $applicantId, ':agid' => $agencyId,
    ]);
    if ($updStmt->rowCount() === 0) {
        return 'not_found';
    }

    $availmentIdStmt = $pdo->prepare(
        "SELECT id FROM care_jf_service_availments WHERE applicant_id = :aid AND agency_id = :agid"
    );
    $availmentIdStmt->execute([':aid' => $applicantId, ':agid' => $agencyId]);
    $availmentId = (int)$availmentIdStmt->fetchColumn();

    $codeStmt = $pdo->prepare("SELECT applicant_code FROM care_jf_applicants WHERE id = :id");
    $codeStmt->execute([':id' => $applicantId]);
    $applicantCode = (string)$codeStmt->fetchColumn();

    audit_log($pdo, $actingUserId, 'SERVICE_AVAILED_SELECTION_SET', 'care_jf_service_availments', $availmentId,
        "Applicant {$applicantCode}'s availed service set to \"{$resolvedName}\"");

    return 'updated';
}
```

- [ ] **Step 3: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l includes/functions.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Smoke-test against the live DB with a throwaway CLI script**

Write a temporary script (outside the repo, e.g. under your OS temp directory) that requires `config/database.php` and `includes/functions.php`, looks up a real applicant id and a real Active agency id (and a real Active `care_jf_agency_services` row for that agency, or creates a throwaway one), then:

```php
<?php
require '/c/xampp/htdocs/applicant-system/config/database.php';
require '/c/xampp/htdocs/applicant-system/includes/functions.php';
$pdo = Database::getConnection();

// Use real ids from your dev DB for these three.
$applicantId = /* real applicant id */;
$agencyId = /* real Active agency id */;
$serviceId = /* real Active care_jf_agency_services id belonging to $agencyId */;

// Step 1 (mirrors what qr-tag.php/applicant-view.php already do):
$step1 = tag_applicant_for_service($pdo, $applicantId, 'TEST-CODE', $agencyId, 1, 'manual');
echo "step1: $step1\n"; // expect 'created' (or 'reused' if a row already exists for this pair)

// Step 2, a real service:
$step2 = set_service_availment_selection($pdo, $applicantId, $agencyId, $serviceId, null, 1);
echo "step2 real service: $step2\n"; // expect 'updated'

// Step 2, OTHERS / custom text:
$step2b = set_service_availment_selection($pdo, $applicantId, $agencyId, null, 'CUSTOM TEST SERVICE', 1);
echo "step2 custom: $step2b\n"; // expect 'updated'

// Step 2, a service_id belonging to a DIFFERENT agency (must fail):
// substitute a real service id you know belongs to some OTHER agency
$wrongAgencyServiceId = /* real service id belonging to a different agency */;
$step2c = set_service_availment_selection($pdo, $applicantId, $agencyId, $wrongAgencyServiceId, null, 1);
echo "step2 wrong-agency service: $step2c\n"; // expect 'service_invalid'
```

Run it with `"/c/xampp/php/php.exe" <script>`, then verify with a direct query:
`"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db -e "SELECT * FROM care_jf_service_availments WHERE applicant_id=<id> AND agency_id=<id>; SELECT * FROM care_jf_audit_logs WHERE table_name='care_jf_service_availments' ORDER BY id DESC LIMIT 3;"`
Expected: the row's `custom_service_name` ends up `'CUSTOM TEST SERVICE'` and `service_id` NULL after the last successful call (step2b ran after step2, overwriting it — both are legitimate updates); two `SERVICE_AVAILED_SELECTION_SET` audit rows exist (one per successful call); the wrong-agency attempt wrote no audit row and didn't change the data.

Clean up: `"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db -e "DELETE FROM care_jf_service_availments WHERE applicant_id=<id> AND agency_id=<id>; DELETE FROM care_jf_audit_logs WHERE table_name='care_jf_service_availments' AND created_at > '<timestamp before your test>';"` (or delete the specific audit row ids your test printed). Delete the throwaway script.

- [ ] **Step 5: Commit**

```bash
git add includes/functions.php
git commit -m "Add set_service_availment_selection(), rename tag_applicant_for_service()'s duplicate outcome to reused"
```

---

### Task 3: `api/qr-tag.php` — return service options + full name for the modal

**Files:**
- Modify: `public/api/qr-tag.php`

**Interfaces:**
- Consumes: nothing new (uses existing `tag_applicant_for_agency()`/`tag_applicant_for_service()`).
- Produces: response JSON gains `full_name` (string) and `service_options` (array of `{id: int, service_name: string}`) whenever `service_status` is present. Task 4's JS reads these two keys.

- [ ] **Step 1: Add name fields to the applicant lookup query**

Change:
```php
$stmt = $pdo->prepare(
    "SELECT id, applicant_code, service_job_seeker, service_agency_services
     FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0"
);
```
to:
```php
$stmt = $pdo->prepare(
    "SELECT id, applicant_code, first_name, middle_name, last_name, extension_name,
            service_job_seeker, service_agency_services
     FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0"
);
```

- [ ] **Step 2: Update the flash-message map for the renamed `'reused'` outcome**

Change:
```php
    if (isset($results['service'])) {
        if ($results['service'] === 'created') {
            $flashParts[] = 'service availed logged with your agency';
        } elseif ($results['service'] === 'duplicate') {
            $flashParts[] = 'service availment already on file with your agency';
        }
    }
```
to:
```php
    if (isset($results['service'])) {
        if ($results['service'] === 'created') {
            $flashParts[] = 'service availed logged with your agency';
        } elseif ($results['service'] === 'reused') {
            $flashParts[] = 'service availment already on file with your agency';
        }
    }
```
(Same message text as before — only the matched string changes, from `'duplicate'` to `'reused'`, since the function's return value was renamed in Task 2. This flash is what shows if the service-selection modal ends up cancelled — the row itself was still tagged.)

- [ ] **Step 3: Add `full_name`/`service_options` to the JSON response**

Change the final response block:
```php
echo json_encode(array_merge(
    ['ok' => true, 'applicant_id' => $applicantId],
    isset($results['employment']) ? ['employment_status' => $results['employment']] : [],
    isset($results['service']) ? ['service_status' => $results['service']] : []
));
```
to:
```php
$extra = [];
if (isset($results['employment'])) {
    $extra['employment_status'] = $results['employment'];
}
if (isset($results['service'])) {
    $extra['service_status'] = $results['service'];
    $extra['full_name'] = full_name($applicant);

    $svcOptStmt = $pdo->prepare(
        "SELECT id, service_name FROM care_jf_agency_services WHERE agency_id = :agid AND status = 'Active' ORDER BY service_name"
    );
    $svcOptStmt->execute([':agid' => $agencyId]);
    $extra['service_options'] = array_map(
        fn($row) => ['id' => (int)$row['id'], 'service_name' => $row['service_name']],
        $svcOptStmt->fetchAll()
    );
}

echo json_encode(array_merge(['ok' => true, 'applicant_id' => $applicantId], $extra));
```

- [ ] **Step 4: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/api/qr-tag.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual verification via direct POST**

As a logged-in Partner Agency test account with at least one Active service in `care_jf_agency_services`, run the same dev-console `fetch()` pattern used in Phase 1's Task 6 against a real applicant code with `service_agency_services=1`:
```js
fetch('api/qr-tag.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
  body: JSON.stringify({ code: 'APP-...', via: 'manual' })
}).then(r => r.json()).then(console.log);
```
Expected: response includes `full_name` (a real name string) and `service_options` (an array containing at least the Active services for your test agency, empty array if none exist — not an error). For an applicant with only `service_job_seeker=1`, response has neither key (unchanged from Phase 1).

- [ ] **Step 6: Commit**

```bash
git add public/api/qr-tag.php
git commit -m "Return service options and full name from qr-tag.php for the service-selection modal"
```

---

### Task 4: Service Availment modal — markup + `qrScanner()` JS changes

**Files:**
- Modify: `includes/qr-scanner-modal.php`
- Modify: `public/assets/js/app.js:160-357` (the `qrScanner()` function)

**Interfaces:**
- Consumes: `api/qr-tag.php`'s new `full_name`/`service_options` response fields (Task 3); `api/service-availment-confirm.php` (Task 5 — this task's Confirm button POSTs to it, written against the endpoint contract §5.3 of the spec even though Task 5 hasn't been implemented yet; both tasks must land together for this to work end-to-end, but each is independently testable via `php -l`/manual POST).
- Produces: nothing new consumed by later tasks — this is the leaf UI for the modal flow.

- [ ] **Step 1: Add the Service Availment modal markup**

In `includes/qr-scanner-modal.php`, insert a new modal block immediately after the existing "Confirm Association" modal's closing `</div>` (currently the block ending at line 105, right before the component's final closing `</div>` at line 106):

```html
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
```

`current_agency_name($pdo)` doesn't exist yet — add it to `includes/functions.php` right after `full_name()`:
```php
/** The logged-in Partner Agency's own name, for display contexts where
 * only the session-derived agency (never a request-supplied one) may be
 * shown — e.g. the Service Availment modal's header. Empty string if not
 * a Partner Agency session or the agency record is missing. */
function current_agency_name(PDO $pdo): string
{
    if (!is_partner_agency()) {
        return '';
    }
    $stmt = $pdo->prepare("SELECT agency_name FROM care_jf_partner_agencies WHERE id = :id");
    $stmt->execute([':id' => current_agency_id($pdo)]);
    return (string)($stmt->fetchColumn() ?: '');
}
```
`qr-scanner-modal.php` needs `$pdo` in scope — it's already included from pages that define `$pdo = Database::getConnection();` before the `require` (confirmed: `applicants.php`, `clients.php` both do this already), so no new dependency is introduced, but double check this holds for every page that includes this partial before relying on it (grep for `require.*qr-scanner-modal` across `public/` and confirm each including file already has `$pdo` defined above the require line).

- [ ] **Step 2: Add the new Alpine state and methods**

In `public/assets/js/app.js`'s `qrScanner()` return object, add these properties alongside the existing ones (after `pendingName: '',`):
```js
    showServiceModal: false,
    serviceClientName: '',
    serviceApplicantId: null,
    serviceOptions: [],
    selectedServiceId: '',
    customServiceName: '',
    serviceModalError: '',
    confirmingService: false,
```

Add these new methods (anywhere inside the returned object, e.g. right after `tagAndNavigate()`):
```js
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
```

- [ ] **Step 3: Wire `tagAndNavigate()` to open the modal instead of navigating**

Change:
```js
      this.tagging = false;
      if (data && data.ok) {
        window.location.href = 'applicant-view.php?id=' + encodeURIComponent(data.applicant_id);
        return;
      }
```
to:
```js
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
```
(`data.service_options` is only present when a service tag was created/reused, per Task 3 — so a Job-Seeker-only tag still navigates immediately, unchanged. `stopCamera()` already exists in this same component — releases the camera before showing a modal that doesn't need it.)

- [ ] **Step 4: Verify syntax and behavior**

Run: `"/c/xampp/php/php.exe" -l includes/functions.php` and `"/c/xampp/php/php.exe" -l includes/qr-scanner-modal.php`
Expected: `No syntax errors detected` for both. (`app.js` has no PHP to lint — verify it by reading the resulting file for balanced braces/parens around your edits.)

Manually verify in a browser (Task 5 must also be done for this to fully work end-to-end — if Task 5 isn't done yet, verify as far as: the modal opens after a scan/lookup of a service-eligible applicant, the dropdown is populated, selecting `OTHERS` reveals the text box, and Cancel navigates to the profile without any POST to the not-yet-built endpoint).

- [ ] **Step 5: Commit**

```bash
git add includes/qr-scanner-modal.php public/assets/js/app.js includes/functions.php
git commit -m "Add Service Availment modal to the shared QR/manual-code scanner"
```

---

### Task 5: New endpoint `api/service-availment-confirm.php`

**Files:**
- Create: `public/api/service-availment-confirm.php`

**Interfaces:**
- Consumes: `set_service_availment_selection()` (Task 2).
- Produces: JSON `{ok: bool, status?: string}` — consumed by Task 4's `confirmServiceModal()`.

- [ ] **Step 1: Write the endpoint**

```php
<?php
/**
 * api/service-availment-confirm.php — sets which specific service a
 * Client's already-tagged service-availment row represents (Step 2 of
 * the two-step tagging flow — Step 1 is tag_applicant_for_service(),
 * already run by api/qr-tag.php before this modal ever opens). Called
 * by the Service Availment modal's Confirm button. See
 * docs/superpowers/specs/2026-09-10-client-service-modal-and-nav-design.md §5.3.
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
$applicantId = (int)($jsonInput['applicant_id'] ?? 0);
$rawServiceId = $jsonInput['service_id'] ?? null;
$rawCustomName = $jsonInput['custom_service_name'] ?? '';

if (!$applicantId) {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$agencyId = current_agency_id($pdo);
if (!$agencyId) {
    echo json_encode(['ok' => false, 'status' => 'agency_invalid']);
    exit;
}

if ($rawServiceId === 'others') {
    $serviceId = null;
    $customServiceName = mb_strtoupper(trim(clean(is_string($rawCustomName) ? $rawCustomName : '')), 'UTF-8');
    if ($customServiceName === '') {
        echo json_encode(['ok' => false, 'status' => 'custom_name_required']);
        exit;
    }
    if (mb_strlen($customServiceName) > 200) {
        echo json_encode(['ok' => false, 'status' => 'custom_name_too_long']);
        exit;
    }
} elseif (is_numeric($rawServiceId) && (int)$rawServiceId > 0) {
    $serviceId = (int)$rawServiceId;
    $customServiceName = null;
} else {
    echo json_encode(['ok' => false, 'status' => 'service_required']);
    exit;
}

$result = set_service_availment_selection(
    $pdo, $applicantId, $agencyId, $serviceId, $customServiceName, (int)current_user()['id']
);

if ($result === 'updated') {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'status' => $result]);
}
```

- [ ] **Step 2: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/api/service-availment-confirm.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual end-to-end verification**

With Task 4 also in place, as a logged-in Partner Agency test account: scan/manually-enter a service-eligible applicant's code via the scanner modal (Dashboard, Applicants, or Clients page), confirm the Service Availment modal opens with the correct agency name and client name, select a real service, click Confirm, confirm it navigates to the applicant's profile and the "Services Availed History" card shows that service name. Repeat choosing `OTHERS` with custom text — confirm it's stored and displayed uppercased. Also verify directly via POST (dev console, as in Task 3 Step 5) that a `service_id` belonging to a *different* agency is rejected with `status: 'service_invalid'`.

- [ ] **Step 4: Commit**

```bash
git add public/api/service-availment-confirm.php
git commit -m "Add api/service-availment-confirm.php for the Service Availment modal's Confirm action"
```

---

### Task 6: `applicant-view.php`'s "Tag for Service" gains the same service selection

**Files:**
- Modify: `public/applicant-view.php`

**Interfaces:**
- Consumes: `set_service_availment_selection()` (Task 2).

- [ ] **Step 1: Update the `tag_for_service` POST branch**

Currently (lines 137-170, exact content — locate via `grep -n "tag_for_service" public/applicant-view.php` since line numbers may have shifted from earlier phase-1 edits):
```php
    } elseif ($action === 'tag_for_service') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        if (empty($applicant['service_agency_services'])) {
            flash_set('error', 'This applicant did not register to avail agency services.');
            redirect('applicant-view.php?id=' . $id);
        }

        if (is_partner_agency()) {
            $serviceAgencyId = current_agency_id($pdo);
        } else {
            $serviceAgencyId = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
        }

        if (!$serviceAgencyId) {
            flash_set('error', 'Select a Partner Agency to tag for service.');
            redirect('applicant-view.php?id=' . $id);
        }

        $serviceTagResult = tag_applicant_for_service(
            $pdo, $id, $applicant['applicant_code'], $serviceAgencyId, (int)current_user()['id'], 'manual'
        );

        $serviceTagMessages = [
            'agency_invalid' => ['error', 'Selected Partner Agency was not found.'],
            'duplicate' => ['error', 'This agency has already logged a service availment for this applicant.'],
            'created' => ['success', 'Service availment tagged.'],
        ];
        [$flashType, $flashMessage] = $serviceTagMessages[$serviceTagResult] ?? ['error', 'Could not tag this applicant.'];
        flash_set($flashType, $flashMessage);
        redirect('applicant-view.php?id=' . $id);
```

Replace with:
```php
    } elseif ($action === 'tag_for_service') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        if (empty($applicant['service_agency_services'])) {
            flash_set('error', 'This applicant did not register to avail agency services.');
            redirect('applicant-view.php?id=' . $id);
        }

        if (is_partner_agency()) {
            $serviceAgencyId = current_agency_id($pdo);
        } else {
            $serviceAgencyId = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
        }

        if (!$serviceAgencyId) {
            flash_set('error', 'Select a Partner Agency to tag for service.');
            redirect('applicant-view.php?id=' . $id);
        }

        $rawServiceSelection = clean($_POST['service_selection'] ?? '');
        $customServiceInput = mb_strtoupper(trim(clean($_POST['custom_service_name'] ?? '')), 'UTF-8');
        if ($rawServiceSelection === 'others' && $customServiceInput === '') {
            flash_set('error', 'Please specify the other service.');
            redirect('applicant-view.php?id=' . $id);
        }

        $serviceTagResult = tag_applicant_for_service(
            $pdo, $id, $applicant['applicant_code'], $serviceAgencyId, (int)current_user()['id'], 'manual'
        );

        $serviceTagMessages = [
            'agency_invalid' => ['error', 'Selected Partner Agency was not found.'],
            'created' => ['success', 'Service availment tagged.'],
            'reused' => ['success', 'Service availment already on file for this applicant.'],
        ];
        [$flashType, $flashMessage] = $serviceTagMessages[$serviceTagResult] ?? ['error', 'Could not tag this applicant.'];

        if (in_array($serviceTagResult, ['created', 'reused'], true) && $rawServiceSelection !== '') {
            $selServiceId = $rawServiceSelection === 'others' ? null : (int)$rawServiceSelection;
            $selCustomName = $rawServiceSelection === 'others' ? $customServiceInput : null;
            $selectionResult = set_service_availment_selection(
                $pdo, $id, $serviceAgencyId, $selServiceId, $selCustomName, (int)current_user()['id']
            );
            if ($selectionResult === 'updated') {
                $flashMessage = 'Service availment tagged.';
            } elseif ($selectionResult === 'service_invalid') {
                $flashType = 'error';
                $flashMessage = 'Selected service was not found for this agency.';
            }
        }

        flash_set($flashType, $flashMessage);
        redirect('applicant-view.php?id=' . $id);
```

- [ ] **Step 2: Add the service dropdown + OTHERS field to the Tag for Service modal**

Locate the existing modal (search for `Tag for Service` in `public/applicant-view.php` — the `<h3>...Tag for Service</h3>` block, inside the "Services Availed History" card added in Phase 1). It currently contains only the agency-picker (staff) or a plain confirmation sentence (Partner Agency) before its Cancel/Confirm buttons. Add a service dropdown between that agency section and the buttons, gated by an `x-data` flag on this card's modal wrapper (add `serviceSelection: ''` to that `x-data` — check the modal's current `x-data` attribute, likely on the `showTagForService` div from Phase 1's Task 7, and add the new key alongside it):

```php
              <div class="mt-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Service Availed <span class="text-red-500">*</span></label>
                <select name="service_selection" x-model="serviceSelection" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-3">
                  <option value="">Select Service</option>
                  <?php foreach (active_agency_services($pdo, is_partner_agency() ? current_agency_id($pdo) : null) as $svc): ?>
                    <option value="<?= (int)$svc['id'] ?>"><?= e($svc['service_name']) ?></option>
                  <?php endforeach; ?>
                  <option value="others">OTHERS</option>
                </select>
                <div x-show="serviceSelection === 'others'" x-cloak>
                  <label class="block text-sm font-medium text-slate-700 mb-1">Please Specify Other Service <span class="text-red-500">*</span></label>
                  <input type="text" name="custom_service_name" maxlength="200" :required="serviceSelection === 'others'"
                         class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
              </div>
```

Note this is only meaningful/rendered for a Partner Agency actor (whose agency is fixed and known) — for staff, the agency is only known once they've picked it from the existing dropdown, which this modal doesn't currently make reactive. To keep this bounded: render the service dropdown/OTHERS block only when `is_partner_agency()` is true (staff still tag agency-level only via this form, exactly as before this task — they don't get a service dropdown here, since the CARE codebase's own current UI doesn't expose the reactive agency-then-service dependency this would need for staff; a Partner Agency's own "Tag for Service" is this task's actual target per the spec, and covers the common case). Wrap the new block in `<?php if (is_partner_agency()): ?> ... <?php endif; ?>`.

`active_agency_services($pdo, ?int $agencyId)` doesn't exist yet — add it to `includes/functions.php` near `active_agencies()`:
```php
/** Active care_jf_agency_services rows for one agency (or empty if
 * $agencyId is null — the staff/no-agency-picked case). */
function active_agency_services(PDO $pdo, ?int $agencyId): array
{
    if (!$agencyId) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT id, service_name FROM care_jf_agency_services WHERE agency_id = :agid AND status = 'Active' ORDER BY service_name"
    );
    $stmt->execute([':agid' => $agencyId]);
    return $stmt->fetchAll();
}
```

- [ ] **Step 3: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/applicant-view.php` and `"/c/xampp/php/php.exe" -l includes/functions.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manual browser verification**

As a Partner Agency test account, open a service-eligible applicant's profile, click "Tag for Service," confirm the service dropdown (with OTHERS) now appears, confirm selecting a real service and submitting sets it correctly (check the Services Availed History card). As staff (Administrator/Employee), confirm the existing agency-picker flow still works exactly as before (no service dropdown shown, agency-level tag only) — this is the expected, unchanged behavior for that role in this task.

- [ ] **Step 5: Commit**

```bash
git add public/applicant-view.php includes/functions.php
git commit -m "Add service selection to the Tag for Service modal on the applicant profile"
```

---

### Task 7: Standalone `public/services.php`, relocated from the agency-profile pages

**Files:**
- Create: `public/services.php`
- Modify: `public/partner-agency-form.php` (remove the embedded Services Offered section and its 3 POST branches)
- Modify: `public/my-agency.php` (remove the embedded Services Offered section and its 3 POST branches)

**Interfaces:**
- Consumes: `active_agencies($pdo)` (existing helper, already used by `vacancies.php`).

- [ ] **Step 1: Remove the embedded section from `public/my-agency.php`**

Delete the three POST branches for `add_service`/`toggle_service`/`edit_service` (lines 62-112 in the file as read during planning — verify via `grep -n "action.*service" public/my-agency.php` since exact line numbers may have shifted), the `$svcListStmt`/`$agencyServices` fetch (immediately before `$pageTitle = 'My Partner Agency';`), and the entire `<div ... x-data="{ showAddService: false, editingServiceId: null }"> ... Services Offered ... </div>` HTML block (between the profile `</form>` and this file's final closing `</div>`). Leave everything else (the profile save form, `$agencyId`, `$accountRow`, etc.) untouched.

- [ ] **Step 2: Remove the embedded section from `public/partner-agency-form.php`**

Same removal, mirrored: the `add_service`/`toggle_service`/`edit_service` POST branches (guarded by `$isEdit &&` in this file), the `$agencyServices` fetch, and the `<?php if ($isEdit): ?> <div ... Services Offered ... </div> <?php endif; ?>` block. Leave the agency add/edit form itself untouched.

- [ ] **Step 3: Create `public/services.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!can_manage_agency() && !is_partner_agency()) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif">403 — You do not have permission to access this page.</h2>');
}

$pdo = Database::getConnection();
$currentUserId = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    // Additive defense, matching every other POST handler in this codebase.
    if (!can_manage_agency() && !is_partner_agency()) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
    }

    if ($action === 'add_service') {
        $agencyId = is_partner_agency() ? current_agency_id($pdo) : (int)($_POST['agency_id'] ?? 0);
        $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
        $serviceDesc = clean($_POST['description'] ?? '');

        if (!$agencyId) {
            flash_set('error', 'Select a Partner Agency.');
        } elseif ($serviceName === '') {
            flash_set('error', 'Service name is required.');
        } elseif (mb_strlen($serviceName) > 200) {
            flash_set('error', 'Service name must be 200 characters or fewer.');
        } elseif (mb_strlen($serviceDesc) > 500) {
            flash_set('error', 'Description must be 500 characters or fewer.');
        } else {
            $svcStmt = $pdo->prepare(
                "INSERT INTO care_jf_agency_services (agency_id, service_name, description) VALUES (:aid, :n, :d)"
            );
            $svcStmt->execute([':aid' => $agencyId, ':n' => $serviceName, ':d' => $serviceDesc ?: null]);
            $newServiceId = (int)$pdo->lastInsertId();
            audit_log($pdo, $currentUserId, 'CREATE', 'care_jf_agency_services', $newServiceId, "Added service \"$serviceName\"");
            flash_set('success', 'Service added.');
        }
        redirect('services.php');
    } elseif (in_array($action, ['toggle_service', 'edit_service'], true)) {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $ownershipWhere = "id = :id";
        $ownershipParams = [':id' => $serviceId];
        if (is_partner_agency()) {
            $ownershipWhere .= " AND agency_id = :aid";
            $ownershipParams[':aid'] = current_agency_id($pdo);
        }
        $ownStmt = $pdo->prepare("SELECT id, status FROM care_jf_agency_services WHERE $ownershipWhere");
        $ownStmt->execute($ownershipParams);
        $svcRow = $ownStmt->fetch();

        if (!$svcRow) {
            flash_set('error', 'Service not found.');
        } elseif ($action === 'toggle_service') {
            $newStatus = $svcRow['status'] === 'Active' ? 'Disabled' : 'Active';
            $pdo->prepare("UPDATE care_jf_agency_services SET status = :s WHERE id = :id")->execute([':s' => $newStatus, ':id' => $serviceId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'care_jf_agency_services', $serviceId, "Service $newStatus");
            flash_set('success', "Service $newStatus.");
        } else {
            $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
            $serviceDesc = clean($_POST['description'] ?? '');
            if ($serviceName === '') {
                flash_set('error', 'Service name is required.');
            } elseif (mb_strlen($serviceName) > 200) {
                flash_set('error', 'Service name must be 200 characters or fewer.');
            } elseif (mb_strlen($serviceDesc) > 500) {
                flash_set('error', 'Description must be 500 characters or fewer.');
            } else {
                $pdo->prepare("UPDATE care_jf_agency_services SET service_name = :n, description = :d WHERE id = :id")
                    ->execute([':n' => $serviceName, ':d' => $serviceDesc ?: null, ':id' => $serviceId]);
                audit_log($pdo, $currentUserId, 'UPDATE', 'care_jf_agency_services', $serviceId, "Updated service \"$serviceName\"");
                flash_set('success', 'Service updated.');
            }
        }
        redirect('services.php');
    }
}

if (is_partner_agency()) {
    $svcListStmt = $pdo->prepare(
        "SELECT s.*, pa.agency_name FROM care_jf_agency_services s
         JOIN care_jf_partner_agencies pa ON pa.id = s.agency_id
         WHERE s.agency_id = :aid ORDER BY s.service_name"
    );
    $svcListStmt->execute([':aid' => current_agency_id($pdo)]);
} else {
    $svcListStmt = $pdo->query(
        "SELECT s.*, pa.agency_name FROM care_jf_agency_services s
         JOIN care_jf_partner_agencies pa ON pa.id = s.agency_id
         ORDER BY pa.agency_name, s.service_name"
    );
}
$services = $svcListStmt->fetchAll();
$agencyOptions = is_partner_agency() ? [] : active_agencies($pdo);
$canManage = can_manage_agency() || is_partner_agency();

$pageTitle = 'Services';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-5" x-data="{ showAddService: false, editingServiceId: null }">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Services</h1>
      <p class="text-sm text-slate-500"><?= is_partner_agency() ? 'Manage the services your agency offers Job Fair participants.' : 'Services offered by all participating Partner Agencies.' ?></p>
    </div>
    <?php if ($canManage): ?>
    <button type="button" @click="showAddService = true" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-plus"></i> Add Service
    </button>
    <?php endif; ?>
  </div>

  <div x-show="showAddService" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
    <form method="POST" class="space-y-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_service">
      <?php if (!is_partner_agency()): ?>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency <span class="text-red-500">*</span></label>
        <select name="agency_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="">Select a Partner Agency</option>
          <?php foreach ($agencyOptions as $ag): ?>
            <option value="<?= (int)$ag['id'] ?>"><?= e($ag['agency_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Service Name <span class="text-red-500">*</span></label>
        <input type="text" name="service_name" required maxlength="200" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
        <textarea name="description" rows="2" maxlength="500" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" @click="showAddService = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
        <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium">Add</button>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <table class="min-w-full text-sm responsive-cards">
      <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
        <tr>
          <?php if (!is_partner_agency()): ?><th class="px-4 py-2.5 text-left">Agency</th><?php endif; ?>
          <th class="px-4 py-2.5 text-left">Service Name</th>
          <th class="px-4 py-2.5 text-left">Description</th>
          <th class="px-4 py-2.5 text-left">Status</th>
          <?php if ($canManage): ?><th class="px-4 py-2.5 text-right">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php if (!$services): ?>
          <tr><td colspan="<?= (!is_partner_agency() ? 1 : 0) + 3 + ($canManage ? 1 : 0) ?>" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-list-check text-2xl mb-2 block"></i> No services on file.</td></tr>
        <?php endif; ?>
        <?php foreach ($services as $svc): ?>
        <tr>
          <?php if (!is_partner_agency()): ?><td class="px-4 py-3" data-label="Agency"><?= e($svc['agency_name']) ?></td><?php endif; ?>
          <td class="px-4 py-3 font-medium" data-label="Service"><?= e($svc['service_name']) ?></td>
          <td class="px-4 py-3 text-slate-500" data-label="Description"><?= e($svc['description'] ?: '—') ?></td>
          <td class="px-4 py-3" data-label="Status">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $svc['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($svc['status']) ?></span>
          </td>
          <?php if ($canManage): ?>
          <td class="px-4 py-3 text-right" data-label="Actions">
            <button type="button" @click="editingServiceId = editingServiceId === <?= (int)$svc['id'] ?> ? null : <?= (int)$svc['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></button>
            <form method="POST" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_service">
              <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
              <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $svc['status']==='Active' ? 'Disable' : 'Enable' ?>">
                <i class="fa-solid <?= $svc['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
              </button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php if ($canManage): ?>
        <tr x-show="editingServiceId === <?= (int)$svc['id'] ?>" x-cloak>
          <td colspan="<?= (!is_partner_agency() ? 1 : 0) + 3 + 1 ?>" class="px-4 py-4 bg-slate-50">
            <form method="POST" class="space-y-2 max-w-md">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="edit_service">
              <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
              <input type="text" name="service_name" required maxlength="200" value="<?= e($svc['service_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
              <textarea name="description" rows="2" maxlength="500" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"><?= e($svc['description'] ?? '') ?></textarea>
              <div class="flex justify-end gap-2">
                <button type="button" @click="editingServiceId = null" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300">Cancel</button>
                <button type="submit" class="px-3 py-1.5 text-sm rounded-lg bg-brand-600 text-white font-medium">Save</button>
              </div>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```

- [ ] **Step 4: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/services.php` and `"/c/xampp/php/php.exe" -l public/partner-agency-form.php` and `"/c/xampp/php/php.exe" -l public/my-agency.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Manual browser verification**

As a Partner Agency test account: `services.php` shows only their own services with full CRUD; `my-agency.php` no longer has a Services section, its own profile-save still works. As Administrator/Employee: `services.php` shows every agency's services with an Agency column, adding requires picking an agency from the dropdown; `partner-agency-form.php`'s edit page no longer has a Services section, its own agency-save still works. As Viewer: `services.php` shows the cross-agency list with no action buttons (`$canManage` false for that role). A crafted POST from a Partner Agency naming another agency's `agency_id` in `add_service` is ignored (their own `current_agency_id()` is used instead) — or, for `toggle_service`/`edit_service` on a `service_id` from another agency, is rejected as "Service not found."

- [ ] **Step 6: Commit**

```bash
git add public/services.php public/partner-agency-form.php public/my-agency.php
git commit -m "Relocate Services Offered CRUD to a standalone services.php for both roles"
```

---

### Task 8: Partner Agency's Clients view — role branch + `api/agency-clients.php`

**Files:**
- Modify: `public/clients.php`
- Create: `public/api/agency-clients.php`

**Interfaces:**
- Produces: `api/agency-clients.php` JSON shape `{data: [...], total, page, limit, pages}` where each row has `applicant_code, full_name, sex, services_registered (array), service_availed (string), status (string), date_availed (string), id`.

- [ ] **Step 1: Create `public/api/agency-clients.php`**

```php
<?php
/**
 * api/agency-clients.php — AJAX search/filter/pagination for a Partner
 * Agency's own Clients list (public/clients.php's Partner-Agency-role
 * branch). Scoped exclusively to the authenticated agency's own
 * care_jf_service_availments rows — never trusts a posted/URL agency id.
 * Mirrors api/applicants.php's shape (SQL_CALC_FOUND_ROWS, whitelisted
 * sort) for consistency, but the underlying data (Service Availed, Date
 * Availed, per-agency Status) lives on care_jf_service_availments, not
 * on the applicant row, so this is a separate query rather than a filter
 * added to api/applicants.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!is_partner_agency()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$pdo = Database::getConnection();
$agencyId = current_agency_id($pdo);

$search = clean($_GET['search'] ?? '');
$sex = clean($_GET['sex'] ?? '');
$sortCol = clean($_GET['sort'] ?? 'sa.updated_at');
$sortDir = strtolower(clean($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$allowedSort = ['a.applicant_code', 'a.last_name', 'a.sex', 'sa.updated_at'];
if (!in_array($sortCol, $allowedSort, true)) {
    $sortCol = 'sa.updated_at';
}

[$limit, $offset, $page] = paginate_params();

$where = ['sa.agency_id = :agid'];
$params = [':agid' => $agencyId];

if ($search !== '') {
    $where[] = "(a.applicant_code LIKE :search1 OR CONCAT(a.last_name,' ',a.first_name,' ',IFNULL(a.middle_name,'')) LIKE :search2)";
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}
if ($sex !== '' && $sex !== 'All') {
    $where[] = 'a.sex = :sex';
    $params[':sex'] = $sex;
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT SQL_CALC_FOUND_ROWS
        a.id, a.applicant_code, a.last_name, a.first_name, a.middle_name, a.extension_name,
        a.sex, a.service_job_seeker, a.service_agency_services,
        sa.status AS availment_status, sa.updated_at AS date_availed,
        COALESCE(asv.service_name, sa.custom_service_name) AS service_availed
    FROM care_jf_service_availments sa
    JOIN care_jf_applicants a ON a.id = sa.applicant_id AND a.is_deleted = 0
    LEFT JOIN care_jf_agency_services asv ON asv.id = sa.service_id
    WHERE $whereSql
    ORDER BY $sortCol $sortDir
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$total = (int)$pdo->query('SELECT FOUND_ROWS()')->fetchColumn();

$data = array_map(function ($row) {
    $services = [];
    if ($row['service_job_seeker']) $services[] = 'Job Seeker';
    if ($row['service_agency_services']) $services[] = 'Avail Agency Services';
    return [
        'id'                 => (int)$row['id'],
        'applicant_code'     => $row['applicant_code'],
        'full_name'          => full_name($row),
        'sex'                => $row['sex'],
        'services_registered' => $services,
        'service_availed'   => $row['service_availed'] ?: '—',
        'status'             => $row['availment_status'] === 'Active' ? 'AVAILING SERVICES' : 'WITHDRAWN',
        'date_availed'       => format_date($row['date_availed']),
    ];
}, $rows);

echo json_encode([
    'data'  => $data,
    'total' => $total,
    'page'  => $page,
    'limit' => $limit,
    'pages' => (int)ceil($total / $limit),
]);
```

- [ ] **Step 2: Add the role branch to `public/clients.php`**

Change:
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
// Partner Agency accounts get no list view of any kind for Clients —
// matches employment-list.php's existing exclusion of that role. They
// see a service-availment tag they created only via that client's own
// applicant-view.php profile page.
if (is_partner_agency()) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif">403 — Partner Agency accounts do not have access to the Clients module.</h2>');
}

$pageTitle = 'Clients';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div x-data="applicantTable()" x-init="filters.service = 'agency_services'; load()" class="space-y-5">
```
to:
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pageTitle = 'Clients';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (is_partner_agency()):
?>
<div x-data="agencyClientTable()" x-init="load()" class="space-y-5">

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Clients</h1>
      <p class="text-sm text-slate-500">Registrants who have availed your agency's services.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <?php require __DIR__ . '/../includes/qr-scanner-modal.php'; ?>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4">
    <div class="grid md:grid-cols-3 gap-3">
      <div class="md:col-span-2 relative">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
        <input type="text" x-model="filters.search" @input.debounce.400ms="load(1)"
               placeholder="Search by name or Application ID..."
               class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
      </div>
      <select x-model="filters.sex" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
        <option value="All">All Sex</option>
        <option value="MALE">MALE</option>
        <option value="FEMALE">FEMALE</option>
      </select>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm responsive-cards">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wide">
          <tr>
            <th class="px-4 py-3 text-left">Seq. No.</th>
            <th class="px-4 py-3 text-left">Application ID</th>
            <th class="px-4 py-3 text-left">Full Name</th>
            <th class="px-4 py-3 text-left">Sex</th>
            <th class="px-4 py-3 text-left">Services Registered</th>
            <th class="px-4 py-3 text-left">Service Availed</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3 text-left">Date Availed</th>
            <th class="px-4 py-3 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <template x-if="loading">
            <tr><td colspan="9" class="px-4 py-6"><div class="h-4 skeleton rounded"></div></td></tr>
          </template>
          <template x-if="!loading && rows.length === 0">
            <tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">
              <i class="fa-solid fa-inbox text-2xl mb-2 block"></i> No clients found.
            </td></tr>
          </template>
          <template x-for="(row, idx) in rows" :key="row.id">
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3" data-label="Seq" x-text="offset() + idx + 1"></td>
              <td class="px-4 py-3 font-medium text-brand-700" data-label="ID" x-text="row.applicant_code"></td>
              <td class="px-4 py-3" data-label="Name" x-text="row.full_name"></td>
              <td class="px-4 py-3" data-label="Sex" x-text="row.sex"></td>
              <td class="px-4 py-3" data-label="Services Registered">
                <template x-for="svc in row.services_registered" :key="svc">
                  <span class="inline-block px-2 py-0.5 mr-1 mb-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 uppercase" x-text="svc"></span>
                </template>
              </td>
              <td class="px-4 py-3" data-label="Service Availed" x-text="row.service_availed"></td>
              <td class="px-4 py-3" data-label="Status">
                <span class="px-2 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800" x-text="row.status"></span>
              </td>
              <td class="px-4 py-3" data-label="Date Availed" x-text="row.date_availed"></td>
              <td class="px-4 py-3 text-right" data-label="Action">
                <a :href="'applicant-view.php?id=' + row.id" class="text-slate-500 hover:text-brand-600 px-1.5" title="View"><i class="fa-solid fa-eye"></i></a>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100 flex-wrap gap-3">
      <p class="text-xs text-slate-500">
        Showing <span x-text="rows.length ? (offset()+1) : 0"></span>–<span x-text="offset()+rows.length"></span> of <span x-text="total"></span> clients
      </p>
      <div class="flex gap-1">
        <button @click="load(page-1)" :disabled="page<=1" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 disabled:opacity-40">Previous</button>
        <span class="px-3 py-1.5 text-sm">Page <span x-text="page"></span> of <span x-text="Math.max(pages,1)"></span></span>
        <button @click="load(page+1)" :disabled="page>=pages" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 disabled:opacity-40">Next</button>
      </div>
    </div>
  </div>
</div>

<script>
function agencyClientTable() {
  return {
    rows: [], total: 0, page: 1, pages: 1, perPage: 25, loading: true,
    filters: { search: '', sex: 'All' },
    offset() { return (this.page - 1) * this.perPage; },
    async load(page = this.page) {
      this.loading = true;
      this.page = Math.max(1, page);
      const params = new URLSearchParams({ page: this.page, per_page: this.perPage, ...this.filters });
      try {
        const res = await fetch('api/agency-clients.php?' + params.toString());
        const json = await res.json();
        this.rows = json.data || [];
        this.total = json.total || 0;
        this.pages = json.pages || 1;
      } catch (err) {
        showToast('Failed to load clients.', 'error');
      } finally {
        this.loading = false;
      }
    }
  }
}
</script>
<?php else: ?>
<div x-data="applicantTable()" x-init="filters.service = 'agency_services'; load()" class="space-y-5">
```

The `<?php else: ?>` branch continues with the existing staff view exactly as it already exists in the file today, unmodified. Concretely, in the file as it stands before this task: everything from the line `<div x-data="applicantTable()" x-init="filters.service = 'agency_services'; load()" class="space-y-5">` through the line immediately before `<?php require_once __DIR__ . '/../includes/footer.php'; ?>` (i.e. the closing `</div>` of that same top-level div, followed by the closing `</script>` of its inline `applicantTable()` block) is moved as-is into this `else` branch, with one new line, `<?php endif; ?>`, inserted directly after it and directly before the `<?php require_once __DIR__ . '/../includes/footer.php'; ?>` line. That footer `require_once` line itself is untouched and stays outside the `if`/`else` — it already runs unconditionally today and must keep doing so for both branches. Do not alter, reformat, or re-type any line inside the moved block — cut and paste it verbatim.

- [ ] **Step 3: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/clients.php` and `"/c/xampp/php/php.exe" -l public/api/agency-clients.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manual browser verification**

As a Partner Agency test account, open `clients.php` — confirm it shows only clients who've availed *their* agency's services, with the columns above, and the scanner modal is present and functional (scanning a new service-eligible client and confirming a service makes them appear in this list). As staff (Administrator/Employee/Viewer), confirm `clients.php` behaves exactly as before this task (unchanged query/columns).

- [ ] **Step 5: Commit**

```bash
git add public/clients.php public/api/agency-clients.php
git commit -m "Add Partner Agency's own Clients view (api/agency-clients.php)"
```

---

### Task 9: Sidebar restructure — Settings dropdown, Services, Clients for Partner Agency

**Files:**
- Modify: `includes/sidebar.php`

**Interfaces:** none — leaf UI change.

- [ ] **Step 1: Restructure both `$navItems` arrays with a Settings dropdown**

Replace the entire `<?php ... ?>` block (from `$currentPage = basename(...)` through the closing `?>` right before `<!-- Mobile top bar -->`) with:

```php
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
// Employment section highlights for both the list and the add/edit form
$employmentPages = ['employment-list.php', 'employment-form.php'];

if (is_partner_agency()) {
    $navItems = [
        ['href' => 'dashboard.php',  'icon' => 'fa-gauge-high',        'label' => 'Dashboard',      'match' => ['dashboard.php']],
        ['href' => 'applicants.php', 'icon' => 'fa-users',             'label' => 'Applicant',      'match' => ['applicants.php', 'applicant-view.php']],
        ['href' => 'clients.php',    'icon' => 'fa-handshake',         'label' => 'Clients',         'match' => ['clients.php']],
        ['href' => 'vacancies.php',  'icon' => 'fa-briefcase-medical', 'label' => 'Job Vacancies',   'match' => ['vacancies.php']],
        ['href' => 'services.php',   'icon' => 'fa-list-check',        'label' => 'Services',        'match' => ['services.php']],
        ['href' => 'reports.php',    'icon' => 'fa-chart-column',      'label' => 'Report',          'match' => ['reports.php']],
    ];
    $settingsItems = [
        ['href' => 'my-agency.php',    'icon' => 'fa-building',     'label' => 'My Partner Agency', 'match' => ['my-agency.php']],
        ['href' => 'agency-users.php', 'icon' => 'fa-users-gear',   'label' => 'Agency Users',       'match' => ['agency-users.php']],
        ['href' => 'settings.php',     'icon' => 'fa-user-gear',    'label' => 'Account Settings',   'match' => ['settings.php']],
    ];
} else {
    $navItems = [
        ['href' => 'dashboard.php',       'icon' => 'fa-gauge-high',   'label' => 'Dashboard',  'match' => ['dashboard.php']],
        ['href' => 'applicants.php',      'icon' => 'fa-users',        'label' => 'Applicant',  'match' => ['applicants.php', 'applicant-create.php', 'applicant-edit.php', 'applicant-view.php']],
        ['href' => 'clients.php',         'icon' => 'fa-handshake',    'label' => 'Clients',    'match' => ['clients.php']],
        ['href' => 'employment-list.php', 'icon' => 'fa-briefcase',    'label' => 'Employment', 'match' => $employmentPages],
        ['href' => 'reports.php',         'icon' => 'fa-chart-column', 'label' => 'Report',     'match' => ['reports.php']],
    ];
    if (can_manage_employment()) {
        array_splice($navItems, 4, 0, [[
            'href' => 'vacancies.php', 'icon' => 'fa-briefcase-medical', 'label' => 'Job Vacancies', 'match' => ['vacancies.php'],
        ]]);
    }
    array_splice($navItems, count($navItems) - 1, 0, [[
        'href' => 'services.php', 'icon' => 'fa-list-check', 'label' => 'Services', 'match' => ['services.php'],
    ]]);

    $settingsItems = [
        ['href' => 'partner-agency.php', 'icon' => 'fa-building', 'label' => 'Partner Agency', 'match' => ['partner-agency.php', 'partner-agency-form.php']],
    ];
    if (can_manage_users()) {
        $settingsItems[] = ['href' => 'users.php', 'icon' => 'fa-user-shield', 'label' => 'Users', 'match' => ['users.php']];
    }
    if (can_view_audit_logs()) {
        $settingsItems[] = ['href' => 'audit-logs.php', 'icon' => 'fa-clipboard-list', 'label' => 'Audit Logs', 'match' => ['audit-logs.php']];
    }
    $settingsItems[] = ['href' => 'settings.php', 'icon' => 'fa-user-gear', 'label' => 'Account Settings', 'match' => ['settings.php']];
}

// The Settings dropdown starts expanded when the current page is one of
// its own sub-pages, so the active section stays visibly open on load.
$settingsPages = array_merge(...array_column($settingsItems, 'match'));
$settingsOpenDefault = in_array($currentPage, $settingsPages, true);
?>
<!-- Mobile top bar -->
```

- [ ] **Step 2: Render the Settings dropdown in the nav loop**

Change:
```php
  <nav class="flex-1 overflow-y-auto py-5 px-3 space-y-1">
    <?php foreach ($navItems as $item): ?>
      <a href="<?= e($item['href']) ?>"
         class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition
                <?= in_array($currentPage, $item['match'], true) ? 'bg-brand-600 text-white shadow' : 'hover:bg-white/5 text-brand-100' ?>">
        <i class="fa-solid <?= e($item['icon']) ?> w-4 text-center"></i>
        <?= e($item['label']) ?>
      </a>
    <?php endforeach; ?>
  </nav>
```
to:
```php
  <nav class="flex-1 overflow-y-auto py-5 px-3 space-y-1" x-data="{ settingsOpen: <?= $settingsOpenDefault ? 'true' : 'false' ?> }">
    <?php foreach ($navItems as $item): ?>
      <a href="<?= e($item['href']) ?>"
         class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition
                <?= in_array($currentPage, $item['match'], true) ? 'bg-brand-600 text-white shadow' : 'hover:bg-white/5 text-brand-100' ?>">
        <i class="fa-solid <?= e($item['icon']) ?> w-4 text-center"></i>
        <?= e($item['label']) ?>
      </a>
    <?php endforeach; ?>

    <button type="button" @click="settingsOpen = !settingsOpen"
            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition
                   <?= $settingsOpenDefault ? 'bg-white/5' : '' ?> hover:bg-white/5 text-brand-100">
      <i class="fa-solid fa-gear w-4 text-center"></i>
      <span class="flex-1 text-left">Settings</span>
      <i class="fa-solid fa-chevron-down text-xs transition-transform" :class="settingsOpen ? 'rotate-180' : ''"></i>
    </button>
    <div x-show="settingsOpen" x-collapse class="pl-4 space-y-1">
      <?php foreach ($settingsItems as $item): ?>
        <a href="<?= e($item['href']) ?>"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                  <?= in_array($currentPage, $item['match'], true) ? 'bg-brand-600 text-white shadow' : 'hover:bg-white/5 text-brand-100' ?>">
          <i class="fa-solid <?= e($item['icon']) ?> w-4 text-center text-xs"></i>
          <?= e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>
```

`x-collapse` is an Alpine plugin — check `includes/header.php`/`footer.php` for whether Alpine's collapse plugin is already vendored/loaded in this app (grep for `alpine` script tags and `collapse` across `public/assets/vendor/`). If it is not already present, replace `x-collapse` with a plain `x-show x-cloak` (no animated height transition, immediate show/hide) instead of adding a new vendored dependency — simpler and consistent with this app's existing no-new-dependencies convention; note which one you used in your task report.

- [ ] **Step 3: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l includes/sidebar.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual browser verification**

As a Partner Agency test account: confirm the sidebar shows Dashboard, Applicant, Clients, Job Vacancies, Services, Report, Settings ▾ (My Partner Agency, Agency Users, Account Settings), and Logout is still always visible without scrolling. As Administrator: confirm Dashboard, Applicant, Clients, Employment, Job Vacancies, Services, Report, Settings ▾ (Partner Agency, Users, Audit Logs, Account Settings), Logout. Click Settings to confirm it expands/collapses; navigate directly to `users.php` and confirm Settings auto-expands on load. Confirm every relocated link (My Partner Agency, Agency Users, Partner Agency, Users, Audit Logs, Account Settings) still works.

- [ ] **Step 5: Commit**

```bash
git add includes/sidebar.php
git commit -m "Restructure sidebar nav: collapsible Settings group, standalone Services, Clients for Partner Agency"
```

---

### Task 10: Uppercase verification pass

**Files:** none expected (verification only — fix inline only if a genuine gap is found)

- [ ] **Step 1: Verify every field this phase introduced already uppercases on save**

Confirm (by reading the relevant code, already written in Tasks 5-7): `custom_service_name` in `api/service-availment-confirm.php` (`mb_strtoupper(trim(clean(...)), 'UTF-8')`), `custom_service_name` in `applicant-view.php`'s `tag_for_service` branch (same pattern), `service_name`/`description` in `services.php`'s `add_service`/`edit_service` (`mb_strtoupper(...)` for name; note `description` in this codebase's established pattern for this exact field, per `my-agency.php`'s Phase 1 version, is NOT uppercased — `clean($_POST['description'] ?? '')` only, matching what Task 7 copied — confirm this matches Phase 1's precedent exactly rather than introducing a new inconsistency; if the source document's field list (§1 explicitly lists "SERVICE DETAILS") is read as requiring `description` to also be uppercased, that's a real gap — check with the user rather than guessing, since it means changing established Phase 1 behavior, not just this phase's new code).

- [ ] **Step 2: Spot-check the frontend `text-transform: uppercase` layer**

Confirm the new/relocated text inputs use this app's existing `uppercase-field uppercase` CSS class combo (already applied in Task 4's custom-service-name input, Task 6's custom-service-name input, Task 7's service_name/description inputs) — grep `class="uppercase-field uppercase` across the files this phase touched and confirm every free-text field that should be uppercased has it.

- [ ] **Step 3: Report findings**

If Step 1 surfaces a genuine ambiguity (the `description` field question above) or any missed field, stop and flag it explicitly rather than silently deciding — this is a scope/behavior question, not a bug fix. If everything checks out, note that in the task report with no code changes.

---

### Task 11: End-to-end regression verification

**Files:** none (verification only)

- [ ] **Step 1: Run `php -l` across every file touched or created by this plan**

```bash
"/c/xampp/php/php.exe" -l includes/functions.php
"/c/xampp/php/php.exe" -l public/api/qr-tag.php
"/c/xampp/php/php.exe" -l includes/qr-scanner-modal.php
"/c/xampp/php/php.exe" -l public/api/service-availment-confirm.php
"/c/xampp/php/php.exe" -l public/applicant-view.php
"/c/xampp/php/php.exe" -l public/services.php
"/c/xampp/php/php.exe" -l public/partner-agency-form.php
"/c/xampp/php/php.exe" -l public/my-agency.php
"/c/xampp/php/php.exe" -l public/clients.php
"/c/xampp/php/php.exe" -l public/api/agency-clients.php
"/c/xampp/php/php.exe" -l includes/sidebar.php
```
Expected: `No syntax errors detected` for all eleven.

- [ ] **Step 2: Full-flow browser walkthrough**

1. As a Partner Agency test account, scan/manually-enter a new service-eligible client's code — confirm the Service Availment modal opens with the correct agency name (yours) and client name, pick a real service, Confirm — confirm the profile's Services Availed History shows it, and `clients.php` now lists them.
2. Re-scan the SAME client at the SAME agency — confirm the modal reopens (not an error), pick `OTHERS` with custom text, Confirm — confirm the SAME row updated (no duplicate row: `SELECT COUNT(*) FROM care_jf_service_availments WHERE applicant_id=<id> AND agency_id=<id>` returns 1), and the custom text shows uppercased.
3. Scan a Job-Seeker-only applicant — confirm behavior is completely unchanged from before this phase (instant tag, no modal, normal navigation).
4. As Administrator, open `services.php` — add a service to a specific agency via the dropdown, confirm it appears; as that agency's Partner Agency account, confirm the same service now appears in their own `services.php` and their service-availment modal's dropdown.
5. As Administrator, confirm the sidebar's Settings group and all relocated links work; confirm `clients.php` for staff is unchanged; confirm `partner-agency-form.php`/`my-agency.php` no longer show an embedded Services section.
6. Regression: existing Job Seeker QR-tagging, "Tag for Review," hiring workflow, Employment History visibility (all from Phase 1) — spot-check each still works exactly as before.

Expected: every check above matches its stated expectation, no PHP warnings/errors in the browser or webserver error log.

This task has no commit of its own — it is a verification gate. If any check fails, return to the relevant task, fix, and re-verify that task's own steps before re-running this one.
