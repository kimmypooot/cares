# Partner Agency Registration & Access Control (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Public visitors can register as a Partner Agency (Pending until an Administrator activates them); once Active, a Partner Agency user can log in and see/act on only their own agency's data across the existing Applicants, Dashboard, Reports, and Partner Agency Profile areas — enforced server-side, never via UI hiding alone.

**Architecture:** Add a shared `users.status` (Pending/Active/Disabled) and `users.agency_id` → `partner_agencies` relationship. Extend `includes/auth.php` with agency-aware login gating and a `current_agency_id()` helper that always re-derives the agency from the DB by session `user_id` (never trusts session-cached or posted `agency_id`). Every Partner-Agency-facing page/query binds that server-derived id. Registration is a second panel on the existing `login.php` (client-side toggle, still a normal POST). Approval reuses the existing `users.php` admin page as a second tab.

**Tech Stack:** PHP 8 (no framework), PDO/MySQL, Alpine.js, Tailwind (prebuilt CSS, no rebuild needed for these changes since no new utility classes are introduced beyond ones already present in the built CSS — verify per-task).

**Spec:** `docs/superpowers/specs/2026-09-07-partner-agency-registration-design.md`

## Global Constraints

- Every state-changing POST handler calls `csrf_require()`; every form emits `csrf_field()`.
- Every protected page double-enforces authorization: a `require_role()`/role check at the top of the file AND again inside any POST handler that changes state (existing convention — see `applicant-view.php`, `partner-agency.php`).
- All SQL goes through PDO prepared statements (`ATTR_EMULATE_PREPARES => false` already set in `config/database.php`) — never string-interpolate user input into SQL.
- All dynamic output goes through `e()` before landing in HTML.
- Passwords: `password_hash()` / `password_verify()` only.
- `agency_id` for a Partner Agency query/mutation is **always** obtained via `current_agency_id($pdo)` (re-reads from `users` by the trusted session `user_id`) — never from `$_GET`, `$_POST`, or a session-cached value.
- Role literal for the new role is the exact string `Partner Agency` (matches existing `Administrator`/`Employee`/`Viewer` proper-case convention).
- `database/database.sql` and `database/migrations/update_application_management.sql` are **not edited** — schema changes go in a new migration file only.
- No test suite exists in this repo (confirmed in CLAUDE.md). Verification below uses `php -l` for syntax, direct DB assertions via the MySQL CLI, and HTTP behavior checks via PHP's built-in server + `curl` — this is the closest equivalent to "write a failing test, make it pass" available in this codebase.
- Local verification tool paths (Windows/XAMPP, confirmed present):
  - PHP: `/c/xampp/php/php.exe`
  - MySQL client: `/c/xampp/mysql/bin/mysql.exe -u root applicant_system`
  - Local DB name: `applicant_system` (see `config/database.php`)
  - Seeded admin credentials (from `database/database.sql`): `admin` / `Admin@123` — if your local DB's admin password differs, substitute it in the curl steps below.

---

## Task 1: Database migration — status, agency_id, partner_agencies contact fields

**Files:**
- Create: `database/migrations/add_partner_agency_accounts.sql`
- Modify: `README.md` (setup instructions — add a note that a fresh install now runs this migration too)

**Interfaces:**
- Produces: `users.status ENUM('Pending','Active','Disabled')`, `users.agency_id INT UNSIGNED NULL` (FK → `partner_agencies.id`, `ON DELETE RESTRICT`), `users.role` enum widened to include `'Partner Agency'`, `partner_agencies.contact_person VARCHAR(150)`, `partner_agencies.contact_no VARCHAR(20)`, `partner_agencies.email VARCHAR(150) NULL`. All later tasks depend on these columns existing.

- [ ] **Step 1: Write the migration file**

```sql
-- =====================================================================
-- Migration: add_partner_agency_accounts.sql
-- Adds Partner Agency self-registration support: a shared Pending/
-- Active/Disabled status for ALL user accounts (replacing is_active as
-- the source of truth, though is_active is kept in sync for backward
-- compatibility), a users.agency_id relationship to partner_agencies,
-- and contact fields on partner_agencies.
--
-- Safe to run against either a fresh v2 install (database/database.sql)
-- or an existing database already upgraded via
-- update_application_management.sql. Every statement is idempotent
-- (IF NOT EXISTS / enum widen) so re-running it is harmless.
--
-- Usage:
--   mysql -u root -p applicant_system < database/migrations/add_partner_agency_accounts.sql
-- =====================================================================

USE applicant_system;

-- ---------------------------------------------------------------------
-- 1. partner_agencies: add contact fields required by Partner Agency
--    Registration. Existing rows (including the two seeded samples)
--    get blank/NULL defaults, editable later via the Partner Agency form.
-- ---------------------------------------------------------------------
ALTER TABLE partner_agencies
  ADD COLUMN IF NOT EXISTS contact_person VARCHAR(150) NOT NULL DEFAULT '' AFTER address,
  ADD COLUMN IF NOT EXISTS contact_no     VARCHAR(20)  NOT NULL DEFAULT '' AFTER contact_person,
  ADD COLUMN IF NOT EXISTS email          VARCHAR(150) NULL AFTER contact_no;

-- ---------------------------------------------------------------------
-- 2. users: widen role enum to add 'Partner Agency'
-- ---------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('Administrator','Employee','Viewer','Partner Agency') NOT NULL DEFAULT 'Viewer';

-- ---------------------------------------------------------------------
-- 3. users: add a shared Pending/Active/Disabled status.
--
--    Backfill logic: is_active=1 -> Active. is_active=0 with
--    role='Viewer' -> Pending (matches the only existing code path that
--    ever created a disabled-pending account: the public Viewer
--    sign-up form). is_active=0 for any other role -> Disabled (matches
--    the only other code path that clears is_active: an Administrator's
--    manual toggle in users.php).
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS status ENUM('Pending','Active','Disabled') NOT NULL DEFAULT 'Active' AFTER role;

UPDATE users SET status = 'Active'   WHERE is_active = 1;
UPDATE users SET status = 'Pending'  WHERE is_active = 0 AND role = 'Viewer';
UPDATE users SET status = 'Disabled' WHERE is_active = 0 AND role <> 'Viewer';

-- ---------------------------------------------------------------------
-- 4. users: add agency_id relationship. NULL for every role except
--    Partner Agency, where application code enforces NOT NULL (MySQL
--    can't conditionally require a column based on another column's
--    value).
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS agency_id INT UNSIGNED NULL AFTER status;

ALTER TABLE users
  ADD CONSTRAINT fk_users_agency
    FOREIGN KEY IF NOT EXISTS (agency_id) REFERENCES partner_agencies(id) ON DELETE RESTRICT,
  ADD INDEX IF NOT EXISTS idx_users_agency (agency_id);

-- ---------------------------------------------------------------------
-- 5. Sanity check
-- ---------------------------------------------------------------------
SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS users_pending FROM users WHERE status = 'Pending';
SELECT COUNT(*) AS partner_agency_users_missing_agency FROM users WHERE role = 'Partner Agency' AND agency_id IS NULL;
```

- [ ] **Step 2: Apply the migration to the local database**

Run: `/c/xampp/mysql/bin/mysql.exe -u root applicant_system < database/migrations/add_partner_agency_accounts.sql`
Expected: prints "Migration complete." then two `0` counts (no Partner Agency users exist yet), no errors.

- [ ] **Step 3: Verify schema**

Run:
```bash
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "DESCRIBE users;"
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "DESCRIBE partner_agencies;"
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "SELECT username, role, status, is_active, agency_id FROM users;"
```
Expected: `users` shows `status` (enum Pending/Active/Disabled) and `agency_id` columns; `partner_agencies` shows `contact_person`/`contact_no`/`email`; the existing `admin` row shows `status = Active` (backfilled from `is_active = 1`).

- [ ] **Step 4: Confirm idempotency (safe to re-run)**

Run: `/c/xampp/mysql/bin/mysql.exe -u root applicant_system < database/migrations/add_partner_agency_accounts.sql`
Expected: runs again with no errors (all `ADD COLUMN`/`ADD CONSTRAINT`/`ADD INDEX` use `IF NOT EXISTS` guards, matching the pattern already used in `update_application_management.sql`).

- [ ] **Step 5: Update README setup instructions**

Read `README.md`, find the database setup section (references `database/database.sql` and/or `update_application_management.sql`), add one line noting new installs also run `database/migrations/add_partner_agency_accounts.sql` after `database.sql`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/add_partner_agency_accounts.sql README.md
git commit -m "db: add partner agency account status, agency_id, and contact fields"
```

---

## Task 2: `includes/auth.php` — status-aware login, agency identity helpers

**Files:**
- Modify: `includes/auth.php`

**Interfaces:**
- Consumes: Task 1's `users.status`, `users.agency_id`, `partner_agencies.status`.
- Produces: `current_agency_id(PDO $pdo): ?int`, `is_partner_agency(): bool`, `require_own_agency_record(PDO $pdo, ?int $recordAgencyId): void`, `set_user_status(PDO $pdo, int $userId, string $status): void` — all consumed by Tasks 5, 6, 7, 8, 9, 10, 11. `attempt_login()` keeps its existing signature (`PDO $pdo, string $username, string $password): bool`) but now checks `status`/agency status instead of only `is_active`.

- [ ] **Step 1: Replace `attempt_login()`**

Find this block in `includes/auth.php`:
```php
function attempt_login(PDO $pdo, string $username, string $password): bool
{
    $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
    $_SESSION['login_locked_until'] = $_SESSION['login_locked_until'] ?? 0;

    if (time() < $_SESSION['login_locked_until']) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_attempts'] = 0;

        audit_log($pdo, $user['id'], 'LOGIN', 'users', $user['id'], 'User logged in');
        return true;
    }

    $_SESSION['login_attempts']++;
    if ($_SESSION['login_attempts'] >= 5) {
        $_SESSION['login_locked_until'] = time() + 60; // 60s lockout after 5 failed attempts
        $_SESSION['login_attempts'] = 0;
    }

    return false;
}
```

Replace with:
```php
function attempt_login(PDO $pdo, string $username, string $password): bool
{
    $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
    $_SESSION['login_locked_until'] = $_SESSION['login_locked_until'] ?? 0;

    if (time() < $_SESSION['login_locked_until']) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT u.*, pa.status AS agency_status
         FROM users u
         LEFT JOIN partner_agencies pa ON pa.id = u.agency_id
         WHERE u.username = :u LIMIT 1"
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    // Agency-based access control: a Partner Agency account additionally
    // needs its agency record to be Active — an admin can lock out an
    // entire agency by disabling the agency record, independent of the
    // individual account's own status.
    $accountUsable = $user
        && $user['status'] === 'Active'
        && ($user['role'] !== 'Partner Agency' || $user['agency_status'] === 'Active');

    if ($accountUsable && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_attempts'] = 0;

        audit_log($pdo, $user['id'], 'LOGIN', 'users', $user['id'], 'User logged in');
        return true;
    }

    $_SESSION['login_attempts']++;
    if ($_SESSION['login_attempts'] >= 5) {
        $_SESSION['login_locked_until'] = time() + 60; // 60s lockout after 5 failed attempts
        $_SESSION['login_attempts'] = 0;
    }

    return false;
}
```

- [ ] **Step 2: Add agency identity + status helpers**

Insert immediately after the `can_view_audit_logs()` function (before `is_last_active_admin`):
```php
/** Is the current session's role Partner Agency? */
function is_partner_agency(): bool
{
    return current_user()['role'] === 'Partner Agency';
}

