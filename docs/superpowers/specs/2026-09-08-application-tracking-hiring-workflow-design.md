# Application Tracking & Hiring Workflow Redesign — Design (Phase 3 of 3)

## 1. Goal

Phase 3 of a 3-phase effort (Job Vacancies + Employer ID was Phase 1;
the Applicant QR Code was Phase 2 — both merged). Phase 3 replaces the
one-click "Mark as Hired" button (built in an earlier, pre-phased
effort) with a formal two-step workflow — a Partner Agency first tags
an applicant "For Review," then later confirms "Hired" against one of
their own open Job Vacancies — plus the per-agency visibility rules
and Manage Users updates that come with it.

## 2. Decisions confirmed with the user

- **Concurrent tagging**: multiple Partner Agencies can tag the same
  applicant "For Review" at the same time. Whichever agency hires
  first wins; every other agency's open tag on that applicant is then
  auto-closed (`Superseded`).
- **Vacancy is required at Hire time**: confirming "Hired" requires
  selecting one of the agency's own Active vacancies with
  `vacant_count > 0`. This is the reason Phase 1 built the vacancy
  module first.
- **"For Review" is a required first step**: "Confirm Hired" is only
  ever offered for an applicant this agency has already tagged "For
  Review." There is no direct one-step hire path anymore.
- **Cross-agency privacy**: a Partner Agency can only ever see their
  *own* tag/interest on an applicant — never that another agency has
  also tagged the same person. Administrator/Employee see every
  agency's tags, for oversight.
- **Remarks are private per agency**: an agency (or staff acting on
  its behalf) can write/edit a remark on their own review row at any
  time. It is never shared with other agencies — it lives on the new
  tracking row, not on the applicant's shared profile `remarks` field.
- **Hired applicants disappear from other agencies' pool**: once an
  applicant is hired (by *any* means — this reuses the system's one
  existing definition of "hired," `is_applicant_hired()`, not a new
  parallel one scoped just to this workflow), every Partner Agency
  other than the hiring one stops seeing that applicant in their
  applicants list. The hiring agency keeps seeing their own hire.
  Administrator/Employee are unaffected — they always see everyone.
- **Dashboard stats are out of scope for this phase**: the Partner
  Agency dashboard's summary cards are left as-is. "Available for
  Recruitment" already excludes every hired applicant regardless of
  agency, so it's unaffected by the new visibility rule; "Total
  Registered Applicants" keeps showing the true system-wide total
  rather than a per-agency-filtered count.

## 3. Database changes

New migration `database/migrations/add_application_tracking_and_hiring_workflow.sql`
(additive only — the established convention of never editing an
already-shipped migration continues to apply):

