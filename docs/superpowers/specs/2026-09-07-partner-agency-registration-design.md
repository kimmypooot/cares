# Partner Agency Registration & Agency-Based Access Control — Design

Status: Phase 1 design (approved scope: Phase 1 only). Phase 2 (Job
Vacancies module + agency vacancy stats + Partner Agency Summary report)
is scoped and referenced here for forward-compatibility, but specced and
planned separately once Phase 1 ships.

## 1. Goal

Replace the public "Sign Up as a Viewer" flow with a "Partner Agency
Registration" flow. A registered agency gets a `Partner Agency` user
account that starts `Pending`, must be activated by an Administrator, and
once `Active` can only ever see and act on data belonging to its own
`agency_id` — enforced server-side on every query, never via UI hiding
alone.

## 2. Decisions already confirmed with the user

- Partner Agency Summary percentages use the conventional formula:
  `(Level Count / Total Vacant Positions) × 100`. (Phase 2.)
- Work is split into two phases. This spec + the next implementation plan
  cover **Phase 1** only: registration, approval, login gating, the
  `Partner Agency` role, and agency-scoped visibility of the *existing*
  Applicants/Dashboard/Reports/sidebar. **Phase 2** (separate spec/plan
  later) adds the Job Vacancies subsystem, agency vacancy stats, and the
  Partner Agency Summary report — none of that exists in the codebase
  today, so it's genuinely new work, not "maintaining" anything.
- Account status becomes one shared model: `users.status ENUM('Pending',
  'Active','Disabled')`, used by every role. The existing public Viewer
  sign-up flow (`signup.php`) is migrated onto the same enum so Pending
  vs Disabled messaging is consistent everywhere, not just for agencies.

## 3. Additional implementation decisions (flagging for review, not asking as separate questions — correct me here if wrong)

1. **Role literal:** `'Partner Agency'` (matches the existing
   `Administrator`/`Employee`/`Viewer` proper-case convention), not
   `partner_agency`.
2. **`is_active` column:** kept in the schema (never dropped, per
   CLAUDE.md), but demoted — every write path that sets `status` also
   sets `is_active = (status = 'Active')` for backward compatibility with
   any code that still reads it. All auth logic (`attempt_login`, login
   gating, `users.php`) is rewritten to read/write `status` as the source
   of truth.
3. **Agency identity is never trusted from session.** `current_user()`
   keeps returning session-cached display fields as it does today, but a
   new `current_agency_id(PDO $pdo): ?int` helper always re-reads
   `agency_id` from `users` by the session's `user_id` on every call — no
   `agency_id` is ever cached in `$_SESSION`. Every Partner Agency query
   in every page/API calls this helper fresh; nothing trusts a posted or
   GET'd `agency_id`.
4. **Agency status also gates login.** Login requires
   `users.status = 'Active'` **and**, for `Partner Agency` accounts,
   `partner_agencies.status = 'Active'` — if an admin disables the agency
   record itself (existing Active/Disabled column on `partner_agencies`,
   already used for the employment-record dropdown), its users are locked
   out too, independent of their own account status.
5. **Two distinct "delete" operations**, both required by the prompt but
   easy to conflate:
   - Deleting a **Partner Agency user account** (`users` row) is always
     safe — no FKs reference `users.id` — and doesn't touch the agency
     record or its history.
   - Deleting the **agency record** (`partner_agencies` row) reuses the
     existing dependency check in `partner-agency.php` (blocked if
     `employment_records.agency_id` references it) and additionally
     cannot succeed while any `users.agency_id` still references it —
     enforced by `ON DELETE RESTRICT` on the new FK, in addition to an
     app-level pre-check with a friendly message.
6. **Registration UI:** the login page panel switch (Login ↔ Partner
   Agency Registration) is pure client-side Alpine state — no navigation.
   The registration form itself still does a normal full-page POST back
   to the same page (like the existing login form and the current
   `signup.php` already do) — "without unnecessarily reloading" refers to
   not navigating away just to *see* the form, not to making registration
   itself AJAX/SPA.
