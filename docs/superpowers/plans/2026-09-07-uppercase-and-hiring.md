# Uppercase Formatting & Partner Agency Hiring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** All applicable applicant/Partner-Agency free text is automatically uppercased (except email/username/password), and a Partner Agency or Administrator/Employee can move an applicant from `FOR FURTHER REVIEW` to a new `Hired` classification, with the hiring agency's info then visible on the applicant's profile.

**Architecture:** Extend the existing (but inconsistently-applied) `uppercase-field` client-side + `mb_strtoupper()` server-side convention from `register-applicant.php` to every other form that writes the same kind of data. Add `'Hired'` as a new `employment_status` ENUM value and a new `mark_hired` POST action on the existing `applicant-view.php`, reusing the same `employment_records` insert pattern already used by `employment-form.php`/`applicant-edit.php`. No new tables — `employment_records.agency_id` (added in the prior Partner Agency Registration work) already persists "which agency hired this applicant."

**Tech Stack:** PHP 8 (no framework), PDO/MySQL, Alpine.js, Tailwind (prebuilt CSS — the only new utility classes used, `uppercase`/`emerald-*`, are already present in the built CSS from prior work; verify per-task, no rebuild expected).

**Spec:** `docs/superpowers/specs/2026-09-07-uppercase-and-hiring-design.md`

## Global Constraints

- Uppercase applies to user-entered free-text data fields (names, addresses, agency contact person) and derived status/service labels — **never** to `email`, `username`, or `password` fields, anywhere.
- Free-form "Remarks/Notes" textareas (`applicants.remarks`, `employment_records.remarks`) are **not** forced uppercase.
- `contact_no`/`contact_number` fields are left untouched (numeric).
- Server-side normalization (`mb_strtoupper($value, 'UTF-8')`) is required on every form that writes an affected column — client-side JS alone is not sufficient (defense in depth, matching the existing comment in `register-applicant.php`).
- Derived/enum labels (employment classifications, "For Further Review", service badges) are uppercased **visually only** (Tailwind `uppercase` class) — their underlying stored/compared values never change, so no SQL `WHERE`, PHP `switch`, or color-map lookup anywhere needs to change, except where a task explicitly adds a new `'Hired'` key alongside the existing ones.
- Every state-changing POST handler calls `csrf_require()`; every form emits `csrf_field()`.
- A Partner Agency's `agency_id` is **always** obtained via `current_agency_id($pdo)` — never from `$_GET`/`$_POST` — for the new hiring action, exactly as established throughout the prior Partner Agency work.
- Every protected action re-checks its role/permission server-side, not just via UI hiding.
- No automated test suite exists in this repo (confirmed CLAUDE.md convention). Verification uses `php -l`, MySQL CLI assertions, and curl-driven HTTP checks against the real local database — the same substitute used throughout the prior plan.
- Local verification tool paths (Windows/XAMPP, confirmed present): PHP `/c/xampp/php/php.exe`; MySQL client `/c/xampp/mysql/bin/mysql.exe -u root applicant_system`; DB name `applicant_system`; seeded admin credentials `admin` / `Admin@123`.
- `database/database.sql` and both pre-existing migration files (`update_application_management.sql`, `add_partner_agency_accounts.sql`) are **not edited** — this plan's schema change goes in a new migration file only.

---

## Task 1: Migration — `Hired` classification + uppercase backfill, plus color map

