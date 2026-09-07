-- =====================================================================
-- Migration: update_application_management.sql
-- Upgrades an EXISTING "Applicant Registration and Employment Tracking
-- System" (v1) database to the "Application Management and Monitoring
-- System" (v2) schema, without deleting any existing data.
--
-- Safe to run once against a live v1 database. Every ALTER either adds
-- a nullable/defaulted column, adds a new table, or widens an enum
-- before narrowing it — no column is ever dropped and no row is deleted.
--
-- Usage:
--   mysql -u root -p applicant_system < database/migrations/update_application_management.sql
-- =====================================================================

USE applicant_system;

-- ---------------------------------------------------------------------
-- 1. users: rename role "Staff" -> "Employee"
--    (widen enum first so existing 'Staff' rows remain valid mid-migration)
-- ---------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('Administrator','Staff','Employee','Viewer') NOT NULL DEFAULT 'Viewer';

UPDATE users SET role = 'Employee' WHERE role = 'Staff';

ALTER TABLE users
  MODIFY COLUMN role ENUM('Administrator','Employee','Viewer') NOT NULL DEFAULT 'Viewer';

-- ---------------------------------------------------------------------
-- 2. partner_agencies: new table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partner_agencies (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agency_name  VARCHAR(200) NOT NULL,
  address      VARCHAR(255) NOT NULL,
  status       ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agency_status (status)
) ENGINE=InnoDB;

-- Backfill: create a partner agency record for every distinct agency name
-- already referenced by existing employment_records, so historical data
-- can optionally be linked up (agency_id stays NULL until an admin does so
-- via the Employment module — see step 4).
INSERT INTO partner_agencies (agency_name, address, status)
SELECT DISTINCT er.agency_company_name, er.agency_company_address, 'Active'
FROM employment_records er
LEFT JOIN partner_agencies pa ON pa.agency_name = er.agency_company_name
WHERE pa.id IS NULL;

-- ---------------------------------------------------------------------
-- 3. applicants: add new fields (all nullable — existing rows stay valid)
-- ---------------------------------------------------------------------
ALTER TABLE applicants
  ADD COLUMN IF NOT EXISTS place_of_birth VARCHAR(150) NULL AFTER date_of_birth,
  ADD COLUMN IF NOT EXISTS email_address  VARCHAR(150) NULL AFTER contact_number,
  ADD COLUMN IF NOT EXISTS source ENUM('Public','Internal') NOT NULL DEFAULT 'Internal' AFTER civil_status,
  ADD COLUMN IF NOT EXISTS remarks TEXT NULL;

ALTER TABLE applicants
  ADD INDEX IF NOT EXISTS idx_email (email_address);

-- ---------------------------------------------------------------------
-- 4. employment_records: add agency_id FK, add status (Active/Disabled),
--    and remap the employment_status enum to the new classification labels
--    ("Contract of Service" -> "COS", "Casual" -> "Temporary").
-- ---------------------------------------------------------------------
ALTER TABLE employment_records
  ADD COLUMN IF NOT EXISTS agency_id INT UNSIGNED NULL AFTER applicant_id,
  ADD COLUMN IF NOT EXISTS status ENUM('Active','Disabled') NOT NULL DEFAULT 'Active' AFTER is_current,
  ADD COLUMN IF NOT EXISTS remarks VARCHAR(255) NULL;

-- Widen the enum so old + new values coexist during the data remap
ALTER TABLE employment_records
  MODIFY COLUMN employment_status
  ENUM('Job Order','Contract of Service','Casual','Permanent','Not Yet Hired','Other','Temporary','COS')
  NOT NULL;

UPDATE employment_records SET employment_status = 'COS'       WHERE employment_status = 'Contract of Service';
UPDATE employment_records SET employment_status = 'Temporary' WHERE employment_status = 'Casual';
-- "Not Yet Hired" as a row-level classification doesn't apply under the new
-- model (absence of a current row means Not Hired) — reclassify any such
-- legacy rows as 'Other' rather than deleting them.
UPDATE employment_records SET employment_status = 'Other'     WHERE employment_status = 'Not Yet Hired';

