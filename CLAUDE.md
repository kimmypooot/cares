# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

CARE (Candidate Application & Registration for Employment) — a PHP 8 +
MySQL/MariaDB + Tailwind CSS administrative system for public job applicant
registration, applicant/employment tracking, partner agency management, and
role-based user administration. No framework, no autoloader, no Composer —
plain PHP files with shared `includes/`.

## Commands

```bash
npm install
npm run build   # rebuild public/assets/css/app.build.css from resources/css/input.css (tailwindcss --minify)
npm run watch    # same, watching for changes
```

There is no test suite, linter, or type-checker configured — verify PHP
changes by exercising the page in a browser against a real MySQL database.
CSS/vendor rebuilding is optional: `public/assets/` already contains built
output, so most changes to PHP/JS don't require running anything.

Document root is `/public` (see README "3. Point your web server document
root at `/public`"). DB connection settings are in `config/database.php`
(`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` constants — no `.env`).

## Architecture

**Request flow.** Every protected page under `public/` starts with the same
boilerplate:
```php
require_once __DIR__ . '/../includes/auth.php';
require_login();                    // or require_role(['Administrator', ...])
$pageTitle = '...';
require_once __DIR__ . '/../includes/header.php';   // opens <html>, <body>, flash toast
require_once __DIR__ . '/../includes/sidebar.php';  // nav + opens <main>
// ... page content ...
require_once __DIR__ . '/../includes/footer.php';   // closes tags, includes app.js
```
`includes/auth.php` pulls in `functions.php` and `csrf.php`, so including
just `auth.php` is enough to get all shared helpers.

**Authorization is enforced server-side twice**, not just once: at the top
of the page (`require_role([...])`) *and* again inside every POST/state-change
handler on that same page, because a page can render read-only UI for one
role while still needing to reject a forged POST from that role. Role checks
live in `includes/auth.php` as small predicates (`can_edit()`,
`can_delete()`, `can_manage_employment()`, `can_manage_agency()`,
`can_manage_users()`, `can_view_audit_logs()`) — always check via these, not
by comparing `current_user()['role']` inline, so the rule stays in one place.
Roles: `Administrator` / `Employee` / `Viewer` / `Partner Agency` — see the
capability table in README.md for what the first three can do.

**`Partner Agency` is a fourth role**, not in README's table: a portal
account for an external agency, logged into the same app but scoped to its
own data everywhere. `includes/sidebar.php` gives it a different nav (no
Employment module; adds Clients, Job Vacancies, Services, and a
Settings → "My Partner Agency" / "Agency Users" self-service section via
`public/agency-users.php`). Never trust an `agency_id` from request input
for this role — always derive it server-side with `current_agency_id($pdo)`,
and call `require_own_agency_record($pdo, $recordAgencyId)` before any
read/write that targets a specific record by id, to close the IDOR gap of a
Partner Agency user editing another agency's row via a tampered id.
`public/vacancies.php` (Job Vacancies) is shared: Administrator/Employee see
and manage every agency's vacancies via a dropdown, while a Partner Agency
sees and can only touch its own.

**Every state-changing POST handler must call `csrf_require()`** (from
`includes/csrf.php`) before touching the database, and every form must emit
`csrf_field()`. AJAX callers send the token via the `X-CSRF-Token` header
instead of a POST field (`csrf_require()` checks both).

**Sensitive scopes get step-up re-auth.** `has_valid_reauth($scope)` /
`grant_reauth($scope)` / `clear_reauth($scope)` in `auth.php` gate a
15-minute password-reconfirmation window (`REAUTH_WINDOW`) for actions like
managing users, independent of the normal 30-minute session idle timeout
(`SESSION_IDLE_TIMEOUT`).

**Audit trail.** Every login, logout, create, update, delete,
enable/disable, role change, and password reset must call
`audit_log($pdo, $userId, $action, $table, $recordId, $description)`
(`includes/functions.php`) — this is the only record of who did what and is
relied on by `public/audit-logs.php`.

**Soft delete / history preservation.** Applicants use `is_deleted` (never
hard-deleted). Employment records and partner agencies use a `status`
Active/Disabled enum instead of a boolean, kept distinct from
`is_current`/business status — a Disabled record still counts as history.
Hard-deleting a partner agency must be blocked server-side if it still has
employment history attached. A row's employment status label (`Job Order` /
`Temporary` / `COS` / `Permanent` / `Casual` / `Other` / `Hired` / `GIP` /
"For Further Review") is never stored as an applicant field — it's *derived* per-request
by `current_employment_status()` / `is_applicant_hired()` in
`includes/functions.php`, which look for a `care_jf_employment_records` row with
`is_current = 1 AND status = 'Active'`. A Disabled current-employment row
does **not** count as "hired" — don't shortcut this by reading
`is_current` alone.

