# Applicant QR Code — Design (Phase 2 of 3)

## 1. Goal

Phase 2 of a 3-phase effort (Job Vacancies + Employer ID was Phase 1,
merged; the For-Review→Hired application-tracking redesign is Phase 3).
Phase 2 adds an auto-generated QR code for every applicant — shown at
registration and on the applicant profile — plus a camera/manual QR
lookup on the Dashboard and Applicants pages so staff can pull up an
applicant's profile instantly at a job fair.

## 2. Decisions confirmed with the user

- **Scan behavior:** a successful scan or manual code entry navigates
  straight to the applicant's profile (`applicant-view.php`) — not a
  search-box fill-and-review step.
- **Camera vs. manual entry:** the scan modal always shows a manual
  code-entry text input. A camera button/video preview is offered only
  when `getUserMedia` is actually available in a secure context (HTTPS
  or `localhost`) — camera is a bonus, never a hard dependency, since a
  typical XAMPP-on-LAN deployment serves plain HTTP.
- **Where QR codes are shown:** the public registration success popup,
  the applicant profile page (included in Print Profile, plus a
  standalone Download QR button), and — via the profile page — internal
  registration too (see §4). Explicitly **not** a QR thumbnail per row
  in the paginated Applicants list (rendering cost for no clear benefit;
  the scan/lookup feature on that page already covers "find someone
  fast").
- **Download QR:** yes, a small standalone "Download QR (PNG)" button
  on the applicant profile (and the registration success popup), in
  addition to Print Profile already including the QR.
- **QR content:** the plain applicant code string only (e.g.
  `APP-202609-000001`) — never a URL, never any other applicant field.
  A generic phone camera scanning it shows only that text; the in-app
  scanner is what makes it actionable. This is a hard requirement, not
  a default: the QR must never encode name, contact info, or any other
  personal data.
- **Libraries:** two small, dependency-free JS libraries, vendored
  locally under `public/assets/vendor/` (no CDN, matching this
  codebase's existing convention for Alpine/Chart.js/FontAwesome):
  `qrcode-generator` (kazuhikoarase) for generation into a `<canvas>`,
  and `jsQR` (cozmo) for decoding QR codes from camera video frames.

## 3. QR generation (client-side only — no new DB columns, no migration)

Every place a QR is shown, it's generated fresh in the browser from the
applicant code already present in the page's HTML/JS — never stored,
never rendered server-side, never round-tripped to an API. This keeps
Phase 2 entirely additive: no schema change, no new server-side
generation logic, nothing to keep in sync if an applicant code were
ever displayed from a cached/stale source.

A small shared JS helper (added to `public/assets/js/app.js`, colocated
with the file's existing Alpine components) wraps the vendored
`qrcode-generator` library: given a DOM element and a code string,
render a QR into a `<canvas>` sized large enough to be reliably
re-scanned (both on-screen and when printed).

## 4. Where QR codes appear

1. **Public registration success popup** (`register-applicant.php`) —
   the existing "Registration Successful" overlay, which already shows
   the new Applicant ID in a highlighted box, gains the QR rendered
   next to/below that box. A "Download QR (PNG)" button sits alongside
   it (see §6).
2. **Applicant profile** (`applicant-view.php`) — the QR renders near
   the page header (next to the Applicant ID line). It is **not**
   wrapped in the page's existing `print:hidden` convention, so it's
   included automatically when staff use the existing "Print Profile"
   button. A "Download QR (PNG)" button sits next to it, hidden on
   print like the other action buttons already are.
3. **Internal registration** (`applicant-create.php`) — no new UI
   needed here. This page already redirects straight to
   `applicant-view.php?id=<new id>` on successful save (existing
   behavior, unchanged), so the profile page's QR (item 2) covers the
   "Admin/Employee registers someone and needs their QR" case for free.
4. **Applicants list** (`applicants.php`) — no per-row QR. This page
   instead gains the scan/lookup modal described in §5.

## 5. QR search/scan (Dashboard + Applicants page)

A single reusable "Scan / Look Up Applicant" button + modal, backed by
one Alpine component (`qrScanner()`, added to `app.js`) and one shared
markup partial (`includes/qr-scanner-modal.php`), included once each on:
- `dashboard.php` — both the Administrator/Employee/Viewer dashboard and
  the Partner Agency "My Agency" dashboard branch at the top of the same
  file (Partner Agency already views the shared applicant pool, so this
  is consistent with their existing access).
- `applicants.php` — placed near the existing search/filter bar.

**Modal contents:**
- A manual text input (always present, always usable) — type or paste
  an applicant code, submit (Enter or a button) to navigate.
- A camera button that appears only when `navigator.mediaDevices &&
  navigator.mediaDevices.getUserMedia` exists. Clicking it requests
  camera permission, starts a `<video>` preview, and runs `jsQR`
  against captured frames on an interval/`requestAnimationFrame` loop
  until a QR is decoded or the modal is closed. Decoding stops and the
  camera stream is released (`track.stop()`) the moment the modal
  closes or a code is found — no camera access lingers in the
  background.
- On a decoded value (from either path): `window.location.href =
  'applicant-view.php?code=' + encodeURIComponent(value)`. No
  client-side validation of the code's shape beyond non-empty — the
  server-side lookup (§6) is the single source of truth for "does this
  applicant exist," so a mistyped or garbage code just produces the
  existing "Applicant not found" flash + redirect, not a separate
  client-side error path to keep in sync.

## 6. Server-side lookup: `applicant-view.php` gains `?code=`

Currently `applicant-view.php` only accepts `?id=` (int). It's extended
to also accept `?code=` (string) as an alternate lookup key:

- If `id` is present and non-zero, resolve by id (existing behavior,
  unchanged).
- Else if `code` is present and non-empty, resolve via
  `WHERE applicant_code = :code AND is_deleted = 0` instead of the
  `WHERE id = :id AND is_deleted = 0` this page already runs.
- Not-found handling is identical either way — the page's existing
  `if (!$applicant) { flash_set('error', 'Applicant not found.');
  redirect('applicants.php'); }` block covers both paths unchanged.
- Viewing permission is unchanged: this page already only requires
  `require_login()` (no role restriction on viewing), so every
  currently-supported role — Administrator, Employee, Viewer, Partner
  Agency — can use the scanner exactly as they can already browse to a
  profile by clicking a row in Applicants today.
- No new API endpoint. This is a deliberately small, additive change to
  one existing query's `WHERE` clause and its bound parameters — every
  other line of `applicant-view.php` (POST handlers, employment
  history, Mark as Hired) is untouched by this phase.

## 7. Download QR (PNG)

Pure client-side: the same `<canvas>` element the QR is rendered into
is read via `canvas.toDataURL('image/png')`, assigned to a synthetic
`<a download="<applicant_code>-qr.png" href="...">` element, and
`.click()`ed. No server request, no new file storage, no temp files to
clean up.

## 8. Security

- The QR never encodes anything beyond the applicant code — no name,
  contact info, address, or any other field, on any of the three
  display surfaces in §4. This is verified in testing (§10) by
  decoding a generated QR back to plain text and asserting it equals
  exactly the applicant code, nothing appended or prepended.
- Camera access is strictly opt-in per the browser's own permission
  prompt — nothing scans without the user first clicking the camera
  button and granting permission. Closing the modal or navigating away
  always stops the active camera stream.
- The new `?code=` lookup path reuses this codebase's existing
  discipline: a bound PDO parameter (never string-concatenated SQL) and
  the same `is_deleted = 0` filter the `?id=` path already applies —
  functionally identical exposure to the existing lookup, not a new
  attack surface.
- Every dynamic value this phase adds to the DOM (the applicant code
  rendered into the QR canvas's accessible label/alt text, any error
  text in the scan modal) goes through `e()` wherever it's echoed from
  PHP, per this codebase's standing convention.

## 9. Out of scope for Phase 2

Per-row QR thumbnails in the Applicants list; encoding anything beyond
the applicant code into the QR; server-side QR image generation/caching
or any new DB column/migration for QR data; changes to the Applicants
list's existing text search (the scan modal is additive, not a
replacement); anything from Phase 3 (application tracking, the For
Review→Hired redesign, Manage Users Partner Agency updates).

## 10. Testing

No automated test suite in this repo (CLAUDE.md convention) — same
`php -l` + MySQL CLI + curl/browser verification approach as Phase 1,
plus browser-level checks specific to this phase since it's
JS/camera-heavy:

- **QR round-trip:** for at least one applicant code, generate the QR
  client-side and decode it back (e.g. with the same vendored `jsQR`
  library or an external decoder) to confirm the payload is exactly
  the applicant code string — no extra characters, no URL wrapping.
- **`?code=` lookup:** curl `applicant-view.php?code=<existing code>`
  as a logged-in session of each role (Administrator, Employee, Viewer,
  Partner Agency) and confirm it resolves to the same applicant as
  `?id=`; confirm a nonexistent/garbage code produces the existing
  "Applicant not found" flash + redirect, not a fatal error.
- **Manual-entry fallback works with no camera permission granted** —
  the modal must be fully usable (type a code, submit, navigate) even
  if the camera button is never clicked or permission is denied.
- **Camera stream cleanup:** opening the scanner, granting camera
  permission, then closing the modal without scanning must stop the
  video track (checkable via the browser's own camera-in-use indicator
  or `track.readyState`).
- **Print Profile still works** and now includes the QR at a
  legible/re-scannable size; the Download QR button is excluded from
  print output (consistent with this page's other `print:hidden`
  action buttons).
- Regression smoke test across the same admin-accessible page list
  Phase 1 used (dashboard, applicants, reports, partner-agency, users,
  employment-list, audit-logs, settings, vacancies), now also covering
  `applicant-view.php?code=...` and confirming the scan modal's markup
  renders without a PHP warning/fatal on both Dashboard and Applicants.