/**
 * The authenticated user's agency_id, always re-read from the database
 * by the trusted session user_id — never cached in $_SESSION and never
 * taken from a request parameter. This is the only source of truth for
 * "which agency does this request belong to."
 */
function current_agency_id(PDO $pdo): ?int
{
    $userId = current_user()['id'];
    if (!$userId) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT agency_id FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $agencyId = $stmt->fetchColumn();
    return $agencyId !== null && $agencyId !== false ? (int)$agencyId : null;
}

/**
 * 403s unless $recordAgencyId belongs to the current session's own
 * agency. Call this before any Partner Agency read/write that targets a
 * specific record by id, to close the IDOR gap (a Partner Agency user
 * changing a URL/POST id to target another agency's data).
 */
function require_own_agency_record(PDO $pdo, ?int $recordAgencyId): void
{
    if ($recordAgencyId === null || $recordAgencyId !== current_agency_id($pdo)) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif">403 — You do not have permission to access this record.</h2>');
    }
}

/** Set a user's account status, keeping the legacy is_active column in sync. */
function set_user_status(PDO $pdo, int $userId, string $status): void
{
    $pdo->prepare("UPDATE users SET status = :s, is_active = :a WHERE id = :id")
        ->execute([':s' => $status, ':a' => $status === 'Active' ? 1 : 0, ':id' => $userId]);
}
```

- [ ] **Step 3: Syntax check**

Run: `/c/xampp/php/php.exe -l includes/auth.php`
Expected: `No syntax errors detected in includes/auth.php`

- [ ] **Step 4: Write and run a CLI verification script**

Create a throwaway script (not part of the repo) at the session scratchpad path, e.g.
`C:\Users\CSCESD~1\AppData\Local\Temp\claude\C--xampp-htdocs-applicant-system\<session>\scratchpad\verify_auth.php`:
```php
<?php
declare(strict_types=1);
require_once 'C:/xampp/htdocs/applicant-system/includes/auth.php';

$pdo = Database::getConnection();
$fail = 0;
function check(bool $cond, string $label): void {
    global $fail;
    echo ($cond ? "PASS" : "FAIL") . " - $label\n";
    if (!$cond) $fail++;
}

// Set up a throwaway agency + Pending Partner Agency user
$pdo->prepare("DELETE FROM users WHERE username = 'verify_pa_user'")->execute();
$pdo->prepare("DELETE FROM partner_agencies WHERE agency_name = 'Verify Agency Co'")->execute();

$pdo->prepare("INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email, status) VALUES ('Verify Agency Co','Addr','CP','0900000000','verify@example.com','Active')")->execute();
$agencyId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (username, password, full_name, role, status, is_active, agency_id) VALUES ('verify_pa_user', :p, 'Verify Contact', 'Partner Agency', 'Pending', 0, :aid)")
    ->execute([':p' => password_hash('TestPass123', PASSWORD_DEFAULT), ':aid' => $agencyId]);
$userId = (int)$pdo->lastInsertId();

// Pending -> login must fail
check(attempt_login($pdo, 'verify_pa_user', 'TestPass123') === false, 'Pending Partner Agency cannot log in');

// Activate -> login must succeed
set_user_status($pdo, $userId, 'Active');
check(attempt_login($pdo, 'verify_pa_user', 'TestPass123') === true, 'Active Partner Agency can log in');
check(is_partner_agency() === true, 'is_partner_agency() true after login');
check(current_agency_id($pdo) === $agencyId, 'current_agency_id() matches seeded agency');

// Disable the agency record itself -> a fresh login attempt must fail even though the account is Active
$_SESSION = []; // simulate logging out
$pdo->prepare("UPDATE partner_agencies SET status = 'Disabled' WHERE id = :id")->execute([':id' => $agencyId]);
check(attempt_login($pdo, 'verify_pa_user', 'TestPass123') === false, 'Login blocked when agency record is Disabled, even if account status is Active');

// Cleanup
$pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $userId]);
$pdo->prepare("DELETE FROM partner_agencies WHERE id = :id")->execute([':id' => $agencyId]);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
```

Run: `/c/xampp/php/php.exe /path/to/scratchpad/verify_auth.php`
Expected: five `PASS` lines, then `ALL PASS`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add includes/auth.php
git commit -m "feat: status- and agency-aware login gating, agency identity helpers"
```

---

## Task 3: Shared duplicate-agency-name helper + contact fields in the internal agency form/list

**Files:**
- Modify: `includes/functions.php`
- Modify: `public/partner-agency-form.php`
- Modify: `public/partner-agency.php`

**Interfaces:**
- Consumes: Task 1's `partner_agencies.contact_person`/`contact_no`/`email`.
- Produces: `agency_name_taken(PDO $pdo, string $agencyName, ?int $excludeId = null): bool`, reused by Task 5 (public registration) and Task 9 (`my-agency.php`).

- [ ] **Step 1: Add the shared helper to `includes/functions.php`**

Insert after `active_agencies()`:
```php
/** Is this agency name already used by a different partner agency? Pass $excludeId when editing an existing one. */
function agency_name_taken(PDO $pdo, string $agencyName, ?int $excludeId = null): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partner_agencies WHERE agency_name = :n AND id <> :id");
    $stmt->execute([':n' => $agencyName, ':id' => $excludeId ?? 0]);
    return (int)$stmt->fetchColumn() > 0;
}
```

- [ ] **Step 2: Use the helper in `partner-agency-form.php` and add contact fields**

Find:
```php
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];
$old = ['agency_name' => '', 'address' => ''];
```
Replace with:
```php
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];
$old = ['agency_name' => '', 'address' => '', 'contact_person' => '', 'contact_no' => '', 'email' => ''];
```

Find:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old['agency_name'] = clean($_POST['agency_name'] ?? '');
    $old['address'] = clean($_POST['address'] ?? '');

    if ($old['agency_name'] === '') $errors['agency_name'] = 'Name of Agency/Office is required.';
    if ($old['address'] === '') $errors['address'] = 'Address is required.';

    if (!$errors) {
        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM partner_agencies WHERE agency_name = :n AND id <> :id");
        $dupStmt->execute([':n' => $old['agency_name'], ':id' => $id]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            $errors['agency_name'] = 'A Partner Agency with this name already exists.';
        }
    }

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("UPDATE partner_agencies SET agency_name = :n, address = :a WHERE id = :id")
                ->execute([':n' => $old['agency_name'], ':a' => $old['address'], ':id' => $id]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'partner_agencies', $id, "Updated Partner Agency: {$old['agency_name']}");
            flash_set('success', 'Partner Agency updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO partner_agencies (agency_name, address) VALUES (:n, :a)");
            $stmt->execute([':n' => $old['agency_name'], ':a' => $old['address']]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, (int)current_user()['id'], 'CREATE', 'partner_agencies', $newId, "Created Partner Agency: {$old['agency_name']}");
            flash_set('success', 'Partner Agency added successfully.');
        }
        redirect('partner-agency.php');
    }
}
```
Replace with:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old['agency_name']    = clean($_POST['agency_name'] ?? '');
    $old['address']        = clean($_POST['address'] ?? '');
    $old['contact_person'] = clean($_POST['contact_person'] ?? '');
    $old['contact_no']     = clean($_POST['contact_no'] ?? '');
    $old['email']          = clean($_POST['email'] ?? '');

    if ($old['agency_name'] === '') $errors['agency_name'] = 'Name of Agency/Office is required.';
    if ($old['address'] === '') $errors['address'] = 'Address is required.';
    if ($old['email'] !== '' && !is_valid_email($old['email'])) $errors['email'] = 'Enter a valid email address.';

    if (!$errors && agency_name_taken($pdo, $old['agency_name'], $isEdit ? $id : null)) {
        $errors['agency_name'] = 'A Partner Agency with this name already exists.';
    }

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("UPDATE partner_agencies SET agency_name = :n, address = :a, contact_person = :cp, contact_no = :cn, email = :e WHERE id = :id")
                ->execute([':n' => $old['agency_name'], ':a' => $old['address'], ':cp' => $old['contact_person'], ':cn' => $old['contact_no'], ':e' => $old['email'] ?: null, ':id' => $id]);
            audit_log($pdo, (int)current_user()['id'], 'UPDATE', 'partner_agencies', $id, "Updated Partner Agency: {$old['agency_name']}");
            flash_set('success', 'Partner Agency updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email) VALUES (:n, :a, :cp, :cn, :e)");
            $stmt->execute([':n' => $old['agency_name'], ':a' => $old['address'], ':cp' => $old['contact_person'], ':cn' => $old['contact_no'], ':e' => $old['email'] ?: null]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, (int)current_user()['id'], 'CREATE', 'partner_agencies', $newId, "Created Partner Agency: {$old['agency_name']}");
            flash_set('success', 'Partner Agency added successfully.');
        }
        redirect('partner-agency.php');
    }
}
```

Find the form fields block:
```php
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
        <textarea name="address" required rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['address']) ? 'hidden' : '' ?>" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
      </div>
    </div>
```
Replace with:
```php
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
        <textarea name="address" required rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['address']) ? 'hidden' : '' ?>" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact Person</label>
          <input type="text" name="contact_person" value="<?= e($old['contact_person']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact No</label>
          <input type="text" name="contact_no" value="<?= e($old['contact_no']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
        <input type="email" name="email" value="<?= e($old['email']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1 <?= empty($errors['email']) ? 'hidden' : '' ?>" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
      </div>
    </div>
```

