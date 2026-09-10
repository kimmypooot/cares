# Client Service-Availment Modal, Standalone Services/Clients Modules, Nav Restructure — Design Spec

Date: 2026-09-10

## 1. Goal

This is Phase 2 of the Client / Agency-Service Availment feature (Phase 1:
`docs/superpowers/specs/2026-09-10-client-service-availment-design.md`,
already implemented and shipped). The user supplied a detailed, formal
requirements document ("APPLICATION MANAGEMENT AND MONITORING SYSTEM"
update) that supersedes two Phase 1 decisions and adds new scope:

- **Reversed from Phase 1**: service availment now requires selecting a
  *specific* service (from the acting agency's own catalog, or "OTHERS" +
  free text) via a modal, rather than staying agency-level only.
- **Reversed from Phase 1**: Partner Agency accounts get their own
  "Clients" list (scoped to their own agency), not just a per-record tag
  visible on an individual client's profile.
- **New**: a standalone "Services" module (list + CRUD) for both roles,
  relocated out of the agency-profile pages it's currently embedded in.
- **New**: sidebar nav restructuring — both roles group their
  settings-style pages under a collapsible "Settings" entry, and gain
  "Services" as its own top-level nav item.
- **Reconfirmed unchanged**: everything else already built in Phase 1
  (the two-checkbox registration model, Applicant ID/QR reuse as Client
  identity, duplicate-safe agency association, CSRF/role/audit
  conventions, the Job Seeker/Employment workflow) stays exactly as-is.

## 2. Decisions confirmed with the user

- **Service-availment tagging is two steps, not one.** Step 1 (automatic,
  unchanged from Phase 1): scanning/looking up a Client with
  `service_agency_services=1` creates-or-reuses their
  `(applicant_id, agency_id)` row in `care_jf_service_availments` — no
  behavior change here. Step 2 (new, human-confirmed): a modal then sets
  *which* service was availed on that same row via an UPDATE. Cancelling
  the modal leaves the service unset — nothing is lost, since the
  agency-level tag from Step 1 already exists.
- **Re-scanning an already-tagged Client is no longer an error.** The
  duplicate check that previously blocked a second scan now reopens the
  modal against the existing row, letting staff update which service is
  being availed this time. `tag_applicant_for_service()`'s `'duplicate'`
  return value is renamed to `'reused'` — same non-INSERT behavior,
  different (non-error) disposition in every caller.
- **One shared function does the service-selection UPDATE**, called from
  two different surfaces: the async QR/manual-code scanner flow (new
  endpoint `api/service-availment-confirm.php`) and the same-page "Tag
  for Service" form on `applicant-view.php` (calls the function directly,
  no extra HTTP round-trip needed since it's already a plain form POST).
  This satisfies the requirement that both entry points use the same
  backend logic without over-engineering a second HTTP hop where none is
  needed.
- **No `created_by` column.** `care_jf_service_availments` follows the
  same precedent as `care_jf_employment_records` — attribution lives
  entirely in `care_jf_audit_logs`, not a column on the row.
- **No new workflow-status column yet.** Only one real state exists today
  ("Availing Services" — the future "Service Completed" state is
  explicitly deferred in the source requirements). The existing
  `status` column (`Active`/`Disabled`, this codebase's standard
  soft-invalidate pattern) already distinguishes "currently availing" from
  "withdrawn/invalidated" — `Active` displays as "AVAILING SERVICES",
  `Disabled` as "WITHDRAWN". Adding a dedicated workflow-status enum for a
  single current value would be building ahead of what's needed.
- **Services CRUD is relocated, not duplicated.** The existing embedded
  "Services Offered" sections in `partner-agency-form.php` (staff) and
  `my-agency.php` (self-service) are removed entirely and replaced by a
  new standalone `public/services.php`, reusing the exact
  `vacancies.php` convention for cross-agency staff management: staff
  pick an `agency_id` from a dropdown when adding a service; a Partner
  Agency's own `agency_id` is always `current_agency_id($pdo)`, never
  trusted from the request.
