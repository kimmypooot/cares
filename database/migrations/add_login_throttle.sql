-- =====================================================================
-- Migration: add_login_throttle.sql
-- Adds persistent, database-backed login rate limiting.
--
-- The previous lockout (5 failed attempts -> 60s lockout) lived only in
-- $_SESSION, keyed by the PHP session cookie. Since a fresh session (no
-- cookie sent, or cookie cleared) starts with a zeroed attempt counter,
-- the lockout was trivially bypassed by any scripted attacker that
-- fetched a new session + CSRF token per login attempt — confirmed via
-- 8 consecutive failed logins against the same account with zero
-- lockout triggered. This table moves the counter server-side, keyed
-- by username and by client IP, so it survives across sessions.
--
-- Safe/non-destructive: adds one new table only.
--
-- Usage:
--   mysql -u root -p care_job_fair_db < database/migrations/add_login_throttle.sql
-- =====================================================================

USE care_job_fair_db;

CREATE TABLE IF NOT EXISTS care_jf_login_throttle (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier     VARCHAR(191) NOT NULL COMMENT 'e.g. user:<username> or ip:<address>',
  attempt_count  INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_login_throttle_identifier (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Migration complete.' AS status;
