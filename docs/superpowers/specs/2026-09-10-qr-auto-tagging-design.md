# QR Auto-Tagging for Partner Agencies — Design

## 1. Goal

Today, scanning or manually entering an applicant's code in the shared
QR scanner modal (`includes/qr-scanner-modal.php`) only navigates to
that applicant's profile (`applicant-view.php?code=...`) — it never
associates the applicant with the scanning Partner Agency. Associating
an applicant with an agency still requires the agency to notice and
click the separate "Tag for Review" button on the profile page.

This phase makes scanning a QR the **primary** way a Partner Agency
associates itself with an applicant, while keeping "Tag for Review" as
the secondary/manual option for when a QR code is unavailable. It is
additive to the existing application-tracking model
(`docs/superpowers/specs/2026-09-08-application-tracking-hiring-workflow-design.md`)
— no new statuses, no schema change.

## 2. Decisions confirmed with the user

- **Camera scan → auto-tag instantly, no prompt.** A successful camera
  decode immediately associates the applicant with the scanning
  agency and navigates to the profile.
- **Manual code entry → confirm first.** Typing a code into the
  modal's text field (the fallback used when camera scanning isn't
  available) shows an in-app confirm dialog ("Associate this applicant
  with your agency?") before tagging. Confirming tags and navigates;
  cancelling navigates to the profile with **no** tagging attempt
  (view only) — an explicit checkpoint, since a typed code is more
  likely to be a mistake (fat-fingered digits, wrong applicant) than a
  camera decode of an actual physical QR code.
- **Non-Partner-Agency users unaffected.** Administrator/Employee/
  Viewer accounts use the exact same modal (from the Dashboard) purely
  for lookup — never tags, regardless of camera or manual path.
- **No modal closes on outside/backdrop click.** Matches this
  codebase's existing modal convention (verified: no modal in the app
  currently binds a backdrop click to close itself — only explicit
  Cancel/X buttons and Escape do). The new manual-entry confirm dialog
  follows the same rule: dismissible only via its own Yes/Cancel
  buttons, never by clicking outside it.
- **"Tag for Review" stays, unchanged in behavior.** Refactored
  internally to share logic with the new auto-tag path, but its
  inputs, permission checks, and user-facing messages are identical to
  today.
- **Mechanism: a new CSRF-protected POST endpoint, not a GET side
  effect.** The confirm-vs-instant split requires the *client* to
  decide whether to tag before navigating, which only a JS-initiated
  call allows. This also keeps every state change behind
  `csrf_require()`, per this codebase's standing rule — nothing
  currently calls `csrf_require()` from JS, so a CSRF token has to be
  exposed to the page for the first time (a `<meta>` tag).

## 3. Data model — no changes

`care_jf_employment_records` and its `For Review` status already
represent exactly what's needed: multiple agencies can each hold an
independent `For Review` row against the same applicant, and the
existing per-agency duplicate check (one open `For Review` row per
`(applicant_id, agency_id)` pair) is already idempotent. **No
migration in this phase.**

## 4. Shared tagging function

New function in `includes/functions.php`:

```php
function tag_applicant_for_agency(
    PDO $pdo, int $applicantId, string $applicantCode,
    int $agencyId, int $actingUserId, string $source
): string
```

`$source` is `'manual'` or `'qr_scan'` — used only to pick the audit
action string and description text; the tagging logic itself is
identical either way:

1. `is_applicant_hired($pdo, $applicantId)` → return `already_hired`
   (no row inserted).
2. Agency must exist and be `status = 'Active'` in
   `care_jf_partner_agencies` → else return `agency_invalid`.
3. Existing per-agency duplicate check (`COUNT(*) ... WHERE
   applicant_id = :id AND agency_id = :agid AND employment_status =
   'For Review'`) → if found, return `duplicate`.
4. Insert the `For Review` row exactly as today's manual handler does
   (`is_current = 0`, `date_hired = NULL`, `status = 'Active'`).
5. `audit_log()` with `APPLICANT_TAGGED_FOR_REVIEW` (source = manual)
   or the new `APPLICANT_AUTO_TAGGED_QR` (source = qr_scan) action
   string, description noting the applicant code and agency name (and,
   for `qr_scan`, whether it came via camera decode or a confirmed
   manual entry). Return `created`.

