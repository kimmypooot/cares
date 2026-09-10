-- =====================================================================
-- Migration: add_service_availments_and_agency_services.sql
-- Adds two new tables for the Client / Agency-Service Availment feature
-- (see docs/superpowers/specs/2026-09-10-client-service-availment-design.md):
--   - care_jf_service_availments: tracks which Partner Agency a client's
--     (an applicant with service_agency_services=1) service was availed
--     with. Sibling to care_jf_employment_records but with no Hired/
--     vacancy lifecycle and no state machine.
--   - care_jf_agency_services: a reference-only catalog of each Partner
--     Agency's own services, never selected per-availment in this phase.
-- No changes to any existing table.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_service_availments_and_agency_services.sql
-- =====================================================================

USE care_job_fair_db;

CREATE TABLE IF NOT EXISTS care_jf_service_availments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  applicant_id  INT UNSIGNED NOT NULL,
  agency_id     INT UNSIGNED NOT NULL,
  source        ENUM('manual','qr_scan') NOT NULL DEFAULT 'qr_scan',
  status        ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_availment_applicant FOREIGN KEY (applicant_id) REFERENCES care_jf_applicants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_availment_agency FOREIGN KEY (agency_id) REFERENCES care_jf_partner_agencies(id) ON DELETE RESTRICT,
  UNIQUE KEY uniq_applicant_agency (applicant_id, agency_id),
  INDEX idx_availment_agency (agency_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS care_jf_agency_services (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agency_id     INT UNSIGNED NOT NULL,
  service_name  VARCHAR(200) NOT NULL,
  description   VARCHAR(500) NULL,
  status        ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_service_agency FOREIGN KEY (agency_id) REFERENCES care_jf_partner_agencies(id) ON DELETE RESTRICT,
  INDEX idx_service_agency (agency_id)
) ENGINE=InnoDB;

SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS service_availments FROM care_jf_service_availments;
SELECT COUNT(*) AS agency_services FROM care_jf_agency_services;
