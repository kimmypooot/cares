# Multi-User Partner Agency Accounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let one Partner Agency have multiple login accounts, all with identical permissions over that agency's own data, with the original registration account labeled (not privileged) and a safeguard against removing the agency's last active user.

**Architecture:** No new authentication system and no schema change to the agency relationship itself (`care_jf_users.agency_id` already supports many users per agency). Add one display-only flag column (`is_primary`), one new Partner-Agency-only self-service page (`public/agency-users.php`) that mirrors this codebase's existing CRUD/modal conventions exactly, one new safeguard helper in `includes/auth.php`, and a small display fix to the Administrator's existing Manage Users page.

**Tech Stack:** PHP 8 (no framework), PDO/MySQL, Tailwind (prebuilt CSS, no build step needed for this plan), Alpine.js for the modal/inline-edit interactivity — all matching the existing codebase exactly. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-09-08-multi-user-partner-agency-accounts-design.md`

## Global Constraints

- Every mutation must call `csrf_require()` (from `includes/csrf.php`) before touching the database, and every form must emit `csrf_field()`.
- `agency_id` is never read from `$_GET`/`$_POST`/any request field — always `current_agency_id($pdo)`, re-derived from the trusted session `user_id` on every request.
- Every mutation on an existing row re-derives that row's own `agency_id` from the database and calls the existing `require_own_agency_record($pdo, $recordAgencyId)` (in `includes/auth.php`) before acting — never trust the UI to have only shown the user's own rows.
- All new/changed dynamic output goes through `e()` (htmlspecialchars); all SQL goes through PDO prepared statements — no string-concatenated queries.
- Every state change calls the existing `audit_log($pdo, $userId, $action, $table, $recordId, $description)` — no second logging mechanism.
- New modals use the established non-dismissable pattern from this session's prior work: `x-show="..."` wrapped in `class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4"` with `@keydown.escape.window="...=false"`, and **no** `@click.outside` anywhere — clicking the backdrop must never close a form modal.
- `is_primary` is a **label only** — it must never appear in any permission check. Every active Partner Agency user for an agency has identical permissions.
- Never edit an already-shipped migration file — schema changes are new migration files only.
- There is no automated test suite in this repo (confirmed in `CLAUDE.md`). Every task's testing step uses `php -l`, direct MySQL assertions via `/c/xampp/mysql/bin/mysql.exe`, and `curl`-driven HTTP checks against the already-running local XAMPP Apache at `http://localhost/applicant-system/public/`.
- The live database currently holds only the `admin` account as permanent — everything else (including the two existing Partner Agency test accounts, `cscro8.esd_01` and `psa_01`) may be freely used, modified, or removed during testing; the user has explicitly authorized this.

---

### Task 1: Database migration — `is_primary` flag

**Files:**
- Create: `database/migrations/add_agency_primary_user_flag.sql`

**Interfaces:**
- Produces: `care_jf_users.is_primary` (`TINYINT(1) NOT NULL DEFAULT 0`) — Task 3 and Task 4 both read/write this column.

- [ ] **Step 1: Write the migration file**

```sql
-- =====================================================================
-- Migration: add_agency_primary_user_flag.sql
-- Adds care_jf_users.is_primary, identifying the original/initial
-- account for a Partner Agency now that an agency can have more than
-- one user. Display/audit label only — it must never gate a
-- permission check; every active user in an agency has identical
-- permissions. See
-- docs/superpowers/specs/2026-09-08-multi-user-partner-agency-accounts-design.md
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_agency_primary_user_flag.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_users
  ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER agency_id;

-- Every existing Partner Agency account today is, by definition, the
-- original/only account for its agency.
UPDATE care_jf_users SET is_primary = 1 WHERE role = 'Partner Agency';

SELECT 'Migration complete.' AS status;
SELECT id, username, role, agency_id, is_primary FROM care_jf_users WHERE role = 'Partner Agency';
```

- [ ] **Step 2: Apply the migration to the live database**

Run: `/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db < "database/migrations/add_agency_primary_user_flag.sql"`
Expected: the trailing `SELECT` shows every existing `role = 'Partner Agency'` row with `is_primary = 1`.

- [ ] **Step 3: Verify the column shape**