- **Partner Agency's Clients view needs its own query**, not a reuse of
  staff's `api/applicants.php` path — the columns required (Service
  Availed, Date Availed, per-agency Status) live on
  `care_jf_service_availments`, not on the applicant row. New endpoint
  `api/agency-clients.php` serves this role's view of `clients.php`;
  staff's existing path is untouched.
- **The existing "Settings" (account/password) page is folded into the
  new "Settings" dropdown** as one more item, rather than keeping two
  same-named nav concepts. Both roles get exactly one "Settings ▾" entry.
- **Audit action strings are unchanged** (`SERVICE_AVAILED_TAGGED`,
  `SERVICE_AVAILED_AUTO_TAGGED_QR`, both already shipped and visible in
  Audit Logs) — the source document's action-name examples aren't binding
  requirements.

## 3. Data model

### 3.1 `care_jf_service_availments` — additive columns

```sql
ALTER TABLE care_jf_service_availments
  ADD COLUMN service_id INT UNSIGNED NULL AFTER agency_id,
  ADD COLUMN custom_service_name VARCHAR(200) NULL AFTER service_id,
  ADD CONSTRAINT fk_availment_service FOREIGN KEY (service_id)
    REFERENCES care_jf_agency_services(id) ON DELETE SET NULL;
```

`service_id` is nullable (a row exists from Step 1 before Step 2 sets it,
and a Cancelled modal leaves it null indefinitely — a valid "tagged, service
not yet specified" state). `ON DELETE SET NULL` so a later-disabled/removed
catalog service doesn't destroy the historical availment record. No other
column changes to this table (`source`, `status`, timestamps, the existing
`UNIQUE(applicant_id, agency_id)` — all unchanged, and that unique
constraint is exactly what makes Step 1's create-or-reuse behavior work).

Migration file: `database/migrations/add_service_selection_to_service_availments.sql`,
following this repo's standard idempotent/additive convention.

### 3.2 No other schema changes

`care_jf_agency_services`, `care_jf_applicants`, `care_jf_employment_records`
are all unchanged.

## 4. Shared service-selection function

New function in `includes/functions.php`:

```php
function set_service_availment_selection(
    PDO $pdo, int $applicantId, int $agencyId,
    ?int $serviceId, ?string $customServiceName, int $actingUserId
): string
```

1. Exactly one of `$serviceId`/`$customServiceName` must be meaningfully
   set by the caller (the caller validates this before calling — see §5.2
   and §6.3 for the two call sites' own validation) — this function
   itself defends by re-checking: if `$serviceId` is given, it must
   belong to `$agencyId` and be `status='Active'` in
   `care_jf_agency_services` (else return `'service_invalid'`); if
   `$customServiceName` is given instead, it's used as-is (already
   uppercased/trimmed by the caller).
2. `UPDATE care_jf_service_availments SET service_id = :sid,
   custom_service_name = :csn WHERE applicant_id = :aid AND agency_id =
   :agid` — if no row matched (`rowCount() === 0`, meaning Step 1 never
   ran for this pair), return `'not_found'`.
3. `audit_log()` with action `SERVICE_AVAILED_SELECTION_SET`, description
   noting the applicant code, agency name, and the resolved service name
   (catalog name or the custom text). Return `'updated'`.

Both `api/service-availment-confirm.php` (§5) and `applicant-view.php`'s
`tag_for_service` branch (§6) call this function identically after their
own role/CSRF/ownership checks, so the actual selection-setting logic
exists exactly once.

## 5. Async flow: QR scan / manual code entry (scanner modal)

### 5.1 `api/qr-tag.php` response changes

After today's existing employment/service tagging (Step 1, unchanged —
`tag_applicant_for_agency()` / `tag_applicant_for_service()` calls stay
exactly as they are), the response gains two new keys whenever
`service_status` is present (i.e., a service tag was created or reused):

```php
'full_name' => full_name($applicant),      // needs last_name/first_name/middle_name added to the existing SELECT
'service_options' => [ ['id' => 1, 'service_name' => 'DOCUMENT AUTHENTICATION'], ... ]
   // SELECT id, service_name FROM care_jf_agency_services
   //  WHERE agency_id = :agid AND status = 'Active' ORDER BY service_name
```

`service_status`'s value is now `'created'` or `'reused'` (was
`'duplicate'`) — `'agency_invalid'` is unchanged and still short-circuits
before any of this.

