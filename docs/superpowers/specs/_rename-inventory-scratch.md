# Rename inventory: `applicant_system` → `care_job_fair_db`, table prefix `care_jf_`

Mapping: `applicants`→`care_jf_applicants`, `partner_agencies`→`care_jf_partner_agencies`,
`employment_records`→`care_jf_employment_records`, `users`→`care_jf_users`,
`audit_logs`→`care_jf_audit_logs`. Columns/structure unchanged.

Every fragment below is the exact current SQL string (or its containing
statement) with genuine table references only — UI text, PHP variable
names, comments using the word informally, and unrelated matches (e.g.
`user_id` column, `full_name`) are excluded.

---

### public\users.php
- L23: `SELECT password FROM users WHERE id = :id` → `FROM care_jf_users`
- L29: `audit_log($pdo, ..., 'users', $currentUserId, ...)` — 5th arg to `audit_log()` is the `table_name` value stored in `audit_logs.table_name`, a free-text label not itself a table reference in SQL, **but** it should be updated to the new table name string for consistency with the new schema's naming (`'users'` → `'care_jf_users'`) since this column records "which table this action affected." (Same applies to every other `audit_log(...)` call listed below — flagging once here, not repeating the rationale each time.)
- L88: `SELECT COUNT(*) FROM users WHERE username = :u` → `FROM care_jf_users`
- L96: `INSERT INTO users (username, password, full_name, role) VALUES (...)` → `INTO care_jf_users`
- L98: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L116: `UPDATE users SET full_name = :f, role = :r WHERE id = :id` → `UPDATE care_jf_users`
- L118: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L131: `SELECT status FROM users WHERE id = :id` → `FROM care_jf_users`
- L135: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L146: `DELETE FROM users WHERE id = :id` → `FROM care_jf_users`
- L147: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L157: `UPDATE users SET password = :p WHERE id = :id` → `UPDATE care_jf_users`
- L159: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L165: `SELECT id FROM users WHERE id = :id AND role = 'Partner Agency'` (×3, also L177, L189) → `FROM care_jf_users`
- L171: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L183: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L195: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L201: `SELECT agency_id FROM users WHERE id = :id AND role = 'Partner Agency'` → `FROM care_jf_users`
- L213: `SELECT COUNT(*) FROM users WHERE agency_id = :aid` → `FROM care_jf_users`
- L215: `SELECT COUNT(*) FROM employment_records WHERE agency_id = :aid` → `FROM care_jf_employment_records`
- L223: `DELETE FROM users WHERE id = :id` → `FROM care_jf_users`
- L224: `audit_log(..., 'users', ...)` → `'care_jf_users'`
- L232: `SELECT * FROM users WHERE role <> 'Partner Agency' ORDER BY created_at DESC` → `FROM care_jf_users`
- L233-240 (multi-line): `SELECT u.id, ... FROM users u JOIN partner_agencies pa ON pa.id = u.agency_id WHERE u.role = 'Partner Agency' ...` → `FROM care_jf_users u JOIN care_jf_partner_agencies pa ...`

### public\employment-list.php
- L28: `UPDATE employment_records SET status = :s WHERE id = :id` → `UPDATE care_jf_employment_records`
- L30: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L34: `DELETE FROM employment_records WHERE id = :id` → `FROM care_jf_employment_records`
- L36: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L82-87 (multi-line): `SELECT DISTINCT COALESCE(pa.agency_name, er.agency_company_name) ... FROM employment_records er LEFT JOIN partner_agencies pa ON pa.id = er.agency_id ...` → `FROM care_jf_employment_records er LEFT JOIN care_jf_partner_agencies pa ...`
- L89-94 (multi-line): `SELECT COUNT(*) FROM employment_records er JOIN applicants a ON a.id = er.applicant_id LEFT JOIN partner_agencies pa ON pa.id = er.agency_id WHERE $whereSql` → `FROM care_jf_employment_records er JOIN care_jf_applicants a ... LEFT JOIN care_jf_partner_agencies pa ...`
- L98-107 (multi-line): `SELECT er.*, a.applicant_code, ... FROM employment_records er JOIN applicants a ON a.id = er.applicant_id LEFT JOIN partner_agencies pa ON pa.id = er.agency_id WHERE $whereSql ORDER BY ...` → same table substitutions as above

