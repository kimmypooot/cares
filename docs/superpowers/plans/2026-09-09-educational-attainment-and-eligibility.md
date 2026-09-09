# Educational Attainment & Eligibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Educational Attainment and Eligibility fields to the applicant record, collected at registration (public and Administrator), editable, and visible on the profile/print page — fully additive, preserving every existing applicant, employment, agency, QR, and auth workflow.

**Architecture:** One additive migration on `care_jf_applicants` (10 new nullable columns, no other table touched). Each of the three applicant forms (public registration, Administrator registration, Edit) gets the same two new sections — Educational Attainment and Eligibility — added using this codebase's existing conventions verbatim: PDO prepared statements, `clean()`/`mb_strtoupper()` server-side normalization, the existing `x-show` + `:data-conditional-hidden` + `validateForm()` mechanism already proven in `applicant-edit.php`'s Employment section, and the existing `uppercase-field` CSS-class auto-uppercase mechanism in `app.js`. The profile page gets two new display cards. No JavaScript file changes — the existing `app.js` mechanisms already cover everything this feature needs.

**Tech Stack:** PHP 8 (no framework), PDO/MySQL, Tailwind (prebuilt CSS), Alpine.js. No new dependencies, no JS file changes.

**Spec:** The user's own request (verbatim, given directly in chat — reproduced field-for-field, option-for-option throughout this plan). There is no separate spec file for this plan; the task briefs below are the complete, exact-value source of truth.

## Global Constraints

- All new columns are nullable and additive (`ALTER TABLE ... ADD COLUMN`) — no existing column, index, or constraint is touched. Never `DROP`/`TRUNCATE`/recreate `care_jf_applicants`.
- Exact option wording (case-sensitive, verbatim) for `educational_level`: `High School/Senior High School Graduate`, `Technical/Vocational`, `College Graduate`, `Postgraduate (Master/Doctorate)`.
- Exact option wording for `completion_status`: `Not Graduate`, `Graduate`.
- Exact option wording for `eligibility_status`: `Eligible`, `Not Eligible`.
- Exact option wording for `eligibility_type` (11 values, in this order): `Civil Service Professional`, `Civil Service Subprofessional`, `Civil Service Professional (Preference Rating)`, `Civil Service Subprofessional (Preference Rating)`, `Basic Competency on Local Treasury`, `Barangay Official`, `Honor Graduate Eligibility`, `Fire Officer`, `Penology Officer`, `Skills Eligibility (MC 11)`, `Other`.
- Server-side validation is authoritative and independent of the client — every rule below must be enforced in PHP regardless of what JavaScript did or what was POSTed. Never trust hidden fields, GET parameters, or POSTed applicant IDs for authorization (existing `require_role`/ownership-check patterns are untouched by this plan and continue to apply as-is).
- All SQL goes through PDO prepared statements. All dynamic HTML output goes through `e()`. Every mutating POST handler already calls `csrf_require()` before touching the database — do not remove or reorder that call in any file this plan touches.
- Conditional field logic reuses the exact existing mechanism from `public/applicant-edit.php`'s Employment section: `x-show="condition" x-cloak :data-conditional-hidden="!condition ? '1' : null"` on the wrapping element, plus `:required="condition"` on the actual input (a small robustness improvement over the Employment section's fields, which have no `required` attribute at all — see Task 2's notes for why this is safe). `public/assets/js/app.js`'s `validateForm()` already skips required-checking inside any `[data-conditional-hidden]` ancestor — do not modify `app.js`.
- `other_eligibility_type` uses the existing `uppercase-field uppercase` CSS classes (client-side auto-uppercase, already implemented in `app.js`) AND is normalized server-side via `mb_strtoupper(trim(...), 'UTF-8')` — client-side uppercasing is a convenience, never the authority.
- Whenever a field's governing selection makes it inapplicable (e.g. `completion_status = 'Not Graduate'` makes the Graduate-only fields inapplicable), the server-side handler must overwrite that field to empty/NULL before saving — never trust that a hidden client-side field was actually empty.
- This repo has no automated test suite (confirmed in `CLAUDE.md`) — verify via `php -l`, direct MySQL assertions, and `curl`/browser-driven HTTP checks against the real local database, exactly as established throughout this project.
- `reports.php`, `applicants.php`'s listing/search, `dashboard.php`, and `public/api/applicants.php` are explicitly OUT OF SCOPE for this plan (confirmed via full-codebase inspection: none of them use `SELECT *`, so none will break from the new columns; the request itself says "do not unnecessarily redesign existing reports" and marks report/filter inclusion as "where appropriate" rather than required).

---

### Task 1: Database migration — Educational Attainment & Eligibility columns

**Files:**
- Create: `database/migrations/add_educational_attainment_and_eligibility.sql`

**Interfaces:**
- Produces: 10 new nullable columns on `care_jf_applicants` — `educational_level`, `completion_status`, `highest_year_level_units`, `date_graduated`, `course_degree`, `school_name`, `school_address`, `eligibility_status`, `eligibility_type`, `other_eligibility_type`. Every later task reads/writes these by exact name.

- [ ] **Step 1: Write the migration file**

```sql
-- =====================================================================
-- Migration: add_educational_attainment_and_eligibility.sql
-- Adds Educational Attainment and Eligibility fields to
-- care_jf_applicants. Purely additive — 10 new nullable columns, no
-- existing column, index, constraint, or row is touched. Existing
-- applicants keep every value NULL until edited; applicant_code, QR
-- identifiers, employment history, and agency relationships are all
-- unaffected (this migration touches no other table).
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_educational_attainment_and_eligibility.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_applicants
  ADD COLUMN educational_level ENUM(
    'High School/Senior High School Graduate',
    'Technical/Vocational',
    'College Graduate',
    'Postgraduate (Master/Doctorate)'
  ) NULL AFTER remarks,
  ADD COLUMN completion_status ENUM('Not Graduate','Graduate') NULL AFTER educational_level,
  ADD COLUMN highest_year_level_units VARCHAR(100) NULL AFTER completion_status,
  ADD COLUMN date_graduated DATE NULL AFTER highest_year_level_units,
  ADD COLUMN course_degree VARCHAR(255) NULL AFTER date_graduated,
  ADD COLUMN school_name VARCHAR(200) NULL AFTER course_degree,
  ADD COLUMN school_address VARCHAR(255) NULL AFTER school_name,
  ADD COLUMN eligibility_status ENUM('Eligible','Not Eligible') NULL AFTER school_address,
  ADD COLUMN eligibility_type ENUM(
    'Civil Service Professional',
    'Civil Service Subprofessional',
    'Civil Service Professional (Preference Rating)',
    'Civil Service Subprofessional (Preference Rating)',
    'Basic Competency on Local Treasury',
    'Barangay Official',
    'Honor Graduate Eligibility',
    'Fire Officer',
    'Penology Officer',
    'Skills Eligibility (MC 11)',
    'Other'
  ) NULL AFTER eligibility_status,
  ADD COLUMN other_eligibility_type VARCHAR(150) NULL AFTER eligibility_type;

SELECT 'Migration complete.' AS status;
SHOW COLUMNS FROM care_jf_applicants WHERE Field IN (
  'educational_level','completion_status','highest_year_level_units','date_graduated',
  'course_degree','school_name','school_address','eligibility_status','eligibility_type','other_eligibility_type'
);
SELECT COUNT(*) AS existing_applicant_count FROM care_jf_applicants;
SELECT id, applicant_code FROM care_jf_applicants ORDER BY id;
```