**`is_current` is maintained by application code, not a trigger** (see the
comment block above the `employment_records` table in `database/database.sql`
— MySQL forbids a trigger from updating the table that fired it during a
multi-row statement). Before setting a new row's `is_current = 1`, first run
an `UPDATE` clearing `is_current` on the applicant's other rows, in the same
request. Follow the pattern in `public/employment-form.php` when touching
this logic.

**Not every `care_jf_employment_records` row is a confirmed employment.**
A row can also be `For Review`, `Withdrawn`, or `Superseded` — a Partner
Agency's in-progress or closed interest in an applicant, never a real hire.
These tracking rows are always `is_current = 0` with `date_hired = NULL`;
`vacancy_id` is only ever meaningful on a `Hired` row (it names the Job
Vacancy that hire filled). Any new query over this table must decide
whether it wants confirmed employment only — filter `is_current = 1 AND
status = 'Active'` (see `public/dashboard.php`'s hires-per-month query),
or explicitly exclude the three tracking states (see
`public/employment-list.php`'s listing query) — rather than assuming every
row is a real hire.

**Applicant codes** are sequential per calendar month:
`APP-YYYYMM-NNNNNN`, generated by `generate_applicant_code()` in
`functions.php`, resetting to `000001` each new month — don't assume
global sequential uniqueness across months. `generate_employer_id()`
(also in `functions.php`) follows the same atomic-counter pattern for
Partner Agency employer IDs — copy its locking approach rather than a
plain `SELECT MAX(...) + 1`, which races under concurrent inserts.

**QR-based applicant tagging** is a separate workflow from the employment
form. `includes/qr-scanner-modal.php` (jsQR camera scan or manual code
entry) resolves an applicant code and calls one of `tag_applicant_for_agency()`,
`tag_applicant_for_service()`, or `set_service_availment_selection()`
(`includes/functions.php`) via `public/api/`. These create `For Review`-style
tracking rows, not confirmed hires — see the employment-status note above.
`tag_applicant_for_agency()` writes to `care_jf_employment_records` (hire
pipeline); `tag_applicant_for_service()` / `set_service_availment_selection()`
instead write/update a `care_jf_service_availments` row — a separate table for
non-employment agency services (referral, counseling, etc.) where a tagged
applicant is a "Client" (`public/clients.php`), not a hire candidate. Don't
conflate the two tables when querying "what has this agency done with this
applicant."

**Login throttling** is database-backed (`care_jf_login_throttle` table,
not a session/cookie), enforced in `includes/auth.php` via `throttle_locked()`
/ `throttle_record_failure()` / `throttle_reset()`. Two independent
identifiers are throttled per attempt: source IP (20 attempts / 5 min) and
username (5 attempts / 60 sec) — both are checked before a login is allowed
to proceed.

**Two schema files, not interchangeable:** `database/database.sql` is
fresh-install only; `database/migrations/update_application_management.sql`
is the non-destructive v1→v2 upgrade path. Never run `database.sql` against
an existing database. Any future schema change needs a new migration file
here, not an edit to `database.sql`'s already-shipped `CREATE TABLE`
statements.

**Live search/filter/pagination** for the applicants list is AJAX via
`public/api/applicants.php`, driven by Alpine.js (`applicantTable()` in
`public/assets/js/app.js`) — it returns JSON, requires `is_logged_in()`
(401 if not), whitelists sortable columns, and uses `SQL_CALC_FOUND_ROWS`
for total counts. Filtering by employment status requires a `LEFT JOIN`
against the current employment record then a `HAVING` clause (not `WHERE`),
since the status is a derived/joined column.

**Excel export endpoints** (`public/api/*-export.php`) mirror a list page's
own filters/scoping exactly (reusing the same filter-building helpers, e.g.
`build_applicant_filters()`) but drop `LIMIT`/`OFFSET` to stream the full
matching result set, not just the current page, via `stream_xlsx()`
(`includes/xlsx_writer.php` — a dependency-free .xlsx writer, no library).
Each corresponds to one list page: `applicants-export.php` ↔
`applicants.php`, `agency-clients-export.php` ↔ `clients.php` (Partner
Agency only), `employment-export.php` ↔ `employment-list.php`,
`services-export.php` ↔ `services.php`, `vacancies-export.php` ↔
`vacancies.php`. Follow this same pattern for any new exportable list rather
than inventing another.

**Database backup/reset** (`includes/db_admin.php`, Administrator-only, from
Account Settings) streams a full `.sql` dump straight to the browser via
`stream_sql_backup()` (never written to disk server-side) using the same
"no third-party library" philosophy as `xlsx_writer.php`. Its
`reset_application_records()` `TRUNCATE`s per-cycle transactional data
(applicants, employment records, job vacancies, service availments, audit
logs, id sequences, login throttle) while deliberately preserving
`care_jf_users`, `care_jf_partner_agencies`, and `care_jf_agency_services` —
a "start a new cycle" reset, not a factory reset; don't add a table to that
list without confirming the same scope decision applies.

**Dark mode** is class-based (`darkMode: 'class'` in `tailwind.config.js`).
`includes/theme-init.php` is included as early and blocking (non-deferred)
as possible in `<head>`, before `header.php`'s own markup, to set the
`dark` class on `<html>` before first paint (localStorage `care-theme` >
`prefers-color-scheme`) and avoid a flash of the wrong theme. It also
defines `applyChartDefaults()`, which a page's own inline chart-setup script
must call before instantiating any Chart.js chart (Chart.js only reads
`Chart.defaults` at creation time, not reactively).

**Frontend stack:** Tailwind (compiled, not CDN) + Alpine.js + Chart.js +
Font Awesome + qrcode-generator + jsQR, all vendored locally under
`public/assets/` (no external CDN calls at runtime — see header.php's v2
comment). Tailwind content globs are
`./public/**/*.php` and `./includes/**/*.php` (`tailwind.config.js`) — new
utility classes must appear in one of those to survive the production build.
Brand color scale is `brand-{50..900}` (indigo/blue); default sans font is
`Inter`, self-hosted via `@fontsource/inter` files under
`public/assets/vendor/fonts/`.

**Output escaping:** always wrap dynamic values with `e()` (a
`htmlspecialchars` wrapper in `functions.php`) when echoing into HTML.
`clean()` trims + strips tags for input normalization but is explicitly
*not* a substitute for parameterized queries — all SQL still goes through
PDO prepared statements with `ATTR_EMULATE_PREPARES => false`.

**`docs/superpowers/`** holds the approved plan + design doc for each past
feature (job vacancies, partner agency registration, QR tagging, service
availment, reports overhaul, etc.) — check here for the *why* behind a
module before assuming its current shape is accidental. `scripts/` holds
one-off CLI data-load scripts (run via `php scripts/<name>.php`, not through
the browser); they're written to be safe to re-run (skip already-imported
rows) rather than idempotent by transaction.