### public\login.php
- L27-31 (multi-line): `SELECT u.status, u.role, pa.status AS agency_status FROM users u LEFT JOIN partner_agencies pa ON pa.id = u.agency_id WHERE u.username = :u LIMIT 1` → `FROM care_jf_users u LEFT JOIN care_jf_partner_agencies pa ...`
- L81: `SELECT COUNT(*) FROM users WHERE username = :u` → `FROM care_jf_users`
- L88: `SELECT COUNT(*) FROM partner_agencies WHERE email = :e` → `FROM care_jf_partner_agencies`
- L99-102 (multi-line): `INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email, status) VALUES (...)` → `INTO care_jf_partner_agencies`
- L109-112 (multi-line): `INSERT INTO users (username, password, full_name, role, status, is_active, agency_id) VALUES (...)` → `INTO care_jf_users`
- L120: `audit_log($pdo, null, 'PARTNER_AGENCY_REGISTER', 'partner_agencies', $agencyId, ...)` → `'care_jf_partner_agencies'`

### public\my-agency.php
- L10: `SELECT * FROM partner_agencies WHERE id = :id` → `FROM care_jf_partner_agencies`
- L19: `SELECT username, status, created_at FROM users WHERE id = :id` → `FROM care_jf_users`
- L49-51 (multi-line): `UPDATE partner_agencies SET agency_name = :n, ... WHERE id = :id` → `UPDATE care_jf_partner_agencies`
- L56: `audit_log(..., 'partner_agencies', ...)` → `'care_jf_partner_agencies'`

### public\partner-agency-form.php
- L13: `SELECT * FROM partner_agencies WHERE id = :id` → `FROM care_jf_partner_agencies`
- L41: `UPDATE partner_agencies SET agency_name = :n, ... WHERE id = :id` → `UPDATE care_jf_partner_agencies`
- L43: `audit_log(..., 'partner_agencies', ...)` → `'care_jf_partner_agencies'`
- L46: `INSERT INTO partner_agencies (agency_name, address, contact_person, contact_no, email) VALUES (...)` → `INTO care_jf_partner_agencies`
- L49: `audit_log(..., 'partner_agencies', ...)` → `'care_jf_partner_agencies'`

### public\reports.php
- L63-66 (multi-line, inside `$base` string): `FROM applicants a LEFT JOIN employment_records er ON er.applicant_id = a.id ... LEFT JOIN partner_agencies pa ON pa.id = er.agency_id WHERE a.is_deleted = 0` → `FROM care_jf_applicants a LEFT JOIN care_jf_employment_records er ... LEFT JOIN care_jf_partner_agencies pa ...`
  (Note: this base query is built with string concatenation, not one literal — the table names appear once in the initial `$base = "..."` heredoc-style string at L57-67; every later `$base .= "..."` appended clause references only column names, not tables, so no further table-name edits needed elsewhere in this function.)

### public\employment-form.php
- L29: `SELECT agency_name, address FROM partner_agencies WHERE id = :id` → `FROM care_jf_partner_agencies`
- L56: `UPDATE employment_records SET is_current = 0 WHERE applicant_id = :aid` → `UPDATE care_jf_employment_records`
- L61-63 (multi-line): `INSERT INTO employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status, remarks) VALUES (...)` → `INTO care_jf_employment_records`
- L70: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L74-76 (multi-line): `UPDATE employment_records SET agency_id=:agid, ... WHERE id=:id` → `UPDATE care_jf_employment_records`
- L82: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L96: `SELECT * FROM applicants WHERE id = :id AND is_deleted = 0` → `FROM care_jf_applicants`
- L106: `SELECT * FROM employment_records WHERE id = :id AND applicant_id = :aid` → `FROM care_jf_employment_records`

