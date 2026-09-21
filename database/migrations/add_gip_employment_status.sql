-- =====================================================================
-- Migration: add_gip_employment_status.sql
-- Adds 'GIP' (Government Internship Program) as a value of
-- care_jf_employment_records.employment_status, alongside the existing
-- 'Job Order', 'Temporary', 'COS', 'Permanent', 'Casual', 'Other',
-- 'Hired', 'For Review', 'Withdrawn', 'Superseded'. Pure addition, no
-- data remap needed since no existing row can hold 'GIP' yet. Mirrors
-- add_gip_job_level.sql's addition of 'GIP' to care_jf_job_vacancies.job_level.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_gip_employment_status.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_employment_records
  MODIFY COLUMN employment_status ENUM(
    'Job Order','Temporary','COS','Permanent','Casual','Other','Hired',
    'For Review','Withdrawn','Superseded','GIP'
  ) NOT NULL;

SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS employment_records_gip FROM care_jf_employment_records WHERE employment_status = 'GIP';
