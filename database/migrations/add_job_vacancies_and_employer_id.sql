-- =====================================================================
-- Migration: add_job_vacancies_and_employer_id.sql
-- Adds a minimal Job Vacancies module and atomic Employer ID generation
-- for activated Partner Agencies. Phase 1 of a 3-phase effort (QR Code
-- is Phase 2; the For-Review->Hired application-tracking redesign is
-- Phase 3, which will add employment_records.vacancy_id referencing
-- the table created here).
--
-- Safe to run against the current database. Idempotent except for the
-- backfill step, which is written to only ever raise the sequence
-- counter, never lower it, so re-running it is harmless.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_job_vacancies_and_employer_id.sql
-- =====================================================================

USE care_job_fair_db;

-- ---------------------------------------------------------------------
-- 1. Employer ID column — assigned once at activation, never regenerated.
-- ---------------------------------------------------------------------
ALTER TABLE care_jf_partner_agencies
  ADD COLUMN IF NOT EXISTS employer_id VARCHAR(20) NULL UNIQUE AFTER id;

-- ---------------------------------------------------------------------
-- 2. Reusable atomic-counter table. generate_employer_id() (functions.php)
--    wraps an INSERT ... ON DUPLICATE KEY UPDATE last_value = last_value + 1
--    in an explicit transaction, followed by a SELECT to read the value
--    back — relying on InnoDB's "read your own writes" within the same
--    transaction. The UPDATE clause row-locks atomically as part of ONE
--    statement, so two simultaneous callers can never read-then-write
--    the same next value (the exact race COUNT(*) + 1 has). No
--    LAST_INSERT_ID() trick and no PDO::lastInsertId() call are used.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS care_jf_id_sequences (
  sequence_name VARCHAR(50) NOT NULL,
  year_key      INT NOT NULL,
  last_value    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (sequence_name, year_key)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Minimal Job Vacancies module.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS care_jf_job_vacancies (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agency_id            INT UNSIGNED NOT NULL,
  title                VARCHAR(150) NOT NULL DEFAULT 'Job Available',
  position             VARCHAR(150) NOT NULL,
  job_level            ENUM('Level 1','Level 2','Level 3','Job Order - Level 1','Job Order - Level 2','COS - Level 1','COS - Level 2') NOT NULL,
  salary_grade         VARCHAR(100) NULL,
  occupational_option  VARCHAR(150) NULL,
  vacant_count         INT UNSIGNED NOT NULL DEFAULT 1,
  status               ENUM('Active','Disabled','Filled','Closed') NOT NULL DEFAULT 'Active',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vacancy_agency FOREIGN KEY (agency_id) REFERENCES care_jf_partner_agencies(id) ON DELETE RESTRICT,
  INDEX idx_vacancy_agency (agency_id),
  INDEX idx_vacancy_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Backfill: assign Employer IDs to any already-Active Partner Agency
--    account that doesn't have one yet, in registration order, then
--    seed the sequence counter so the next NEW activation continues
--    correctly rather than colliding with a backfilled value.
-- ---------------------------------------------------------------------
SET @rownum = 0;

UPDATE care_jf_partner_agencies pa
JOIN (
  SELECT pa2.id, (@rownum := @rownum + 1) AS rn
  FROM care_jf_partner_agencies pa2
  JOIN care_jf_users u ON u.agency_id = pa2.id AND u.role = 'Partner Agency' AND u.status = 'Active'
  WHERE pa2.employer_id IS NULL
  GROUP BY pa2.id
  ORDER BY pa2.created_at, pa2.id
) ranked ON ranked.id = pa.id
SET pa.employer_id = CONCAT('EMP-', YEAR(CURDATE()), '-', LPAD(ranked.rn, 7, '0'));

INSERT INTO care_jf_id_sequences (sequence_name, year_key, last_value)
SELECT 'employer_id', YEAR(CURDATE()), COALESCE(MAX(CAST(SUBSTRING_INDEX(employer_id, '-', -1) AS UNSIGNED)), 0)
FROM care_jf_partner_agencies
WHERE employer_id IS NOT NULL AND employer_id LIKE CONCAT('EMP-', YEAR(CURDATE()), '-%')
ON DUPLICATE KEY UPDATE last_value = GREATEST(last_value, VALUES(last_value));

-- ---------------------------------------------------------------------
-- Sanity check
-- ---------------------------------------------------------------------
SELECT 'Migration complete.' AS status;
SELECT u.id, u.agency_id, u.username, pa.employer_id
FROM care_jf_users u JOIN care_jf_partner_agencies pa ON pa.id = u.agency_id
WHERE u.role = 'Partner Agency';
SELECT * FROM care_jf_id_sequences;
