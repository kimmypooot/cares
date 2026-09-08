# Application Tracking & Hiring Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the one-click "Mark as Hired" button with a two-step
Tag for Review → Confirm Hired workflow, backed entirely by extending
the existing `care_jf_employment_records` table (no new table), with
per-agency privacy, post-hire visibility rules, a vacancy-delete
dependency guard, and a Partner Agency password-reset UI addition.

**Architecture:** One `care_jf_employment_records` row represents one
Partner Agency's relationship with one applicant, progressing through
`employment_status` states (`For Review` → `Hired`, or `For Review` →
`Withdrawn`/`Superseded`) via `UPDATE`, not a series of inserts across
tables. `is_current` stays the single source of truth for "is this a
real, confirmed, currently-active hire" — a `For Review` row is always
`is_current = 0`, so every existing piece of logic that already keys
off `is_current = 1 AND status = 'Active'`
(`is_applicant_hired()`/`current_employment_status()`) needs zero
changes to stay correct.

**Tech Stack:** PHP 8 + PDO/MySQL (unchanged), Alpine.js (existing).

**Spec:** docs/superpowers/specs/2026-09-08-application-tracking-hiring-workflow-design.md

## Global Constraints

- **No automated test suite** in this repo — verification is `php -l`
  + MySQL CLI assertions + curl-driven HTTP checks, same as every
  prior phase.