-- Narrow the enum to its final v2 form
ALTER TABLE employment_records
  MODIFY COLUMN employment_status
  ENUM('Job Order','Temporary','COS','Permanent','Other')
  NOT NULL;

-- ---------------------------------------------------------------------
-- v2.1: Re-add "Casual" as its own distinct classification, alongside
-- (not replacing) "Temporary". This is a separate, later step from the
-- one-time "Casual" -> "Temporary" rename above — that rename only ever
-- touches historical data once (a Casual value can no longer exist by
-- this point in a fresh run), so it's safe to re-run this whole script,
-- including this ADD, without corrupting any new "Casual" records
-- created after the original v1->v2 upgrade.
-- ---------------------------------------------------------------------
ALTER TABLE employment_records
  MODIFY COLUMN employment_status
  ENUM('Job Order','Temporary','COS','Permanent','Casual','Other')
  NOT NULL;

-- Link existing employment rows to the partner_agencies backfilled in step 2
UPDATE employment_records er
JOIN partner_agencies pa ON pa.agency_name = er.agency_company_name
SET er.agency_id = pa.id
WHERE er.agency_id IS NULL;

ALTER TABLE employment_records
  ADD CONSTRAINT fk_employment_agency
    FOREIGN KEY IF NOT EXISTS (agency_id) REFERENCES partner_agencies(id) ON DELETE SET NULL,
  ADD INDEX IF NOT EXISTS idx_agency (agency_id),
  ADD INDEX IF NOT EXISTS idx_record_status (status);

