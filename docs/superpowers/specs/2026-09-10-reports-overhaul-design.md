# Reports Module Overhaul — Design Spec

Date: 2026-09-10

## Context

`public/reports.php` currently exposes 15 report types (demographic
breakdowns, employment-status breakdowns, by-agency, by-registration-date,
services-availed). This spec replaces that report list with exactly 4
report types per explicit product decision, reusing all existing
infrastructure (`care_jf_applicants.service_job_seeker` /
`service_agency_services`, `care_jf_employment_records`, CSRF, RBAC,
`stream_xlsx()`, the existing print layout).

No schema changes are needed — every value used below already exists on
`care_jf_applicants`, `care_jf_employment_records`, `care_jf_partner_agencies`,
and `care_jf_job_vacancies`.

## Decisions already confirmed with the user

- The Report Type dropdown is **replaced** by exactly these 4 entries (the
  other 15 existing report types are removed from the UI; nothing about
  the underlying applicant/employment data is deleted).
- Withdrawn/Superseded `employment_records` rows do **not** count as "applied"
  for the not-hired report — only `employment_status = 'For Review'` does.
  (This is also structurally guaranteed: `supersede_other_reviews()` already
  flips every other agency's `For Review` row to `Superseded` the moment one
  agency hires the applicant, so a hired applicant cannot have a live
  `For Review` row anywhere else.)
- Year attribution: registration year (`care_jf_applicants.created_at`) for
  the Job Seeker count; `care_jf_employment_records.created_at` (the tag
  date) for agency participation and the not-hired count; `date_hired` for
  the hired count and vacancy-fill count; vacancy `created_at` for vacancies
  created that year.
- "Total Job Vacancies Filled" = count of `Hired` employment_records rows
  with a non-null `vacancy_id`, bucketed by `date_hired` year (not by
  counting vacancy rows with `status='Filled'`).
