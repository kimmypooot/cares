# Job Vacancies & Employer ID — Design (Phase 1 of 3)

## 1. Goal

This is Phase 1 of a 3-phase effort (QR Code is Phase 2, the For-Review→Hired
application-tracking redesign is Phase 3). Phase 1 builds the two
prerequisite subsystems Phase 3 needs: a minimal Job Vacancies module
(so a hire can decrement a real vacancy count), and atomic Employer ID
generation for activated Partner Agencies.

## 2. Decisions confirmed with the user

- Build a minimal Job Vacancies module now, rather than treating
  vacancy-count requirements as out of scope. "Minimal" means: the
  table, agency-scoped CRUD, and the fields Phase 3 needs (`vacant_count`
  to decrement) — no xlsx export, no Partner Agency Summary report, no
  applicant-to-vacancy matching (those stay future work, as originally
  scoped).
- The existing one-click "Mark as Hired" button (built in the prior
  session) will be **retired** in Phase 3, replaced by the two-step
  For Review → Hired flow. Not built in this phase, but this phase's
  vacancy table is shaped for that future consumer.
- QR generation/scanning will vendor two small, dependency-free JS
  libraries (`qrcode-generator` by kazuhikoarase, `jsQR` by cozmo) rather
  than hand-writing QR encode/decode — this is Phase 2's concern, noted
  here only because it doesn't touch this phase's files.

## 3. Database changes

New migration `database/migrations/add_job_vacancies_and_employer_id.sql`
(new tables/columns only — the established convention in this repo of
not editing already-shipped migrations continues to apply):

**`care_jf_partner_agencies`**
- `employer_id VARCHAR(20) NULL UNIQUE` — assigned once, at activation,
  never regenerated (re-enabling a disabled account, editing the
  profile, resetting the password, etc. never touches it).

**New table `care_jf_id_sequences`** — a small, reusable atomic-counter
table (`sequence_name`, `year_key`, `last_value`), used via MySQL's
well-known `INSERT ... ON DUPLICATE KEY UPDATE last_value =
LAST_INSERT_ID(last_value + 1)` pattern. This is the standard
race-condition-safe MySQL counter idiom — the `UPDATE` clause acquires
its row lock atomically as part of the single statement, so two
simultaneous activations can never read-then-write the same next value
(the exact failure mode `COUNT(*) + 1` has, and the exact thing section
17 of the request explicitly rules out).

**New table `care_jf_job_vacancies`**
```sql
CREATE TABLE care_jf_job_vacancies (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agency_id            INT UNSIGNED NOT NULL,
  title                VARCHAR(150) NOT NULL DEFAULT 'Job Available',
  position             VARCHAR(150) NOT NULL,
  job_level            ENUM('Level 1','Level 2','Level 3','Job Order - Level 1','Job Order - Level 2','COS - Level 1','COS - Level 2') NOT NULL,
  salary_grade         VARCHAR(100) NULL,
  occupational_option  VARCHAR(150) NULL,
  vacant_count         INT UNSIGNED NOT NULL DEFAULT 1,
  status               ENUM('Active','Disabled','Filled','Closed') NOT NULL DEFAULT 'Active',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vacancy_agency FOREIGN KEY (agency_id) REFERENCES care_jf_partner_agencies(id) ON DELETE CASCADE,
  INDEX idx_vacancy_agency (agency_id),
  INDEX idx_vacancy_status (status)
) ENGINE=InnoDB;
```
Field names/options match the original Partner-Agency-era spec's Job
Vacancy field list exactly (Title/Position/Job Level/Salary Grade/
Occupational Option/No. of Vacant Positions/Status), so Phase 3 and any
future vacancy-facing report can rely on this shape without another
migration.

**Backfill**: the one existing real Active Partner Agency account
(`cscro8.esd`, from earlier manual testing) gets an Employer ID assigned
retroactively, and the sequence counter is seeded so newly-activated
agencies continue the sequence correctly rather than colliding with it.
Written generically (works for however many pre-existing Active
accounts exist, not hardcoded to one row), ordered by `created_at` so
the assignment order matches registration order.

## 4. Employer ID generation

