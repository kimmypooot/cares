# Uppercase Formatting & Partner Agency Hiring — Design

## 1. Goal

Two independent, additive changes to the existing CARE system:

1. Consistently uppercase user-entered/displayed applicant and Partner
   Agency data (excluding email/username/password), extending a pattern
   that already exists on `register-applicant.php` but is missing
   elsewhere.
2. Let a Partner Agency (for any applicant) or an Administrator/Employee
   (for any applicant, with an agency of their choice) move an applicant
   from `FOR FURTHER REVIEW` to a new `Hired` classification, with the
   hiring agency's info then visible on the applicant's record.

## 2. Decisions confirmed with the user

- Uppercase scope: user-entered free-text data fields + derived status
  labels only. UI chrome (nav, buttons, role badges) is untouched.
- `Hired` becomes a new `employment_status` ENUM value (migration), used
  by this new quick action. The existing granular classifications
  (Job Order/Temporary/COS/Permanent/Casual/Other) and the full Employment
  module are untouched.
- Hiring authorization: any Active Partner Agency may hire any applicant
  currently `FOR FURTHER REVIEW` — first-come basis, no pre-existing
  applicant-to-agency relationship exists in this system (the applicant
  pool is an intentionally shared, view-only list per the prior Partner
  Agency Registration work). The only server-side guard needed is
  preventing a double-hire.

## 3. Additional implementation decisions (flagging for review)

1. **Remarks/notes free-text fields are excluded** from forced uppercase
   (`applicants.remarks`, `employment_records.remarks`). These are
   narrative notes where case can carry meaning; the requirement's
   examples are all short identity/location fields. Correct me if these
   should be included too.
2. **`contact_no`/`contact_number` fields are left alone** — they're
   numeric, so uppercase is a no-op either way; not worth touching.
3. **Derived/enum labels get a *visual* uppercase (CSS), not a data
   change** — `employment_status` values, the "For Further Review"
   literal, and the "Job Seeker"/"Agency Services" service-badge labels
   stay exactly as currently stored/compared internally (so no SQL
   `WHERE`, PHP `switch`, or color-map lookup anywhere in the codebase
   needs to change) — only their rendered text is uppercased, via the
   `uppercase` Tailwind utility class already used elsewhere in this
   codebase for section headers. This is zero-risk to business logic.
   The one exception is **Chart.js labels on `dashboard.php`**, which are
   canvas-drawn and don't respond to CSS — those label *strings* get
   uppercased in PHP right before `json_encode`, in a separate array from
   the one used for counting/color lookups (which stays original-case).
