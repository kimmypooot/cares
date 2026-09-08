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
  its behalf) can write/edit a remark on their own tag at any time. It
  is never shared with other agencies.
- **Hired applicants disappear from other agencies' pool**: once an
  applicant is hired (reusing the system's one existing definition of
  "hired," `is_applicant_hired()`), every Partner Agency other than
  the hiring one stops seeing that applicant in their applicants list.
  The hiring agency keeps seeing their own hire. Administrator/Employee
  are unaffected — they always see everyone.
- **Dashboard stats are out of scope for this phase**: the Partner
  Agency dashboard's summary cards are left as-is (see original
  reasoning in §11) — except for one specific, unrelated correctness
  fix to the hires-per-month chart query, §7.
- **Tagging "For Review" immediately shows up in the applicant's
  Employment History** — this is a revision made after reviewing the
  first draft of this spec. The agency's tag is not a side record in a
  separate area; it *is* an Employment History entry from the moment
  it's created (just not yet a confirmed one). This reshapes §3-§5
  below relative to the first draft: there is no separate tracking
  table — the existing `care_jf_employment_records` table is extended
  instead.

## 3. Database changes

New migration `database/migrations/add_application_tracking_and_hiring_workflow.sql`
(additive only). **No new table.** `care_jf_employment_records`'s
actual current schema (confirmed by reading the live database, not
assumed) is:

```sql
CREATE TABLE `care_jf_employment_records` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `applicant_id` int(10) unsigned NOT NULL,
  `agency_id` int(10) unsigned DEFAULT NULL,
  `agency_company_name` varchar(200) NOT NULL,
  `agency_company_address` varchar(255) NOT NULL,
  `date_hired` date NOT NULL,
  `employment_status` enum('Job Order','Temporary','COS','Permanent','Casual','Other','Hired') NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('Active','Disabled') NOT NULL DEFAULT 'Active',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  ...
  CONSTRAINT `fk_employment_agency` FOREIGN KEY (`agency_id`) REFERENCES `care_jf_partner_agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employment_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `care_jf_applicants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
```

Three changes:

```sql
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

- `remarks` already exists — reused as-is, no change. It's already
  displayed in the Employment History table today.
- `date_hired` becomes nullable because a `For Review` row has no hire
  date yet — it's populated only at the moment a row transitions to
  `Hired`. The internal Employment module (`employment-form.php`)
  keeps requiring/setting a real date for its own record types
  (Job Order/Temporary/COS/Permanent/Casual/Other) — nothing there
  changes; nullability is additive.
- No `tagged_by`/actor-id column is added — this codebase's existing
  `audit_log()` trail already answers "who did this and when" for
  every state transition (see §4), matching how other tables in this
  codebase (e.g. `care_jf_job_vacancies`) don't store a
  created-by column either.
- `vacancy_id` is the same nullable, `ON DELETE RESTRICT` column
  planned in the first draft — unchanged, just living on the table
  that now also hosts the tracking rows instead of a second table.

## 4. Workflow

One `care_jf_employment_records` row represents one Partner Agency's
relationship with one applicant, progressing through states via
`employment_status`. **The row is inserted once, at "Tag for Review,"
and updated in place as it progresses** — Confirm Hired and Withdraw
are `UPDATE`s to that same row, not new inserts. This means a Partner
Agency's tag is visible in Employment History from the moment it's
created, exactly as requested.

**Tag for Review** — available to a Partner Agency (their own agency,
always server-derived via `current_agency_id($pdo)`) or to
Administrator/Employee (choosing an agency from the same
`active_agencies()` dropdown the old Mark-as-Hired form already used).
Before inserting, check no existing row for this
`(applicant_id, agency_id)` pair already has `employment_status =
'For Review'` — if one does, block (no duplicate concurrent tags from
the same agency; re-tagging is fine once a prior tag has moved to
`Withdrawn`/`Superseded`/`Hired`, since none of those match the check).
Insert:
```
agency_id            = <resolved agency>
agency_company_name  = <that agency's current name, snapshotted>
agency_company_address = <that agency's current address, snapshotted>
date_hired            = NULL
employment_status      = 'For Review'
is_current              = 0
status                  = 'Active'
```
`is_current = 0` is what keeps this row invisible to
`is_applicant_hired()`/`current_employment_status()` (both filter
`is_current = 1 AND status = 'Active'`) — nothing else in the system
(dashboard counts, the "hide once hired" rule in §6, reports) is
affected by a mere tag. Audit action `APPLICANT_TAGGED_FOR_REVIEW`.

**Confirm Hired** — only offered for a row this agency already has in
`For Review` status on this applicant. Requires selecting one of that
agency's Active vacancies with `vacant_count > 0`. Guarded, same as
the retired one-click flow was, against a double-hire: block if the
applicant already has any other `is_current = 1 AND status = 'Active'`
row. In one DB transaction:
1. Clear `is_current` on every other row for this applicant first
   (this codebase's standing bookkeeping rule for this column — see
   CLAUDE.md).
2. `UPDATE` this row: `employment_status = 'Hired'`, `is_current = 1`,
   `date_hired = CURDATE()`, `vacancy_id = <selected>`.
3. Decrement the selected vacancy's `vacant_count` by 1; if it reaches
   0, set the vacancy's `status` to `Filled`.
4. For every *other* row on the same applicant still in `For Review`
   (from other agencies): `UPDATE ... SET employment_status =
   'Superseded'`. One `audit_log()` call per superseded row.
5. `audit_log()` for the hire itself (`APPLICANT_HIRED`) and the
   vacancy decrement (`VACANCY_DECREMENT`).

A failure at any step rolls back the whole transaction.

**Withdraw** — an agency (or staff on its behalf) can release their
own `For Review` row: `UPDATE ... SET employment_status =
'Withdrawn'`. Audit action `APPLICANT_REVIEW_WITHDRAWN`. A withdrawn
row is not reused by a later re-tag — a fresh "Tag for Review" inserts
a new row, preserving the full history of every episode of interest
this agency ever had in this applicant.

**Remarks** — the tagging agency (or staff acting on that agency's
row) can write/update the row's existing `remarks` column at any time
while it's `For Review` or `Hired` (not after `Withdrawn`/`Superseded`
— no ongoing relationship left to annotate). Audit action
`APPLICANT_REVIEW_REMARKS_UPDATED`.

## 5. UI: `applicant-view.php`'s Employment History section

**No new section.** The existing Employment History table gains new
row states, styled distinctly, and its query/actions become
role-scoped:

- **Query, role-scoped**: Administrator/Employee see every row for
  this applicant, exactly as today (this includes every agency's
  `For Review`/`Withdrawn`/`Superseded` rows, for oversight). A
  Partner Agency session adds a filter: every row whose
  `employment_status` is one of `For Review`/`Withdrawn`/`Superseded`
  is hidden **unless** its `agency_id` matches their own — so they
  still see every confirmed/historical employment record exactly as
  before (nothing about that visibility changes), but only their own
  in-flight tags, never another agency's.
- **Ordering**: switches from `ORDER BY date_hired DESC, id DESC` to
  `ORDER BY created_at DESC, id DESC` — `date_hired` is now nullable
  for `For Review` rows, so it's no longer a reliable global sort key.
- **Display**: a `For Review`/`Withdrawn`/`Superseded` row shows a
  distinct badge (matching this table's existing badge pattern) and
  "—" (or "Pending") where `format_date($rec['date_hired'])` would
  otherwise print an empty string for a null date.
- **Actions per row**: the existing generic Enable/Disable/Delete
  actions (Administrator-only, via `enable_employment`/
  `disable_employment`/`delete_employment`) continue to apply only to
  genuinely confirmed records exactly as today — untouched by this
  phase. `For Review` rows instead get **Confirm Hired** (opens the
  vacancy-selection modal) and **Withdraw**, visible to the owning
  Partner Agency or to Administrator/Employee acting on that agency's
  behalf. `Withdrawn`/`Superseded` rows are read-only history, no
  actions.
- **Tag for Review trigger**: a button near the Employment History
  section header — for a Partner Agency, tags their own agency
  directly; for Administrator/Employee, opens an `active_agencies()`
  dropdown (excluding agencies that already have an open `For Review`
  row on this applicant) to tag on behalf of a chosen agency.
- A remarks field (textarea + "Save Remarks") is shown inline on a
  `For Review`/`Hired` row the viewer is allowed to edit (their own
  agency's row, or staff acting on that agency's behalf).

## 6. Applicants list visibility (`public/api/applicants.php`)

Unchanged from the first draft — this was never dependent on which
table backs the tracking, only on `is_applicant_hired()`, which
remains correct because `For Review`/`Withdrawn`/`Superseded` rows
never set `is_current = 1`. For a Partner Agency session, an applicant
is excluded once hired **unless** the current active record's
`agency_id` equals the viewing agency's own id.

## 7. `public/dashboard.php` — one query needs an `is_current` guard

Audited every `care_jf_employment_records` query in `dashboard.php`
and `reports.php` for the assumption "every row here is a confirmed
hire," now that `For Review`/`Withdrawn`/`Superseded` rows exist
alongside real ones. `reports.php`'s one join already filters
`is_current = 1 AND status = 'Active'`, so it's unaffected by
construction — a `For Review`/etc. row never matches (it's always
`is_current = 0` until promoted to `Hired`). Every query in
`dashboard.php` has the same guard **except one**: the "Applicants
Hired Per Month" chart data,
```sql
SELECT DATE_FORMAT(date_hired, '%Y-%m') AS ym, COUNT(*) AS total
FROM care_jf_employment_records WHERE status = 'Active'
GROUP BY ym ORDER BY ym DESC LIMIT 6
```
Without an `is_current = 1` filter, this would now also count
`For Review` rows (`status = 'Active'` but not yet a real hire,
`date_hired = NULL`), corrupting the chart with a spurious NULL-month
bucket. Fix: add `AND is_current = 1` to the `WHERE` clause, matching
every sibling query in this same file exactly. This is the one
required code change from this audit; noted here as its own numbered
item (not folded into "testing will catch it") because it's a fix, not
a test.

## 8. `vacancies.php` delete guard

Unchanged from the first draft: `SELECT COUNT(*) FROM
care_jf_employment_records WHERE vacancy_id = :id` blocks deletion of
a vacancy with hire history.

## 9. Manage Users: Partner Agency password reset

Unchanged from the first draft — add a reset-password row action to
the Partner Agency Accounts tab, reusing the existing generic
`reset_password` POST action.

## 10. Security

- Every mutation re-derives `agency_id` server-side for a Partner
  Agency session — never trusted from a posted value.
- Administrator/Employee's chosen agency is validated against
  `active_agencies()` before use.
- Every action targeting an *existing* row (Confirm Hired, Withdraw,
  Remarks) re-resolves that row's true `agency_id` from the database
  first and 403s a Partner Agency session whose own agency doesn't
  match — the same DB-resolved-ownership pattern
  `vacancies.php`/`applicant-view.php`'s other POST handlers already
  use, now applied to `employment_records` rows in the new states.
- The Hire confirmation's vacancy selection is re-validated
  server-side against that specific agency's own vacancies with
  `status = 'Active' AND vacant_count > 0`.
- The hire transaction (clear other `is_current`, update the row,
  decrement vacancy, supersede competing rows) is atomic.
- Every new POST action re-checks role/ownership server-side inside
  its own handler, in addition to the page's top-of-file gate.
- `e()` escaping on all new dynamic output, including remarks text.
- The Employment History query's Partner-Agency filter (§5) is the
  sole mechanism enforcing cross-agency privacy — it is covered
  explicitly in testing (§12) since a mistake there leaks exactly the
  information this phase was asked to keep private.

## 11. Out of scope for Phase 3

Recalculating the Partner Agency dashboard's summary cards for the new
visibility rule; any change to the unrelated internal Employment
module (`employment-form.php`'s Job Order/Temporary/COS/Permanent/
Casual/Other flow, or the existing Enable/Disable/Delete actions on
confirmed records); new report types beyond keeping the existing ones
correct against the extended schema.

## 12. Testing

No automated test suite in this repo — same `php -l` + MySQL CLI +
curl-driven HTTP checks as Phases 1 and 2, including: the full tag →
hire transaction verified atomic; **the cross-agency privacy filter
specifically** — Agency B's session genuinely cannot see that Agency A
tagged the same applicant, verified by reading Agency B's rendered
Employment History HTML and confirming Agency A's row is absent, not
just hidden by CSS; the post-hire visibility rule (a hired applicant
disappears from every other agency's `applicants.php`/API results
while remaining visible to the hiring agency and to
Administrator/Employee); a `For Review` row's `date_hired = NULL`
correctly renders as "—" and is excluded from any month-bucketed
report/chart query that groups by `date_hired` (e.g. the dashboard's
existing hires-per-month chart) so it doesn't silently corrupt those
counts; the vacancy-delete guard blocking deletion of a vacancy with
hire history; the Partner Agency password-reset action.