- [ ] **Step 2: Apply the migration to the live database**

Run: `/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db < "database/migrations/add_educational_attainment_and_eligibility.sql"`
Expected: the trailing queries show all 10 columns present (types matching the SQL above, `Null: YES`, `Default: NULL`), the existing applicant count unchanged from before the migration, and the same `applicant_code` value(s) as before (record this exact count/codes in your report — the controller needs it to confirm nothing was lost).

- [ ] **Step 3: Verify no other table was touched**

Run: `/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SHOW TABLES;"` — confirm the table list is unchanged from before (no new/missing tables). Run `/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT COUNT(*) FROM care_jf_employment_records;"` and note the count — this must also be unchanged after the migration (this migration should not touch this table at all, this is a sanity check).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/add_educational_attainment_and_eligibility.sql
git commit -m "Add Educational Attainment and Eligibility columns to care_jf_applicants

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

### Task 2: `public/register-applicant.php` — public Job Seeker Registration

**Files:**
- Modify: `public/register-applicant.php`

**Interfaces:**
- Consumes: the 10 columns from Task 1.
- Produces: nothing new consumed by other tasks — each of Tasks 2-4 independently implements the same validation rules against the same columns; there is no shared validation function in this codebase to add one to (confirmed: every existing field, e.g. `is_valid_ph_number`/duplicate-check, is validated inline per file, not centralized) — follow that exact precedent, do not create a new shared validation file.

**Context:** This is the public, unauthenticated registration form (`https://.../register-applicant.php`). It currently collects Services Availed + Personal Information, then INSERTs into `care_jf_applicants`. You are adding two new sections — Educational Attainment and Eligibility — between the existing Personal Information card and the submit buttons, plus the matching PHP validation and INSERT columns. The page has TWO existing modals (Privacy Notice, Photo/Video Recording Notice) and a Success popup, all driven by `x-data` on the `<body>` tag — **do not touch the `<body>` tag's `x-data` or any of the three existing modals in any way**; your new Alpine state goes on the `<form>` tag instead, which currently has no `x-data` of its own.

**Why `:required` instead of the older no-`required`-attribute pattern:** `public/applicant-edit.php`'s existing conditional Employment fields (Agency, Date Hired, Employment Classification) have no native `required` attribute at all — validity is enforced only by server-side checks plus the custom `validateForm()`/`data-conditional-hidden` combo. That avoids any assumption about how browsers treat a `required` field inside a `display:none` ancestor. For this plan, use `:required="<same condition as the x-show>"` (an Alpine binding that adds/removes the `required` attribute in lockstep with visibility) instead — this is strictly more precise: the attribute is only ever present while the field is genuinely visible, so there is no reliance on "browsers skip hidden required fields" behavior at all, and it gives real-time native+custom validation feedback. Keep `:data-conditional-hidden` on the same wrapping element regardless, exactly as in the Employment section — it is `validateForm()`'s own skip mechanism and is independent of whether the field carries `required`.

**Before You Begin:** If anything about the exact option lists, column names, or conditional rules is unclear, re-read this brief's Global Constraints and this task's own field list below before asking — every value is specified exactly. Ask only if something in the actual current file (not this brief) looks inconsistent with what's described here.

- [ ] **Step 1: Add the option-list constants and read+normalize the new POST fields**

**Placement matters here — read this carefully.** The two option-list arrays (`$educLevelOptions`, `$eligibilityTypeOptions`) are consumed BOTH by the validation logic (inside the `if (POST)` block) AND by the `<select>` dropdowns in the always-rendered template further down the file (rendered on every page load, GET or POST). If you declare them only inside the `if (POST)` block, every plain GET request — i.e. every first-time visitor, and the page reload after a successful redirect — leaves both arrays undefined, and both `<select>` elements render with no options at all except the empty placeholder, making Educational Level and Eligibility Type impossible to select in a real browser. **Declare both arrays where `$old = [ 'last_name' => '', ... ];` is declared, ABOVE the `if ($_SERVER['REQUEST_METHOD'] === 'POST')` line — not inside it.** Add:

```php
$educLevelOptions = ['High School/Senior High School Graduate', 'Technical/Vocational', 'College Graduate', 'Postgraduate (Master/Doctorate)'];
$eligibilityTypeOptions = ['Civil Service Professional', 'Civil Service Subprofessional', 'Civil Service Professional (Preference Rating)', 'Civil Service Subprofessional (Preference Rating)', 'Basic Competency on Local Treasury', 'Barangay Official', 'Honor Graduate Eligibility', 'Fire Officer', 'Penology Officer', 'Skills Eligibility (MC 11)', 'Other'];
```

right next to (immediately before or after) the existing `$old = [...]` array declaration, at the top level of the file (not indented inside any `if` block).

Then, separately, find this block near the top of the file (inside `if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_require(); ...`), right after the existing `foreach ($old as $key => $_) { $old[$key] = clean($_POST[$key] ?? ''); }` and the `service_job_seeker`/`service_agency_services` boolean reads, but BEFORE the "Normalize to uppercase server-side too" block. Insert the 10 field reads (note: NOT the two arrays again — those are already declared above the POST block per the instruction above):

```php
    $old['educational_level'] = clean($_POST['educational_level'] ?? '');
    $old['completion_status'] = clean($_POST['completion_status'] ?? '');
    $old['highest_year_level_units'] = clean($_POST['highest_year_level_units'] ?? '');
    $old['date_graduated'] = clean($_POST['date_graduated'] ?? '');
    $old['course_degree'] = clean($_POST['course_degree'] ?? '');
    $old['school_name'] = clean($_POST['school_name'] ?? '');
    $old['school_address'] = clean($_POST['school_address'] ?? '');
    $old['eligibility_status'] = clean($_POST['eligibility_status'] ?? '');
    $old['eligibility_type'] = clean($_POST['eligibility_type'] ?? '');
    $old['other_eligibility_type'] = mb_strtoupper(trim(clean($_POST['other_eligibility_type'] ?? '')), 'UTF-8');
```

Also add `'course_degree'` and `'school_name'` and `'school_address'` to the existing uppercase-normalization loop (`foreach (['last_name', 'first_name', 'middle_name', 'address', 'place_of_birth'] as $upperKey)`) — change it to:

```php
    foreach (['last_name', 'first_name', 'middle_name', 'address', 'place_of_birth', 'school_name', 'school_address'] as $upperKey) {
        $old[$upperKey] = mb_strtoupper($old[$upperKey], 'UTF-8');
    }
```

Do NOT add `course_degree` to this uppercase loop — course/degree titles are conventionally mixed-case (e.g. "Bachelor of Science in Civil Engineering") and the spec never asks for it to be uppercased; only `other_eligibility_type` is uppercased, per spec section 8.

- [ ] **Step 2: Add the ten new lines to `$old`'s initial defaults array**

Find the `$old = [ 'last_name' => '', ... ];` array near the top of the file (before the POST handling). Add these keys to it (anywhere in the array, order doesn't matter):

```php
    'educational_level' => '', 'completion_status' => '', 'highest_year_level_units' => '',
    'date_graduated' => '', 'course_degree' => '', 'school_name' => '', 'school_address' => '',
    'eligibility_status' => '', 'eligibility_type' => '', 'other_eligibility_type' => '',
```

- [ ] **Step 3: Add the validation block**

Insert this immediately after the existing `if ($old['address'] === '') $errors['address'] = 'Complete address is required.';` line and before the `$validCivil = [...]` line:

```php
    if (!in_array($old['educational_level'], $educLevelOptions, true)) {
        $errors['educational_level'] = 'Please select an educational level.';
    }

    if (!in_array($old['completion_status'], ['Not Graduate', 'Graduate'], true)) {
        $errors['completion_status'] = 'Please select completion status.';
    } elseif ($old['completion_status'] === 'Not Graduate') {
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
        if ($old['eligibility_type'] === 'Other') {
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

- [ ] **Step 4: Add the ten columns to the INSERT statement**

Find the existing INSERT:
```php
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_applicants
                    (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth,
                     place_of_birth, contact_number, email_address, address, civil_status, source,
                     service_job_seeker, service_agency_services)
                 VALUES
                    (:code, :ln, :fn, :mn, :ext, :sex, :dob, :pob, :contact, :email, :address, :civil, 'Public',
                     :svc_js, :svc_as)"
            );
            $stmt->execute([
                ':code'    => $code,
                ':ln'      => $old['last_name'],
                ':fn'      => $old['first_name'],
                ':mn'      => $old['middle_name'] ?: null,
                ':ext'     => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex'     => $old['sex'],
                ':dob'     => $old['date_of_birth'],
                ':pob'     => $old['place_of_birth'] ?: null,
                ':contact' => $old['contact_number'],
                ':email'   => $old['email_address'],
                ':address' => $old['address'],
                ':civil'   => $old['civil_status'],
                ':svc_js'  => $old['service_job_seeker'] ? 1 : 0,
                ':svc_as'  => $old['service_agency_services'] ? 1 : 0,
            ]);
```

Replace it with:
```php
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_applicants
                    (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth,
                     place_of_birth, contact_number, email_address, address, civil_status, source,
                     service_job_seeker, service_agency_services,
                     educational_level, completion_status, highest_year_level_units, date_graduated,
                     course_degree, school_name, school_address,
                     eligibility_status, eligibility_type, other_eligibility_type)
                 VALUES
                    (:code, :ln, :fn, :mn, :ext, :sex, :dob, :pob, :contact, :email, :address, :civil, 'Public',
                     :svc_js, :svc_as,
                     :educ_level, :completion, :hylu, :date_grad, :course, :school_name, :school_addr,
                     :elig_status, :elig_type, :other_elig)"
            );
            $stmt->execute([
                ':code'    => $code,
                ':ln'      => $old['last_name'],
                ':fn'      => $old['first_name'],
                ':mn'      => $old['middle_name'] ?: null,
                ':ext'     => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex'     => $old['sex'],
                ':dob'     => $old['date_of_birth'],
                ':pob'     => $old['place_of_birth'] ?: null,
                ':contact' => $old['contact_number'],
                ':email'   => $old['email_address'],
                ':address' => $old['address'],
                ':civil'   => $old['civil_status'],
                ':svc_js'  => $old['service_job_seeker'] ? 1 : 0,
                ':svc_as'  => $old['service_agency_services'] ? 1 : 0,
                ':educ_level' => $old['educational_level'],
                ':completion' => $old['completion_status'],
                ':hylu'       => $old['highest_year_level_units'] ?: null,
                ':date_grad'  => $old['date_graduated'] ?: null,
                ':course'     => $old['course_degree'] ?: null,
                ':school_name' => $old['school_name'] ?: null,
                ':school_addr' => $old['school_address'] ?: null,
                ':elig_status' => $old['eligibility_status'],
                ':elig_type'   => $old['eligibility_type'] ?: null,
                ':other_elig'  => $old['other_eligibility_type'] ?: null,
            ]);
```

- [ ] **Step 5: Add the `<form>` tag's `x-data`**

Find `<form method="POST" @submit="if (!validateForm($el)) { $event.preventDefault(); }">` (there is exactly one such form on this page — the registration form itself, inside the `<div class="p-4 sm:p-8 lg:p-12">` right panel, NOT the two modal forms near the top of `<body>`). Replace it with:

```php
  <form method="POST" x-data="{ completion: '<?= e($old['completion_status']) ?>', eligibility: '<?= e($old['eligibility_status']) ?>', eligType: '<?= e($old['eligibility_type']) ?>' }"
        @submit="if (!validateForm($el)) { $event.preventDefault(); }">
```

- [ ] **Step 6: Insert the Educational Attainment and Eligibility cards**

Find the closing of the Personal Information card — the file has this structure:
```php
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Personal Information</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        ... (existing fields) ...
      </div>
    </div>

    <div class="flex justify-end gap-3">