**Files:**
- Create: `database/migrations/add_hired_status_and_uppercase_backfill.sql`
- Modify: `includes/functions.php` (`current_employment_status()`'s color map)

**Interfaces:**
- Produces: `employment_records.employment_status` ENUM now includes `'Hired'`. `current_employment_status()`'s returned `color` for `label === 'Hired'` is `'bg-emerald-100 text-emerald-800'`. Consumed by Tasks 2, 3, 4.

- [ ] **Step 1: Write the migration**

```sql
-- =====================================================================
-- Migration: add_hired_status_and_uppercase_backfill.sql
-- Adds a 'Hired' employment_status classification (used by the new
-- Partner Agency / Admin / Employee "Mark as Hired" quick action) and
-- backfills UPPER() normalization onto columns that should already be
-- uppercase but weren't consistently normalized by every form that
-- writes them.
--
-- Safe to run against the current database (already includes the
-- Partner Agency Registration migration). Idempotent: widening an enum
-- to include a value it already has, and UPPER()-ing already-uppercase
-- text, are both safe no-ops on re-run.
--
-- Usage:
--   mysql -u root -p applicant_system < database/migrations/add_hired_status_and_uppercase_backfill.sql
-- =====================================================================

USE applicant_system;

-- ---------------------------------------------------------------------
-- 1. employment_records: add 'Hired' as a new classification, used by
--    the new one-click "Mark as Hired" action. Existing granular
--    classifications (Job Order/Temporary/COS/Permanent/Casual/Other)
--    and the full Employment module are unchanged.
-- ---------------------------------------------------------------------
ALTER TABLE employment_records
  MODIFY COLUMN employment_status
  ENUM('Job Order','Temporary','COS','Permanent','Casual','Other','Hired')
  NOT NULL;

-- ---------------------------------------------------------------------
-- 2. Uppercase backfill.
--
--    applicants: a prior migration already ran UPPER() on these columns
--    once, but public/applicant-create.php and public/applicant-edit.php
--    never normalized on save, so any admin-created/edited row since
--    then may be mixed-case. Re-running UPPER() on already-uppercase
--    text is a safe no-op.
--
--    partner_agencies / users.full_name: never normalized before this
--    migration.
-- ---------------------------------------------------------------------
UPDATE applicants SET
  last_name      = UPPER(last_name),
  first_name     = UPPER(first_name),
  middle_name    = UPPER(middle_name),
  extension_name = UPPER(extension_name),
  address        = UPPER(address),
  place_of_birth = UPPER(place_of_birth)
WHERE is_deleted = 0 OR is_deleted = 1;

UPDATE partner_agencies SET
  agency_name    = UPPER(agency_name),
  address        = UPPER(address),
  contact_person = UPPER(contact_person);

UPDATE users SET full_name = UPPER(full_name);

-- ---------------------------------------------------------------------
-- 3. Sanity check
-- ---------------------------------------------------------------------
SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS employment_records_hired FROM employment_records WHERE employment_status = 'Hired';
```

- [ ] **Step 2: Apply the migration and verify**

Run:
```bash
/c/xampp/mysql/bin/mysql.exe -u root applicant_system < database/migrations/add_hired_status_and_uppercase_backfill.sql
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "DESCRIBE employment_records;" | grep employment_status
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "SELECT last_name, first_name, address FROM applicants LIMIT 3;"
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "SELECT agency_name, address, contact_person FROM partner_agencies LIMIT 3;"
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "SELECT full_name FROM users LIMIT 3;"
```
Expected: `employment_status` enum column definition includes `'Hired'`; all sampled values are already-uppercase (unchanged visually, since they were already uppercase, confirming the backfill is a safe no-op on this data).

- [ ] **Step 3: Confirm idempotency**

Run: `/c/xampp/mysql/bin/mysql.exe -u root applicant_system < database/migrations/add_hired_status_and_uppercase_backfill.sql`
Expected: runs again with no errors.

- [ ] **Step 4: Add the `Hired` color map entry**

Find in `includes/functions.php`:
```php
    $colors = [
        'Job Order'  => 'bg-yellow-100 text-yellow-800',
        'Temporary'  => 'bg-purple-100 text-purple-800',
        'COS'        => 'bg-blue-100 text-blue-800',
        'Permanent'  => 'bg-green-100 text-green-800',
        'Casual'     => 'bg-orange-100 text-orange-800',
        'Other'      => 'bg-gray-100 text-gray-700',
    ];
```
Replace with:
```php
    $colors = [
        'Job Order'  => 'bg-yellow-100 text-yellow-800',
        'Temporary'  => 'bg-purple-100 text-purple-800',
        'COS'        => 'bg-blue-100 text-blue-800',
        'Permanent'  => 'bg-green-100 text-green-800',
        'Casual'     => 'bg-orange-100 text-orange-800',
        'Other'      => 'bg-gray-100 text-gray-700',
        'Hired'      => 'bg-emerald-100 text-emerald-800',
    ];
```

- [ ] **Step 5: Syntax check and functional verification**

Run: `/c/xampp/php/php.exe -l includes/functions.php`
Expected: "No syntax errors detected"

Write and run a small CLI script (scratchpad, not in repo):
```php
<?php
declare(strict_types=1);
require_once 'C:/xampp/htdocs/applicant-system/includes/auth.php';
$pdo = Database::getConnection();

// Insert a throwaway applicant + a 'Hired' employment record, confirm the
// color map resolves correctly, then clean up.
$pdo->prepare("DELETE FROM applicants WHERE applicant_code = 'VERIFY-HIRED-001'")->execute();
$pdo->prepare(
    "INSERT INTO applicants (applicant_code, last_name, first_name, sex, date_of_birth, contact_number, address, civil_status)
     VALUES ('VERIFY-HIRED-001', 'TEST', 'VERIFY', 'MALE', '1990-01-01', '09170000000', 'TEST ADDR', 'SINGLE')"
)->execute();
$aid = (int)$pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO employment_records (applicant_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
     VALUES (:aid, 'Test Agency', 'Test Addr', CURDATE(), 'Hired', 1, 'Active')"
)->execute([':aid' => $aid]);

$status = current_employment_status($pdo, $aid);
echo ($status['label'] === 'Hired' && $status['color'] === 'bg-emerald-100 text-emerald-800') ? "PASS\n" : "FAIL: " . json_encode($status) . "\n";

$pdo->prepare("DELETE FROM applicants WHERE id = :id")->execute([':id' => $aid]);
```
Run: `/c/xampp/php/php.exe /path/to/scratchpad/verify_hired_color.php`
Expected: `PASS`

- [ ] **Step 6: Commit**

```bash
git add database/migrations/add_hired_status_and_uppercase_backfill.sql includes/functions.php
git commit -m "db: add Hired employment classification and uppercase backfill migration"
```

---

## Task 2: Uppercase data-entry forms (batch — 7 files, same-shape edits)

**Files:**
- Modify: `public/applicant-create.php`, `public/applicant-edit.php`, `public/employment-form.php`, `public/partner-agency-form.php`, `public/my-agency.php`, `public/login.php`, `public/users.php`

**Interfaces:**
- Consumes: Task 1's `'Hired'` enum value (for the two classification-dropdown additions below).
- Produces: nothing consumed by later tasks by name — this task's effect is purely "these forms now normalize their free-text fields to uppercase," observable via the DB.

This is one batched dispatch covering 7 files with the same two kinds of edit repeated: (a) add `uppercase-field uppercase` to the relevant `<input>`/`<textarea>` elements' `class` attribute, and (b) add `mb_strtoupper($value, 'UTF-8')` server-side normalization for the same fields. Two files also get `'Hired'` added to a classification `<option>` list (unrelated to uppercase, but bundled here because these are the same files, to avoid two tasks editing the same file — see Global Constraints' single-file-ownership discipline from the prior plan).

- [ ] **Step 1: `public/applicant-create.php`**

Find:
```php
    foreach ($old as $key => $_) {
        $old[$key] = clean($_POST[$key] ?? '');
    }

    // ---- Server-side validation ----
```
Replace:
```php
    foreach ($old as $key => $_) {
        $old[$key] = clean($_POST[$key] ?? '');
    }

    // Normalize to uppercase server-side too — defense in depth in case
    // JavaScript is disabled or the form is submitted directly.
    foreach (['last_name', 'first_name', 'middle_name', 'address'] as $upperKey) {
        $old[$upperKey] = mb_strtoupper($old[$upperKey], 'UTF-8');
    }

    // ---- Server-side validation ----
```

Find:
```php
          <input type="text" name="last_name" required value="<?= e($old['last_name']) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
```
Replace:
```php
          <input type="text" name="last_name" required value="<?= e($old['last_name']) ?>"
                 class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
```

Find:
```php
          <input type="text" name="first_name" required value="<?= e($old['first_name']) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
```
Replace:
```php
          <input type="text" name="first_name" required value="<?= e($old['first_name']) ?>"
                 class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
```

Find:
```php
          <input type="text" name="middle_name" value="<?= e($old['middle_name']) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
```
Replace:
```php
          <input type="text" name="middle_name" value="<?= e($old['middle_name']) ?>"
                 class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none">
```

Find:
```php
          <textarea name="address" required rows="2"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none"><?= e($old['address']) ?></textarea>
```
Replace:
```php
          <textarea name="address" required rows="2"
                    class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-100 focus:border-brand-500 outline-none"><?= e($old['address']) ?></textarea>
```

- [ ] **Step 2: `public/applicant-edit.php`**

