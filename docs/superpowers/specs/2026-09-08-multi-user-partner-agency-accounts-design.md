# Multi-User Partner Agency Accounts — Design

## 1. Goal

This is the first of five sub-projects decomposed from a larger system-update
request (multi-user agencies; an Administrator applicant-registration modal
reusing the public registration fields; a second Privacy Notice step; a
Partner Agency vacancy Delete action; and an Applicant Profile print
redesign). The other four are out of scope for this spec and will each get
their own brainstorm/spec/plan cycle.

Today, a Partner Agency is exactly one login account (`agency_id` is set on
registration, one user forever). This sub-project lets a Partner Agency
self-register additional user accounts under the same agency, all sharing
equal permissions over that agency's own data, while keeping every existing
cross-agency isolation guarantee intact.

## 2. Decisions confirmed with the user

- All active users within one agency have **identical permissions** —
  mirrors how the existing system-wide `Administrator` role already works
  (any Administrator can manage any user). There is no "only the owner can
  manage users" tier.
- The **original registration account is still identified** (as `is_primary`)
  for display/audit clarity, but this flag does **not** gate any permission
  — it exists so the UI can show a "Primary" label and so history stays
  legible, not to restrict what a secondary user can do.
- The **only** special protection is a safeguard against **removing the
  agency's last active user** entirely (mirrors `is_last_active_admin()`,
  scoped per-agency instead of system-wide). An Administrator can still
  disable/delete through the existing `users.php`, unaffected by this
  safeguard (that path already has its own checks).
- New agency-created users become Active or Disabled immediately, as chosen
  in the Add User form — **no Administrator approval step**. That gate
  (`status = 'Pending'` in `attempt_login()`) exists specifically to vet a
  brand-new, never-before-seen agency at self-registration time; once an
  agency is already Active, its own users adding teammates is a fully
  self-service action.
- Per the user: all data in the live database except the `admin` account is
  temporary/disposable for this work — implementation and testing don't need
  to preserve the two existing Partner Agency test accounts if doing so gets
  in the way.

## 3. Database changes

New migration `database/migrations/add_agency_primary_user_flag.sql` (new
column only — existing shipped migrations are never edited, per this repo's
established convention):

```sql
ALTER TABLE care_jf_users
  ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER agency_id;

-- Backfill: every existing Partner Agency account today is, by definition,
-- the original/only account for its agency.
UPDATE care_jf_users SET is_primary = 1 WHERE role = 'Partner Agency';
```

No change to `agency_id` itself — it is already `INT UNSIGNED DEFAULT NULL`
with a plain (non-unique) index and an FK to `care_jf_partner_agencies(id)`,
so multiple users sharing one `agency_id` already works at the schema level.

`is_primary` is meaningless for non-`Partner Agency` roles; the backfill and
all application code only ever sets/reads it in that context.

## 4. New page: `public/agency-users.php`

`require_role(['Partner Agency'])`. Every query and mutation is scoped to
`current_agency_id($pdo)` — never a request parameter. Structure mirrors
`my-agency.php` (Partner-Agency-only, single-agency-scoped) and reuses the
non-dismissable-modal pattern already established for `users.php`'s "New
User" modal and `vacancies.php`'s "Add Vacancy" modal (fixed-overlay
`x-show`, no `@click.outside`, explicit Cancel/X only).

**Listing table:**

| Full Name | Username | Account | Status | Date Created | Actions |
|---|---|---|---|---|---|

"Account" shows a "Primary" badge for `is_primary = 1`, otherwise "Member".
Actions: Edit (inline row, matching `vacancies.php`'s inline-edit pattern),
Enable/Disable toggle, Reset Password (inline row, matching `users.php`'s
existing reset-password row pattern).

**Add User modal** (fields, in order, per the original request): Full Name,
Username, Password, Confirm Password, Status (Active/Disabled). No
`agency_id` field anywhere in the form — it is always
`current_agency_id($pdo)`, set server-side, never read from the request.

**POST actions**, all under `csrf_require()` first, all re-deriving the
target row's `agency_id` from the database before acting:

- `add` — validates (full name required; username required, unique
  system-wide exactly like the existing `users.php` create path; password
  ≥ 8 chars; confirm matches), inserts with `role = 'Partner Agency'`,
  `agency_id = current_agency_id($pdo)`, `is_primary = 0`,
  `status = $_POST['status']` (Active/Disabled only — validated against an
  allowlist). Audit: `AGENCY_USER_CREATE`.
- `edit` — full name + status only (username and password are never
  editable here; password changes go through Reset Password, matching the
  existing `users.php` convention where `edit` never touches the password
  field). Audit: `AGENCY_USER_UPDATE`.
- `toggle` (enable/disable) — blocked by `is_last_active_agency_user()` if
  this is the agency's only remaining active user; blocked from disabling
  the acting user's own account (mirrors `users.php`'s existing
  "you cannot disable your own account" rule). Audit: `AGENCY_USER_ENABLE`
  or `AGENCY_USER_DISABLE`.
