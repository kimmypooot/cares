# Client / Agency-Service Availment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give "client" registrants (applicants who checked "Avail Agency Service") their own service-availment tracking, gate the existing job-seeker QR/manual tagging to only job seekers, relax registration validation for non-job-seekers, and add a Clients list page and a reference-only Partner Agency Services catalog.

**Architecture:** No new person/registrant table — `care_jf_applicants` is untouched. A new sibling table to `care_jf_employment_records` (`care_jf_service_availments`) tracks which Partner Agency a client's service was availed with, populated by a new `tag_applicant_for_service()` function that mirrors the existing `tag_applicant_for_agency()`. The existing QR-scan endpoint and "Tag for Review" manual flow are extended to call the right tagging function(s) based on the applicant's own `service_job_seeker`/`service_agency_services` flags. A new reference-only catalog table (`care_jf_agency_services`) is managed inline on the existing agency-editing pages.

**Tech Stack:** PHP 8, MySQL/MariaDB via PDO, Tailwind CSS (prebuilt), Alpine.js. No test framework — this repo verifies changes via `php -l`, direct `mysql` CLI queries against the live dev database, and manual browser exercise (see CLAUDE.md).

**Spec:** `docs/superpowers/specs/2026-09-10-client-service-availment-design.md`

## Global Constraints

- Every state-changing POST handler calls `csrf_require()` before touching the database; every form emits `csrf_field()`.
- Authorization is checked both at the top of the page and again inside every POST handler on that page (never trust the top-of-file gate alone).
- Agency identity for a Partner Agency user is always `current_agency_id($pdo)` — never trusted from request input.
- All dynamic output goes through `e()`. All SQL goes through PDO prepared statements.
- Every create/update/enable/disable calls `audit_log($pdo, $userId, $action, $table, $recordId, $description)`.
- No hard deletes of tracking-style rows — soft-invalidate via a `status` column, matching `care_jf_employment_records`/`care_jf_job_vacancies`.
- No automated test suite exists. "Test" steps in this plan mean: `php -l` for syntax, a direct `mysql` CLI query or small one-off PHP CLI snippet to verify data/behavior, and/or a manual browser check — never `pytest`/`phpunit`.
- DB name is `care_job_fair_db` (per `config/database.php`); connect via `"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db` or `"/c/xampp/php/php.exe"` (this environment's paths).

---

### Task 1: Database migration — new tables

**Files:**
- Create: `database/migrations/add_service_availments_and_agency_services.sql`

**Interfaces:**
- Produces: `care_jf_service_availments` (columns: `id, applicant_id, agency_id, source, status, created_at, updated_at`), `care_jf_agency_services` (columns: `id, agency_id, service_name, description, status, created_at, updated_at`) — every later task's SQL reads/writes these exact column names.

- [ ] **Step 1: Write the migration file**

```sql
-- =====================================================================
-- Migration: add_service_availments_and_agency_services.sql
-- Adds two new tables for the Client / Agency-Service Availment feature
-- (see docs/superpowers/specs/2026-09-10-client-service-availment-design.md):
--   - care_jf_service_availments: tracks which Partner Agency a client's
--     (an applicant with service_agency_services=1) service was availed
--     with. Sibling to care_jf_employment_records but with no Hired/
--     vacancy lifecycle and no state machine.
--   - care_jf_agency_services: a reference-only catalog of each Partner
--     Agency's own services, never selected per-availment in this phase.
-- No changes to any existing table.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_service_availments_and_agency_services.sql
-- =====================================================================

USE care_job_fair_db;

CREATE TABLE IF NOT EXISTS care_jf_service_availments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  applicant_id  INT UNSIGNED NOT NULL,
  agency_id     INT UNSIGNED NOT NULL,
  source        ENUM('manual','qr_scan') NOT NULL DEFAULT 'qr_scan',
  status        ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_availment_applicant FOREIGN KEY (applicant_id) REFERENCES care_jf_applicants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_availment_agency FOREIGN KEY (agency_id) REFERENCES care_jf_partner_agencies(id) ON DELETE RESTRICT,
  UNIQUE KEY uniq_applicant_agency (applicant_id, agency_id),
  INDEX idx_availment_agency (agency_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS care_jf_agency_services (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agency_id     INT UNSIGNED NOT NULL,
  service_name  VARCHAR(200) NOT NULL,
  description   VARCHAR(500) NULL,
  status        ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_service_agency FOREIGN KEY (agency_id) REFERENCES care_jf_partner_agencies(id) ON DELETE RESTRICT,
  INDEX idx_service_agency (agency_id)
) ENGINE=InnoDB;

SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS service_availments FROM care_jf_service_availments;
SELECT COUNT(*) AS agency_services FROM care_jf_agency_services;
```

- [ ] **Step 2: Run the migration against the live dev database**

Run: `"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db < database/migrations/add_service_availments_and_agency_services.sql`
Expected: prints `Migration complete.` then two zero counts, no errors.

- [ ] **Step 3: Verify both tables exist with the right columns**

Run: `"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db -e "SHOW COLUMNS FROM care_jf_service_availments; SHOW COLUMNS FROM care_jf_agency_services;"`
Expected: both tables listed with exactly the columns above.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/add_service_availments_and_agency_services.sql
git commit -m "Add care_jf_service_availments and care_jf_agency_services tables"
```

---

### Task 2: `tag_applicant_for_service()` function

**Files:**
- Modify: `includes/functions.php` (insert after the closing `}` of `tag_applicant_for_agency()`, currently at line 362, before `is_valid_email()`)

**Interfaces:**
- Consumes: nothing new (uses `$pdo`, `audit_log()` already in this file).
- Produces: `tag_applicant_for_service(PDO $pdo, int $applicantId, string $applicantCode, int $agencyId, int $actingUserId, string $source, string $sourceDetail = ''): string` returning one of `'created' | 'duplicate' | 'agency_invalid'`. Task 6 and Task 7 call this exact signature.

- [ ] **Step 1: Add the function**

Insert immediately after line 362 (`}` closing `tag_applicant_for_agency`) in `includes/functions.php`:

```php
/**
 * Associates a client (an applicant with service_agency_services=1) with
 * a Partner Agency by inserting a care_jf_service_availments row. Mirrors
 * tag_applicant_for_agency()'s agency-validity and duplicate checks, but
 * has no "already hired" gate — employment status has no bearing on
 * whether someone can avail a Partner Agency's service — and no Hired/
 * vacancy lifecycle to advance later.
 *
 * NOTE: $agencyId is trusted as-is — this function does not verify it
 * belongs to the acting user. Callers for a Partner Agency user MUST
 * derive it via current_agency_id(), never from request input.
 */
