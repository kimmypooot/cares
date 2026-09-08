-- =====================================================================
-- Migration: add_agency_primary_user_flag.sql
-- Adds care_jf_users.is_primary, identifying the original/initial
-- account for a Partner Agency now that an agency can have more than
-- one user. Display/audit label only — it must never gate a
-- permission check; every active user in an agency has identical
-- permissions. See
-- docs/superpowers/specs/2026-09-08-multi-user-partner-agency-accounts-design.md
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_agency_primary_user_flag.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_users
  ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER agency_id;

-- Every existing Partner Agency account today is, by definition, the
-- original/only account for its agency.
UPDATE care_jf_users SET is_primary = 1 WHERE role = 'Partner Agency';

SELECT 'Migration complete.' AS status;
SELECT id, username, role, agency_id, is_primary FROM care_jf_users WHERE role = 'Partner Agency';
