-- =====================================================================
-- Migration: rename_educational_eligibility_labels.sql
-- Renames option labels on care_jf_applicants introduced by
-- add_educational_attainment_and_eligibility.sql:
--   educational_level: 'High School/Senior High School Graduate' -> 'High School/Senior High Level'
--                       'College Graduate' -> 'College Level'
--   completion_status: 'Not Graduate' -> 'Not Graduated'
--                       'Graduate' -> 'Graduated'
--   eligibility_type:   adds 'RA 1080' (just before the catch-all option)
--                       'Other' -> 'Other Eligibility'
--
-- Each ENUM is first widened to the union of its old and new values so
-- ALTER TABLE never coerces an existing row's value to '' (MySQL does
-- this silently to any row whose current value is dropped from the
-- ENUM's allowed list — see the comment above the employment_records
-- table in database.sql for the same landmine). Existing rows are then
-- UPDATEd from the old label to the new one, and only then is each
-- ENUM narrowed to its final list. No other column is touched.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/rename_educational_eligibility_labels.sql
-- =====================================================================

USE care_job_fair_db;

-- ---- Step 1: widen each ENUM to old values + new values ----
ALTER TABLE care_jf_applicants
  MODIFY COLUMN educational_level ENUM(
    'High School/Senior High School Graduate',
    'Technical/Vocational',
    'College Graduate',
    'Postgraduate (Master/Doctorate)',
    'High School/Senior High Level',
    'College Level'
  ) NULL,
  MODIFY COLUMN completion_status ENUM(
    'Not Graduate',
    'Graduate',
    'Not Graduated',
    'Graduated'
  ) NULL,
  MODIFY COLUMN eligibility_type ENUM(
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
    'Other',
    'RA 1080',
    'Other Eligibility'
  ) NULL;

-- ---- Step 2: migrate existing rows from the old labels to the new ones ----
UPDATE care_jf_applicants SET educational_level = 'High School/Senior High Level' WHERE educational_level = 'High School/Senior High School Graduate';
UPDATE care_jf_applicants SET educational_level = 'College Level' WHERE educational_level = 'College Graduate';
UPDATE care_jf_applicants SET completion_status = 'Not Graduated' WHERE completion_status = 'Not Graduate';
UPDATE care_jf_applicants SET completion_status = 'Graduated' WHERE completion_status = 'Graduate';
UPDATE care_jf_applicants SET eligibility_type = 'Other Eligibility' WHERE eligibility_type = 'Other';

-- ---- Step 3: narrow each ENUM to its final list ----
ALTER TABLE care_jf_applicants
  MODIFY COLUMN educational_level ENUM(
    'High School/Senior High Level',
    'Technical/Vocational',
    'College Level',
    'Postgraduate (Master/Doctorate)'
  ) NULL,
  MODIFY COLUMN completion_status ENUM('Not Graduated','Graduated') NULL,
  MODIFY COLUMN eligibility_type ENUM(
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
    'RA 1080',
    'Other Eligibility'
  ) NULL;

SELECT 'Migration complete.' AS status;
SHOW COLUMNS FROM care_jf_applicants WHERE Field IN ('educational_level', 'completion_status', 'eligibility_type');
SELECT id, applicant_code, educational_level, completion_status, eligibility_type FROM care_jf_applicants ORDER BY id;
