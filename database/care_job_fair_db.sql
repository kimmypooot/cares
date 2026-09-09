-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 09, 2026 at 07:33 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `care_job_fair_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_applicants`
--

CREATE TABLE `care_jf_applicants` (
  `id` int(10) UNSIGNED NOT NULL,
  `applicant_code` varchar(20) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `extension_name` varchar(20) DEFAULT NULL,
  `sex` enum('MALE','FEMALE') NOT NULL,
  `date_of_birth` date NOT NULL,
  `place_of_birth` varchar(150) DEFAULT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email_address` varchar(150) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `civil_status` enum('SINGLE','MARRIED','WIDOWED','SEPARATED','DIVORCED','OTHER') NOT NULL,
  `source` enum('Public','Internal') NOT NULL DEFAULT 'Internal',
  `service_job_seeker` tinyint(1) NOT NULL DEFAULT 0,
  `service_agency_services` tinyint(1) NOT NULL DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `educational_level` enum('High School/Senior High Level','Technical/Vocational','College Level','Postgraduate (Master/Doctorate)') DEFAULT NULL,
  `completion_status` enum('Not Graduated','Graduated') DEFAULT NULL,
  `highest_year_level_units` varchar(100) DEFAULT NULL,
  `date_graduated` date DEFAULT NULL,
  `course_degree` varchar(255) DEFAULT NULL,
  `school_name` varchar(200) DEFAULT NULL,
  `school_address` varchar(255) DEFAULT NULL,
  `eligibility_status` enum('Eligible','Not Eligible') DEFAULT NULL,
  `eligibility_type` enum('Civil Service Professional','Civil Service Subprofessional','Civil Service Professional (Preference Rating)','Civil Service Subprofessional (Preference Rating)','Basic Competency on Local Treasury','Barangay Official','Honor Graduate Eligibility','Fire Officer','Penology Officer','Skills Eligibility (MC 11)','RA 1080','Other Eligibility') DEFAULT NULL,
  `other_eligibility_type` varchar(150) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_audit_logs`
--

CREATE TABLE `care_jf_audit_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` int(10) UNSIGNED DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `care_jf_audit_logs`
--

INSERT INTO `care_jf_audit_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `description`, `ip_address`, `created_at`) VALUES
(58, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-09 05:26:23'),
(59, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '::1', '2026-09-09 05:26:38');

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_employment_records`
--

CREATE TABLE `care_jf_employment_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `applicant_id` int(10) UNSIGNED NOT NULL,
  `agency_id` int(10) UNSIGNED DEFAULT NULL,
  `vacancy_id` int(10) UNSIGNED DEFAULT NULL,
  `agency_company_name` varchar(200) NOT NULL,
  `agency_company_address` varchar(255) NOT NULL,
  `date_hired` date DEFAULT NULL,
  `employment_status` enum('Job Order','Temporary','COS','Permanent','Casual','Other','Hired','For Review','Withdrawn','Superseded') NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('Active','Disabled') NOT NULL DEFAULT 'Active',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_id_sequences`
--

CREATE TABLE `care_jf_id_sequences` (
  `sequence_name` varchar(50) NOT NULL,
  `year_key` int(11) NOT NULL,
  `last_value` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_job_vacancies`
--

CREATE TABLE `care_jf_job_vacancies` (
  `id` int(10) UNSIGNED NOT NULL,
  `agency_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT 'Job Available',
  `position` varchar(150) NOT NULL,
  `job_level` enum('Plantilla Level 1','Plantilla Level 2','Job Order','COS') NOT NULL,
  `salary_grade` varchar(100) DEFAULT NULL,
  `occupational_option` varchar(150) DEFAULT NULL,
  `vacant_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `status` enum('Active','Disabled','Filled','Closed') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_login_throttle`
--

CREATE TABLE `care_jf_login_throttle` (
  `id` int(10) UNSIGNED NOT NULL,
  `identifier` varchar(191) NOT NULL COMMENT 'e.g. user:<username> or ip:<address>',
  `attempt_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_partner_agencies`
--

CREATE TABLE `care_jf_partner_agencies` (
  `id` int(10) UNSIGNED NOT NULL,
  `employer_id` varchar(20) DEFAULT NULL,
  `agency_name` varchar(200) NOT NULL,
  `address` varchar(255) NOT NULL,
  `contact_person` varchar(150) NOT NULL DEFAULT '',
  `contact_no` varchar(20) NOT NULL DEFAULT '',
  `email` varchar(150) DEFAULT NULL,
  `status` enum('Active','Disabled') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_users`
--

CREATE TABLE `care_jf_users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role` enum('Administrator','Employee','Viewer','Partner Agency') NOT NULL DEFAULT 'Viewer',
  `status` enum('Pending','Active','Disabled') NOT NULL DEFAULT 'Active',
  `agency_id` int(10) UNSIGNED DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `care_jf_users`
--

INSERT INTO `care_jf_users` (`id`, `username`, `password`, `full_name`, `role`, `status`, `agency_id`, `is_primary`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2b$10$rxKO5rVyUKtNmuhu1S1aDuFq8GeiR.IQ9rK81QB6cGvD9F5vbKv1i', 'SYSTEM ADMINISTRATOR', 'Administrator', 'Active', NULL, 0, 1, '2026-09-07 01:50:59', '2026-09-07 08:01:26');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `care_jf_applicants`
--
ALTER TABLE `care_jf_applicants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `applicant_code` (`applicant_code`),
  ADD KEY `idx_last_name` (`last_name`),
  ADD KEY `idx_first_name` (`first_name`),
  ADD KEY `idx_contact` (`contact_number`),
  ADD KEY `idx_email` (`email_address`);
ALTER TABLE `care_jf_applicants` ADD FULLTEXT KEY `ft_name` (`last_name`,`first_name`,`middle_name`);

--
-- Indexes for table `care_jf_audit_logs`
--
ALTER TABLE `care_jf_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_table` (`table_name`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `care_jf_employment_records`
--
ALTER TABLE `care_jf_employment_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_applicant` (`applicant_id`),
  ADD KEY `idx_agency` (`agency_id`),
  ADD KEY `idx_status` (`employment_status`),
  ADD KEY `idx_record_status` (`status`),
  ADD KEY `fk_employment_vacancy` (`vacancy_id`);

--
-- Indexes for table `care_jf_id_sequences`
--
ALTER TABLE `care_jf_id_sequences`
  ADD PRIMARY KEY (`sequence_name`,`year_key`);

--
-- Indexes for table `care_jf_job_vacancies`
--
ALTER TABLE `care_jf_job_vacancies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vacancy_agency` (`agency_id`),
  ADD KEY `idx_vacancy_status` (`status`);

--
-- Indexes for table `care_jf_login_throttle`
--
ALTER TABLE `care_jf_login_throttle`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_login_throttle_identifier` (`identifier`);

--
-- Indexes for table `care_jf_partner_agencies`
--
ALTER TABLE `care_jf_partner_agencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employer_id` (`employer_id`),
  ADD KEY `idx_agency_status` (`status`);

--
-- Indexes for table `care_jf_users`
--
ALTER TABLE `care_jf_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_users_agency` (`agency_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `care_jf_applicants`
--
ALTER TABLE `care_jf_applicants`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `care_jf_audit_logs`
--
ALTER TABLE `care_jf_audit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `care_jf_employment_records`
--
ALTER TABLE `care_jf_employment_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `care_jf_job_vacancies`
--
ALTER TABLE `care_jf_job_vacancies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `care_jf_login_throttle`
--
ALTER TABLE `care_jf_login_throttle`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `care_jf_partner_agencies`
--
ALTER TABLE `care_jf_partner_agencies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `care_jf_users`
--
ALTER TABLE `care_jf_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `care_jf_audit_logs`
--
ALTER TABLE `care_jf_audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `care_jf_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `care_jf_employment_records`
--
ALTER TABLE `care_jf_employment_records`
  ADD CONSTRAINT `fk_employment_agency` FOREIGN KEY (`agency_id`) REFERENCES `care_jf_partner_agencies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_employment_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `care_jf_applicants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_employment_vacancy` FOREIGN KEY (`vacancy_id`) REFERENCES `care_jf_job_vacancies` (`id`);

--
-- Constraints for table `care_jf_job_vacancies`
--
ALTER TABLE `care_jf_job_vacancies`
  ADD CONSTRAINT `fk_vacancy_agency` FOREIGN KEY (`agency_id`) REFERENCES `care_jf_partner_agencies` (`id`);

--
-- Constraints for table `care_jf_users`
--
ALTER TABLE `care_jf_users`
  ADD CONSTRAINT `fk_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `care_jf_partner_agencies` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
