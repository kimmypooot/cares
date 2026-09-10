-- =====================================================================
-- Migration: add_gip_job_level.sql
-- Adds 'GIP' (Government Internship Program) as a 5th value to
-- care_jf_job_vacancies.job_level, alongside the existing 'Plantilla
-- Level 1', 'Plantilla Level 2', 'Job Order', 'COS'. Pure addition, no
-- data remap needed since no existing row can hold 'GIP' yet.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_gip_job_level.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_job_vacancies
  MODIFY COLUMN job_level ENUM('Plantilla Level 1', 'Plantilla Level 2', 'Job Order', 'COS', 'GIP') NOT NULL;

SELECT 'Migration complete.' AS status;
SELECT id, agency_id, position, job_level FROM care_jf_job_vacancies;