7. **`signup.php` is retired**, not just unlinked: it now redirects to
   `login.php`. Administrators can still create `Viewer` accounts
   directly (already supported in `users.php`, created `Active`
   immediately) — leaving the old endpoint live-but-unlinked would be
   security-by-obscurity, not an actual removal of public self-service
   Viewer creation.
8. **Employment module stays out of the Partner Agency sidebar**,
   matching the prompt's own suggested sidebar (section 16), which omits
   it. A Partner Agency user still sees employment history read-only
   inside the Applicant Profile page (already has no edit/delete controls
   for non-`can_edit()` roles) — that satisfies "Restricted/View as
   allowed" from the access matrix without opening the full Employment
   module.
9. **Reports vs Applicants list have different scopes**, per the access
   matrix's literal text: the *Applicants list/profile* stays a shared
   pool (section 9 explicitly carves this out — every agency can search
   and view every applicant). *Reports* (section 8/38: "Own Agency Only")
   are scoped to applicants this agency has actually hired
   (`employment_records.agency_id = current_agency_id()`).
10. **Dashboard "Applicant Information" stats for Partner Agency** (my
    interpretation of an ambiguous part of the prompt — flag if wrong):
    "Total Registered Applicants" and "Applicants available for
    recruitment" are system-wide pool numbers (consistent with the
    shared-visibility rule in #9 above); "Applicants hired" is scoped to
    this agency's own current-active hires; "Applicants not hired" reuses
    the same not-yet-hired pool count as "available for recruitment"
    (the prompt lists them as two bullets but they describe the same
    underlying set).
11. **Partner Agency profile edits** (`My Partner Agency` page) can
    change `agency_name`, `address`, `contact_person`, `contact_no`,
    `email` (with the same duplicate-name check `partner-agency-form.php`
    already does), but never `agency_id`, `status`, or `role` — those stay
    Administrator-only, enforced server-side regardless of what the form
    posts.
12. **Schema changes go in a new migration file only** —
    `database/database.sql` and the existing
    `update_application_management.sql` are both already-shipped per
    CLAUDE.md and are not edited. A new file,
    `database/migrations/add_partner_agency_accounts.sql`, is added,
    written idempotently (`ADD COLUMN IF NOT EXISTS`, enum-widen-then-
    narrow like the existing migration does for enums) so it's safe to
    run against a fresh `database.sql` install or an already-upgraded v2
    database. README gets a one-line note that new installs now run both
    files.

## 4. Database changes (Phase 1)

New migration `database/migrations/add_partner_agency_accounts.sql`:

**`users`**
- `role` enum widened: `ENUM('Administrator','Employee','Viewer','Partner Agency')`
- `status ENUM('Pending','Active','Disabled') NOT NULL DEFAULT 'Active'`
  — backfilled from existing data: `is_active=1` → `Active`;
  `is_active=0 AND role='Viewer'` → `Pending` (matches what the current
  disabled-Viewer-signup flow actually means); `is_active=0` for any
  other role → `Disabled` (matches the only other code path that clears
  `is_active`, the admin "toggle" action in `users.php`).