- `reset_password` — new temporary password, ≥ 8 chars, hashed with
  `password_hash()`. Audit: `AGENCY_USER_PASSWORD_RESET`.

No `delete` action in this first pass — the existing `users.php` pattern
for system users only *disables*, never hard-deletes, a Partner Agency's
own login accounts either (hard-delete of a Partner Agency's very first/
last account is already blocked elsewhere for the "would strand the
agency" reason); Disable is the correct action here and keeps history
intact. (Administrators retain their own existing hard-delete path in
`users.php`, unaffected by this sub-project.)

## 5. New helper: `is_last_active_agency_user()`

In `includes/auth.php`, alongside the existing `is_last_active_admin()`:

```php
/** Safeguard: prevent removing/disabling the last remaining active user of a Partner Agency. */
function is_last_active_agency_user(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT agency_id, status FROM care_jf_users WHERE id = :id AND role = 'Partner Agency'");
    $stmt->execute([':id' => $userId]);
    $target = $stmt->fetch();
    if (!$target || $target['status'] !== 'Active' || !$target['agency_id']) {
        return false;
    }
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM care_jf_users WHERE agency_id = :aid AND role = 'Partner Agency' AND status = 'Active'"
    );
    $countStmt->execute([':aid' => $target['agency_id']]);
    return (int)$countStmt->fetchColumn() <= 1;
}
```

Same style and structure as the existing `is_last_active_admin()` right
above it, scoped by `agency_id` instead of system-wide.

## 6. Existing pages touched

**`public/users.php`** (Administrator's Manage Users, unaffected in scope
but needs one correction): the Partner Agency Accounts tab's query currently
selects `pa.contact_person` and displays it as if it were the logged-in
user's own name — correct when there was exactly one user per agency, wrong
now. Add `u.full_name` to the `SELECT`, add a "Full Name" column showing it,
and add a "Primary"/"Member" badge next to Username using `u.is_primary`.
`Contact Person` stays as its own column (it's a real, distinct
agency-level field — the agency's designated point of contact, not
necessarily any one user's name).

**`includes/sidebar.php`**: add an "Agency Users" nav link, visible only for
`is_partner_agency()`, pointing at `agency-users.php` — same visibility
pattern already used for the existing `my-agency.php` link.

**Self password-change**: no changes needed — `public/settings.php` already
works for every authenticated role including Partner Agency.

## 7. Authorization / data isolation

Every Partner-Agency-scoped query in this sub-project follows the existing,
already-audited pattern used throughout the app (`current_agency_id($pdo)`
re-derived from the session's trusted `user_id` on every request, never
trusted from `$_GET`/`$_POST`). No new authorization concept is introduced;
this sub-project extends the existing one to a second "who am I acting as"
column (`is_primary`) that never itself gates access.

## 8. Audit logging

New action constants, logged via the existing `audit_log($pdo, $userId,
$action, $table, $recordId, $description)` — no second logging mechanism:
`AGENCY_USER_CREATE`, `AGENCY_USER_UPDATE`, `AGENCY_USER_ENABLE`,
`AGENCY_USER_DISABLE`, `AGENCY_USER_PASSWORD_RESET`.

## 9. Testing

- Create a second and third user under one agency; confirm all three log in
  independently and see identical data (own agency's vacancies, applicants
  pool, reviews) — reusing the *already-verified* agency-scoping in
  `vacancies.php`, `applicant-view.php`, `api/applicants.php`, etc., since
  none of those files change in this sub-project.
- Confirm a user from Agency A cannot view or mutate Agency B's users by
  editing `agency-users.php`'s POST `id` (each mutation re-derives
  `agency_id` from the DB row being acted on and 403s on mismatch).
- Disable one of three active agency users; confirm the disabled one can no
  longer log in (reuses the existing `attempt_login()`/`is_logged_in()`
  Active-status check) and the other two still can.
- Attempt to disable the last remaining active user; confirm it's blocked
  with a clear message.
- Confirm an Administrator can still see every agency's users (now
  correctly labeled with their own full name) in `users.php`.