**`public/applicant-view.php`'s existing `tag_for_review` POST branch
is refactored to call this function** and map its return value to the
exact same flash messages/redirects it already produces today
(`already_hired` → "This applicant has already been hired.",
`agency_invalid` → "Selected Partner Agency was not found.",
`duplicate` → "This agency has already tagged this applicant for
review.", `created` → "Applicant tagged for review."). This is a pure
refactor — no behavior change for the manual path, for either Partner
Agency users (agency forced from session) or staff (agency chosen
from a dropdown).

## 5. New endpoint: `public/api/qr-tag.php`

- `require_login(); require_role(['Partner Agency']);` — 403 for any
  other role (staff never call this; their scans are lookup-only).
- POST only, `csrf_require()`.
- Input: `code` (applicant code string, `clean()`ed).
- Looks up the applicant by `applicant_code` (`is_deleted = 0`). Not
  found → `{ok: false, status: 'not_found'}`, no flash (nothing to
  show it on — see §6).
- Found → `$agencyId = current_agency_id($pdo)` (session-derived,
  exactly like the existing manual flow — **never** taken from the
  request body) → call `tag_applicant_for_agency(..., $source =
  'qr_scan')`.
- On `created` → `flash_set('success', 'Applicant successfully
  associated with your agency.')`.
- On `duplicate` → `flash_set('success', 'Applicant is already
  associated with your agency.')` (this codebase's `flash_get()`/toast
  renderer only distinguishes `success` from everything-else-as-error —
  there is no separate "info" style — and this is not an error, so it
  reuses `success`).
- On `already_hired` → no flash (silent — the profile page's existing
  hired-visibility check governs what the agency sees next).
- On `agency_invalid` → `{ok: false, status: 'agency_invalid'}`, no
  flash (edge case: the agency's own account was disabled mid-session;
  shown inline, not via a page navigation).
- Response body: `{ok, status, applicant_id}` (id present whenever the
  applicant was found, so the client can navigate to `?id=` instead of
  re-resolving by code).

## 6. Scanner modal / JS changes

`includes/qr-scanner-modal.php` passes a new flag into the Alpine
component: `qrScanner({ isPartnerAgency: <?= is_partner_agency() ?
'true' : 'false' ?> })`. `includes/header.php` gains `<meta
name="csrf-token" content="<?= e(csrf_token()) ?>">` so JS can read it
without a new round trip.

In `app.js`'s `qrScanner()`:

- **Camera decode path** (`_scanLoop()` finds a value): if
  `isPartnerAgency`, `await` a POST to `api/qr-tag.php` with the
  decoded code and the CSRF header, then navigate to
  `applicant-view.php?id=<applicant_id>` on any response that resolved
  an applicant, or show an inline "Applicant not found." message in
  the still-open modal otherwise (no navigation). If not
  `isPartnerAgency`, behavior is unchanged (navigate straight to
  `?code=`).
- **Manual submit path** (`submitManual()`): if `isPartnerAgency`, show
  a small in-app confirm dialog (styled like this app's existing
  modals — explicit "Associate" / "Cancel" buttons, no backdrop-click
  or outside-click dismissal, only Escape/Cancel/Associate close it).
  "Associate" does the same POST-then-navigate as the camera path.
  "Cancel" navigates straight to `applicant-view.php?code=<code>` with
  no tagging call at all (view-only, matching today's behavior
  exactly). If not `isPartnerAgency`, unchanged.
- A decoded/typed value that doesn't resolve to any applicant is
  reported inline in the modal (new `lookupError` reactive property)
  rather than navigating to a blank/error page.

## 7. Security

- Agency identity for the auto-tag path is **always**
  `current_agency_id($pdo)` from the authenticated session — the POST
  body only ever carries the applicant `code`, never an `agency_id`.
  Identical guarantee to the existing manual Partner-Agency tag path.
- `qr-tag.php` is CSRF-protected like every other state-changing
  handler in this codebase; the manual "Tag for Review" POST is
  unchanged (already CSRF-protected).
- The endpoint is restricted to the `Partner Agency` role only — a
  staff/admin account hitting it directly gets a 403, since staff have
  no `agency_id` to tag with and this phase gives them no reason to
  call it.
- QR payload is unchanged (still just the applicant code — no new
  encoded data, per the existing QR design).
- Auto-tagging never sets `is_current = 1`, never touches
  `date_hired`, never writes to `care_jf_job_vacancies`, and never
  changes `employment_status` away from `For Review` — hiring stays
  exclusively a manual staff/agency action via the existing "Mark as
  Hired" flow. `already_hired` short-circuits before any write.
- Every new dynamic value rendered (the inline lookup/agency-invalid
  error text) goes through the same escaping discipline already used
  throughout the app (`e()` server-side; the JS-inserted error text is
  set via `textContent`/Alpine `x-text`, never `innerHTML`, so no new
  XSS surface).

## 8. Out of scope for this phase

Changing what a Partner Agency can see once tagged (unchanged);
changing the "Tag for Review" button's placement, visibility rules, or
staff-facing agency-picker dropdown; any Reports-module changes (a
separate sub-project); any sidebar/layout changes (a separate
sub-project); any change to the admin-side applicant creation form's
Services fields (a separate sub-project); rate-limiting the new
endpoint beyond the existing session/login throttling already in
place.

## 9. Testing

No automated test suite in this repo — manual verification via two
Partner Agency test accounts and the existing role accounts:

- **New agency, camera-style scan (simulated via direct POST since a
  real camera isn't available in this environment):** applicant not
  previously tagged by this agency → `created`, correct flash, exactly
  one new `For Review` row, `APPLICANT_AUTO_TAGGED_QR` audit entry.
- **Duplicate scan:** repeat the same POST → `duplicate`, correct
  flash, no second row inserted.
- **Manual entry, confirm accepted:** same outcome as the camera path.
- **Manual entry, confirm cancelled:** no POST fired, applicant profile
  loads via `?code=` unchanged, no new row.
- **Two different agencies, same applicant:** each gets its own `For
  Review` row; confirm via `applicant-view.php` that Agency A cannot
  see or act on Agency B's row (existing visibility filter, unchanged).
- **Already-hired applicant:** `already_hired`, no new row, no
  vacancy/employment side effects.
- **Non-Partner-Agency role hits `qr-tag.php` directly:** 403.
- **Missing/garbage code:** `not_found`, modal shows inline error,
  stays open.
- **Manual "Tag for Review" button, staff and Partner Agency:**
  unchanged behavior/messages, confirming the refactor preserved
  parity.
- Regression smoke test: Dashboard and Applicants pages still render
  the scanner modal without a PHP warning; `audit-logs.php` displays
  the new action string correctly; Print Profile / existing QR
  generation untouched.