Run: `/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SHOW COLUMNS FROM care_jf_users WHERE Field='is_primary';"`
Expected: `tinyint(1)`, `NO` (not null), Default `0`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/add_agency_primary_user_flag.sql
git commit -m "Add is_primary flag to care_jf_users for multi-user Partner Agency accounts

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

### Task 2: `is_last_active_agency_user()` safeguard helper

**Files:**
- Modify: `includes/auth.php` (add the new function immediately after the existing `is_last_active_admin()` function)

**Interfaces:**
- Consumes: `care_jf_users.is_primary` (Task 1) — not read by this function, but exists in the same table it queries.
- Produces: `is_last_active_agency_user(PDO $pdo, int $userId): bool` — Task 3 calls this to block disabling an agency's only remaining active user.

- [ ] **Step 1: Locate the insertion point**

Open `includes/auth.php` and find the existing `is_last_active_admin()` function (it ends with a `return $count <= 1;` line inside a function that queries `WHERE role = 'Administrator' AND is_active = 1`). The new function goes directly after it.

- [ ] **Step 2: Add the new function**

```php
/** Safeguard: prevent removing/disabling the last remaining active user of a Partner Agency. */
function is_last_active_agency_user(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT agency_id, status FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
    $stmt->execute([':id' => $userId]);
    $target = $stmt->fetch();
    if (!$target || $target['status'] !== 'Active' || !$target['agency_id']) {
        return false;
    }
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM care_jf_users WHERE agency_id = :aid AND role = 'Partner Agency' AND status = 'Active'"
    );
    $countStmt->execute([':aid' => $target['agency_id']]);
    return (int)$countStmt->fetchColumn() <= 1;
}
```

- [ ] **Step 3: Lint**

Run: `& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\applicant-system\includes\auth.php"`
Expected: `No syntax errors detected`

- [ ] **Step 4: Write and run a standalone verification script**