-- ---------------------------------------------------------------------
-- 5. Drop the old auto-current trigger if it exists.
--    It causes MySQL error 1442 ("Can't update table ... already used by
--    statement") on any multi-row INSERT into employment_records — this
--    was the underlying cause of the Employment module's batch/seed
--    failures. "Only one current record per applicant" is now enforced
--    in application code (public/employment-form.php) instead.
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_employment_before_insert;
DROP TRIGGER IF EXISTS trg_employment_before_update;

-- ---------------------------------------------------------------------
-- 7. Convert sex/civil_status enums to uppercase-only values, and
--    normalize existing applicant text fields to uppercase for
--    consistency with the registration form's uppercase-on-input rule.
--
--    IMPORTANT: MySQL/MariaDB enum matching is case-insensitive under the
--    default collation, and will not even let you define an enum with
--    both 'Male' and 'MALE' as separate values (tested — it's rejected
--    outright: "Column 'sex' has duplicated value"). A naive widen/rename
--    also silently re-collapses to the original casing on any accidental
--    re-match. The only reliable way to actually flip the stored casing
--    is a two-hop migration through a value that cannot case-collide with
--    either the old or new casing (e.g. 'MALE_TMP').
-- ---------------------------------------------------------------------

-- sex: Male/Female -> MALE/FEMALE
ALTER TABLE applicants MODIFY COLUMN sex ENUM('Male','Female','MALE_TMP','FEMALE_TMP') NOT NULL;
UPDATE applicants SET sex = 'MALE_TMP'   WHERE sex = 'Male';
UPDATE applicants SET sex = 'FEMALE_TMP' WHERE sex = 'Female';
ALTER TABLE applicants MODIFY COLUMN sex ENUM('MALE_TMP','FEMALE_TMP','MALE','FEMALE') NOT NULL;
UPDATE applicants SET sex = 'MALE'   WHERE sex = 'MALE_TMP';
UPDATE applicants SET sex = 'FEMALE' WHERE sex = 'FEMALE_TMP';
ALTER TABLE applicants MODIFY COLUMN sex ENUM('MALE','FEMALE') NOT NULL;

-- civil_status: Single/Married/... -> SINGLE/MARRIED/...
ALTER TABLE applicants MODIFY COLUMN civil_status
  ENUM('Single','Married','Widowed','Separated','Divorced','Other',
       'SINGLE_TMP','MARRIED_TMP','WIDOWED_TMP','SEPARATED_TMP','DIVORCED_TMP','OTHER_TMP') NOT NULL;
UPDATE applicants SET civil_status = 'SINGLE_TMP'    WHERE civil_status = 'Single';
UPDATE applicants SET civil_status = 'MARRIED_TMP'   WHERE civil_status = 'Married';
UPDATE applicants SET civil_status = 'WIDOWED_TMP'   WHERE civil_status = 'Widowed';
UPDATE applicants SET civil_status = 'SEPARATED_TMP' WHERE civil_status = 'Separated';
UPDATE applicants SET civil_status = 'DIVORCED_TMP'  WHERE civil_status = 'Divorced';
UPDATE applicants SET civil_status = 'OTHER_TMP'     WHERE civil_status = 'Other';
ALTER TABLE applicants MODIFY COLUMN civil_status
  ENUM('SINGLE_TMP','MARRIED_TMP','WIDOWED_TMP','SEPARATED_TMP','DIVORCED_TMP','OTHER_TMP',
       'SINGLE','MARRIED','WIDOWED','SEPARATED','DIVORCED','OTHER') NOT NULL;
UPDATE applicants SET civil_status = 'SINGLE'    WHERE civil_status = 'SINGLE_TMP';
UPDATE applicants SET civil_status = 'MARRIED'   WHERE civil_status = 'MARRIED_TMP';
UPDATE applicants SET civil_status = 'WIDOWED'   WHERE civil_status = 'WIDOWED_TMP';
UPDATE applicants SET civil_status = 'SEPARATED' WHERE civil_status = 'SEPARATED_TMP';
UPDATE applicants SET civil_status = 'DIVORCED'  WHERE civil_status = 'DIVORCED_TMP';
UPDATE applicants SET civil_status = 'OTHER'     WHERE civil_status = 'OTHER_TMP';
ALTER TABLE applicants MODIFY COLUMN civil_status ENUM('SINGLE','MARRIED','WIDOWED','SEPARATED','DIVORCED','OTHER') NOT NULL;

-- extension_name is a plain VARCHAR (not an enum) — safe to UPPER() directly
UPDATE applicants SET extension_name = UPPER(extension_name) WHERE extension_name IS NOT NULL;

-- Normalize existing free-text fields to uppercase too, matching the
-- registration form's uppercase-on-input convention going forward.
-- Email is deliberately left untouched (kept as typed, per rule).
UPDATE applicants SET
  last_name      = UPPER(last_name),
  first_name     = UPPER(first_name),
  middle_name    = UPPER(middle_name),
  address        = UPPER(address),
  place_of_birth = UPPER(place_of_birth)
WHERE is_deleted = 0 OR is_deleted = 1;

-- ---------------------------------------------------------------------
-- 9. "Services Availed" on registration: Job Seeker / Avail of Agency
--    Services (multi-select — an applicant can be either, or both).
--    Stored as two plain booleans rather than a single combined value so
--    each option can be filtered/reported on independently. Existing
--    applicants default to 0/0 (unset) since this predates their record;
--    staff can backfill it via Edit Applicant.
-- ---------------------------------------------------------------------
ALTER TABLE applicants
  ADD COLUMN IF NOT EXISTS service_job_seeker      TINYINT(1) NOT NULL DEFAULT 0 AFTER source,
  ADD COLUMN IF NOT EXISTS service_agency_services TINYINT(1) NOT NULL DEFAULT 0 AFTER service_job_seeker;

-- ---------------------------------------------------------------------
-- 10. Sanity check: report anything that needs a human look
-- ---------------------------------------------------------------------
SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS applicants_missing_email FROM applicants WHERE email_address IS NULL;
SELECT COUNT(*) AS employment_rows_without_agency FROM employment_records WHERE agency_id IS NULL;