- [ ] **Step 3: Add the new columns to `partner-agency.php`'s list table**

Find:
```php
            <th class="px-4 py-2.5 text-left">Agency / Office Name</th>
            <th class="px-4 py-2.5 text-left">Address</th>
            <th class="px-4 py-2.5 text-left">Employment Records</th>
            <th class="px-4 py-2.5 text-left">Status</th>
```
Replace with:
```php
            <th class="px-4 py-2.5 text-left">Agency / Office Name</th>
            <th class="px-4 py-2.5 text-left">Contact Person</th>
            <th class="px-4 py-2.5 text-left">Contact No</th>
            <th class="px-4 py-2.5 text-left">Email</th>
            <th class="px-4 py-2.5 text-left">Employment Records</th>
            <th class="px-4 py-2.5 text-left">Status</th>
```

Find:
```php
            <td class="px-4 py-3 font-medium" data-label="Agency"><?= e($ag['agency_name']) ?></td>
            <td class="px-4 py-3 text-slate-500" data-label="Address"><?= e($ag['address']) ?></td>
            <td class="px-4 py-3" data-label="Records"><?= (int)$ag['record_count'] ?></td>
```
Replace with:
```php
            <td class="px-4 py-3 font-medium" data-label="Agency"><?= e($ag['agency_name']) ?><p class="text-xs text-slate-400 font-normal"><?= e($ag['address']) ?></p></td>
            <td class="px-4 py-3 text-slate-500" data-label="Contact Person"><?= e($ag['contact_person'] ?: '—') ?></td>
            <td class="px-4 py-3 text-slate-500" data-label="Contact No"><?= e($ag['contact_no'] ?: '—') ?></td>
            <td class="px-4 py-3 text-slate-500" data-label="Email"><?= e($ag['email'] ?: '—') ?></td>
            <td class="px-4 py-3" data-label="Records"><?= (int)$ag['record_count'] ?></td>
```

Also update `colspan="5"` on the empty-state row to `colspan="7"` (two new columns added):
Find: `<tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">`
Replace with: `<tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">`

- [ ] **Step 4: Syntax check all three files**

Run:
```bash
/c/xampp/php/php.exe -l includes/functions.php
/c/xampp/php/php.exe -l public/partner-agency-form.php
/c/xampp/php/php.exe -l public/partner-agency.php
```
Expected: "No syntax errors detected" for all three.

- [ ] **Step 5: Verify via CLI script**

```php
<?php
declare(strict_types=1);
require_once 'C:/xampp/htdocs/applicant-system/includes/auth.php';
$pdo = Database::getConnection();

$pdo->prepare("DELETE FROM partner_agencies WHERE agency_name IN ('Dup Test Agency')")->execute();
$pdo->prepare("INSERT INTO partner_agencies (agency_name, address) VALUES ('Dup Test Agency', 'Addr')")->execute();
$id = (int)$pdo->lastInsertId();

$fail = 0;
function check(bool $c, string $l) { global $fail; echo ($c?'PASS':'FAIL')." - $l\n"; if(!$c)$fail++; }

check(agency_name_taken($pdo, 'Dup Test Agency') === true, 'Existing name detected as taken');
check(agency_name_taken($pdo, 'Dup Test Agency', $id) === false, 'Excluding own id, not taken (edit case)');
check(agency_name_taken($pdo, 'Totally New Agency Name') === false, 'New name not taken');

$pdo->prepare("DELETE FROM partner_agencies WHERE id = :id")->execute([':id' => $id]);
echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
```
Run: `/c/xampp/php/php.exe /path/to/scratchpad/verify_agency_name_taken.php`
Expected: three `PASS` lines, `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add includes/functions.php public/partner-agency-form.php public/partner-agency.php
git commit -m "feat: shared agency-name duplicate check; contact fields in internal agency form/list"
```

---

## Task 4: Retire public Viewer sign-up (`public/signup.php`)

**Files:**
- Modify: `public/signup.php`

**Interfaces:** None (terminal page — redirects only).

- [ ] **Step 1: Replace the entire file**

Replace all of `public/signup.php` with:
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

// Public Viewer self-registration has been retired in favor of Partner
// Agency Registration (see login.php). Administrators can still create
// Viewer accounts directly via users.php.
redirect('login.php');
```

- [ ] **Step 2: Syntax check**

Run: `/c/xampp/php/php.exe -l public/signup.php`
Expected: "No syntax errors detected"

- [ ] **Step 3: Verify via HTTP**

```bash
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://localhost:8899/signup.php
kill %1
```
Expected: `302 http://localhost:8899/login.php`

- [ ] **Step 4: Commit**

```bash
git add public/signup.php
git commit -m "refactor: retire public Viewer sign-up in favor of Partner Agency registration"
```

---

## Task 5: `public/login.php` — Partner Agency Registration panel

**Files:**
- Modify: `public/login.php`

**Interfaces:**
- Consumes: `agency_name_taken()` (Task 3), `is_valid_email()` (existing, `functions.php`).
- Produces: on success, a `users` row with `role='Partner Agency'`, `status='Pending'`, `agency_id` set, and a matching `partner_agencies` row with `status='Active'`.

- [ ] **Step 1: Replace the entire file**

Replace all of `public/login.php` with:
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$pdo = Database::getConnection();
$error = null;
$activePanel = 'login';

$regErrors = [];
$regOld = ['agency_name' => '', 'address' => '', 'contact_person' => '', 'contact_no' => '', 'email' => '', 'username' => ''];
$regSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    csrf_require();
    $username = clean($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (attempt_login($pdo, $username, $password)) {
        redirect('dashboard.php');
    } else {
        $stmt = $pdo->prepare(
            "SELECT u.status, u.role, pa.status AS agency_status
             FROM users u LEFT JOIN partner_agencies pa ON pa.id = u.agency_id
             WHERE u.username = :u LIMIT 1"
        );
        $stmt->execute([':u' => $username]);
        $target = $stmt->fetch();

        if ($target && $target['role'] === 'Partner Agency' && $target['status'] === 'Pending') {
            $error = 'Your Partner Agency account is still pending Administrator approval.';
        } elseif ($target && $target['role'] === 'Partner Agency' && ($target['status'] === 'Disabled' || $target['agency_status'] === 'Disabled')) {
            $error = 'Your Partner Agency account has been disabled. Please contact the Administrator.';
        } elseif ($target && $target['status'] !== 'Active') {
            $error = 'Your account is pending administrator approval or has been disabled.';
        } else {
            $error = 'Invalid username or password, or your account is temporarily locked.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'register_agency') {
    csrf_require();
    $activePanel = 'register';

    $regOld['agency_name']    = clean($_POST['agency_name'] ?? '');
    $regOld['address']        = clean($_POST['address'] ?? '');
    $regOld['contact_person'] = clean($_POST['contact_person'] ?? '');
    $regOld['contact_no']     = clean($_POST['contact_no'] ?? '');
    $regOld['email']          = clean($_POST['email'] ?? '');
    $regOld['username']       = clean($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if ($regOld['agency_name'] === '')    $regErrors['agency_name'] = 'Agency Name is required.';
    if ($regOld['address'] === '')        $regErrors['address'] = 'Address is required.';
    if ($regOld['contact_person'] === '') $regErrors['contact_person'] = 'Contact Person is required.';
    if ($regOld['contact_no'] === '')     $regErrors['contact_no'] = 'Contact No is required.';
    if ($regOld['email'] === '') {
        $regErrors['email'] = 'Email Address is required.';
    } elseif (!is_valid_email($regOld['email'])) {
        $regErrors['email'] = 'Enter a valid email address.';
    }
    if ($regOld['username'] === '') {
        $regErrors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $regOld['username'])) {
        $regErrors['username'] = 'Username may only contain letters, numbers, underscores, and periods (3-50 characters).';
    }
    if (strlen($password) < 8) $regErrors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $regErrors['confirm_password'] = 'Passwords do not match.';

    if (!$regErrors && agency_name_taken($pdo, $regOld['agency_name'])) {
        $regErrors['agency_name'] = 'A Partner Agency with this name is already registered.';
    }
    if (!$regErrors) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
        $dup->execute([':u' => $regOld['username']]);
        if ((int)$dup->fetchColumn() > 0) {
            $regErrors['username'] = 'That username is already taken.';
        }
    }
    if (!$regErrors) {
        $dupEmail = $pdo->prepare("SELECT COUNT(*) FROM partner_agencies WHERE email = :e");
        $dupEmail->execute([':e' => $regOld['email']]);
        if ((int)$dupEmail->fetchColumn() > 0) {
            $regErrors['email'] = 'That email address is already registered to a Partner Agency.';
        }
    }

    if (!$regErrors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email, status)
                 VALUES (:n, :a, :cp, :cn, :e, 'Active')"
            );
            $stmt->execute([
                ':n' => $regOld['agency_name'], ':a' => $regOld['address'],
                ':cp' => $regOld['contact_person'], ':cn' => $regOld['contact_no'], ':e' => $regOld['email'],
            ]);
            $agencyId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO users (username, password, full_name, role, status, is_active, agency_id)
                 VALUES (:u, :p, :f, 'Partner Agency', 'Pending', 0, :aid)"
            );
            $stmt->execute([
                ':u' => $regOld['username'],
                ':p' => password_hash($password, PASSWORD_DEFAULT),
                ':f' => $regOld['contact_person'],
                ':aid' => $agencyId,
            ]);

            audit_log($pdo, null, 'PARTNER_AGENCY_REGISTER', 'partner_agencies', $agencyId,
                "Partner Agency registration: {$regOld['agency_name']} (user: {$regOld['username']}), pending approval");

            $pdo->commit();
            $regSuccess = true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $regErrors['general'] = 'Registration failed due to a system error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · CARE</title>
<link rel="icon" type="image/png" href="assets/images/csc-logo.png">
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<script defer src="assets/vendor/alpine/alpine.min.js"></script>
</head>
<body class="min-h-screen" style="background: radial-gradient(circle at top, #1e2a5e 0%, #0f172a 70%); background-repeat: no-repeat; background-attachment: fixed; background-size: cover;">

