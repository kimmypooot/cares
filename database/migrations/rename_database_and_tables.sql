-- =====================================================================
-- Migration: rename_database_and_tables.sql
-- Renames the database from applicant_system to care_job_fair_db, and
-- prefixes every table with care_jf_. Table structure/columns/data are
-- completely unchanged — this is a pure rename, using MySQL/MariaDB's
-- RENAME TABLE, which is a metadata-only operation (no data is copied
-- or re-written) and correctly carries every index and foreign-key
-- relationship over to the new names automatically.
--
-- Run this LAST, strictly after database.sql and all 3 existing
-- migration files (update_application_management.sql,
-- add_partner_agency_accounts.sql, add_hired_status_and_uppercase_backfill.sql)
-- have already been applied to the applicant_system database under its
-- original table names — this migration does not itself create any
-- table, only renames existing ones.
--
-- IMPORTANT: after this migration, config/database.php's DB_NAME
-- constant must ALSO be updated to 'care_job_fair_db' (done as part of
-- this same task, not by this SQL file) — the application will not
-- function until both are done together.
--
-- This migration does NOT drop the old (now-empty) applicant_system
-- database — once you've confirmed the application works correctly
-- against the new database, you can remove it manually with:
--   DROP DATABASE applicant_system;
--
-- Unlike the other migrations in this repo, this one is NOT safe to run
-- twice — the second run will fail with "table doesn't exist" since the
-- RENAME TABLE has already moved the source tables. Run it exactly once.
--
-- Note: this migration does NOT rewrite historical audit_logs.table_name
-- values (e.g. old rows still say 'users', not 'care_jf_users') — those
-- are a factual record of what the table was actually called at the time
-- each action happened, and rewriting them would misrepresent history.
-- Only new audit log entries created after this migration will use the
-- new table names.
--
-- Usage:
--   mysql -u root -p < database/migrations/rename_database_and_tables.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS care_job_fair_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- One atomic multi-table RENAME TABLE statement, so InnoDB resolves every
-- foreign-key relationship (employment_records -> applicants,
-- employment_records -> partner_agencies, audit_logs -> users) in a
-- single step rather than passing through any transient partially-renamed
-- state.
RENAME TABLE
  applicant_system.users              TO care_job_fair_db.care_jf_users,
  applicant_system.partner_agencies   TO care_job_fair_db.care_jf_partner_agencies,
  applicant_system.applicants         TO care_job_fair_db.care_jf_applicants,
  applicant_system.employment_records TO care_job_fair_db.care_jf_employment_records,
  applicant_system.audit_logs         TO care_job_fair_db.care_jf_audit_logs;

-- ---------------------------------------------------------------------
-- Sanity check
-- ---------------------------------------------------------------------
SELECT 'Rename complete.' AS status;
SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'care_job_fair_db' ORDER BY TABLE_NAME;
SELECT COUNT(*) AS old_db_tables_remaining FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'applicant_system';