```

Insert the following TWO new cards between that Personal Information card's closing `</div>` and the `<div class="flex justify-end gap-3">` line:

```php
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Educational Attainment</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Educational Level <span class="text-red-500">*</span></label>
          <select name="educational_level" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Select</option>
            <?php foreach ($educLevelOptions as $opt): ?>
              <option <?= $old['educational_level']===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['educational_level']) ? 'hidden' : '' ?>" data-error-for="educational_level"><?= e($errors['educational_level'] ?? '') ?></p>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Completion <span class="text-red-500">*</span></label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="completion_status" value="Not Graduate" x-model="completion" <?= $old['completion_status']==='Not Graduate'?'checked':'' ?> required> Not Graduate</label>
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="completion_status" value="Graduate" x-model="completion" <?= $old['completion_status']==='Graduate'?'checked':'' ?>> Graduate</label>
          </div>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['completion_status']) ? 'hidden' : '' ?>" data-error-for="completion_status"><?= e($errors['completion_status'] ?? '') ?></p>
        </div>

        <div x-show="completion === 'Not Graduate'" x-cloak :data-conditional-hidden="completion !== 'Not Graduate' ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Highest Year/Level/Units Earned <span class="text-red-500">*</span></label>
          <input type="text" name="highest_year_level_units" :required="completion === 'Not Graduate'" placeholder="e.g. Grade 12, 3rd Year College, 72 Units" value="<?= e($old['highest_year_level_units']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['highest_year_level_units']) ? 'hidden' : '' ?>" data-error-for="highest_year_level_units"><?= e($errors['highest_year_level_units'] ?? '') ?></p>
        </div>

        <div x-show="completion === 'Graduate'" x-cloak :data-conditional-hidden="completion !== 'Graduate' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Date Graduated <span class="text-red-500">*</span></label>
          <input type="date" name="date_graduated" :required="completion === 'Graduate'" max="<?= date('Y-m-d') ?>" value="<?= e($old['date_graduated']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['date_graduated']) ? 'hidden' : '' ?>" data-error-for="date_graduated"><?= e($errors['date_graduated'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduate'" x-cloak :data-conditional-hidden="completion !== 'Graduate' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Complete Title of Course/Degree <span class="text-red-500">*</span></label>
          <input type="text" name="course_degree" :required="completion === 'Graduate'" value="<?= e($old['course_degree']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['course_degree']) ? 'hidden' : '' ?>" data-error-for="course_degree"><?= e($errors['course_degree'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduate'" x-cloak :data-conditional-hidden="completion !== 'Graduate' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">Name of School <span class="text-red-500">*</span></label>
          <input type="text" name="school_name" :required="completion === 'Graduate'" value="<?= e($old['school_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['school_name']) ? 'hidden' : '' ?>" data-error-for="school_name"><?= e($errors['school_name'] ?? '') ?></p>
        </div>
        <div x-show="completion === 'Graduate'" x-cloak :data-conditional-hidden="completion !== 'Graduate' ? '1' : null">
          <label class="block text-sm font-medium text-slate-700 mb-1">School Address <span class="text-red-500">*</span></label>
          <textarea name="school_address" :required="completion === 'Graduate'" rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['school_address']) ?></textarea>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['school_address']) ? 'hidden' : '' ?>" data-error-for="school_address"><?= e($errors['school_address'] ?? '') ?></p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Eligibility</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Eligibility Status <span class="text-red-500">*</span></label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="eligibility_status" value="Eligible" x-model="eligibility" <?= $old['eligibility_status']==='Eligible'?'checked':'' ?> required> Eligible</label>
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="eligibility_status" value="Not Eligible" x-model="eligibility" <?= $old['eligibility_status']==='Not Eligible'?'checked':'' ?>> Not Eligible</label>
          </div>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['eligibility_status']) ? 'hidden' : '' ?>" data-error-for="eligibility_status"><?= e($errors['eligibility_status'] ?? '') ?></p>
        </div>

        <div x-show="eligibility === 'Eligible'" x-cloak :data-conditional-hidden="eligibility !== 'Eligible' ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Eligibility Type <span class="text-red-500">*</span></label>
          <select name="eligibility_type" x-model="eligType" :required="eligibility === 'Eligible'" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Select</option>
            <?php foreach ($eligibilityTypeOptions as $opt): ?>
              <option <?= $old['eligibility_type']===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['eligibility_type']) ? 'hidden' : '' ?>" data-error-for="eligibility_type"><?= e($errors['eligibility_type'] ?? '') ?></p>
        </div>

        <div x-show="eligibility === 'Eligible' && eligType === 'Other'" x-cloak :data-conditional-hidden="!(eligibility === 'Eligible' && eligType === 'Other') ? '1' : null" class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Other Eligibility Type <span class="text-red-500">*</span></label>
          <input type="text" name="other_eligibility_type" :required="eligibility === 'Eligible' && eligType === 'Other'" maxlength="150" value="<?= e($old['other_eligibility_type']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1 <?= empty($errors['other_eligibility_type']) ? 'hidden' : '' ?>" data-error-for="other_eligibility_type"><?= e($errors['other_eligibility_type'] ?? '') ?></p>
        </div>
      </div>
    </div>

```

- [ ] **Step 7: Lint**

Run: `& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\applicant-system\public\register-applicant.php"` (adjust the worktree path prefix to your own worktree's path)
Expected: `No syntax errors detected`

- [ ] **Step 8: Live verification — start a PHP server in your worktree**

