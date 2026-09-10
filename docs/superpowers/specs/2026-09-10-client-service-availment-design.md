# Client / Agency-Service Availment — Design Spec

Date: 2026-09-10

## 1. Goal

Today, `care_jf_applicants` already lets a registrant check two independent
boxes: **Job Seeker** (`service_job_seeker`) and **Avail Agency Service**
(`service_agency_services`). The Job Seeker path is fully built (employment
tracking via `care_jf_employment_records`, QR auto-tagging, hiring
workflow). The Avail-Agency-Service path is not: registration validation
requires educational attainment / eligibility from *every* registrant
regardless of which box is checked, and there is no record of which
Partner Agency's service a "client" (an Avail-Agency-Service registrant)
actually used — scanning or manually tagging *any* applicant today
unconditionally creates a job-seeker `For Review` employment record, even
for someone who never checked Job Seeker.

This phase:
- Relaxes registration validation so educational attainment / eligibility
  are only required when Job Seeker is checked.
- Adds a new record type, `care_jf_service_availments`, that logs which
  Partner Agency a client's service was availed with — populated the same
  way employment tracking already is: a Partner Agency scanning or
  manually tagging the person's QR code / applicant code.
- Gates today's job-seeker tagging (QR scan + manual "Tag for Review") to
  only fire when `service_job_seeker = 1`, and adds the equivalent
  service-availment tagging gated to `service_agency_services = 1`. A
  person with both flags set gets both from a single scan.
- Adds a reference-only Partner Agency Services catalog
  (`care_jf_agency_services`) so each agency's own service list can be
  recorded and displayed — not selected per-availment (confirmed with the
  user: availment stays agency-level, not tied to a specific catalog
  entry, at least for this phase).
- Adds a "Clients" list page (Administrator/Employee/Viewer) that is the
  same `care_jf_applicants` data filtered to `service_agency_services = 1`
  — no new "client" table, no new code prefix. A client is an applicant.

## 2. Decisions confirmed with the user

- **Applicant and Client are the same table.** `care_jf_applicants` is
  unchanged. "Separate record" means a separate *tracking* record
  (`care_jf_service_availments`), not a separate person/registrant table —
  exactly parallel to how `care_jf_employment_records` tracks a job
  seeker's agency interactions without duplicating the applicant's own
  data.
- **Educational Attainment / Eligibility stay visible, become optional.**
  When Job Seeker is unchecked, these fields remain on the registration
  form (not hidden) but are no longer required. They become required again
  the instant Job Seeker is checked, including when both boxes are
  checked together.
- **Tagging is automatic, driven by the applicant's own flags — never an
  extra choice at scan time.** A Partner Agency scanning/tagging an
  applicant with `service_job_seeker=1` gets job-seeker tagging (as
  today); one with `service_agency_services=1` gets service-availment
  tagging; both flags set → both happen from the same action.
- **The Partner Agency Services catalog is reference-only for this
  phase.** It documents what each agency offers (shown on that agency's
  own record) but is never selected per client at availment time. A
  later phase could add a specific-service picker without a schema
  change to `care_jf_service_availments` (it would just gain a nullable
  `agency_service_id` column).
- **No repeat-visit history.** `UNIQUE(applicant_id, agency_id)` on
  `care_jf_service_availments` — re-scanning the same client at the same
  agency is an idempotent no-op (`duplicate`), not a new dated row.
  Confirmed acceptable; revisit if the user later wants a full visit log.
- **Clients get a list page, not a new table.** `public/clients.php`
  filters the same applicants data to `service_agency_services = 1`.
  Visible to Administrator/Employee/Viewer only — Partner Agency accounts
  continue to have no list view of any kind (matches
  `employment-list.php`'s existing exclusion of that role); they see a
  tag they created only via that client's own profile page, exactly like
  today's Job Seeker pattern.
- **Reports-module changes are out of scope.** The Reports Overhaul
  (`docs/superpowers/specs/2026-09-10-reports-overhaul-design.md`) is a
  separate, already-in-progress effort. This phase does not touch
  `reports.php`; a later phase can add a Services-Availed-by-Agency report
  once this data exists.
- **Populating the real services catalog is a follow-up bounded task.**
  This spec defines the table; loading the user's file into it (once
  supplied) is a small one-off import script, the same shape as
  `scripts/import-partner-agencies-2026-09.php`.

