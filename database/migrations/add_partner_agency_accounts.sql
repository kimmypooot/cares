-- =====================================================================
-- Migration: add_partner_agency_accounts.sql
-- Adds Partner Agency self-registration support: a shared Pending/
-- Active/Disabled status for ALL user accounts (replacing is_active as
-- the source of truth, though is_active is kept in sync for backward
-- compatibility), a users.agency_id relationship to partner_agencies,
-- and contact fields on partner_agencies.
--
-- Safe to run against either a fresh v2 install (database/database.sql)
-- or an existing database already upgraded via
-- update_application_management.sql. Every statement is idempotent
-- (IF NOT EXISTS / enum widen) so re-running it is harmless.
--
-- Usage:
--   mysql -u root -p applicant_system < database/migrations/add_partner_agency_accounts.sql
-- =====================================================================

USE applicant_system;

-- ---------------------------------------------------------------------
-- 1. partner_agencies: add contact fields required by Partner Agency
--    Registration. Existing rows (including the two seeded samples)
--    get blank/NULL defaults, editable later via the Partner Agency form.
-- ---------------------------------------------------------------------
ALTER TABLE partner_agencies
  ADD COLUMN IF NOT EXISTS contact_person VARCHAR(150) NOT NULL DEFAULT '' AFTER address,
  ADD COLUMN IF NOT EXISTS contact_no     VARCHAR(20)  NOT NULL DEFAULT '' AFTER contact_person,
  ADD COLUMN IF NOT EXISTS email          VARCHAR(150) NULL AFTER contact_no;

-- ---------------------------------------------------------------------
-- 2. users: widen role enum to add 'Partner Agency'
-- ---------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('Administrator','Employee','Viewer','Partner Agency') NOT NULL DEFAULT 'Viewer';

-- ---------------------------------------------------------------------
-- 3. users: add a shared Pending/Active/Disabled status.
--
--    Backfill logic: is_active=1 -> Active. is_active=0 with
--    role='Viewer' -> Pending (matches the only existing code path that
--    ever created a disabled-pending account: the public Viewer
--    sign-up form). is_active=0 for any other role -> Disabled (matches
--    the only other code path that clears is_active: an Administrator's
--    manual toggle in users.php).
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS status ENUM('Pending','Active','Disabled') NOT NULL DEFAULT 'Active' AFTER role;

UPDATE users SET status = 'Active'   WHERE is_active = 1;
UPDATE users SET status = 'Pending'  WHERE is_active = 0 AND role = 'Viewer';
UPDATE users SET status = 'Disabled' WHERE is_active = 0 AND role <> 'Viewer';

-- ---------------------------------------------------------------------
-- 4. users: add agency_id relationship. NULL for every role except
--    Partner Agency, where application code enforces NOT NULL (MySQL
--    can't conditionally require a column based on another column's
--    value).
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS agency_id INT UNSIGNED NULL AFTER status;

ALTER TABLE users
  ADD CONSTRAINT fk_users_agency
    FOREIGN KEY IF NOT EXISTS (agency_id) REFERENCES partner_agencies(id) ON DELETE RESTRICT,
  ADD INDEX IF NOT EXISTS idx_users_agency (agency_id);

-- ---------------------------------------------------------------------
-- 5. Sanity check
-- ---------------------------------------------------------------------
SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS users_pending FROM users WHERE status = 'Pending';
SELECT COUNT(*) AS partner_agency_users_missing_agency FROM users WHERE role = 'Partner Agency' AND agency_id IS NULL;