<div class="min-h-screen grid lg:grid-cols-2" x-data="{ panel: '<?= $activePanel ?>' }">

  <!-- LEFT: system branding (unchanged) -->
  <div class="flex flex-col justify-center items-center lg:items-start text-center lg:text-left
              px-8 py-10 lg:py-0 lg:px-24 xl:px-32 lg:translate-x-[25px]  text-white">
    <div class="flex items-center gap-4 mb-5">
      <img src="assets/images/csc-logo.png" alt="Civil Service Commission Logo" width="128" height="128" class="h-32 w-32 object-contain">
      <img src="assets/images/bagong-pilipinas.png" alt="Bagong Pilipinas Logo" width="128" height="128" class="h-32 w-32 object-contain">
      <img src="assets/images/lingkod-bayani.png" alt="Lingkod Bayani Logo" width="128" height="128" class="h-32 w-32 object-contain">
    </div>
    <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight leading-tight max-w-md">
      Civil Service Commission Regional Office VIII
    </h1>
    <p class="text-lg sm:text-xl font-semibold text-blue-300 tracking-wide mt-2 max-w-md">
      Candidate Application &amp; Registration for Employment
    </p>
    <p class="text-slate-300 mt-4 text-sm lg:text-base max-w-md leading-relaxed">Managing the lifecycle of a job fair applicant from registration to placement.</p>
  </div>

  <!-- RIGHT: login / registration panel -->
  <div class="flex items-center justify-center p-4 sm:p-8 lg:p-12">
    <div class="w-full max-w-md">

      <!-- LOGIN PANEL -->
      <div x-show="panel === 'login'" x-cloak class="bg-white rounded-2xl shadow-xl border border-slate-100 p-8">
        <h2 class="text-lg font-semibold text-slate-800 mb-1">Login</h2>
        <p class="text-sm text-slate-500 mb-6">Enter your credentials to continue.</p>

        <?php if ($error): ?>
          <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2.5">
            <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="login">
          <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
          <div class="relative mb-4">
            <i class="fa-solid fa-user absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
            <input type="text" name="username" required autofocus
                   class="w-full pl-9 pr-3 py-2.5 rounded-lg border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-100 outline-none text-sm transition">
          </div>

          <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <div class="relative mb-6">
            <i class="fa-solid fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
            <input type="password" name="password" required
                   class="w-full pl-9 pr-3 py-2.5 rounded-lg border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-100 outline-none text-sm transition">
          </div>

          <button type="submit"
                  class="w-full bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 rounded-lg transition shadow-sm">
            <i class="fa-solid fa-right-to-bracket mr-1"></i> Login
          </button>
        </form>

        <div class="mt-6 pt-6 border-t border-slate-100 text-center">
          <p class="text-sm text-slate-500 mb-3">New applicant?</p>
          <a href="register-applicant.php"
             class="block w-full text-center px-4 py-2.5 rounded-lg border-2 border-brand-600 text-brand-700 font-semibold text-sm hover:bg-brand-50 transition">
            <i class="fa-solid fa-user-plus mr-1"></i> Register as Job Applicant
          </a>
        </div>

        <div class="mt-4 text-center">
          <button type="button" @click="panel = 'register'" class="text-sm text-brand-600 hover:text-brand-700 font-medium">
            <i class="fa-solid fa-building mr-1"></i> Partner Agency Registration
          </button>
        </div>
      </div>

      <!-- PARTNER AGENCY REGISTRATION PANEL -->
      <div x-show="panel === 'register'" x-cloak class="bg-white rounded-2xl shadow-xl border border-slate-100 p-8">
        <?php if ($regSuccess): ?>
          <div class="text-center py-4">
            <i class="fa-solid fa-circle-check text-4xl text-green-500 mb-3"></i>
            <h2 class="font-semibold text-slate-800 mb-2">Registration Submitted</h2>
            <p class="text-sm text-slate-600">Your Partner Agency registration has been submitted successfully. Your account is currently pending Administrator approval. You will be able to log in once your account has been activated.</p>
            <button type="button" @click="panel = 'login'" class="inline-block mt-5 px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Back to Login</button>
          </div>
        <?php else: ?>
          <button type="button" @click="panel = 'login'" class="text-xs text-slate-400 hover:text-slate-600 mb-3 inline-block"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Login</button>
          <h2 class="text-lg font-semibold text-slate-800 mb-1">Partner Agency Registration</h2>
          <p class="text-sm text-slate-500 mb-4">Register your agency to participate in the system. Your account will be reviewed by an Administrator before you can log in.</p>

          <?php if (!empty($regErrors['general'])): ?>
            <div class="mb-4 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2.5">
              <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
              <span><?= e($regErrors['general']) ?></span>
            </div>
          <?php endif; ?>

          <form method="POST" x-data="{ show1:false, show2:false, submitting:false }" @submit="submitting = true" onsubmit="return validateForm(this);">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="register_agency">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2 mt-1">Agency Information</p>
            <div class="space-y-3">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Agency Name <span class="text-red-500">*</span></label>
                <input type="text" name="agency_name" required value="<?= e($regOld['agency_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['agency_name'] ?? '') ?></p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
                <input type="text" name="address" required value="<?= e($regOld['address']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['address'] ?? '') ?></p>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-1">Contact Person <span class="text-red-500">*</span></label>
                  <input type="text" name="contact_person" required value="<?= e($regOld['contact_person']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <p class="text-xs text-red-500 mt-1"><?= e($regErrors['contact_person'] ?? '') ?></p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-1">Contact No <span class="text-red-500">*</span></label>
                  <input type="text" name="contact_no" required value="<?= e($regOld['contact_no']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <p class="text-xs text-red-500 mt-1"><?= e($regErrors['contact_no'] ?? '') ?></p>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Email Address <span class="text-red-500">*</span></label>
                <input type="email" name="email" required value="<?= e($regOld['email']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['email'] ?? '') ?></p>
              </div>
            </div>

            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2 mt-4">Account Information</p>
            <div class="space-y-3">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
                <input type="text" name="username" required value="<?= e($regOld['username']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['username'] ?? '') ?></p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
                <div class="relative">
                  <input :type="show1 ? 'text' : 'password'" name="password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 pr-9 text-sm">
                  <button type="button" @click="show1 = !show1" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid" :class="show1 ? 'fa-eye-slash' : 'fa-eye'"></i></button>
                </div>
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['password'] ?? '') ?></p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                <div class="relative">
                  <input :type="show2 ? 'text' : 'password'" name="confirm_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 pr-9 text-sm">
                  <button type="button" @click="show2 = !show2" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid" :class="show2 ? 'fa-eye-slash' : 'fa-eye'"></i></button>
                </div>
                <p class="text-xs text-red-500 mt-1"><?= e($regErrors['confirm_password'] ?? '') ?></p>
              </div>
            </div>

            <button type="submit" :disabled="submitting" :class="submitting ? 'opacity-60 cursor-not-allowed' : ''" class="w-full mt-5 bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 rounded-lg transition shadow-sm">
              <span x-show="!submitting">Submit Registration</span>
              <span x-show="submitting"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Submitting...</span>
            </button>
          </form>
        <?php endif; ?>
      </div>

      <p class="text-center text-slate-400 text-xs mt-6">© <?= date('Y') ?> CARE — Candidate Application & Registration for Employment. All rights reserved.</p>
    </div>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
```

- [ ] **Step 2: Syntax check**

Run: `/c/xampp/php/php.exe -l public/login.php`
Expected: "No syntax errors detected"

- [ ] **Step 3: Verify the registration flow end-to-end via curl**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)

BODY=$(curl -s -c "$COOKIE" http://localhost:8899/login.php)
echo "$BODY" | grep -q "Partner Agency Registration" && echo "PASS - registration link present"
echo "$BODY" | grep -qv "Sign Up as a Viewer" && echo "PASS - old Viewer signup link removed"
CSRF=$(echo "$BODY" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)

# Successful registration
RESP=$(curl -s -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "form=register_agency" \
  --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "agency_name=Curl Test Agency" \
  --data-urlencode "address=123 Test St" \
  --data-urlencode "contact_person=Jane Tester" \
  --data-urlencode "contact_no=09171234567" \
  --data-urlencode "email=curltest@example.com" \
  --data-urlencode "username=curltestagency" \
  --data-urlencode "password=TestPass123" \
  --data-urlencode "confirm_password=TestPass123" \
  http://localhost:8899/login.php)
echo "$RESP" | grep -q "pending Administrator approval" && echo "PASS - success message shown"

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e \
  "SELECT u.status, u.role, pa.agency_name FROM users u JOIN partner_agencies pa ON pa.id = u.agency_id WHERE u.username = 'curltestagency';"

# Login attempt while Pending must be rejected with the agency-specific message
CSRF2=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
LOGIN_RESP=$(curl -s -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF2" \
  --data-urlencode "username=curltestagency" --data-urlencode "password=TestPass123" \
  http://localhost:8899/login.php)
echo "$LOGIN_RESP" | grep -q "still pending Administrator approval" && echo "PASS - pending login correctly rejected"

# Duplicate agency name must be rejected
CSRF3=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
DUP_RESP=$(curl -s -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "form=register_agency" --data-urlencode "csrf_token=$CSRF3" \
  --data-urlencode "agency_name=Curl Test Agency" --data-urlencode "address=Other addr" \
  --data-urlencode "contact_person=X" --data-urlencode "contact_no=09170000000" \
  --data-urlencode "email=other@example.com" --data-urlencode "username=someoneelse" \
  --data-urlencode "password=TestPass123" --data-urlencode "confirm_password=TestPass123" \
  http://localhost:8899/login.php)
echo "$DUP_RESP" | grep -q "already registered" && echo "PASS - duplicate agency name rejected"

kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "DELETE FROM users WHERE username='curltestagency'; DELETE FROM partner_agencies WHERE agency_name='Curl Test Agency';"
```
Expected: all `PASS` lines print; the `SELECT` shows `status=Pending, role=Partner Agency, agency_name=Curl Test Agency`.

- [ ] **Step 4: Commit**

```bash
git add public/login.php
git commit -m "feat: Partner Agency Registration panel on the login page, remove Viewer sign-up link"
```

---

## Task 6: `public/users.php` — Partner Agency Accounts tab

**Files:**
- Modify: `public/users.php`

**Interfaces:**
- Consumes: `set_user_status()` (Task 2).
- Produces: nothing consumed by later tasks directly, but Task 7's dashboard notification links to `users.php?tab=partner-agencies`.

- [ ] **Step 1: Fix the existing `toggle` action to keep `status` in sync**