## 3. Data model

### 3.1 `care_jf_service_availments` (new table)

```sql
CREATE TABLE care_jf_service_availments (
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
```

No `tagged_by_user_id` column — `care_jf_employment_records` doesn't store
one either; that attribution lives exclusively in `care_jf_audit_logs` via
`audit_log()`, and this table follows the same convention. `status`
(Active/Disabled) exists so a mistaken tag can be soft-invalidated by an
Administrator without a hard delete, matching this codebase's
soft-delete/history-preservation convention (never hard-delete a
tracking-style row).

### 3.2 `care_jf_agency_services` (new table)

```sql
CREATE TABLE care_jf_agency_services (
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
```

Both tables ship in one new migration file,
`database/migrations/add_service_availments_and_agency_services.sql`,
following this repo's standing convention (idempotent
`CREATE TABLE IF NOT EXISTS`, never edits to `database.sql`'s already-
shipped statements).

### 3.3 No changes to `care_jf_applicants` or `care_jf_employment_records`

Both existing tables and their columns are reused exactly as-is.

## 4. Shared tagging function

New function in `includes/functions.php`, structurally parallel to
`tag_applicant_for_agency()`:

```php
function tag_applicant_for_service(
    PDO $pdo, int $applicantId, string $applicantCode,
    int $agencyId, int $actingUserId, string $source, string $sourceDetail = ''
): string
```

1. Agency must exist and be `status = 'Active'` in
   `care_jf_partner_agencies` → else return `agency_invalid`.
2. Duplicate check: a row already exists for `(applicant_id, agency_id)`
   → return `duplicate` (any status — Disabled counts too, since the
   `UNIQUE` constraint would reject a second insert regardless; an
   Administrator wanting to re-enable a Disabled row does so via edit, not
   by re-tagging).
3. Insert `(applicant_id, agency_id, source, status='Active')`.
4. `audit_log()` with `SERVICE_AVAILED_TAGGED` (source = manual) or
   `SERVICE_AVAILED_AUTO_TAGGED_QR` (source = qr_scan), description noting
   the applicant code and agency name, matching
   `tag_applicant_for_agency()`'s description style. Return `created`.

No `already_hired`-style gate — employment status has no bearing on
whether someone can avail a Partner Agency's service.

## 5. Call-site changes

### 5.1 `public/api/qr-tag.php`

Currently calls `tag_applicant_for_agency()` unconditionally for any
scanned applicant. Changes to: look up the applicant (unchanged), then:

```php
$results = [];
if ($applicant['service_job_seeker']) {
    $results['employment'] = tag_applicant_for_agency($pdo, $applicantId, $code, $agencyId, $userId, 'qr_scan', $via);
}
if ($applicant['service_agency_services']) {
    $results['service'] = tag_applicant_for_service($pdo, $applicantId, $code, $agencyId, $userId, 'qr_scan', $via);
}
```