- `agency_id INT UNSIGNED NULL` + `CONSTRAINT fk_users_agency FOREIGN KEY
  (agency_id) REFERENCES partner_agencies(id) ON DELETE RESTRICT`, index
  on `agency_id`. `NULL` for every existing role; enforced `NOT NULL` in
  application code (not a DB constraint, since MySQL can't conditionally
  require a column by another column's value) whenever `role = 'Partner
  Agency'`.
- `is_active` column left in place, kept in sync by every write path
  (see decision #2).

**`partner_agencies`**
- `contact_person VARCHAR(150) NOT NULL DEFAULT ''`
- `contact_no VARCHAR(20) NOT NULL DEFAULT ''`
- `email VARCHAR(150) NULL`
- (existing `agency_name`/`address`/`status` unchanged; the two seeded
  sample agencies get blank contact fields, editable later via the
  existing Partner Agency form.)

No changes to `applicants`, `employment_records`, or `audit_logs` structure
— `audit_log()` is reused as-is with new `action`/`table_name` values.

## 5. Auth changes (`includes/auth.php`)

- `attempt_login()`: query becomes `SELECT u.*, pa.status AS agency_status
  FROM users u LEFT JOIN partner_agencies pa ON pa.id = u.agency_id WHERE
  u.username = :u LIMIT 1` (drops the `is_active = 1` filter from the
  WHERE clause so the function can distinguish *why* login failed).
  Login succeeds only when `password_verify()` passes **and**
  `status = 'Active'` **and** (`role != 'Partner Agency'` OR
  `agency_status = 'Active'`). On any other case, returns `false` and the
  caller (`login.php`) queries status/role separately (as it already does
  for the `is_active` case today) to pick the right message.
- New `current_agency_id(PDO $pdo): ?int` — see decision #3.
- New `is_partner_agency(): bool` predicate, mirroring `can_edit()` style.
- `require_role()` unchanged in shape; pages simply add `'Partner Agency'`
  to their allowed-roles array where relevant.
- New `require_own_agency_record(PDO $pdo, int $recordAgencyId): void` —
  called by every Partner-Agency-scoped write/view handler; 403s if
  `$recordAgencyId !== current_agency_id($pdo)`. Centralizes the IDOR
  check (section 15/33 scenario 4) in one place instead of repeating the
  comparison inline everywhere.
- `is_last_active_admin()` unaffected (still Administrator-only, still
  reads `role`).

## 6. Public registration flow (`public/login.php`)

- Alpine `activePanel: 'login' | 'register'` toggles the right-side card
  content; the left brand panel is untouched.
- "Sign Up as a Viewer" footer link removed. New link under the login
  form: "Partner Agency Registration" → sets `activePanel = 'register'`.
- Registration form fields (all required except email format/duplicate
  checks noted): Agency Name, Address, Contact Person, Contact No, Email,
  Username, Password, Confirm Password. Password visibility toggle,
  inline validation, disabled-submit-while-processing — matching the
  existing `signup.php`/`users.php` form conventions (`validateForm()` in
  `app.js`, `data-error-for` spans).
- POST handler (`form=register_agency`) runs in a single PDO transaction:
  1. `csrf_require()`.
  2. Validate all required fields non-empty, email format
     (`is_valid_email()`), password ≥ 8 chars, password === confirm.
  3. Duplicate checks: `agency_name` (case-sensitive exact match, same
     rule as `partner-agency-form.php`), `username`, `email`.
  4. Insert `partner_agencies` row (`status = 'Active'` — the *agency
     record* itself isn't what's pending, the *account* is; an admin can
     still separately disable the agency record later via the existing
     Partner Agency page).
  5. Insert `users` row: `role = 'Partner Agency'`, `status = 'Pending'`,
     `agency_id` = the new agency's id, `password_hash()`.
  6. `audit_log($pdo, null, 'CREATE', 'partner_agencies', $agencyId,
     "Partner Agency registration: {agency_name} ({username}), pending approval")`.
  7. Commit; on any validation/duplicate failure, no writes happen (fail
     before the transaction opens). On any DB exception mid-transaction,
     rollback and show a generic error.
- Success view (still on `login.php`, no redirect): the confirmation
  message from prompt section 3, verbatim.

## 7. Administrator approval (`public/users.php`)

Reuses the existing page (same step-up re-auth gate, same layout system)
rather than a new file, since it already owns all account
CRUD/status-toggle logic and CLAUDE.md says reuse existing structures.

- Adds an Alpine tab switch: **System Users** (existing table, unchanged
  behavior) / **Partner Agency Accounts** (new table).
- Partner Agency Accounts table columns: Agency Name, Contact Person,
  Contact No, Email, Username, Registration Date, Account Status
  (Pending/Active/Disabled badge), Agency Status (Active/Disabled badge,
  from `partner_agencies.status`).
- Row actions, each a small POST form (csrf + role check
  `require_role(['Administrator'])` again inside the handler, per
  CLAUDE.md's double-enforcement rule):
  - **Activate** (`status: Pending → Active`)
  - **Disable** (`status: Active → Disabled`)
  - **Re-enable** (`status: Disabled → Active`)
  - **Delete account** — deletes the `users` row only (decision #5);
    blocked with a friendly message if it's the agency's only account and
    the agency itself still has employment history, prompting "disable
    the account instead."
  - Each action calls `audit_log()` with one of: `PARTNER_AGENCY_ACTIVATE`,
    `PARTNER_AGENCY_DISABLE`, `PARTNER_AGENCY_REENABLE`,
    `PARTNER_AGENCY_ACCOUNT_DELETE`.
- Dashboard notification (section 25): a "Pending Partner Agency
  Registrations: N" card on `dashboard.php`, Administrator-only, linking
  to `users.php` with the Partner Agency tab pre-selected
  (`?tab=partner-agencies`).

## 8. Sidebar (`includes/sidebar.php`)

Branches on `current_user()['role']`:

- `Partner Agency`: Dashboard, Applicants, My Partner Agency, Reports, My
  Account (existing `settings.php`, reused as-is), Logout. No Users, no
  Audit Logs, no Employment, no full Partner Agency management page, no
  Job Vacancies yet (Phase 2).
- All other roles: unchanged from today (Administrator additionally sees
  Users/Audit Logs, per existing `can_manage_users()`/
  `can_view_audit_logs()` checks — untouched).

## 9. Data isolation on existing modules

- **`public/applicants.php` / `applicant-view.php` / `api/applicants.php`**:
  add `'Partner Agency'` wherever `require_login()` alone already permits
  any authenticated role (no change needed — these pages don't currently
  role-restrict beyond login). `can_edit()`/`can_delete()` are **not**
  extended to include `Partner Agency`, so Edit/Delete/Add-Employment
  controls stay hidden and, more importantly, the POST handlers that gate
  on `can_edit()`/`can_delete()` reject a forged request from this role
  too (already true today since those predicates only check
  Administrator/Employee).
- **`public/dashboard.php`**: branches early — if `is_partner_agency()`,
  render a separate agency-scoped block instead of the system-wide one
  (see §3.10 for the stat definitions), and skip the system-wide
  charts/cards entirely for this role.
- **`public/reports.php`**: if `is_partner_agency()`, every report query
  gets an additional `AND er.agency_id = :agencyId` clause (bound from
  `current_agency_id($pdo)`, never from `$_GET`), and the report-type
  dropdown drops any options that don't make sense per-agency (e.g.
  `by_agency` is meaningless when already scoped to one).
- **`public/partner-agency.php` / `partner-agency-form.php`**: `Partner
  Agency` role gets **no access**. `partner-agency-form.php` already
  `require_role(['Administrator', 'Employee'])`s at the top, but
  `partner-agency.php` itself was only `require_login()`-gated with role
  checks inside individual POST actions — page *rendering* (including the
  full agency list and `?export=xlsx`) was not actually restricted. A
  composition-review fix pass added an explicit `is_partner_agency()` ->
  403 guard right after `require_login()` to close that gap. Their own
  agency info lives on the new `my-agency.php` page instead, per §10.

## 10. New page: `public/my-agency.php`

- `require_role(['Partner Agency'])`.
- Loads the agency row via `current_agency_id($pdo)` — never a GET/POST
  `id`/`agency_id` param — so there is no `?id=` to tamper with in the
  first place (closes the IDOR vector at the query-shape level, not just
  with a permission check).
- Displays: Agency Name, Address, Contact Person, Contact No, Email,
  Account Username, Account Status, Registration Date.
- Edit form (same page, POST) updates `agency_name`, `address`,
  `contact_person`, `contact_no`, `email` only — the `UPDATE` statement's
  `WHERE id = :id` uses the server-derived `current_agency_id()`, never a
  posted value, so there is nothing to manipulate. Duplicate-agency-name
  check reused from `partner-agency-form.php`. Audit action
  `PARTNER_AGENCY_PROFILE_UPDATE`.

## 11. Audit logging additions

New `action` values used with the existing `audit_log()` helper (no
schema change): `PARTNER_AGENCY_REGISTER`, `PARTNER_AGENCY_ACTIVATE`,
`PARTNER_AGENCY_DISABLE`, `PARTNER_AGENCY_REENABLE`,
`PARTNER_AGENCY_ACCOUNT_DELETE`, `PARTNER_AGENCY_PROFILE_UPDATE`. Login/
logout keep using the existing generic `LOGIN`/`LOGOUT` actions (already
recorded for every role, description text naturally includes the
username). `can_view_audit_logs()` is not extended, so `Partner Agency`
still cannot reach `audit-logs.php` (already enforced by
`require_role(['Administrator'])` there today).

## 12. Security checklist mapped to this design

- CSRF: every new POST handler (`login.php` registration, `users.php`
  new actions, `my-agency.php`) calls `csrf_require()` / emits
  `csrf_field()`.
- IDOR: `my-agency.php` never accepts an id param at all; every
  agency-scoped query anywhere else binds `current_agency_id($pdo)`
  (server-derived) rather than trusting a request value; the one
  cross-agency-URL scenario the prompt calls out explicitly
  (`vacancy-edit.php?id=<other agency>`) is Phase 2, and will reuse this
  same `require_own_agency_record()` helper.
- Password handling: `password_hash()`/`password_verify()`, unchanged
  patterns from the existing codebase.
- Transactions: registration is the one new multi-table write path and is
  wrapped in a single PDO transaction with rollback on failure.
- Double-enforced authorization: every new/modified POST handler repeats
  its role check inside the handler, not just at page top, matching the
  existing `applicant-view.php`/`partner-agency.php` pattern.

## 13. Out of scope for Phase 1 (explicitly deferred)

- `job_vacancies` table and all CRUD (sections 12–13).
- Job Vacancies sidebar entry, dashboard vacancy stats (section 11's
  "Job Vacancies" block).
- Partner Agency Summary report with Level 1/2/3 percentages
  (section 27–28).
- Applicant-to-vacancy matching (section 30 — explicitly "future-ready
  only," not implemented even in Phase 2 unless separately requested).

## 14. Files touched (Phase 1)

**New**
- `database/migrations/add_partner_agency_accounts.sql`
- `public/my-agency.php`

**Modified**
- `includes/auth.php` (login/status/agency helpers)
- `includes/functions.php` (if any small shared helper is needed, e.g. a
  duplicate-agency-name check factored out for reuse between
  `partner-agency-form.php` and `my-agency.php`)
- `includes/sidebar.php`
- `public/login.php`
- `public/signup.php` (retired → redirect)
- `public/users.php`
- `public/dashboard.php`
- `public/reports.php`
- `README.md` (note new migration file in setup steps)

## 15. Testing (manual, per CLAUDE.md — no test suite in this repo)

Maps directly to prompt section 33 scenarios 1, 2, 3 (minus vacancies),
6, 7, plus the IDOR checks in §12: register → Pending → cannot log in;
Administrator activates → can log in; dashboard/reports/applicants show
only own-agency data where scoped and full pool where shared; disabled
agency blocks login even with `status='Active'` on the user row;
`my-agency.php` never exposes another agency's data since it takes no id
param at all.