New helper in `includes/functions.php`:
```php
function generate_employer_id(PDO $pdo): string
{
    $year = date('Y');
    $pdo->prepare(
        "INSERT INTO care_jf_id_sequences (sequence_name, year_key, last_value) VALUES ('employer_id', :y, 1)
         ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)"
    )->execute([':y' => $year]);
    $next = (int)$pdo->lastInsertId();
    return 'EMP-' . $year . '-' . str_pad((string)$next, 7, '0', STR_PAD_LEFT);
}
```
Called from `public/users.php`'s existing `activate_agency` action
(the one place a Partner Agency account transitions Pending→Active),
guarded by `WHERE employer_id IS NULL` so it's a no-op if somehow called
twice — Employer ID is assigned exactly once, ever, per agency.
`reenable_agency` (Disabled→Active) does **not** call this — the
account already has its Employer ID from its original activation.

## 5. New page: `public/vacancies.php`

Mirrors the existing `partner-agency.php` list/form pattern (same file
structure: a list view with search/filter and inline Add/Edit/Enable/
Disable/Delete actions, reusing `csrf_field()`/`csrf_require()`,
`flash_set()`, `audit_log()` exactly as that file already does).

- **Administrator/Employee**: see and manage every agency's vacancies
  (list shows an Agency column).
- **Partner Agency**: see and manage only their own
  (`current_agency_id($pdo)` — never a posted `agency_id` — same
  IDOR-safe pattern used everywhere else in this codebase). No Agency
  column needed (always their own).
- Fields on the add/edit form: Position (required), Job Level (required
  select, the 7 options above), Salary/Pay Grade (optional), Occupational
  Option (optional), No. of Vacant Positions (required, positive
  integer), Status (Active/Disabled/Filled/Closed).
- Delete blocked server-side if any dependent record references the
  vacancy (none exist yet in Phase 1 — Phase 3 adds
  `employment_records.vacancy_id`; this page's delete guard is written
  now against that eventual FK so Phase 3 doesn't need to touch this
  file again).
- Audit actions: `VACANCY_CREATE`, `VACANCY_UPDATE`,
  `VACANCY_ENABLE`/`VACANCY_DISABLE`, `VACANCY_DELETE`.

## 6. Sidebar & profile display

- `includes/sidebar.php`: add a "Job Vacancies" nav item — for
  Administrator/Employee (in their existing nav list) and for Partner
  Agency (in their existing reduced nav list).
- `public/my-agency.php`: show the read-only Employer ID at the top of
  the profile (only rendered when non-null — an agency activated before
  this phase and not yet covered by the backfill, if any, simply won't
  show one; in practice the migration's backfill covers every currently
  Active account).
- `public/users.php`'s Partner Agency Accounts tab: add an Employer ID
  column (shown once assigned, "—" for still-Pending accounts).

## 7. Security

- Every Job Vacancies query for a Partner Agency session is scoped via
  `current_agency_id($pdo)`, never a request parameter — identical
  discipline to every other Partner-Agency-facing page in this
  codebase.
- `vacancies.php`'s POST handlers re-check role server-side (top-of-file
  gate is `require_login()`; each action re-checks
  `can_manage_employment() || (is_partner_agency() && owns the row)`
  before mutating).
- Employer ID generation only ever runs inside the existing
  `activate_agency` action, which is already Administrator-only and
  already re-authenticated behind `users.php`'s step-up reauth gate.

## 8. Out of scope for Phase 1

xlsx export for vacancies, the Partner Agency Summary report with Level
1/2/3 percentages, applicant-to-vacancy matching, and anything from
Phase 2 (QR) or Phase 3 (application tracking, hiring workflow
redesign) — those are separate phases with their own spec/plan.

## 9. Testing

No automated test suite in this repo (CLAUDE.md convention) — same
`php -l` + MySQL CLI + curl verification approach as every prior phase,
including: the atomic-counter pattern under a simulated race (two rapid
sequential activations get consecutive, non-colliding Employer IDs);
Partner Agency vacancy CRUD scoped correctly (cross-agency IDOR check,
same style as the `my-agency.php` test from the earlier phase); the
backfill assigning the existing Active account's Employer ID correctly
and seeding the sequence so the next new activation continues from
where the backfill left off.