From your worktree root: `& "C:\xampp\php\php.exe" -S 127.0.0.1:8910 -t public` in the background. This shares the same live MySQL database as the main checkout (Task 1's migration already applies there).

Test via `curl` with a cookie jar (fetch the page for a CSRF token, POST, follow redirects):

1. **Graduate path:** submit with `educational_level=College Graduate`, `completion_status=Graduate`, a valid past `date_graduated`, `course_degree`, `school_name`, `school_address`, `eligibility_status=Eligible`, `eligibility_type=Civil Service Professional`, plus all required personal-info fields and at least one service checkbox. Expect a redirect to `?success=1&code=...`. Then query the DB directly: `SELECT educational_level, completion_status, date_graduated, course_degree, school_name, school_address, highest_year_level_units, eligibility_status, eligibility_type, other_eligibility_type FROM care_jf_applicants WHERE applicant_code = '<the code>';` — confirm every field matches what was submitted and `highest_year_level_units` is NULL.
2. **Not Graduate path:** submit with `completion_status=Not Graduate`, `highest_year_level_units=3rd Year / 90 Units`, omitting the Graduate fields entirely from the POST body. Expect success. Confirm via DB query that `highest_year_level_units` matches and `date_graduated`/`course_degree`/`school_name`/`school_address` are all NULL.
3. **Other eligibility path:** submit with `eligibility_status=Eligible`, `eligibility_type=Other`, `other_eligibility_type=career service eligibility` (lowercase, with leading/trailing spaces: `  career service eligibility  `). Expect success. Confirm via DB query that `other_eligibility_type` is stored as exactly `CAREER SERVICE ELIGIBILITY` (uppercase, trimmed) — this proves server-side uppercasing/trimming works independent of JavaScript.
4. **Not Eligible path:** submit with `eligibility_status=Not Eligible` and also include `eligibility_type=Civil Service Professional` and `other_eligibility_type=SHOULD NOT BE SAVED` in the POST body anyway (simulating a bypassed/tampered client). Expect success. Confirm via DB query that `eligibility_type` and `other_eligibility_type` are BOTH NULL despite being present in the POST — this proves the server clears stale values rather than trusting the client.
5. **Invalid submission:** submit with `educational_level` omitted entirely, and separately a submission with `completion_status=Graduate` but `date_graduated` in the future (e.g. next year). Expect both to be rejected (no redirect, error shown, no new row created) — confirm via `SELECT COUNT(*) FROM care_jf_applicants` unchanged before/after each of these two attempts.
6. **Regression:** confirm a normal registration with NO educational/eligibility manipulation (i.e., a realistic full submission through every field) still succeeds end-to-end exactly as before, and that the success popup, Applicant Code, and QR code rendering are visually unaffected (check the response HTML contains the expected `regQrCanvas` canvas element and applicant code).

Delete every test applicant you created (`DELETE FROM care_jf_applicants WHERE id = ...`) before finishing — do not leave test data in the shared database. Stop your `php -S` server when done.

- [ ] **Step 9: Commit**

```bash
git add public/register-applicant.php
git commit -m "Add Educational Attainment and Eligibility to public Job Seeker Registration

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

### Task 3: `public/applicant-create.php` — Administrator → Register New Applicant

**Files:**
- Modify: `public/applicant-create.php`

**Interfaces:**
- Consumes: the 10 columns from Task 1. Same validation rules as Task 2 (independently implemented — this codebase has no shared validation function to reuse, confirmed).

**Context:** This is the Administrator/Employee-only internal registration page (`require_role(['Administrator', 'Employee'])`). Its current field set is Personal Information only (no Services Availed section, no place_of_birth/email — that's a pre-existing, separately-tracked gap from an earlier task, NOT something this task fixes). You are adding the same two new sections as Task 2, using this page's own existing conventions (note: its input classes include `focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none` in addition to the base classes Task 2 used — match THIS file's existing class string exactly, do not copy Task 2's shorter class list here). The `<form>` already has `x-data="{ confirming: false }"` — extend it, do not replace it.

- [ ] **Step 1: Add the option-list constants, `$old` defaults, and POST field reads**

**Placement matters — read this carefully (an earlier task on this same plan shipped this exact mistake and had to be fixed).** `$educLevelOptions` and `$eligibilityTypeOptions` are consumed both by validation (inside the `if (POST)` block) and by the `<select>` dropdowns in the always-rendered template (rendered on every page load, GET or POST). Declare BOTH arrays where `$old` is initialized, ABOVE the `if ($_SERVER['REQUEST_METHOD'] === 'POST')` line — NOT inside it. If declared only inside the POST block, every plain GET page load (every first visit) renders both `<select>` elements with no options at all, making the fields impossible to select in a browser.

Near the top of the file, find where `$old` is initialized (an array with `'last_name' => '', 'first_name' => '', ...` etc. — read the current file to find its exact current key list). Add the same 10 keys as Task 2 Step 2 to it, AND add the two option-list array declarations (identical to Task 2 Step 1's corrected placement) right next to it, both at the top level of the file, not indented inside any `if` block:

```php
$educLevelOptions = ['High School/Senior High School Graduate', 'Technical/Vocational', 'College Graduate', 'Postgraduate (Master/Doctorate)'];
$eligibilityTypeOptions = ['Civil Service Professional', 'Civil Service Subprofessional', 'Civil Service Professional (Preference Rating)', 'Civil Service Subprofessional (Preference Rating)', 'Basic Competency on Local Treasury', 'Barangay Official', 'Honor Graduate Eligibility', 'Fire Officer', 'Penology Officer', 'Skills Eligibility (MC 11)', 'Other'];
```

Inside the `if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_require(); ... }` block, find where existing fields are read via `clean($_POST[...] ?? '')` and add the same 10 `$old[...] = clean($_POST[...] ?? '')` reads as Task 2 Step 1 (do NOT declare the two arrays again here — they're already declared above the POST block per the instruction above). This file's existing uppercase-normalization loop is `foreach (['last_name', 'first_name', 'middle_name', 'address'] as $upperKey)` (no `place_of_birth` here, since this page has no such field) — extend it to also include `'school_name'` and `'school_address'`:
```php
    foreach (['last_name', 'first_name', 'middle_name', 'address', 'school_name', 'school_address'] as $upperKey) {
        $old[$upperKey] = mb_strtoupper($old[$upperKey], 'UTF-8');
    }
```

- [ ] **Step 2: Add the validation block**

Insert the identical validation block from Task 2 Step 3, in the equivalent position (after the existing address/sex/civil_status checks, before the duplicate-name check).

- [ ] **Step 3: Add the ten columns to the INSERT statement**

This file's existing INSERT is:
```php
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_applicants
                    (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth, contact_number, address, civil_status)
                 VALUES
                    (:code, :ln, :fn, :mn, :ext, :sex, :dob, :contact, :address, :civil)"
            );
            $stmt->execute([
                ':code'    => $code,
                ':ln'      => $old['last_name'],
                ':fn'      => $old['first_name'],
                ':mn'      => $old['middle_name'] ?: null,
                ':ext'     => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex'     => $old['sex'],
                ':dob'     => $old['date_of_birth'],
                ':contact' => $old['contact_number'],
                ':address' => $old['address'],
                ':civil'   => $old['civil_status'],
            ]);
```

Replace it with:
```php
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_applicants
                    (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth, contact_number, address, civil_status,
                     educational_level, completion_status, highest_year_level_units, date_graduated,
                     course_degree, school_name, school_address,
                     eligibility_status, eligibility_type, other_eligibility_type)
                 VALUES
                    (:code, :ln, :fn, :mn, :ext, :sex, :dob, :contact, :address, :civil,
                     :educ_level, :completion, :hylu, :date_grad, :course, :school_name, :school_addr,
                     :elig_status, :elig_type, :other_elig)"
            );
            $stmt->execute([
                ':code'    => $code,
                ':ln'      => $old['last_name'],
                ':fn'      => $old['first_name'],
                ':mn'      => $old['middle_name'] ?: null,
                ':ext'     => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex'     => $old['sex'],
                ':dob'     => $old['date_of_birth'],
                ':contact' => $old['contact_number'],
                ':address' => $old['address'],
                ':civil'   => $old['civil_status'],
                ':educ_level' => $old['educational_level'],
                ':completion' => $old['completion_status'],
                ':hylu'       => $old['highest_year_level_units'] ?: null,
                ':date_grad'  => $old['date_graduated'] ?: null,
                ':course'     => $old['course_degree'] ?: null,
                ':school_name' => $old['school_name'] ?: null,
                ':school_addr' => $old['school_address'] ?: null,
                ':elig_status' => $old['eligibility_status'],
                ':elig_type'   => $old['eligibility_type'] ?: null,
                ':other_elig'  => $old['other_eligibility_type'] ?: null,
            ]);
```

(This INSERT is inside the try/catch transaction block already added to this file in an earlier task — keep that try/catch structure exactly as-is, only change the SQL/params shown above.)

- [ ] **Step 4: Extend the `<form>` tag's `x-data`**

Find `x-data="{ confirming: false }"` on the `<form>` tag and change it to:
```php
  <form method="POST" id="registerForm" x-data="{ confirming: false, completion: '<?= e($old['completion_status']) ?>', eligibility: '<?= e($old['eligibility_status']) ?>', eligType: '<?= e($old['eligibility_type']) ?>' }"