Response body gains both outcomes: `{ok, applicant_id, employment_status,
service_status}` (either key absent if that flag wasn't set on the
applicant). `ok` is true if at least one tagging ran and none of the ones
that did run failed for a reason other than `duplicate`/`already_hired`
(both of those still count as a successful, idempotent outcome — matching
today's `duplicate` handling). `app.js`'s `qrScanner()` builds its
flash/toast text from whichever key(s) are present instead of a single
fixed string — e.g. "Applicant tagged for review and service logged with
your agency." when both ran, or just one clause when only one applies.

### 5.2 `public/applicant-view.php`

- `$canTagAsPartnerAgency` / `$canTagAsStaff` (gating the "Tag for
  Review" button and its modal) additionally require
  `!empty($applicant['service_job_seeker'])`.
- New equivalent pair `$canTagForServiceAsPartnerAgency` /
  `$canTagForServiceAsStaff`, gated on
  `!empty($applicant['service_agency_services'])` and (for the
  Partner-Agency case) no existing row for `(applicant_id,
  current_agency_id())` — mirrors `$myOpenReview`'s shape but querying
  `care_jf_service_availments`.
- New `tag_for_service` POST action, mirroring `tag_for_review`
  structurally (role check, agency resolution — server-derived for
  Partner Agency, dropdown for staff — call
  `tag_applicant_for_service()`, map result to flash message, redirect).
- New "Services Availed History" card (gated on
  `service_agency_services`), placed alongside the existing "Employment
  History" card, listing this applicant's `care_jf_service_availments`
  rows (agency name, date tagged, source), with its own "Tag for Service"
  button/modal reusing the same modal markup pattern as "Tag for Review."
- "Employment History" card itself (heading, list, "Tag for Review"
  button, "Add Record" link) becomes conditional on
  `service_job_seeker` — an Avail-Agency-Service-only applicant no longer
  shows an (irrelevant, currently-empty) Employment History card at all.

### 5.3 Registration / create / edit forms

`public/register-applicant.php`, `public/applicant-create.php`,
`public/applicant-edit.php` (wherever each currently validates
`educational_level` / `completion_status` / graduation fields /
`eligibility_status` / `eligibility_type` unconditionally): each such
check becomes conditional on `$old['service_job_seeker']` being true.
When Job Seeker is unchecked, these fields are simply not validated (and,
per the confirmed decision, still rendered — Alpine `x-show`/CSS is
already what controls visibility today for the conditional sub-fields
within Educational Attainment, e.g. Graduated vs Not Graduated; Job
Seeker just adds one more outer condition without hiding the section).
No field is deleted from `$old` or the INSERT/UPDATE column list — an
Avail-Agency-Service-only registrant can still optionally fill these in
(e.g. someone who unchecks Job Seeker but still wants their education on
file); they're just not *required*.

## 6. New Clients list page

`public/clients.php`: same shell and same `applicantTable()` Alpine
component as `public/applicants.php`, reusing `public/api/applicants.php`
verbatim except for one new optional query param, `service`
(`job_seeker` | `agency_services` | absent = no filter), applied as an
additional `WHERE` clause exactly like the existing whitelisted filters.
`clients.php` initializes the Alpine component with
`filters.service = 'agency_services'` fixed (not exposed as a UI
control — the page's entire purpose is that filter) and drops the
Employment Status filter (meaningless for a pure Avail-Agency-Service
registrant, though such a person could still also be a job seeker if both
boxes are checked — clicking through to their profile shows everything
regardless of which list they were found from).

`require_login()` only, then an explicit role check identical to
`employment-list.php`'s: Partner Agency accounts get a 403 (they have no
list view of any kind, per the confirmed decision).

`includes/sidebar.php` gains a "Clients" nav entry (icon:
`fa-handshake`), positioned after "Applicants", in the same nav array(s)
that currently list "Applicants" for Administrator/Employee/Viewer —
excluded from the Partner Agency nav array.

## 7. Partner Agency Services catalog management

- **Staff side (`public/partner-agency-form.php`, edit mode only):** a
  new "Services Offered" section below the existing fields — an inline
  list of this agency's `care_jf_agency_services` rows with add /
  edit / enable-disable actions (status toggle, no hard delete, same
  soft-invalidate convention as everywhere else in this codebase), POST
  actions role-checked `require_role(['Administrator', 'Employee'])`
  (same as the page's top-of-file gate) both at top and per-handler.
- **Partner Agency self-service (`public/my-agency.php`):** the same
  "Services Offered" section, scoped to `current_agency_id($pdo)` exactly
  like the rest of that page — never trusts a posted `agency_id`.
- Both call sites share the same validation shape (`service_name`
  required, ≤200 chars; `description` optional, ≤500 chars) — following
  this codebase's existing precedent of each form file duplicating its
  own POST handling rather than factoring out a shared handler (matches
  how `partner-agency-form.php` and `my-agency.php` already duplicate the
  base profile-field validation today).
- Read-only display: `public/partner-agency.php`'s agency detail/list
  view shows each agency's Active services (if any) inline.

## 8. Security

- Every new POST handler calls `csrf_require()` before touching the
  database, per this codebase's standing rule.
- Partner Agency identity for both the services-catalog CRUD and the
  `tag_for_service` manual action is always `current_agency_id($pdo)` —
  never trusted from request input, identical to every existing
  Partner-Agency-scoped write in this codebase.
- `clients.php` and the `service` filter of `api/applicants.php` apply
  the same role/authorization checks the underlying data already has
  (`require_login()` for the API, matching today; explicit role gate on
  `clients.php` itself).
- `care_jf_service_availments` rows are never hard-deleted by any
  user-facing action — only `status` toggles, matching
  `care_jf_employment_records`' and `care_jf_job_vacancies`' existing
  soft-invalidate pattern.
- New audit actions (`SERVICE_AVAILED_TAGGED`,
  `SERVICE_AVAILED_AUTO_TAGGED_QR`, plus `CREATE`/`UPDATE` on
  `care_jf_agency_services`) follow the exact same `audit_log()` call
  shape already used throughout this codebase — no new logging
  mechanism.

## 9. Out of scope for this phase

Any change to `public/reports.php` (separate, already in-progress
effort); picking a specific catalog service at availment time (confirmed
agency-level only); multiple dated visits per `(applicant, agency)` pair
(confirmed idempotent no-op instead); populating real
`care_jf_agency_services` data (deferred bounded follow-up once the
user's file is supplied); any change to how Job Seeker tagging itself
behaves beyond adding the `service_job_seeker` gate (the existing
`tag_applicant_for_agency()` function, hiring workflow, and
`already_hired` semantics are untouched).

## 10. Testing

No automated test suite in this repo — manual verification:

- **Registration, Job Seeker only:** educational/eligibility required as
  today; unchanged behavior end to end.
- **Registration, Avail Agency Service only:** educational/eligibility
  fields render but submit succeeds with them blank; applicant saved with
  `service_job_seeker=0, service_agency_services=1`.
- **Registration, both checked:** both sets of fields required, matching
  today's full behavior.
- **Registration, neither checked:** existing "select at least one
  service" validation error, unchanged.
- **QR scan, Job-Seeker-only applicant:** creates exactly one
  `care_jf_employment_records` `For Review` row, no
  `care_jf_service_availments` row, same flash text as today.
- **QR scan, Agency-Service-only applicant:** creates exactly one
  `care_jf_service_availments` row, no employment record, new flash text.
- **QR scan, both-flags applicant:** creates one row in each table from a
  single scan; combined flash text.
- **Duplicate scan (either kind):** idempotent, no second row, correct
  `duplicate` flash.
- **Manual "Tag for Service":** staff agency-picker and Partner-Agency
  forced-own-agency both work, matching "Tag for Review"'s existing
  parity.
- **Employment History / Services Availed History cards:** each shows
  only when its respective flag is set; an Avail-Agency-Service-only
  applicant's profile shows no Employment History card and no "Tag for
  Review" button anywhere (button already covered by the QR-auto-tag
  design's existing test matrix for the Job-Seeker path).
- **`clients.php`:** lists only `service_agency_services=1` applicants,
  search/sort/pagination parity with `applicants.php`; Partner Agency
  role gets 403; Administrator/Employee/Viewer see it.
- **Agency Services CRUD:** staff can add/edit/disable for any agency via
  `partner-agency-form.php`; a Partner Agency user can do the same for
  only their own agency via `my-agency.php`, and gets 403/ownership
  failure attempting another agency's `agency_id` via a crafted POST.
- **Migration:** run against a copy of the live `care_job_fair_db`
  database, confirm both new tables created, confirm existing data
  (Applicants, Employment Records, Job Vacancies, Partner Agencies)
  completely untouched.
- Regression smoke test: existing Job-Seeker QR scan, "Tag for Review",
  hiring workflow, and `applicants.php` all behave exactly as before for
  applicants that predate this change.