function tag_applicant_for_service(
    PDO $pdo, int $applicantId, string $applicantCode,
    int $agencyId, int $actingUserId, string $source, string $sourceDetail = ''
): string {
    $agStmt = $pdo->prepare("SELECT agency_name FROM care_jf_partner_agencies WHERE id = :id AND status = 'Active'");
    $agStmt->execute([':id' => $agencyId]);
    $agencyRow = $agStmt->fetch();
    if (!$agencyRow) {
        return 'agency_invalid';
    }

    $dupStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM care_jf_service_availments WHERE applicant_id = :id AND agency_id = :agid"
    );
    $dupStmt->execute([':id' => $applicantId, ':agid' => $agencyId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        return 'duplicate';
    }

    $tagStmt = $pdo->prepare(
        "INSERT INTO care_jf_service_availments (applicant_id, agency_id, source, status)
         VALUES (:aid, :agid, :source, 'Active')"
    );
    $tagStmt->execute([':aid' => $applicantId, ':agid' => $agencyId, ':source' => $source]);
    $newId = (int)$pdo->lastInsertId();

    $actionCode = $source === 'qr_scan' ? 'SERVICE_AVAILED_AUTO_TAGGED_QR' : 'SERVICE_AVAILED_TAGGED';
    if ($source === 'qr_scan') {
        if ($sourceDetail === 'camera') {
            $verb = 'auto-tagged (service availed) via QR camera scan by';
        } elseif ($sourceDetail === 'manual') {
            $verb = 'auto-tagged (service availed) via confirmed manual code entry by';
        } else {
            $verb = 'auto-tagged (service availed) via QR scan by';
        }
    } else {
        $verb = 'tagged (service availed) by';
    }
    audit_log($pdo, $actingUserId, $actionCode, 'care_jf_service_availments', $newId,
        "Applicant {$applicantCode} {$verb} {$agencyRow['agency_name']}");

    return 'created';
}
```

- [ ] **Step 2: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l includes/functions.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Smoke-test against the live DB with a throwaway CLI script**

Write a temporary script (outside the repo, e.g. in a scratch directory) that requires `config/database.php` and `includes/functions.php`, then:

```php
<?php
require '/c/xampp/htdocs/applicant-system/config/database.php';
require '/c/xampp/htdocs/applicant-system/includes/functions.php';
$pdo = Database::getConnection();
// Use an existing applicant id and an existing Active agency id from your dev DB.
$result = tag_applicant_for_service($pdo, 1, 'TEST-CODE', 1, 1, 'manual');
echo $result, "\n"; // expect 'created' (or 'duplicate' if already tagged)
$result2 = tag_applicant_for_service($pdo, 1, 'TEST-CODE', 1, 1, 'manual');
echo $result2, "\n"; // expect 'duplicate'
```

Run it with `"/c/xampp/php/php.exe" <script>`, then clean up the test row:
`"/c/xampp/mysql/bin/mysql.exe" -u root care_job_fair_db -e "DELETE FROM care_jf_service_availments WHERE applicant_id=1 AND agency_id=1;"`
Expected: first call prints `created`, second prints `duplicate`; one `care_jf_audit_logs` row with action `SERVICE_AVAILED_TAGGED` was written (`SELECT * FROM care_jf_audit_logs ORDER BY id DESC LIMIT 1;`).

- [ ] **Step 4: Commit**

```bash
git add includes/functions.php
git commit -m "Add tag_applicant_for_service() for client service-availment tagging"
```

---

### Task 3: Conditional validation — `register-applicant.php`

**Files:**
- Modify: `public/register-applicant.php:52-96`

**Interfaces:**
- Consumes: `$old['service_job_seeker']` (already set at line 28).
- Produces: nothing new consumed elsewhere — purely a validation-gating change to an existing file.

- [ ] **Step 1: Wrap the three validation blocks in a Job-Seeker check**

Replace lines 52–96 (the `educational_level` check through the end of the `eligibility_status` else-branch) — currently:

```php
    if (!in_array($old['educational_level'], $educLevelOptions, true)) {
        $errors['educational_level'] = 'Please select an educational level.';
    }

    if (!in_array($old['completion_status'], ['Not Graduated', 'Graduated'], true)) {
        $errors['completion_status'] = 'Please select completion status.';
    } elseif ($old['completion_status'] === 'Not Graduated') {
        if ($old['highest_year_level_units'] === '') {
            $errors['highest_year_level_units'] = 'Highest year/level/units earned is required.';
        }
        $old['date_graduated'] = '';
        $old['course_degree'] = '';
        $old['school_name'] = '';
        $old['school_address'] = '';
    } else {
        if ($old['date_graduated'] === '' || !strtotime($old['date_graduated'])) {
            $errors['date_graduated'] = 'A valid graduation date is required.';
        } elseif (strtotime($old['date_graduated']) > time()) {
            $errors['date_graduated'] = 'Date graduated cannot be in the future.';
        }
        if ($old['course_degree'] === '') $errors['course_degree'] = 'Complete title of course/degree is required.';
        if ($old['school_name'] === '') $errors['school_name'] = 'Name of school is required.';
        if ($old['school_address'] === '') $errors['school_address'] = 'School address is required.';
        $old['highest_year_level_units'] = '';
    }

    if (!in_array($old['eligibility_status'], ['Eligible', 'Not Eligible'], true)) {
        $errors['eligibility_status'] = 'Please select eligibility status.';
    } elseif ($old['eligibility_status'] === 'Not Eligible') {
        $old['eligibility_type'] = '';
        $old['other_eligibility_type'] = '';
    } else {
        if (!in_array($old['eligibility_type'], $eligibilityTypeOptions, true)) {
            $errors['eligibility_type'] = 'Please select an eligibility type.';
        }
        if ($old['eligibility_type'] === 'Other Eligibility') {
            if ($old['other_eligibility_type'] === '') {
                $errors['other_eligibility_type'] = 'Please specify the other eligibility type.';
            } elseif (mb_strlen($old['other_eligibility_type']) > 150) {
                $errors['other_eligibility_type'] = 'Other eligibility type must be 150 characters or fewer.';
            }
        } else {
            $old['other_eligibility_type'] = '';
        }
    }
