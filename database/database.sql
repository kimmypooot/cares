-- =====================================================================
-- Application Management and Monitoring System
-- Database Installation Script (v2)
-- Engine: MySQL 8+ / MariaDB 10.4+
--
-- NOTE: If you are upgrading an EXISTING installation, do NOT run this
-- file — run database/migrations/update_application_management.sql
-- instead, which preserves existing data.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS applicant_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE applicant_system;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  role          ENUM('Administrator','Employee','Viewer') NOT NULL DEFAULT 'Viewer',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default administrator account -> username: admin / password: Admin@123
INSERT INTO users (username, password, full_name, role) VALUES
('admin', '$2b$10$rxKO5rVyUKtNmuhu1S1aDuFq8GeiR.IQ9rK81QB6cGvD9F5vbKv1i', 'System Administrator', 'Administrator');

-- ---------------------------------------------------------------------
-- partner_agencies
-- ---------------------------------------------------------------------
CREATE TABLE partner_agencies (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agency_name  VARCHAR(200) NOT NULL,
  address      VARCHAR(255) NOT NULL,
  status       ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agency_status (status)
) ENGINE=InnoDB;

INSERT INTO partner_agencies (agency_name, address, status) VALUES
('ABC Corporation', 'Makati City, Metro Manila', 'Active'),
('Government Agency (Sample)', 'Quezon City, Metro Manila', 'Active');

-- ---------------------------------------------------------------------
-- applicants
-- ---------------------------------------------------------------------
CREATE TABLE applicants (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  applicant_code  VARCHAR(20)  NOT NULL UNIQUE,
  last_name       VARCHAR(100) NOT NULL,
  first_name      VARCHAR(100) NOT NULL,
  middle_name     VARCHAR(100) NULL,
  extension_name  VARCHAR(20)  NULL,
  sex             ENUM('MALE','FEMALE') NOT NULL,
  date_of_birth   DATE NOT NULL,
  place_of_birth  VARCHAR(150) NULL,
  contact_number  VARCHAR(20)  NOT NULL,
  email_address   VARCHAR(150) NULL,
  address         VARCHAR(255) NOT NULL,
  civil_status    ENUM('SINGLE','MARRIED','WIDOWED','SEPARATED','DIVORCED','OTHER') NOT NULL,
  source          ENUM('Public','Internal') NOT NULL DEFAULT 'Internal',
  service_job_seeker       TINYINT(1) NOT NULL DEFAULT 0,
  service_agency_services  TINYINT(1) NOT NULL DEFAULT 0,
  remarks         TEXT NULL,
  is_deleted      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_last_name (last_name),
  INDEX idx_first_name (first_name),
  INDEX idx_contact (contact_number),
  INDEX idx_email (email_address),
  FULLTEXT INDEX ft_name (last_name, first_name, middle_name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- employment_records
--   employment_status  = Employment CLASSIFICATION (Job Order / Temporary /
--                         COS / Permanent / Other) — NOT the applicant's
--                         overall Employed/Not-Yet status, which is always
--                         DERIVED from whether an is_current=1, status='Active'
--                         row exists for the applicant (see functions.php
--                         current_employment_status()).
--   agency_id           = FK to partner_agencies (preferred, new records)
--   agency_company_name / agency_company_address
--                       = free-text fallback, kept for legacy/public
--                         registrations that don't reference a partner
--                         agency record.
--   status               = Active / Disabled (soft-disable a record without
--                         losing history; distinct from is_current)
-- ---------------------------------------------------------------------
CREATE TABLE employment_records (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  applicant_id            INT UNSIGNED NOT NULL,
  agency_id               INT UNSIGNED NULL,
  agency_company_name     VARCHAR(200) NOT NULL,
  agency_company_address  VARCHAR(255) NOT NULL,
  date_hired              DATE NOT NULL,
  employment_status       ENUM('Job Order','Temporary','COS','Permanent','Casual','Other') NOT NULL,
  is_current              TINYINT(1)   NOT NULL DEFAULT 1,
  status                  ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  remarks                 VARCHAR(255) NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_employment_applicant
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
  CONSTRAINT fk_employment_agency
    FOREIGN KEY (agency_id) REFERENCES partner_agencies(id) ON DELETE SET NULL,
  INDEX idx_applicant (applicant_id),
  INDEX idx_agency (agency_id),
  INDEX idx_status (employment_status),
  INDEX idx_record_status (status)
) ENGINE=InnoDB;

-- NOTE: There is intentionally NO trigger auto-managing is_current here.
-- MySQL/MariaDB forbids a trigger from updating the same table that fired
-- it during a multi-row statement (error 1442) — this previously broke
-- batch/seed inserts. "Only one current record per applicant" is instead
-- enforced in application code: before setting is_current = 1, the app
-- first runs an UPDATE to clear is_current on the applicant's other rows,
-- in the same request. See public/employment-form.php.

-- ---------------------------------------------------------------------
-- audit_logs
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(100) NOT NULL,
  table_name  VARCHAR(100) NOT NULL,
  record_id   INT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  ip_address  VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user (user_id),
  INDEX idx_table (table_name),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Sample data (optional - safe to remove)
-- ---------------------------------------------------------------------
INSERT INTO applicants
  (applicant_code, last_name, first_name, middle_name, extension_name, sex, date_of_birth, place_of_birth, contact_number, email_address, address, civil_status, source, service_job_seeker, service_agency_services)
VALUES
  ('APP-202601-000001','DELA CRUZ','JUAN','SANTOS','JR.','MALE','1995-01-15','QUEZON CITY','09171234567','juan.delacruz@example.com','BARANGAY EXAMPLE, QUEZON CITY, METRO MANILA','SINGLE','Internal', 1, 0),
  ('APP-202601-000002','REYES','MARIA','LOPEZ',NULL,'FEMALE','1998-06-20','MANILA','09281234567','maria.reyes@example.com','BARANGAY UNO, MANILA, METRO MANILA','MARRIED','Public', 1, 1);

-- Seeded one statement at a time — see note above on why is_current
-- bookkeeping must be sequenced by the application, not a trigger.
INSERT INTO employment_records
  (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
VALUES
  (1, 1, 'ABC Corporation', 'Makati City', '2024-01-10', 'COS', 0, 'Active');

INSERT INTO employment_records
  (applicant_id, agency_id, agency_company_name, agency_company_address, date_hired, employment_status, is_current, status)
VALUES
  (1, 2, 'Government Agency (Sample)', 'Quezon City', '2026-01-15', 'Permanent', 1, 'Active');