- **`is_current` bookkeeping**: before setting any row's `is_current =
  1`, first clear `is_current` on every other row for that applicant,
  in the same request/transaction — this codebase's standing rule
  (CLAUDE.md), unchanged by this plan, just now also applied inside
  the new `confirm_hired` action.
- **The hire confirmation transaction (clearing `is_current`, updating
  the row to `Hired`, decrementing the vacancy, superseding competing
  agencies' rows) is atomic** — wrap it in `$pdo->beginTransaction()`
  / `commit()` / `rollBack()`, matching the transactional discipline
  Phase 1's `generate_employer_id()`/`activate_agency` already
  established. A failure at any step must leave nothing partially
  applied.
- **`agency_id` is always server-derived for a Partner Agency session**
  via `current_agency_id($pdo)` — never trusted from a posted value.
  Administrator/Employee's chosen agency is validated against
  `active_agencies()`. Every action targeting an *existing*
  `employment_records` row re-resolves that row's true `agency_id`
  from the database first and 403s a Partner Agency session whose own
  agency doesn't match — the same DB-resolved-ownership pattern this
  codebase's other POST handlers already use
  (`vacancies.php`/`applicant-view.php`'s existing actions).
- **Every new POST action calls `csrf_require()` and `audit_log()`**,
  and re-checks role/ownership server-side inside its own handler, in
  addition to the page's top-of-file gate — this codebase's standing
  double-enforcement convention.
- **`e()` escaping** on all new dynamic output, including remarks
  text. **Never embed a bare `json_encode(...)` result inside a
  double-quoted HTML attribute** — a prior phase's plan shipped this
  exact bug (see Phase 2's ledger); if any task here needs to pass a
  PHP value into an `x-init`/`onclick`/similar attribute, wrap it in
  `e(json_encode(...))`.
- **`date_hired` is now nullable** — every place that reads/displays
  it must handle `NULL` (a `For Review` row has no hire date yet).
  `format_date(null)`'s exact behavior should be verified, not
  assumed, by the task that touches it — guard explicitly
  (`$rec['date_hired'] ? format_date($rec['date_hired']) : '—'`)
  rather than relying on `format_date()` to handle `null` gracefully
  on its own.
- **The existing generic Enable/Disable/Delete/Edit actions on a
  genuinely confirmed employment record** (any `employment_status`
  other than `For Review`/`Withdrawn`/`Superseded` — i.e. `Hired`,
  `Job Order`, `Temporary`, `COS`, `Permanent`, `Casual`, `Other`) **are
  unchanged by this plan** — they must keep working exactly as they do
  today. Only `For Review` rows get the new Confirm Hired/Withdraw
  actions instead; `Withdrawn`/`Superseded` rows get no actions at all
  (read-only history).
- **The unrelated internal Employment module** (`employment-form.php`,
  used by Administrator/Employee to add a `Job Order`/`Temporary`/
  `COS`/`Permanent`/`Casual`/`Other` record directly) **is out of
  scope** — no task in this plan touches that file.

## Preflight file-touch map

| Task | Files created/modified | Touched by another task? |
|------|------------------------|---------------------------|
| 1 | database/migrations/add_application_tracking_and_hiring_workflow.sql (new) | No |
| 2 | public/dashboard.php, public/vacancies.php, public/users.php, public/api/applicants.php | No |
| 3 | public/applicant-view.php | No |
| 4 | none (verification only) | n/a |

No two tasks touch the same file. Interface dependency: Task 1's
schema changes (`employment_status` ENUM values, nullable `date_hired`,
`vacancy_id` column) are consumed by Task 3. Task 2 is independent of
Task 1's schema (it only queries columns that already exist) but
should still run after Task 1 so the whole branch stays in a
consistently-migrated state at every commit. Strictly ascending
dispatch order 1→4 satisfies both.

---

### Task 1: Migration

**Files:**
- Create: `database/migrations/add_application_tracking_and_hiring_workflow.sql`

**Interfaces:**
- Produces: three new `employment_status` ENUM values (`For Review`,
  `Withdrawn`, `Superseded`), a nullable `date_hired` column, and a new
  nullable `vacancy_id` column (FK to `care_jf_job_vacancies`, `ON
  DELETE RESTRICT`) on `care_jf_employment_records` — consumed by
  Task 3.
- Consumes: nothing from earlier tasks (this is the first task).

- [ ] **Step 1: Write the migration**

Create `database/migrations/add_application_tracking_and_hiring_workflow.sql`:

```sql
-- Migration: Application Tracking & Hiring Workflow (Phase 3 of 3)
--
-- Extends care_jf_employment_records to also track a Partner Agency's
-- in-progress interest in an applicant, not just confirmed employment.
-- One row per agency progresses through employment_status states:
--   'For Review'  -> tagged, not yet a hire (is_current always 0)
--   'Hired'       -> confirmed (is_current = 1 for the winning row)
--   'Withdrawn'   -> the agency released their own tag
--   'Superseded'  -> auto-closed because another agency hired first
-- date_hired is NULL until a row actually becomes 'Hired'.
-- vacancy_id links a confirmed hire back to the Job Vacancy it filled
-- (care_jf_job_vacancies.vacant_count is decremented at that same
-- moment -- see public/applicant-view.php's confirm_hired action).
--
-- No new table -- see
-- docs/superpowers/specs/2026-09-08-application-tracking-hiring-workflow-design.md
-- for the full rationale.

ALTER TABLE care_jf_employment_records
  MODIFY COLUMN employment_status ENUM(
    'Job Order','Temporary','COS','Permanent','Casual','Other','Hired',
    'For Review','Withdrawn','Superseded'
  ) NOT NULL;

ALTER TABLE care_jf_employment_records
  MODIFY COLUMN date_hired DATE NULL;

ALTER TABLE care_jf_employment_records
  ADD COLUMN vacancy_id INT UNSIGNED NULL AFTER agency_id,
  ADD CONSTRAINT fk_employment_vacancy FOREIGN KEY (vacancy_id) REFERENCES care_jf_job_vacancies(id) ON DELETE RESTRICT;
```

- [ ] **Step 2: Apply it to the local database**

```bash
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db < database/migrations/add_application_tracking_and_hiring_workflow.sql
```

- [ ] **Step 3: Verify**

```bash
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SHOW CREATE TABLE care_jf_employment_records\G"
```
Expected: `employment_status` ENUM includes all 10 values, `date_hired`
shows `DEFAULT NULL` (nullable), `vacancy_id` column present with the
`fk_employment_vacancy` constraint referencing `care_jf_job_vacancies`
with `ON DELETE RESTRICT`. Confirm existing data survived — e.g.:
```bash
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT id, employment_status, date_hired, vacancy_id FROM care_jf_employment_records LIMIT 5;"
```
Expected: pre-existing rows unaffected (their `employment_status`
values still valid members of the widened ENUM, `date_hired` still
populated, `vacancy_id` NULL since nothing has set it yet).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/add_application_tracking_and_hiring_workflow.sql
git commit -m "feat: extend employment_records schema for application tracking & hiring workflow"
```

---

### Task 2: Small fixes batch — dashboard chart guard, vacancy delete guard, Partner Agency password reset, applicants-list visibility

**Files:**
- Modify: `public/dashboard.php`
- Modify: `public/vacancies.php`
- Modify: `public/users.php`
- Modify: `public/api/applicants.php`

**Interfaces:**
- Consumes: nothing new from Task 1 (all four edits below query
  columns/tables that already existed before this plan — this task's
  correctness doesn't depend on Task 1's schema change, but it should
  still be dispatched after Task 1 so every commit on this branch
  reflects a consistently-migrated database).
- Produces: nothing consumed by later tasks.

These are four small, independent, mechanical edits in four different
files — batched into one dispatch per this codebase's "batch small
same-shape work" convention rather than four separate task overheads.
The `api/applicants.php` edit (spec §6, the "hide once hired from
other agencies" rule) is genuinely small in code size, but it is a
real privacy/correctness requirement, not a nice-to-have — Task 4's
verification specifically checks it.

- [ ] **Step 1: `public/dashboard.php` — add the missing `is_current` guard**

Find:

```php
$hireByMonth = $pdo->query(
    "SELECT DATE_FORMAT(date_hired, '%Y-%m') AS ym, COUNT(*) AS total
     FROM care_jf_employment_records WHERE status = 'Active'
     GROUP BY ym ORDER BY ym DESC LIMIT 6"
)->fetchAll();
```

Replace with:

```php
$hireByMonth = $pdo->query(
    "SELECT DATE_FORMAT(date_hired, '%Y-%m') AS ym, COUNT(*) AS total
     FROM care_jf_employment_records WHERE status = 'Active' AND is_current = 1
     GROUP BY ym ORDER BY ym DESC LIMIT 6"
)->fetchAll();
```

This matches every other `care_jf_employment_records` query in this
same file — all of them already filter `is_current = 1`; this one was
the sole exception, and now that non-current `For Review`/`Withdrawn`/
`Superseded` rows exist, it would otherwise corrupt the "Applicants
Hired Per Month" chart with a spurious NULL-month bucket (since a
`For Review` row's `date_hired` is `NULL`).

- [ ] **Step 2: `public/vacancies.php` — add the dependency guard the delete action's own comment already anticipated**

Find:

```php
    } elseif ($action === 'delete') {
        if (!can_delete()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — Only an Administrator can delete a job vacancy.</h2>');
        }
        // No dependent records can exist yet in Phase 1 (employment_records
        // has no vacancy_id column until Phase 3) — once Phase 3 adds that
        // column, a dependency check belongs here before the DELETE, same
        // pattern as partner-agency.php's employment-history guard.
        $pdo->prepare("DELETE FROM care_jf_job_vacancies WHERE id = :id")->execute([':id' => $id]);
        audit_log($pdo, (int)current_user()['id'], 'VACANCY_DELETE', 'care_jf_job_vacancies', $id, 'Job vacancy deleted');
        flash_set('success', 'Job vacancy deleted.');
    }
```

Replace with:

```php
    } elseif ($action === 'delete') {
        if (!can_delete()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — Only an Administrator can delete a job vacancy.</h2>');
        }
        $historyStmt = $pdo->prepare("SELECT COUNT(*) FROM care_jf_employment_records WHERE vacancy_id = :id");
        $historyStmt->execute([':id' => $id]);
        if ((int)$historyStmt->fetchColumn() > 0) {
            flash_set('error', 'This vacancy has hire history linked to it and cannot be deleted. Disable it instead.');
        } else {
            $pdo->prepare("DELETE FROM care_jf_job_vacancies WHERE id = :id")->execute([':id' => $id]);
            audit_log($pdo, (int)current_user()['id'], 'VACANCY_DELETE', 'care_jf_job_vacancies', $id, 'Job vacancy deleted');
            flash_set('success', 'Job vacancy deleted.');
        }
    }
```

Mirrors `partner-agency.php`'s existing employment-history guard
exactly (count check → flash error and skip the delete, or proceed).

- [ ] **Step 3: `public/users.php` — add `resettingAgencyId` to the page's Alpine state**

Find:

```php
<div class="space-y-6" x-data="{ tab: '<?= $initialTab ?>', showCreate: false, editingId: null, resettingId: null }">
```

Replace with:

```php
<div class="space-y-6" x-data="{ tab: '<?= $initialTab ?>', showCreate: false, editingId: null, resettingId: null, resettingAgencyId: null }">
```

- [ ] **Step 4: `public/users.php` — add a Reset Password action to the Partner Agency Accounts tab**

Find:

```php
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

Replace with:

```php
            <?php endif; ?>
            <button @click="resettingAgencyId = resettingAgencyId === <?= (int)$a['id'] ?> ? null : <?= (int)$a['id'] ?>" class="text-slate-500 hover:text-purple-600 px-1.5" title="Reset Password"><i class="fa-solid fa-key"></i></button>
            <form method="POST" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_agency_account">
              <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
              <button type="button" data-confirm-delete="<?= e($a['agency_name'] . ' (' . $a['username'] . ')') ?>" class="text-slate-500 hover:text-red-600 px-1.5" title="Delete Account"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <!-- Inline reset password row -->
        <tr x-show="resettingAgencyId === <?= (int)$a['id'] ?>" x-cloak>
          <td colspan="9" class="px-4 py-4 bg-slate-50">
            <form method="POST" class="flex flex-wrap items-end gap-3" onsubmit="return validateForm(this);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">New Temporary Password</label>
                <input type="password" name="new_password" required minlength="8" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
              </div>
              <button type="submit" class="px-4 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-medium">Reset Password</button>
              <button type="button" @click="resettingAgencyId = null" class="px-4 py-1.5 rounded-lg border border-slate-300 text-sm">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div x-show="tab === 'users'" x-cloak>
```

`colspan="9"` matches this table's 9 columns (Agency Name, Employer ID,
Contact Person, Contact No, Email, Username, Registered, Account
Status, Actions). This reuses the existing generic `reset_password`
POST action verbatim (already implemented, operates on any
`care_jf_users` row by `user_id`) — no backend change needed, this
step is UI-only, mirroring the System Users tab's own reset-password
row (same file, `resettingId`/inline-form pattern) exactly.

- [ ] **Step 5: `public/api/applicants.php` — hide an applicant hired by another agency, for a Partner Agency session**

Find:

```php
if ($dateTo !== '') {
    $where[] = 'DATE(a.created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereSql = implode(' AND ', $where);
```

Replace with:

```php
if ($dateTo !== '') {
    $where[] = 'DATE(a.created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}
if (is_partner_agency()) {
    // Once hired, an applicant disappears from every OTHER agency's
    // pool — but the hiring agency keeps seeing their own hire. This
    // reuses the query's existing LEFT JOIN against the current
    // active employment record (already present for current_status
    // below) rather than adding a second join. Not hired at all
    // (er.id IS NULL) is always visible; hired by this same agency is
    // always visible; hired by anyone else is excluded.
    $where[] = '(er.id IS NULL OR er.agency_id = :my_agency_id)';
    $params[':my_agency_id'] = current_agency_id($pdo);
}

$whereSql = implode(' AND ', $where);
```

This must come after the `LEFT JOIN care_jf_employment_records er ON
er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'`
already present lower in this same file's `$sql` string — that join is
unconditional and already exists (it's how `current_status` is
computed), so `er.id`/`er.agency_id` are already available to reference
in `$where` without any change to the `FROM`/`JOIN` clauses themselves.

- [ ] **Step 6: Verify**

```bash
/c/xampp/php/php.exe -l public/dashboard.php
/c/xampp/php/php.exe -l public/vacancies.php
/c/xampp/php/php.exe -l public/users.php
/c/xampp/php/php.exe -l public/api/applicants.php
```
Expected: no syntax errors on any of the four.

Then, with the built-in server running (same pattern as prior phases):
```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/phase3_task2_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null
for PAGE in dashboard.php vacancies.php "users.php?tab=partner-agencies" "api/applicants.php"; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$PAGE")
  echo "admin: $PAGE -> $STATUS"
done
grep -i 'warning\|fatal\|error' /tmp/phase3_task2_server.log || echo "no server-log warnings/errors"
kill %1
```
Expected: 200 for all four (the Administrator session hits
`api/applicants.php`'s normal, unfiltered path — the new filter only
activates for `is_partner_agency()`, so this just confirms no syntax/
runtime regression for the common case). Also confirm the "Reset
Password" key icon now appears in the Partner Agency Accounts table by
grepping the fetched `users.php?tab=partner-agencies` HTML for
`resettingAgencyId`.

If a Partner Agency test session is available, additionally confirm
`api/applicants.php`'s JSON response as that agency excludes an
applicant known to be hired by a *different* agency, while still
including one hired by their own agency and every not-yet-hired
applicant — otherwise note in your report that this specific check
needs a second agency account and was verified structurally only
(reading the generated SQL/params) rather than against live data.

- [ ] **Step 7: Commit**

```bash
git add public/dashboard.php public/vacancies.php public/users.php public/api/applicants.php
git commit -m "fix: guard hires-per-month chart against non-current rows; add vacancy delete guard, Partner Agency password reset, and hide-once-hired applicants-list filter"
```

---

### Task 3: `applicant-view.php` — Tag for Review, Confirm Hired, Withdraw, Remarks

**Files:**
- Modify: `public/applicant-view.php`

**Interfaces:**
- Consumes: the widened `employment_status` ENUM, nullable
  `date_hired`, and `vacancy_id` column from Task 1.
- Produces: nothing consumed by later tasks (Task 4 is
  verification-only).

This is the core task — it replaces the retired one-click `mark_hired`
POST action with four new ones, makes the Employment History query
role-scoped, and reworks the Employment History table's display and
per-row actions. Read the whole file first; the diffs below assume the
file's current post-Phase-2 state (it already has the `?code=` lookup
and the QR code card from that phase — untouched by this task).

- [ ] **Step 1: Replace the `mark_hired` POST action with four new actions**

Find the entire `mark_hired` branch:

```php
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

        $agStmt = $pdo->prepare("SELECT agency_name, address FROM care_jf_partner_agencies WHERE id = :id");
        $agStmt->execute([':id' => $hireAgencyId]);
        $hireAgencyRow = $agStmt->fetch();
        if (!$hireAgencyRow) {
            flash_set('error', 'Selected Partner Agency was not found.');
            redirect('applicant-view.php?id=' . $id);
        }

        // Guard against a double-hire: only proceed if the applicant genuinely
        // still has no current active employment record right now.
        $hireCheckStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'"
        );
        $hireCheckStmt->execute([':id' => $id]);
        if ((int)$hireCheckStmt->fetchColumn() > 0) {
            flash_set('error', 'This applicant has already been hired.');
            redirect('applicant-view.php?id=' . $id);
        }

        $hireStmt = $pdo->prepare(
            "INSERT INTO care_jf_employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
             VALUES (:aid, :agid, :agency, :address, CURDATE(), 'Hired', 1, 'Active')"
        );
        $hireStmt->execute([
            ':aid' => $id, ':agid' => $hireAgencyId,
            ':agency' => $hireAgencyRow['agency_name'], ':address' => $hireAgencyRow['address'],
        ]);
        $newHireId = (int)$pdo->lastInsertId();
        audit_log($pdo, (int)current_user()['id'], 'MARK_HIRED', 'care_jf_employment_records', $newHireId,
            "Applicant {$applicant['applicant_code']} marked Hired by {$hireAgencyRow['agency_name']}");
        flash_set('success', 'Applicant marked as Hired.');
        redirect('applicant-view.php?id=' . $id);
    }
```

Replace with:

```php
    } elseif ($action === 'tag_for_review') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        if (is_applicant_hired($pdo, $id)) {
            flash_set('error', 'This applicant has already been hired.');
            redirect('applicant-view.php?id=' . $id);
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

        $agStmt = $pdo->prepare("SELECT agency_name, address FROM care_jf_partner_agencies WHERE id = :id");
        $agStmt->execute([':id' => $reviewAgencyId]);
        $reviewAgencyRow = $agStmt->fetch();
        if (!$reviewAgencyRow) {
            flash_set('error', 'Selected Partner Agency was not found.');
            redirect('applicant-view.php?id=' . $id);
        }

        // No duplicate concurrent tags from the same agency — re-tagging is
        // fine once a prior tag has moved to Withdrawn/Superseded/Hired,
        // since none of those match this check.
        $dupStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND agency_id = :agid AND employment_status = 'For Review'"
        );
        $dupStmt->execute([':id' => $id, ':agid' => $reviewAgencyId]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            flash_set('error', 'This agency has already tagged this applicant for review.');
            redirect('applicant-view.php?id=' . $id);
        }

        $tagStmt = $pdo->prepare(
            "INSERT INTO care_jf_employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
             VALUES (:aid, :agid, :agency, :address, NULL, 'For Review', 0, 'Active')"
        );
        $tagStmt->execute([
            ':aid' => $id, ':agid' => $reviewAgencyId,
            ':agency' => $reviewAgencyRow['agency_name'], ':address' => $reviewAgencyRow['address'],
        ]);
        $newReviewId = (int)$pdo->lastInsertId();
        audit_log($pdo, (int)current_user()['id'], 'APPLICANT_TAGGED_FOR_REVIEW', 'care_jf_employment_records', $newReviewId,
            "Applicant {$applicant['applicant_code']} tagged For Review by {$reviewAgencyRow['agency_name']}");
        flash_set('success', 'Applicant tagged for review.');
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'confirm_hired') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        $recordId = (int)($_POST['record_id'] ?? 0);
        $vacancyId = (int)($_POST['vacancy_id'] ?? 0);

        // Resolve and verify ownership of the review row being confirmed —
        // DB-resolved, never trusted from the request.
        $rowStmt = $pdo->prepare(
            "SELECT agency_id FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid AND employment_status = 'For Review'"
        );
        $rowStmt->execute([':id' => $recordId, ':aid' => $id]);
        $rowAgencyId = (int)($rowStmt->fetchColumn() ?: 0);

        if (!$rowAgencyId || (is_partner_agency() && $rowAgencyId !== current_agency_id($pdo))) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to confirm this hire.</h2>');
        }

        if (!$vacancyId) {
            flash_set('error', 'Select a Job Vacancy to confirm this hire.');
            redirect('applicant-view.php?id=' . $id);
        }

        $pdo->beginTransaction();
        try {
            // Vacancy must belong to the same agency and still have an
            // opening — re-validated here, never trusted from the form.
            $vacStmt = $pdo->prepare(
                "SELECT vacant_count FROM care_jf_job_vacancies WHERE id = :vid AND agency_id = :agid AND status = 'Active'"
            );
            $vacStmt->execute([':vid' => $vacancyId, ':agid' => $rowAgencyId]);
            $vacantCount = $vacStmt->fetchColumn();
            if ($vacantCount === false || (int)$vacantCount < 1) {
                $pdo->rollBack();
                flash_set('error', 'Selected Job Vacancy is not available.');
                redirect('applicant-view.php?id=' . $id);
            }

            // Guard against a double-hire: only proceed if the applicant
            // genuinely still has no other current active employment record.
            $hireCheckStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM care_jf_employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'"
            );
            $hireCheckStmt->execute([':id' => $id]);
            if ((int)$hireCheckStmt->fetchColumn() > 0) {
                $pdo->rollBack();
                flash_set('error', 'This applicant has already been hired.');
                redirect('applicant-view.php?id=' . $id);
            }

            // Standing is_current bookkeeping rule: clear before setting.
            $pdo->prepare("UPDATE care_jf_employment_records SET is_current = 0 WHERE applicant_id = :aid")
                ->execute([':aid' => $id]);

            $pdo->prepare(
                "UPDATE care_jf_employment_records SET employment_status = 'Hired', is_current = 1, date_hired = CURDATE(), vacancy_id = :vid WHERE id = :id"
            )->execute([':vid' => $vacancyId, ':id' => $recordId]);

            $newVacantCount = (int)$vacantCount - 1;
            $newVacStatus = $newVacantCount <= 0 ? 'Filled' : 'Active';
            $pdo->prepare("UPDATE care_jf_job_vacancies SET vacant_count = :vc, status = :st WHERE id = :id")
                ->execute([':vc' => $newVacantCount, ':st' => $newVacStatus, ':id' => $vacancyId]);

            // Auto-close every other agency's still-open tag on this applicant.
            $superStmt = $pdo->prepare(
                "SELECT id FROM care_jf_employment_records WHERE applicant_id = :aid AND employment_status = 'For Review' AND id != :rid"
            );
            $superStmt->execute([':aid' => $id, ':rid' => $recordId]);
            foreach ($superStmt->fetchAll(PDO::FETCH_COLUMN) as $supersededId) {
                $pdo->prepare("UPDATE care_jf_employment_records SET employment_status = 'Superseded' WHERE id = :id")
                    ->execute([':id' => $supersededId]);
                audit_log($pdo, (int)current_user()['id'], 'APPLICANT_REVIEW_SUPERSEDED', 'care_jf_employment_records', (int)$supersededId,
                    "Superseded by another agency's confirmed hire");
            }

            audit_log($pdo, (int)current_user()['id'], 'APPLICANT_HIRED', 'care_jf_employment_records', $recordId,
                "Applicant {$applicant['applicant_code']} hired");
            audit_log($pdo, (int)current_user()['id'], 'VACANCY_DECREMENT', 'care_jf_job_vacancies', $vacancyId,
                "Vacancy decremented (hire: {$applicant['applicant_code']})");

            $pdo->commit();
            flash_set('success', 'Applicant confirmed as Hired.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Confirm Hired failed: ' . $e->getMessage());
            flash_set('error', 'Confirming this hire failed due to a system error. Please try again.');
        }
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'withdraw_review') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        $recordId = (int)($_POST['record_id'] ?? 0);
        $rowStmt = $pdo->prepare(
            "SELECT agency_id FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid AND employment_status = 'For Review'"
        );
        $rowStmt->execute([':id' => $recordId, ':aid' => $id]);
        $rowAgencyId = (int)($rowStmt->fetchColumn() ?: 0);

        if (!$rowAgencyId || (is_partner_agency() && $rowAgencyId !== current_agency_id($pdo))) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to withdraw this tag.</h2>');
        }

        $pdo->prepare("UPDATE care_jf_employment_records SET employment_status = 'Withdrawn' WHERE id = :id")
            ->execute([':id' => $recordId]);
        audit_log($pdo, (int)current_user()['id'], 'APPLICANT_REVIEW_WITHDRAWN', 'care_jf_employment_records', $recordId,
            "Applicant {$applicant['applicant_code']} review tag withdrawn");
        flash_set('success', 'Review tag withdrawn.');
        redirect('applicant-view.php?id=' . $id);
    } elseif ($action === 'update_remarks') {
        if (!can_manage_employment() && !is_partner_agency()) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to perform this action.</h2>');
        }

        $recordId = (int)($_POST['record_id'] ?? 0);
        $remarks = clean($_POST['remarks'] ?? '');

        if (mb_strlen($remarks) > 255) {
            flash_set('error', 'Remarks must be 255 characters or fewer.');
            redirect('applicant-view.php?id=' . $id);
        }

        $rowStmt = $pdo->prepare(
            "SELECT agency_id FROM care_jf_employment_records WHERE id = :id AND applicant_id = :aid AND employment_status IN ('For Review', 'Hired')"
        );
        $rowStmt->execute([':id' => $recordId, ':aid' => $id]);
        $rowAgencyId = (int)($rowStmt->fetchColumn() ?: 0);

        if (!$rowAgencyId || (is_partner_agency() && $rowAgencyId !== current_agency_id($pdo))) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif">403 — You do not have permission to update remarks on this record.</h2>');
        }

        $pdo->prepare("UPDATE care_jf_employment_records SET remarks = :r WHERE id = :id")
            ->execute([':r' => $remarks !== '' ? $remarks : null, ':id' => $recordId]);
        audit_log($pdo, (int)current_user()['id'], 'APPLICANT_REVIEW_REMARKS_UPDATED', 'care_jf_employment_records', $recordId,
            "Remarks updated for applicant {$applicant['applicant_code']}");
        flash_set('success', 'Remarks saved.');
        redirect('applicant-view.php?id=' . $id);
    }
```

- [ ] **Step 2: Make the Employment History query role-scoped, fix its ordering, and compute the new display-support variables**

Find:

```php
$empStmt = $pdo->prepare(
    "SELECT er.*, COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name,
            pa.contact_person AS agency_contact_person, pa.contact_no AS agency_contact_no, pa.email AS agency_email
     FROM care_jf_employment_records er
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     WHERE er.applicant_id = :id ORDER BY er.date_hired DESC, er.id DESC"
);
$empStmt->execute([':id' => $id]);
$employmentRecords = $empStmt->fetchAll();

$status = current_employment_status($pdo, $id);

$hireAgencies = can_manage_employment() ? active_agencies($pdo) : [];
$myAgencyName = '';
if (is_partner_agency()) {
    $myAgencyStmt = $pdo->prepare("SELECT agency_name FROM care_jf_partner_agencies WHERE id = :id");
    $myAgencyStmt->execute([':id' => current_agency_id($pdo)]);
    $myAgencyName = (string)$myAgencyStmt->fetchColumn();
}
```

Replace with:

```php
$empWhere = "er.applicant_id = :id";
$empParams = [':id' => $id];
if (is_partner_agency()) {
    // Partner Agency sees every confirmed/historical record exactly as
    // before, plus only their OWN in-flight tags — never another
    // agency's For Review/Withdrawn/Superseded row.
    $empWhere .= " AND (er.employment_status NOT IN ('For Review', 'Withdrawn', 'Superseded') OR er.agency_id = :myagid)";
    $empParams[':myagid'] = current_agency_id($pdo);
}
$empStmt = $pdo->prepare(
    "SELECT er.*, COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name,
            pa.contact_person AS agency_contact_person, pa.contact_no AS agency_contact_no, pa.email AS agency_email
     FROM care_jf_employment_records er
     LEFT JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
     WHERE $empWhere ORDER BY er.created_at DESC, er.id DESC"
);
$empStmt->execute($empParams);
$employmentRecords = $empStmt->fetchAll();

$status = current_employment_status($pdo, $id);
$applicantIsHired = is_applicant_hired($pdo, $id);

$myAgencyName = '';
$myAgencyIdForCheck = null;
if (is_partner_agency()) {
    $myAgencyIdForCheck = current_agency_id($pdo);
    $myAgencyStmt = $pdo->prepare("SELECT agency_name FROM care_jf_partner_agencies WHERE id = :id");
    $myAgencyStmt->execute([':id' => $myAgencyIdForCheck]);
    $myAgencyName = (string)$myAgencyStmt->fetchColumn();
}

$myOpenReview = null;
if (is_partner_agency()) {
    foreach ($employmentRecords as $rec) {
        if ($rec['employment_status'] === 'For Review' && (int)$rec['agency_id'] === $myAgencyIdForCheck) {
            $myOpenReview = $rec;
            break;
        }
    }
}

// Agencies with an open (For Review) tag on this applicant right now —
// used both to scope which agencies' vacancies to fetch and to exclude
// them from the "tag a new agency" dropdown (no duplicate concurrent tags).
$reviewingAgencyIds = array_values(array_unique(array_map('intval', array_column(
    array_filter($employmentRecords, fn($r) => $r['employment_status'] === 'For Review'), 'agency_id'
))));

$vacancyOptionsByAgency = [];
if ($reviewingAgencyIds) {
    $inClause = implode(',', array_fill(0, count($reviewingAgencyIds), '?'));
    $vacStmt = $pdo->prepare(
        "SELECT id, agency_id, position, job_level, vacant_count FROM care_jf_job_vacancies
         WHERE agency_id IN ($inClause) AND status = 'Active' AND vacant_count > 0 ORDER BY position"
    );
    $vacStmt->execute($reviewingAgencyIds);
    foreach ($vacStmt->fetchAll() as $vRow) {
        $vacancyOptionsByAgency[(int)$vRow['agency_id']][] = $vRow;
    }
}

$tagAgencyOptions = can_manage_employment()
    ? array_values(array_filter(active_agencies($pdo), fn($ag) => !in_array((int)$ag['id'], $reviewingAgencyIds, true)))
    : [];
```

`$hireAgencies` is removed entirely — nothing uses it after this task
(the retired Mark-as-Hired modal was its only consumer); `$tagAgencyOptions`
takes over that role for the new Tag-for-Review modal, additionally
filtered to exclude agencies that already have an open tag.

- [ ] **Step 3: Rework the Employment History section header (Tag for Review trigger)**

Find:

```php
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Employment History</h2>
          <?php if (can_edit()): ?>
          <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=add" class="text-xs font-medium text-brand-600 hover:text-brand-800 print:hidden"><i class="fa-solid fa-plus mr-1"></i> Add Record</a>
          <?php endif; ?>
        </div>
```

Replace with:

```php
      <?php
        $canTagAsPartnerAgency = is_partner_agency() && !$myOpenReview && !$applicantIsHired;
        $canTagAsStaff = can_manage_employment() && $tagAgencyOptions && !$applicantIsHired;
      ?>
      <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6" x-data="{ confirmHireRecordId: null, showTagForReview: false }">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-brand-700 uppercase tracking-wide">Employment History</h2>
          <div class="flex gap-3 print:hidden">
            <?php if ($canTagAsPartnerAgency || $canTagAsStaff): ?>
            <button type="button" @click="showTagForReview = true" class="text-xs font-medium text-emerald-600 hover:text-emerald-800"><i class="fa-solid fa-flag mr-1"></i> Tag for Review</button>
            <?php endif; ?>
            <?php if (can_edit()): ?>
            <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=add" class="text-xs font-medium text-brand-600 hover:text-brand-800"><i class="fa-solid fa-plus mr-1"></i> Add Record</a>
            <?php endif; ?>
          </div>
        </div>

        <div x-show="showTagForReview" x-cloak class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4">
          <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6" @click.outside="showTagForReview = false">
            <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-flag text-emerald-600 mr-1"></i> Tag for Review</h3>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="tag_for_review">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <?php if (can_manage_employment()): ?>
                <label class="block text-sm font-medium text-slate-700 mb-1">Partner Agency <span class="text-red-500">*</span></label>
                <select name="agency_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4">
                  <option value="">Select a Partner Agency</option>
                  <?php foreach ($tagAgencyOptions as $ag): ?>
                    <option value="<?= (int)$ag['id'] ?>"><?= e($ag['agency_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <p class="text-sm text-slate-600 mb-5">Tag <?= e(full_name($applicant)) ?> for review by <strong><?= e($myAgencyName) ?></strong>?</p>
              <?php endif; ?>
              <div class="flex justify-end gap-2">
                <button type="button" @click="showTagForReview = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium">Confirm Tag</button>
              </div>
            </form>
          </div>
        </div>
```

Note the wrapping `<div>`'s `x-data` moves onto this card (it had none
before) — `confirmHireRecordId`/`showTagForReview` are shared by every
row's Confirm Hired button/modal added in the next step.

- [ ] **Step 4: Rework the Employment History table body — badges, null-safe date, role-scoped actions, remarks, Confirm Hired modal**

Find:

```php
        <?php if (!$employmentRecords): ?>
          <div class="text-center py-8 text-slate-400">
            <i class="fa-solid fa-briefcase text-2xl mb-2 block"></i> No employment records yet.
          </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-xs uppercase text-slate-500 border-b border-slate-100">
              <tr>
                <th class="text-left py-2 pr-3">Agency / Company</th>
                <th class="text-left py-2 pr-3">Date Hired</th>
                <th class="text-left py-2 pr-3">Classification</th>
                <th class="text-left py-2 pr-3">Record Status</th>
                <th class="text-right py-2 print:hidden">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($employmentRecords as $rec): ?>
              <tr>
                <td class="py-2.5 pr-3">
                  <?= e($rec['agency_display_name']) ?>
                  <?php if ($rec['is_current']): ?>
                    <span class="ml-1 text-[10px] font-semibold text-green-700 bg-green-100 px-1.5 py-0.5 rounded-full align-middle">CURRENT</span>
                  <?php endif; ?>
                  <p class="text-xs text-slate-400"><?= e($rec['agency_company_address']) ?></p>
                  <?php if (!empty($rec['remarks'])): ?>
                    <p class="text-xs text-slate-500 mt-1"><i class="fa-solid fa-note-sticky text-slate-400 mr-1"></i><?= e($rec['remarks']) ?></p>
                  <?php endif; ?>
                </td>
                <td class="py-2.5 pr-3"><?= format_date($rec['date_hired']) ?></td>
                <td class="py-2.5 pr-3 uppercase"><?= e($rec['employment_status']) ?></td>
                <td class="py-2.5 pr-3">
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $rec['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($rec['status']) ?></span>
                </td>
                <td class="py-2.5 text-right print:hidden">
                  <?php if (can_edit()): ?>
                  <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=edit&record_id=<?= (int)$rec['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></a>
                  <form method="POST" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                    <input type="hidden" name="action" value="<?= $rec['status']==='Active' ? 'disable_employment' : 'enable_employment' ?>">
                    <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $rec['status']==='Active' ? 'Disable' : 'Enable' ?>">
                      <i class="fa-solid <?= $rec['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                  <?php if (can_delete()): ?>
                  <form method="POST" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="delete_employment">
                    <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                    <button type="button" data-confirm-delete="this employment record" class="text-slate-500 hover:text-red-600 px-1" title="Delete"><i class="fa-solid fa-trash"></i></button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
```

Replace with:

```php
        <?php if (!$employmentRecords): ?>
          <div class="text-center py-8 text-slate-400">
            <i class="fa-solid fa-briefcase text-2xl mb-2 block"></i> No employment records yet.
          </div>
        <?php else: ?>
        <?php $classColors = [
            'For Review' => 'bg-amber-100 text-amber-800',
            'Hired'      => 'bg-emerald-100 text-emerald-800',
            'Withdrawn'  => 'bg-gray-100 text-gray-500',
            'Superseded' => 'bg-gray-100 text-gray-500',
        ]; ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-xs uppercase text-slate-500 border-b border-slate-100">
              <tr>
                <th class="text-left py-2 pr-3">Agency / Company</th>
                <th class="text-left py-2 pr-3">Date Hired</th>
                <th class="text-left py-2 pr-3">Classification</th>
                <th class="text-left py-2 pr-3">Record Status</th>
                <th class="text-right py-2 print:hidden">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($employmentRecords as $rec): ?>
              <?php
                $isOwnAgencyRow = !empty($rec['agency_id']) && is_partner_agency() && (int)$rec['agency_id'] === current_agency_id($pdo);
                $canActOnReview = can_manage_employment() || $isOwnAgencyRow;
                $canEditRemarks = in_array($rec['employment_status'], ['For Review', 'Hired'], true) && $canActOnReview;
              ?>
              <tr>
                <td class="py-2.5 pr-3">
                  <?= e($rec['agency_display_name']) ?>
                  <?php if ($rec['is_current']): ?>
                    <span class="ml-1 text-[10px] font-semibold text-green-700 bg-green-100 px-1.5 py-0.5 rounded-full align-middle">CURRENT</span>
                  <?php endif; ?>
                  <p class="text-xs text-slate-400"><?= e($rec['agency_company_address']) ?></p>
                  <?php if ($canEditRemarks): ?>
                    <form method="POST" class="mt-1 flex items-start gap-1 print:hidden">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$id ?>">
                      <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                      <input type="hidden" name="action" value="update_remarks">
                      <input type="text" name="remarks" maxlength="255" value="<?= e($rec['remarks'] ?? '') ?>" placeholder="Add a private remark..." class="flex-1 text-xs rounded border border-slate-300 px-2 py-1">
                      <button type="submit" class="text-xs text-brand-600 hover:text-brand-800 px-1.5 py-1" title="Save Remarks"><i class="fa-solid fa-floppy-disk"></i></button>
                    </form>
                  <?php elseif (!empty($rec['remarks'])): ?>
                    <p class="text-xs text-slate-500 mt-1"><i class="fa-solid fa-note-sticky text-slate-400 mr-1"></i><?= e($rec['remarks']) ?></p>
                  <?php endif; ?>
                </td>
                <td class="py-2.5 pr-3"><?= $rec['date_hired'] ? format_date($rec['date_hired']) : '—' ?></td>
                <td class="py-2.5 pr-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium uppercase <?= $classColors[$rec['employment_status']] ?? 'bg-slate-100 text-slate-700' ?>"><?= e($rec['employment_status']) ?></span></td>
                <td class="py-2.5 pr-3">
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $rec['status']==='Active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($rec['status']) ?></span>
                </td>
                <td class="py-2.5 text-right print:hidden">
                  <?php if ($rec['employment_status'] === 'For Review' && $canActOnReview): ?>
                    <button type="button" @click="confirmHireRecordId = <?= (int)$rec['id'] ?>" class="text-slate-500 hover:text-emerald-600 px-1" title="Confirm Hired"><i class="fa-solid fa-handshake"></i></button>
                    <form method="POST" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$id ?>">
                      <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                      <input type="hidden" name="action" value="withdraw_review">
                      <button type="button" data-confirm-delete="<?= e($rec['agency_display_name']) ?>'s review tag" data-confirm-verb="Withdraw" class="text-slate-500 hover:text-red-600 px-1" title="Withdraw"><i class="fa-solid fa-rotate-left"></i></button>
                    </form>

                    <div x-show="confirmHireRecordId === <?= (int)$rec['id'] ?>" x-cloak class="fixed inset-0 bg-black/40 z-[90] flex items-center justify-center p-4">
                      <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6 text-left" @click.outside="confirmHireRecordId = null">
                        <h3 class="font-semibold text-slate-800 mb-2"><i class="fa-solid fa-circle-question text-emerald-600 mr-1"></i> Confirm Hire</h3>
                        <p class="text-sm text-slate-600 mb-3">Confirm <?= e(full_name($applicant)) ?> as hired by <strong><?= e($rec['agency_display_name']) ?></strong>?</p>
                        <form method="POST">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="confirm_hired">
                          <input type="hidden" name="id" value="<?= (int)$id ?>">
                          <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                          <?php $vacOptions = $vacancyOptionsByAgency[(int)$rec['agency_id']] ?? []; ?>
                          <label class="block text-sm font-medium text-slate-700 mb-1">Job Vacancy <span class="text-red-500">*</span></label>
                          <?php if ($vacOptions): ?>
                          <select name="vacancy_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-4">
                            <option value="">Select a Job Vacancy</option>
                            <?php foreach ($vacOptions as $v): ?>
                              <option value="<?= (int)$v['id'] ?>"><?= e($v['position']) ?> (<?= e($v['job_level']) ?>, <?= (int)$v['vacant_count'] ?> open)</option>
                            <?php endforeach; ?>
                          </select>
                          <?php else: ?>
                          <p class="text-xs text-red-500 mb-4">This agency has no open Job Vacancies with available slots. Add one under Job Vacancies first.</p>
                          <?php endif; ?>
                          <div class="flex justify-end gap-2">
                            <button type="button" @click="confirmHireRecordId = null" class="px-4 py-2 text-sm rounded-lg border border-slate-300">Cancel</button>
                            <button type="submit" <?= $vacOptions ? '' : 'disabled' ?> class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium disabled:opacity-50 disabled:cursor-not-allowed">Confirm &amp; Hire</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  <?php elseif (in_array($rec['employment_status'], ['Withdrawn', 'Superseded'], true)): ?>
                    <span class="text-xs text-slate-300 px-1">—</span>
                  <?php else: ?>
                    <?php if (can_edit()): ?>
                    <a href="employment-form.php?applicant_id=<?= (int)$id ?>&action=edit&record_id=<?= (int)$rec['id'] ?>" class="text-slate-500 hover:text-amber-600 px-1" title="Edit"><i class="fa-solid fa-pen"></i></a>
                    <form method="POST" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$id ?>">
                      <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                      <input type="hidden" name="action" value="<?= $rec['status']==='Active' ? 'disable_employment' : 'enable_employment' ?>">
                      <button type="submit" class="text-slate-500 hover:text-blue-600 px-1" title="<?= $rec['status']==='Active' ? 'Disable' : 'Enable' ?>">
                        <i class="fa-solid <?= $rec['status']==='Active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php if (can_delete()): ?>
                    <form method="POST" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$id ?>">
                      <input type="hidden" name="action" value="delete_employment">
                      <input type="hidden" name="record_id" value="<?= (int)$rec['id'] ?>">
                      <button type="button" data-confirm-delete="this employment record" class="text-slate-500 hover:text-red-600 px-1" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
```

- [ ] **Step 5: Retire the old "Mark as Hired" button and modal from the Employment Status sidebar card**

Find:

```php
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
```

Replace with:

```php
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
            <div><dt class="text-slate-500">Date Hired</dt><dd class="font-medium text-slate-800"><?= $current['date_hired'] ? format_date($current['date_hired']) : '—' ?></dd></div>
            <?php if (!empty($current['remarks'])): ?>
            <div><dt class="text-slate-500">Remarks</dt><dd class="font-medium text-slate-800"><?= e($current['remarks']) ?></dd></div>
            <?php endif; ?>
          </dl>
        <?php else: ?>
          <p class="mt-4 text-sm text-slate-500">Use the Employment History section to tag a Partner Agency for review, then confirm the hire once ready.</p>
        <?php endif; ?>
      </div>
```

The "Mark as Hired" button and its modal are gone entirely — replaced
by a short pointer to the new Employment History section, which is now
where every review/hire action lives.

- [ ] **Step 6: Verify**

```bash
/c/xampp/php/php.exe -l public/applicant-view.php
```
Expected: no syntax errors.

Then, with the built-in server running, exercise the full flow as
Administrator (credentials: admin/Admin@123) against a real,
not-yet-hired applicant and a real Active Partner Agency with at least
one Active vacancy with `vacant_count > 0` (create one via
`vacancies.php` first if none exists):

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/phase3_task3_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null

# Find a real not-yet-hired applicant id and a real agency id + vacancy id first:
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "
  SELECT a.id, a.applicant_code FROM care_jf_applicants a
  LEFT JOIN care_jf_employment_records er ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active'
  WHERE a.is_deleted = 0 AND er.id IS NULL LIMIT 1;"
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT id, agency_name FROM care_jf_partner_agencies WHERE status = 'Active' LIMIT 1;"
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT id, vacant_count FROM care_jf_job_vacancies WHERE status = 'Active' AND vacant_count > 0 LIMIT 1;"

# Tag for review (substitute real ids for <APPLICANT_ID>/<AGENCY_ID>):
CSRF2=$(curl -s -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?id=<APPLICANT_ID>" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" -o /dev/null -w "tag_for_review: %{http_code}\n" \
  --data-urlencode "csrf_token=$CSRF2" --data-urlencode "action=tag_for_review" \
  --data-urlencode "id=<APPLICANT_ID>" --data-urlencode "agency_id=<AGENCY_ID>" \
  "http://localhost:8899/applicant-view.php"

/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT id, employment_status, is_current, date_hired, vacancy_id FROM care_jf_employment_records WHERE applicant_id = <APPLICANT_ID> ORDER BY id DESC LIMIT 1;"
```
Expected: a new row with `employment_status = 'For Review'`,
`is_current = 0`, `date_hired = NULL`, `vacancy_id = NULL`. Note its
`id` as `<RECORD_ID>`, then confirm hire:

```bash
CSRF3=$(curl -s -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/applicant-view.php?id=<APPLICANT_ID>" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" -o /dev/null -w "confirm_hired: %{http_code}\n" \
  --data-urlencode "csrf_token=$CSRF3" --data-urlencode "action=confirm_hired" \
  --data-urlencode "id=<APPLICANT_ID>" --data-urlencode "record_id=<RECORD_ID>" --data-urlencode "vacancy_id=<VACANCY_ID>" \
  "http://localhost:8899/applicant-view.php"

/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT id, employment_status, is_current, date_hired, vacancy_id FROM care_jf_employment_records WHERE id = <RECORD_ID>;"
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "SELECT id, vacant_count, status FROM care_jf_job_vacancies WHERE id = <VACANCY_ID>;"
```
Expected: the row now shows `employment_status = 'Hired'`,
`is_current = 1`, `date_hired` set to today, `vacancy_id` set; the
vacancy's `vacant_count` decremented by exactly 1.

Also verify the cross-agency privacy filter and the retired-flow
regression: fetch `applicant-view.php?id=<APPLICANT_ID>` as a
*different* Partner Agency session than the one that tagged/hired
(create a second test session if only one Partner Agency test account
is available, or verify structurally via the query's `WHERE` clause
and note in your report if a live second-agency session genuinely
isn't available) and confirm the response does NOT contain that
agency's row; confirm the response also does NOT contain the string
`mark_hired` anywhere (the retired action must be completely gone).

- [ ] **Step 7: Commit**

```bash
git add public/applicant-view.php
git commit -m "feat: replace one-click Mark as Hired with Tag for Review / Confirm Hired workflow"
```

---

### Task 4: End-to-end verification

**Files:** none modified — verification-only task confirming Tasks 1-3
compose correctly.

**Interfaces:** none.

- [ ] **Step 1: Full `php -l` sweep**

```bash
cd /c/xampp/htdocs/applicant-system
find public includes -name "*.php" -print0 | xargs -0 -n1 /c/xampp/php/php.exe -l 2>&1 | grep -v "No syntax errors detected"
```
Expected: no output.

- [ ] **Step 2: Regression smoke test across every admin-accessible page**

```bash
cd /c/xampp/htdocs/applicant-system
/c/xampp/php/php.exe -S localhost:8899 -t public > /tmp/phase3_final_server.log 2>&1 &
sleep 1
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" http://localhost:8899/login.php | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
curl -s -b "$COOKIE" -c "$COOKIE" --data-urlencode "form=login" --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=admin" --data-urlencode "password=Admin@123" http://localhost:8899/login.php -o /dev/null
for PAGE in dashboard.php applicants.php reports.php partner-agency.php users.php employment-list.php audit-logs.php settings.php vacancies.php register-applicant.php; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE" -c "$COOKIE" "http://localhost:8899/$PAGE")
  echo "admin: $PAGE -> $STATUS"
done
grep -i 'warning\|fatal\|error' /tmp/phase3_final_server.log || echo "no server-log warnings/errors"
kill %1
```
Expected: `200` for every page including every page carried over from
Phases 1 and 2 (no regression there), no server-log warnings.

- [ ] **Step 3: Confirm the "hide once hired" applicants-list rule**

Using the applicant hired during Task 3's verification, confirm via
`api/applicants.php` (as a Partner Agency session other than the
hiring one, if a second test account is available — otherwise verify
the query logic directly and note the limitation) that the hired
applicant no longer appears in that other agency's results, while
confirming as Administrator that the applicant still appears (staff
visibility unaffected).

- [ ] **Step 4: Confirm the dashboard chart fix didn't regress the count**

```bash
/c/xampp/mysql/bin/mysql.exe -u root care_job_fair_db -e "
  SELECT DATE_FORMAT(date_hired, '%Y-%m') AS ym, COUNT(*) AS total
  FROM care_jf_employment_records WHERE status = 'Active' AND is_current = 1
  GROUP BY ym ORDER BY ym DESC LIMIT 6;"
```
Expected: only real `Hired`/confirmed rows counted, no NULL-keyed row
in the output (confirming the `For Review` row from Task 3's
verification, if not yet promoted to Hired, is correctly excluded).

- [ ] **Step 5: No commit needed** (verification-only task).
