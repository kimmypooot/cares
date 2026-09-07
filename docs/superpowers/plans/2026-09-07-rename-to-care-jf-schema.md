# Database & Table Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the database from `applicant_system` to `care_job_fair_db`, and prefix every table with `care_jf_` (`applicants`→`care_jf_applicants`, `partner_agencies`→`care_jf_partner_agencies`, `employment_records`→`care_jf_employment_records`, `users`→`care_jf_users`, `audit_logs`→`care_jf_audit_logs`). Table structure, columns, and all existing data are completely unchanged — this is a pure rename.

**Architecture:** One new migration performs the actual rename via MySQL's `RENAME TABLE` (a metadata-only operation — no data copy, and InnoDB automatically carries foreign-key relationships to the new names). `database/database.sql` and the 3 existing migration files are **not edited** (CLAUDE.md convention) — they keep referencing the old names, since they run *before* the new rename migration in the install sequence, against the still-old-named database. Every PHP file's SQL is then updated to reference the new table names via a single mechanical rule, applied file-by-file.

**Tech Stack:** PHP 8, PDO/MySQL, no framework.

**Reference:** A full inventory of every genuine SQL table reference in this codebase (as opposed to incidental English-word matches like the UI label "Registered Applicants" or the PHP variable `$applicants`) is already built and saved at `docs/superpowers/specs/_rename-inventory-scratch.md` — every task below tells you which section of it to read. This file is scratch/reference material for this plan only; it is not itself part of the change (don't edit it, and it can be deleted once this plan is complete).

## Global Constraints

- Table mapping (exact, no exceptions): `applicants`→`care_jf_applicants`, `partner_agencies`→`care_jf_partner_agencies`, `employment_records`→`care_jf_employment_records`, `users`→`care_jf_users`, `audit_logs`→`care_jf_audit_logs`. Database: `applicant_system`→`care_job_fair_db`.
- **The rename rule** (apply this exact rule everywhere a task touches PHP SQL):
  1. Inside any SQL string (a `$pdo->prepare("...")`/`$pdo->query("...")` argument, or a string being built up for one), replace the bare table name with its prefixed equivalent wherever it's a genuine table reference — after `FROM`, `JOIN`, `INTO`, `UPDATE`, `TABLE`, `DELETE FROM`, or as a correlated-subquery `FROM` target. Table **aliases** (e.g. `applicants a`, `users u`, `partner_agencies pa`, `employment_records er`, `audit_logs al`) keep their alias letter — only the table name itself changes (`FROM applicants a` → `FROM care_jf_applicants a`).
  2. In every `audit_log($pdo, $userId, $action, $table, $recordId, $description)` call (signature in `includes/functions.php`), the 4th positional argument — the one immediately after the action string like `'CREATE'`/`'UPDATE'`/`'DELETE'`/`'LOGIN'`/`'MARK_HIRED'` — is the table-name argument. When its string literal equals one of the 5 old table names, update it to the prefixed name too (it's stored in `audit_logs.table_name` for record-keeping and should stay consistent with the real schema).
  3. Do **NOT** touch: PHP variable names (`$applicants`, `$users`), function/file names, UI text/labels, comments using the word informally, or any column name (`user_id`, `agency_id`, `is_current`, `full_name`, etc. are columns — never renamed).
  4. After editing each file, grep it for every remaining bare old-name SQL keyword combination (`FROM applicants`, `FROM users`, `FROM partner_agencies`, `FROM employment_records`, `FROM audit_logs`, `INTO applicants`, `INTO users`, `INTO partner_agencies`, `INTO employment_records`, `INTO audit_logs`, `UPDATE applicants`, `UPDATE users`, `UPDATE partner_agencies`, `UPDATE employment_records`, `TABLE applicants`, `TABLE users`, `TABLE partner_agencies`, `TABLE employment_records`, `TABLE audit_logs`) and confirm **zero** matches remain in that file. This is the safety net.
- `database/database.sql`, `database/migrations/update_application_management.sql`, `database/migrations/add_partner_agency_accounts.sql`, and `database/migrations/add_hired_status_and_uppercase_backfill.sql` are **NOT edited** by this plan — they keep referencing the old database/table names throughout, per CLAUDE.md's "never edit already-shipped migrations" rule. The new rename migration runs strictly after all of them.
- `public/employment.php` is confirmed dead/unreachable code (nothing links to it; it already can't work against the current schema) — **excluded from this plan entirely**. Leave its old table-name references exactly as they are.
- **The application is expected to be non-functional between Task 1 and the completion of Task 4** — the live database gets renamed in Task 1 (including `config/database.php` pointing at the new name), but the PHP code isn't updated to match until Tasks 2-4 land. This is normal for a multi-commit rename on a branch (nobody deploys mid-branch) — task reviewers should NOT flag "the app doesn't fully work yet" as a defect for Tasks 1-3; only Task 4/5's live curl checks need the full app working.
- No automated test suite exists in this repo (confirmed CLAUDE.md convention). Verification uses `php -l`, MySQL CLI assertions, grep-based safety-net checks, and (once the full app is reassembled) curl-driven HTTP checks.
- Local verification tool paths: PHP `/c/xampp/php/php.exe`; MySQL client `/c/xampp/mysql/bin/mysql.exe -u root` (root has no password); seeded admin credentials `admin` / `Admin@123`.

---

## Task 1: Rename migration + config + README

**Files:**
- Create: `database/migrations/rename_database_and_tables.sql`
- Modify: `config/database.php`, `README.md`

**Interfaces:**
- Produces: the live local database is renamed from `applicant_system` to `care_job_fair_db`, with tables `care_jf_users`, `care_jf_partner_agencies`, `care_jf_applicants`, `care_jf_employment_records`, `care_jf_audit_logs`. `config/database.php`'s `DB_NAME` constant reflects this. Consumed by every later task (they all run SQL against these new names).

- [ ] **Step 1: Write the migration**

```sql
-- =====================================================================
-- Migration: rename_database_and_tables.sql
-- Renames the database from applicant_system to care_job_fair_db, and
-- prefixes every table with care_jf_. Table structure/columns/data are
-- completely unchanged — this is a pure rename, using MySQL/MariaDB's
-- RENAME TABLE, which is a metadata-only operation (no data is copied
-- or re-written) and correctly carries every index and foreign-key
-- relationship over to the new names automatically.
--
-- Run this LAST, strictly after database.sql and all 3 existing
-- migration files (update_application_management.sql,
-- add_partner_agency_accounts.sql, add_hired_status_and_uppercase_backfill.sql)
-- have already been applied to the applicant_system database under its
-- original table names — this migration does not itself create any
-- table, only renames existing ones.
--
-- IMPORTANT: after this migration, config/database.php's DB_NAME
-- constant must ALSO be updated to 'care_job_fair_db' (done as part of
-- this same task, not by this SQL file) — the application will not
-- function until both are done together.
--
-- This migration does NOT drop the old (now-empty) applicant_system
-- database — once you've confirmed the application works correctly
-- against the new database, you can remove it manually with:
--   DROP DATABASE applicant_system;
--
-- Usage:
--   mysql -u root -p < database/migrations/rename_database_and_tables.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS care_job_fair_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- One atomic multi-table RENAME TABLE statement, so InnoDB resolves every
-- foreign-key relationship (employment_records -> applicants,
-- employment_records -> partner_agencies, audit_logs -> users) in a
-- single step rather than passing through any transient partially-renamed
-- state.
RENAME TABLE
  applicant_system.users              TO care_job_fair_db.care_jf_users,
  applicant_system.partner_agencies   TO care_job_fair_db.care_jf_partner_agencies,
  applicant_system.applicants         TO care_job_fair_db.care_jf_applicants,
  applicant_system.employment_records TO care_job_fair_db.care_jf_employment_records,
  applicant_system.audit_logs         TO care_job_fair_db.care_jf_audit_logs;

-- ---------------------------------------------------------------------
-- Sanity check
-- ---------------------------------------------------------------------
SELECT 'Rename complete.' AS status;
SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'care_job_fair_db' ORDER BY TABLE_NAME;
SELECT COUNT(*) AS old_db_tables_remaining FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'applicant_system';
```

- [ ] **Step 2: Record pre-rename data counts (so you can prove nothing was lost)**

Run:
```bash
/c/xampp/mysql/bin/mysql.exe -u root applicant_system -e "
SELECT 'users' AS t, COUNT(*) AS c FROM users
UNION ALL SELECT 'partner_agencies', COUNT(*) FROM partner_agencies
UNION ALL SELECT 'applicants', COUNT(*) FROM applicants
UNION ALL SELECT 'employment_records', COUNT(*) FROM employment_records
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs;
"
```
Record this output — you'll compare it after the rename.

- [ ] **Step 3: Apply the migration**

Run: `/c/xampp/mysql/bin/mysql.exe -u root < database/migrations/rename_database_and_tables.sql`
Expected: "Rename complete.", then a 5-row table listing showing `care_jf_applicants`, `care_jf_audit_logs`, `care_jf_employment_records`, `care_jf_partner_agencies`, `care_jf_users` (alphabetical), then `old_db_tables_remaining = 0`.

- [ ] **Step 4: Verify row counts match exactly**

Run:
```bash
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "
SELECT 'care_jf_users' AS t, COUNT(*) AS c FROM care_jf_users
UNION ALL SELECT 'care_jf_partner_agencies', COUNT(*) FROM care_jf_partner_agencies
UNION ALL SELECT 'care_jf_applicants', COUNT(*) FROM care_jf_applicants
UNION ALL SELECT 'care_jf_employment_records', COUNT(*) FROM care_jf_employment_records
UNION ALL SELECT 'care_jf_audit_logs', COUNT(*) FROM care_jf_audit_logs;
"
```
Expected: identical counts to Step 2's output, per table (nothing lost, nothing duplicated).

- [ ] **Step 5: Verify foreign keys survived the rename**

Run: `/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SHOW CREATE TABLE care_jf_employment_records\G"`
Expected: the output's `CONSTRAINT` clauses reference `care_jf_applicants` and `care_jf_partner_agencies` (not the old names) — confirming InnoDB updated the FK targets automatically as part of the rename.

- [ ] **Step 6: Update `config/database.php`**

Find:
```php
    private const DB_NAME = 'applicant_system';
```
Replace:
```php
    private const DB_NAME = 'care_job_fair_db';
```

- [ ] **Step 7: Update `README.md`**

Read the current file first (find the fresh-install and upgrade command blocks, and the `DB_NAME` config example, by searching for `applicant_system`). Add one line to the end of BOTH the fresh-install sequence and the upgrade sequence, in the same style as the existing lines:
```
mysql -u root -p < database/migrations/rename_database_and_tables.sql
```
And update the shown config example from `private const DB_NAME = 'applicant_system';` to `private const DB_NAME = 'care_job_fair_db';`.

- [ ] **Step 8: Syntax check**

Run: `/c/xampp/php/php.exe -l config/database.php`
Expected: "No syntax errors detected"

- [ ] **Step 9: Commit**

```bash
git add database/migrations/rename_database_and_tables.sql config/database.php README.md
git commit -m "db: rename database to care_job_fair_db and prefix all tables with care_jf_"
```

Note: the application is expected to be broken after this commit (every PHP query still references the old bare table names, which no longer exist) until Task 4 completes. This is expected — do not try to fix PHP files in this task.

---

## Task 2: `includes/auth.php` + `includes/functions.php`

**Files:**
- Modify: `includes/auth.php`, `includes/functions.php`

**Interfaces:**
- Consumes: Task 1's renamed tables.
- Produces: nothing new by name — every function in these two files keeps its existing signature; only their internal SQL changes.

- [ ] **Step 1: Apply the rename rule to both files**

Read `docs/superpowers/specs/_rename-inventory-scratch.md`, sections `### includes\functions.php` and `### includes\auth.php`, for the exact list of statements to update in each file (line numbers, current SQL, target SQL). Read each live file yourself before editing (line numbers in the inventory may have drifted slightly since it was written) and apply the Global Constraints' rename rule.

- [ ] **Step 2: Syntax check**

```bash
/c/xampp/php/php.exe -l includes/auth.php
/c/xampp/php/php.exe -l includes/functions.php
```
Expected: "No syntax errors detected" for both.

- [ ] **Step 3: Run the grep safety net on both files**

```bash
grep -nE "FROM (applicants|users|partner_agencies|employment_records|audit_logs)\b|INTO (applicants|users|partner_agencies|employment_records|audit_logs)\b|UPDATE (applicants|users|partner_agencies|employment_records|audit_logs)\b|TABLE (applicants|users|partner_agencies|employment_records|audit_logs)\b" includes/auth.php includes/functions.php
```
Expected: no output (zero matches).

- [ ] **Step 4: Functional verification via a CLI script**

Since these are pure functions (no HTTP request needed), verify them directly against the real renamed database:
```php
<?php
declare(strict_types=1);
require_once 'C:/xampp/htdocs/applicant-system/includes/auth.php';
$pdo = Database::getConnection();

$fail = 0;
function check(bool $c, string $l) { global $fail; echo ($c?'PASS':'FAIL')." - $l\n"; if(!$c)$fail++; }

// current_agency_id() / current_employment_status() / active_agencies() /
// agency_name_taken() / is_last_active_admin() all query the renamed
// tables — confirm each runs without throwing and returns a sane shape.
$_SESSION['user_id'] = 1; // seeded admin
check(current_agency_id($pdo) === null, 'current_agency_id() runs clean for a non-agency user (admin)');

$agencies = active_agencies($pdo);
check(is_array($agencies), 'active_agencies() returns an array without SQL error');

check(agency_name_taken($pdo, 'Definitely Not A Real Agency Name XYZ') === false, 'agency_name_taken() runs clean');

check(is_last_active_admin($pdo, 1) === false || is_last_active_admin($pdo, 1) === true, 'is_last_active_admin() runs without SQL error');

$status = current_employment_status($pdo, 1);
check(isset($status['label'], $status['color']), 'current_employment_status() returns the expected shape');

echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
```
Run: `/c/xampp/php/php.exe /path/to/scratchpad/verify_task2.php`
Expected: five `PASS` lines, `ALL PASS`. (If any query throws a PDO exception referencing an old table name, this script will fatal-error instead of printing FAIL — that's an equally valid signal something was missed; read the error to find which table reference wasn't updated.)

- [ ] **Step 5: Commit**

```bash
git add includes/auth.php includes/functions.php
git commit -m "refactor: rename SQL table references in auth.php and functions.php to care_jf_ prefix"
```

---

## Task 3: Management pages (batch — 7 files)

**Files:**
- Modify: `public/login.php`, `public/users.php`, `public/my-agency.php`, `public/partner-agency-form.php`, `public/partner-agency.php`, `public/employment-form.php`, `public/employment-list.php`

**Interfaces:**
- Consumes: Task 1's renamed tables, Task 2's updated `includes/auth.php`/`includes/functions.php`.
- Produces: nothing new by name.

- [ ] **Step 1: Apply the rename rule to all 7 files**

Read `docs/superpowers/specs/_rename-inventory-scratch.md`, the sections for each of these 7 files (`### public\login.php`, `### public\users.php`, `### public\my-agency.php`, `### public\partner-agency-form.php`, `### public\partner-agency.php`, `### public\employment-form.php`, `### public\employment-list.php`), for the exact list of statements to update. Read each live file yourself before editing and apply the Global Constraints' rename rule.

- [ ] **Step 2: Syntax check all 7**

```bash
/c/xampp/php/php.exe -l public/login.php
/c/xampp/php/php.exe -l public/users.php
/c/xampp/php/php.exe -l public/my-agency.php
/c/xampp/php/php.exe -l public/partner-agency-form.php
/c/xampp/php/php.exe -l public/partner-agency.php
/c/xampp/php/php.exe -l public/employment-form.php
/c/xampp/php/php.exe -l public/employment-list.php
```
Expected: "No syntax errors detected" for all 7.

- [ ] **Step 3: Run the grep safety net on all 7 files**

```bash
grep -nE "FROM (applicants|users|partner_agencies|employment_records|audit_logs)\b|INTO (applicants|users|partner_agencies|employment_records|audit_logs)\b|UPDATE (applicants|users|partner_agencies|employment_records|audit_logs)\b|TABLE (applicants|users|partner_agencies|employment_records|audit_logs)\b" public/login.php public/users.php public/my-agency.php public/partner-agency-form.php public/partner-agency.php public/employment-form.php public/employment-list.php
```
Expected: no output.

- [ ] **Step 4: Verify the login flow works end-to-end (the app is now reassembled enough to test this)**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/rename_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" \
  -w "\nlogin status: %{http_code}\n" http://localhost:8899/login.php -o /dev/null

for PAGE in users.php my-agency.php partner-agency.php employment-list.php; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$PAGE")
  echo "$PAGE -> $STATUS"
done
kill %1
```
Expected: `login status: 302`; `users.php` and `partner-agency.php` return `200` (the step-up reauth gate on `users.php` renders its own confirm-password page at `200`, not a 500 — that's success at this layer); `my-agency.php` may 302-redirect a non-Partner-Agency admin session (acceptable — it means the role gate is being evaluated without a SQL error, not a crash); `employment-list.php` returns `200`. None should return `500`.

- [ ] **Step 5: Commit**

```bash
git add public/login.php public/users.php public/my-agency.php public/partner-agency-form.php public/partner-agency.php public/employment-form.php public/employment-list.php
git commit -m "refactor: rename SQL table references in management pages to care_jf_ prefix"
```

---

## Task 4: Applicant/reporting pages (batch — 9 files)

**Files:**
- Modify: `public/applicant-create.php`, `public/applicant-edit.php`, `public/applicant-view.php`, `public/register-applicant.php`, `public/api/applicants.php`, `public/dashboard.php`, `public/reports.php`, `public/audit-logs.php`, `public/settings.php`

**Interfaces:**
- Consumes: Task 1's renamed tables, Task 2/3's updated shared code.
- Produces: nothing new by name — after this task, every SQL table reference in the codebase (outside the intentionally-excluded dead `public/employment.php`) targets the new `care_jf_`-prefixed names.

- [ ] **Step 1: Apply the rename rule to all 9 files**

Read `docs/superpowers/specs/_rename-inventory-scratch.md`, the sections for each of these 9 files. Two of them (`public\api\applicants.php`, `public\settings.php`) are marked in the inventory as "not re-read fresh" — read those two live files especially carefully yourself (don't trust the inventory's line numbers for those two without confirming against the actual current file first). Apply the Global Constraints' rename rule to all 9.

- [ ] **Step 2: Syntax check all 9**

```bash
/c/xampp/php/php.exe -l public/applicant-create.php
/c/xampp/php/php.exe -l public/applicant-edit.php
/c/xampp/php/php.exe -l public/applicant-view.php
/c/xampp/php/php.exe -l public/register-applicant.php
/c/xampp/php/php.exe -l public/api/applicants.php
/c/xampp/php/php.exe -l public/dashboard.php
/c/xampp/php/php.exe -l public/reports.php
/c/xampp/php/php.exe -l public/audit-logs.php
/c/xampp/php/php.exe -l public/settings.php
```
Expected: "No syntax errors detected" for all 9.

- [ ] **Step 3: Run the grep safety net on all 9 files**

```bash
grep -nE "FROM (applicants|users|partner_agencies|employment_records|audit_logs)\b|INTO (applicants|users|partner_agencies|employment_records|audit_logs)\b|UPDATE (applicants|users|partner_agencies|employment_records|audit_logs)\b|TABLE (applicants|users|partner_agencies|employment_records|audit_logs)\b" public/applicant-create.php public/applicant-edit.php public/applicant-view.php public/register-applicant.php public/api/applicants.php public/dashboard.php public/reports.php public/audit-logs.php public/settings.php
```
Expected: no output.

- [ ] **Step 4: Verify the full app now works end-to-end via curl**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/rename_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

for PAGE in dashboard.php applicants.php reports.php audit-logs.php settings.php; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$PAGE")
  echo "$PAGE -> $STATUS"
done

# Confirm the applicant hired during earlier manual testing survived the
# rename intact — this doubles as an end-to-end data-integrity check.
HIRED_ID=$(/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -N -e "SELECT applicant_id FROM care_jf_employment_records WHERE employment_status='Hired' LIMIT 1;")
if [ -n "$HIRED_ID" ]; then
  PROFILE_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?id=$HIRED_ID")
  echo "applicant-view.php (hired applicant #$HIRED_ID) -> $PROFILE_STATUS"
fi

# api/applicants.php is the AJAX endpoint the Applicants list depends on
API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/api/applicants.php")
echo "api/applicants.php -> $API_STATUS"

kill %1
```
Expected: every page returns `200`, including the hired applicant's profile (confirming data survived the rename and the app can read it under the new table names) and the AJAX API endpoint.

- [ ] **Step 5: Commit**

```bash
git add public/applicant-create.php public/applicant-edit.php public/applicant-view.php public/register-applicant.php public/api/applicants.php public/dashboard.php public/reports.php public/audit-logs.php public/settings.php
git commit -m "refactor: rename SQL table references in applicant/reporting pages to care_jf_ prefix"
```

---

## Task 5: Final end-to-end verification

**Files:** none modified — verification-only task confirming Tasks 1-4 compose correctly.

**Interfaces:** none.

- [ ] **Step 1: Full regression smoke test across roles**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/rename_server.log 2>&1 &
sleep 1

# Admin: every page in the app
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

for PAGE in dashboard.php applicants.php reports.php partner-agency.php users.php employment-list.php audit-logs.php settings.php my-agency.php; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$PAGE")
  echo "admin: $PAGE -> $STATUS"
done

# Public (unauthenticated) pages
for PAGE in login.php register-applicant.php; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8899/$PAGE")
  echo "public: $PAGE -> $STATUS"
done

# Full applicant registration -> a real INSERT against the renamed tables
CSRF2=$(curl -s -c "$COOKIE" http://localhost:8899/register-applicant.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "csrf_token=$CSRF2" --data-urlencode "last_name=Rename Test" --data-urlencode "first_name=Verify" \
  --data-urlencode "sex=MALE" --data-urlencode "date_of_birth=1990-01-01" --data-urlencode "address=Test Addr" \
  --data-urlencode "civil_status=SINGLE" --data-urlencode "contact_number=09170000099" \
  --data-urlencode "email_address=renametest@example.com" --data-urlencode "service_job_seeker=1" \
  -w "\nregistration status: %{http_code}\n" http://localhost:8899/register-applicant.php

/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT applicant_code, last_name, first_name FROM care_jf_applicants WHERE last_name='RENAME TEST';"

kill %1
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "DELETE FROM care_jf_applicants WHERE last_name='RENAME TEST';"
```
Expected: every admin page returns `200`; both public pages return `200`; the registration POST redirects successfully (`302` visible via the final URL, or `200` if it renders inline — check the `SELECT` regardless); the `SELECT` shows the new applicant row with uppercased name fields (confirming both this rename AND the earlier uppercase-formatting work still compose correctly together); test row cleaned up.

- [ ] **Step 2: Confirm `public/employment.php` was correctly left alone (still references old table names, still unreachable — no regression, not a new requirement)**

```bash
grep -c "FROM applicants\|FROM employment_records\|FROM users" public/employment.php
```
Expected: a non-zero count (confirms the file's old-name references are untouched, as the plan intended — this file is dead code and out of scope, not a defect).

- [ ] **Step 3: Delete the scratch inventory file**

```bash
git rm docs/superpowers/specs/_rename-inventory-scratch.md
git commit -m "chore: remove rename-planning scratch inventory (no longer needed)"
```

- [ ] **Step 4: No further commit needed for Steps 1-2** (verification-only).