Find:
```php
$classOptions = ['Job Order', 'Temporary', 'COS', 'Permanent', 'Casual', 'Other'];
```
Replace:
```php
$classOptions = ['Job Order', 'Temporary', 'COS', 'Permanent', 'Casual', 'Other', 'Hired'];
```
(This lets Administrator/Employee edit a record that was created via the new "Mark as Hired" action — Task 4 — without the form's classification dropdown rejecting the `'Hired'` value.)

Find:
```php
    foreach (['last_name','first_name','middle_name','extension_name','sex','date_of_birth','place_of_birth','contact_number','email_address','address','civil_status'] as $key) {
        $old[$key] = clean($_POST[$key] ?? '');
    }
```
Replace:
```php
    foreach (['last_name','first_name','middle_name','extension_name','sex','date_of_birth','place_of_birth','contact_number','email_address','address','civil_status'] as $key) {
        $old[$key] = clean($_POST[$key] ?? '');
    }

    // Normalize to uppercase server-side too — defense in depth. Email is
    // deliberately excluded (kept as typed).
    foreach (['last_name', 'first_name', 'middle_name', 'place_of_birth', 'address'] as $upperKey) {
        $old[$upperKey] = mb_strtoupper($old[$upperKey], 'UTF-8');
    }
```

Find:
```php
    $old['employment_status_flag'] = clean($_POST['employment_status_flag'] ?? 'Not Yet');
    $old['agency_id'] = clean($_POST['agency_id'] ?? '');
    $old['agency_free_text'] = clean($_POST['agency_free_text'] ?? '');
    $old['date_hired'] = clean($_POST['date_hired'] ?? '');
    $old['employment_classification'] = clean($_POST['employment_classification'] ?? '');
    $old['remarks'] = clean($_POST['remarks'] ?? '');
```
Replace:
```php
    $old['employment_status_flag'] = clean($_POST['employment_status_flag'] ?? 'Not Yet');
    $old['agency_id'] = clean($_POST['agency_id'] ?? '');
    $old['agency_free_text'] = mb_strtoupper(clean($_POST['agency_free_text'] ?? ''), 'UTF-8');
    $old['date_hired'] = clean($_POST['date_hired'] ?? '');
    $old['employment_classification'] = clean($_POST['employment_classification'] ?? '');
    $old['remarks'] = clean($_POST['remarks'] ?? '');
```

Find (last_name field):
```php
          <input type="text" name="last_name" required value="<?= e($old['last_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="last_name" required value="<?= e($old['last_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find (first_name field):
```php
          <input type="text" name="first_name" required value="<?= e($old['first_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="first_name" required value="<?= e($old['first_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find (middle_name field):
```php
          <input type="text" name="middle_name" value="<?= e($old['middle_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="middle_name" value="<?= e($old['middle_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find (place_of_birth field):
```php
          <input type="text" name="place_of_birth" value="<?= e($old['place_of_birth'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="place_of_birth" value="<?= e($old['place_of_birth'] ?? '') ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find (address field):
```php
          <textarea name="address" required rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
```
Replace:
```php
          <textarea name="address" required rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
```

Find (agency_free_text field):
```php
          <input type="text" name="agency_free_text" value="<?= e($old['agency_free_text']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="agency_free_text" value="<?= e($old['agency_free_text']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

- [ ] **Step 3: `public/employment-form.php`**

Find:
```php
$statusOptions = ['Job Order', 'Temporary', 'COS', 'Permanent', 'Casual', 'Other'];
```
Replace:
```php
$statusOptions = ['Job Order', 'Temporary', 'COS', 'Permanent', 'Casual', 'Other', 'Hired'];
```

Find:
```php
    $agencyFreeText = clean($_POST['agency_free_text'] ?? '');
```
Replace:
```php
    $agencyFreeText = mb_strtoupper(clean($_POST['agency_free_text'] ?? ''), 'UTF-8');
```

Find:
```php
          <input type="text" name="agency_free_text" value="<?= $record['agency_id'] ? '' : e($record['agency_company_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="agency_free_text" value="<?= $record['agency_id'] ? '' : e($record['agency_company_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

- [ ] **Step 4: `public/partner-agency-form.php`**

Find:
```php
    $old['agency_name']    = clean($_POST['agency_name'] ?? '');
    $old['address']        = clean($_POST['address'] ?? '');
    $old['contact_person'] = clean($_POST['contact_person'] ?? '');
    $old['contact_no']     = clean($_POST['contact_no'] ?? '');
    $old['email']          = clean($_POST['email'] ?? '');
```
Replace:
```php
    $old['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
    $old['address']        = mb_strtoupper(clean($_POST['address'] ?? ''), 'UTF-8');
    $old['contact_person'] = mb_strtoupper(clean($_POST['contact_person'] ?? ''), 'UTF-8');
    $old['contact_no']     = clean($_POST['contact_no'] ?? '');
    $old['email']          = clean($_POST['email'] ?? '');
```

Find:
```php
        <input type="text" name="agency_name" required value="<?= e($old['agency_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
        <input type="text" name="agency_name" required value="<?= e($old['agency_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find:
```php
        <textarea name="address" required rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
```
Replace:
```php
        <textarea name="address" required rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
```

Find:
```php
          <input type="text" name="contact_person" value="<?= e($old['contact_person']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="contact_person" value="<?= e($old['contact_person']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

- [ ] **Step 5: `public/my-agency.php`**

Find:
```php
    $old['agency_name']    = clean($_POST['agency_name'] ?? '');
    $old['address']        = clean($_POST['address'] ?? '');
    $old['contact_person'] = clean($_POST['contact_person'] ?? '');
    $old['contact_no']     = clean($_POST['contact_no'] ?? '');
    $old['email']          = clean($_POST['email'] ?? '');
```
Replace:
```php
    $old['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
    $old['address']        = mb_strtoupper(clean($_POST['address'] ?? ''), 'UTF-8');
    $old['contact_person'] = mb_strtoupper(clean($_POST['contact_person'] ?? ''), 'UTF-8');
    $old['contact_no']     = clean($_POST['contact_no'] ?? '');
    $old['email']          = clean($_POST['email'] ?? '');
```

Find:
```php
        <input type="text" name="agency_name" required value="<?= e($old['agency_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
        <input type="text" name="agency_name" required value="<?= e($old['agency_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find:
```php
        <textarea name="address" required rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
```
Replace:
```php
        <textarea name="address" required rows="2" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
```

Find:
```php
          <input type="text" name="contact_person" required value="<?= e($old['contact_person']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="contact_person" required value="<?= e($old['contact_person']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

- [ ] **Step 6: `public/login.php`**

Find:
```php
    $regOld['agency_name']    = clean($_POST['agency_name'] ?? '');
    $regOld['address']        = clean($_POST['address'] ?? '');
    $regOld['contact_person'] = clean($_POST['contact_person'] ?? '');
    $regOld['contact_no']     = clean($_POST['contact_no'] ?? '');
    $regOld['email']          = clean($_POST['email'] ?? '');
    $regOld['username']       = clean($_POST['username'] ?? '');
```
Replace:
```php
    $regOld['agency_name']    = mb_strtoupper(clean($_POST['agency_name'] ?? ''), 'UTF-8');
    $regOld['address']        = mb_strtoupper(clean($_POST['address'] ?? ''), 'UTF-8');
    $regOld['contact_person'] = mb_strtoupper(clean($_POST['contact_person'] ?? ''), 'UTF-8');
    $regOld['contact_no']     = clean($_POST['contact_no'] ?? '');
    $regOld['email']          = clean($_POST['email'] ?? '');
    $regOld['username']       = clean($_POST['username'] ?? '');
```
(`full_name` for the created user is set from `$regOld['contact_person']` later in this same handler — since `contact_person` is now uppercased here, the resulting `users.full_name` is automatically uppercase too, with no further change needed there.)

Find:
```php
                <input type="text" name="agency_name" required value="<?= e($regOld['agency_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
                <input type="text" name="agency_name" required value="<?= e($regOld['agency_name']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find:
```php
                <input type="text" name="address" required value="<?= e($regOld['address']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
                <input type="text" name="address" required value="<?= e($regOld['address']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find:
```php
                  <input type="text" name="contact_person" required value="<?= e($regOld['contact_person']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
                  <input type="text" name="contact_person" required value="<?= e($regOld['contact_person']) ?>" class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

- [ ] **Step 7: `public/users.php`**

Find:
```php
    if ($action === 'create') {
        // Field order per spec: Full Name, Username, Temporary Password, Role
        $fullName = clean($_POST['full_name'] ?? '');
        $username = clean($_POST['username'] ?? '');
```
Replace:
```php
    if ($action === 'create') {
        // Field order per spec: Full Name, Username, Temporary Password, Role
        $fullName = mb_strtoupper(clean($_POST['full_name'] ?? ''), 'UTF-8');
        $username = clean($_POST['username'] ?? '');
```

Find:
```php
    } elseif ($action === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = clean($_POST['full_name'] ?? '');
        $role = clean($_POST['role'] ?? '');
```
Replace:
```php
    } elseif ($action === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = mb_strtoupper(clean($_POST['full_name'] ?? ''), 'UTF-8');
        $role = clean($_POST['role'] ?? '');
```

Find (create form's full name input):
```php
          <input type="text" name="full_name" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```
Replace:
```php
          <input type="text" name="full_name" required class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
```

Find (inline edit row's full name input):
```php
                <input type="text" name="full_name" required value="<?= e($u['full_name']) ?>" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
```
Replace:
```php
                <input type="text" name="full_name" required value="<?= e($u['full_name']) ?>" class="uppercase-field uppercase rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
```

- [ ] **Step 8: Syntax check all 7 files**

```bash
/c/xampp/php/php.exe -l public/applicant-create.php
/c/xampp/php/php.exe -l public/applicant-edit.php
/c/xampp/php/php.exe -l public/employment-form.php
/c/xampp/php/php.exe -l public/partner-agency-form.php
/c/xampp/php/php.exe -l public/my-agency.php
/c/xampp/php/php.exe -l public/login.php
/c/xampp/php/php.exe -l public/users.php
```
Expected: "No syntax errors detected" for all 7.

- [ ] **Step 9: Verify uppercase round-trip via curl for a representative sample (applicant-create.php and login.php registration — the two most-used entry points)**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/uh_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)

# Log in as admin
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

# Submit applicant-create.php with lowercase/mixed-case input
CSRF2=$(curl -s -b "$COOKIE" -c "$COOKIE" http://localhost:8899/applicant-create.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "csrf_token=$CSRF2" --data-urlencode "last_name=dela cruz" --data-urlencode "first_name=juan" \
  --data-urlencode "middle_name=santos" --data-urlencode "extension_name=NONE" --data-urlencode "sex=MALE" \
  --data-urlencode "date_of_birth=1990-01-01" --data-urlencode "contact_number=09171234567" \
  --data-urlencode "address=123 manila st" --data-urlencode "civil_status=SINGLE" \
  http://localhost:8899/applicant-create.php -o /dev/null

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e \
  "SELECT last_name, first_name, middle_name, address FROM applicants WHERE last_name='DELA CRUZ' AND first_name='JUAN';"
# Expected: DELA CRUZ | JUAN | SANTOS | 123 MANILA ST

# Register a Partner Agency with lowercase agency/contact fields + a mixed-case email/username/password
CSRF3=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=register_agency" --data-urlencode "csrf_token=$CSRF3" \
  --data-urlencode "agency_name=lowercase agency" --data-urlencode "address=456 quezon ave" \
  --data-urlencode "contact_person=jane doe" --data-urlencode "contact_no=09170000001" \
  --data-urlencode "email=MixedCase@Example.com" --data-urlencode "username=MixedUser123" \
  --data-urlencode "password=MixedPass123" --data-urlencode "confirm_password=MixedPass123" \
  http://localhost:8899/login.php -o /dev/null

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e \
  "SELECT agency_name, address, contact_person, email FROM partner_agencies WHERE agency_name='LOWERCASE AGENCY';"
# Expected: LOWERCASE AGENCY | 456 QUEZON AVE | JANE DOE | MixedCase@Example.com (email untouched)

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e \
  "SELECT username, full_name FROM users WHERE username='MixedUser123';"
# Expected: MixedUser123 (untouched) | JANE DOE (from contact_person)

# Confirm the stored password hash still verifies against the exact original password (case-sensitive, untouched)
HASH=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT password FROM users WHERE username='MixedUser123';")
/c/xampp/php/php.exe -r "var_dump(password_verify('MixedPass123', '$HASH'));"
# Expected: bool(true)

kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM applicants WHERE last_name='DELA CRUZ' AND first_name='JUAN';
DELETE FROM users WHERE username='MixedUser123';
DELETE FROM partner_agencies WHERE agency_name='LOWERCASE AGENCY';
"
```
Expected: all `SELECT`s match the expected values above; `bool(true)` for the password check; email/username/password all preserved exactly as typed.

- [ ] **Step 10: Commit**

```bash
git add public/applicant-create.php public/applicant-edit.php public/employment-form.php public/partner-agency-form.php public/my-agency.php public/login.php public/users.php
git commit -m "feat: uppercase normalization on all remaining applicant/agency/user data-entry forms"
```

---

## Task 3: Visual uppercase for derived labels + Hired display wiring (batch — applicants.php, reports.php, dashboard.php)

**Files:**
- Modify: `public/applicants.php`, `public/reports.php`, `public/dashboard.php`

**Interfaces:**
- Consumes: Task 1's `'Hired'` enum value.
- Produces: nothing consumed by later tasks by name.

- [ ] **Step 1: `public/applicants.php` — badge visual uppercase, `Hired` filter option, `Hired` color**

Find:
```php
              <td class="px-4 py-3" data-label="Services">
                <template x-for="svc in row.services_availed" :key="svc">
                  <span class="inline-block px-2 py-0.5 mr-1 mb-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700" x-text="svc"></span>
                </template>
```
Replace:
```php
              <td class="px-4 py-3" data-label="Services">
                <template x-for="svc in row.services_availed" :key="svc">
                  <span class="inline-block px-2 py-0.5 mr-1 mb-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 uppercase" x-text="svc"></span>
                </template>
```

Find:
```php
              <td class="px-4 py-3" data-label="Employment">
                <span class="px-2 py-1 rounded-full text-xs font-medium"
                      :class="statusColor(row.employment_status)" x-text="row.employment_status"></span>
              </td>
```
Replace:
```php
              <td class="px-4 py-3" data-label="Employment">
                <span class="px-2 py-1 rounded-full text-xs font-medium uppercase"
                      :class="statusColor(row.employment_status)" x-text="row.employment_status"></span>
              </td>
```

Find:
```php
      <select x-model="filters.employment_status" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
        <option value="All">All Employment Status</option>
        <option>For Further Review</option><option>Job Order</option>
        <option>Temporary</option><option>COS</option><option>Permanent</option><option>Casual</option>
      </select>
```
Replace:
```php
      <select x-model="filters.employment_status" @change="load(1)" class="rounded-lg border border-slate-300 text-sm py-2 px-3">
        <option value="All">All Employment Status</option>
        <option>For Further Review</option><option>Job Order</option>
        <option>Temporary</option><option>COS</option><option>Permanent</option><option>Casual</option><option>Hired</option>
      </select>
```

Find:
```js
    statusColor(status) {
      const map = {
        'For Further Review': 'bg-gray-100 text-gray-700',
        'Job Order': 'bg-yellow-100 text-yellow-800',
        'COS': 'bg-blue-100 text-blue-800',
        'Temporary': 'bg-purple-100 text-purple-800',
        'Permanent': 'bg-green-100 text-green-800',
        'Casual': 'bg-orange-100 text-orange-800',
      };
      return map[status] || 'bg-gray-100 text-gray-700';
    },
```
Replace:
```js
    statusColor(status) {
      const map = {
        'For Further Review': 'bg-gray-100 text-gray-700',
        'Job Order': 'bg-yellow-100 text-yellow-800',
        'COS': 'bg-blue-100 text-blue-800',
        'Temporary': 'bg-purple-100 text-purple-800',
        'Permanent': 'bg-green-100 text-green-800',
        'Casual': 'bg-orange-100 text-orange-800',
        'Hired': 'bg-emerald-100 text-emerald-800',
      };
      return map[status] || 'bg-gray-100 text-gray-700';
    },
```

- [ ] **Step 2: `public/reports.php` — visual uppercase on Services/Employment Status columns**

Find:
```php
            <td class="px-4 py-2.5">
              <?php
                $svc = [];
                if ($r['service_job_seeker']) $svc[] = 'Job Seeker';
                if ($r['service_agency_services']) $svc[] = 'Agency Services';
              ?>
              <?= $svc ? e(implode(', ', $svc)) : '<span class="text-slate-300">—</span>' ?>
            </td>
            <td class="px-4 py-2.5"><?= e($r['agency_company_name'] ?: '—') ?></td>
            <td class="px-4 py-2.5"><?= format_date($r['date_hired'] ?? null) ?></td>
            <td class="px-4 py-2.5"><?= e($r['employment_status'] ?: 'For Further Review') ?></td>
```
Replace:
```php
            <td class="px-4 py-2.5 uppercase">
              <?php
                $svc = [];
                if ($r['service_job_seeker']) $svc[] = 'Job Seeker';
                if ($r['service_agency_services']) $svc[] = 'Agency Services';
              ?>
              <?= $svc ? e(implode(', ', $svc)) : '<span class="text-slate-300">—</span>' ?>
            </td>
            <td class="px-4 py-2.5"><?= e($r['agency_company_name'] ?: '—') ?></td>
            <td class="px-4 py-2.5"><?= format_date($r['date_hired'] ?? null) ?></td>
            <td class="px-4 py-2.5 uppercase"><?= e($r['employment_status'] ?: 'For Further Review') ?></td>
```

- [ ] **Step 3: `public/dashboard.php` — Hired bucket + chart label uppercase**

Find:
```php
$statusCounts = [
    'For Further Review' => 0,
    'Job Order'     => 0,
    'Temporary'     => 0,
    'COS'           => 0,
    'Permanent'     => 0,
    'Casual'        => 0,
    'Other'         => 0,
];
```
Replace:
```php
$statusCounts = [
    'For Further Review' => 0,
    'Job Order'     => 0,
    'Temporary'     => 0,
    'COS'           => 0,
    'Permanent'     => 0,
    'Casual'        => 0,
    'Other'         => 0,
    'Hired'         => 0,
];
```

Find:
```php
new Chart(document.getElementById('chartApplicantStatus'), {
  type: 'doughnut',
  data: {
    labels: ['Hired', 'Not Hired'],
```
Replace:
```php
new Chart(document.getElementById('chartApplicantStatus'), {
  type: 'doughnut',
  data: {
    labels: ['HIRED', 'NOT HIRED'],
```

Find:
```php
    labels: <?= json_encode(array_keys($statusCounts)) ?>,
    datasets: [{ label: 'Applicants', data: <?= json_encode(array_values($statusCounts)) ?>, backgroundColor: palette }]
```
Replace:
```php
    labels: <?= json_encode(array_map('strtoupper', array_keys($statusCounts))) ?>,
    datasets: [{ label: 'Applicants', data: <?= json_encode(array_values($statusCounts)) ?>, backgroundColor: palette }]
```

- [ ] **Step 4: Syntax check**

```bash
/c/xampp/php/php.exe -l public/applicants.php
/c/xampp/php/php.exe -l public/reports.php
/c/xampp/php/php.exe -l public/dashboard.php
```
Expected: "No syntax errors detected" for all three.

- [ ] **Step 5: Verify via curl**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/uh_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

APPLICANTS=$(curl -s -b "$COOKIE" -c "$COOKIE" http://localhost:8899/applicants.php)
echo "$APPLICANTS" | grep -q "uppercase\" x-text=\"row.employment_status\"" && echo "PASS - employment status badge has uppercase class"
echo "$APPLICANTS" | grep -q "<option>Hired</option>" && echo "PASS - Hired filter option present"
echo "$APPLICANTS" | grep -q "'Hired': 'bg-emerald-100 text-emerald-800'" && echo "PASS - Hired color entry present"

DASH=$(curl -s -b "$COOKIE" -c "$COOKIE" http://localhost:8899/dashboard.php)
echo "$DASH" | grep -q "HIRED" && echo "PASS - dashboard doughnut chart label uppercased"

kill %1
```
Expected: four `PASS` lines.

- [ ] **Step 6: Commit**

```bash
git add public/applicants.php public/reports.php public/dashboard.php
git commit -m "feat: visual uppercase for derived status/service labels; wire Hired classification into dashboard/applicants display"
```

---

## Task 4: "Mark as Hired" — `applicant-view.php` POST action, UI, and agency contact-info display

**Files:**
- Modify: `public/applicant-view.php`

**Interfaces:**
- Consumes: Task 1's `'Hired'` enum value; `can_manage_employment()`, `is_partner_agency()`, `current_agency_id($pdo)` (all pre-existing, from `includes/auth.php`); `active_agencies($pdo)` (pre-existing, from `includes/functions.php`).
- Produces: nothing consumed by later tasks by name — Task 5 verifies this feature end-to-end.

- [ ] **Step 1: Include Partner Agency contact info in the employment query**

Find:
```php
$empStmt = $pdo->prepare(
    "SELECT er.*, COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name
     FROM employment_records er
     LEFT JOIN partner_agencies pa ON pa.id = er.agency_id
     WHERE er.applicant_id = :id ORDER BY er.date_hired DESC, er.id DESC"
);
```
Replace:
```php
$empStmt = $pdo->prepare(
    "SELECT er.*, COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name,
            pa.contact_person AS agency_contact_person, pa.contact_no AS agency_contact_no, pa.email AS agency_email
     FROM employment_records er
     LEFT JOIN partner_agencies pa ON pa.id = er.agency_id
     WHERE er.applicant_id = :id ORDER BY er.date_hired DESC, er.id DESC"
);
```

- [ ] **Step 2: Add the `mark_hired` POST action**

Find:
```php
    } elseif ($action === 'delete_employment') {
        require_role(['Administrator']);
        $recordId = (int)($_POST['record_id'] ?? 0);
        $pdo->prepare("DELETE FROM employment_records WHERE id = :id AND applicant_id = :aid")
            ->execute([':id' => $recordId, ':aid' => $id]);
        audit_log($pdo, (int)current_user()['id'], 'DELETE', 'employment_records', $recordId, 'Employment record deleted');
        flash_set('success', 'Employment record deleted.');
        redirect('applicant-view.php?id=' . $id);
    }
}
```
Replace:
```php
    } elseif ($action === 'delete_employment') {
        require_role(['Administrator']);
        $recordId = (int)($_POST['record_id'] ?? 0);
        $pdo->prepare("DELETE FROM employment_records WHERE id = :id AND applicant_id = :aid")
            ->execute([':id' => $recordId, ':aid' => $id]);
        audit_log($pdo, (int)current_user()['id'], 'DELETE', 'employment_records', $recordId, 'Employment record deleted');
        flash_set('success', 'Employment record deleted.');
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'mark_hired') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        // Agency identity is always server-derived for a Partner Agency —
        // never trusted from the request. Administrator/Employee explicitly
        // choose which agency to credit with the hire.
        if (is_partner_agency()) {
            $hireAgencyId = current_agency_id($pdo);
        } else {
            $hireAgencyId = !empty($_POST['agency_id']) ? (int)$_POST['agency_id'] : null;
        }

        if (!$hireAgencyId) {
            flash_set('error', 'Select a Partner Agency to hire this applicant into.');
            redirect('applicant-view.php?id=' . $id);
        }

        $agStmt = $pdo->prepare("SELECT agency_name, address FROM partner_agencies WHERE id = :id");
        $agStmt->execute([':id' => $hireAgencyId]);
        $hireAgencyRow = $agStmt->fetch();
        if (!$hireAgencyRow) {
            flash_set('error', 'Selected Partner Agency was not found.');
            redirect('applicant-view.php?id=' . $id);
        }

        // Guard against a double-hire: only proceed if the applicant genuinely
        // still has no current active employment record right now.
        $hireCheckStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'"
        );
        $hireCheckStmt->execute([':id' => $id]);
        if ((int)$hireCheckStmt->fetchColumn() > 0) {
            flash_set('error', 'This applicant has already been hired.');
            redirect('applicant-view.php?id=' . $id);
        }

        $hireStmt = $pdo->prepare(
            "INSERT INTO employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
             VALUES (:aid, :agid, :agency, :address, CURDATE(), 'Hired', 1, 'Active')"
        );
        $hireStmt->execute([
            ':aid' => $id, ':agid' => $hireAgencyId,
            ':agency' => $hireAgencyRow['agency_name'], ':address' => $hireAgencyRow['address'],
        ]);
        $newHireId = (int)$pdo->lastInsertId();
        audit_log($pdo, (int)current_user()['id'], 'MARK_HIRED', 'employment_records', $newHireId,
            "Applicant {$applicant['applicant_code']} marked Hired by {$hireAgencyRow['agency_name']}");
        flash_set('success', 'Applicant marked as Hired.');
        redirect('applicant-view.php?id=' . $id);
    }
}
```

- [ ] **Step 3: Fetch agency data needed by the confirmation UI**

Find:
```php
$status = current_employment_status($pdo, $id);

$pageTitle = 'Applicant Profile';
```
Replace:
```php
$status = current_employment_status($pdo, $id);

$hireAgencies = can_manage_employment() ? active_agencies($pdo) : [];
$myAgencyName = '';
if (is_partner_agency()) {
    $myAgencyStmt = $pdo->prepare("SELECT agency_name FROM partner_agencies WHERE id = :id");
    $myAgencyStmt->execute([':id' => current_agency_id($pdo)]);
    $myAgencyName = (string)$myAgencyStmt->fetchColumn();
}

$pageTitle = 'Applicant Profile';
```

- [ ] **Step 4: Visual uppercase on the Employment History table's Classification column**

Find:
```php
                <td class="py-2.5 pr-3"><?= format_date($rec['date_hired']) ?></td>
                <td class="py-2.5 pr-3"><?= e($rec['employment_status']) ?></td>
```
Replace:
```php
                <td class="py-2.5 pr-3"><?= format_date($rec['date_hired']) ?></td>
                <td class="py-2.5 pr-3 uppercase"><?= e($rec['employment_status']) ?></td>
```

- [ ] **Step 5: Visual uppercase on the Services Availed badges**

Find:
```php
              <?php if ($svc): ?>
                <?php foreach ($svc as $s): ?>
                  <span class="inline-block px-2 py-0.5 mr-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700"><?= e($s) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
```
Replace:
```php
              <?php if ($svc): ?>
                <?php foreach ($svc as $s): ?>
                  <span class="inline-block px-2 py-0.5 mr-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 uppercase"><?= e($s) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
```

- [ ] **Step 6: Employment Status card — agency contact info + "Mark as Hired" button/modal**

Find:
```php
    <div class="space-y-6">
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-3">Employment Status</h2>
        <span class="inline-block px-3 py-1.5 rounded-full text-sm font-semibold <?= $status['color'] ?>"><?= e(strtoupper($status['label'])) ?></span>
        <?php
          $current = null;
          foreach ($employmentRecords as $rec) { if ($rec['is_current'] && $rec['status'] === 'Active') { $current = $rec; break; } }
        ?>
        <?php if ($current): ?>
          <dl class="mt-4 space-y-2 text-sm">
            <div><dt class="text-slate-500">Agency/Company</dt><dd class="font-medium text-slate-800"><?= e($current['agency_display_name']) ?></dd></div>
            <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-800"><?= e($current['agency_company_address']) ?></dd></div>
            <div><dt class="text-slate-500">Date Hired</dt><dd class="font-medium text-slate-800"><?= format_date($current['date_hired']) ?></dd></div>
            <?php if (!empty($current['remarks'])): ?>
            <div><dt class="text-slate-500">Remarks</dt><dd class="font-medium text-slate-800"><?= e($current['remarks']) ?></dd></div>
            <?php endif; ?>
          </dl>
        <?php endif; ?>
      </div>
    </div>
```
Replace:
```php
    <div class="space-y-6">
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6" x-data="{ showHireConfirm: false, hireAgencyId: '' }">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide mb-3">Employment Status</h2>
        <span class="inline-block px-3 py-1.5 rounded-full text-sm font-semibold <?= $status['color'] ?>"><?= e(strtoupper($status['label'])) ?></span>
        <?php
          $current = null;
          foreach ($employmentRecords as $rec) { if ($rec['is_current'] && $rec['status'] === 'Active') { $current = $rec; break; } }
        ?>
        <?php if ($current): ?>
          <dl class="mt-4 space-y-2 text-sm">
            <div><dt class="text-slate-500">Agency/Company</dt><dd class="font-medium text-slate-800"><?= e($current['agency_display_name']) ?></dd></div>
            <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-800"><?= e($current['agency_company_address']) ?></dd></div>
            <?php if (!empty($current['agency_id'])): ?>
              <?php if (!empty($current['agency_contact_person'])): ?>
              <div><dt class="text-slate-500">Agency Contact Person</dt><dd class="font-medium text-slate-800"><?= e($current['agency_contact_person']) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($current['agency_contact_no'])): ?>
              <div><dt class="text-slate-500">Agency Contact No</dt><dd class="font-medium text-slate-800"><?= e($current['agency_contact_no']) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($current['agency_email'])): ?>
              <div><dt class="text-slate-500">Agency Email</dt><dd class="font-medium text-slate-800"><?= e($current['agency_email']) ?></dd></div>
              <?php endif; ?>
            <?php endif; ?>
            <div><dt class="text-slate-500">Date Hired</dt><dd class="font-medium text-slate-800"><?= format_date($current['date_hired']) ?></dd></div>
            <?php if (!empty($current['remarks'])): ?>
            <div><dt class="text-slate-500">Remarks</dt><dd class="font-medium text-slate-800"><?= e($current['remarks']) ?></dd></div>
            <?php endif; ?>
          </dl>
        <?php elseif (can_manage_employment() || is_partner_agency()): ?>
          <div class="mt-4 print:hidden">
            <button type="button" @click="showHireConfirm = true" class="w-full px-4 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold shadow-sm">
              <i class="fa-solid fa-handshake mr-1"></i> Mark as Hired
            </button>
          </div>

          <!-- Confirmation modal -->
          <div x-show="showHireConfirm" x-cloak class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6" @click.outside="showHireConfirm = false">
              <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-circle-question text-emerald-600 mr-1"></i> Confirm Hire</h3>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_hired">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php if (can_manage_employment()): ?>
                  <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency <span class="text-red-500">*</span></label>
                  <select name="agency_id" x-model="hireAgencyId" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4">
                    <option value="">Select a Partner Agency</option>
                    <?php foreach ($hireAgencies as $ag): ?>
                      <option value="<?= (int)$ag['id'] ?>"><?= e($ag['agency_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <p class="text-sm text-slate-600 mb-5">Mark <?= e(full_name($applicant)) ?> as hired by <strong><?= e($myAgencyName) ?></strong>?</p>
                <?php endif; ?>
                <div class="flex justify-end gap-2">
                  <button type="button" @click="showHireConfirm = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
                  <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium">Confirm &amp; Mark as Hired</button>
                </div>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
```

- [ ] **Step 7: Syntax check**

Run: `/c/xampp/php/php.exe -l public/applicant-view.php`
Expected: "No syntax errors detected"

- [ ] **Step 8: Verify via curl — the full hiring flow, both roles, plus the security-critical checks**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/uh_server.log 2>&1 &
sleep 1

# Seed two agencies (one to hire through as Partner Agency, one as an
# "other" agency an attacker might try to spoof), two applicants (one for
# each hiring path), and an Active Partner Agency user for Agency A.
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username='hiretest_pa';
DELETE FROM partner_agencies WHERE agency_name IN ('Hire Test Agency A','Hire Test Agency B (Other)');
DELETE FROM applicants WHERE applicant_code IN ('HIRE-TEST-001','HIRE-TEST-002');
INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email, status) VALUES
('Hire Test Agency A','Addr A','Alice CP','0900000001','alice@example.com','Active'),
('Hire Test Agency B (Other)','Addr B','Bob CP','0900000002','bob@example.com','Active');
INSERT INTO applicants (applicant_code, last_name, first_name, sex, date_of_birth, contact_number, address, civil_status) VALUES
('HIRE-TEST-001','TESTONE','APPLICANT','MALE','1990-01-01','09171111111','ADDR','SINGLE'),
('HIRE-TEST-002','TESTTWO','APPLICANT','FEMALE','1990-01-01','09172222222','ADDR','SINGLE');
"
AGENCY_A_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Hire Test Agency A';")
AGENCY_B_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Hire Test Agency B (Other)';")
APPLICANT_1_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM applicants WHERE applicant_code='HIRE-TEST-001';")
APPLICANT_2_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM applicants WHERE applicant_code='HIRE-TEST-002';")
HASH=$(/c/xampp/php/php.exe -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
INSERT INTO users (username, password, full_name, role, status, is_active, agency_id) VALUES ('hiretest_pa','$HASH','ALICE CP','Partner Agency','Active',1,$AGENCY_A_ID);
"

# --- Partner Agency hires Applicant 1, attempting to spoof Agency B's id ---
PACOOKIE=$(mktemp)
CSRF=$(curl -s -c "$PACOOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$PACOOKIE" -c "$PACOOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=hiretest_pa" --data-urlencode "password=TestPass123" http://localhost:8899/login.php -o /dev/null

PROFILE1=$(curl -s -b "$PACOOKIE" -c "$PACOOKIE" "http://localhost:8899/applicant-view.php?id=$APPLICANT_1_ID")
echo "$PROFILE1" | grep -q "Mark as Hired" && echo "PASS - Mark as Hired button shown to Partner Agency"
echo "$PROFILE1" | grep -qv 'name="agency_id"' && echo "PASS - no agency picker shown to Partner Agency"

CSRF2=$(echo "$PROFILE1" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$PACOOKIE" -c "$PACOOKIE" --data-urlencode "action=mark_hired" --data-urlencode "csrf_token=$CSRF2" \
  --data-urlencode "id=$APPLICANT_1_ID" --data-urlencode "agency_id=$AGENCY_B_ID" \
  http://localhost:8899/applicant-view.php -o /dev/null
# Note: agency_id=$AGENCY_B_ID above is a deliberate spoof attempt — the
# server must ignore it and use the Partner Agency's own agency (A).

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e \
  "SELECT agency_id, employment_status, is_current, status FROM employment_records WHERE applicant_id=$APPLICANT_1_ID;"
# Expected: agency_id = $AGENCY_A_ID (NOT B), employment_status=Hired, is_current=1, status=Active

PROFILE1_AFTER=$(curl -s -b "$PACOOKIE" -c "$PACOOKIE" "http://localhost:8899/applicant-view.php?id=$APPLICANT_1_ID")
echo "$PROFILE1_AFTER" | grep -q "Alice CP" && echo "PASS - agency contact person shown on profile after hire"
echo "$PROFILE1_AFTER" | grep -q "alice@example.com" && echo "PASS - agency email shown on profile after hire"

# --- Double-hire guard: try hiring Applicant 1 again (already hired) ---
CSRF3=$(curl -s -b "$PACOOKIE" -c "$PACOOKIE" "http://localhost:8899/applicant-view.php?id=$APPLICANT_1_ID" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$PACOOKIE" -c "$PACOOKIE" --data-urlencode "action=mark_hired" --data-urlencode "csrf_token=$CSRF3" \
  --data-urlencode "id=$APPLICANT_1_ID" http://localhost:8899/applicant-view.php -o /dev/null
COUNT_AFTER_DUP=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT COUNT(*) FROM employment_records WHERE applicant_id=$APPLICANT_1_ID;")
[ "$COUNT_AFTER_DUP" = "1" ] && echo "PASS - double-hire attempt rejected, still only 1 employment record"

# --- Admin hires Applicant 2 with an explicit agency picker ---
ACOOKIE=$(mktemp)
CSRF4=$(curl -s -c "$ACOOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$ACOOKIE" -c "$ACOOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF4" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

PROFILE2=$(curl -s -b "$ACOOKIE" -c "$ACOOKIE" "http://localhost:8899/applicant-view.php?id=$APPLICANT_2_ID")
echo "$PROFILE2" | grep -q 'name="agency_id"' && echo "PASS - agency picker shown to Administrator"

CSRF5=$(echo "$PROFILE2" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$ACOOKIE" -c "$ACOOKIE" --data-urlencode "action=mark_hired" --data-urlencode "csrf_token=$CSRF5" \
  --data-urlencode "id=$APPLICANT_2_ID" --data-urlencode "agency_id=$AGENCY_B_ID" \
  http://localhost:8899/applicant-view.php -o /dev/null
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e \
  "SELECT agency_id, employment_status FROM employment_records WHERE applicant_id=$APPLICANT_2_ID;"
# Expected: agency_id = $AGENCY_B_ID, employment_status = Hired

# --- Unauthorized direct POST (no session at all) ---
STATUS=$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "action=mark_hired" --data-urlencode "id=$APPLICANT_2_ID" http://localhost:8899/applicant-view.php)
echo "Unauthenticated POST status: $STATUS"
# Expected: redirected to login (302) — require_login() at the top of the
# file already handles this before the new action branch is ever reached.

kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username='hiretest_pa';
DELETE FROM partner_agencies WHERE agency_name IN ('Hire Test Agency A','Hire Test Agency B (Other)');
DELETE FROM applicants WHERE applicant_code IN ('HIRE-TEST-001','HIRE-TEST-002');
"
```
Expected: seven `PASS` lines; the agency_id spoof attempt is confirmed ignored (row shows Agency A, not B); the double-hire attempt is confirmed rejected; Administrator's explicit agency choice (B) is honored; unauthenticated POST is redirected, not processed.

- [ ] **Step 9: Commit**

```bash
git add public/applicant-view.php
git commit -m "feat: Mark as Hired action for Partner Agency/Admin/Employee, with agency contact info display"
```

---

## Task 5: End-to-end verification

**Files:** none modified — verification-only task confirming Tasks 1-4 compose correctly.

**Interfaces:** none.

- [ ] **Step 1: Confirm a Viewer cannot mark an applicant as hired**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/uh_server.log 2>&1 &
sleep 1

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username='e2e_viewer';
DELETE FROM applicants WHERE applicant_code='HIRE-E2E-001';
INSERT INTO applicants (applicant_code, last_name, first_name, sex, date_of_birth, contact_number, address, civil_status)
VALUES ('HIRE-E2E-001','VIEWERTEST','APPLICANT','MALE','1990-01-01','09173333333','ADDR','SINGLE');
"
HASH=$(/c/xampp/php/php.exe -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
INSERT INTO users (username, password, full_name, role, status, is_active) VALUES ('e2e_viewer','$HASH','E2E VIEWER','Viewer','Active',1);
"
APPLICANT_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM applicants WHERE applicant_code='HIRE-E2E-001';")

COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=e2e_viewer" --data-urlencode "password=TestPass123" http://localhost:8899/login.php -o /dev/null

PROFILE=$(curl -s -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?id=$APPLICANT_ID")
echo "$PROFILE" | grep -qv "Mark as Hired" && echo "PASS - Viewer does not see the button"

CSRF2=$(echo "$PROFILE" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM partner_agencies WHERE agency_name='Viewer Spoof Target Agency';
INSERT INTO partner_agencies (agency_name, address, status) VALUES ('Viewer Spoof Target Agency','Addr','Active');
"
SPOOF_AGENCY_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Viewer Spoof Target Agency';")
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "action=mark_hired" --data-urlencode "csrf_token=$CSRF2" \
  --data-urlencode "id=$APPLICANT_ID" --data-urlencode "agency_id=$SPOOF_AGENCY_ID" \
  -w "\nHTTP status: %{http_code}\n" http://localhost:8899/applicant-view.php

COUNT=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT COUNT(*) FROM employment_records WHERE applicant_id=$APPLICANT_ID;")
[ "$COUNT" = "0" ] && echo "PASS - direct POST by a Viewer created no employment record"

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username='e2e_viewer';
DELETE FROM applicants WHERE applicant_code='HIRE-E2E-001';
DELETE FROM partner_agencies WHERE agency_name='Viewer Spoof Target Agency';
"
```
Expected: HTTP status shown is `403` (the new action's own `can_manage_employment() || is_partner_agency()` gate); two `PASS` lines.

- [ ] **Step 2: Regression smoke test — confirm prior functionality still works after all uppercase changes**

```bash
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

for PAGE in dashboard.php applicants.php reports.php partner-agency.php users.php employment-list.php audit-logs.php; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$PAGE")
  echo "$PAGE -> $STATUS"
done
kill %1
```
Expected: `200` for every page listed (no 500s introduced by any of this plan's changes).

- [ ] **Step 3: No commit needed** (verification-only task).

---

## Post-plan note

This plan does not touch Excel/`.xlsx` export output (`partner-agency.php`, `reports.php` exports) — export values are left exactly as stored (already uppercase, from Task 1's backfill and Task 2's normalized writes going forward), but no extra uppercase transform is applied at export time beyond that. If exports should also visually differ from what's stored, that's a follow-up, not covered here.