Find:
```php
    } elseif ($action === 'toggle') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $currentUserId) {
            flash_set('error', 'You cannot disable your own account.');
        } elseif (is_last_active_admin($pdo, $userId)) {
            flash_set('error', 'Cannot disable the only active Administrator account.');
        } else {
            $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = :id")->execute([':id' => $userId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'users', $userId, 'Toggled user active status');
            flash_set('success', 'User status updated.');
        }
        redirect('users.php');
    } elseif ($action === 'delete') {
```
Replace with:
```php
    } elseif ($action === 'toggle') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $currentUserId) {
            flash_set('error', 'You cannot disable your own account.');
        } elseif (is_last_active_admin($pdo, $userId)) {
            flash_set('error', 'Cannot disable the only active Administrator account.');
        } else {
            $statusStmt = $pdo->prepare("SELECT status FROM users WHERE id = :id");
            $statusStmt->execute([':id' => $userId]);
            $currentStatus = $statusStmt->fetchColumn();
            set_user_status($pdo, $userId, $currentStatus === 'Active' ? 'Disabled' : 'Active');
            audit_log($pdo, $currentUserId, 'UPDATE', 'users', $userId, 'Toggled user active status');
            flash_set('success', 'User status updated.');
        }
        redirect('users.php');
    } elseif ($action === 'delete') {
```

- [ ] **Step 2: Add Partner Agency account actions**

Find the end of the POST handler — this block:
```php
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            flash_set('error', 'Temporary password must be at least 8 characters.');
        } else {
            $pdo->prepare("UPDATE users SET password = :p WHERE id = :id")
                ->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'users', $userId, 'Password reset by administrator');
            flash_set('success', 'Password reset successfully.');
        }
        redirect('users.php');
    }
}
```
Replace with:
```php
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            flash_set('error', 'Temporary password must be at least 8 characters.');
        } else {
            $pdo->prepare("UPDATE users SET password = :p WHERE id = :id")
                ->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'UPDATE', 'users', $userId, 'Password reset by administrator');
            flash_set('success', 'Password reset successfully.');
        }
        redirect('users.php');
    } elseif ($action === 'activate_agency') {
        $userId = (int)($_POST['user_id'] ?? 0);
        set_user_status($pdo, $userId, 'Active');
        audit_log($pdo, $currentUserId, 'PARTNER_AGENCY_ACTIVATE', 'users', $userId, 'Partner Agency account activated');
        flash_set('success', 'Partner Agency account activated.');
        redirect('users.php?tab=partner-agencies');
    } elseif ($action === 'disable_agency') {
        $userId = (int)($_POST['user_id'] ?? 0);
        set_user_status($pdo, $userId, 'Disabled');
        audit_log($pdo, $currentUserId, 'PARTNER_AGENCY_DISABLE', 'users', $userId, 'Partner Agency account disabled');
        flash_set('success', 'Partner Agency account disabled.');
        redirect('users.php?tab=partner-agencies');
    } elseif ($action === 'reenable_agency') {
        $userId = (int)($_POST['user_id'] ?? 0);
        set_user_status($pdo, $userId, 'Active');
        audit_log($pdo, $currentUserId, 'PARTNER_AGENCY_REENABLE', 'users', $userId, 'Partner Agency account re-enabled');
        flash_set('success', 'Partner Agency account re-enabled.');
        redirect('users.php?tab=partner-agencies');
    } elseif ($action === 'delete_agency_account') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT agency_id FROM users WHERE id = :id AND role = 'Partner Agency'");
        $stmt->execute([':id' => $userId]);
        $agencyId = $stmt->fetchColumn();
        if (!$agencyId) {
            flash_set('error', 'Partner Agency account not found.');
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $userId]);
            audit_log($pdo, $currentUserId, 'PARTNER_AGENCY_ACCOUNT_DELETE', 'users', $userId, 'Partner Agency account deleted');
            flash_set('success', 'Partner Agency account deleted.');
        }
        redirect('users.php?tab=partner-agencies');
    }
}
```

- [ ] **Step 3: Load Partner Agency accounts and add the tab UI**

Find:
```php
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-6" x-data="{ showCreate: false, editingId: null, resettingId: null }">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">System Users</h1>
      <p class="text-sm text-slate-500">Manage login accounts and role-based permissions.</p>
    </div>
    <button @click="showCreate = !showCreate" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-user-plus"></i> New User
    </button>
  </div>
```
Replace with:
```php
$users = $pdo->query("SELECT * FROM users WHERE role <> 'Partner Agency' ORDER BY created_at DESC")->fetchAll();
$agencyAccounts = $pdo->query(
    "SELECT u.id, u.username, u.status AS account_status, u.created_at,
            pa.agency_name, pa.contact_person, pa.contact_no, pa.email, pa.status AS agency_status
     FROM users u
     JOIN partner_agencies pa ON pa.id = u.agency_id
     WHERE u.role = 'Partner Agency'
     ORDER BY u.created_at DESC"
)->fetchAll();
$initialTab = ($_GET['tab'] ?? '') === 'partner-agencies' ? 'partner-agencies' : 'users';

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-6" x-data="{ tab: '<?= $initialTab ?>', showCreate: false, editingId: null, resettingId: null }">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Manage Users</h1>
      <p class="text-sm text-slate-500">Manage login accounts, role-based permissions, and Partner Agency approvals.</p>
    </div>
    <button x-show="tab === 'users'" @click="showCreate = !showCreate" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-user-plus"></i> New User
    </button>
  </div>

  <div class="flex gap-2 border-b border-slate-200">
    <button @click="tab = 'users'" :class="tab==='users' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">
      System Users
    </button>
    <button @click="tab = 'partner-agencies'" :class="tab==='partner-agencies' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">
      Partner Agency Accounts
      <?php $pendingCount = count(array_filter($agencyAccounts, fn($a) => $a['account_status'] === 'Pending')); ?>
      <?php if ($pendingCount > 0): ?>
        <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800"><?= $pendingCount ?></span>
      <?php endif; ?>
    </button>
  </div>

  <div x-show="tab === 'partner-agencies'" x-cloak class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <table class="min-w-full text-sm responsive-cards">
      <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
        <tr>
          <th class="px-4 py-2.5 text-left">Agency Name</th>
          <th class="px-4 py-2.5 text-left">Contact Person</th>
          <th class="px-4 py-2.5 text-left">Contact No</th>
          <th class="px-4 py-2.5 text-left">Email</th>
          <th class="px-4 py-2.5 text-left">Username</th>
          <th class="px-4 py-2.5 text-left">Registered</th>
          <th class="px-4 py-2.5 text-left">Account Status</th>
          <th class="px-4 py-2.5 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php if (!$agencyAccounts): ?>
          <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-building text-2xl mb-2 block"></i> No Partner Agency registrations yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($agencyAccounts as $a):
          $statusColors = ['Pending' => 'bg-amber-100 text-amber-800', 'Active' => 'bg-green-100 text-green-700', 'Disabled' => 'bg-gray-100 text-gray-600'];
        ?>
        <tr>
          <td class="px-4 py-2.5 font-medium" data-label="Agency"><?= e($a['agency_name']) ?></td>
          <td class="px-4 py-2.5" data-label="Contact Person"><?= e($a['contact_person'] ?: '—') ?></td>
          <td class="px-4 py-2.5" data-label="Contact No"><?= e($a['contact_no'] ?: '—') ?></td>
          <td class="px-4 py-2.5" data-label="Email"><?= e($a['email'] ?: '—') ?></td>
          <td class="px-4 py-2.5" data-label="Username"><?= e($a['username']) ?></td>
          <td class="px-4 py-2.5 text-slate-500" data-label="Registered"><?= format_date($a['created_at']) ?></td>
          <td class="px-4 py-2.5" data-label="Status">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statusColors[$a['account_status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= e($a['account_status']) ?></span>
            <?php if ($a['agency_status'] === 'Disabled'): ?>
              <span class="block text-[10px] text-red-500 mt-0.5">Agency record disabled</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-2.5 text-right" data-label="Actions">
            <?php if ($a['account_status'] === 'Pending'): ?>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="activate_agency">
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="px-2.5 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-medium">Activate</button>
              </form>
            <?php elseif ($a['account_status'] === 'Active'): ?>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="disable_agency">
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="px-2.5 py-1 rounded-lg border border-slate-300 hover:bg-slate-50 text-xs font-medium">Disable</button>
              </form>
            <?php else: ?>
              <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reenable_agency">
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium">Re-enable</button>
              </form>
            <?php endif; ?>
            <form method="POST" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_agency_account">
              <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
              <button type="button" data-confirm-delete="<?= e($a['agency_name'] . ' (' . $a['username'] . ')') ?>" class="text-slate-500 hover:text-red-600 px-1.5" title="Delete Account"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div x-show="tab === 'users'" x-cloak>
```

- [ ] **Step 4: Close the new `x-show="tab === 'users'"` wrapper and fix the status badge**

Find:
```php
          <td class="px-4 py-2.5" data-label="Status">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $u['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
              <?= $u['is_active'] ? 'Active' : ($u['role'] === 'Viewer' ? 'Pending/Disabled' : 'Disabled') ?>
            </span>
          </td>
```
Replace with:
```php
          <td class="px-4 py-2.5" data-label="Status">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $u['status'] === 'Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
              <?= e($u['status']) ?>
            </span>
          </td>
```

Find the very end of the file:
```php
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```
Replace with:
```php
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```
(This closes the `x-show="tab === 'users'"` div opened in Step 3, wrapping the entire existing System Users table + create form + inline edit/reset rows.)

- [ ] **Step 5: Syntax check**

Run: `/c/xampp/php/php.exe -l public/users.php`
Expected: "No syntax errors detected"

- [ ] **Step 6: Verify via curl (admin session + reauth + activate/disable/delete)**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)

# Seed a Pending Partner Agency account directly for a clean, deterministic test
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username='tabtest_pa';
DELETE FROM partner_agencies WHERE agency_name='Tab Test Agency';
INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email, status) VALUES ('Tab Test Agency','Addr','CP','0900000001','tabtest@example.com','Active');
"
AGENCY_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Tab Test Agency';")
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
INSERT INTO users (username, password, full_name, role, status, is_active, agency_id)
VALUES ('tabtest_pa', '\$2y\$10\$abcdefghijklmnopqrstuv', 'CP', 'Partner Agency', 'Pending', 0, $AGENCY_ID);
"
# (password hash above is a placeholder — this test only needs the account to exist for admin actions, not to log in as it)

# Log in as admin
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" \
  http://localhost:8899/login.php -o /dev/null