### public\dashboard.php
- L11: `SELECT * FROM partner_agencies WHERE id = :id` → `FROM care_jf_partner_agencies`
- L18: `SELECT COUNT(*) FROM applicants WHERE is_deleted = 0` → `FROM care_jf_applicants`
- L20-22 (multi-line): `SELECT COUNT(*) FROM applicants a LEFT JOIN employment_records er ON er.applicant_id = a.id ... WHERE a.is_deleted = 0 AND er.id IS NULL` → `FROM care_jf_applicants a LEFT JOIN care_jf_employment_records er ...`
- L27: `SELECT COUNT(*) FROM employment_records WHERE agency_id = :aid AND is_current = 1 AND status = 'Active'` → `FROM care_jf_employment_records`
- L72: `SELECT COUNT(*) FROM users WHERE role = 'Partner Agency' AND status = 'Pending'` → `FROM care_jf_users`
- L76: `SELECT COUNT(*) FROM applicants WHERE is_deleted = 0` → `FROM care_jf_applicants`
- L77: `SELECT COUNT(*) FROM applicants WHERE is_deleted = 0 AND sex='MALE'` → `FROM care_jf_applicants`
- L78: `SELECT COUNT(*) FROM applicants WHERE is_deleted = 0 AND sex='FEMALE'` → `FROM care_jf_applicants`
- L93-98 (multi-line): `SELECT a.id, er.employment_status FROM applicants a LEFT JOIN employment_records er ON er.applicant_id = a.id ... WHERE a.is_deleted = 0` → `FROM care_jf_applicants a LEFT JOIN care_jf_employment_records er ...`
- L111-114 (multi-line): `SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total FROM applicants WHERE is_deleted = 0 GROUP BY ym ...` → `FROM care_jf_applicants`
- L119-122 (multi-line): `SELECT DATE_FORMAT(date_hired, '%Y-%m') AS ym, COUNT(*) AS total FROM employment_records WHERE status = 'Active' GROUP BY ym ...` → `FROM care_jf_employment_records`

