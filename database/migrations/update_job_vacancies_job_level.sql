-- =====================================================================
-- Migration: update_job_vacancies_job_level.sql
-- Replaces care_jf_job_vacancies.job_level's 7-value ENUM with a
-- simplified 4-value list requested for the Job Vacancies form:
--   Plantilla Level 1 / Plantilla Level 2 / Job Order / COS
--
-- The column is first widened to a superset ENUM (old values + new
-- values) so the remap UPDATEs below can assign a new-list value to a
-- row that is still holding an old-list value. Only once every row
-- holds a new-list value does the final ALTER narrow the column down
-- to just the 4 new values. Doing the narrowing ALTER before the data
-- remap (as an earlier version of this file did) makes MySQL silently
-- coerce any value outside the new ENUM to '' at ALTER time — since
-- the UPDATEs haven't run yet, EVERY row loses its job_level. Widen,
-- migrate, narrow — never narrow first.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/update_job_vacancies_job_level.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_job_vacancies
  MODIFY COLUMN job_level ENUM(
    'Level 1', 'Level 2', 'Level 3',
    'Job Order - Level 1', 'Job Order - Level 2',
    'COS - Level 1', 'COS - Level 2',
    'Plantilla Level 1', 'Plantilla Level 2', 'Job Order', 'COS'
  ) NOT NULL;

UPDATE care_jf_job_vacancies SET job_level = 'Plantilla Level 1' WHERE job_level = 'Level 1';
UPDATE care_jf_job_vacancies SET job_level = 'Plantilla Level 2' WHERE job_level IN ('Level 2', 'Level 3');
UPDATE care_jf_job_vacancies SET job_level = 'Job Order' WHERE job_level IN ('Job Order - Level 1', 'Job Order - Level 2');
UPDATE care_jf_job_vacancies SET job_level = 'COS' WHERE job_level IN ('COS - Level 1', 'COS - Level 2');

ALTER TABLE care_jf_job_vacancies
  MODIFY COLUMN job_level ENUM('Plantilla Level 1', 'Plantilla Level 2', 'Job Order', 'COS') NOT NULL;

SELECT 'Migration complete.' AS status;
SELECT id, agency_id, position, job_level FROM care_jf_job_vacancies;