```
(keep the rest of that tag — the `@submit="..."` attribute — exactly as it already is)

- [ ] **Step 5: Insert the Educational Attainment and Eligibility cards**

Find the existing Personal Information card's closing (`</div>\n    </div>` after its `grid sm:grid-cols-2 gap-4` block) and the `<div class="flex justify-end gap-3 mt-5">` that follows it. Insert the same two cards as Task 2 Step 6 between them, but using THIS file's input class strings (i.e., append ` focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none` to every `<input>`/`<select>`/`<textarea>` element's class attribute, matching the rest of this specific file — copy the exact class list from a neighboring existing field in this same file, e.g. its `last_name` input, rather than Task 2's version).

- [ ] **Step 6: Lint**

Run: `& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\applicant-system\public\applicant-create.php"`
Expected: `No syntax errors detected`

- [ ] **Step 7: Live verification**

Using your `php -S` instance (start one if Task 2's isn't still running) and a logged-in Administrator or Employee session (username `admin`, password `Admin@123`), repeat the same 6 test scenarios from Task 2 Step 8, adapted to this page's URL and its smaller base field set (no services-availed checkboxes, no place_of_birth/email on this page — omit those from your test POST body, they don't exist here). Delete every test applicant afterward. Also confirm the existing "Register Another"-equivalent flow on this page (its own confirmation-modal-then-redirect success path) still works exactly as before — this page has no education/eligibility-specific interaction with that modal, just confirm the modal still opens/confirms/submits correctly with the new fields present on the page.

- [ ] **Step 8: Commit**

```bash
git add public/applicant-create.php
git commit -m "Add Educational Attainment and Eligibility to Administrator applicant registration

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

### Task 4: `public/applicant-edit.php` — Edit Applicant

**Files:**
- Modify: `public/applicant-edit.php`

**Interfaces:**
- Consumes: the 10 columns from Task 1. Same validation rules as Tasks 2-3.

**Context:** This page already does `$stmt = $pdo->prepare("SELECT * FROM care_jf_applicants WHERE id = :id AND is_deleted = 0"); ... $old = $applicant;` — because it uses `SELECT *`, the 10 new columns are ALREADY present in `$applicant` and copied into `$old` with zero extra code, for the initial GET/prefill case. You only need to: (a) handle the POST-time re-read/validation/clear-on-hide (same as Tasks 2-3), (b) add the two new sections to the form, prefilled from `$old` exactly like every other existing field on this page already is, and (c) add the columns to the existing UPDATE statement. The `<form>` already has `x-data="{ employmentFlag: ..., agencySel: ... }"` — extend it.

**Important prefill detail:** unlike Tasks 2-3 (where `$old['completion_status']` starts as `''` and only gets a real value after a POST attempt), on a fresh GET load of this Edit page `$old['completion_status']` etc. will already hold the applicant's SAVED value (possibly `NULL` from the database for an applicant that predates this feature — PHP will read that as `null`, and `e(null)` / string comparisons against `null` behave like `e('')`/comparing against `''`, which is what every `<?= $old['completion_status']==='Graduate'?'selected':'' ?>`-style check in Task 2/3's cards already handles correctly — no special NULL-handling code is needed, but be aware `null` is the expected value here for pre-existing applicants, not `''` as in Tasks 2-3's fresh forms).

- [ ] **Step 1: Add the option-list constants and POST field reads**

**Placement matters — read this carefully (an earlier task on this same plan shipped this exact mistake and had to be fixed).** `$educLevelOptions` and `$eligibilityTypeOptions` are consumed both by validation (inside the `if (POST)` block) and by the `<select>` dropdowns in the always-rendered template. Declare BOTH arrays at the top level of the file, ABOVE the `if ($_SERVER['REQUEST_METHOD'] === 'POST')` line — a good spot is right after `$old = $applicant;` near the top of the file. If declared only inside the POST block, every plain GET page load (i.e. every time this Edit page is opened, since editing always starts with a GET) renders both `<select>` elements with no options at all:

```php
$educLevelOptions = ['High School/Senior High School Graduate', 'Technical/Vocational', 'College Graduate', 'Postgraduate (Master/Doctorate)'];
$eligibilityTypeOptions = ['Civil Service Professional', 'Civil Service Subprofessional', 'Civil Service Professional (Preference Rating)', 'Civil Service Subprofessional (Preference Rating)', 'Basic Competency on Local Treasury', 'Barangay Official', 'Honor Graduate Eligibility', 'Fire Officer', 'Penology Officer', 'Skills Eligibility (MC 11)', 'Other'];
```

Inside `if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_require(); ... }`, find the existing `foreach (['last_name','first_name','middle_name','extension_name','sex','date_of_birth','place_of_birth','contact_number','email_address','address','civil_status'] as $key) { $old[$key] = clean($_POST[$key] ?? ''); }` loop. Immediately after it, add the same 10 `$old[...] = clean($_POST[...] ?? '')` reads from Task 2 Step 1 (do NOT declare the two arrays again here — they're already declared above the POST block per the instruction above).

Extend the existing uppercase loop `foreach (['last_name', 'first_name', 'middle_name', 'place_of_birth', 'address'] as $upperKey)` to also include `'school_name'` and `'school_address'`:
```php
    foreach (['last_name', 'first_name', 'middle_name', 'place_of_birth', 'address', 'school_name', 'school_address'] as $upperKey) {
        $old[$upperKey] = mb_strtoupper($old[$upperKey], 'UTF-8');
    }
```

- [ ] **Step 2: Add the validation block**

Insert the identical validation block from Task 2 Step 3, positioned after the existing `if ($old['address'] === '') $errors['address'] = 'Address is required.';` line and before the `$validCivil = [...]` line.

- [ ] **Step 3: Add the ten columns to the UPDATE statement**

Find the existing UPDATE:
```php
            $stmt = $pdo->prepare(
                "UPDATE care_jf_applicants SET last_name=:ln, first_name=:fn, middle_name=:mn, extension_name=:ext,
                 sex=:sex, date_of_birth=:dob, place_of_birth=:pob, contact_number=:contact, email_address=:email,
                 address=:address, civil_status=:civil, remarks=:remarks,
                 service_job_seeker=:svc_js, service_agency_services=:svc_as
                 WHERE id=:id"
            );
            $stmt->execute([
                ':ln' => $old['last_name'], ':fn' => $old['first_name'], ':mn' => $old['middle_name'] ?: null,
                ':ext' => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex' => $old['sex'], ':dob' => $old['date_of_birth'], ':pob' => $old['place_of_birth'] ?: null,
                ':contact' => $old['contact_number'], ':email' => $old['email_address'] ?: null,
                ':address' => $old['address'], ':civil' => $old['civil_status'], ':remarks' => $old['remarks'] ?: null,
                ':svc_js' => $old['service_job_seeker'] ? 1 : 0, ':svc_as' => $old['service_agency_services'] ? 1 : 0,
                ':id' => $id,
            ]);