### 5.2 `includes/qr-scanner-modal.php` + `app.js` changes

A new modal markup block (Service Availment modal) is added alongside the
existing "Confirm Association" modal, per the source document's layout:
`<h2>` agency name (from a PHP-rendered value at page load — the logged-in
Partner Agency's own name, never from the API response, never editable),
`<h1>` client full name (from the API response), a `SERVICE AVAILED`
`<select>` built from `service_options`, an `OTHERS` value that reveals a
required "please specify" text input, `Cancel`/`Confirm` buttons. Same
no-outside-click-close convention as every other modal in this app.

`qrScanner()`'s `tagAndNavigate()`: if the response includes
`service_options`, open this new modal (populated from the response)
instead of navigating immediately; store `pendingApplicantId` from the
response. If `service_options` is absent (Job-Seeker-only tag), behavior
is byte-for-byte unchanged — navigate immediately, exactly as today.

**Confirm** validates client-side that either a real service is selected
or `OTHERS` + non-blank text, then POSTs to `api/service-availment-confirm.php`
with `{ applicant_id, service_id }` or `{ applicant_id, service_id:
'others', custom_service_name }`, then navigates to the profile on
success. **Cancel** navigates to the profile immediately with no POST (the
Step 1 tag already exists; the service just stays unset, exactly like
today's "Tag for Review, decide details later" pattern elsewhere in this
app).

### 5.3 New endpoint `api/service-availment-confirm.php`

- `require_login(); require_role(['Partner Agency']);` → 403 JSON for any
  other role (staff never reach this — their scans are lookup-only,
  matching the existing `qr-tag.php` convention).
- POST only, `csrf_require()`.
- Input: `applicant_id` (int), and either `service_id` (int, or the
  literal string `'others'`) with `custom_service_name` (string, required
  when `service_id === 'others'`, `mb_strtoupper(trim(...), 'UTF-8')`,
  capped at 200 chars — reject with a clear error if longer).
- `$agencyId = current_agency_id($pdo)` — never from the request.
- Resolves `$serviceId` (null if `'others'`) and `$customServiceName`
  (null unless `'others'`), calls
  `set_service_availment_selection($pdo, $applicantId, $agencyId,
  $serviceId, $customServiceName, (int)current_user()['id'])`.
- Maps `'updated'` → `{ok:true}`; `'service_invalid'` → `{ok:false,
  status:'service_invalid'}`; `'not_found'` → `{ok:false,
  status:'not_found'}`.

## 6. Same-page flow: `applicant-view.php`'s "Tag for Service"

The existing button/modal (Phase 1) currently tags immediately with no
service selection. It gains the same dropdown/OTHERS UI as §5.2's modal
(agency name and client name are already known from the page — no lookup
needed), and its form POST gains `service_id`/`custom_service_name`
fields.

The `tag_for_service` POST branch (existing role/eligibility checks
unchanged) calls `tag_applicant_for_service()` first (Step 1, as today),
then — if the result is `'created'` or `'reused'` and a service selection
was submitted — calls `set_service_availment_selection()` (Step 2, §4)
with the same validation as §5.3 (service must belong to the acting
agency; custom text required and uppercased if `OTHERS`). Both steps run
in one request/transaction; flash message reflects the combined outcome
("Service availment tagged: DOCUMENT AUTHENTICATION.").

## 7. Standalone Services module

### 7.1 `public/services.php` (new)

- `require_login()`, then role branch:
  - **Partner Agency**: services scoped to `current_agency_id($pdo)`,
    full add/edit/toggle-status CRUD — this is the exact logic currently
    embedded in `my-agency.php`, moved here verbatim (same POST action
    names, same validation, same `audit_log()` calls).
  - **Administrator/Employee**: every agency's services in one table
    (columns: Agency, Service Name, Description, Status), with an
    `agency_id` `<select>` (from `active_agencies($pdo)`, same helper
    `vacancies.php` already uses) required when adding a new service —
    mirrors `vacancies.php`'s `$agencyId = is_partner_agency() ?
    $scopedAgencyId : (int)($_POST['agency_id'] ?? 0);` pattern exactly.
  - **Viewer**: read-only list, same cross-agency view as
    Administrator/Employee minus the add/edit/toggle actions.
- `partner-agency-form.php` and `my-agency.php` lose their entire
  "Services Offered" sections and `add_service`/`toggle_service`/
  `edit_service` POST branches — relocated here, not duplicated.
  `partner-agency.php`'s existing Active-services-count column stays (it's
  still a useful at-a-glance figure); it just no longer has an inline
  management UI on that page.

## 8. Partner Agency's Clients module

### 8.1 `public/clients.php` — role branch, not a 403

The existing `if (is_partner_agency()) { 403 }` block is replaced with a
role branch: staff keep today's exact page (unchanged query, unchanged
columns, `api/applicants.php`); a Partner Agency viewer gets a different
column set per the source document (Seq No., Application ID, Full Name,
Sex, Services Registered, **Service Availed**, Status, Date Availed,
Action) backed by a new endpoint.

### 8.2 New endpoint `api/agency-clients.php`

- `require_login(); require_role(['Partner Agency']);`
- `$agencyId = current_agency_id($pdo)` — never from the request.
- Query: `care_jf_service_availments` joined to `care_jf_applicants` (for
  name/sex/service flags) and `LEFT JOIN care_jf_agency_services` (for the
  catalog service name, falling back to `custom_service_name`, falling
  back to an em dash if neither is set yet), `WHERE
  care_jf_service_availments.agency_id = :agid`. Same
  search/sort/pagination shape as `api/applicants.php` (`SQL_CALC_FOUND_ROWS`,
  whitelisted sort columns) for consistency.
- Status column derives from `care_jf_service_availments.status` per §3.1's
  decision (`Active` → "AVAILING SERVICES", `Disabled` → "WITHDRAWN").
- "Date Availed" uses `updated_at`, not `created_at` — since a re-scan
  updates the existing row (§2) rather than inserting a new one,
  `updated_at` is the timestamp that actually reflects the most recent
  service selection; `created_at` would misleadingly freeze at the first
  visit even after a later re-scan changed which service was recorded.

## 9. Navigation restructure

`includes/sidebar.php`'s two `$navItems` arrays are rebuilt:

- **Partner Agency**: Dashboard, Applicant, Clients, Job Vacancies,
  Services, Report, **Settings ▾**, Logout. The Settings dropdown contains:
  My Partner Agency, Agency Users, Account Settings (the page currently at
  the flat `settings.php` link).
- **Administrator/Employee/Viewer**: Dashboard, Applicant, Clients,
  Employment, Job Vacancies, Services, Report, **Settings ▾**, Logout. The
  Settings dropdown contains: Partner Agency, Users (only if
  `can_manage_users()`), Audit Logs (only if `can_view_audit_logs()`),
  Account Settings.
- The dropdown is a small Alpine component (`x-data="{ open: <bool> }"`,
  `x-show`) consistent with this app's existing Alpine usage elsewhere —
  no new JS library. `open` defaults to `true` when the current page is
  one of that dropdown's own sub-pages (so the active section stays
  visibly expanded on load), `false` otherwise.
- "Applicant" (was "Applicants") and "Report" (was "Reports") label
  wording follows the source document's singular form; hrefs
  (`applicants.php`, `reports.php`) are unchanged — only the visible label
  text changes, so no links break.

## 10. Uppercase verification (no new mechanism, an audit pass)

The existing `mb_strtoupper($value, 'UTF-8')`-on-save pattern (already
applied throughout this codebase, including every Phase 1 form) is the
established mechanism — this phase adds no new one. Two new surfaces need
it applied for the first time, both already specified above:
`custom_service_name` (§5.3, §6) and any new `service_name`/`description`
fields on the relocated `services.php` (§7 — identical validation to what
`my-agency.php`/`partner-agency-form.php` already did, just relocated).
The implementation plan includes a verification pass confirming every
field the source document lists (§1) already uses this pattern (most do,
from Phase 1 or earlier) rather than assuming.

## 11. Security

- Every new POST handler (`api/service-availment-confirm.php`,
  `services.php`'s CRUD actions) calls `csrf_require()` before touching
  the database, and role-checks both at page-top and per-handler, per
  this codebase's standing rule.
- Every Partner-Agency-scoped query/write in this phase uses
  `current_agency_id($pdo)` exclusively — `service_id`, `agency_id`,
  never trusted from `$_GET`/`$_POST`/hidden fields, matching every
  existing pattern in this codebase (`vacancies.php`, `my-agency.php`,
  Phase 1's `tag_applicant_for_service()`).
- `set_service_availment_selection()` re-validates `service_id`'s agency
  ownership server-side even though the modal's dropdown is already
  agency-filtered — never trusts that the browser only ever sent an
  option that was actually rendered.
- No new output-escaping surface beyond `e()` used consistently, matching
  existing convention.

## 12. Out of scope for this phase

Any change to `public/reports.php` (separate, already in-progress
effort); the future "SERVICE COMPLETED" workflow state (§3.1 — explicitly
deferred by the source document itself); rate-limiting beyond existing
session/login throttling; any change to the Job Seeker/Employment
workflow's own behavior (only its *visibility gating*, already correct
from Phase 1, is reused here — not modified).

## 13. Testing

No automated test suite in this repo — manual verification, per this
repo's established convention:

- **Migration**: run against the live dev DB, confirm the two new nullable
  columns and the new FK exist, confirm zero existing rows are affected
  (both are nullable, no backfill needed).
- **QR scan, service-eligible Client, first time**: Step 1 creates the row
  (unchanged from Phase 1); modal opens with the scanning agency's Active
  services in the dropdown and `OTHERS`; selecting a real service and
  Confirm sets `service_id`, navigates, profile shows the selected service
  name in Services Availed History.
- **QR scan, same Client + agency again**: Step 1 reuses the existing row
  (no new row, confirmed via `SELECT COUNT(*)`); modal reopens, selecting a
  *different* service and Confirm updates the same row's `service_id`.
- **OTHERS path**: selecting `OTHERS`, leaving the text blank, and
  clicking Confirm is rejected client-side and server-side; entering
  lowercase text and confirming stores it uppercased.
- **Cancel path**: modal Cancel navigates to the profile with no POST; the
  row from Step 1 still exists with `service_id` still null.
- **Job-Seeker-only scan**: unchanged end-to-end — no `service_options` in
  the response, no modal, immediate navigation, exactly as Phase 1.
- **Manual "Tag for Service" on the profile page**: same dropdown/OTHERS
  behavior, single-request, correct flash text.
- **`services.php`**: Partner Agency sees/manages only their own services;
  staff can add a service to any agency via the `agency_id` dropdown;
  Viewer sees the list with no action buttons; a crafted POST from a
  Partner Agency naming another agency's `agency_id` is rejected (ignored
  in favor of session-derived value).
- **`clients.php` for Partner Agency**: only shows clients who've availed
  *this* agency's services, with the right Service Availed/Status/Date
  columns; staff's existing view is unchanged.
- **Sidebar**: Settings dropdown expands/collapses, auto-expands when
  already on a sub-page, all relocated links work, Account Settings still
  reachable for every role, Logout still always visible without scrolling
  (already true from Phase 1's sticky-sidebar fix).
- Regression: existing Job Seeker QR-tagging, "Tag for Review," hiring
  workflow, Employment History visibility — all unchanged, spot-checked
  end to end.
