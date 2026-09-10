-- =====================================================================
-- Migration: add_service_selection_to_service_availments.sql
-- Adds service-selection columns to care_jf_service_availments (Phase 2
-- of the Client / Agency-Service Availment feature — see
-- docs/superpowers/specs/2026-09-10-client-service-modal-and-nav-design.md).
-- A row's agency-level tag (Phase 1) is created before its specific
-- service is chosen (Phase 2's modal), so both new columns are nullable.
-- No changes to any existing column, table, or row.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_service_selection_to_service_availments.sql
-- =====================================================================

USE care_job_fair_db;

ALTER TABLE care_jf_service_availments
  ADD COLUMN IF NOT EXISTS service_id INT UNSIGNED NULL AFTER agency_id,
  ADD COLUMN IF NOT EXISTS custom_service_name VARCHAR(200) NULL AFTER service_id;

-- Guarded separately: MySQL/MariaDB don't support "ADD CONSTRAINT ... IF
-- NOT EXISTS" the way ADD COLUMN does, so this is idempotent via a
-- pre-check instead — re-running the migration after the FK already
-- exists must not error.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = 'care_job_fair_db'
    AND TABLE_NAME = 'care_jf_service_availments'
    AND CONSTRAINT_NAME = 'fk_availment_service'
);
SET @add_fk_sql = IF(@fk_exists = 0,
  'ALTER TABLE care_jf_service_availments ADD CONSTRAINT fk_availment_service FOREIGN KEY (service_id) REFERENCES care_jf_agency_services(id) ON DELETE SET NULL',
  'SELECT ''fk_availment_service already exists, skipping'' AS status'
);
PREPARE stmt FROM @add_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migration complete.' AS status;
SELECT COUNT(*) AS total_rows, SUM(service_id IS NOT NULL) AS rows_with_service FROM care_jf_service_availments;