```

Replace it with:
```php
            $stmt = $pdo->prepare(
                "UPDATE care_jf_applicants SET last_name=:ln, first_name=:fn, middle_name=:mn, extension_name=:ext,
                 sex=:sex, date_of_birth=:dob, place_of_birth=:pob, contact_number=:contact, email_address=:email,
                 address=:address, civil_status=:civil, remarks=:remarks,
                 service_job_seeker=:svc_js, service_agency_services=:svc_as,
                 educational_level=:educ_level, completion_status=:completion, highest_year_level_units=:hylu,
                 date_graduated=:date_grad, course_degree=:course, school_name=:school_name, school_address=:school_addr,
                 eligibility_status=:elig_status, eligibility_type=:elig_type, other_eligibility_type=:other_elig
                 WHERE id=:id"
            );
            $stmt->execute([
                ':ln' => $old['last_name'], ':fn' => $old['first_name'], ':mn' => $old['middle_name'] ?: null,
                ':ext' => $old['extension_name'] !== 'NONE' ? $old['extension_name'] : null,
                ':sex' => $old['sex'], ':dob' => $old['date_of_birth'], ':pob' => $old['place_of_birth'] ?: null,
                ':contact' => $old['contact_number'], ':email' => $old['email_address'] ?: null,
                ':address' => $old['address'], ':civil' => $old['civil_status'], ':remarks' => $old['remarks'] ?: null,
                ':svc_js' => $old['service_job_seeker'] ? 1 : 0, ':svc_as' => $old['service_agency_services'] ? 1 : 0,
                ':educ_level' => $old['educational_level'],
                ':completion' => $old['completion_status'],
                ':hylu'       => $old['highest_year_level_units'] ?: null,
                ':date_grad'  => $old['date_graduated'] ?: null,
                ':course'     => $old['course_degree'] ?: null,
                ':school_name' => $old['school_name'] ?: null,
                ':school_addr' => $old['school_address'] ?: null,
                ':elig_status' => $old['eligibility_status'],
                ':elig_type'   => $old['eligibility_type'] ?: null,
                ':other_elig'  => $old['other_eligibility_type'] ?: null,
                ':id' => $id,
            ]);
```

- [ ] **Step 4: Extend the `<form>` tag's `x-data`**

Find `x-data="{ employmentFlag: '<?= e($old['employment_status_flag']) ?>', agencySel: '<?= $old['agency_id'] !== '' ? (int)$old['agency_id'] : '' ?>' }"` and change it to:
```php
  <form method="POST" x-data="{ employmentFlag: '<?= e($old['employment_status_flag']) ?>', agencySel: '<?= $old['agency_id'] !== '' ? (int)$old['agency_id'] : '' ?>', completion: '<?= e($old['completion_status'] ?? '') ?>', eligibility: '<?= e($old['eligibility_status'] ?? '') ?>', eligType: '<?= e($old['eligibility_type'] ?? '') ?>' }"
```
Note the `?? ''` on these three (unlike Tasks 2-3, `$old['completion_status']` etc. can genuinely be `null` here from the database, and `e(null)` already returns `''` safely per `e()`'s own signature — but the `?? ''` inside the Alpine string interpolation avoids emitting the literal text `null` into the JS string if `e()`'s behavior ever changes; match this defensive pattern).

- [ ] **Step 5: Insert the Educational Attainment and Eligibility cards**

Find the existing Personal Information card's closing and the "Employment Information" card that follows it (`<div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6"><h2 ...>Employment Information</h2>`). Insert the same two cards from Task 2 Step 6 between the Personal Information card and the Employment Information card, using this file's own class conventions (this file's inputs use the shorter class list like Task 2's, e.g. `class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"` with no extra focus-ring classes — confirm by reading a neighboring field in this exact file and match it) and using `?? ''` after each `$old['...']` reference in `value="<?= e($old['...'] ?? '') ?>"` positions (since, again, these can be genuinely NULL from the database for this specific page, unlike Tasks 2-3).

- [ ] **Step 6: Lint**

Run: `& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\applicant-system\public\applicant-edit.php"`
Expected: `No syntax errors detected`

- [ ] **Step 7: Live verification**

Using your `php -S` instance and an Administrator/Employee session:

1. Create one test applicant directly via SQL (or reuse the public registration flow) with `educational_level`, `completion_status='Graduate'`, and its Graduate fields all populated, plus `eligibility_status='Eligible'`, `eligibility_type='Other'`, `other_eligibility_type='TEST TYPE'`. Load `applicant-edit.php?id=<that id>` and confirm (via `get_page_text` or reading the response HTML) that every field's current value is correctly pre-selected/pre-filled, AND that the Graduate-only fields are visible while the Not-Graduate field is not (check the rendered `x-show`/`data-conditional-hidden` state matches, same technique as verified in the multi-user-agency and vacancy-delete work this session).
2. Edit that applicant: change `completion_status` to `Not Graduate` with a `highest_year_level_units` value, submit. Confirm via DB query that the Graduate fields are now NULL and `highest_year_level_units` holds the new value.
3. **Critical pre-existing-applicant regression test:** find or create an applicant whose educational/eligibility columns are all NULL (simulating an applicant registered before this feature). Load its Edit page — confirm it loads without any PHP warning/error (check for "Warning" or "Notice" text in the raw response body, and check your `php -S` server's own stdout/stderr for logged warnings) and that all new fields render as their empty/unselected default state. Edit and save it with real educational/eligibility values, confirm the save succeeds and the values now persist correctly. This is the specific scenario Phase 23 of the request is about — do not skip it.

Clean up any test data you created. Stop your `php -S` server when done.

- [ ] **Step 8: Commit**

```bash
git add public/applicant-edit.php
git commit -m "Add Educational Attainment and Eligibility to Edit Applicant, with correct prefill

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

### Task 5: `public/applicant-view.php` — Profile / Print display

**Files:**
- Modify: `public/applicant-view.php`

**Interfaces:**
- Consumes: the 10 columns from Task 1 (already available via this page's own existing `SELECT * FROM care_jf_applicants` — no query change needed here at all).

**Context:** This page is both the on-screen profile AND the print target (there is no separate print file — `window.print()` prints this same page, and it already uses `print:hidden` throughout for screen-only chrome like buttons and the sidebar). You are adding two new read-only display cards — Educational Attainment, then Eligibility — between the existing "Personal Information" card and the "Employment History" card. These print automatically as part of the normal page flow; no special print-only markup is needed or should be added (this codebase's other print-redesign work is tracked separately and is explicitly out of scope here — do not add a letterhead, do not restructure the existing print CSS, do not touch anything about the QR code display).

- [ ] **Step 1: Insert the two new cards**

Find this exact closing sequence (the end of the Personal Information card, immediately followed by the PHP block that computes `$canTagAsPartnerAgency`/`$canTagAsStaff` and then the Employment History card's opening):

```php
        </dl>
      </div>

      <?php
        $canTagAsPartnerAgency = is_partner_agency() && !$myOpenReview && !$applicantIsHired;
        $canTagAsStaff = can_manage_employment() && $tagAgencyOptions && !$applicantIsHired;
      ?>
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6" x-data="{ confirmHireRecordId: null, showTagForReview: false }">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Employment History</h2>
```

Insert the following TWO new cards between the Personal Information card's closing `</div>` (the one right after `</dl>`) and the `<?php $canTagAsPartnerAgency = ...` PHP block:

```php
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Educational Attainment</h2>
        <?php if (empty($applicant['educational_level'])): ?>
          <p class="text-sm text-slate-400">No educational attainment information on file.</p>
        <?php else: ?>
        <dl class="grid sm:grid-cols-2 gap-4 text-sm">
          <div><dt class="text-slate-500">Educational Level</dt><dd class="font-medium text-slate-800"><?= e($applicant['educational_level']) ?></dd></div>
          <div><dt class="text-slate-500">Completion</dt><dd class="font-medium text-slate-800"><?= e($applicant['completion_status'] ?: '—') ?></dd></div>
          <?php if ($applicant['completion_status'] === 'Not Graduate' && !empty($applicant['highest_year_level_units'])): ?>
            <div class="sm:col-span-2"><dt class="text-slate-500">Highest Year/Level/Units Earned</dt><dd class="font-medium text-slate-800"><?= e($applicant['highest_year_level_units']) ?></dd></div>
          <?php elseif ($applicant['completion_status'] === 'Graduate'): ?>
            <div><dt class="text-slate-500">Date Graduated</dt><dd class="font-medium text-slate-800"><?= $applicant['date_graduated'] ? format_date($applicant['date_graduated']) : '—' ?></dd></div>
            <div><dt class="text-slate-500">Complete Title of Course/Degree</dt><dd class="font-medium text-slate-800"><?= e($applicant['course_degree'] ?: '—') ?></dd></div>
            <div><dt class="text-slate-500">Name of School</dt><dd class="font-medium text-slate-800"><?= e($applicant['school_name'] ?: '—') ?></dd></div>
            <div><dt class="text-slate-500">School Address</dt><dd class="font-medium text-slate-800"><?= e($applicant['school_address'] ?: '—') ?></dd></div>
          <?php endif; ?>
        </dl>
        <?php endif; ?>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-4">Eligibility</h2>
        <?php if (empty($applicant['eligibility_status'])): ?>
          <p class="text-sm text-slate-400">No eligibility information on file.</p>
        <?php else: ?>
        <dl class="grid sm:grid-cols-2 gap-4 text-sm">
          <div><dt class="text-slate-500">Eligibility Status</dt><dd class="font-medium text-slate-800"><?= e($applicant['eligibility_status']) ?></dd></div>
          <?php if ($applicant['eligibility_status'] === 'Eligible' && !empty($applicant['eligibility_type'])): ?>
            <div><dt class="text-slate-500">Eligibility Type</dt><dd class="font-medium text-slate-800"><?= e($applicant['eligibility_type']) ?></dd></div>
            <?php if ($applicant['eligibility_type'] === 'Other' && !empty($applicant['other_eligibility_type'])): ?>
              <div><dt class="text-slate-500">Other Eligibility Type</dt><dd class="font-medium text-slate-800"><?= e($applicant['other_eligibility_type']) ?></dd></div>
            <?php endif; ?>
          <?php endif; ?>
        </dl>
        <?php endif; ?>
      </div>

```

- [ ] **Step 2: Lint**

Run: `& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\applicant-system\public\applicant-view.php"`
Expected: `No syntax errors detected`

- [ ] **Step 3: Live verification**

Using your `php -S` instance:

1. Load the profile page (`applicant-view.php?id=...`) for the Graduate-path test applicant from Task 4 Step 7 (or create a fresh one) and confirm the Educational Attainment card shows Educational Level, Completion, Date Graduated, Course/Degree, School Name, School Address — all correct — and does NOT show "Highest Year/Level/Units Earned". Confirm the Eligibility card shows Eligibility Status, Eligibility Type, and (if Other) Other Eligibility Type.
2. Load the profile page for a Not-Graduate-path applicant and confirm the opposite: Highest Year/Level/Units Earned shown, the four Graduate fields not shown.
3. Load the profile page for an applicant with NULL educational/eligibility columns (the pre-existing-applicant scenario from Task 4 Step 7.3) and confirm both new cards render their "No ... information on file." empty state cleanly — no PHP warnings, no broken layout, no empty `<dd>` elements with dashes scattered around.
4. Confirm this page's existing regression surface is untouched: the applicant's name/ID/QR code block above still renders, the Employment History section still renders below your new cards, the Remarks section still renders at the bottom, and (if you're testing as a role that can see it) the print button (`onclick="window.print()"`) is still present and unchanged.

Clean up any test data you created. Stop your `php -S` server when done.

- [ ] **Step 4: Commit**

```bash
git add public/applicant-view.php
git commit -m "Display Educational Attainment and Eligibility on the applicant profile/print page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

## Self-Review Notes (for whoever executes this plan)

- **Spec coverage:** every numbered section of the user's original request maps to a task above: §3-4 (Educational Level/Completion fields) → Tasks 2-4's Educational Attainment card. §4.1-4.2 (conditional Graduate/Not-Graduate fields) → same, with the `x-show`/`:data-conditional-hidden`/`:required` triad. §5-8 (Eligibility, conditional type, Other uppercase) → Tasks 2-4's Eligibility card. §10-11 (server-side validation, date validation) → the validation block, identical across Tasks 2-4. §12 (Edit prefill) → Task 4. §13 (View) → Task 5. §14 (Print) → Task 5 (same page, no separate file). §15-16 (registration success / QR unaffected) → verified in each task's live-testing step, no code changes needed since the existing success/QR flow is untouched by any diff in this plan. §17-18 (security/DB safety) → Global Constraints + Task 1's additive-only migration. §21 (reports) → explicitly declared out of scope in Global Constraints, with reasoning. §22 (not agency-specific) → confirmed by design: all 10 columns live on `care_jf_applicants` only, never on `care_jf_employment_records`.
- **Why no shared validation function was introduced:** confirmed via inspection that this codebase validates every existing field inline, per-file, with no shared validation module — introducing one now for just these 10 fields would be inconsistent with the established pattern and is exactly the kind of unrequested abstraction the task explicitly warns against ("Do not silently change unrelated behavior"). The three tasks' validation blocks are intentionally near-identical copies, matching how `last_name`/`sex`/`civil_status`/etc. are already triplicated across these same three files today.
- **Why Tasks 2-4 are NOT merged into fewer tasks despite near-identical logic:** each touches a different file with different surrounding context (different existing class strings, different existing `x-data`, register-applicant.php's extra modals, applicant-edit.php's SELECT * prefill and Employment section, applicant-create.php's smaller base field set) — collapsing them risks context bleed between files. Each gets its own task-scoped review gate.