### public\applicant-view.php
- L9: `SELECT * FROM applicants WHERE id = :id AND is_deleted = 0` → `FROM care_jf_applicants`
- L24: `UPDATE applicants SET is_deleted = 1 WHERE id = :id` → `UPDATE care_jf_applicants`
- L25: `audit_log(..., 'applicants', ...)` → `'care_jf_applicants'`
- L32: `UPDATE employment_records SET status = :s WHERE id = :id AND applicant_id = :aid` → `UPDATE care_jf_employment_records`
- L34: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L40: `DELETE FROM employment_records WHERE id = :id AND applicant_id = :aid` → `FROM care_jf_employment_records`
- L42: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L65: `SELECT agency_name, address FROM partner_agencies WHERE id = :id` → `FROM care_jf_partner_agencies`
- L76: `SELECT COUNT(*) FROM employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'` → `FROM care_jf_employment_records`
- L85-86 (multi-line): `INSERT INTO employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status) VALUES (...)` → `INTO care_jf_employment_records`
- L93: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L101-105 (multi-line): `SELECT er.*, COALESCE(pa.agency_name, er.agency_company_name) AS agency_display_name, pa.contact_person ..., pa.contact_no ..., pa.email ... FROM employment_records er LEFT JOIN partner_agencies pa ON pa.id = er.agency_id WHERE er.applicant_id = :id ORDER BY ...` → `FROM care_jf_employment_records er LEFT JOIN care_jf_partner_agencies pa ...`
- L115: `SELECT agency_name FROM partner_agencies WHERE id = :id` → `FROM care_jf_partner_agencies`
- (`current_employment_status($pdo, $id)` and `active_agencies($pdo)` calls at L110/L112 are function calls into `includes/functions.php` — their internal SQL is covered under that file's own entry below, not duplicated here.)

### public\applicants.php
- No genuine SQL table references — this file is pure view/template markup (Alpine.js table + filters); all data comes from `api/applicants.php` via `fetch()`. Only incidental matches: the page title "Registered Applicants", labels like "Applicant ID", JS variable/function names (`applicantTable`, `row.employment_status`), and the `statusColor()` map — none are SQL.

### public\applicant-edit.php
- L9: `SELECT * FROM applicants WHERE id = :id AND is_deleted = 0` → `FROM care_jf_applicants`
- L19: `SELECT * FROM employment_records WHERE applicant_id = :id AND is_current = 1 ORDER BY date_hired DESC LIMIT 1` → `FROM care_jf_employment_records`
- L101-104 (multi-line): `SELECT COUNT(*) FROM applicants WHERE is_deleted = 0 AND id <> :id AND last_name = :ln AND first_name = :fn AND date_of_birth = :dob` → `FROM care_jf_applicants`
- L114-119 (multi-line): `UPDATE applicants SET last_name=:ln, ... WHERE id=:id` → `UPDATE care_jf_applicants`
- L141: `SELECT agency_name, address FROM partner_agencies WHERE id = :id` → `FROM care_jf_partner_agencies`
- L149: `UPDATE employment_records SET is_current = 0 WHERE applicant_id = :aid` → `UPDATE care_jf_employment_records`
- L152-154 (multi-line): `UPDATE employment_records SET agency_id=:agid, ... WHERE id=:rid` → `UPDATE care_jf_employment_records`
- L159: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L161-163 (multi-line): `INSERT INTO employment_records (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status) VALUES (...)` → `INTO care_jf_employment_records`
- L169: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L173-174 (multi-line): `UPDATE employment_records SET is_current = 0 WHERE id = :rid` → `UPDATE care_jf_employment_records`
- L175: `audit_log(..., 'employment_records', ...)` → `'care_jf_employment_records'`
- L178: `audit_log(..., 'applicants', ...)` → `'care_jf_applicants'`
- (`active_agencies($pdo, ...)` call at L190 — covered under `includes/functions.php`.)

### public\applicant-create.php
- L50-53 (multi-line): `SELECT COUNT(*) FROM applicants WHERE is_deleted = 0 AND last_name = :ln AND first_name = :fn AND date_of_birth = :dob` → `FROM care_jf_applicants`
- L64-68 (multi-line): `INSERT INTO applicants (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth, contact_number, address, civil_status) VALUES (...)` → `INTO care_jf_applicants`
- L84: `audit_log(..., 'applicants', ...)` → `'care_jf_applicants'`

### includes\functions.php
- L108: `INSERT INTO audit_logs (user_id, action, table_name, record_id, description, ip_address) VALUES (...)` (inside `audit_log()`) → `INTO care_jf_audit_logs`
- L129-131 (multi-line, inside `current_employment_status()`): `SELECT employment_status FROM employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active' ORDER BY date_hired DESC LIMIT 1` → `FROM care_jf_employment_records`
- L156-158 (multi-line, inside `is_applicant_hired()`): `SELECT COUNT(*) FROM employment_records WHERE applicant_id = :id AND is_current = 1 AND status = 'Active'` → `FROM care_jf_employment_records`
- L71-73 (multi-line, inside `generate_applicant_code()`): `SELECT applicant_code FROM applicants WHERE applicant_code LIKE :prefix ORDER BY applicant_code DESC LIMIT 1` → `FROM care_jf_applicants`
- L175-179 (multi-line, inside `active_agencies()`, `$includeId` branch): `SELECT id, agency_name, status FROM partner_agencies WHERE status = 'Active' OR id = :id ORDER BY agency_name` → `FROM care_jf_partner_agencies`
- L182 (inside `active_agencies()`, else branch): `SELECT id, agency_name, status FROM partner_agencies WHERE status = 'Active' ORDER BY agency_name` → `FROM care_jf_partner_agencies`
- L190: `SELECT COUNT(*) FROM partner_agencies WHERE agency_name = :n AND id <> :id` (inside `agency_name_taken()`) → `FROM care_jf_partner_agencies`

### public\signup.php
- No genuine SQL — file is a 9-line redirect stub (`redirect('login.php')`), no `$pdo` usage at all.

### public\partner-agency.php
- L25: `UPDATE partner_agencies SET status = :s WHERE id = :id` → `UPDATE care_jf_partner_agencies`
- L26: `audit_log(..., 'partner_agencies', ...)` → `'care_jf_partner_agencies'`
- L30: `SELECT COUNT(*) FROM employment_records WHERE agency_id = :id` → `FROM care_jf_employment_records`
- L32: `SELECT COUNT(*) FROM users WHERE agency_id = :id` → `FROM care_jf_users`
- L39: `DELETE FROM partner_agencies WHERE id = :id` → `FROM care_jf_partner_agencies`
- L40: `audit_log(..., 'partner_agencies', ...)` → `'care_jf_partner_agencies'`
- L62-63 (multi-line): `SELECT pa.*, (SELECT COUNT(*) FROM employment_records er WHERE er.agency_id = pa.id) AS record_count FROM partner_agencies pa $whereSql ORDER BY agency_name` → `(SELECT COUNT(*) FROM care_jf_employment_records er ...) ... FROM care_jf_partner_agencies pa ...` (note: this has a table reference in BOTH the outer `FROM` and an inner correlated subquery `FROM` — both need updating)

### includes\sidebar.php
- No genuine SQL — pure PHP array building (nav items) + HTML. `$currentPage`, `$navItems`, `$user['full_name']`/`$user['role']` are session-array reads via `current_user()`, not direct SQL.

### includes\auth.php
- L143: `SELECT agency_id FROM users WHERE id = :id` (inside `current_agency_id()`) → `FROM care_jf_users`
- L173: `SELECT role, is_active FROM users WHERE id = :id` (inside `is_last_active_admin()`) → `FROM care_jf_users`
- L179: `SELECT COUNT(*) FROM users WHERE role = 'Administrator' AND is_active = 1` (inside `is_last_active_admin()`) → `FROM care_jf_users`
- L166: `UPDATE users SET status = :s, is_active = :a WHERE id = :id` (inside `set_user_status()`) → `UPDATE care_jf_users`
- L196-200 (multi-line, inside `attempt_login()`): `SELECT u.*, pa.status AS agency_status FROM users u LEFT JOIN partner_agencies pa ON pa.id = u.agency_id WHERE u.username = :u LIMIT 1` → `FROM care_jf_users u LEFT JOIN care_jf_partner_agencies pa ...`
- L222: `audit_log($pdo, $user['id'], 'LOGIN', 'users', $user['id'], ...)` (inside `attempt_login()`) → `'care_jf_users'`
- L239: `audit_log($pdo, ..., 'LOGOUT', 'users', ..., ...)` (inside `do_logout()`) → `'care_jf_users'`

### public\register-applicant.php
- L70-76 (multi-line): `SELECT COUNT(*) FROM applicants WHERE is_deleted = 0 AND last_name = :ln AND first_name = :fn AND created_at >= (NOW() - INTERVAL 1 MONTH)` → `FROM care_jf_applicants`
- L86-93 (multi-line): `INSERT INTO applicants (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth, place_of_birth, contact_number, email_address, address, civil_status, source, service_job_seeker, service_agency_services) VALUES (...)` → `INTO care_jf_applicants`
- L118: `audit_log($pdo, null, 'CREATE', 'applicants', $newId, ...)` → `'care_jf_applicants'`
- (Uses `generate_applicant_code($pdo)` at L85 — covered under `includes/functions.php`.)

### public\api\applicants.php
Confirmed unchanged since original session read (not re-read this fork turn — flagging so the plan author re-verifies line numbers against the live file before writing exact find/replace blocks, since this fork did not re-open it). From the earlier read in this session:
- The `WHERE`/`HAVING`/`ORDER BY` construction references `a.` (applicants) and `er.` (employment_records) aliases throughout via a query shaped like:
  `SELECT SQL_CALC_FOUND_ROWS a.id, ... , er.employment_status AS current_status FROM applicants a LEFT JOIN employment_records er ON er.applicant_id = a.id AND er.is_current = 1 AND er.status = 'Active' WHERE $whereSql $havingSql ORDER BY a.$sortCol $sortDir LIMIT :limit OFFSET :offset`
  → `FROM care_jf_applicants a LEFT JOIN care_jf_employment_records er ...`
  This is the ONE place both table names appear (in the `FROM`/`JOIN` clause of the single main query) — no other table-name references in this file (the rest is PHP array-building of the JSON response, and a `PDO::FETCH_COLUMN`-shaped `applicant_code`/`sex`/etc. column list, none of which are table names).
- **Action item for the plan author:** re-Read this file fresh before writing its task (not done in this inventory pass) to get exact current line numbers, since the file wasn't re-opened in this research pass.

### public\audit-logs.php
- L9: `SELECT COUNT(*) FROM audit_logs` → `FROM care_jf_audit_logs`
- L10-13 (multi-line): `SELECT al.*, u.full_name, u.username FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT :limit OFFSET :offset` → `FROM care_jf_audit_logs al LEFT JOIN care_jf_users u ...`

### public\settings.php
Confirmed unchanged since original session read. From that read:
- `SELECT * FROM users WHERE id = :id` → `FROM care_jf_users`
- `UPDATE users SET password = :p WHERE id = :id` → `UPDATE care_jf_users`
- `audit_log($pdo, ..., 'users', ..., 'Changed own password')` → `'care_jf_users'`
**Action item:** re-Read this file fresh before writing its task to confirm exact current line numbers (not re-opened in this pass).

### public\employment.php — DEAD/ORPHANED CODE, confirmed unreachable
- `require_role(['Administrator', 'Staff'])` — `'Staff'` is not a valid role anywhere else in this system (roles are `Administrator`/`Employee`/`Viewer`/`Partner Agency`); this file predates the Staff→Employee rename.
- Confirmed via `grep -rn "employment.php"` across the whole repo: the ONLY match is this file's own `<form action="employment.php">` self-reference (line 111 in the version read). **No other file — not `sidebar.php`, not any `href=`/`redirect()` call anywhere — links to `employment.php`.** It is reachable only by an Administrator manually typing the URL.
- It uses old/incompatible enum values (`'Contract of Service'`, `'Not Yet Hired'`) that the `employment_status` ENUM no longer contains after the v1→v2 migration — so its own `add`/`edit` actions would already fail validation-then-insert against the current schema (the `in_array($status, $statusOptions, true)` check would pass for its own stale list, but the subsequent INSERT/UPDATE against the real narrowed ENUM would throw a data-truncation error for any of the 3 stale-only values still in its `$statusOptions`).
- **Recommendation for the plan: exclude this file from the rename entirely** (leave its old `applicants`/`employment_records` table references as-is) OR delete it outright as unrelated dead-code cleanup — flag to the user/plan-author as a decision point rather than silently doing either. Do not spend a task's SQL-rename effort on a file nothing reaches.

---

## Database name — every place `applicant_system` appears

- `config\database.php`: `private const DB_NAME = 'applicant_system';` → `'care_job_fair_db'`
- `database\database.sql`: `CREATE DATABASE IF NOT EXISTS applicant_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` and `USE applicant_system;` (2 occurrences) → both → `care_job_fair_db`
- `database\migrations\update_application_management.sql`: `USE applicant_system;` (1 occurrence, near top) → `care_job_fair_db`
- `database\migrations\add_partner_agency_accounts.sql`: `USE applicant_system;` (L18) → `care_job_fair_db`; also the header-comment usage example `mysql -u root -p applicant_system < ...` (L15, prose, update for consistency but not functionally required)
- `database\migrations\add_hired_status_and_uppercase_backfill.sql`: `USE applicant_system;` (L18) → `care_job_fair_db`; header-comment usage example (L15)
- `README.md`: 5 occurrences — L19 (`mysql -u root -p < database/database.sql` — this line doesn't name the DB, no change), L20/21/27/28/29 (`mysql -u root -p applicant_system < ...`, ×5 total across the fresh-install and upgrade blocks) → all → `care_job_fair_db`; L40 (`private const DB_NAME = 'applicant_system';` shown as a config example) → `'care_job_fair_db'`

---

## Full CREATE TABLE / schema-level table names (database.sql)

From the original full read earlier this session (not re-opened this pass —
plan author should re-Read fresh before writing exact line-numbered edits,
since line numbers may have shifted slightly if anything else changed this
file — unlikely, but confirm):

- `CREATE TABLE users (...)` → `CREATE TABLE care_jf_users (...)`
- `INSERT INTO users (username, password, full_name, role) VALUES (...)` (seeded admin) → `INTO care_jf_users`
- `CREATE TABLE partner_agencies (...)` → `CREATE TABLE care_jf_partner_agencies (...)`
- `INSERT INTO partner_agencies (agency_name, address, status) VALUES (...)` (2 seeded rows) → `INTO care_jf_partner_agencies`
- `CREATE TABLE applicants (...)` → `CREATE TABLE care_jf_applicants (...)`
- `INSERT INTO applicants (...)` (2 seeded rows) → `INTO care_jf_applicants`
- `CREATE TABLE employment_records (applicant_id ..., agency_id ..., CONSTRAINT fk_employment_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE, CONSTRAINT fk_employment_agency FOREIGN KEY (agency_id) REFERENCES partner_agencies(id) ON DELETE SET NULL, ...)` → table itself `CREATE TABLE care_jf_employment_records (...)`; both `REFERENCES` clauses need their target table renamed too: `REFERENCES care_jf_applicants(id)` and `REFERENCES care_jf_partner_agencies(id)`. FK constraint *names* (`fk_employment_applicant`, `fk_employment_agency`) don't have to change (they're arbitrary identifiers, not table references) but consider renaming them too for consistency (e.g. `fk_care_jf_employment_applicant`) — flag as a style choice, not a functional requirement.
- `INSERT INTO employment_records (applicant_id, agency_id, ...) VALUES (...)` (2 seeded rows) → `INTO care_jf_employment_records`
- `CREATE TABLE audit_logs (user_id ..., CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL, ...)` → `CREATE TABLE care_jf_audit_logs (...)`; `REFERENCES users(id)` → `REFERENCES care_jf_users(id)`

## `update_application_management.sql` (existing migration — CLAUDE.md says do not edit this file)

This file ALTERs `users`, `partner_agencies` (`CREATE TABLE IF NOT EXISTS`), `applicants`, and `employment_records` by their pre-rename names throughout (it's a historical v1→v2 migration). **This is the crux of the rename-strategy decision the plan author must make explicitly** — see the two options below; this inventory does not resolve it, only surfaces it:
- Option A: leave this file untouched (per CLAUDE.md's "never edit shipped migrations") and have a NEW rename migration run strictly after it (and after `add_partner_agency_accounts.sql` and `add_hired_status_and_uppercase_backfill.sql`, which also reference old names throughout) — i.e. the install order becomes `database.sql` (old names) → 3 existing migrations (old names) → new rename migration (does the actual `RENAME TABLE`/database move). `database.sql` itself would also need updating in this option only for the DB-name-level `CREATE DATABASE`/`USE` lines if the intent is for a *fresh* install to land directly on the new DB name before the old-named migrations run against it (a fresh install would create `care_job_fair_db` empty, then need the 3 old migrations run against tables that don't exist yet under old names inside that DB — this doesn't work cleanly; more likely a fresh install should just run `database.sql` (as-is, old table names) into a DB still named `applicant_system`, then run all 4 migrations in order ending with the rename).
- Option B: treat this rename as superseding the incremental-migration convention (since the user is explicitly directing a full rename) and rewrite `database.sql` directly to the final state (new DB name + new table names + the union of everything all 3 migrations already did), making it the new single-step fresh-install baseline; keep a rename+migrate path only for upgrading an *existing* live database that already has the old names.

Both options are viable; this is a judgment call for the plan/spec step, not something to decide unilaterally in this inventory.

## `add_partner_agency_accounts.sql` — table/DB references
Every `ALTER TABLE partner_agencies`, `ALTER TABLE users`, and the `USE applicant_system;` line shown in the Read output above.

## `add_hired_status_and_uppercase_backfill.sql` — table/DB references
Every `ALTER TABLE employment_records`, `UPDATE applicants`, `UPDATE partner_agencies`, `UPDATE users`, and the `USE applicant_system;` line shown in the Read output above.

---

## Summary counts

- **PHP files with genuine SQL table references requiring changes: 20** of the 22 checked (`public/applicants.php` and `public/signup.php` have none — pure view/redirect).
- **Individual SQL-statement-level reference points across those 20 PHP files: ~95** (counting each distinct `SELECT`/`INSERT`/`UPDATE`/`DELETE`/`audit_log()` table-name occurrence listed above, including repeated near-identical statements in different branches of the same file).
- **SQL/migration/config/README files needing the database-name string changed: 6** (`config/database.php`, `database/database.sql`, all 3 migration files, `README.md`), plus the full `database.sql` table-DDL rename (5 `CREATE TABLE` + all seed `INSERT`s + 2 `REFERENCES` clauses inside FK constraints).
- **`public/employment.php` is confirmed dead/unreachable code** — no other file links to it, and it already can't fully function against the current schema (stale ENUM values, stale `'Staff'` role check). Recommend excluding it from the rename or deleting it — flagged as a decision point, not resolved here.
- **Two files were not re-read fresh this pass** (`public/api/applicants.php`, `public/settings.php`) — their table-reference summary above is reconstructed from an earlier read this session and should be re-verified with a fresh Read before the plan author writes exact line-numbered find/replace blocks for them.
