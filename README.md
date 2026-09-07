# CARE — Candidate Application & Registration for Employment

A PHP 8 + MySQL/MariaDB + Tailwind CSS administrative system for public job
applicant registration, applicant/employment tracking, partner agency
management, and role-based user administration.

## Requirements

- Apache (or Nginx) with PHP **8.0+** and the PDO MySQL extension
- MySQL 8+ or MariaDB 10.4+
- Node.js + npm **only if you want to rebuild the CSS/vendor assets** — the
  built assets are already included under `public/assets/`, so this is
  optional unless you change Tailwind classes or upgrade a library.

## 1. Install or upgrade the database

**New installation:**
```bash
mysql -u root -p < database/database.sql
mysql -u root -p applicant_system < database/migrations/add_partner_agency_accounts.sql
mysql -u root -p applicant_system < database/migrations/add_hired_status_and_uppercase_backfill.sql
mysql -u root -p < database/migrations/rename_database_and_tables.sql
```

**Upgrading an existing v1 installation** (preserves all data — do NOT run
`database.sql` over an existing database):
```bash
mysql -u root -p applicant_system < database/migrations/update_application_management.sql
mysql -u root -p applicant_system < database/migrations/add_partner_agency_accounts.sql
mysql -u root -p applicant_system < database/migrations/add_hired_status_and_uppercase_backfill.sql
mysql -u root -p < database/migrations/rename_database_and_tables.sql
```

(Once you've confirmed the application works correctly, the now-empty
`applicant_system` database can be removed with `DROP DATABASE
applicant_system;`.)

Default administrator account: `admin` / `Admin@123` — **change this
immediately** via Settings after first login.

## 2. Configure the database connection

Edit `config/database.php`:
```php
private const DB_HOST = 'localhost';
private const DB_NAME = 'care_job_fair_db';
private const DB_USER = 'root';
private const DB_PASS = '';
```

## 3. Point your web server document root at `/public`

Everything the browser needs — pages, CSS, JS, Font Awesome, Alpine.js,
Chart.js, and the `api/` endpoint — now lives under `public/`, so this is
the only folder that needs to be web-accessible.

```apache
<VirtualHost *:80>
    ServerName applicants.local
    DocumentRoot /var/www/applicant-system/public
    <Directory /var/www/applicant-system/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## 4. (Optional) Rebuild frontend assets

The compiled CSS and vendored JS libraries are committed under
`public/assets/`, so most deployments can skip this step entirely. Rebuild
only if you change Tailwind usage or want to update a vendored library:

```bash
npm install
npm run build          # rebuilds public/assets/css/app.build.css
```

To refresh the vendored copies of Font Awesome / Alpine.js / Chart.js after
`npm install`:
```bash
cp node_modules/@fortawesome/fontawesome-free/css/all.min.css public/assets/vendor/fontawesome/all.min.css
cp -r node_modules/@fortawesome/fontawesome-free/webfonts public/assets/vendor/fontawesome/webfonts
cp node_modules/alpinejs/dist/cdn.min.js public/assets/vendor/alpine/alpine.min.js
cp node_modules/chart.js/dist/chart.umd.js public/assets/vendor/chart/chart.min.js
```

## 5. Enable HTTPS in production

Once served over HTTPS, uncomment in `includes/auth.php`:
```php
ini_set('session.cookie_secure', '1');
```

## 6. Log in

- **Staff/Admin:** `/login.php` → "Employee / Admin Login"
- **Public applicant registration:** `/register-applicant.php` (no account needed)
- **Public Viewer sign-up:** `/signup.php` (creates a disabled account pending administrator approval)

---

## Project structure

```
/applicant-system
├── config/database.php
├── database/
│   ├── database.sql                                             Fresh-install schema (v2)
│   └── migrations/
│       ├── update_application_management.sql                    Non-destructive v1→v2 upgrade
│       ├── add_partner_agency_accounts.sql                       Adds partner agency accounts
│       ├── add_hired_status_and_uppercase_backfill.sql           Adds hired status + uppercase backfill
│       └── rename_database_and_tables.sql                        Renames DB to care_job_fair_db, prefixes tables with care_jf_
├── includes/            auth.php, csrf.php, functions.php, header.php, footer.php, sidebar.php
├── public/               Web-accessible root
│   ├── login.php                  Landing hub: Login / Register Applicant / Sign Up
│   ├── register-applicant.php     Public self-registration
│   ├── signup.php                 Public Viewer sign-up (pending approval)
│   ├── dashboard.php              Summary cards + charts (incl. Applicant Status pie)
│   ├── applicants.php             Searchable/filterable list (AJAX)
│   ├── applicant-create.php / applicant-edit.php / applicant-view.php
│   ├── employment-list.php        Employment module home (search/filter all records)
│   ├── employment-form.php        Add/edit a single employment record
│   ├── partner-agency.php / partner-agency-form.php
│   ├── reports.php, users.php, audit-logs.php, settings.php
│   ├── api/applicants.php         JSON endpoint for live search/pagination
│   └── assets/                    Built CSS + vendored Font Awesome/Alpine/Chart.js
├── resources/css/input.css        Tailwind source (compiled to public/assets/css/app.build.css)
├── tailwind.config.js
└── package.json
```

## Roles

| Capability                          | Administrator | Employee | Viewer |
|--------------------------------------|:---:|:---:|:---:|
| View applicants / reports            | ✅ | ✅ | ✅ |
| Register / edit applicants           | ✅ | ✅ | ❌ |
| Delete applicants                    | ✅ | ❌ | ❌ |
| Add / edit / enable / disable employment | ✅ | ✅ | ❌ |
| Permanently delete employment records| ✅ | ❌ | ❌ |
| Add / edit / enable / disable Partner Agency | ✅ | ✅ | ❌ |
| Permanently delete Partner Agency    | ✅ | ❌ | ❌ |
| Manage users, view audit logs        | ✅ | ❌ | ❌ |

All of the above is enforced **server-side** on every page (`require_role()`
at the top of each file and inside every POST handler) — not just by hiding
buttons in the UI. Direct URL access by an under-privileged role returns a
403, verified by automated testing (see Testing Performed in the update
summary).

## Security notes

- PDO prepared statements everywhere; no string-concatenated SQL.
- CSRF token verified on every state-changing POST.
- Output escaped via `e()` (`htmlspecialchars`) everywhere.
- Passwords hashed with `password_hash()` / verified with `password_verify()`.
- Sessions: `httponly` + `SameSite=Lax`, ID regenerated on login, 30-minute
  idle timeout.
- Applicant and Partner Agency deletion are soft where history exists
  (`is_deleted` / `Disabled` status) to preserve audit trail and referential
  integrity; hard delete is blocked server-side if an agency still has
  employment history attached.
- Every login, logout, create, update, delete, enable/disable, role change,
  and password reset is written to `care_jf_audit_logs` with the acting user,
  IP address, and a description.