**New table `care_jf_applicant_agency_reviews`**
```sql
CREATE TABLE care_jf_applicant_agency_reviews (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  applicant_id INT UNSIGNED NOT NULL,
  agency_id    INT UNSIGNED NOT NULL,
  vacancy_id   INT UNSIGNED NULL,
  status       ENUM('For Review','Hired','Withdrawn','Superseded') NOT NULL DEFAULT 'For Review',
  remarks      TEXT NULL,
  tagged_by    INT UNSIGNED NOT NULL,
  tagged_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  TIMESTAMP NULL,
  CONSTRAINT fk_review_applicant FOREIGN KEY (applicant_id) REFERENCES care_jf_applicants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_review_agency    FOREIGN KEY (agency_id)    REFERENCES care_jf_partner_agencies(id) ON DELETE RESTRICT,
  CONSTRAINT fk_review_vacancy   FOREIGN KEY (vacancy_id)   REFERENCES care_jf_job_vacancies(id) ON DELETE RESTRICT,
  INDEX idx_review_applicant (applicant_id),
  INDEX idx_review_agency (agency_id),
  INDEX idx_review_status (status)
) ENGINE=InnoDB;
```
`tagged_by` deliberately has no FK constraint (matching how this
codebase's audit trail already treats the acting user id) so a row
never becomes orphaned/unreadable if a user account is later disabled.

There is no database-level uniqueness constraint preventing two open
`For Review` rows for the same `(applicant_id, agency_id)` pair — MySQL
has no partial/filtered unique index, and a real "re-tag after
withdrawing" case must remain possible. This is enforced in
application code instead: before inserting a new `For Review` row,
check no existing `For Review` row already exists for that pair.

**`care_jf_employment_records` gains one column**
```sql
ALTER TABLE care_jf_employment_records
  ADD COLUMN vacancy_id INT UNSIGNED NULL AFTER agency_id,
  ADD CONSTRAINT fk_employment_vacancy FOREIGN KEY (vacancy_id) REFERENCES care_jf_job_vacancies(id) ON DELETE RESTRICT;
```
Nullable and only ever populated by the new "Confirm Hired" flow — the
existing, unrelated internal Employment module (`employment-form.php`,
used by Administrator/Employee to record Job Order/Temporary/COS/
Permanent/Casual/Other classifications directly) keeps writing `NULL`
here, untouched by this phase.

## 4. Workflow

**Tag for Review** — available to a Partner Agency (their own agency,
always server-derived via `current_agency_id($pdo)`, never a posted
value) or to Administrator/Employee (choosing an agency from the same
`active_agencies()` dropdown the old Mark-as-Hired form already used).
Inserts a `For Review` row. No employment record is created, no
vacancy is touched, and the applicant's derived employment status is
completely unaffected — they remain "For Further Review" everywhere
else in the system exactly as before this phase existed.

**Confirm Hired** — only offered for an agency that already has an
open (`status = 'For Review'`) row on this applicant. Requires
selecting one of that agency's Active vacancies with
`vacant_count > 0`. On confirm, in one DB transaction:
1. Insert into `care_jf_employment_records` — same shape as the
   retired one-click flow's insert (`employment_status = 'Hired'`,
   `is_current = 1`, `status = 'Active'`, `agency_id`,
   `agency_company_name`/`agency_company_address` copied from the
   agency row), now also setting the new `vacancy_id`.
2. Decrement the selected vacancy's `vacant_count` by 1; if it reaches
   0, set the vacancy's `status` to `Filled`.
3. Update this review row: `status = 'Hired'`, `vacancy_id` set,
   `resolved_at = NOW()`.
4. For every *other* open (`For Review`) row on the same applicant
   (from other agencies): `status = 'Superseded'`,
   `resolved_at = NOW()`. One `audit_log()` call per superseded row
   (`APPLICANT_REVIEW_SUPERSEDED`), matching this codebase's
   one-entry-per-state-change audit convention.
5. `audit_log()` for the hire itself (`APPLICANT_HIRED`) and for the
   vacancy decrement (`VACANCY_DECREMENT`, record id = the vacancy).

A failure at any step rolls back the whole transaction — an applicant
can never end up "Hired" with no vacancy decremented, or a vacancy
decremented with no employment record, matching the transactional
discipline `generate_employer_id()`/`activate_agency` already
established in Phase 1.

**Withdraw** — an agency (or staff on its behalf) can release their
own open `For Review` row without hiring: `status = 'Withdrawn'`,
`resolved_at = NOW()`. Audit action `APPLICANT_REVIEW_WITHDRAWN`.

**Remarks** — the tagging agency (or staff acting on that agency's
row) can write/update `remarks` on their own row at any time while it
exists (`For Review` or `Hired` — not after `Withdrawn`/`Superseded`,
since there is no ongoing relationship left to annotate). Audit action
`APPLICANT_REVIEW_REMARKS_UPDATED`.

## 5. UI: `applicant-view.php`'s "Agency Interest" section

Replaces the retired single "Mark as Hired" button + confirmation
modal (both removed from this page). A role-scoped section, in the
same sidebar-card position the old Employment Status card occupied:

- **Partner Agency**: sees only their own row for this applicant,
  queried with `WHERE agency_id = current_agency_id($pdo)` — the same
  IDOR-safe pattern used everywhere else in this codebase. Three
  possible states rendered:
  - No row exists → a "Tag for Review" button.
  - Row exists, `status = 'For Review'` → status badge, a remarks
    textarea (editable, "Save Remarks" button), a "Confirm Hired"
    button (opens the vacancy-selection modal) and a "Withdraw"
    button.
  - Row exists, any other status → status badge + (if `Hired`) the
    vacancy/hire details, read-only remarks if present. No further
    action available.