- **Superseded 2026-09-10:** the 4 new report types are visible to both
  roles. Administrator/Employee sees the full cross-agency view described
  below. Partner Agency sees the same 4 report types but every query is
  scoped to `agency_id = current_agency_id($pdo)` (never trusted from the
  request — same pattern `reports.php` already uses for its other
  agency-scoped reports today), with labels and content adjusted per
  report where a cross-agency concept doesn't apply to a single agency:
  - **Services Availed** (Partner Agency): scoped to applicants who have
    at least one `employment_records` row with this agency (any status).
    Label: "Services Availed — My Agency's Applicants".
  - **All Hired Applicants** (Partner Agency): scoped to
    `agency_id = current_agency_id()`; no per-agency grouping needed
    (there's only one). Label: "Applicants Hired by My Agency".
  - **Applicant that was not hired** (Partner Agency): Section A only
    (scoped to `agency_id = current_agency_id()`), same "Not Hired"
    semantics. Section B ("Registered but Didn't Apply") is omitted for
    this role — it's inherently agency-agnostic (an applicant who applied
    to no one belongs to no agency's report). Label: "Applicants Not
    Hired by My Agency".
  - **Summarize per participant per year** (Partner Agency): "Total
    Participating Agency" is dropped (meaningless at single-agency scope).
    "Total Job Seeker" is replaced with "Total Applicants Engaged" =
    `COUNT(DISTINCT applicant_id)` from this agency's `employment_records`
    rows (any status) created that year. Hired/Not Hired/Vacancies/Filled
    keep the same definitions, scoped to this agency. Label: "My Agency's
    Yearly Summary".

## Report 1 — Services Availed

Three mutually exclusive buckets from the two boolean columns:
- Job Seeker only: `service_job_seeker=1 AND service_agency_services=0`
- Agency Services only: `service_job_seeker=0 AND service_agency_services=1`
- Both: `service_job_seeker=1 AND service_agency_services=1`

One query against `care_jf_applicants WHERE is_deleted=0`, split into the
3 buckets in PHP from a single `CASE` column so the totals can't drift
apart from the detail rows. Columns: Seq No., Applicant ID, Full Name,
Sex, Services Availed, Date Registered. Summary line: 3 bucket counts +
total (total = sum of the 3, since every applicant is in exactly one
bucket, or in none if both are 0 — those rows deliberately excluded from
all 3 buckets and from the total, since a service-less applicant should
not have gotten past registration validation but a pre-existing temp
record could).

Filter: optional Date Registered range (from/to), applied to
`care_jf_applicants.created_at`.

## Report 2 — All Hired Applicants

```sql
SELECT a.*, er.*, pa.agency_name
FROM care_jf_employment_records er
JOIN care_jf_applicants a ON a.id = er.applicant_id AND a.is_deleted = 0
JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
WHERE er.employment_status = 'Hired' AND er.is_current = 1 AND er.status = 'Active'
```
Grouped by `pa.agency_name`, ordered by `er.date_hired`. Columns: Seq No.
(per-agency), Applicant ID, Full Name, Sex, Services Availed, Office/Agency,
Eligibility Type, Date Hired, Employment Status. Per-agency subtotal +
grand total. Filters: Year (`date_hired`), Agency, Date range
(`date_hired` from/to).

## Report 3 — Applicant that was not hired

**Section A — by agency:**
```sql
SELECT a.*, er.*, pa.agency_name
FROM care_jf_employment_records er
JOIN care_jf_applicants a ON a.id = er.applicant_id AND a.is_deleted = 0
JOIN care_jf_partner_agencies pa ON pa.id = er.agency_id
WHERE er.employment_status = 'For Review' AND er.status = 'Active'
```
Grouped by agency, same column set as Report 2 but Date Hired shows
"N/A" and Employment Status shows "Not Hired". Filters: Year (tag
`created_at`), Agency.

**Section B — Registered but Didn't Apply:**
```sql
SELECT a.* FROM care_jf_applicants a
WHERE a.is_deleted = 0 AND a.service_job_seeker = 1
  AND NOT EXISTS (SELECT 1 FROM care_jf_employment_records er WHERE er.applicant_id = a.id)
```
Office/Agency = "N/A", Date Hired = "N/A", Employment Status =
"Registered — Did Not Apply". Not assigned to any agency section.

Grand total = Section A total + Section B total, reported separately per
the brief's format (Section A shows its own per-agency + "TOTAL NOT
HIRED", Section B shows its own count).

## Report 4 — Summarize per participant per year

Filter: From Year / To Year, default = last 5 years ending this year.
For each year in range, 6 independently-computed metrics:

1. **Total Job Seeker**: `COUNT(DISTINCT a.id) WHERE service_job_seeker=1 AND YEAR(a.created_at) = :y`
2. **Total Participant Hired**: `COUNT(DISTINCT er.applicant_id) WHERE employment_status='Hired' AND is_current=1 AND status='Active' AND YEAR(date_hired) = :y`
3. **Total Participant Not Hired**: `COUNT(DISTINCT er.applicant_id) WHERE employment_status='For Review' AND status='Active' AND YEAR(er.created_at) = :y` (Registered-but-didn't-apply applicants are never counted here — they have no employment_records row to match on)
4. **Total Participating Agency**: `COUNT(DISTINCT er.agency_id) WHERE YEAR(er.created_at) = :y` (any employment_records row counts as participation that year, regardless of eventual outcome)
5. **Total Job Vacancies**: `SUM(vacant_count) WHERE YEAR(created_at) = :y` (positions created that year, not row count)
6. **Total Job Vacancies Filled**: `COUNT(*) FROM employment_records WHERE employment_status='Hired' AND vacancy_id IS NOT NULL AND YEAR(date_hired) = :y`

Each metric is its own scalar query per year (6 queries × N years) rather
than one giant join, since the 6 metrics have incompatible filter/date
columns and forcing them into one query would require fragile
conditional-COUNT-DISTINCT-with-different-WHERE tricks. N is small
(default 5, admin-adjustable), so this stays well clear of any N+1
concern — this is a fixed small number of aggregate queries, not one
query per row.

## UI / Filters / Print / Export

- Same `reports.php` page, same visual shell (card, table, print letterhead
  already in place). Report Type `<select>` now lists only the 4 types.
  Filter fields shown conditionally per report type (Alpine `x-show`, no
  new JS file needed):
  - Services Availed: Date Registered range.
  - All Hired Applicants / Not Hired: Year, Agency, Date range.
  - Per-year summary: From Year / To Year.
- Print: reuse the existing `print:hidden` / print letterhead block
  pattern already in `reports.php`; grouped tables print with an agency
  sub-heading row per group (`<tr>` with `colspan`), avoiding
  `break-inside: avoid` issues by keeping each agency's rows contiguous.
- Export: `stream_xlsx()` reused as-is (no changes to the writer needed).
  For grouped reports, the exported sheet is flattened to one sheet with
  an inserted "Agency: X — Total Hired: N" row between groups, mirroring
  the print/screen grouping without needing a multi-sheet writer.

## Security

- `require_login()` already at top of `reports.php`; the 4 new report
  keys are simply never added to `$reportOptions` when `is_partner_agency()`
  is true (same mechanism already used for `by_agency`/`not_yet_hired`),
  so a Partner Agency account cannot select them from the UI. Server-side,
  the report-generation `switch` also checks `is_partner_agency()` before
  running any of the 4 new report queries and 403s a direct
  `?report_type=all_hired` URL attempt from that role — hiding the menu
  entry alone is not sufficient enforcement.
- All queries use PDO prepared statements with named params, no string-built
  WHERE clauses from request input beyond the fixed `switch`-selected SQL
  fragments already used elsewhere in this file.
- No new write operations — reports.php remains read-only, so no CSRF
  token is needed on the GET-based filter form (consistent with current
  behavior).

## Migration

Purely additive/subtractive in `public/reports.php` only — remove the 15
old `$reportOptions` entries and their `switch` cases, add the 4 new ones.
No `ALTER TABLE`, no data migration: every column the new reports read
already exists and is already populated by the registration/QR/hiring
flows.
