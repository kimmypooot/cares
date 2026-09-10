-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 10, 2026 at 11:56 PM
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
-- Table structure for table `care_jf_agency_services`
--

CREATE TABLE `care_jf_agency_services` (
  `id` int(10) UNSIGNED NOT NULL,
  `agency_id` int(10) UNSIGNED NOT NULL,
  `service_name` varchar(200) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `status` enum('Active','Disabled') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `care_jf_agency_services`
--

INSERT INTO `care_jf_agency_services` (`id`, `agency_id`, `service_name`, `description`, `status`, `created_at`, `updated_at`) VALUES
(5, 1, 'DOCUMENT AUTHENTICATION', 'AUTHENTICATION OF CIVIL SERVICE ELIGIBILITY DOCUMENTS', 'Disabled', '2026-09-10 14:31:18', '2026-09-10 16:08:19'),
(6, 4, 'ISSUANCE OF CERTIFIED TRUE COPY OF BIRTH, DEATH AND MARRIAGE CERTIFICATE', NULL, 'Active', '2026-09-10 16:07:34', '2026-09-10 16:07:34'),
(7, 4, 'INQUIRIES AND REQUIREMENTS SUBMISSION', NULL, 'Active', '2026-09-10 16:07:45', '2026-09-10 16:07:45'),
(8, 1, 'ISSUANCE OF CERTIFICATE OF ELIGIBILITIES', NULL, 'Active', '2026-09-10 16:08:32', '2026-09-10 16:08:32'),
(9, 1, 'AUTHENTICATION OF CERTIFICATE OF ELIGIBILITIES', NULL, 'Active', '2026-09-10 16:08:40', '2026-09-10 16:08:40'),
(10, 1, 'RESPONSE TO QUERY ON CSC MATTERS', NULL, 'Active', '2026-09-10 16:08:49', '2026-09-10 16:08:49'),
(11, 10, 'RECEIVING APPLICATIONS FOR GIP', NULL, 'Active', '2026-09-10 16:10:19', '2026-09-10 16:10:19'),
(12, 10, 'ANSWER INQUIRIES ON THE DILP PROGRAM', NULL, 'Active', '2026-09-10 16:10:34', '2026-09-10 16:10:34'),
(13, 10, 'ANSWER INQUIRIES ON THE TUPAD PROGRAM', NULL, 'Active', '2026-09-10 16:10:42', '2026-09-10 16:10:42'),
(14, 18, 'FREE NOTARIZATION OF PERSONAL DATA SHEET', NULL, 'Active', '2026-09-10 16:23:18', '2026-09-10 16:23:18'),
(15, 18, 'FREE LEGAL ADVICE', NULL, 'Active', '2026-09-10 16:23:25', '2026-09-10 16:23:25'),
(16, 19, 'MDR AND ID PRINTING', NULL, 'Active', '2026-09-10 16:28:34', '2026-09-10 16:28:34'),
(17, 19, 'RECORD AMENDMENT/UPDATE', NULL, 'Active', '2026-09-10 16:28:43', '2026-09-10 16:28:43'),
(18, 19, 'YAKAP REGISTRATION', NULL, 'Active', '2026-09-10 16:28:50', '2026-09-10 16:28:50'),
(19, 19, 'IEC MATERIALS', NULL, 'Active', '2026-09-10 16:28:57', '2026-09-10 16:28:57'),
(20, 17, 'FREE HAIRCUT', NULL, 'Active', '2026-09-10 16:30:08', '2026-09-10 16:30:08'),
(21, 17, 'FREE MASSAGE', NULL, 'Active', '2026-09-10 16:30:16', '2026-09-10 16:30:16'),
(22, 17, 'FREE NAIL CARE (MANICURE/PEDICURE)', NULL, 'Active', '2026-09-10 16:30:23', '2026-09-10 16:30:23'),
(23, 17, 'RENEWAL OF NATIONAL CERTIFICATE (NC)', NULL, 'Active', '2026-09-10 16:30:29', '2026-09-10 16:30:29'),
(24, 17, 'ASSISTANCE TO THE AVAILABILITY OF NATIONAL COMPETENCY ASSESSMENT SCHEDULE', NULL, 'Active', '2026-09-10 16:30:39', '2026-09-10 16:30:39'),
(25, 17, 'AVAILABILITY OF SCHOLARSHIPS FOR SKILLS TRAINING', NULL, 'Active', '2026-09-10 16:30:46', '2026-09-10 16:30:46'),
(26, 3, 'ISSUANCE OF IEC MATERIALS OF CIVIL REGISTRY DOCUMENTS', NULL, 'Active', '2026-09-10 16:31:42', '2026-09-10 16:31:42'),
(27, 3, 'NATIONAL ID', NULL, 'Active', '2026-09-10 16:31:51', '2026-09-10 16:31:51'),
(28, 20, 'ONLINE SSS NUMBER ASSISTANCE', NULL, 'Active', '2026-09-10 16:34:01', '2026-09-10 16:34:01'),
(29, 20, 'WEB REGISTRATION', NULL, 'Active', '2026-09-10 16:34:09', '2026-09-10 16:34:09');

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
(1, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 00:58:27'),
(2, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '::1', '2026-09-10 00:58:36'),
(3, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 01:48:26'),
(4, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 02:52:40'),
(5, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 02:53:00'),
(6, NULL, 'PARTNER_AGENCY_REGISTER', 'care_jf_partner_agencies', 1, 'Partner Agency registration: CIVIL SERVICE COMMISSION RO VIII (user: agtb_esd), pending approval', '::1', '2026-09-10 02:53:38'),
(7, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 02:53:54'),
(8, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '::1', '2026-09-10 02:54:06'),
(9, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 1, 'Employer ID EMP-2026-0000001 generated', '::1', '2026-09-10 02:54:11'),
(10, 1, 'PARTNER_AGENCY_ACTIVATE', 'care_jf_users', 2, 'Partner Agency account activated', '::1', '2026-09-10 02:54:11'),
(11, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 02:54:23'),
(12, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 02:54:46'),
(13, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 02:54:55'),
(14, NULL, 'CREATE', 'care_jf_applicants', 1, 'Public self-registration: applicant APP-202609-000002', '::1', '2026-09-10 02:55:58'),
(15, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 02:56:40'),
(16, 2, 'APPLICANT_AUTO_TAGGED_QR', 'care_jf_employment_records', 1, 'Applicant APP-202609-000002 auto-tagged via QR camera scan by CIVIL SERVICE COMMISSION RO VIII', '::1', '2026-09-10 02:57:03'),
(17, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 02:57:23'),
(18, NULL, 'LOGIN', 'care_jf_users', 3, 'User logged in', '::1', '2026-09-10 02:58:19'),
(19, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 02:59:40'),
(20, NULL, 'PARTNER_AGENCY_REGISTER', 'care_jf_partner_agencies', 3, 'Partner Agency registration: PHILIPPINE STATISTICS OFFICE RO VIII (user: psa_01), pending approval', '::1', '2026-09-10 03:00:10'),
(21, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 03:00:23'),
(22, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '::1', '2026-09-10 03:00:29'),
(23, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 3, 'Employer ID EMP-2026-0000002 generated', '::1', '2026-09-10 03:00:33'),
(24, 1, 'PARTNER_AGENCY_ACTIVATE', 'care_jf_users', 4, 'Partner Agency account activated', '::1', '2026-09-10 03:00:33'),
(25, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 03:00:38'),
(26, 4, 'LOGIN', 'care_jf_users', 4, 'User logged in', '::1', '2026-09-10 03:00:54'),
(27, 4, 'APPLICANT_AUTO_TAGGED_QR', 'care_jf_employment_records', 7, 'Applicant APP-202609-000002 auto-tagged via QR camera scan by PHILIPPINE STATISTICS OFFICE RO VIII', '::1', '2026-09-10 03:01:41'),
(28, 4, 'LOGOUT', 'care_jf_users', 4, 'User logged out', '::1', '2026-09-10 03:01:50'),
(29, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 03:01:55'),
(30, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 03:02:34'),
(31, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 03:02:44'),
(32, 2, 'VACANCY_CREATE', 'care_jf_job_vacancies', 3, 'Created vacancy: ACCOUNTANT I', '::1', '2026-09-10 03:03:13'),
(33, 2, 'APPLICANT_REVIEW_SUPERSEDED', 'care_jf_employment_records', 7, 'Superseded by another confirmed hire', '::1', '2026-09-10 03:03:37'),
(34, 2, 'APPLICANT_HIRED', 'care_jf_employment_records', 1, 'Applicant APP-202609-000002 hired', '::1', '2026-09-10 03:03:37'),
(35, 2, 'VACANCY_DECREMENT', 'care_jf_job_vacancies', 3, 'Vacancy decremented (hire: APP-202609-000002)', '::1', '2026-09-10 03:03:37'),
(36, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 03:04:13'),
(37, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 03:04:22'),
(38, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 03:05:03'),
(39, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 03:08:22'),
(40, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 03:10:53'),
(41, NULL, 'CREATE', 'care_jf_applicants', 8, 'Public self-registration: applicant APP-202609-000003', '::1', '2026-09-10 03:12:28'),
(42, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 03:12:58'),
(43, 2, 'APPLICANT_AUTO_TAGGED_QR', 'care_jf_employment_records', 8, 'Applicant APP-202609-000003 auto-tagged via confirmed manual code entry by CIVIL SERVICE COMMISSION RO VIII', '::1', '2026-09-10 03:14:18'),
(44, 2, 'VACANCY_CREATE', 'care_jf_job_vacancies', 4, 'Created vacancy: Computer Technician', '::1', '2026-09-10 03:14:55'),
(45, 2, 'APPLICANT_REVIEW_WITHDRAWN', 'care_jf_employment_records', 8, 'Applicant APP-202609-000003 review tag withdrawn', '::1', '2026-09-10 03:15:15'),
(46, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 03:19:37'),
(47, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 03:36:51'),
(48, 4, 'LOGIN', 'care_jf_users', 4, 'User logged in', '::1', '2026-09-10 03:37:03'),
(49, 4, 'LOGOUT', 'care_jf_users', 4, 'User logged out', '::1', '2026-09-10 03:37:56'),
(50, NULL, 'CREATE', 'care_jf_applicants', 9, 'Public self-registration: applicant APP-202609-000004', '192.168.108.210', '2026-09-10 04:34:17'),
(51, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '192.168.108.210', '2026-09-10 04:35:11'),
(52, 2, 'APPLICANT_AUTO_TAGGED_QR', 'care_jf_employment_records', 9, 'Applicant APP-202609-000004 auto-tagged via confirmed manual code entry by CIVIL SERVICE COMMISSION RO VIII', '192.168.108.210', '2026-09-10 04:36:09'),
(53, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.108.210', '2026-09-10 05:12:19'),
(54, 1, 'DISABLE', 'care_jf_partner_agencies', 1, 'Partner Agency Disabled', '192.168.108.210', '2026-09-10 05:12:47'),
(55, 1, 'ENABLE', 'care_jf_partner_agencies', 1, 'Partner Agency Active', '192.168.108.210', '2026-09-10 05:12:54'),
(56, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.108.210', '2026-09-10 05:28:00'),
(57, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '192.168.108.210', '2026-09-10 05:28:58'),
(58, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 05:30:03'),
(59, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '192.168.108.211', '2026-09-10 05:32:34'),
(60, 2, 'APPLICANT_REVIEW_WITHDRAWN', 'care_jf_employment_records', 9, 'Applicant APP-202609-000004 review tag withdrawn', '192.168.108.210', '2026-09-10 05:34:35'),
(61, 2, 'APPLICANT_AUTO_TAGGED_QR', 'care_jf_employment_records', 10, 'Applicant APP-202609-000004 auto-tagged via QR camera scan by CIVIL SERVICE COMMISSION RO VIII', '192.168.108.210', '2026-09-10 05:34:50'),
(62, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 06:14:15'),
(63, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 06:41:39'),
(64, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 06:41:50'),
(65, 1, 'CREATE', 'care_jf_partner_agencies', 4, 'Created Partner Agency: CITY GOVERNMENT OF TACLOBAN (bulk import)', NULL, '2026-09-10 06:49:43'),
(66, 1, 'CREATE', 'care_jf_users', 5, 'Created and activated Partner Agency account city_government_of_tacloban for CITY GOVERNMENT OF TACLOBAN (bulk import)', NULL, '2026-09-10 06:49:43'),
(67, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 4, 'Employer ID EMP-2026-0000003 generated (bulk import)', NULL, '2026-09-10 06:49:43'),
(68, 1, 'CREATE', 'care_jf_partner_agencies', 5, 'Created Partner Agency: DEPARTMENT OF AGRARIAN REFORM REGION VIII (bulk import)', NULL, '2026-09-10 06:49:43'),
(69, 1, 'CREATE', 'care_jf_users', 6, 'Created and activated Partner Agency account department_of_agrarian_reform_ for DEPARTMENT OF AGRARIAN REFORM REGION VIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(70, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 5, 'Employer ID EMP-2026-0000004 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(71, 1, 'CREATE', 'care_jf_partner_agencies', 6, 'Created Partner Agency: DEPARTMENT OF AGRICULTURE REGIONAL FIELD OFFICE VIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(72, 1, 'CREATE', 'care_jf_users', 7, 'Created and activated Partner Agency account department_of_agriculture_regi for DEPARTMENT OF AGRICULTURE REGIONAL FIELD OFFICE VIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(73, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 6, 'Employer ID EMP-2026-0000005 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(74, 1, 'CREATE', 'care_jf_partner_agencies', 7, 'Created Partner Agency: DEPARTMENT OF ENVIRONMENT AND NATURAL RESOURCES (bulk import)', NULL, '2026-09-10 06:49:44'),
(75, 1, 'CREATE', 'care_jf_users', 8, 'Created and activated Partner Agency account department_of_environment_and_ for DEPARTMENT OF ENVIRONMENT AND NATURAL RESOURCES (bulk import)', NULL, '2026-09-10 06:49:44'),
(76, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 7, 'Employer ID EMP-2026-0000006 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(77, 1, 'CREATE', 'care_jf_partner_agencies', 8, 'Created Partner Agency: DEPED SCHOOLS DIVISION OF TACLOBAN CITY (bulk import)', NULL, '2026-09-10 06:49:44'),
(78, 1, 'CREATE', 'care_jf_users', 9, 'Created and activated Partner Agency account deped_schools_division_of_tacl for DEPED SCHOOLS DIVISION OF TACLOBAN CITY (bulk import)', NULL, '2026-09-10 06:49:44'),
(79, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 8, 'Employer ID EMP-2026-0000007 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(80, 1, 'CREATE', 'care_jf_partner_agencies', 9, 'Created Partner Agency: DOH - TREATMENT AND REHABILITATION CENTER, DULAG (bulk import)', NULL, '2026-09-10 06:49:44'),
(81, 1, 'CREATE', 'care_jf_users', 10, 'Created and activated Partner Agency account doh_treatment_and_rehabilitati for DOH - TREATMENT AND REHABILITATION CENTER, DULAG (bulk import)', NULL, '2026-09-10 06:49:44'),
(82, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 9, 'Employer ID EMP-2026-0000008 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(83, 1, 'CREATE', 'care_jf_partner_agencies', 10, 'Created Partner Agency: DEPARTMENT OF LABOR AND EMPLOYMENT (bulk import)', NULL, '2026-09-10 06:49:44'),
(84, 1, 'CREATE', 'care_jf_users', 11, 'Created and activated Partner Agency account department_of_labor_and_employ for DEPARTMENT OF LABOR AND EMPLOYMENT (bulk import)', NULL, '2026-09-10 06:49:44'),
(85, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 10, 'Employer ID EMP-2026-0000009 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(86, 1, 'CREATE', 'care_jf_partner_agencies', 11, 'Created Partner Agency: DSWD FIELD OFFICE VIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(87, 1, 'CREATE', 'care_jf_users', 12, 'Created and activated Partner Agency account dswd_field_office_viii for DSWD FIELD OFFICE VIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(88, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 11, 'Employer ID EMP-2026-0000010 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(89, 1, 'CREATE', 'care_jf_partner_agencies', 12, 'Created Partner Agency: EASTERN VISAYAS MEDICAL CENTER (bulk import)', NULL, '2026-09-10 06:49:44'),
(90, 1, 'CREATE', 'care_jf_users', 13, 'Created and activated Partner Agency account eastern_visayas_medical_center for EASTERN VISAYAS MEDICAL CENTER (bulk import)', NULL, '2026-09-10 06:49:44'),
(91, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 12, 'Employer ID EMP-2026-0000011 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(92, 1, 'CREATE', 'care_jf_partner_agencies', 13, 'Created Partner Agency: EASTERN VISAYAS STATE UNIVERSITY (bulk import)', NULL, '2026-09-10 06:49:44'),
(93, 1, 'CREATE', 'care_jf_users', 14, 'Created and activated Partner Agency account eastern_visayas_state_universi for EASTERN VISAYAS STATE UNIVERSITY (bulk import)', NULL, '2026-09-10 06:49:44'),
(94, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 13, 'Employer ID EMP-2026-0000012 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(95, 1, 'CREATE', 'care_jf_partner_agencies', 14, 'Created Partner Agency: LAND TRANSPORTATION OFFICE, ROVIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(96, 1, 'CREATE', 'care_jf_users', 15, 'Created and activated Partner Agency account land_transportation_office_rov for LAND TRANSPORTATION OFFICE, ROVIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(97, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 14, 'Employer ID EMP-2026-0000013 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(98, 1, 'CREATE', 'care_jf_partner_agencies', 15, 'Created Partner Agency: MGO-BURAUEN, LEYTE (bulk import)', NULL, '2026-09-10 06:49:44'),
(99, 1, 'CREATE', 'care_jf_users', 16, 'Created and activated Partner Agency account mgo_burauen_leyte for MGO-BURAUEN, LEYTE (bulk import)', NULL, '2026-09-10 06:49:44'),
(100, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 15, 'Employer ID EMP-2026-0000014 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(101, 1, 'CREATE', 'care_jf_partner_agencies', 16, 'Created Partner Agency: PALOMPON INSTITUTE OF TECHNOLOGY (bulk import)', NULL, '2026-09-10 06:49:44'),
(102, 1, 'CREATE', 'care_jf_users', 17, 'Created and activated Partner Agency account palompon_institute_of_technolo for PALOMPON INSTITUTE OF TECHNOLOGY (bulk import)', NULL, '2026-09-10 06:49:44'),
(103, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 16, 'Employer ID EMP-2026-0000015 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(104, 1, 'CREATE', 'care_jf_partner_agencies', 17, 'Created Partner Agency: TECHNICAL EDUCATION AND SKILLS DEVELOPMENT AUTHORITY REGION VIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(105, 1, 'CREATE', 'care_jf_users', 18, 'Created and activated Partner Agency account technical_education_and_skills for TECHNICAL EDUCATION AND SKILLS DEVELOPMENT AUTHORITY REGION VIII (bulk import)', NULL, '2026-09-10 06:49:44'),
(106, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 17, 'Employer ID EMP-2026-0000016 generated (bulk import)', NULL, '2026-09-10 06:49:44'),
(107, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 5, 'Created vacancy: Laboratory Technician I (bulk import)', NULL, '2026-09-10 06:49:44'),
(108, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 6, 'Created vacancy: Administrative Assistant II (Public Relations Assistant) (bulk import)', NULL, '2026-09-10 06:49:44'),
(109, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 7, 'Created vacancy: Social Welfare Officer I (bulk import)', NULL, '2026-09-10 06:49:44'),
(110, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 8, 'Created vacancy: Driver (bulk import)', NULL, '2026-09-10 06:49:44'),
(111, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 9, 'Created vacancy: Utility (bulk import)', NULL, '2026-09-10 06:49:44'),
(112, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 10, 'Created vacancy: Administrative Aide (bulk import)', NULL, '2026-09-10 06:49:44'),
(113, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 11, 'Created vacancy: Administrative Assistant III (bulk import)', NULL, '2026-09-10 06:49:44'),
(114, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 12, 'Created vacancy: Agrarian Reform Program Officer II (bulk import)', NULL, '2026-09-10 06:49:44'),
(115, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 13, 'Created vacancy: Legal Assistant II (bulk import)', NULL, '2026-09-10 06:49:44'),
(116, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 14, 'Created vacancy: Senior Agrarian Reform Program Technologist (bulk import)', NULL, '2026-09-10 06:49:44'),
(117, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 15, 'Created vacancy: Agrarian Reform Program Officer I (bulk import)', NULL, '2026-09-10 06:49:44'),
(118, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 16, 'Created vacancy: Agrarian Reform Program Technologist (bulk import)', NULL, '2026-09-10 06:49:44'),
(119, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 17, 'Created vacancy: Cartographer II (bulk import)', NULL, '2026-09-10 06:49:44'),
(120, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 18, 'Created vacancy: Senior Administrative Assistant I (bulk import)', NULL, '2026-09-10 06:49:44'),
(121, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 19, 'Created vacancy: Project Assistant IV (bulk import)', NULL, '2026-09-10 06:49:44'),
(122, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 20, 'Created vacancy: Utility Worker II (Plumber) (bulk import)', NULL, '2026-09-10 06:49:44'),
(123, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 21, 'Created vacancy: Project Assistant I (bulk import)', NULL, '2026-09-10 06:49:44'),
(124, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 22, 'Created vacancy: Administrative Assistant II (Artist Illustrator II) (bulk import)', NULL, '2026-09-10 06:49:44'),
(125, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 23, 'Created vacancy: Information Systems Analyst III (bulk import)', NULL, '2026-09-10 06:49:44'),
(126, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 24, 'Created vacancy: Statistician II (bulk import)', NULL, '2026-09-10 06:49:44'),
(127, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 25, 'Created vacancy: Administrative Officer IV(Management and Audit Analyst II) (bulk import)', NULL, '2026-09-10 06:49:44'),
(128, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 26, 'Created vacancy: Administrative Assistant I (bulk import)', NULL, '2026-09-10 06:49:44'),
(129, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 27, 'Created vacancy: Administrative Officer V (Budget Officer III) (bulk import)', NULL, '2026-09-10 06:49:44'),
(130, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 28, 'Created vacancy: Administrative Assistant III (Senior Bookkeeper) (bulk import)', NULL, '2026-09-10 06:49:44'),
(131, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 29, 'Created vacancy: Administrative Assistant II (Accounting Clerk III) (bulk import)', NULL, '2026-09-10 06:49:44'),
(132, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 30, 'Created vacancy: Administrative Assistant II (Budgeting Assistant) (bulk import)', NULL, '2026-09-10 06:49:44'),
(133, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 31, 'Created vacancy: Administrative Officer IV (Human Resource Management Officer II) (bulk import)', NULL, '2026-09-10 06:49:44'),
(134, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 32, 'Created vacancy: Administrative Officer II (Administrative Officer I) (bulk import)', NULL, '2026-09-10 06:49:44'),
(135, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 33, 'Created vacancy: Administrative Assistant II (Clerk IV) (bulk import)', NULL, '2026-09-10 06:49:44'),
(136, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 34, 'Created vacancy: Administrative Assistant II (Property Custodian) (bulk import)', NULL, '2026-09-10 06:49:44'),
(137, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 35, 'Created vacancy: Administrative Assistant I (Computer Operator I) (bulk import)', NULL, '2026-09-10 06:49:44'),
(138, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 36, 'Created vacancy: Legal Assistant II (bulk import)', NULL, '2026-09-10 06:49:44'),
(139, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 37, 'Created vacancy: Farm Supervisor (bulk import)', NULL, '2026-09-10 06:49:44'),
(140, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 38, 'Created vacancy: Engineer III (bulk import)', NULL, '2026-09-10 06:49:44'),
(141, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 39, 'Created vacancy: Forest Management Specialist II (bulk import)', NULL, '2026-09-10 06:49:44'),
(142, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 40, 'Created vacancy: Cartographer II (bulk import)', NULL, '2026-09-10 06:49:44'),
(143, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 41, 'Created vacancy: Mathematician Aide II (bulk import)', NULL, '2026-09-10 06:49:44'),
(144, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 42, 'Created vacancy: Administrative Aide VI (Clerk III) (bulk import)', NULL, '2026-09-10 06:49:44'),
(145, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 43, 'Created vacancy: Cartographer I (bulk import)', NULL, '2026-09-10 06:49:44'),
(146, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 44, 'Created vacancy: Mathematician Aide I (bulk import)', NULL, '2026-09-10 06:49:44'),
(147, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 45, 'Created vacancy: Engineering Aide (bulk import)', NULL, '2026-09-10 06:49:44'),
(148, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 46, 'Created vacancy: Development Management Officer IV (bulk import)', NULL, '2026-09-10 06:49:44'),
(149, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 47, 'Created vacancy: Economist I (bulk import)', NULL, '2026-09-10 06:49:44'),
(150, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 48, 'Created vacancy: Administrative Officer I (Records Officer I) (bulk import)', NULL, '2026-09-10 06:49:44'),
(151, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 49, 'Created vacancy: Forest Ranger (bulk import)', NULL, '2026-09-10 06:49:44'),
(152, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 50, 'Created vacancy: Administrative Assistant III (bulk import)', NULL, '2026-09-10 06:49:44'),
(153, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 51, 'Created vacancy: ADMINISTRATIVE OFFICER V (bulk import)', NULL, '2026-09-10 06:49:44'),
(154, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 52, 'Created vacancy: Administrative Officer V/ Budget Officer III (bulk import)', NULL, '2026-09-10 06:49:44'),
(155, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 53, 'Created vacancy: Administrative Officer V/ Cashier III (bulk import)', NULL, '2026-09-10 06:49:44'),
(156, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 54, 'Created vacancy: GIP (bulk import)', NULL, '2026-09-10 06:49:44'),
(157, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 55, 'Created vacancy: SOCIAL WELFARE OFFICER III (bulk import)', NULL, '2026-09-10 06:49:44'),
(158, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 56, 'Created vacancy: SOCIAL WELFARE OFFICER II (bulk import)', NULL, '2026-09-10 06:49:44'),
(159, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 57, 'Created vacancy: PROJECT DEVELOPMENT OFFICER III (Area Coordinator) (bulk import)', NULL, '2026-09-10 06:49:44'),
(160, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 58, 'Created vacancy: PROJECT DEVELOPMENT OFFICER II (Community Empowerment Facilitator) (bulk import)', NULL, '2026-09-10 06:49:44'),
(161, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 59, 'Created vacancy: TECHNICAL FACILITATOR (bulk import)', NULL, '2026-09-10 06:49:44'),
(162, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 60, 'Created vacancy: Medical Officer IV (bulk import)', NULL, '2026-09-10 06:49:44'),
(163, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 61, 'Created vacancy: Medical Officer III (bulk import)', NULL, '2026-09-10 06:49:44'),
(164, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 62, 'Created vacancy: Psychologist II (bulk import)', NULL, '2026-09-10 06:49:44'),
(165, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 63, 'Created vacancy: Occupational Therapist II (bulk import)', NULL, '2026-09-10 06:49:44'),
(166, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 64, 'Created vacancy: Midwife III (bulk import)', NULL, '2026-09-10 06:49:44'),
(167, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 65, 'Created vacancy: Medical Equipment Technician IV (bulk import)', NULL, '2026-09-10 06:49:44'),
(168, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 66, 'Created vacancy: Pharmacist I (bulk import)', NULL, '2026-09-10 06:49:44'),
(169, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 67, 'Created vacancy: Occupational Therapist I (bulk import)', NULL, '2026-09-10 06:49:44'),
(170, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 68, 'Created vacancy: Occupational Therapist I (bulk import)', NULL, '2026-09-10 06:49:44'),
(171, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 69, 'Created vacancy: Health Education And Promotion Officer I (bulk import)', NULL, '2026-09-10 06:49:44'),
(172, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 70, 'Created vacancy: Dental Hygienist (bulk import)', NULL, '2026-09-10 06:49:44'),
(173, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 71, 'Created vacancy: Speech Therapist I (Part-Time) (bulk import)', NULL, '2026-09-10 06:49:44'),
(174, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 72, 'Created vacancy: Speech Therapist I (Part-Time) (bulk import)', NULL, '2026-09-10 06:49:44'),
(175, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 73, 'Created vacancy: Ward Assistant (bulk import)', NULL, '2026-09-10 06:49:44'),
(176, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 74, 'Created vacancy: Associate Professor V (Guidance and Counselling) (bulk import)', NULL, '2026-09-10 06:49:44'),
(177, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 75, 'Created vacancy: Instructor III (Guidance and Counselling) (bulk import)', NULL, '2026-09-10 06:49:44'),
(178, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 76, 'Created vacancy: Instructor III (Electronics Engineering) (bulk import)', NULL, '2026-09-10 06:49:44'),
(179, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 77, 'Created vacancy: Instructor I (Industrial Engineering) (bulk import)', NULL, '2026-09-10 06:49:44'),
(180, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 78, 'Created vacancy: Instructor I (Geodetic Engineering) (bulk import)', NULL, '2026-09-10 06:49:44'),
(181, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 79, 'Created vacancy: Instructor I (Natural Science) (bulk import)', NULL, '2026-09-10 06:49:44'),
(182, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 80, 'Created vacancy: Instructor I (Economics) (bulk import)', NULL, '2026-09-10 06:49:44'),
(183, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 81, 'Created vacancy: Instructor I (Culture and Arts) (bulk import)', NULL, '2026-09-10 06:49:44'),
(184, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 82, 'Created vacancy: Instructor I (Science) (bulk import)', NULL, '2026-09-10 06:49:44'),
(185, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 83, 'Created vacancy: Instructor I (Accounting) (bulk import)', NULL, '2026-09-10 06:49:44'),
(186, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 84, 'Created vacancy: Instructor I (Entreprenuership) (bulk import)', NULL, '2026-09-10 06:49:44'),
(187, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 85, 'Created vacancy: Instructor I (Nutrition and Dietitics) (bulk import)', NULL, '2026-09-10 06:49:44'),
(188, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 86, 'Created vacancy: Instructor I (Elem. Education) (bulk import)', NULL, '2026-09-10 06:49:44'),
(189, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 87, 'Created vacancy: Guidance Counselor II (bulk import)', NULL, '2026-09-10 06:49:44'),
(190, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 88, 'Created vacancy: Guidance Counselor I (bulk import)', NULL, '2026-09-10 06:49:44'),
(191, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 89, 'Created vacancy: Dental Aide (bulk import)', NULL, '2026-09-10 06:49:44'),
(192, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 90, 'Created vacancy: Medical Aide (bulk import)', NULL, '2026-09-10 06:49:44'),
(193, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 91, 'Created vacancy: Admin. Aide I (bulk import)', NULL, '2026-09-10 06:49:44'),
(194, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 92, 'Created vacancy: Administrative Aide IV (Clerk II) (bulk import)', NULL, '2026-09-10 06:49:44'),
(195, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 93, 'Created vacancy: Administrative Aide VI (Clerk III) (bulk import)', NULL, '2026-09-10 06:49:44'),
(196, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 94, 'Created vacancy: Administrative Officer III (Supply Officer II) (bulk import)', NULL, '2026-09-10 06:49:44'),
(197, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 95, 'Created vacancy: ADMINISTRATIVE OFFICER V (Administrative Officer III) (bulk import)', NULL, '2026-09-10 06:49:44'),
(198, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 96, 'Created vacancy: DORMITORY MANAGER III (bulk import)', NULL, '2026-09-10 06:49:44'),
(199, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 97, 'Created vacancy: GUIDANCE COUNSELOR II (bulk import)', NULL, '2026-09-10 06:49:44'),
(200, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 98, 'Created vacancy: GUIDANCE COUNSELOR I (bulk import)', NULL, '2026-09-10 06:49:44'),
(201, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 99, 'Created vacancy: INSTRUCTOR I (BA Communication) (bulk import)', NULL, '2026-09-10 06:49:44'),
(202, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 100, 'Created vacancy: INSTRUCTOR I (Home Economics) (bulk import)', NULL, '2026-09-10 06:49:44'),
(203, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 101, 'Created vacancy: INSTRUCTOR I (Librarian) (bulk import)', NULL, '2026-09-10 06:49:44'),
(204, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 102, 'Created vacancy: TEACHING PERSONNEL UNDER CONTRACT OF SERVICE (bulk import)', NULL, '2026-09-10 06:49:44'),
(205, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 103, 'Created vacancy: Accountant I (bulk import)', NULL, '2026-09-10 06:49:44'),
(206, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 104, 'Created vacancy: Registration Officer II (bulk import)', NULL, '2026-09-10 06:49:44'),
(207, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 105, 'Created vacancy: Instructor I (TESDAB-INST1-2-2020) (bulk import)', NULL, '2026-09-10 06:49:44'),
(208, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 106, 'Created vacancy: Instructor I (TESDAB-INST1-540004-2022) (bulk import)', NULL, '2026-09-10 06:49:44'),
(209, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 107, 'Created vacancy: Guidance Counselor III (bulk import)', NULL, '2026-09-10 06:49:44'),
(210, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 108, 'Created vacancy: Administrative Officer V (Human Resource Management Officer III) (bulk import)', NULL, '2026-09-10 06:49:44'),
(211, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 109, 'Created vacancy: Technical Education and Skills Development Specialist II (bulk import)', NULL, '2026-09-10 06:49:44'),
(212, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 110, 'Created vacancy: Administrative Officer II (Financial Analyst I) (bulk import)', NULL, '2026-09-10 06:49:44'),
(213, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 111, 'Created vacancy: Guidance Counselor I (bulk import)', NULL, '2026-09-10 06:49:44'),
(214, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 112, 'Created vacancy: Technical Education and Skills Development Specialist I (bulk import)', NULL, '2026-09-10 06:49:44'),
(215, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 113, 'Created vacancy: Administrative Officer IV (Human Resource Management Officer II) (bulk import)', NULL, '2026-09-10 06:49:44'),
(216, 1, 'VACANCY_CREATE', 'care_jf_job_vacancies', 114, 'Created vacancy: Assistant Professor I (TESDAB-AP1-182-2017) (bulk import)', NULL, '2026-09-10 06:49:44'),
(217, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 07:11:03'),
(218, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 07:25:18'),
(219, 1, 'VACANCY_UPDATE', 'care_jf_job_vacancies', 59, 'Updated vacancy: TECHNICAL FACILITATOR', '::1', '2026-09-10 07:26:43'),
(221, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 08:00:49'),
(222, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 08:00:58'),
(223, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 08:01:09'),
(228, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 08:41:25'),
(229, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 08:41:35'),
(230, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 08:43:02'),
(231, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 08:43:11'),
(232, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 08:48:20'),
(233, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 08:48:28'),
(234, 1, 'SERVICE_AVAILED_TAGGED', 'care_jf_service_availments', 2, 'Applicant APP-202609-000003 tagged (service availed) by PHILIPPINE STATISTICS OFFICE RO VIII', '::1', '2026-09-10 08:49:49'),
(235, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 08:55:20'),
(236, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 08:55:33'),
(237, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 08:58:41'),
(238, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 08:58:47'),
(239, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 09:01:01'),
(240, NULL, 'CREATE', 'care_jf_applicants', 11, 'Public self-registration: applicant APP-202609-000005', '::1', '2026-09-10 09:02:31'),
(241, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 09:17:04'),
(242, 1, 'UPDATE', 'care_jf_partner_agencies', 4, 'Updated Partner Agency: CITY GOVERNMENT OF TACLOBAN', '::1', '2026-09-10 09:18:33'),
(243, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '::1', '2026-09-10 09:18:42'),
(244, 1, 'UPDATE', 'care_jf_users', 5, 'Password reset by administrator', '::1', '2026-09-10 09:19:50'),
(245, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 09:19:56'),
(246, 5, 'LOGIN', 'care_jf_users', 5, 'User logged in', '::1', '2026-09-10 09:20:06'),
(247, 5, 'LOGOUT', 'care_jf_users', 5, 'User logged out', '::1', '2026-09-10 09:20:41'),
(248, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 09:25:45'),
(249, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '::1', '2026-09-10 09:31:11'),
(250, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 13:37:46'),
(251, 2, 'VACANCY_DELETE', 'care_jf_job_vacancies', 4, 'Job vacancy deleted', '::1', '2026-09-10 13:38:16'),
(252, 2, 'VACANCY_DELETE', 'care_jf_job_vacancies', 3, 'Job vacancy deleted', '::1', '2026-09-10 13:38:21'),
(253, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 13:38:28'),
(254, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 13:38:37'),
(255, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '::1', '2026-09-10 13:43:16'),
(256, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 13:43:28'),
(257, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 13:53:00'),
(258, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 13:53:06'),
(262, 1, 'SERVICE_AVAILED_SELECTION_SET', 'care_jf_service_availments', 4, 'Applicant APP-202609-000001\'s availed service set to \"DOCUMENT AUTHENTICATION\"', NULL, '2026-09-10 14:44:02'),
(263, 1, 'SERVICE_AVAILED_SELECTION_SET', 'care_jf_service_availments', 4, 'Applicant APP-202609-000001\'s availed service set to \"TEST CUSTOM SERVICE\"', NULL, '2026-09-10 14:44:02'),
(264, 1, 'SERVICE_AVAILED_SELECTION_SET', 'care_jf_service_availments', 4, 'Applicant APP-202609-000001\'s availed service set to \"DOCUMENT AUTHENTICATION\"', NULL, '2026-09-10 15:20:50'),
(265, 1, 'SERVICE_AVAILED_SELECTION_SET', 'care_jf_service_availments', 4, 'Applicant APP-202609-000001\'s availed service set to \"A CUSTOM SERVICE TEST\"', NULL, '2026-09-10 15:20:50'),
(266, 1, 'SERVICE_AVAILED_SELECTION_SET', 'care_jf_service_availments', 4, 'Applicant APP-202609-000001\'s availed service set to \"DOCUMENT AUTHENTICATION\"', NULL, '2026-09-10 15:41:22'),
(267, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 15:49:44'),
(268, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '192.168.1.11', '2026-09-10 15:53:36'),
(269, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '192.168.1.11', '2026-09-10 15:56:05'),
(270, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 15:56:11'),
(271, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '::1', '2026-09-10 15:57:40'),
(272, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '::1', '2026-09-10 15:57:50'),
(273, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:01:18'),
(274, 1, 'UPDATE', 'care_jf_users', 2, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(275, 1, 'UPDATE', 'care_jf_users', 4, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(276, 1, 'UPDATE', 'care_jf_users', 5, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(277, 1, 'UPDATE', 'care_jf_users', 6, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(278, 1, 'UPDATE', 'care_jf_users', 7, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(279, 1, 'UPDATE', 'care_jf_users', 8, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(280, 1, 'UPDATE', 'care_jf_users', 9, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(281, 1, 'UPDATE', 'care_jf_users', 10, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(282, 1, 'UPDATE', 'care_jf_users', 11, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(283, 1, 'UPDATE', 'care_jf_users', 12, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(284, 1, 'UPDATE', 'care_jf_users', 13, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(285, 1, 'UPDATE', 'care_jf_users', 14, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(286, 1, 'UPDATE', 'care_jf_users', 15, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(287, 1, 'UPDATE', 'care_jf_users', 16, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(288, 1, 'UPDATE', 'care_jf_users', 17, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(289, 1, 'UPDATE', 'care_jf_users', 18, 'Password reset by administrator', NULL, '2026-09-10 16:04:24'),
(290, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:06:38'),
(291, 5, 'LOGIN', 'care_jf_users', 5, 'User logged in', '192.168.1.11', '2026-09-10 16:06:42'),
(292, 5, 'CREATE', 'care_jf_agency_services', 6, 'Added service \"ISSUANCE OF CERTIFIED TRUE COPY OF BIRTH, DEATH AND MARRIAGE CERTIFICATE\"', '192.168.1.11', '2026-09-10 16:07:34'),
(293, 5, 'CREATE', 'care_jf_agency_services', 7, 'Added service \"INQUIRIES AND REQUIREMENTS SUBMISSION\"', '192.168.1.11', '2026-09-10 16:07:45'),
(294, 5, 'LOGOUT', 'care_jf_users', 5, 'User logged out', '192.168.1.11', '2026-09-10 16:07:51'),
(295, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '192.168.1.11', '2026-09-10 16:07:57'),
(296, 2, 'UPDATE', 'care_jf_agency_services', 5, 'Service Disabled', '192.168.1.11', '2026-09-10 16:08:19'),
(297, 2, 'CREATE', 'care_jf_agency_services', 8, 'Added service \"ISSUANCE OF CERTIFICATE OF ELIGIBILITIES\"', '192.168.1.11', '2026-09-10 16:08:32'),
(298, 2, 'CREATE', 'care_jf_agency_services', 9, 'Added service \"AUTHENTICATION OF CERTIFICATE OF ELIGIBILITIES\"', '192.168.1.11', '2026-09-10 16:08:40'),
(299, 2, 'CREATE', 'care_jf_agency_services', 10, 'Added service \"RESPONSE TO QUERY ON CSC MATTERS\"', '192.168.1.11', '2026-09-10 16:08:49'),
(300, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '192.168.1.11', '2026-09-10 16:09:05'),
(301, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:09:16'),
(302, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:09:25'),
(303, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:09:58'),
(304, 11, 'LOGIN', 'care_jf_users', 11, 'User logged in', '192.168.1.11', '2026-09-10 16:10:03'),
(305, 11, 'CREATE', 'care_jf_agency_services', 11, 'Added service \"RECEIVING APPLICATIONS FOR GIP\"', '192.168.1.11', '2026-09-10 16:10:19'),
(306, 11, 'CREATE', 'care_jf_agency_services', 12, 'Added service \"ANSWER INQUIRIES ON THE DILP PROGRAM\"', '192.168.1.11', '2026-09-10 16:10:34'),
(307, 11, 'CREATE', 'care_jf_agency_services', 13, 'Added service \"ANSWER INQUIRIES ON THE TUPAD PROGRAM\"', '192.168.1.11', '2026-09-10 16:10:42'),
(308, 11, 'LOGOUT', 'care_jf_users', 11, 'User logged out', '192.168.1.11', '2026-09-10 16:10:48'),
(309, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:10:57'),
(310, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:11:14'),
(311, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:12:21'),
(312, NULL, 'PARTNER_AGENCY_REGISTER', 'care_jf_partner_agencies', 18, 'Partner Agency registration: PUBLIC ATTORNEY\'S OFFICE REGIONAL OFFICE VIII (user: pao_01), pending approval', '192.168.1.11', '2026-09-10 16:13:54'),
(313, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:14:40'),
(314, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:14:46'),
(315, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 18, 'Employer ID EMP-2026-0000017 generated', '192.168.1.11', '2026-09-10 16:14:51'),
(316, 1, 'PARTNER_AGENCY_ACTIVATE', 'care_jf_users', 19, 'Partner Agency account activated', '192.168.1.11', '2026-09-10 16:14:51'),
(317, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:16:30'),
(318, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:18:04'),
(319, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:18:14'),
(320, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:23:02'),
(321, 19, 'LOGIN', 'care_jf_users', 19, 'User logged in', '192.168.1.11', '2026-09-10 16:23:06'),
(322, 19, 'CREATE', 'care_jf_agency_services', 14, 'Added service \"FREE NOTARIZATION OF PERSONAL DATA SHEET\"', '192.168.1.11', '2026-09-10 16:23:18'),
(323, 19, 'CREATE', 'care_jf_agency_services', 15, 'Added service \"FREE LEGAL ADVICE\"', '192.168.1.11', '2026-09-10 16:23:25'),
(324, 19, 'LOGOUT', 'care_jf_users', 19, 'User logged out', '192.168.1.11', '2026-09-10 16:23:32'),
(325, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:23:47'),
(326, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:24:00'),
(327, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:24:53'),
(328, NULL, 'PARTNER_AGENCY_REGISTER', 'care_jf_partner_agencies', 19, 'Partner Agency registration: PHILHEALTH (user: phil_01), pending approval', '192.168.1.11', '2026-09-10 16:27:48'),
(329, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:27:56'),
(330, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:28:01'),
(331, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 19, 'Employer ID EMP-2026-0000018 generated', '192.168.1.11', '2026-09-10 16:28:07'),
(332, 1, 'PARTNER_AGENCY_ACTIVATE', 'care_jf_users', 20, 'Partner Agency account activated', '192.168.1.11', '2026-09-10 16:28:07'),
(333, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:28:16'),
(334, 20, 'LOGIN', 'care_jf_users', 20, 'User logged in', '192.168.1.11', '2026-09-10 16:28:22'),
(335, 20, 'CREATE', 'care_jf_agency_services', 16, 'Added service \"MDR AND ID PRINTING\"', '192.168.1.11', '2026-09-10 16:28:34'),
(336, 20, 'CREATE', 'care_jf_agency_services', 17, 'Added service \"RECORD AMENDMENT/UPDATE\"', '192.168.1.11', '2026-09-10 16:28:43'),
(337, 20, 'CREATE', 'care_jf_agency_services', 18, 'Added service \"YAKAP REGISTRATION\"', '192.168.1.11', '2026-09-10 16:28:50'),
(338, 20, 'CREATE', 'care_jf_agency_services', 19, 'Added service \"IEC MATERIALS\"', '192.168.1.11', '2026-09-10 16:28:57'),
(339, 20, 'LOGOUT', 'care_jf_users', 20, 'User logged out', '192.168.1.11', '2026-09-10 16:29:07'),
(340, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:29:17'),
(341, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:29:27'),
(342, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:29:46'),
(343, 18, 'LOGIN', 'care_jf_users', 18, 'User logged in', '192.168.1.11', '2026-09-10 16:29:50'),
(344, 18, 'CREATE', 'care_jf_agency_services', 20, 'Added service \"FREE HAIRCUT\"', '192.168.1.11', '2026-09-10 16:30:08'),
(345, 18, 'CREATE', 'care_jf_agency_services', 21, 'Added service \"FREE MASSAGE\"', '192.168.1.11', '2026-09-10 16:30:16'),
(346, 18, 'CREATE', 'care_jf_agency_services', 22, 'Added service \"FREE NAIL CARE (MANICURE/PEDICURE)\"', '192.168.1.11', '2026-09-10 16:30:23'),
(347, 18, 'CREATE', 'care_jf_agency_services', 23, 'Added service \"RENEWAL OF NATIONAL CERTIFICATE (NC)\"', '192.168.1.11', '2026-09-10 16:30:29'),
(348, 18, 'CREATE', 'care_jf_agency_services', 24, 'Added service \"ASSISTANCE TO THE AVAILABILITY OF NATIONAL COMPETENCY ASSESSMENT SCHEDULE\"', '192.168.1.11', '2026-09-10 16:30:39'),
(349, 18, 'CREATE', 'care_jf_agency_services', 25, 'Added service \"AVAILABILITY OF SCHOLARSHIPS FOR SKILLS TRAINING\"', '192.168.1.11', '2026-09-10 16:30:46'),
(350, 18, 'LOGOUT', 'care_jf_users', 18, 'User logged out', '192.168.1.11', '2026-09-10 16:30:52'),
(351, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:31:00'),
(352, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:31:08'),
(353, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:31:18'),
(354, 4, 'LOGIN', 'care_jf_users', 4, 'User logged in', '192.168.1.11', '2026-09-10 16:31:23'),
(355, 4, 'CREATE', 'care_jf_agency_services', 26, 'Added service \"ISSUANCE OF IEC MATERIALS OF CIVIL REGISTRY DOCUMENTS\"', '192.168.1.11', '2026-09-10 16:31:42'),
(356, 4, 'CREATE', 'care_jf_agency_services', 27, 'Added service \"NATIONAL ID\"', '192.168.1.11', '2026-09-10 16:31:51'),
(357, 4, 'LOGOUT', 'care_jf_users', 4, 'User logged out', '192.168.1.11', '2026-09-10 16:31:55'),
(358, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:32:00'),
(359, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:32:16'),
(360, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:32:24'),
(361, NULL, 'PARTNER_AGENCY_REGISTER', 'care_jf_partner_agencies', 20, 'Partner Agency registration: SOCIAL SECURITY SYSTEM (user: sss_01), pending approval', '192.168.1.11', '2026-09-10 16:33:17'),
(362, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:33:27'),
(363, 1, 'REAUTH', 'care_jf_users', 1, 'Confirmed password to access User Management', '192.168.1.11', '2026-09-10 16:33:32'),
(364, 1, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', 20, 'Employer ID EMP-2026-0000019 generated', '192.168.1.11', '2026-09-10 16:33:38'),
(365, 1, 'PARTNER_AGENCY_ACTIVATE', 'care_jf_users', 21, 'Partner Agency account activated', '192.168.1.11', '2026-09-10 16:33:38'),
(366, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:33:44'),
(367, 21, 'LOGIN', 'care_jf_users', 21, 'User logged in', '192.168.1.11', '2026-09-10 16:33:50'),
(368, 21, 'CREATE', 'care_jf_agency_services', 28, 'Added service \"ONLINE SSS NUMBER ASSISTANCE\"', '192.168.1.11', '2026-09-10 16:34:01'),
(369, 21, 'CREATE', 'care_jf_agency_services', 29, 'Added service \"WEB REGISTRATION\"', '192.168.1.11', '2026-09-10 16:34:09'),
(370, 21, 'LOGOUT', 'care_jf_users', 21, 'User logged out', '192.168.1.11', '2026-09-10 16:34:33'),
(371, 5, 'LOGIN', 'care_jf_users', 5, 'User logged in', '192.168.1.11', '2026-09-10 16:34:41'),
(372, 5, 'LOGOUT', 'care_jf_users', 5, 'User logged out', '192.168.1.11', '2026-09-10 16:35:25'),
(373, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '192.168.1.11', '2026-09-10 16:35:41'),
(374, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '192.168.1.11', '2026-09-10 16:36:49'),
(375, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:38:36'),
(376, 1, 'LOGOUT', 'care_jf_users', 1, 'User logged out', '192.168.1.11', '2026-09-10 16:38:41'),
(377, NULL, 'CREATE', 'care_jf_applicants', 14, 'Public self-registration: applicant APP-202609-000006', '192.168.1.11', '2026-09-10 16:40:22'),
(378, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '192.168.1.11', '2026-09-10 16:41:22'),
(379, 2, 'LOGOUT', 'care_jf_users', 2, 'User logged out', '192.168.1.11', '2026-09-10 16:43:14'),
(380, 2, 'LOGIN', 'care_jf_users', 2, 'User logged in', '::1', '2026-09-10 16:43:22'),
(381, 2, 'APPLICANT_AUTO_TAGGED_QR', 'care_jf_employment_records', 11, 'Applicant APP-202609-000006 auto-tagged via QR camera scan by CIVIL SERVICE COMMISSION RO VIII', '::1', '2026-09-10 16:43:40'),
(382, NULL, 'CREATE', 'care_jf_applicants', 15, 'Public self-registration: applicant APP-202609-000007', '192.168.1.11', '2026-09-10 16:45:15'),
(383, 2, 'SERVICE_AVAILED_AUTO_TAGGED_QR', 'care_jf_service_availments', 5, 'Applicant APP-202609-000007 auto-tagged (service availed) via QR camera scan by CIVIL SERVICE COMMISSION RO VIII', '::1', '2026-09-10 16:45:43'),
(384, 2, 'SERVICE_AVAILED_SELECTION_SET', 'care_jf_service_availments', 5, 'Applicant APP-202609-000007\'s availed service set to \"TEST\"', '::1', '2026-09-10 16:46:10'),
(385, 1, 'LOGIN', 'care_jf_users', 1, 'User logged in', '192.168.1.11', '2026-09-10 16:53:55'),
(386, 1, 'VACANCY_UPDATE', 'care_jf_job_vacancies', 91, 'Updated vacancy: Administrative Aide I', '192.168.1.11', '2026-09-10 16:54:42');

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

--
-- Dumping data for table `care_jf_id_sequences`
--

INSERT INTO `care_jf_id_sequences` (`sequence_name`, `year_key`, `last_value`) VALUES
('applicant_code', 202609, 0),
('employer_id', 2026, 19);

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_job_vacancies`
--

CREATE TABLE `care_jf_job_vacancies` (
  `id` int(10) UNSIGNED NOT NULL,
  `agency_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT 'Job Available',
  `position` varchar(150) NOT NULL,
  `job_level` enum('Plantilla Level 1','Plantilla Level 2','Job Order','COS','GIP') NOT NULL,
  `salary_grade` varchar(100) DEFAULT NULL,
  `occupational_option` varchar(150) DEFAULT NULL,
  `vacant_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `status` enum('Active','Disabled','Filled','Closed') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `care_jf_job_vacancies`
--

INSERT INTO `care_jf_job_vacancies` (`id`, `agency_id`, `title`, `position`, `job_level`, `salary_grade`, `occupational_option`, `vacant_count`, `status`, `created_at`, `updated_at`) VALUES
(5, 4, 'Job Available', 'Laboratory Technician I', 'Plantilla Level 1', '6', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(6, 4, 'Job Available', 'Administrative Assistant II (Public Relations Assistant)', 'Plantilla Level 1', '8', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(7, 4, 'Job Available', 'Social Welfare Officer I', 'Plantilla Level 2', '11', NULL, 6, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(8, 4, 'Job Available', 'Driver', 'Job Order', NULL, NULL, 4, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(9, 4, 'Job Available', 'Utility', 'Job Order', NULL, NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(10, 4, 'Job Available', 'Administrative Aide', 'Job Order', NULL, NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(11, 1, 'Job Available', 'Administrative Assistant III', 'Plantilla Level 1', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(12, 5, 'Job Available', 'Agrarian Reform Program Officer II', 'Plantilla Level 2', '15', NULL, 3, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(13, 5, 'Job Available', 'Legal Assistant II', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(14, 5, 'Job Available', 'Senior Agrarian Reform Program Technologist', 'Plantilla Level 2', '14', NULL, 7, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(15, 5, 'Job Available', 'Agrarian Reform Program Officer I', 'Plantilla Level 2', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(16, 5, 'Job Available', 'Agrarian Reform Program Technologist', 'Plantilla Level 2', '10', NULL, 3, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(17, 5, 'Job Available', 'Cartographer II', 'Plantilla Level 1', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(18, 6, 'Job Available', 'Senior Administrative Assistant I', 'COS', '13', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(19, 6, 'Job Available', 'Project Assistant IV', 'Job Order', NULL, NULL, 3, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(20, 6, 'Job Available', 'Utility Worker II (Plumber)', 'COS', '3', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(21, 6, 'Job Available', 'Project Assistant I', 'COS', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(22, 7, 'Job Available', 'Administrative Assistant II (Artist Illustrator II)', 'Plantilla Level 1', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(23, 7, 'Job Available', 'Information Systems Analyst III', 'Plantilla Level 2', '19', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(24, 7, 'Job Available', 'Statistician II', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(25, 7, 'Job Available', 'Administrative Officer IV(Management and Audit Analyst II)', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(26, 7, 'Job Available', 'Administrative Assistant I', 'Plantilla Level 1', '7', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(27, 7, 'Job Available', 'Administrative Officer V (Budget Officer III)', 'Plantilla Level 2', '18', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(28, 7, 'Job Available', 'Administrative Assistant III (Senior Bookkeeper)', 'Plantilla Level 1', '9', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(29, 7, 'Job Available', 'Administrative Assistant II (Accounting Clerk III)', 'Plantilla Level 1', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(30, 7, 'Job Available', 'Administrative Assistant II (Budgeting Assistant)', 'Plantilla Level 1', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(31, 7, 'Job Available', 'Administrative Officer IV (Human Resource Management Officer II)', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(32, 7, 'Job Available', 'Administrative Officer II (Administrative Officer I)', 'Plantilla Level 2', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(33, 7, 'Job Available', 'Administrative Assistant II (Clerk IV)', 'Plantilla Level 1', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(34, 7, 'Job Available', 'Administrative Assistant II (Property Custodian)', 'Plantilla Level 1', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(35, 7, 'Job Available', 'Administrative Assistant I (Computer Operator I)', 'Plantilla Level 1', '7', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(36, 7, 'Job Available', 'Legal Assistant II', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(37, 7, 'Job Available', 'Farm Supervisor', 'Plantilla Level 1', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(38, 7, 'Job Available', 'Engineer III', 'Plantilla Level 2', '19', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(39, 7, 'Job Available', 'Forest Management Specialist II', 'Plantilla Level 2', '15', NULL, 4, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(40, 7, 'Job Available', 'Cartographer II', 'Plantilla Level 1', '8', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(41, 7, 'Job Available', 'Mathematician Aide II', 'Plantilla Level 1', '8', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(42, 7, 'Job Available', 'Administrative Aide VI (Clerk III)', 'Plantilla Level 1', '6', NULL, 3, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(43, 7, 'Job Available', 'Cartographer I', 'Plantilla Level 1', '6', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(44, 7, 'Job Available', 'Mathematician Aide I', 'Plantilla Level 1', '6', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(45, 7, 'Job Available', 'Engineering Aide', 'Plantilla Level 1', '4', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(46, 7, 'Job Available', 'Development Management Officer IV', 'Plantilla Level 2', '22', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(47, 7, 'Job Available', 'Economist I', 'Plantilla Level 2', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(48, 7, 'Job Available', 'Administrative Officer I (Records Officer I)', 'Plantilla Level 2', '10', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(49, 7, 'Job Available', 'Forest Ranger', 'Plantilla Level 1', '4', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(50, 8, 'Job Available', 'Administrative Assistant III', 'Plantilla Level 1', '9', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(51, 9, 'Job Available', 'ADMINISTRATIVE OFFICER V', 'Plantilla Level 2', '18', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(52, 10, 'Job Available', 'Administrative Officer V/ Budget Officer III', 'Plantilla Level 2', '18', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(53, 10, 'Job Available', 'Administrative Officer V/ Cashier III', 'Plantilla Level 2', '18', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(54, 10, 'Job Available', 'GIP', 'GIP', NULL, NULL, 5, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(55, 11, 'Job Available', 'SOCIAL WELFARE OFFICER III', 'COS', '18', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(56, 11, 'Job Available', 'SOCIAL WELFARE OFFICER II', 'COS', '15', NULL, 4, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(57, 11, 'Job Available', 'PROJECT DEVELOPMENT OFFICER III (Area Coordinator)', 'COS', '18', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(58, 11, 'Job Available', 'PROJECT DEVELOPMENT OFFICER II (Community Empowerment Facilitator)', 'COS', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(59, 11, 'Job Available', 'TECHNICAL FACILITATOR', 'COS', '17', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(60, 12, 'Job Available', 'Medical Officer IV', 'Plantilla Level 2', '23', NULL, 4, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(61, 12, 'Job Available', 'Medical Officer III', 'Plantilla Level 2', '21', NULL, 3, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(62, 12, 'Job Available', 'Psychologist II', 'Plantilla Level 2', '18', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(63, 12, 'Job Available', 'Occupational Therapist II', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(64, 12, 'Job Available', 'Midwife III', 'Plantilla Level 2', '13', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(65, 12, 'Job Available', 'Medical Equipment Technician IV', 'Plantilla Level 2', '13', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(66, 12, 'Job Available', 'Pharmacist I', 'Plantilla Level 2', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(67, 12, 'Job Available', 'Occupational Therapist I', 'Plantilla Level 2', '11', NULL, 5, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(68, 12, 'Job Available', 'Occupational Therapist I', 'Plantilla Level 2', '10', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(69, 12, 'Job Available', 'Health Education And Promotion Officer I', 'Plantilla Level 2', '10', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(70, 12, 'Job Available', 'Dental Hygienist', 'Plantilla Level 2', '10', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(71, 12, 'Job Available', 'Speech Therapist I (Part-Time)', 'Plantilla Level 2', '10', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(72, 12, 'Job Available', 'Speech Therapist I (Part-Time)', 'Plantilla Level 2', '7', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(73, 12, 'Job Available', 'Ward Assistant', 'Plantilla Level 1', '7', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(74, 13, 'Job Available', 'Associate Professor V (Guidance and Counselling)', 'Plantilla Level 2', '22', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(75, 13, 'Job Available', 'Instructor III (Guidance and Counselling)', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(76, 13, 'Job Available', 'Instructor III (Electronics Engineering)', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(77, 13, 'Job Available', 'Instructor I (Industrial Engineering)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(78, 13, 'Job Available', 'Instructor I (Geodetic Engineering)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(79, 13, 'Job Available', 'Instructor I (Natural Science)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(80, 13, 'Job Available', 'Instructor I (Economics)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(81, 13, 'Job Available', 'Instructor I (Culture and Arts)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(82, 13, 'Job Available', 'Instructor I (Science)', 'Plantilla Level 2', '12', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(83, 13, 'Job Available', 'Instructor I (Accounting)', 'Plantilla Level 2', '12', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(84, 13, 'Job Available', 'Instructor I (Entreprenuership)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(85, 13, 'Job Available', 'Instructor I (Nutrition and Dietitics)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(86, 13, 'Job Available', 'Instructor I (Elem. Education)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(87, 13, 'Job Available', 'Guidance Counselor II', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(88, 13, 'Job Available', 'Guidance Counselor I', 'Plantilla Level 2', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(89, 13, 'Job Available', 'Dental Aide', 'COS', NULL, NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(90, 13, 'Job Available', 'Medical Aide', 'COS', NULL, NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(91, 13, 'Job Available', 'Administrative Aide I', 'COS', NULL, NULL, 5, 'Active', '2026-09-10 06:49:44', '2026-09-10 16:54:42'),
(92, 14, 'Job Available', 'Administrative Aide IV (Clerk II)', 'Plantilla Level 1', '17506', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(93, 14, 'Job Available', 'Administrative Aide VI (Clerk III)', 'Plantilla Level 1', '19716', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(94, 15, 'Job Available', 'Administrative Officer III (Supply Officer II)', 'Plantilla Level 2', '14', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(95, 16, 'Job Available', 'ADMINISTRATIVE OFFICER V (Administrative Officer III)', 'Plantilla Level 2', '18', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(96, 16, 'Job Available', 'DORMITORY MANAGER III', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(97, 16, 'Job Available', 'GUIDANCE COUNSELOR II', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(98, 16, 'Job Available', 'GUIDANCE COUNSELOR I', 'Plantilla Level 2', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(99, 16, 'Job Available', 'INSTRUCTOR I (BA Communication)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(100, 16, 'Job Available', 'INSTRUCTOR I (Home Economics)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(101, 16, 'Job Available', 'INSTRUCTOR I (Librarian)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(102, 16, 'Job Available', 'TEACHING PERSONNEL UNDER CONTRACT OF SERVICE', 'COS', 'N/A', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(103, 3, 'Job Available', 'Accountant I', 'Plantilla Level 2', '13', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(104, 3, 'Job Available', 'Registration Officer II', 'Plantilla Level 2', '14', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(105, 17, 'Job Available', 'Instructor I (TESDAB-INST1-2-2020)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(106, 17, 'Job Available', 'Instructor I (TESDAB-INST1-540004-2022)', 'Plantilla Level 2', '12', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(107, 17, 'Job Available', 'Guidance Counselor III', 'Plantilla Level 2', '13', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(108, 17, 'Job Available', 'Administrative Officer V (Human Resource Management Officer III)', 'Plantilla Level 2', '18', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(109, 17, 'Job Available', 'Technical Education and Skills Development Specialist II', 'Plantilla Level 2', '16', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(110, 17, 'Job Available', 'Administrative Officer II (Financial Analyst I)', 'Plantilla Level 2', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(111, 17, 'Job Available', 'Guidance Counselor I', 'Plantilla Level 2', '11', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(112, 17, 'Job Available', 'Technical Education and Skills Development Specialist I', 'Plantilla Level 2', '13', NULL, 2, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(113, 17, 'Job Available', 'Administrative Officer IV (Human Resource Management Officer II)', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(114, 17, 'Job Available', 'Assistant Professor I (TESDAB-AP1-182-2017)', 'Plantilla Level 2', '15', NULL, 1, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44');

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

--
-- Dumping data for table `care_jf_partner_agencies`
--

INSERT INTO `care_jf_partner_agencies` (`id`, `employer_id`, `agency_name`, `address`, `contact_person`, `contact_no`, `email`, `status`, `created_at`, `updated_at`) VALUES
(1, 'EMP-2026-0000001', 'CIVIL SERVICE COMMISSION RO VIII', 'TACLOBAN CITY', 'ATTY. GERVIEN T. BERECIO', '0538322955', 'cscro8.esd@csc.gov.ph', 'Active', '2026-09-10 02:53:38', '2026-09-10 05:12:54'),
(3, 'EMP-2026-0000002', 'PHILIPPINE STATISTICS OFFICE RO VIII', 'TACLOBAN CITY', 'XXYY', '092100000000', 'yuhs@gmail.com', 'Active', '2026-09-10 03:00:10', '2026-09-10 03:00:33'),
(4, 'EMP-2026-0000003', 'CITY GOVERNMENT OF TACLOBAN', 'TACLOBAN CITY', 'CGOTAC', '092100000000', 'temp@gmail.com', 'Active', '2026-09-10 06:49:43', '2026-09-10 09:18:33'),
(5, 'EMP-2026-0000004', 'DEPARTMENT OF AGRARIAN REFORM REGION VIII', '', '', '', NULL, 'Active', '2026-09-10 06:49:43', '2026-09-10 06:49:44'),
(6, 'EMP-2026-0000005', 'DEPARTMENT OF AGRICULTURE REGIONAL FIELD OFFICE VIII', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(7, 'EMP-2026-0000006', 'DEPARTMENT OF ENVIRONMENT AND NATURAL RESOURCES', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(8, 'EMP-2026-0000007', 'DEPED SCHOOLS DIVISION OF TACLOBAN CITY', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(9, 'EMP-2026-0000008', 'DOH - TREATMENT AND REHABILITATION CENTER, DULAG', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(10, 'EMP-2026-0000009', 'DEPARTMENT OF LABOR AND EMPLOYMENT', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(11, 'EMP-2026-0000010', 'DSWD FIELD OFFICE VIII', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(12, 'EMP-2026-0000011', 'EASTERN VISAYAS MEDICAL CENTER', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(13, 'EMP-2026-0000012', 'EASTERN VISAYAS STATE UNIVERSITY', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(14, 'EMP-2026-0000013', 'LAND TRANSPORTATION OFFICE, ROVIII', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(15, 'EMP-2026-0000014', 'MGO-BURAUEN, LEYTE', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(16, 'EMP-2026-0000015', 'PALOMPON INSTITUTE OF TECHNOLOGY', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(17, 'EMP-2026-0000016', 'TECHNICAL EDUCATION AND SKILLS DEVELOPMENT AUTHORITY REGION VIII', '', '', '', NULL, 'Active', '2026-09-10 06:49:44', '2026-09-10 06:49:44'),
(18, 'EMP-2026-0000017', 'PUBLIC ATTORNEY\'S OFFICE REGIONAL OFFICE VIII', 'TACLOBAN CITY', 'PAO_01', '1112223334444', 'pao@gmail.com', 'Active', '2026-09-10 16:13:54', '2026-09-10 16:14:51'),
(19, 'EMP-2026-0000018', 'PHILHEALTH', 'TAC', 'PHILHEALTH', '09191112222', 'phic@gmail.com', 'Active', '2026-09-10 16:27:48', '2026-09-10 16:28:07'),
(20, 'EMP-2026-0000019', 'SOCIAL SECURITY SYSTEM', 'TACLOBAN CITY', 'SSS_AGENT', '09195557777', 'sss@gmail.com', 'Active', '2026-09-10 16:33:17', '2026-09-10 16:33:38');

-- --------------------------------------------------------

--
-- Table structure for table `care_jf_service_availments`
--

CREATE TABLE `care_jf_service_availments` (
  `id` int(10) UNSIGNED NOT NULL,
  `applicant_id` int(10) UNSIGNED NOT NULL,
  `agency_id` int(10) UNSIGNED NOT NULL,
  `service_id` int(10) UNSIGNED DEFAULT NULL,
  `custom_service_name` varchar(200) DEFAULT NULL,
  `source` enum('manual','qr_scan') NOT NULL DEFAULT 'qr_scan',
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
(1, 'admin', '$2y$10$Z.CoxBdu7O2qzqRY3gH/TeNLYXCcfi1FRfWtcHuo05CqHKQmkmYXO', 'SYSTEM ADMINISTRATOR', 'Administrator', 'Active', NULL, 0, 1, '2026-09-07 01:50:59', '2026-09-10 00:58:05'),
(2, 'agtb_esd', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'ATTY. GERVIEN T. BERECIO', 'Partner Agency', 'Active', 1, 1, 1, '2026-09-10 02:53:38', '2026-09-10 16:04:24'),
(4, 'psa_01', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'XXYY', 'Partner Agency', 'Active', 3, 1, 1, '2026-09-10 03:00:10', '2026-09-10 16:04:24'),
(5, 'city_government_of_tacloban', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'CITY GOVERNMENT OF TACLOBAN', 'Partner Agency', 'Active', 4, 1, 1, '2026-09-10 06:49:43', '2026-09-10 16:04:24'),
(6, 'department_of_agrarian_reform_', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'DEPARTMENT OF AGRARIAN REFORM REGION VIII', 'Partner Agency', 'Active', 5, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(7, 'department_of_agriculture_regi', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'DEPARTMENT OF AGRICULTURE REGIONAL FIELD OFFICE VIII', 'Partner Agency', 'Active', 6, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(8, 'department_of_environment_and_', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'DEPARTMENT OF ENVIRONMENT AND NATURAL RESOURCES', 'Partner Agency', 'Active', 7, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(9, 'deped_schools_division_of_tacl', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'DEPED SCHOOLS DIVISION OF TACLOBAN CITY', 'Partner Agency', 'Active', 8, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(10, 'doh_treatment_and_rehabilitati', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'DOH - TREATMENT AND REHABILITATION CENTER, DULAG', 'Partner Agency', 'Active', 9, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(11, 'department_of_labor_and_employ', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'DEPARTMENT OF LABOR AND EMPLOYMENT', 'Partner Agency', 'Active', 10, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(12, 'dswd_field_office_viii', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'DSWD FIELD OFFICE VIII', 'Partner Agency', 'Active', 11, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(13, 'eastern_visayas_medical_center', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'EASTERN VISAYAS MEDICAL CENTER', 'Partner Agency', 'Active', 12, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(14, 'eastern_visayas_state_universi', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'EASTERN VISAYAS STATE UNIVERSITY', 'Partner Agency', 'Active', 13, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(15, 'land_transportation_office_rov', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'LAND TRANSPORTATION OFFICE, ROVIII', 'Partner Agency', 'Active', 14, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(16, 'mgo_burauen_leyte', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'MGO-BURAUEN, LEYTE', 'Partner Agency', 'Active', 15, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(17, 'palompon_institute_of_technolo', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'PALOMPON INSTITUTE OF TECHNOLOGY', 'Partner Agency', 'Active', 16, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(18, 'technical_education_and_skills', '$2y$10$PjuBBHlwrZcRS4FDUzWMueMFNS7VehnexAruHq.G2UVhbky9j5bhS', 'TECHNICAL EDUCATION AND SKILLS DEVELOPMENT AUTHORITY REGION VIII', 'Partner Agency', 'Active', 17, 1, 1, '2026-09-10 06:49:44', '2026-09-10 16:04:24'),
(19, 'pao_01', '$2y$10$7ASud20gjbc80b.o3C.A2ePmRbN0CMlfgAs4G7uOvEXtCmtlO23N.', 'PAO_01', 'Partner Agency', 'Active', 18, 1, 1, '2026-09-10 16:13:54', '2026-09-10 16:14:51'),
(20, 'phil_01', '$2y$10$.jzoR0yM8AO10hipeS3W0.jQcAIT9Cw8Sf.1DqRfhbSgOnuEs.Zl6', 'PHILHEALTH', 'Partner Agency', 'Active', 19, 1, 1, '2026-09-10 16:27:48', '2026-09-10 16:28:07'),
(21, 'sss_01', '$2y$10$OBy1adzL313WFOH7aIydXOSfNS/d6ix5ML3aVG.DS5m3LYXBVxQkq', 'SSS_AGENT', 'Partner Agency', 'Active', 20, 1, 1, '2026-09-10 16:33:17', '2026-09-10 16:33:38');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `care_jf_agency_services`
--
ALTER TABLE `care_jf_agency_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service_agency` (`agency_id`);

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
-- Indexes for table `care_jf_service_availments`
--
ALTER TABLE `care_jf_service_availments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_applicant_agency` (`applicant_id`,`agency_id`),
  ADD KEY `idx_availment_agency` (`agency_id`),
  ADD KEY `fk_availment_service` (`service_id`);

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
-- AUTO_INCREMENT for table `care_jf_agency_services`
--
ALTER TABLE `care_jf_agency_services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `care_jf_applicants`
--
ALTER TABLE `care_jf_applicants`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `care_jf_audit_logs`
--
ALTER TABLE `care_jf_audit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=387;

--
-- AUTO_INCREMENT for table `care_jf_employment_records`
--
ALTER TABLE `care_jf_employment_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `care_jf_job_vacancies`
--
ALTER TABLE `care_jf_job_vacancies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT for table `care_jf_login_throttle`
--
ALTER TABLE `care_jf_login_throttle`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `care_jf_partner_agencies`
--
ALTER TABLE `care_jf_partner_agencies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `care_jf_service_availments`
--
ALTER TABLE `care_jf_service_availments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `care_jf_users`
--
ALTER TABLE `care_jf_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `care_jf_agency_services`
--
ALTER TABLE `care_jf_agency_services`
  ADD CONSTRAINT `fk_service_agency` FOREIGN KEY (`agency_id`) REFERENCES `care_jf_partner_agencies` (`id`);

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
-- Constraints for table `care_jf_service_availments`
--
ALTER TABLE `care_jf_service_availments`
  ADD CONSTRAINT `fk_availment_agency` FOREIGN KEY (`agency_id`) REFERENCES `care_jf_partner_agencies` (`id`),
  ADD CONSTRAINT `fk_availment_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `care_jf_applicants` (`id`),
  ADD CONSTRAINT `fk_availment_service` FOREIGN KEY (`service_id`) REFERENCES `care_jf_agency_services` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `care_jf_users`
--
ALTER TABLE `care_jf_users`
  ADD CONSTRAINT `fk_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `care_jf_partner_agencies` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