# Step-up reauth for /users.php
CSRF2=$(curl -s -b "$COOKIE" -c "$COOKIE" http://localhost:8899/users.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "action=reauth_confirm" --data-urlencode "csrf_token=$CSRF2" \
  --data-urlencode "password=Admin@123" http://localhost:8899/users.php -o /dev/null

# Confirm the tab and pending row render
USERS_PAGE=$(curl -s -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/users.php?tab=partner-agencies")
echo "$USERS_PAGE" | grep -q "Tab Test Agency" && echo "PASS - pending agency row rendered"

# Activate it
CSRF3=$(echo "$USERS_PAGE" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
PA_USER_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM users WHERE username='tabtest_pa';")
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "action=activate_agency" --data-urlencode "csrf_token=$CSRF3" \
  --data-urlencode "user_id=$PA_USER_ID" http://localhost:8899/users.php -o /dev/null

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "SELECT status, is_active FROM users WHERE id=$PA_USER_ID;"
# Expected: status=Active, is_active=1

kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "DELETE FROM users WHERE username='tabtest_pa'; DELETE FROM partner_agencies WHERE agency_name='Tab Test Agency';"
```
Expected: "PASS - pending agency row rendered"; final SELECT shows `status=Active, is_active=1`.

- [ ] **Step 7: Commit**

```bash
git add public/users.php
git commit -m "feat: Partner Agency Accounts tab in User Management (activate/disable/re-enable/delete)"
```

---

## Task 7: `public/dashboard.php` — pending-registrations notice + Partner Agency dashboard

**Files:**
- Modify: `public/dashboard.php`

**Interfaces:**
- Consumes: `is_partner_agency()`, `current_agency_id()` (Task 2).

- [ ] **Step 1: Add the Partner Agency early-exit branch and the Administrator pending-count query**

Find:
```php
$pdo = Database::getConnection();

// ---- Summary counts ----
$totalApplicants = (int)$pdo->query("SELECT COUNT(*) FROM applicants WHERE is_deleted = 0")->fetchColumn();
```
Replace with:
```php
$pdo = Database::getConnection();

if (is_partner_agency()) {
    $agencyId = current_agency_id($pdo);

    $agencyStmt = $pdo->prepare("SELECT * FROM partner_agencies WHERE id = :id");
    $agencyStmt->execute([':id' => $agencyId]);
    $agency = $agencyStmt->fetch();

    // Shared applicant pool stats (every Partner Agency sees the same
    // system-wide numbers here — the pool itself is shared, per the
    // "Partner Agency can view registered applicants" rule).
    $totalApplicantsPool = (int)$pdo->query("SELECT COUNT(*) FROM applicants WHERE is_deleted = 0")->fetchColumn();
    $notHiredPool = (int)$pdo->query(
        "SELECT COUNT(*) FROM applicants a
         LEFT JOIN employment_records er ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'
         WHERE a.is_deleted = 0 AND er.id IS NULL"
    )->fetchColumn();

    // Own-agency stat: applicants this agency has actually hired.
    $hiredByAgencyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM employment_records WHERE agency_id = :aid AND is_current = 1 AND status = 'Active'"
    );
    $hiredByAgencyStmt->execute([':aid' => $agencyId]);
    $hiredByAgencyCount = (int)$hiredByAgencyStmt->fetchColumn();

    $pageTitle = 'Dashboard';
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    ?>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-800">My Agency</h1>
      <p class="text-sm text-slate-500"><?= e($agency['agency_name']) ?></p>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <?php
      $paCards = [
          ['label' => 'Total Registered Applicants',  'value' => $totalApplicantsPool, 'icon' => 'fa-users',          'color' => 'text-brand-600 bg-brand-50'],
          ['label' => 'Available for Recruitment',     'value' => $notHiredPool,        'icon' => 'fa-hourglass-half', 'color' => 'text-gray-600 bg-gray-100'],
          ['label' => 'Applicants Not Hired',           'value' => $notHiredPool,        'icon' => 'fa-user-clock',     'color' => 'text-gray-600 bg-gray-100'],
          ['label' => 'Hired by This Agency',           'value' => $hiredByAgencyCount,  'icon' => 'fa-briefcase',      'color' => 'text-green-600 bg-green-50'],
      ];
      foreach ($paCards as $c): ?>
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-lg flex items-center justify-center <?= $c['color'] ?>">
          <i class="fa-solid <?= $c['icon'] ?>"></i>
        </div>
        <div>
          <p class="text-xs text-slate-500 font-medium"><?= e($c['label']) ?></p>
          <p class="text-xl font-bold text-slate-800"><?= number_format($c['value']) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5 mb-8">
      <p class="text-sm text-slate-600">
        Browse the full applicant pool under <a href="applicants.php" class="text-brand-600 font-medium hover:underline">Applicants</a>,
        or view your agency's profile under <a href="my-agency.php" class="text-brand-600 font-medium hover:underline">My Partner Agency</a>.
      </p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$pendingAgencyCount = can_manage_users()
    ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Partner Agency' AND status = 'Pending'")->fetchColumn()
    : 0;

// ---- Summary counts ----
$totalApplicants = (int)$pdo->query("SELECT COUNT(*) FROM applicants WHERE is_deleted = 0")->fetchColumn();
```

- [ ] **Step 2: Insert the pending-registrations notice above the summary cards**

Find:
```php
<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
```
Replace with:
```php
<?php if ($pendingAgencyCount > 0): ?>
<a href="users.php?tab=partner-agencies" class="block mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 hover:bg-amber-100 transition">
  <i class="fa-solid fa-building-circle-exclamation mr-2"></i>
  Pending Partner Agency Registrations: <?= $pendingAgencyCount ?> — click to review.
</a>
<?php endif; ?>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
```

- [ ] **Step 3: Syntax check**

Run: `/c/xampp/php/php.exe -l public/dashboard.php`
Expected: "No syntax errors detected"

- [ ] **Step 4: Verify via curl (admin sees notice; Partner Agency sees own dashboard)**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1

# Seed one Pending and one Active Partner Agency for the two checks below
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username IN ('dashtest_pending','dashtest_active');
DELETE FROM partner_agencies WHERE agency_name IN ('Dash Pending Agency','Dash Active Agency');
INSERT INTO partner_agencies (agency_name, address, status) VALUES ('Dash Pending Agency','Addr','Active'), ('Dash Active Agency','Addr','Active');
"
PENDING_AGENCY_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Dash Pending Agency';")
ACTIVE_AGENCY_ID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Dash Active Agency';")
HASH=$(/c/xampp/php/php.exe -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
INSERT INTO users (username, password, full_name, role, status, is_active, agency_id) VALUES
('dashtest_pending', '$HASH', 'CP', 'Partner Agency', 'Pending', 0, $PENDING_AGENCY_ID),
('dashtest_active',  '$HASH', 'CP', 'Partner Agency', 'Active',  1, $ACTIVE_AGENCY_ID);
"

# Admin sees the pending notice
ACOOKIE=$(mktemp)
CSRF=$(curl -s -c "$ACOOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$ACOOKIE" -c "$ACOOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null
curl -s -b "$ACOOKIE" -c "$ACOOKIE" http://localhost:8899/dashboard.php | grep -q "Pending Partner Agency Registrations" && echo "PASS - admin sees pending notice"

# Active Partner Agency sees its own dashboard
PCOOKIE=$(mktemp)
CSRF2=$(curl -s -c "$PCOOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$PCOOKIE" -c "$PCOOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF2" \
  --data-urlencode "username=dashtest_active" --data-urlencode "password=TestPass123" http://localhost:8899/login.php -o /dev/null
PA_DASH=$(curl -s -b "$PCOOKIE" -c "$PCOOKIE" http://localhost:8899/dashboard.php)
echo "$PA_DASH" | grep -q "My Agency" && echo "PASS - agency dashboard rendered"
echo "$PA_DASH" | grep -q "Dash Active Agency" && echo "PASS - own agency name shown"

kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username IN ('dashtest_pending','dashtest_active');
DELETE FROM partner_agencies WHERE agency_name IN ('Dash Pending Agency','Dash Active Agency');
"
```
Expected: three `PASS` lines.

- [ ] **Step 5: Commit**

```bash
git add public/dashboard.php
git commit -m "feat: agency-scoped Partner Agency dashboard + pending-registrations admin notice"
```

---

## Task 8: `includes/sidebar.php` — Partner Agency navigation

**Files:**
- Modify: `includes/sidebar.php`

**Interfaces:**
- Consumes: `is_partner_agency()` (Task 2).

- [ ] **Step 1: Branch the nav items by role**

Find:
```php
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
// Employment section highlights for both the list and the add/edit form
$employmentPages = ['employment-list.php', 'employment-form.php'];
$agencyPages = ['partner-agency.php', 'partner-agency-form.php'];

$navItems = [
    ['href' => 'dashboard.php',       'icon' => 'fa-gauge-high',   'label' => 'Dashboard',       'match' => ['dashboard.php']],
    ['href' => 'applicants.php',      'icon' => 'fa-users',        'label' => 'Applicants',      'match' => ['applicants.php', 'applicant-create.php', 'applicant-edit.php', 'applicant-view.php']],
    ['href' => 'employment-list.php', 'icon' => 'fa-briefcase',    'label' => 'Employment',      'match' => $employmentPages],
    ['href' => 'partner-agency.php',  'icon' => 'fa-building',     'label' => 'Partner Agency',  'match' => $agencyPages],
    ['href' => 'reports.php',         'icon' => 'fa-chart-column', 'label' => 'Reports',         'match' => ['reports.php']],
];
```
Replace with:
```php
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
// Employment section highlights for both the list and the add/edit form
$employmentPages = ['employment-list.php', 'employment-form.php'];
$agencyPages = ['partner-agency.php', 'partner-agency-form.php'];

if (is_partner_agency()) {
    // Partner Agency: no Employment module, no cross-agency Partner Agency
    // management page — their own profile lives at my-agency.php instead.
    $navItems = [
        ['href' => 'dashboard.php',  'icon' => 'fa-gauge-high',    'label' => 'Dashboard',         'match' => ['dashboard.php']],
        ['href' => 'applicants.php', 'icon' => 'fa-users',         'label' => 'Applicants',        'match' => ['applicants.php', 'applicant-view.php']],
        ['href' => 'my-agency.php',  'icon' => 'fa-building',      'label' => 'My Partner Agency', 'match' => ['my-agency.php']],
        ['href' => 'reports.php',    'icon' => 'fa-chart-column',  'label' => 'Reports',           'match' => ['reports.php']],
    ];
} else {
    $navItems = [
        ['href' => 'dashboard.php',       'icon' => 'fa-gauge-high',   'label' => 'Dashboard',       'match' => ['dashboard.php']],
        ['href' => 'applicants.php',      'icon' => 'fa-users',        'label' => 'Applicants',      'match' => ['applicants.php', 'applicant-create.php', 'applicant-edit.php', 'applicant-view.php']],
        ['href' => 'employment-list.php', 'icon' => 'fa-briefcase',    'label' => 'Employment',      'match' => $employmentPages],
        ['href' => 'partner-agency.php',  'icon' => 'fa-building',     'label' => 'Partner Agency',  'match' => $agencyPages],
        ['href' => 'reports.php',         'icon' => 'fa-chart-column', 'label' => 'Reports',         'match' => ['reports.php']],
    ];
}
```

- [ ] **Step 2: Syntax check**

Run: `/c/xampp/php/php.exe -l includes/sidebar.php`
Expected: "No syntax errors detected"

- [ ] **Step 3: Verify via curl**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username='sbtest_pa';
DELETE FROM partner_agencies WHERE agency_name='Sidebar Test Agency';
INSERT INTO partner_agencies (agency_name, address, status) VALUES ('Sidebar Test Agency','Addr','Active');
"
AID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Sidebar Test Agency';")
HASH=$(/c/xampp/php/php.exe -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
INSERT INTO users (username, password, full_name, role, status, is_active, agency_id) VALUES ('sbtest_pa','$HASH','CP','Partner Agency','Active',1,$AID);
"
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=sbtest_pa" --data-urlencode "password=TestPass123" http://localhost:8899/login.php -o /dev/null
NAV=$(curl -s -b "$COOKIE" -c "$COOKIE" http://localhost:8899/dashboard.php)
echo "$NAV" | grep -q "My Partner Agency" && echo "PASS - agency sidebar item present"
echo "$NAV" | grep -qv ">Employment<" && echo "PASS - Employment nav item absent for Partner Agency"
echo "$NAV" | grep -qv ">Users<" && echo "PASS - Users nav item absent for Partner Agency"
kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "DELETE FROM users WHERE username='sbtest_pa'; DELETE FROM partner_agencies WHERE agency_name='Sidebar Test Agency';"
```
Expected: three `PASS` lines.

- [ ] **Step 4: Commit**

```bash
git add includes/sidebar.php
git commit -m "feat: role-specific sidebar for Partner Agency accounts"
```

---

## Task 9: New page `public/my-agency.php`

**Files:**
- Create: `public/my-agency.php`

**Interfaces:**
- Consumes: `current_agency_id()` (Task 2), `agency_name_taken()`, `is_valid_email()` (Task 3/existing).

- [ ] **Step 1: Write the file**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Partner Agency']);

$pdo = Database::getConnection();
$agencyId = current_agency_id($pdo);
$errors = [];

$stmt = $pdo->prepare("SELECT * FROM partner_agencies WHERE id = :id");
$stmt->execute([':id' => $agencyId]);
$agency = $stmt->fetch();

if (!$agency) {
    flash_set('error', 'Your agency record could not be found. Please contact the Administrator.');
    redirect('dashboard.php');
}

$userStmt = $pdo->prepare("SELECT username, status, created_at FROM users WHERE id = :id");
$userStmt->execute([':id' => current_user()['id']]);
$accountRow = $userStmt->fetch();

$old = $agency;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['Partner Agency']);
    csrf_require();

    $old['agency_name']    = clean($_POST['agency_name'] ?? '');
    $old['address']        = clean($_POST['address'] ?? '');
    $old['contact_person'] = clean($_POST['contact_person'] ?? '');
    $old['contact_no']     = clean($_POST['contact_no'] ?? '');
    $old['email']          = clean($_POST['email'] ?? '');

    if ($old['agency_name'] === '')    $errors['agency_name'] = 'Agency Name is required.';
    if ($old['address'] === '')        $errors['address'] = 'Address is required.';
    if ($old['contact_person'] === '') $errors['contact_person'] = 'Contact Person is required.';
    if ($old['contact_no'] === '')     $errors['contact_no'] = 'Contact No is required.';
    if ($old['email'] !== '' && !is_valid_email($old['email'])) $errors['email'] = 'Enter a valid email address.';

    if (!$errors && agency_name_taken($pdo, $old['agency_name'], $agencyId)) {
        $errors['agency_name'] = 'A Partner Agency with this name already exists.';
    }

    if (!$errors) {
        // $agencyId is server-derived (current_agency_id()) — a Partner
        // Agency user cannot target another agency's record no matter
        // what this form posts.
        $pdo->prepare(
            "UPDATE partner_agencies SET agency_name = :n, address = :a, contact_person = :cp, contact_no = :cn, email = :e
             WHERE id = :id"
        )->execute([
            ':n' => $old['agency_name'], ':a' => $old['address'], ':cp' => $old['contact_person'],
            ':cn' => $old['contact_no'], ':e' => $old['email'] ?: null, ':id' => $agencyId,
        ]);
        audit_log($pdo, (int)current_user()['id'], 'PARTNER_AGENCY_PROFILE_UPDATE', 'partner_agencies', $agencyId, 'Partner Agency profile updated');
        flash_set('success', 'Partner Agency profile updated successfully.');
        redirect('my-agency.php');
    }
}

$pageTitle = 'My Partner Agency';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-bold text-slate-800 mb-1">My Partner Agency</h1>
  <p class="text-sm text-slate-500 mb-6">View and update your agency's profile.</p>

  <form method="POST" onsubmit="return validateForm(this) && confirm('Save changes to your agency profile?');">
    <?= csrf_field() ?>
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 space-y-4">
      <div class="grid sm:grid-cols-2 gap-3 text-sm border-b border-slate-100 pb-4 mb-2">
        <div><dt class="text-slate-500 inline">Account Username:</dt> <dd class="font-medium text-slate-800 inline"><?= e($accountRow['username']) ?></dd></div>
        <div><dt class="text-slate-500 inline">Account Status:</dt> <dd class="font-medium text-slate-800 inline"><?= e($accountRow['status']) ?></dd></div>
        <div class="sm:col-span-2"><dt class="text-slate-500 inline">Registration Date:</dt> <dd class="font-medium text-slate-800 inline"><?= format_date($accountRow['created_at']) ?></dd></div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Agency Name <span class="text-red-500">*</span></label>
        <input type="text" name="agency_name" required value="<?= e($old['agency_name']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1" data-error-for="agency_name"><?= e($errors['agency_name'] ?? '') ?></p>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-500">*</span></label>
        <textarea name="address" required rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><?= e($old['address']) ?></textarea>
        <p class="text-xs text-red-500 mt-1" data-error-for="address"><?= e($errors['address'] ?? '') ?></p>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact Person <span class="text-red-500">*</span></label>
          <input type="text" name="contact_person" required value="<?= e($old['contact_person']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1" data-error-for="contact_person"><?= e($errors['contact_person'] ?? '') ?></p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contact No <span class="text-red-500">*</span></label>
          <input type="text" name="contact_no" required value="<?= e($old['contact_no']) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-red-500 mt-1" data-error-for="contact_no"><?= e($errors['contact_no'] ?? '') ?></p>
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
        <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="text-xs text-red-500 mt-1" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
      </div>
    </div>
    <div class="flex justify-end gap-3 mt-5">
      <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-sm">
        <i class="fa-solid fa-floppy-disk mr-1"></i> Save Changes
      </button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```

- [ ] **Step 2: Syntax check**

Run: `/c/xampp/php/php.exe -l public/my-agency.php`
Expected: "No syntax errors detected"

- [ ] **Step 3: Verify via curl — own-agency view, edit, and cross-agency IDOR check**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username IN ('myagency_a','myagency_b');
DELETE FROM partner_agencies WHERE agency_name IN ('My Agency A','My Agency B');
INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, status) VALUES
('My Agency A','Addr A','Alice','0900000001','Active'),
('My Agency B','Addr B','Bob','0900000002','Active');
"
AID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='My Agency A';")
BID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='My Agency B';")
HASH=$(/c/xampp/php/php.exe -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
INSERT INTO users (username, password, full_name, role, status, is_active, agency_id) VALUES
('myagency_a','$HASH','Alice','Partner Agency','Active',1,$AID),
('myagency_b','$HASH','Bob','Partner Agency','Active',1,$BID);
"

# Log in as Agency A
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=myagency_a" --data-urlencode "password=TestPass123" http://localhost:8899/login.php -o /dev/null

PAGE=$(curl -s -b "$COOKIE" -c "$COOKIE" http://localhost:8899/my-agency.php)
echo "$PAGE" | grep -q "My Agency A" && echo "PASS - Agency A sees its own name"
echo "$PAGE" | grep -qv "My Agency B" && echo "PASS - Agency A does NOT see Agency B anywhere on the page"

# Edit own profile (change contact_no)
CSRF2=$(echo "$PAGE" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "agency_name=My Agency A" --data-urlencode "address=Addr A Updated" \
  --data-urlencode "contact_person=Alice" --data-urlencode "contact_no=0911111111" --data-urlencode "email=" \
  --data-urlencode "csrf_token=$CSRF2" http://localhost:8899/my-agency.php -o /dev/null
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "SELECT address, contact_no FROM partner_agencies WHERE id=$AID;"
# Expected: address=Addr A Updated, contact_no=0911111111 — confirms the edit hit the right row

# Confirm Agency B's record is untouched
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "SELECT address, contact_no FROM partner_agencies WHERE id=$BID;"
# Expected: address=Addr B, contact_no=0900000002 — unchanged

kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username IN ('myagency_a','myagency_b');
DELETE FROM partner_agencies WHERE agency_name IN ('My Agency A','My Agency B');
"
```
Expected: two `PASS` lines; the two `SELECT`s confirm A's row changed and B's row is untouched — since the page takes no `id` parameter at all, there is no cross-agency vector to test beyond this.

- [ ] **Step 4: Commit**

```bash
git add public/my-agency.php
git commit -m "feat: My Partner Agency profile page (view + self-scoped edit)"
```

---

## Task 10: `public/reports.php` — agency-scoped reports for Partner Agency

**Files:**
- Modify: `public/reports.php`

**Interfaces:**
- Consumes: `is_partner_agency()`, `current_agency_id()` (Task 2).

- [ ] **Step 1: Scope report options and query**

Find:
```php
$pdo = Database::getConnection();

$reportType = clean($_GET['report_type'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');
$results = null;
$reportLabel = '';

$reportOptions = [
```
Replace with:
```php
$pdo = Database::getConnection();
$scopedAgencyId = is_partner_agency() ? current_agency_id($pdo) : null;

$reportType = clean($_GET['report_type'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');
$results = null;
$reportLabel = '';

$reportOptions = [
```

Find:
```php
if ($reportType && isset($reportOptions[$reportType])) {
    $reportLabel = $reportOptions[$reportType];
```
Replace with:
```php
// Partner Agency accounts never see the cross-agency "by_agency" report —
// they're already scoped to one agency, so it would be meaningless.
if ($scopedAgencyId !== null) {
    unset($reportOptions['by_agency']);
}

if ($reportType && isset($reportOptions[$reportType])) {
    $reportLabel = $reportOptions[$reportType];
```

Find:
```php
    $params = [];

    switch ($reportType) {
```
Replace with:
```php
    $params = [];
    if ($scopedAgencyId !== null) {
        // Own-agency-only, enforced server-side — never trust a request
        // parameter for this; it's always current_agency_id().
        $base .= " AND er.agency_id = :scoped_agency_id";
        $params[':scoped_agency_id'] = $scopedAgencyId;
    }

    switch ($reportType) {
```

- [ ] **Step 2: Syntax check**

Run: `/c/xampp/php/php.exe -l public/reports.php`
Expected: "No syntax errors detected"

- [ ] **Step 3: Verify via curl — agency-scoped counts vs. admin's full counts**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1

/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username='rpttest_pa';
DELETE FROM partner_agencies WHERE agency_name='Report Test Agency';
INSERT INTO partner_agencies (agency_name, address, status) VALUES ('Report Test Agency','Addr','Active');
"
AID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Report Test Agency';")
HASH=$(/c/xampp/php/php.exe -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
INSERT INTO users (username, password, full_name, role, status, is_active, agency_id) VALUES ('rpttest_pa','$HASH','CP','Partner Agency','Active',1,$AID);
"

COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=rpttest_pa" --data-urlencode "password=TestPass123" http://localhost:8899/login.php -o /dev/null

REPORT=$(curl -s -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/reports.php?report_type=hired")
echo "$REPORT" | grep -q "0 record(s)" && echo "PASS - agency with no hires sees 0 records on the 'hired' report"
echo "$REPORT" | grep -qv "by_agency" && echo "PASS - by_agency option not offered to Partner Agency"

kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "DELETE FROM users WHERE username='rpttest_pa'; DELETE FROM partner_agencies WHERE agency_name='Report Test Agency';"
```
Expected: two `PASS` lines (the seeded agency has zero hires, so the scoped "hired" report correctly returns nothing even though the global applicant pool has hires from the seed data in `database.sql`).

- [ ] **Step 4: Commit**

```bash
git add public/reports.php
git commit -m "feat: scope Reports module to the logged-in agency for Partner Agency accounts"
```

---

## Task 11: Block Partner Agency from the Employment module

**Files:**
- Modify: `public/employment-list.php`

**Interfaces:**
- Consumes: `is_partner_agency()` (Task 2).

- [ ] **Step 1: Add the guard**

Find:
```php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
// Server-side enforcement: Viewer may look at this page (read-only), but has
// no write actions available anywhere below. Manage actions are gated per-button.
```
Replace with:
```php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
// Server-side enforcement: Viewer may look at this page (read-only), but has
// no write actions available anywhere below. Manage actions are gated per-button.
// Partner Agency accounts don't get this module at all (not just hidden from
// their sidebar) — they see read-only employment history via an applicant's
// own profile page instead.
if (is_partner_agency()) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif">403 — Partner Agency accounts do not have access to the Employment module.</h2>');
}
```

- [ ] **Step 2: Syntax check**

Run: `/c/xampp/php/php.exe -l public/employment-list.php`
Expected: "No syntax errors detected"

- [ ] **Step 3: Verify via curl**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username='emptest_pa';
DELETE FROM partner_agencies WHERE agency_name='Emp Test Agency';
INSERT INTO partner_agencies (agency_name, address, status) VALUES ('Emp Test Agency','Addr','Active');
"
AID=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM partner_agencies WHERE agency_name='Emp Test Agency';")
HASH=$(/c/xampp/php/php.exe -r "echo password_hash('TestPass123', PASSWORD_DEFAULT);")
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
INSERT INTO users (username, password, full_name, role, status, is_active, agency_id) VALUES ('emptest_pa','$HASH','CP','Partner Agency','Active',1,$AID);
"
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=emptest_pa" --data-urlencode "password=TestPass123" http://localhost:8899/login.php -o /dev/null
curl -s -o /dev/null -w "%{http_code}\n" -b "$COOKIE" -c "$COOKIE" http://localhost:8899/employment-list.php
kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "DELETE FROM users WHERE username='emptest_pa'; DELETE FROM partner_agencies WHERE agency_name='Emp Test Agency';"
```
Expected: `403`

- [ ] **Step 4: Commit**

```bash
git add public/employment-list.php
git commit -m "feat: block Partner Agency accounts from the Employment module server-side"
```

---

## Task 12: End-to-end cross-agency isolation check + `applicants.php` role confirmation

**Files:** none modified — verification-only task confirming Tasks 1–11 compose correctly. (`applicants.php`/`applicant-view.php`/`api/applicants.php` need no code changes: they already use `require_login()` only, so a logged-in Partner Agency account already gets read access, and `can_edit()`/`can_delete()` — unmodified — already exclude the `Partner Agency` role from every edit/delete control and POST handler on those pages.)

**Interfaces:** none.

- [ ] **Step 1: Confirm `can_edit()`/`can_delete()` still exclude Partner Agency**

Run: `/c/xampp/php/php.exe -r "require 'C:/xampp/htdocs/applicant-system/includes/auth.php'; \$_SESSION['role']='Partner Agency'; var_dump(can_edit(), can_delete());"`
Expected: `bool(false)` twice.

- [ ] **Step 2: Full two-agency scenario over HTTP**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/pa_server.log 2>&1 &
sleep 1

# Register Agency A and Agency B through the real public registration flow
COOKIE_A=$(mktemp)
CSRF_A=$(curl -s -c "$COOKIE_A" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE_A" -c "$COOKIE_A" --data-urlencode "form=register_agency" --data-urlencode "csrf_token=$CSRF_A" \
  --data-urlencode "agency_name=E2E Agency A" --data-urlencode "address=Addr A" --data-urlencode "contact_person=A Person" \
  --data-urlencode "contact_no=0921111111" --data-urlencode "email=e2ea@example.com" --data-urlencode "username=e2e_agency_a" \
  --data-urlencode "password=TestPass123" --data-urlencode "confirm_password=TestPass123" http://localhost:8899/login.php -o /dev/null

COOKIE_B=$(mktemp)
CSRF_B=$(curl -s -c "$COOKIE_B" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE_B" -c "$COOKIE_B" --data-urlencode "form=register_agency" --data-urlencode "csrf_token=$CSRF_B" \
  --data-urlencode "agency_name=E2E Agency B" --data-urlencode "address=Addr B" --data-urlencode "contact_person=B Person" \
  --data-urlencode "contact_no=0922222222" --data-urlencode "email=e2eb@example.com" --data-urlencode "username=e2e_agency_b" \
  --data-urlencode "password=TestPass123" --data-urlencode "confirm_password=TestPass123" http://localhost:8899/login.php -o /dev/null

# Admin activates both
ACOOKIE=$(mktemp)
CSRF=$(curl -s -c "$ACOOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$ACOOKIE" -c "$ACOOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null
CSRF2=$(curl -s -b "$ACOOKIE" -c "$ACOOKIE" http://localhost:8899/users.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$ACOOKIE" -c "$ACOOKIE" --data-urlencode "action=reauth_confirm" --data-urlencode "csrf_token=$CSRF2" \
  --data-urlencode "password=Admin@123" http://localhost:8899/users.php -o /dev/null
IDS=$(/c/xampp/mysql/bin/mysql.exe -u root applicant_system -N -e "SELECT id FROM users WHERE username IN ('e2e_agency_a','e2e_agency_b');")
for UID in $IDS; do
  CSRF3=$(curl -s -b "$ACOOKIE" -c "$ACOOKIE" "http://localhost:8899/users.php?tab=partner-agencies" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
  curl -s -b "$ACOOKIE" -c "$ACOOKIE" --data-urlencode "action=activate_agency" --data-urlencode "csrf_token=$CSRF3" \
    --data-urlencode "user_id=$UID" http://localhost:8899/users.php -o /dev/null
done

# Now both can log in
CSRF_A2=$(curl -s -c "$COOKIE_A" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE_A" -c "$COOKIE_A" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF_A2" \
  --data-urlencode "username=e2e_agency_a" --data-urlencode "password=TestPass123" http://localhost:8899/login.php -o /dev/null

MY_A=$(curl -s -b "$COOKIE_A" -c "$COOKIE_A" http://localhost:8899/my-agency.php)
echo "$MY_A" | grep -q "E2E Agency A" && echo "PASS - Agency A logged in, sees own name"
echo "$MY_A" | grep -qv "E2E Agency B" && echo "PASS - Agency A's page never mentions Agency B"

DASH_A=$(curl -s -b "$COOKIE_A" -c "$COOKIE_A" http://localhost:8899/dashboard.php)
echo "$DASH_A" | grep -q "My Agency" && echo "PASS - Agency A gets the agency dashboard, not the admin one"

# Applicants list is still visible (shared pool)
curl -s -o /dev/null -w "%{http_code}\n" -b "$COOKIE_A" -c "$COOKIE_A" http://localhost:8899/applicants.php

# Cleanup
kill %1
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
DELETE FROM users WHERE username IN ('e2e_agency_a','e2e_agency_b');
DELETE FROM partner_agencies WHERE agency_name IN ('E2E Agency A','E2E Agency B');
"
```
Expected: three `PASS` lines; the `applicants.php` status code is `200`.

- [ ] **Step 3: No commit needed** (verification-only task; nothing to add to git beyond what earlier tasks already committed).

---

## Post-plan note

Job Vacancies (prompt sections 12–13, 27–28) and the Partner Agency Summary report are **explicitly out of scope** for this plan — see design doc §13. They get their own spec + plan once this phase is reviewed and merged.
