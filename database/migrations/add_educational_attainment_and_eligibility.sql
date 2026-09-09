-- =====================================================================
-- Migration: add_educational_attainment_and_eligibility.sql
-- Adds Educational Attainment and Eligibility fields to
-- care_jf_applicants. Purely additive — 10 new nullable columns, no
-- existing column, index, constraint, or row is touched. Existing
-- applicants keep every value NULL until edited; applicant_code, QR
-- identifiers, employment history, and agency relationships are all
-- unaffected (this migration touches no other table).
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_educational_attainment_and_eligibility.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_applicants
  ADD COLUMN educational_level ENUM(
    'High School/Senior High School Graduate',
    'Technical/Vocational',
    'College Graduate',
    'Postgraduate (Master/Doctorate)'
  ) NULL AFTER remarks,
  ADD COLUMN completion_status ENUM('Not Graduate','Graduate') NULL AFTER educational_level,
  ADD COLUMN highest_year_level_units VARCHAR(100) NULL AFTER completion_status,
  ADD COLUMN date_graduated DATE NULL AFTER highest_year_level_units,
  ADD COLUMN course_degree VARCHAR(255) NULL AFTER date_graduated,
  ADD COLUMN school_name VARCHAR(200) NULL AFTER course_degree,
  ADD COLUMN school_address VARCHAR(255) NULL AFTER school_name,
  ADD COLUMN eligibility_status ENUM('Eligible','Not Eligible') NULL AFTER school_address,
  ADD COLUMN eligibility_type ENUM(
    'Civil Service Professional',
    'Civil Service Subprofessional',
    'Civil Service Professional (Preference Rating)',
    'Civil Service Subprofessional (Preference Rating)',
    'Basic Competency on Local Treasury',
    'Barangay Official',
    'Honor Graduate Eligibility',
    'Fire Officer',
    'Penology Officer',
    'Skills Eligibility (MC 11)',
    'Other'
  ) NULL AFTER eligibility_status,
  ADD COLUMN other_eligibility_type VARCHAR(150) NULL AFTER eligibility_type;

SELECT 'Migration complete.' AS status;
SHOW COLUMNS FROM care_jf_applicants WHERE Field IN (
  'educational_level','completion_status','highest_year_level_units','date_graduated',
  'course_degree','school_name','school_address','eligibility_status','eligibility_type','other_eligibility_type'
);
SELECT COUNT(*) AS existing_applicant_count FROM care_jf_applicants;
SELECT id, applicant_code FROM care_jf_applicants ORDER BY id;