```

with the same block wrapped in `if ($old['service_job_seeker']) { ... }`:

```php
    // Educational Attainment and Eligibility are only required when
    // registering as a Job Seeker — an Avail-Agency-Service-only
    // registrant can still optionally fill them in (nothing below
    // clears $old for these fields when Job Seeker is unchecked), they
    // just aren't validated or required.
    if ($old['service_job_seeker']) {
        if (!in_array($old['educational_level'], $educLevelOptions, true)) {
            $errors['educational_level'] = 'Please select an educational level.';
        }

        if (!in_array($old['completion_status'], ['Not Graduated', 'Graduated'], true)) {
            $errors['completion_status'] = 'Please select completion status.';
        } elseif ($old['completion_status'] === 'Not Graduated') {
            if ($old['highest_year_level_units'] === '') {
                $errors['highest_year_level_units'] = 'Highest year/level/units earned is required.';
            }
            $old['date_graduated'] = '';
            $old['course_degree'] = '';
            $old['school_name'] = '';
            $old['school_address'] = '';
        } else {
            if ($old['date_graduated'] === '' || !strtotime($old['date_graduated'])) {
                $errors['date_graduated'] = 'A valid graduation date is required.';
            } elseif (strtotime($old['date_graduated']) > time()) {
                $errors['date_graduated'] = 'Date graduated cannot be in the future.';
            }
            if ($old['course_degree'] === '') $errors['course_degree'] = 'Complete title of course/degree is required.';
            if ($old['school_name'] === '') $errors['school_name'] = 'Name of school is required.';
            if ($old['school_address'] === '') $errors['school_address'] = 'School address is required.';
            $old['highest_year_level_units'] = '';
        }

        if (!in_array($old['eligibility_status'], ['Eligible', 'Not Eligible'], true)) {
            $errors['eligibility_status'] = 'Please select eligibility status.';
        } elseif ($old['eligibility_status'] === 'Not Eligible') {
            $old['eligibility_type'] = '';
            $old['other_eligibility_type'] = '';
        } else {
            if (!in_array($old['eligibility_type'], $eligibilityTypeOptions, true)) {
                $errors['eligibility_type'] = 'Please select an eligibility type.';
            }
            if ($old['eligibility_type'] === 'Other Eligibility') {
                if ($old['other_eligibility_type'] === '') {
                    $errors['other_eligibility_type'] = 'Please specify the other eligibility type.';
                } elseif (mb_strlen($old['other_eligibility_type']) > 150) {
                    $errors['other_eligibility_type'] = 'Other eligibility type must be 150 characters or fewer.';
                }
            } else {
                $old['other_eligibility_type'] = '';
            }
        }
    }
```

- [ ] **Step 2: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/register-applicant.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual browser verification**

Open `register-applicant.php` in a browser. Check "Avail Agency Services" only (leave "Job Seeker" unchecked), fill only Last Name/First Name/Address, leave Educational Attainment and Eligibility completely blank, submit. Expected: registration succeeds (no "select an educational level" / "select eligibility status" errors), redirects with a success code. Then check just "Job Seeker" with the same blank Educational/Eligibility fields — expected: the existing required-field errors reappear exactly as before this change.

- [ ] **Step 4: Commit**

```bash
git add public/register-applicant.php
git commit -m "Only require Educational Attainment/Eligibility when registering as Job Seeker"
```

---

### Task 4: Conditional validation — `applicant-create.php`

**Files:**
- Modify: `public/applicant-create.php:66-110`

**Interfaces:** Same as Task 3 — no new interfaces, gating an existing block.

- [ ] **Step 1: Apply the identical wrap**

Wrap lines 66–110 of `public/applicant-create.php` (byte-for-byte the same three blocks as Task 3, this file's copy) in `if ($old['service_job_seeker']) { ... }`, exactly as done in Task 3 — same before/after content, same comment.

- [ ] **Step 2: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/applicant-create.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual browser verification**

As an Administrator/Employee, open `applicant-create.php`. Repeat the same two checks as Task 3 Step 3 (Avail-Agency-Service-only succeeds with blank Educational/Eligibility; Job-Seeker-checked still requires them).

- [ ] **Step 4: Commit**

```bash
git add public/applicant-create.php
git commit -m "Only require Educational Attainment/Eligibility when registering as Job Seeker (staff form)"
```

---

### Task 5: Conditional validation — `applicant-edit.php`

**Files:**
- Modify: `public/applicant-edit.php:100-140+` (the `educational_level` check through the end of the `eligibility_status` else-branch, same three-block shape as Tasks 3–4; read the file first to confirm the exact current end line since it was only partially read during planning — the content is identical to Task 3's blocks)

**Interfaces:** Same as Task 3.

- [ ] **Step 1: Apply the identical wrap**

Read `public/applicant-edit.php` from line 100 to find the exact closing line of the eligibility block (it mirrors Task 3's content exactly), then wrap that whole span in `if ($old['service_job_seeker']) { ... }` with the same explanatory comment used in Task 3.

- [ ] **Step 2: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/applicant-edit.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual browser verification**

Edit an existing applicant: uncheck "Job Seeker", leave only "Avail Agency Services" checked, clear Educational Attainment/Eligibility fields, save. Expected: save succeeds. Re-open and check "Job Seeker" again with those fields still blank, save. Expected: the existing required-field errors appear exactly as before this change.

- [ ] **Step 4: Commit**

```bash
git add public/applicant-edit.php
git commit -m "Only require Educational Attainment/Eligibility when registering as Job Seeker (edit form)"
```

---

### Task 6: Dual tagging in `public/api/qr-tag.php`

**Files:**
- Modify: `public/api/qr-tag.php`

**Interfaces:**
- Consumes: `tag_applicant_for_agency()` (existing), `tag_applicant_for_service()` (Task 2).
- Produces: response JSON shape `{ok: bool, status?: string, applicant_id?: int, employment_status?: string, service_status?: string}` — Task 9's manual "Tag for Service" flow does not consume this (it's a different endpoint), but any future JS change would.

- [ ] **Step 1: Replace the lookup query and tagging call**

Replace:
```php
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
    echo json_encode(['ok' => false, 'status' => 'agency_invalid', 'applicant_id' => $applicantId]);
    exit;
}

