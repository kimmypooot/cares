-- =====================================================================
-- Migration: add_application_tracking_and_hiring_workflow.sql
-- Extends care_jf_employment_records to also track a Partner Agency's
-- in-progress interest in an applicant, not just confirmed employment.
-- Phase 3 of 3 (Job Vacancies + Employer ID was Phase 1; Applicant QR
-- Code was Phase 2).
--
-- One row per agency progresses through employment_status states:
--   'For Review'  -> tagged, not yet a hire (is_current always 0)
--   'Hired'       -> confirmed (is_current = 1 for the winning row)
--   'Withdrawn'   -> the agency released their own tag
--   'Superseded'  -> auto-closed because another agency hired first
-- date_hired is NULL until a row actually becomes 'Hired'.
-- vacancy_id links a confirmed hire back to the Job Vacancy it filled
-- (care_jf_job_vacancies.vacant_count is decremented at that same
-- moment -- see public/applicant-view.php's confirm_hired action).
--
-- No new table -- see
-- docs/superpowers/specs/2026-09-08-application-tracking-hiring-workflow-design.md
-- for the full rationale.
--
-- Safe to run against the current database. Purely additive (new ENUM
-- values, a column made nullable, one new nullable column) against
-- care_jf_employment_records.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_application_tracking_and_hiring_workflow.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_employment_records
  MODIFY COLUMN employment_status ENUM(
    'Job Order','Temporary','COS','Permanent','Casual','Other','Hired',
    'For Review','Withdrawn','Superseded'
  ) NOT NULL;

ALTER TABLE care_jf_employment_records
  MODIFY COLUMN date_hired DATE NULL;

ALTER TABLE care_jf_employment_records
  ADD COLUMN vacancy_id INT UNSIGNED NULL AFTER agency_id,
  ADD CONSTRAINT fk_employment_vacancy FOREIGN KEY (vacancy_id) REFERENCES care_jf_job_vacancies(id) ON DELETE RESTRICT;
