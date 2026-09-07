-- =====================================================================
-- Migration: add_hired_status_and_uppercase_backfill.sql
-- Adds a 'Hired' employment_status classification (used by the new
-- Partner Agency / Admin / Employee "Mark as Hired" quick action) and
-- backfills UPPER() normalization onto columns that should already be
-- uppercase but weren't consistently normalized by every form that
-- writes them.
--
-- Safe to run against the current database (already includes the
-- Partner Agency Registration migration). Idempotent: widening an enum
-- to include a value it already has, and UPPER()-ing already-uppercase
-- text, are both safe no-ops on re-run.
--
-- Usage:
--   mysql -u root -p applicant_system < database/migrations/add_hired_status_and_uppercase_backfill.sql
-- =====================================================================

USE applicant_system;

-- ---------------------------------------------------------------------
-- 1. employment_records: add 'Hired' as a new classification, used by
--    the new one-click "Mark as Hired" action. Existing granular
--    classifications (Job Order/Temporary/COS/Permanent/Casual/Other)
--    and the full Employment module are unchanged.
-- ---------------------------------------------------------------------
ALTER TABLE employment_records
  MODIFY COLUMN employment_status
  ENUM('Job Order','Temporary','COS','Permanent','Casual','Other','Hired')
  NOT NULL;

-- ---------------------------------------------------------------------
-- 2. Uppercase backfill.
--
--    applicants: a prior migration already ran UPPER() on these columns
--    once, but public/applicant-create.php and public/applicant-edit.php
--    never normalized on save, so any admin-created/edited row since
--    then may be mixed-case. Re-running UPPER() on already-uppercase
--    text is a safe no-op.
--
--    partner_agencies / users.full_name: never normalized before this
--    migration.
-- ---------------------------------------------------------------------
UPDATE applicants SET
  last_name      = UPPER(last_name),
  first_name     = UPPER(first_name),
  middle_name    = UPPER(middle_name),
  extension_name = UPPER(extension_name),
  address        = UPPER(address),
  place_of_birth = UPPER(place_of_birth)
WHERE is_deleted = 0 OR is_deleted = 1;

UPDATE partner_agencies SET
  agency_name    = UPPER(agency_name),
  address        = UPPER(address),
  contact_person = UPPER(contact_person);

UPDATE users SET full_name = UPPER(full_name);

-- ---------------------------------------------------------------------
-- 3. Sanity check
-- ---------------------------------------------------------------------
SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS employment_records_hired FROM employment_records WHERE employment_status = 'Hired';