$result = tag_applicant_for_agency(
    $pdo, $applicantId, $applicant['applicant_code'], $agencyId, (int)current_user()['id'], 'qr_scan', $via
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

with:
```php
$stmt = $pdo->prepare(
    "SELECT id, applicant_code, service_job_seeker, service_agency_services
     FROM care_jf_applicants WHERE applicant_code = :code AND is_deleted = 0"
);
$stmt->execute([':code' => $code]);
$applicant = $stmt->fetch();

if (!$applicant) {
    echo json_encode(['ok' => false, 'status' => 'not_found']);
    exit;
}

$applicantId = (int)$applicant['id'];
$agencyId = current_agency_id($pdo);

if (!$agencyId) {
    echo json_encode(['ok' => false, 'status' => 'agency_invalid', 'applicant_id' => $applicantId]);
    exit;
}

$actingUserId = (int)current_user()['id'];
$results = [];
$flashParts = [];

if ($applicant['service_job_seeker']) {
    $results['employment'] = tag_applicant_for_agency(
        $pdo, $applicantId, $applicant['applicant_code'], $agencyId, $actingUserId, 'qr_scan', $via
    );
    if ($results['employment'] === 'created') {
        $flashParts[] = 'tagged for review';
    } elseif ($results['employment'] === 'duplicate') {
        $flashParts[] = 'already associated with your agency';
    }
}
if ($applicant['service_agency_services']) {
    $results['service'] = tag_applicant_for_service(
        $pdo, $applicantId, $applicant['applicant_code'], $agencyId, $actingUserId, 'qr_scan', $via
    );
    if ($results['service'] === 'created') {
        $flashParts[] = 'service availed logged with your agency';
    } elseif ($results['service'] === 'duplicate') {
        $flashParts[] = 'service availment already on file with your agency';
    }
}

// agency_invalid is identical for both calls (same $agencyId every time) —
// checking either is representative of "the scanning agency's own
// account is not valid right now."
$anyAgencyInvalid = in_array('agency_invalid', $results, true);
if ($anyAgencyInvalid) {
    echo json_encode(['ok' => false, 'status' => 'agency_invalid', 'applicant_id' => $applicantId]);
    exit;
}

if ($flashParts) {
    flash_set('success', 'Applicant ' . implode(' and ', $flashParts) . '.');
}

echo json_encode(array_merge(
    ['ok' => true, 'applicant_id' => $applicantId],
    isset($results['employment']) ? ['employment_status' => $results['employment']] : [],
    isset($results['service']) ? ['service_status' => $results['service']] : []
));
```

Note: `already_hired` (a possible value of `$results['employment']`) intentionally adds no `$flashParts` entry (matches today's silent behavior for that case) and does not trigger the `agency_invalid` early-exit, so `ok` stays `true` and the page still navigates — identical to current behavior.

- [ ] **Step 2: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/api/qr-tag.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification via direct POST (simulating a scan)**

As a logged-in Partner Agency test account, in a browser dev console on any page of the app, run:
```js
fetch('api/qr-tag.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
  body: JSON.stringify({ code: 'APP-202609-000001', via: 'manual' }) // use a real applicant_code from your dev DB
}).then(r => r.json()).then(console.log);
```
Expected, for an applicant with only `service_agency_services=1`: response has `service_status: "created"` and no `employment_status` key; a new row appears in `care_jf_service_availments` and none in `care_jf_employment_records`. For an applicant with only `service_job_seeker=1`: `employment_status: "created"`, no `service_status` key, exactly today's pre-existing behavior. For an applicant with both flags: both keys present, one row in each table.

- [ ] **Step 4: Commit**

```bash
git add public/api/qr-tag.php
git commit -m "Gate QR auto-tagging to the applicant's own service flags, add service-availment tagging"
```

---

### Task 7: `applicant-view.php` — gate Employment History, add Services Availed History

**Files:**
- Modify: `public/applicant-view.php`

**Interfaces:**
- Consumes: `tag_applicant_for_service()` (Task 2).
- Produces: new `tag_for_service` POST action (consumed only by this file's own form).

- [ ] **Step 1: Add the `tag_for_service` POST action**

Insert a new `elseif` branch immediately after the existing `tag_for_review` branch (after line 131, before `} elseif ($action === 'confirm_hired') {`):

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

(The next line, `} elseif ($action === 'confirm_hired') {`, is unchanged — this new branch is inserted before it.)

- [ ] **Step 2: Gate the Employment History card and compute Services Availed History data**

Replace (around line 491-493):
```php
      <?php
        $canTagAsPartnerAgency = is_partner_agency() && !$myOpenReview && !$applicantIsHired;
        $canTagAsStaff = can_manage_employment() && $tagAgencyOptions && !$applicantIsHired;
      ?>
```
with:
```php
      <?php
        $canTagAsPartnerAgency = is_partner_agency() && !$myOpenReview && !$applicantIsHired && !empty($applicant['service_job_seeker']);
        $canTagAsStaff = can_manage_employment() && $tagAgencyOptions && !$applicantIsHired && !empty($applicant['service_job_seeker']);
      ?>
```

Then wrap the entire Employment History card — from `<div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6" x-data="{ confirmHireRecordId: null, showTagForReview: false }">` (currently line 495) through its matching closing `</div>` (currently line ~630, the one immediately before the next top-level card or end of the `md:col-span-2` column) — in `<?php if (!empty($applicant['service_job_seeker'])): ?> ... <?php endif; ?>`. Locate the exact closing `</div>` by reading the file (it's the div that closes the `x-data="{ confirmHireRecordId: ..." block, immediately before whatever comes next in the `md:col-span-2` column) rather than guessing a line number.

- [ ] **Step 3: Add the query for this applicant's service-availment history**

Immediately after the existing `$tagAgencyOptions = ...` assignment (currently ending at line 375), add:

```php
$serviceAvailmentsStmt = $pdo->prepare(
    "SELECT sa.*, pa.agency_name FROM care_jf_service_availments sa
     JOIN care_jf_partner_agencies pa ON pa.id = sa.agency_id
     WHERE sa.applicant_id = :id ORDER BY sa.created_at DESC"
);
$serviceAvailmentsStmt->execute([':id' => $id]);
$serviceAvailments = $serviceAvailmentsStmt->fetchAll();

$myOpenServiceAvailment = null;
if (is_partner_agency()) {
    foreach ($serviceAvailments as $sa) {
        if ((int)$sa['agency_id'] === $myAgencyIdForCheck) {
            $myOpenServiceAvailment = $sa;
            break;
        }
    }
}

$canTagForServiceAsPartnerAgency = is_partner_agency() && !$myOpenServiceAvailment && !empty($applicant['service_agency_services']);
$canTagForServiceAsStaff = can_manage_employment() && !empty($applicant['service_agency_services']);
```

- [ ] **Step 4: Add the Services Availed History card**

Immediately after the Employment History card's closing `</div>` (the one now wrapped by the `service_job_seeker` `if`/`endif` from Step 2), add a new card, gated on `service_agency_services`:

```php
      <?php if (!empty($applicant['service_agency_services'])): ?>
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6" x-data="{ showTagForService: false }">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Services Availed History</h2>
          <div class="flex gap-3 print:hidden">
            <?php if ($canTagForServiceAsPartnerAgency || $canTagForServiceAsStaff): ?>
            <button type="button" @click="showTagForService = true" class="text-xs font-medium text-emerald-600 hover:text-emerald-800"><i class="fa-solid fa-flag mr-1"></i> Tag for Service</button>
            <?php endif; ?>
          </div>
        </div>

        <div x-show="showTagForService" x-cloak class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4">
          <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
            <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-flag text-emerald-600 mr-1"></i> Tag for Service</h3>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="tag_for_service">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <?php if (can_manage_employment()): ?>
                <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency <span class="text-red-500">*</span></label>
                <select name="agency_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4">
                  <option value="">Select a Partner Agency</option>
                  <?php foreach (active_agencies($pdo) as $ag): ?>
                    <option value="<?= (int)$ag['id'] ?>"><?= e($ag['agency_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <p class="text-sm text-slate-600 mb-5">Log a service availment for <?= e(full_name($applicant)) ?> with <strong><?= e($myAgencyName) ?></strong>?</p>
              <?php endif; ?>
              <div class="flex justify-end gap-2">
                <button type="button" @click="showTagForService = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium">Confirm Tag</button>
              </div>
            </form>
          </div>
        </div>

        <?php if (!$serviceAvailments): ?>
          <div class="text-center py-8 text-slate-400">
            <i class="fa-solid fa-handshake text-2xl mb-2 block"></i> No service availments yet.
          </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-xs uppercase text-slate-500 border-b border-slate-100">
              <tr>
                <th class="text-left py-2 pr-3">Agency</th>
                <th class="text-left py-2 pr-3">Date Tagged</th>
                <th class="text-left py-2 pr-3">Source</th>
                <th class="text-left py-2 pr-3">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($serviceAvailments as $sa): ?>
              <tr>
                <td class="py-2.5 pr-3"><?= e($sa['agency_name']) ?></td>
                <td class="py-2.5 pr-3"><?= format_date($sa['created_at']) ?></td>
                <td class="py-2.5 pr-3"><?= e($sa['source'] === 'qr_scan' ? 'QR Scan' : 'Manual') ?></td>
                <td class="py-2.5 pr-3">
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $sa['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($sa['status']) ?></span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
```

- [ ] **Step 5: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/applicant-view.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Manual browser verification**

View an applicant with only `service_agency_services=1`: confirm no Employment History card or "Tag for Review" button appear anywhere, and a "Services Availed History" card is present with a working "Tag for Service" button (as staff, pick an agency and confirm; as a Partner Agency test account, confirm the forced-own-agency path). View an applicant with only `service_job_seeker=1`: confirm Employment History behaves exactly as before this change and no Services Availed History card appears. View an applicant with both flags: confirm both cards appear and both tagging actions work independently.

- [ ] **Step 7: Commit**

```bash
git add public/applicant-view.php
git commit -m "Gate Employment History to Job Seekers, add Services Availed History for Clients"
```

---

### Task 8: `service` filter in `public/api/applicants.php`

**Files:**
- Modify: `public/api/applicants.php`

**Interfaces:**
- Produces: new optional GET param `service` (`job_seeker` | `agency_services` | absent) on this existing endpoint. Task 9's `clients.php` sends `service=agency_services`.

- [ ] **Step 1: Add the filter**

After line 22 (`$employmentStatus = clean($_GET['employment_status'] ?? '');`), add:
```php
$service = clean($_GET['service'] ?? '');
```

After the existing `if (is_partner_agency()) { ... }` block (currently ending at line 71, before `$whereSql = implode(...)`), add:
```php
if ($service === 'job_seeker') {
    $where[] = 'a.service_job_seeker = 1';
} elseif ($service === 'agency_services') {
    $where[] = 'a.service_agency_services = 1';
}
```

- [ ] **Step 2: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/api/applicants.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification via direct request**

While logged in (any staff role), visit `api/applicants.php?service=agency_services` directly in the browser. Expected: JSON `data` array contains only applicants with `service_agency_services=1` (cross-check a couple of ids against `SELECT id, service_job_seeker, service_agency_services FROM care_jf_applicants WHERE id IN (...)`). Visit `api/applicants.php?service=job_seeker` and confirm the complementary result. Visit `api/applicants.php` with no `service` param and confirm the result set is unchanged from before this change (all applicants, same as today).

- [ ] **Step 4: Commit**

```bash
git add public/api/applicants.php
git commit -m "Add optional service filter to the applicants list API"
```

---

### Task 9: New Clients list page + sidebar nav

**Files:**
- Create: `public/clients.php`
- Modify: `includes/sidebar.php:19-25`

**Interfaces:**
- Consumes: `public/api/applicants.php?service=agency_services` (Task 8).

- [ ] **Step 1: Add the sidebar nav entry**

In `includes/sidebar.php`, replace the staff `$navItems` array (lines 19–25):
```php
    $navItems = [
        ['href' => 'dashboard.php',       'icon' => 'fa-gauge-high',   'label' => 'Dashboard',       'match' => ['dashboard.php']],
        ['href' => 'applicants.php',      'icon' => 'fa-users',        'label' => 'Applicants',      'match' => ['applicants.php', 'applicant-create.php', 'applicant-edit.php', 'applicant-view.php']],
        ['href' => 'employment-list.php', 'icon' => 'fa-briefcase',    'label' => 'Employment',      'match' => $employmentPages],
        ['href' => 'partner-agency.php',  'icon' => 'fa-building',     'label' => 'Partner Agency',  'match' => $agencyPages],
        ['href' => 'reports.php',         'icon' => 'fa-chart-column', 'label' => 'Reports',         'match' => ['reports.php']],
    ];
```
with:
```php
    $navItems = [
        ['href' => 'dashboard.php',       'icon' => 'fa-gauge-high',   'label' => 'Dashboard',       'match' => ['dashboard.php']],
        ['href' => 'applicants.php',      'icon' => 'fa-users',        'label' => 'Applicants',      'match' => ['applicants.php', 'applicant-create.php', 'applicant-edit.php', 'applicant-view.php']],
        ['href' => 'clients.php',         'icon' => 'fa-handshake',    'label' => 'Clients',         'match' => ['clients.php']],
        ['href' => 'employment-list.php', 'icon' => 'fa-briefcase',    'label' => 'Employment',      'match' => $employmentPages],
        ['href' => 'partner-agency.php',  'icon' => 'fa-building',     'label' => 'Partner Agency',  'match' => $agencyPages],
        ['href' => 'reports.php',         'icon' => 'fa-chart-column', 'label' => 'Reports',         'match' => ['reports.php']],
    ];
```
(The `array_splice($navItems, 4, 0, [...])` call right after that inserts "Job Vacancies" at index 4 for `can_manage_employment()` — leave it as-is; it now lands after "Clients" instead of after "Employment", which is fine since it splices by position, not by searching for a label. Do not renumber it unless a manual check shows the resulting order is wrong — verify in Step 4.)

The Partner Agency `$navItems` array (lines 10–17) is **not** modified — Partner Agency accounts get no Clients list, per the spec.

- [ ] **Step 2: Create `public/clients.php`**

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

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Clients</h1>
      <p class="text-sm text-slate-500">Search and manage registrants who avail Partner Agency services.</p>
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

  <!-- Filters -->
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4">
    <div class="grid md:grid-cols-4 gap-3">
      <div class="md:col-span-2 relative">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
        <input type="text" x-model="filters.search" @input.debounce.400ms="load(1)"
               placeholder="Search by name, ID or contact number..."
               class="w-full pl-9 pr-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
      </div>
      <select x-model="filters.sex" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
        <option value="All">All Sex</option>
        <option value="MALE">MALE</option>
        <option value="FEMALE">FEMALE</option>
      </select>
      <select x-model="filters.civil_status" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
        <option value="All">All Civil Status</option>
        <option>SINGLE</option><option>MARRIED</option><option>WIDOWED</option>
        <option>SEPARATED</option><option>DIVORCED</option><option>OTHER</option>
      </select>
    </div>
    <div class="flex justify-end mt-3">
      <button @click="resetFilters()" class="text-sm text-slate-500 hover:text-slate-700 font-medium">
        <i class="fa-solid fa-rotate-left mr-1"></i> Reset Filters
      </button>
    </div>
  </div>

  <!-- Table -->
  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm responsive-cards">
        <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wide">
          <tr>
            <th class="px-4 py-3 text-left">Applicant ID</th>
            <th class="px-4 py-3 text-left">Full Name</th>
            <th class="px-4 py-3 text-left">Sex</th>
            <th class="px-4 py-3 text-left">Contact</th>
            <th class="px-4 py-3 text-left">Civil Status</th>
            <th class="px-4 py-3 text-left">Services Availed</th>
            <th class="px-4 py-3 text-left">Date Registered</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <template x-if="loading">
            <tr><td colspan="8" class="px-4 py-6"><div class="h-4 skeleton rounded"></div></td></tr>
          </template>
          <template x-if="!loading && rows.length === 0">
            <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">
              <i class="fa-solid fa-inbox text-2xl mb-2 block"></i> No clients found.
            </td></tr>
          </template>
          <template x-for="row in rows" :key="row.id">
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3 font-medium text-brand-700" data-label="ID" x-text="row.applicant_code"></td>
              <td class="px-4 py-3" data-label="Name" x-text="row.full_name"></td>
              <td class="px-4 py-3" data-label="Sex" x-text="row.sex"></td>
              <td class="px-4 py-3" data-label="Contact" x-text="row.contact_number"></td>
              <td class="px-4 py-3" data-label="Civil Status" x-text="row.civil_status"></td>
              <td class="px-4 py-3" data-label="Services">
                <template x-for="svc in row.services_availed" :key="svc">
                  <span class="inline-block px-2 py-0.5 mr-1 mb-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 uppercase" x-text="svc"></span>
                </template>
              </td>
              <td class="px-4 py-3" data-label="Registered" x-text="row.date_registered"></td>
              <td class="px-4 py-3 text-right" data-label="Actions">
                <a :href="'applicant-view.php?id=' + row.id" class="text-slate-500 hover:text-brand-600 px-1.5" title="View"><i class="fa-solid fa-eye"></i></a>
                <?php if (can_edit()): ?>
                <a :href="'applicant-edit.php?id=' + row.id" class="text-slate-500 hover:text-amber-600 px-1.5" title="Edit"><i class="fa-solid fa-pen"></i></a>
                <?php endif; ?>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100 flex-wrap gap-3">
      <p class="text-xs text-slate-500">
        Showing <span x-text="rows.length ? (offset()+1) : 0"></span>–<span x-text="offset()+rows.length"></span> of <span x-text="total"></span> clients
      </p>
      <div class="flex items-center gap-3">
        <select x-model.number="perPage" @change="load(1)" class="text-sm border border-slate-300 rounded-lg px-2 py-1">
          <option :value="10">10 / page</option>
          <option :value="25">25 / page</option>
          <option :value="50">50 / page</option>
          <option :value="100">100 / page</option>
        </select>
        <div class="flex gap-1">
          <button @click="load(page-1)" :disabled="page<=1" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 disabled:opacity-40">Previous</button>
          <span class="px-3 py-1.5 text-sm">Page <span x-text="page"></span> of <span x-text="Math.max(pages,1)"></span></span>
          <button @click="load(page+1)" :disabled="page>=pages" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 disabled:opacity-40">Next</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function applicantTable() {
  return {
    rows: [], total: 0, page: 1, pages: 1, perPage: 25, loading: true,
    filters: { search: '', sex: 'All', civil_status: 'All', service: '' },
    offset() { return (this.page - 1) * this.perPage; },
    resetFilters() {
      this.filters = { search: '', sex: 'All', civil_status: 'All', service: 'agency_services' };
      this.load(1);
    },
    async load(page = this.page) {
      this.loading = true;
      this.page = Math.max(1, page);
      const params = new URLSearchParams({ page: this.page, per_page: this.perPage, ...this.filters });
      try {
        const res = await fetch('api/applicants.php?' + params.toString());
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```

This is a self-contained copy of `applicantTable()` (not a shared import) — matches this file's own inline-`<script>` pattern from `applicants.php`, just with a different `filters` default and no `employment_status` filter/column. `x-init="filters.service = 'agency_services'; load()"` sets the fixed filter before the first load.

- [ ] **Step 3: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/clients.php` and `"/c/xampp/php/php.exe" -l includes/sidebar.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manual browser verification**

Log in as Administrator/Employee/Viewer: confirm "Clients" appears in the sidebar between "Applicants" and "Employment" (and, for a Administrator/Employee account, confirm "Job Vacancies" still appears in a sensible position — if `array_splice` landed it oddly, fix the splice index to match the new array length). Open `clients.php`: confirm only `service_agency_services=1` applicants are listed, search/pagination work. Log in as a Partner Agency test account: confirm no "Clients" nav item and that navigating to `clients.php` directly returns a 403.

- [ ] **Step 5: Commit**

```bash
git add public/clients.php includes/sidebar.php
git commit -m "Add Clients list page (Administrator/Employee/Viewer only)"
```

---

### Task 10: Agency Services CRUD — staff side (`partner-agency-form.php`)

**Files:**
- Modify: `public/partner-agency-form.php`

**Interfaces:**
- Produces: nothing consumed by later tasks (Task 11 duplicates this independently, per the spec's stated no-shared-handler convention).

- [ ] **Step 1: Add POST handlers for the agency's services**

Insert new `elseif` branches after the existing `if ($_SERVER['REQUEST_METHOD'] === 'POST') { ... }` block's agency-save logic (i.e., restructure so agency-save and service actions are distinguished by an `action` field). Change the top of the POST block from:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
```
to:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save_agency') === 'save_agency') {
    csrf_require();
    $old['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
```
(The main Save Partner Agency form does not currently send an `action` field — add `<input type="hidden" name="action" value="save_agency">` to that `<form>` tag so it's unambiguous once the new actions exist alongside it.)

Then, after that entire `if` block's closing `}` (end of the existing agency-save logic, right before `$pageTitle = $isEdit ? ...`), add:
```php
if ($isEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service') {
    require_role(['Administrator', 'Employee']);
    csrf_require();
    $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
    $serviceDesc = clean($_POST['description'] ?? '');
    if ($serviceName === '') {
        flash_set('error', 'Service name is required.');
    } elseif (mb_strlen($serviceName) > 200) {
        flash_set('error', 'Service name must be 200 characters or fewer.');
    } else {
        $svcStmt = $pdo->prepare(
            "INSERT INTO care_jf_agency_services (agency_id, service_name, description) VALUES (:aid, :n, :d)"
        );
        $svcStmt->execute([':aid' => $id, ':n' => $serviceName, ':d' => $serviceDesc ?: null]);
        $newServiceId = (int)$pdo->lastInsertId();
        audit_log($pdo, (int)current_user()['id'], 'CREATE', 'care_jf_agency_services', $newServiceId, "Added service \"$serviceName\" to agency #$id");
        flash_set('success', 'Service added.');
    }
    redirect('partner-agency-form.php?id=' . $id);
} elseif ($isEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['toggle_service', 'edit_service'], true)) {
    require_role(['Administrator', 'Employee']);
    csrf_require();
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $ownStmt = $pdo->prepare("SELECT id, status FROM care_jf_agency_services WHERE id = :id AND agency_id = :aid");
    $ownStmt->execute([':id' => $serviceId, ':aid' => $id]);
    $svcRow = $ownStmt->fetch();
    if (!$svcRow) {
        flash_set('error', 'Service not found for this agency.');
    } elseif (($_POST['action'] ?? '') === 'toggle_service') {
        $newStatus = $svcRow['status'] === 'Active' ? 'Disabled' : 'Active';
        $pdo->prepare("UPDATE care_jf_agency_services SET status = :s WHERE id = :id")->execute([':s' => $newStatus, ':id' => $serviceId]);
        audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_agency_services', $serviceId, "Service $newStatus");
        flash_set('success', "Service $newStatus.");
    } else {
        $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
        $serviceDesc = clean($_POST['description'] ?? '');
        if ($serviceName === '') {
            flash_set('error', 'Service name is required.');
        } else {
            $pdo->prepare("UPDATE care_jf_agency_services SET service_name = :n, description = :d WHERE id = :id")
                ->execute([':n' => $serviceName, ':d' => $serviceDesc ?: null, ':id' => $serviceId]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_agency_services', $serviceId, "Updated service \"$serviceName\"");
            flash_set('success', 'Service updated.');
        }
    }
    redirect('partner-agency-form.php?id=' . $id);
}
```

- [ ] **Step 2: Fetch this agency's services and render the section (edit mode only)**

Immediately before `$pageTitle = $isEdit ? 'Edit Partner Agency' : 'Add Partner Agency';`, add:
```php
$agencyServices = [];
if ($isEdit) {
    $svcListStmt = $pdo->prepare("SELECT * FROM care_jf_agency_services WHERE agency_id = :id ORDER BY service_name");
    $svcListStmt->execute([':id' => $id]);
    $agencyServices = $svcListStmt->fetchAll();
}
```

Add `<input type="hidden" name="action" value="save_agency">` to the existing `<form method="POST" ...>` tag (the one wrapping the agency fields), then after that form's closing `</form>` and before the closing `</div>` of `<div class="max-w-xl mx-auto">`, add (only when `$isEdit`):
```php
<?php if ($isEdit): ?>
<div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mt-5" x-data="{ showAddService: false, editingServiceId: null }">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Services Offered</h2>
    <button type="button" @click="showAddService = true" class="text-xs font-medium text-brand-600 hover:text-brand-800"><i class="fa-solid fa-plus mr-1"></i> Add Service</button>
  </div>

  <div x-show="showAddService" x-cloak class="mb-4 p-4 bg-slate-50 rounded-lg">
    <form method="POST" class="space-y-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_service">
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Service Name <span class="text-red-500">*</span></label>
        <input type="text" name="service_name" required maxlength="200" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Description</label>
        <textarea name="description" rows="2" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"></textarea>
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" @click="showAddService = false" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300">Cancel</button>
        <button type="submit" class="px-3 py-1.5 text-sm rounded-lg bg-brand-600 text-white font-medium">Add</button>
      </div>
    </form>
  </div>

  <?php if (!$agencyServices): ?>
    <p class="text-sm text-slate-400">No services on file for this agency.</p>
  <?php else: ?>
  <div class="divide-y divide-slate-100">
    <?php foreach ($agencyServices as $svc): ?>
    <div class="py-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-sm font-medium text-slate-800"><?= e($svc['service_name']) ?>
            <span class="ml-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $svc['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($svc['status']) ?></span>
          </p>
          <?php if ($svc['description']): ?><p class="text-xs text-slate-500 mt-0.5"><?= e($svc['description']) ?></p><?php endif; ?>
        </div>
        <div class="flex gap-1 shrink-0">
          <button type="button" @click="editingServiceId = editingServiceId === <?= (int)$svc['id'] ?> ? null : <?= (int)$svc['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></button>
          <form method="POST" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_service">
            <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
            <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $svc['status']==='Active' ? 'Disable' : 'Enable' ?>">
              <i class="fa-solid <?= $svc['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
            </button>
          </form>
        </div>
      </div>
      <div x-show="editingServiceId === <?= (int)$svc['id'] ?>" x-cloak class="mt-2 p-3 bg-slate-50 rounded-lg">
        <form method="POST" class="space-y-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="edit_service">
          <input type="hidden" name="service_id" value="<?= (int)$svc['id'] ?>">
          <input type="text" name="service_name" required maxlength="200" value="<?= e($svc['service_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
          <textarea name="description" rows="2" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"><?= e($svc['description'] ?? '') ?></textarea>
          <div class="flex justify-end gap-2">
            <button type="button" @click="editingServiceId = null" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300">Cancel</button>
            <button type="submit" class="px-3 py-1.5 text-sm rounded-lg bg-brand-600 text-white font-medium">Save</button>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Show an Active-services count on the `partner-agency.php` list**

Per spec §7 ("Read-only display: `public/partner-agency.php`'s agency detail/list view shows each agency's Active services"), add a count column to the existing list query and table. In `public/partner-agency.php`, change the list query (currently):
```php
$stmt = $pdo->prepare("SELECT pa.*, (SELECT COUNT(*) FROM care_jf_employment_records er WHERE er.agency_id = pa.id) AS record_count
                        FROM care_jf_partner_agencies pa $whereSql ORDER BY agency_name");
```
to:
```php
$stmt = $pdo->prepare("SELECT pa.*, (SELECT COUNT(*) FROM care_jf_employment_records er WHERE er.agency_id = pa.id) AS record_count,
                        (SELECT COUNT(*) FROM care_jf_agency_services asv WHERE asv.agency_id = pa.id AND asv.status = 'Active') AS active_service_count
                        FROM care_jf_partner_agencies pa $whereSql ORDER BY agency_name");
```
Add a `<th class="px-4 py-2.5 text-left">Services</th>` column header right after "Employment Records", and the matching `<td>` in the row loop right after the Employment Records `<td>`:
```php
<td class="px-4 py-3" data-label="Services"><?= (int)$ag['active_service_count'] ?></td>
```
Update the `colspan="7"` on the "No partner agencies found" empty-state row to `colspan="8"` (one more column now). Leave the Excel export (`$headers`/`$exportRows` for `?export=xlsx`) unchanged — out of scope per the spec.

- [ ] **Step 4: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/partner-agency-form.php` and `"/c/xampp/php/php.exe" -l public/partner-agency.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Manual browser verification**

As Administrator/Employee, open an existing agency's edit page (`partner-agency-form.php?id=N`). Add a service, confirm it appears; edit it, confirm the change saves; toggle it Disabled then Active, confirm the badge updates each time. Confirm the main "Save Partner Agency" button still saves the agency's own fields correctly (regression check on the `action=save_agency` change). Confirm the "Services Offered" section does not appear on the Add-new-agency form (`partner-agency-form.php` with no `id`). Then open `partner-agency.php`'s list and confirm the new "Services" column shows the correct Active-service count per agency (toggling a service Disabled on the edit page should decrement it).

- [ ] **Step 6: Commit**

```bash
git add public/partner-agency-form.php public/partner-agency.php
git commit -m "Add Services Offered management and a Services count column"
```

---

### Task 11: Agency Services CRUD — self-service (`my-agency.php`)

**Files:**
- Modify: `public/my-agency.php`

**Interfaces:** Same shape as Task 10, scoped to `current_agency_id($pdo)` instead of a posted `id`.

- [ ] **Step 1: Add POST handlers**

Change the top of the existing POST block from:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['Partner Agency']);
    csrf_require();

    $old['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
```
to:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save_agency') === 'save_agency') {
    require_role(['Partner Agency']);
    csrf_require();

    $old['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
```
(Add `<input type="hidden" name="action" value="save_agency">` to the existing profile `<form>` tag.)

After that block's closing `}` (immediately before `$pageTitle = 'My Partner Agency';`), add:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service') {
    require_role(['Partner Agency']);
    csrf_require();
    $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
    $serviceDesc = clean($_POST['description'] ?? '');
    if ($serviceName === '') {
        flash_set('error', 'Service name is required.');
    } elseif (mb_strlen($serviceName) > 200) {
        flash_set('error', 'Service name must be 200 characters or fewer.');
    } else {
        // $agencyId is server-derived (current_agency_id()) — never trusted from the request.
        $svcStmt = $pdo->prepare(
            "INSERT INTO care_jf_agency_services (agency_id, service_name, description) VALUES (:aid, :n, :d)"
        );
        $svcStmt->execute([':aid' => $agencyId, ':n' => $serviceName, ':d' => $serviceDesc ?: null]);
        $newServiceId = (int)$pdo->lastInsertId();
        audit_log($pdo, (int)current_user()['id'], 'CREATE', 'care_jf_agency_services', $newServiceId, "Added service \"$serviceName\"");
        flash_set('success', 'Service added.');
    }
    redirect('my-agency.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['toggle_service', 'edit_service'], true)) {
    require_role(['Partner Agency']);
    csrf_require();
    $serviceId = (int)($_POST['service_id'] ?? 0);
    // Ownership check: this service must belong to the acting user's own
    // agency — a Partner Agency user cannot manage another agency's
    // services no matter what service_id is posted.
    $ownStmt = $pdo->prepare("SELECT id, status FROM care_jf_agency_services WHERE id = :id AND agency_id = :aid");
    $ownStmt->execute([':id' => $serviceId, ':aid' => $agencyId]);
    $svcRow = $ownStmt->fetch();
    if (!$svcRow) {
        flash_set('error', 'Service not found.');
    } elseif (($_POST['action'] ?? '') === 'toggle_service') {
        $newStatus = $svcRow['status'] === 'Active' ? 'Disabled' : 'Active';
        $pdo->prepare("UPDATE care_jf_agency_services SET status = :s WHERE id = :id")->execute([':s' => $newStatus, ':id' => $serviceId]);
        audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_agency_services', $serviceId, "Service $newStatus");
        flash_set('success', "Service $newStatus.");
    } else {
        $serviceName = mb_strtoupper(clean($_POST['service_name'] ?? ''), 'UTF-8');
        $serviceDesc = clean($_POST['description'] ?? '');
        if ($serviceName === '') {
            flash_set('error', 'Service name is required.');
        } else {
            $pdo->prepare("UPDATE care_jf_agency_services SET service_name = :n, description = :d WHERE id = :id")
                ->execute([':n' => $serviceName, ':d' => $serviceDesc ?: null, ':id' => $serviceId]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'care_jf_agency_services', $serviceId, "Updated service \"$serviceName\"");
            flash_set('success', 'Service updated.');
        }
    }
    redirect('my-agency.php');
}
```

- [ ] **Step 2: Fetch this agency's services and render the section**

Before `$pageTitle = 'My Partner Agency';`, add:
```php
$svcListStmt = $pdo->prepare("SELECT * FROM care_jf_agency_services WHERE agency_id = :id ORDER BY service_name");
$svcListStmt->execute([':id' => $agencyId]);
$agencyServices = $svcListStmt->fetchAll();
```

After the existing profile `</form>` and before the closing `</div>` of `<div class="max-w-2xl mx-auto">`, add the identical "Services Offered" markup block from Task 10 Step 2 (same HTML/Alpine structure, same field names) — it is not conditional on `$isEdit` here since `my-agency.php` always refers to the caller's own already-existing agency.

- [ ] **Step 3: Verify syntax**

Run: `"/c/xampp/php/php.exe" -l public/my-agency.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual browser verification**

As a Partner Agency test account, open `my-agency.php`. Add/edit/toggle a service exactly as in Task 10 Step 4. Confirm the main profile Save still works (regression check). Attempt a crafted POST with `action=toggle_service` and a `service_id` belonging to a *different* agency (e.g. via browser dev tools) — expected: `flash_set('error', 'Service not found.')`, no row changed (ownership check holds).

- [ ] **Step 5: Commit**

```bash
git add public/my-agency.php
git commit -m "Add Services Offered self-service management to My Partner Agency"
```

---

### Task 12: End-to-end regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run `php -l` across every file touched by this plan**

```bash
"/c/xampp/php/php.exe" -l includes/functions.php
"/c/xampp/php/php.exe" -l public/register-applicant.php
"/c/xampp/php/php.exe" -l public/applicant-create.php
"/c/xampp/php/php.exe" -l public/applicant-edit.php
"/c/xampp/php/php.exe" -l public/api/qr-tag.php
"/c/xampp/php/php.exe" -l public/applicant-view.php
"/c/xampp/php/php.exe" -l public/api/applicants.php
"/c/xampp/php/php.exe" -l public/clients.php
"/c/xampp/php/php.exe" -l includes/sidebar.php
"/c/xampp/php/php.exe" -l public/partner-agency-form.php
"/c/xampp/php/php.exe" -l public/my-agency.php
```
Expected: `No syntax errors detected` for all eleven.

- [ ] **Step 2: Full-flow browser walkthrough**

1. Register a new applicant with only "Avail Agency Service" checked, blank Educational/Eligibility — succeeds.
2. As a Partner Agency test account, scan/manually-enter that new applicant's code via the QR modal (Dashboard or Clients page) — confirm a `care_jf_service_availments` row is created, no `care_jf_employment_records` row, and the applicant's profile shows a populated "Services Availed History" card with no "Employment History" card.
3. Register a second applicant with only "Job Seeker" checked — confirm the full existing flow (education/eligibility required, QR scan creates a `For Review` employment record, "Tag for Review" button present, no "Services Availed History" card) is completely unchanged from before this plan.
4. Register a third applicant with both boxes checked — confirm a single QR scan creates one row in each table, and both cards appear on the profile.
5. Open the Clients list — confirm applicants 1 and 3 appear, applicant 2 does not.
6. Open the Applicants list — confirm all three appear, and existing search/filter/employment-status behavior is unchanged.
7. As Administrator, add a service to a Partner Agency via `partner-agency-form.php`; as that same agency's Partner Agency account, confirm the service is visible/editable via `my-agency.php`.

Expected: every one of the above matches its stated expectation, no PHP warnings/errors surfaced in the browser or in the webserver's error log for any of these requests.

- [ ] **Step 3: Confirm no unrelated regressions**

Spot-check: the existing `applicants.php` list (search/filter/pagination), an existing pre-this-plan applicant's `applicant-view.php` profile (Employment History still renders for a `service_job_seeker=1` applicant that predates this change), and `vacancies.php`/`partner-agency.php` still load without error (these files were not modified but sit next to files that were).

This task has no commit of its own — it is a verification gate before considering the feature done. If any check fails, return to the relevant task, fix, and re-verify that task's own steps before re-running this one.