4. **`Employee` gets the new "Mark as Hired" shortcut too**, not just
   Administrator and Partner Agency. Employee already has full
   employment-record management rights via the existing Employment
   module (`can_manage_employment()` = Administrator + Employee) — broader
   than what this feature grants. Excluding Employee from the new
   shortcut while still letting them do the same thing via the full form
   would be an inconsistent, confusing restriction, and the prompt's own
   rule ("Admin functionality should not be more restricted... unless an
   existing permission requirement") points the same direction.
5. **The new action lives on `applicant-view.php`**, not a new page —
   it's a state transition on data already displayed there, matching
   how "Add Employment Record" already works on the same page.
6. **Scoped to the applicant profile page only**, not the Applicants list
   — matches the existing pattern (the list has no per-row employment
   actions today either).
7. **Race-condition guard**: immediately before inserting the new
   `employment_records` row, re-check (in the same request) that the
   applicant has no `is_current=1 AND status='Active'` row. If one
   exists (another actor claimed them first), reject with a friendly
   error rather than creating a second "current" record. This is a
   plain re-check, not a `SELECT ... FOR UPDATE` — consistent with how
   the rest of this codebase already handles `is_current` bookkeeping
   (no row locking anywhere else either).
8. **Migration re-runs `UPPER()` on `applicants`**, even though a prior
   migration already did this once — `applicant-create.php`/
   `applicant-edit.php` never got the uppercase-on-save convention, so
   any applicant created or edited through those admin forms since that
   migration may be mixed-case. Re-running `UPPER()` on already-uppercase
   data is a safe no-op. Adds the same normalization for
   `partner_agencies` (`agency_name`, `address`, `contact_person`) and
   `users.full_name`, which have never been normalized.

## 4. Database changes

New migration `database/migrations/add_hired_status_and_uppercase_backfill.sql`:

- `employment_records.employment_status` ENUM widened to add `'Hired'`:
  `ENUM('Job Order','Temporary','COS','Permanent','Casual','Other','Hired')`.
- One-time backfill: `UPPER()` on `applicants.last_name/first_name/
  middle_name/address/place_of_birth/extension_name`,
  `partner_agencies.agency_name/address/contact_person`,
  `users.full_name`. Idempotent (uppercasing already-uppercase text is a
  no-op).

No other schema changes — `employment_records.agency_id` already exists
(added in the Partner Agency Registration work) and is exactly what's
needed to persist "which agency hired this applicant."

## 5. "Mark as Hired" flow

**New POST action** on `public/applicant-view.php`: `action=mark_hired`.

- Allowed for: `can_manage_employment()` (Administrator/Employee) OR
  `is_partner_agency()`.
- Administrator/Employee: form includes an agency `<select>` (reusing
  `active_agencies($pdo)`, same as `employment-form.php`) — required.
- Partner Agency: `agency_id` is **always** `current_agency_id($pdo)`,
  never read from the request — consistent with every other Partner
  Agency write path in this system.
- Re-checks applicant exists, is not deleted, and has no current active
  employment record (`is_current=1 AND status='Active'`) — 409-style
  friendly error via `flash_set()` if that check fails.
- Inserts one `employment_records` row: `agency_id`, `agency_company_name`/
  `agency_company_address` (looked up from `partner_agencies`, same
  pattern as `employment-form.php`), `date_hired = today`,
  `employment_status = 'Hired'`, `is_current = 1`, `status = 'Active'`.
- `audit_log()`: action `MARK_HIRED`, table `employment_records`,
  description naming the applicant code and hiring agency.
- Redirects back to `applicant-view.php?id=...` with a success flash.

**UI**: on the existing "Employment Status" card (right sidebar of
`applicant-view.php`), when the applicant is currently `FOR FURTHER
REVIEW` and the viewer is Administrator/Employee/Partner Agency, show a
"MARK AS HIRED" button. Clicking opens a confirmation (Alpine, matching
the existing confirm-modal pattern already used on
`applicant-create.php`) — Admin/Employee's modal includes the agency
picker; Partner Agency's modal just confirms hiring into their own named
agency. Existing Edit/Delete/Add-Employment-Record buttons are unchanged
(still `can_edit()`/`can_delete()`-gated, still excluding Partner Agency).

## 6. Displaying agency info on a hired applicant

`applicant-view.php`'s existing employment queries already
`LEFT JOIN partner_agencies pa ON pa.id = er.agency_id` and show
`agency_display_name` (`COALESCE(pa.agency_name, er.agency_company_name)`)
plus `agency_company_address` for the current record — this already
works for the new `Hired` classification with no changes, since the
display logic isn't conditioned on which specific `employment_status`
value it is.

What's missing: **Partner Agency contact info** (`contact_person`,
`contact_no`, `email`) isn't currently selected or shown anywhere on this
page. Add those three columns to the existing current-employment query
and display them in the "Employment Status" card whenever the current
record references a real `partner_agencies` row (`agency_id IS NOT
NULL`) — falls back to nothing extra when it's a free-text legacy agency
entry (`agency_id IS NULL`), same as the existing agency-name fallback
already does.

## 7. Security

- Every new/modified state-changing branch on `applicant-view.php`
  re-checks role server-side (existing double-enforcement convention on
  this file already does this for `delete`/`enable_employment`/etc.).
- `csrf_require()` on the new action (page already requires it for all
  POSTs).
- Partner Agency's `agency_id` is never accepted from the request.
- Uppercase normalization happens server-side (`mb_strtoupper()`) on
  every form that writes the affected columns, not just client-side JS —
  defense in depth, matching `register-applicant.php`'s existing comment
  ("in case JavaScript is disabled or the form is submitted directly").

## 8. Files touched

**New**
- `database/migrations/add_hired_status_and_uppercase_backfill.sql`

**Modified**
- `includes/functions.php` (color map gets a `Hired` entry;
  `current_employment_status()`'s color map, matching existing entries)
- `public/applicant-create.php`, `public/applicant-edit.php` (uppercase
  fields)
- `public/employment-form.php` (uppercase `agency_free_text`)
- `public/partner-agency-form.php`, `public/my-agency.php` (uppercase
  agency fields)
- `public/login.php` (uppercase registration panel's agency/contact
  fields)
- `public/users.php` (uppercase `full_name`)
- `public/applicant-view.php` (new `mark_hired` action + UI + agency
  contact-info display)
- `public/dashboard.php` (uppercased Chart.js label strings)
- `public/applicants.php`, `public/api/applicants.php`, `public/reports.php`
  (visual `uppercase` class on status/service badges — no data change)

## 9. Testing

Same convention as the prior Partner Agency Registration work — no
automated test suite in this repo; verification via `php -l`, MySQL CLI
assertions, and curl-driven HTTP checks against the real local database,
including: uppercase round-trips through each modified form; a Partner
Agency hiring an applicant end-to-end and the agency info appearing on
the applicant's profile; the double-hire race guard rejecting a second
hire attempt; an unauthenticated/unauthorized-role direct POST to
`mark_hired` being rejected.