- **Administrator/Employee**: sees a small table of every agency that
  has ever tagged this applicant (every status, for oversight), plus
  a "Tag New Agency for Review" control (an `active_agencies()`
  dropdown, excluding agencies that already have an open row). Can act
  on any row (Confirm Hired / Withdraw) on behalf of that agency,
  exactly as they could previously act on behalf of any agency in the
  old Mark-as-Hired dropdown.

## 6. Applicants list visibility (`public/api/applicants.php`)

For a Partner Agency session only (Administrator/Employee/Viewer
unaffected), the query gains a filter: an applicant is excluded once
`is_applicant_hired()` is true for them, **unless** the current active
employment record's `agency_id` equals the viewing agency's own id.
This reuses the query's existing `LEFT JOIN` against
`care_jf_employment_records` (already there for the `employment_status`
column) — no new join needed, just an additional condition alongside
the existing `is_deleted = 0` filter, scoped behind
`is_partner_agency()`.

## 7. `vacancies.php` delete guard

The delete action's comment already anticipated this
(`employment_records has no vacancy_id column until Phase 3`). Add the
dependency check the comment describes, mirroring
`partner-agency.php`'s existing employment-history guard:
`SELECT COUNT(*) FROM care_jf_employment_records WHERE vacancy_id = :id`
— if greater than 0, block the delete with a message directing the
Administrator to disable the vacancy instead.

## 8. Manage Users: Partner Agency password reset

`users.php`'s Partner Agency Accounts tab currently has Activate/
Disable/Re-enable/Delete but genuinely no password-reset action
(confirmed by reading the current file — only the separate System
Users tab has one). Add a reset-password row action for Partner Agency
accounts, reusing the existing `reset_password` POST action already
implemented generically in this file (it operates on any
`care_jf_users` row by `user_id`, so no backend change is needed —
this is a UI-only addition mirroring the System Users tab's existing
key-icon/inline-form pattern).

## 9. Security

- Every mutation re-derives `agency_id` server-side for a Partner
  Agency session — never trusted from a posted value, identical
  discipline to every prior phase.
- Administrator/Employee's chosen agency (Tag for Review, Confirm
  Hired, Withdraw, or Remarks acting on an agency's behalf) is
  validated against `active_agencies()` before use.
- The Hire confirmation's vacancy selection is re-validated
  server-side against that specific agency's own vacancies with
  `status = 'Active' AND vacant_count > 0` — a Partner Agency can never
  decrement another agency's vacancy, and a stale/already-filled
  vacancy id is rejected rather than trusted from the form.
- The hire transaction (insert employment record, decrement vacancy,
  update review row, supersede competing rows) is atomic — see §4.
- Every new POST action re-checks role/ownership server-side inside
  its own handler, in addition to the page's top-of-file gate — this
  codebase's standing double-enforcement convention.
- `e()` escaping on all new dynamic output, including remarks text.

## 10. Out of scope for Phase 3

Recalculating the Partner Agency dashboard's summary cards for the new
visibility rule (§2); any change to the unrelated internal Employment
module (`employment-form.php`'s Job Order/Temporary/COS/Permanent/
Casual/Other flow); new report types beyond what already exists in
`reports.php` — this phase does not add new reports, only keeps the
existing ones correct against the new schema (an applicant who is
"For Review" but not yet hired still reports as "For Further Review,"
unchanged).

## 11. Testing

No automated test suite in this repo (CLAUDE.md convention) — same
`php -l` + MySQL CLI + curl-driven HTTP checks as Phases 1 and 2,
including: the full tag → hire transaction verified atomic (vacancy
decrement, employment record, review-row update, and supersession of
competing agencies' rows all land together or none do); the
cross-agency privacy rule (Agency B's session genuinely cannot see
that Agency A tagged the same applicant, via both the profile page and
any API response); the post-hire visibility rule (a hired applicant
disappears from every other agency's `applicants.php`/API results
while remaining visible to the hiring agency and to
Administrator/Employee); the vacancy-delete guard now blocking
deletion of a vacancy with hire history; and the Partner Agency
password-reset action working end-to-end via the already-existing
`reset_password` handler.