This repo has no test framework — verify with a rolled-back transaction so no real data is touched. Create `C:\Users\CSCESD~1\AppData\Local\Temp\claude\C--xampp-htdocs-applicant-system\107f6109-8c66-4d75-acde-bfcc010e923a\scratchpad\verify_agency_safeguard.php` (adjust the scratchpad path to whatever this session's actual scratchpad directory is):

```php
<?php
declare(strict_types=1);
require_once 'C:/xampp/htdocs/applicant-system/includes/auth.php';

$pdo = Database::getConnection();
$pdo->beginTransaction();
try {
    $pdo->prepare(
        "INSERT INTO care_jf_partner_agencies (agency_name, address, contact_person, contact_no, status) VALUES ('TEST SAFEGUARD AGENCY', 'x', 'x', 'x', 'Active')"
    )->execute();
    $agencyId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO care_jf_users (username, password, full_name, role, status, agency_id, is_primary) VALUES ('test_safeguard_u1', 'x', 'U1', 'Partner Agency', 'Active', :a, 1)"
    )->execute([':a' => $agencyId]);
    $u1 = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO care_jf_users (username, password, full_name, role, status, agency_id, is_primary) VALUES ('test_safeguard_u2', 'x', 'U2', 'Partner Agency', 'Active', :a, 0)"
    )->execute([':a' => $agencyId]);
    $u2 = (int)$pdo->lastInsertId();

    echo "Two active users, u1 last-active? " . (is_last_active_agency_user($pdo, $u1) ? 'true' : 'false') . " (expect false)\n";

    $pdo->prepare("UPDATE care_jf_users SET status = 'Disabled' WHERE id = :id")->execute([':id' => $u2]);
    echo "One active left, u1 last-active? " . (is_last_active_agency_user($pdo, $u1) ? 'true' : 'false') . " (expect true)\n";

    echo "Disabled user (u2) counted as last-active? " . (is_last_active_agency_user($pdo, $u2) ? 'true' : 'false') . " (expect false — only checks currently-Active rows)\n";
} finally {
    $pdo->rollBack();
}
```

Run: `& "C:\xampp\php\php.exe" -f "<scratchpad>\verify_agency_safeguard.php"`
Expected output:
```
Two active users, u1 last-active? false (expect false)
One active left, u1 last-active? true (expect true)
Disabled user (u2) counted as last-active? false (expect false — only checks currently-Active rows)
```

- [ ] **Step 5: Commit**

```bash
git add includes/auth.php
git commit -m "Add is_last_active_agency_user() safeguard helper

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

### Task 3: `public/agency-users.php` — the self-service page

**Files:**
- Create: `public/agency-users.php`
- Modify: `includes/sidebar.php:10-16` (the `is_partner_agency()` branch's `$navItems` array)

**Interfaces:**
- Consumes: `current_agency_id($pdo)`, `require_role(['Partner Agency'])`, `require_own_agency_record($pdo, ?int)` (all existing, in `includes/auth.php`); `is_last_active_agency_user($pdo, int): bool` (Task 2); `csrf_field()`/`csrf_require()` (existing); `audit_log()` (existing); `e()`/`clean()`/`format_date()` (existing, in `includes/functions.php`); `care_jf_users.is_primary` (Task 1).
- Produces: the `public/agency-users.php` page itself — Task 4 does not depend on this file, but both are part of the same feature.

- [ ] **Step 1: Create the page**

Create `public/agency-users.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role(['Partner Agency']);

$pdo = Database::getConnection();
$agencyId = current_agency_id($pdo);
$currentUserId = (int)current_user()['id'];
$errors = [];

if (!$agencyId) {
    flash_set('error', 'Your agency record could not be found. Please contact the Administrator.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $fullName = mb_strtoupper(clean($_POST['full_name'] ?? ''), 'UTF-8');
        $username = clean($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');
        $status   = clean($_POST['status'] ?? 'Active');

        if ($fullName === '') $errors['full_name'] = 'Full name is required.';
        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
            $errors['username'] = 'Username may only contain letters, numbers, underscores, and periods (3-50 characters).';
        }
        if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';
        if (!in_array($status, ['Active', 'Disabled'], true)) $errors['status'] = 'Select a valid status.';

        if (!$errors) {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM care_jf_users WHERE username = :u");
            $dup->execute([':u' => $username]);
            if ((int)$dup->fetchColumn() > 0) {
                $errors['username'] = 'That username is already taken.';
            }
        }

        if (!$errors) {
            // agency_id is always the acting user's own — never posted, never trusted from the request.
            $stmt = $pdo->prepare(
                "INSERT INTO care_jf_users (username, password, full_name, role, status, is_active, agency_id, is_primary)
                 VALUES (:u, :p, :f, 'Partner Agency', :s, :ia, :aid, 0)"
            );
            $stmt->execute([
                ':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':f' => $fullName,
                ':s' => $status, ':ia' => $status === 'Active' ? 1 : 0, ':aid' => $agencyId,
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, $currentUserId, 'AGENCY_USER_CREATE', 'care_jf_users', $newId, "Created agency user $username ($status)");
            flash_set('success', 'User added successfully.');
            redirect('agency-users.php');
        }
    } elseif ($action === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $targetStmt = $pdo->prepare("SELECT agency_id FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $targetStmt->execute([':id' => $userId]);
        $targetAgencyId = $targetStmt->fetchColumn();
        require_own_agency_record($pdo, $targetAgencyId !== false && $targetAgencyId !== null ? (int)$targetAgencyId : null);

        $fullName = mb_strtoupper(clean($_POST['full_name'] ?? ''), 'UTF-8');
        $status = clean($_POST['status'] ?? '');

        if ($fullName === '') $errors['edit_full_name'] = 'Full name is required.';
        if (!in_array($status, ['Active', 'Disabled'], true)) $errors['edit_status'] = 'Select a valid status.';
        if ($status === 'Disabled' && $userId === $currentUserId) {
            $errors['edit_status'] = 'You cannot disable your own account.';
        }
        if ($status === 'Disabled' && is_last_active_agency_user($pdo, $userId)) {
            $errors['edit_status'] = 'This is the only active user for your agency — add or enable another user first.';
        }

        if (!$errors) {
            $pdo->prepare("UPDATE care_jf_users SET full_name = :f, status = :s, is_active = :ia WHERE id = :id")
                ->execute([':f' => $fullName, ':s' => $status, ':ia' => $status === 'Active' ? 1 : 0, ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'AGENCY_USER_UPDATE', 'care_jf_users', $userId, "Updated agency user #$userId (status: $status)");
            flash_set('success', 'User updated successfully.');
        } else {
            flash_set('error', reset($errors));
        }
        redirect('agency-users.php');
    } elseif ($action === 'toggle') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $targetStmt = $pdo->prepare("SELECT agency_id, status FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $targetStmt->execute([':id' => $userId]);
        $target = $targetStmt->fetch();
        require_own_agency_record($pdo, $target ? (int)$target['agency_id'] : null);

        if ($userId === $currentUserId) {
            flash_set('error', 'You cannot disable your own account.');
        } elseif ($target['status'] === 'Active' && is_last_active_agency_user($pdo, $userId)) {
            flash_set('error', 'Cannot disable the only active user for your agency.');
        } else {
            $newStatus = $target['status'] === 'Active' ? 'Disabled' : 'Active';
            $pdo->prepare("UPDATE care_jf_users SET status = :s, is_active = :ia WHERE id = :id")
                ->execute([':s' => $newStatus, ':ia' => $newStatus === 'Active' ? 1 : 0, ':id' => $userId]);
            audit_log($pdo, $currentUserId, $newStatus === 'Active' ? 'AGENCY_USER_ENABLE' : 'AGENCY_USER_DISABLE', 'care_jf_users', $userId, "Agency user $newStatus");
            flash_set('success', "User $newStatus.");
        }
        redirect('agency-users.php');
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $targetStmt = $pdo->prepare("SELECT agency_id FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
        $targetStmt->execute([':id' => $userId]);
        $targetAgencyId = $targetStmt->fetchColumn();
        require_own_agency_record($pdo, $targetAgencyId !== false && $targetAgencyId !== null ? (int)$targetAgencyId : null);

        $newPassword = (string)($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            flash_set('error', 'New password must be at least 8 characters.');
        } else {
            $pdo->prepare("UPDATE care_jf_users SET password = :p WHERE id = :id")
                ->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
            audit_log($pdo, $currentUserId, 'AGENCY_USER_PASSWORD_RESET', 'care_jf_users', $userId, 'Password reset by agency user');
            flash_set('success', 'Password reset successfully.');
        }
        redirect('agency-users.php');
    }
}

$agencyUsersStmt = $pdo->prepare("SELECT * FROM care_jf_users WHERE agency_id = :aid AND role = 'Partner Agency' ORDER BY is_primary DESC, created_at ASC");
$agencyUsersStmt->execute([':aid' => $agencyId]);
$agencyUsers = $agencyUsersStmt->fetchAll();

$pageTitle = 'Agency Users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="space-y-5" x-data="{ showAdd: false, editingId: null, resettingId: null }">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">Agency Users</h1>
      <p class="text-sm text-slate-500">Manage the accounts authorized to work on your agency's behalf.</p>
    </div>
    <button type="button" @click="showAdd = true" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm">
      <i class="fa-solid fa-user-plus"></i> Add User
    </button>
  </div>

  <!-- Add User modal: real modal, does not close on outside click -->
  <div x-show="showAdd" x-cloak
       class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4"
       @keydown.escape.window="showAdd = false">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Add User</h2>
        <button type="button" @click="showAdd = false" class="text-slate-400 hover:text-slate-600" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form method="POST" onsubmit="return validateForm(this);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="grid sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" required class="uppercase-field uppercase w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['full_name']) ? 'hidden' : '' ?>" data-error-for="full_name"><?= e($errors['full_name'] ?? '') ?></p>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
            <input type="text" name="username" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['username']) ? 'hidden' : '' ?>" data-error-for="username"><?= e($errors['username'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
            <input type="password" name="password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['password']) ? 'hidden' : '' ?>" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
            <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="text-xs text-red-500 mt-1 <?= empty($errors['confirm_password']) ? 'hidden' : '' ?>" data-error-for="confirm_password"><?= e($errors['confirm_password'] ?? '') ?></p>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="Active">Active</option>
              <option value="Disabled">Disabled</option>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" @click="showAdd = false" class="px-5 py-2.5 rounded-lg border border-slate-300 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Add User</button>
        </div>
      </form>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
    <table class="min-w-full text-sm responsive-cards">
      <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
        <tr>
          <th class="px-4 py-2.5 text-left">Full Name</th>
          <th class="px-4 py-2.5 text-left">Username</th>
          <th class="px-4 py-2.5 text-left">Account</th>
          <th class="px-4 py-2.5 text-left">Status</th>
          <th class="px-4 py-2.5 text-left">Date Created</th>
          <th class="px-4 py-2.5 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php if (!$agencyUsers): ?>
          <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-users text-2xl mb-2 block"></i> No agency users found.</td></tr>
        <?php endif; ?>
        <?php foreach ($agencyUsers as $u): $isSelf = (int)$u['id'] === $currentUserId; ?>
        <tr>
          <td class="px-4 py-2.5 font-medium" data-label="Name"><?= e($u['full_name']) ?><?= $isSelf ? ' <span class="text-xs text-slate-400">(you)</span>' : '' ?></td>
          <td class="px-4 py-2.5" data-label="Username"><?= e($u['username']) ?></td>
          <td class="px-4 py-2.5" data-label="Account">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $u['is_primary'] ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' ?>">
              <?= $u['is_primary'] ? 'Primary' : 'Member' ?>
            </span>
          </td>
          <td class="px-4 py-2.5" data-label="Status">
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $u['status'] === 'Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
              <?= e($u['status']) ?>
            </span>
          </td>
          <td class="px-4 py-2.5 text-slate-500" data-label="Created"><?= format_date($u['created_at']) ?></td>
          <td class="px-4 py-2.5 text-right" data-label="Actions">
            <button @click="editingId = editingId === <?= (int)$u['id'] ?> ? null : <?= (int)$u['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></button>
            <button @click="resettingId = resettingId === <?= (int)$u['id'] ?> ? null : <?= (int)$u['id'] ?>" class="text-slate-500 hover:text-purple-600 px-1" title="Reset Password"><i class="fa-solid fa-key"></i></button>
            <?php if (!$isSelf): ?>
            <form method="POST" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $u['status'] === 'Active' ? 'Disable' : 'Enable' ?>">
                <i class="fa-solid <?= $u['status'] === 'Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
              </button>
            </form>
            <?php else: ?>
              <span class="text-xs text-slate-300 px-1">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <!-- Inline edit row -->
        <tr x-show="editingId === <?= (int)$u['id'] ?>" x-cloak>
          <td colspan="6" class="px-4 py-4 bg-slate-50">
            <form method="POST" class="flex flex-wrap items-end gap-3" onsubmit="return validateForm(this);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Full Name</label>
                <input type="text" name="full_name" required value="<?= e($u['full_name']) ?>" class="uppercase-field uppercase rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                <select name="status" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                  <option value="Active" <?= $u['status']==='Active'?'selected':'' ?>>Active</option>
                  <option value="Disabled" <?= $u['status']==='Disabled'?'selected':'' ?>>Disabled</option>
                </select>
              </div>
              <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium">Save</button>
              <button type="button" @click="editingId = null" class="px-4 py-1.5 rounded-lg border border-slate-300 text-sm">Cancel</button>
            </form>
          </td>
        </tr>
        <!-- Inline reset password row -->
        <tr x-show="resettingId === <?= (int)$u['id'] ?>" x-cloak>
          <td colspan="6" class="px-4 py-4 bg-slate-50">
            <form method="POST" class="flex flex-wrap items-end gap-3" onsubmit="return validateForm(this);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">New Password</label>
                <input type="password" name="new_password" required minlength="8" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
              </div>
              <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium">Reset Password</button>
              <button type="button" @click="resettingId = null" class="px-4 py-1.5 rounded-lg border border-slate-300 text-sm">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```

- [ ] **Step 2: Add the sidebar nav link**

In `includes/sidebar.php`, inside the `if (is_partner_agency())` branch's `$navItems` array (currently 5 entries: Dashboard, Applicants, My Partner Agency, Job Vacancies, Reports), add a new entry directly after `'my-agency.php'`:

```php
['href' => 'agency-users.php', 'icon' => 'fa-users-gear', 'label' => 'Agency Users', 'match' => ['agency-users.php']],
```

So the full `is_partner_agency()` array becomes:

```php
$navItems = [
    ['href' => 'dashboard.php',      'icon' => 'fa-gauge-high',        'label' => 'Dashboard',         'match' => ['dashboard.php']],
    ['href' => 'applicants.php',     'icon' => 'fa-users',             'label' => 'Applicants',        'match' => ['applicants.php', 'applicant-view.php']],
    ['href' => 'my-agency.php',      'icon' => 'fa-building',          'label' => 'My Partner Agency', 'match' => ['my-agency.php']],
    ['href' => 'agency-users.php',   'icon' => 'fa-users-gear',        'label' => 'Agency Users',       'match' => ['agency-users.php']],
    ['href' => 'vacancies.php',      'icon' => 'fa-briefcase-medical', 'label' => 'Job Vacancies',      'match' => ['vacancies.php']],
    ['href' => 'reports.php',        'icon' => 'fa-chart-column',      'label' => 'Reports',            'match' => ['reports.php']],
];
```

- [ ] **Step 3: Lint both files**

Run: `& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\applicant-system\public\agency-users.php"` and `& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\applicant-system\includes\sidebar.php"`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manual/curl verification — add, edit, disable, safeguard**

Confirm the local Apache is serving the app: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost/applicant-system/public/login.php` should print `200`.

Log in as one of the existing Partner Agency test accounts (or create a fresh one via the public "Partner Agency Registration" panel on `login.php` and activate it as `admin` first, since the user has confirmed all non-admin data is disposable for this work). Using a `curl` cookie jar (fetch the login page for a CSRF token, POST credentials, reuse the jar for every subsequent request). Call the primary account **P**; add a second via the Add User modal, **U2**, `status=Active`.

**Note on the "last active user" safeguard specifically:** within `agency-users.php`'s own `toggle`/`edit` actions, the acting user is always themselves a counted `Partner Agency, Active` row for that same agency (the page requires that role to even load). So whenever the actor targets someone else, the actor's own active presence means the pre-action active count is always >= 2 - the "only active user" branch can only ever be reached when the target *is* the actor, which the separate self-disable check already blocks first. This is correct, not a gap: it means an agency can never lose its last active user through one peer's action alone. `is_last_active_agency_user()` still matters as real defense-in-depth (e.g. two simultaneous disables racing each other) and for consistency with the system-wide `is_last_active_admin()` pattern it mirrors - its actual behavior is proven directly and unambiguously by Task 2's isolated script, so this step doesn't need to re-derive that edge case live.

1. `GET agency-users.php` as **P** -> expect `200`, page lists exactly the one existing (primary) user.
2. `POST action=add` (as **P**) to create **U2** (unique username, matching passwords >= 8 chars, `status=Active`) -> expect redirect, then `GET agency-users.php` shows 2 rows, **P** labeled "Primary", **U2** labeled "Member".
3. Log in as **U2** directly (fresh cookie jar, its own username/password) -> expect successful login and `GET agency-users.php` shows the *same* 2 rows (proves shared `agency_id` scoping).
4. As **U2**, `POST action=toggle` targeting **P**'s `user_id` -> expect success (2 active before the action; **U2** remains active afterward, so **P** was never the "last" one).
5. As **U2**, `POST action=toggle` targeting **U2**'s own `user_id` -> expect the "You cannot disable your own account" error, not a crash - this is the one self-service case that's always reachable, and it's the case that actually matters in practice.
6. Re-enable **P** (as **U2**, `POST action=toggle` targeting **P** again) so the account is left in a normal state.
7. `POST action=reset_password` for **U2** with a new password >= 8 chars -> expect success, then confirm login with the OLD password fails and the NEW password succeeds.
8. As a *different* agency's Partner Agency user (e.g. `psa_01`, or any second test agency), `POST action=edit` targeting a `user_id` belonging to **P**/**U2**'s agency -> expect `403`.

- [ ] **Step 5: Commit**

```bash
git add public/agency-users.php includes/sidebar.php
git commit -m "Add Partner Agency self-service Agency Users page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

### Task 4: Administrator's Manage Users — show real names for multi-user agencies

**Files:**
- Modify: `public/users.php` (the `$agencyAccounts` query and its table markup, inside the "Partner Agency Accounts" tab)

**Interfaces:**
- Consumes: `care_jf_users.full_name`, `care_jf_users.is_primary` (Task 1) — both already exist/added by Task 1, this task is the first to display them here.

- [ ] **Step 1: Update the query**

In `public/users.php`, find:
```php
$agencyAccounts = $pdo->query(
    "SELECT u.id, u.username, u.status AS account_status, u.created_at,
            pa.agency_name, pa.contact_person, pa.contact_no, pa.email, pa.status AS agency_status, pa.employer_id
     FROM care_jf_users u
     JOIN care_jf_partner_agencies pa ON pa.id = u.agency_id
     WHERE u.role = 'Partner Agency'
     ORDER BY u.created_at DESC"
)->fetchAll();
```

Replace with (adds `u.full_name`, `u.is_primary`; re-orders so an agency's users cluster together, primary first, instead of interleaving different agencies by raw creation time — now that one agency can have several rows):

```php
$agencyAccounts = $pdo->query(
    "SELECT u.id, u.username, u.full_name, u.is_primary, u.status AS account_status, u.created_at,
            pa.agency_name, pa.contact_person, pa.contact_no, pa.email, pa.status AS agency_status, pa.employer_id
     FROM care_jf_users u
     JOIN care_jf_partner_agencies pa ON pa.id = u.agency_id
     WHERE u.role = 'Partner Agency'
     ORDER BY pa.agency_name ASC, u.is_primary DESC, u.created_at ASC"
)->fetchAll();
```

- [ ] **Step 2: Add the Full Name column and Primary badge**

Find the table header row (currently: Agency Name, Employer ID, Contact Person, Contact No, Email, Username, Registered, Account Status, Actions — 9 columns) and add a "Full Name" header right after "Agency Name":

```php
<th class="px-4 py-2.5 text-left">Agency Name</th>
<th class="px-4 py-2.5 text-left">Full Name</th>
<th class="px-4 py-2.5 text-left">Employer ID</th>
```

Find the matching data row (`<td ... data-label="Agency">`) and add the new cell right after it:

```php
<td class="px-4 py-2.5 font-medium" data-label="Agency"><?= e($a['agency_name']) ?></td>
<td class="px-4 py-2.5" data-label="Full Name">
  <?= e($a['full_name']) ?>
  <?php if ($a['is_primary']): ?><span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-indigo-100 text-indigo-700">Primary</span><?php endif; ?>
</td>
<td class="px-4 py-2.5 font-mono text-xs" data-label="Employer ID"><?= e($a['employer_id'] ?: '—') ?></td>
```

- [ ] **Step 3: Update the empty-state colspan**

Find `<tr><td colspan="9" class="px-4 py-10 text-center text-slate-400"><i class="fa-solid fa-building text-2xl mb-2 block"></i> No Partner Agency registrations yet.</td></tr>` and change `colspan="9"` to `colspan="10"` (one column added).

- [ ] **Step 4: Lint**

Run: `& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\applicant-system\public\users.php"`
Expected: `No syntax errors detected`

- [ ] **Step 5: Verify live**

Log in as `admin` (via `curl` cookie jar as in Task 3, or the browser), navigate to `users.php`, click the "Partner Agency Accounts" tab. Confirm:
- The two (or more, if Task 3's testing left extra rows) existing Partner Agency accounts now each show their own `full_name`, not the agency's `contact_person`.
- The account created during original self-registration shows the "Primary" badge; any added via Task 3's Agency Users page do not.
- Every existing action (Activate/Disable/Re-enable, Reset Password, Delete) in this tab still works unchanged — this task only added a column, it did not touch any POST handler.

- [ ] **Step 6: Commit**

```bash
git add public/users.php
git commit -m "Show each Partner Agency user's own name in Manage Users, not the agency's contact person

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Pd4rekTEFdJSppKSPtG1LF"
```

---

## Self-Review Notes (for whoever executes this plan)

- **Spec coverage:** §3 (schema) → Task 1. §4 (new page, Add User modal, actions) → Task 3. §5 (safeguard helper) → Task 2. §6 (existing `users.php` correction) → Task 4. §7 (authorization) → woven into Task 3's every action via `require_own_agency_record()`. §8 (audit logging) → the five new action constants, used throughout Task 3. §9 (testing) → each task's own verification step.
- **Not in this plan, by design:** the sidebar entry for `my-agency.php`'s neighbor `agency-users.php` link (Task 3, Step 2) is the only UI-discovery change outside the new page itself — everything else about navigation, dashboard, and other roles is untouched.
- **Administrator's existing `delete_agency_account` action in `users.php` is deliberately left unchanged.** It already blocks deleting an agency's *only total* account (any status) to prevent permanently stranding an agency with no way to log in or re-register — a different, complementary safeguard from Task 2's *only active* check. The user's original request explicitly says an Administrator can override these restrictions, so this asymmetry (Administrator's path is more permissive than the self-service path) is intentional, not a gap.
