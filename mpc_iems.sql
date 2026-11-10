-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 19, 2026 at 08:11 PM
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
-- Database: `mpc_iems`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_apply_leave_balance` (IN `p_request_id` INT)   BEGIN
    DECLARE v_user_id INT;
    DECLARE v_type    VARCHAR(20);
    DECLARE v_days    INT;
    DECLARE v_year    VARCHAR(20);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;

    SELECT sr.requester_id, ld.leave_type, ld.days_count
      INTO v_user_id, v_type, v_days
    FROM service_requests sr
    JOIN leave_details ld ON ld.request_id = sr.id
    WHERE sr.id = p_request_id;

    SET v_year = (SELECT fiscal_year FROM hr_balances
                  WHERE user_id = v_user_id
                  ORDER BY fiscal_year DESC LIMIT 1);

    IF v_type = 'annual' THEN
        UPDATE hr_balances SET annual_used = annual_used + v_days
        WHERE user_id = v_user_id AND fiscal_year = v_year;
    ELSEIF v_type = 'special' OR v_type IN ('mourning','marriage') THEN
        UPDATE hr_balances SET special_used = special_used + v_days
        WHERE user_id = v_user_id AND fiscal_year = v_year;
    END IF;

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_apply_maternal_balance` (IN `p_request_id` INT)   BEGIN
    DECLARE v_user_id INT;
    DECLARE v_type    VARCHAR(20);
    DECLARE v_days    INT;
    DECLARE v_year    VARCHAR(20);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;

    SELECT sr.requester_id, md.type, md.days_count
      INTO v_user_id, v_type, v_days
    FROM service_requests sr
    JOIN maternal_details md ON md.request_id = sr.id
    WHERE sr.id = p_request_id;

    SET v_year = (SELECT fiscal_year FROM hr_balances
                  WHERE user_id = v_user_id
                  ORDER BY fiscal_year DESC LIMIT 1);

    IF v_type = 'maternity' THEN
        UPDATE hr_balances SET maternity_used = maternity_used + v_days
        WHERE user_id = v_user_id AND fiscal_year = v_year;
    ELSEIF v_type = 'paternity' THEN
        UPDATE hr_balances SET paternity_used = paternity_used + v_days
        WHERE user_id = v_user_id AND fiscal_year = v_year;
    END IF;

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_approve_clearance_step` (IN `p_clearance_id` INT, IN `p_step_order` INT, IN `p_approver_id` INT, IN `p_decision` VARCHAR(10), IN `p_comment` VARCHAR(255))   BEGIN
    DECLARE v_remaining INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;

    UPDATE clearance_approvals
    SET status = IF(p_decision='approve','approved','rejected'),
        approver_id = p_approver_id,
        comment = p_comment,
        acted_at = NOW()
    WHERE clearance_id = p_clearance_id AND step_order = p_step_order;

    IF p_decision = 'reject' THEN
        UPDATE clearance_requests SET status='rejected' WHERE id = p_clearance_id;
    ELSE
        SELECT COUNT(*) INTO v_remaining
        FROM clearance_approvals
        WHERE clearance_id = p_clearance_id AND status='pending';

        IF v_remaining = 0 THEN
            UPDATE clearance_requests SET status='approved' WHERE id = p_clearance_id;
        ELSE
            UPDATE clearance_requests
            SET status='in_progress', current_step = p_step_order + 1
            WHERE id = p_clearance_id;
        END IF;
    END IF;

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_approve_step` (IN `p_step_id` INT, IN `p_approver_id` INT, IN `p_decision` VARCHAR(10), IN `p_comment` VARCHAR(255))   BEGIN
    DECLARE v_request_id INT;
    DECLARE v_step_order INT;
    DECLARE v_remaining INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;

    SELECT request_id, step_order INTO v_request_id, v_step_order
    FROM workflow_steps WHERE id = p_step_id FOR UPDATE;

    UPDATE workflow_steps
    SET status = IF(p_decision='approve','approved','rejected'),
        approver_id = p_approver_id,
        comment = p_comment,
        acted_at = NOW()
    WHERE id = p_step_id;

    IF p_decision = 'reject' THEN
        UPDATE service_requests SET status='rejected' WHERE id = v_request_id;
    ELSE
        SELECT COUNT(*) INTO v_remaining
        FROM workflow_steps WHERE request_id = v_request_id AND status='pending';

        IF v_remaining = 0 THEN
            UPDATE service_requests
            SET status='approved', approved_at=NOW()
            WHERE id = v_request_id;
        ELSE
            UPDATE service_requests
            SET status='in_progress', current_step = v_step_order + 1
            WHERE id = v_request_id;
        END IF;
    END IF;

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_clearance` (IN `p_applicant_id` INT, IN `p_applicant_type` VARCHAR(20), IN `p_reason` VARCHAR(40), IN `p_destination` VARCHAR(200), IN `p_dest_type` VARCHAR(20), IN `p_notes` TEXT, IN `p_created_by` INT, OUT `p_clearance_id` INT, OUT `p_ref_no` VARCHAR(40))   BEGIN
    DECLARE v_dept INT DEFAULT NULL;
    DECLARE v_prefix VARCHAR(10);
    DECLARE v_steps JSON;
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_step VARCHAR(120);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;

    SELECT department_id INTO v_dept FROM users WHERE id = p_applicant_id;

    SET v_prefix = CASE WHEN p_applicant_type='student' THEN 'CLR-S' ELSE 'CLR-E' END;
    SET p_ref_no  = CONCAT(v_prefix, '-', DATE_FORMAT(NOW(),'%Y%m%d'), '-',
                           UPPER(SUBSTRING(MD5(RAND()),1,6)));

    INSERT INTO clearance_requests
        (ref_no, applicant_id, applicant_type, reason, destination, destination_type,
         department_id, request_date, status, total_steps, notes, created_by)
    VALUES
        (p_ref_no, p_applicant_id, p_applicant_type, p_reason, p_destination, p_dest_type,
         v_dept, CURDATE(), 'pending', 5, p_notes, p_created_by);

    SET p_clearance_id = LAST_INSERT_ID();

    SET v_steps = CASE
        WHEN p_applicant_type='student'
            THEN JSON_ARRAY('Department','Library','Dormitory','Finance','Registrar')
        ELSE JSON_ARRAY('Department','Library','Store','Finance','HR')
    END;

    WHILE v_i < JSON_LENGTH(v_steps) DO
        SET v_step = JSON_UNQUOTE(JSON_EXTRACT(v_steps, CONCAT('$[', v_i, ']')));
        INSERT INTO clearance_approvals (clearance_id, step_order, department, status)
        VALUES (p_clearance_id, v_i + 1, v_step, 'pending');
        SET v_i = v_i + 1;
    END WHILE;

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_service_request` (IN `p_type` VARCHAR(30), IN `p_requester` INT, IN `p_title` VARCHAR(200), IN `p_summary` VARCHAR(500), IN `p_priority` VARCHAR(20), IN `p_steps_json` JSON, OUT `p_request_id` INT, OUT `p_ref_no` VARCHAR(50))   BEGIN
    DECLARE v_dept INT DEFAULT NULL;
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_role VARCHAR(60);
    DECLARE v_dept_required INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;

    SELECT department_id INTO v_dept FROM users WHERE id = p_requester;

    SET p_ref_no = CONCAT('REQ-', UPPER(p_type), '-',
                          DATE_FORMAT(NOW(),'%Y%m%d'), '-',
                          UPPER(SUBSTRING(MD5(RAND()),1,6)));

    SET v_count = JSON_LENGTH(p_steps_json);

    INSERT INTO service_requests
        (ref_no, request_type, requester_id, department_id, title, summary, priority,
         status, current_step, total_steps, submitted_at)
    VALUES
        (p_ref_no, p_type, p_requester, v_dept, p_title, p_summary, p_priority,
         'submitted', 1, v_count, NOW());

    SET p_request_id = LAST_INSERT_ID();

    WHILE v_i < v_count DO
        SET v_role = JSON_UNQUOTE(JSON_EXTRACT(p_steps_json, CONCAT('$[', v_i, '].role')));
        SET v_dept_required = NULLIF(
            JSON_UNQUOTE(JSON_EXTRACT(p_steps_json, CONCAT('$[', v_i, '].department'))),
            'null'
        );
        INSERT INTO workflow_steps
            (request_id, step_order, role_required, department_id, status)
        VALUES
            (p_request_id, v_i + 1, v_role, v_dept_required, 'pending');
        SET v_i = v_i + 1;
    END WHILE;

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_generate_clearance_letter` (IN `p_clearance_id` INT, IN `p_sender_id` INT, OUT `p_letter_id` INT)   BEGIN
    DECLARE v_ref              VARCHAR(40);
    DECLARE v_applicant        VARCHAR(150);
    DECLARE v_applicant_type   VARCHAR(20);
    DECLARE v_reason           VARCHAR(50);
    DECLARE v_dept             VARCHAR(150);
    DECLARE v_subject          VARCHAR(200);
    DECLARE v_body             MEDIUMTEXT;
    DECLARE v_letter_type      VARCHAR(30);
    DECLARE v_clr_ref          VARCHAR(40);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;

    SELECT cr.ref_no, u.full_name, cr.applicant_type, cr.reason, d.name
      INTO v_clr_ref, v_applicant, v_applicant_type, v_reason, v_dept
    FROM clearance_requests cr
    JOIN users u ON u.id = cr.applicant_id
    LEFT JOIN departments d ON d.id = cr.department_id
    WHERE cr.id = p_clearance_id;

    SET v_ref = CONCAT('LTR-', DATE_FORMAT(NOW(),'%Y%m%d'), '-',
                       UPPER(SUBSTRING(MD5(RAND()),1,6)));

    SET v_letter_type = IF(v_applicant_type='student','certificate_letter','transfer_letter');
    SET v_subject = CONCAT(
        IF(v_applicant_type='student','Certificate of Completion — ','Transfer / Release Letter — '),
        v_applicant
    );

    SET v_body = CONCAT(
        'To Whom It May Concern,\n\n',
        'This is to certify that ', v_applicant,
        ' (', v_applicant_type, ', Department: ', IFNULL(v_dept,'N/A'), ')',
        ' has completed all required clearance procedures at Misrak Polytechnic College.\n\n',
        'Reference: ', v_clr_ref, '\n',
        'Reason: ', REPLACE(v_reason,'_',' '), '\n\n',
        'All approving departments have signed off. We wish the applicant success in their future engagement.\n\n',
        'Sincerely,\nMisrak Polytechnic College\nRegistrar''s Office\n',
        DATE_FORMAT(NOW(),'%M %e, %Y')
    );

    INSERT INTO letters
        (ref_no, sender_id, letter_type, subject, body,
         recipient_name, recipient_type, related_clearance_id, status, sent_at)
    VALUES
        (v_ref, p_sender_id, v_letter_type, v_subject, v_body,
         v_applicant, 'external', p_clearance_id, 'sent', NOW());

    SET p_letter_id = LAST_INSERT_ID();

    INSERT INTO letter_transfers (letter_id, from_user, action, comment)
    VALUES (p_letter_id, p_sender_id, 'generated',
            CONCAT('Auto-generated from clearance ', v_clr_ref));

    UPDATE clearance_requests
    SET status='completed', completed_at=NOW()
    WHERE id = p_clearance_id;

    COMMIT;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `attachments`
--

CREATE TABLE `attachments` (
  `id` int(11) NOT NULL,
  `entity` varchar(60) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `file_name` varchar(200) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime_type` varchar(80) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `entity` varchar(60) NOT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `action`, `entity`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 12, 'UPDATE', 'users', 12, '{\"full_name\": \"Kalkidan Worku\", \"status\": \"active\", \"department_id\": 1, \"position\": \"Lecturer\"}', '{\"full_name\": \"Kalkidan Worku\", \"status\": \"retired\", \"department_id\": 1, \"position\": \"Lecturer\"}', NULL, NULL, '2026-10-14 15:59:52');

-- --------------------------------------------------------

--
-- Table structure for table `clearance_approvals`
--

CREATE TABLE `clearance_approvals` (
  `id` int(11) NOT NULL,
  `clearance_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `department` varchar(120) NOT NULL,
  `approver_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected','skipped') NOT NULL DEFAULT 'pending',
  `comment` varchar(255) DEFAULT NULL,
  `acted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clearance_approvals`
--

INSERT INTO `clearance_approvals` (`id`, `clearance_id`, `step_order`, `department`, `approver_id`, `status`, `comment`, `acted_at`, `created_at`) VALUES
(1, 1, 1, 'Department', 8, 'approved', 'All lab equipment returned. OK.', '2026-10-14 18:59:52', '2026-10-14 15:59:51'),
(2, 1, 2, 'Library', 5, 'approved', 'All books returned.', '2026-10-14 18:59:52', '2026-10-14 15:59:51'),
(3, 1, 3, 'Dormitory', 6, 'approved', 'Room cleared.', '2026-10-14 18:59:52', '2026-10-14 15:59:51'),
(4, 1, 4, 'Finance', 4, 'approved', 'No outstanding dues.', '2026-10-14 18:59:52', '2026-10-14 15:59:51'),
(5, 1, 5, 'Registrar', 2, 'approved', 'Final approval granted.', '2026-10-14 18:59:52', '2026-10-14 15:59:51'),
(6, 2, 1, 'Department', 2, 'approved', '', '2026-10-19 17:27:26', '2026-10-19 14:25:32'),
(7, 2, 2, 'Library', 2, 'approved', '', '2026-10-19 17:27:28', '2026-10-19 14:25:32'),
(8, 2, 3, 'Store', 2, 'approved', '', '2026-10-19 17:27:30', '2026-10-19 14:25:32'),
(9, 2, 4, 'Finance', 2, 'approved', '', '2026-10-19 17:27:33', '2026-10-19 14:25:32'),
(10, 2, 5, 'HR', 2, 'approved', '', '2026-10-19 17:27:35', '2026-10-19 14:25:32');

-- --------------------------------------------------------

--
-- Table structure for table `clearance_requests`
--

CREATE TABLE `clearance_requests` (
  `id` int(11) NOT NULL,
  `ref_no` varchar(40) NOT NULL,
  `applicant_id` int(11) NOT NULL,
  `applicant_type` enum('student','employee') NOT NULL,
  `reason` enum('graduation','class_completion','transfer_college','transfer_region','release_private','release_government','pension','resignation','other') NOT NULL,
  `destination` varchar(200) DEFAULT NULL,
  `destination_type` enum('government','private','college','region','other') DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `request_date` date NOT NULL,
  `status` enum('pending','in_progress','approved','rejected','completed','cancelled') NOT NULL DEFAULT 'pending',
  `current_step` int(11) NOT NULL DEFAULT 1,
  `total_steps` int(11) NOT NULL DEFAULT 5,
  `notes` text DEFAULT NULL,
  `related_request_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clearance_requests`
--

INSERT INTO `clearance_requests` (`id`, `ref_no`, `applicant_id`, `applicant_type`, `reason`, `destination`, `destination_type`, `department_id`, `request_date`, `status`, `current_step`, `total_steps`, `notes`, `related_request_id`, `created_by`, `created_at`, `completed_at`) VALUES
(1, 'CLR-S-20261014-B9ACFF', 13, 'student', 'graduation', 'Ministry of Education', 'government', 1, '2026-10-14', 'completed', 5, 5, 'Completed Level 4 IT program — requesting certificate.', NULL, 2, '2026-10-14 15:59:51', '2026-10-14 18:59:52'),
(2, 'CLR-E-20261019-E258D1', 11, 'employee', 'graduation', 'Teferi Mekonnen Polytechnic College', 'college', 20, '2026-10-19', 'completed', 5, 5, 'Created via UI', NULL, 1, '2026-10-19 14:25:32', '2026-10-19 17:27:37');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `name_am` varchar(200) DEFAULT NULL,
  `type` enum('academic','administrative','support') NOT NULL DEFAULT 'academic',
  `head_user_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `code`, `name`, `name_am`, `type`, `head_user_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'IT', 'Information Technology', 'ኢንፎርሜሽን ቴክኖሎጂ', 'academic', 8, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(2, 'ELEC', 'Electrical & Electronics', 'ኤሌክትሪክና ኤሌክትሮኒክስ', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(3, 'MECH', 'Mechanical Technology', 'ሜካኒካል ቴክኖሎጂ', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(4, 'CIVIL', 'Civil / Construction', 'ሲቪል ኮንስትራክሽን', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(5, 'AUTO', 'Automotive Technology', 'አውቶሞቲቭ ቴክኖሎጂ', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(6, 'BUS', 'Business & Administration', 'ቢዝነስና አስተዳደር', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(7, 'TEXT', 'Textile & Garment', 'ጨርቃጨርቅና ልብስ', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(8, 'HOTEL', 'Hotel & Tourism', 'ሆቴልና ቱሪዝም', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(9, 'HEALTH', 'Health Science', 'ጤና ሳይንስ', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(10, 'GEN', 'General Studies', 'አጠቃላይ ትምህርት', 'academic', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(11, 'REG', 'Registrar Office', 'ሬጅስትራር ቢሮ', 'administrative', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(12, 'HRD', 'Human Resources', 'የሰው ኃይል አስተዳደር', 'administrative', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(13, 'FIN', 'Finance', 'ፋይናንስ', 'administrative', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(14, 'LIB', 'Library', 'ቤተ-መጻሕፍት', 'support', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(15, 'DORM', 'Dormitory', 'ዶርምትሪ', 'support', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(16, 'STORE', 'Store & Property', 'መጋዘንና ንብረት', 'support', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(17, 'PLAN', 'Planning Office', 'ዕቅድ ቢሮ', 'administrative', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(18, 'CLINIC', 'Clinic / Health Center', 'ክሊኒክ', 'support', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(19, 'ICT', 'ICT & Systems', 'አይሲቲ', 'administrative', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14'),
(20, 'ADMIN', 'Administration', 'አስተዳደር', 'administrative', NULL, 1, '2026-10-14 15:57:14', '2026-10-14 15:57:14');

-- --------------------------------------------------------

--
-- Table structure for table `hr_balances`
--

CREATE TABLE `hr_balances` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `fiscal_year` varchar(20) NOT NULL,
  `annual_entitled` int(11) NOT NULL DEFAULT 20,
  `annual_used` int(11) NOT NULL DEFAULT 0,
  `annual_carried` int(11) NOT NULL DEFAULT 0,
  `sick_full_days` int(11) NOT NULL DEFAULT 90,
  `sick_half_days` int(11) NOT NULL DEFAULT 90,
  `sick_unpaid_days` int(11) NOT NULL DEFAULT 60,
  `special_used` int(11) NOT NULL DEFAULT 0,
  `maternity_used` int(11) NOT NULL DEFAULT 0,
  `paternity_used` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `hr_balances`
--

INSERT INTO `hr_balances` (`id`, `user_id`, `fiscal_year`, `annual_entitled`, `annual_used`, `annual_carried`, `sick_full_days`, `sick_half_days`, `sick_unpaid_days`, `special_used`, `maternity_used`, `paternity_used`, `updated_at`) VALUES
(1, 1, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(2, 2, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(3, 3, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(4, 4, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(5, 5, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(6, 6, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(7, 7, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(8, 8, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(9, 9, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(10, 10, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(11, 11, '2025-26', 20, 0, 0, 90, 90, 60, 0, 0, 0, '2026-10-14 15:57:14'),
(12, 12, '2025-26', 20, 10, 0, 90, 90, 60, 0, 120, 0, '2026-10-14 15:59:52');

-- --------------------------------------------------------

--
-- Table structure for table `leave_details`
--

CREATE TABLE `leave_details` (
  `request_id` int(11) NOT NULL,
  `leave_type` enum('annual','sick','casual','study','special','unpaid','mourning','marriage') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_count` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `handover_to` int(11) DEFAULT NULL,
  `substitute_plan` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `return_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `return_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_details`
--

INSERT INTO `leave_details` (`request_id`, `leave_type`, `start_date`, `end_date`, `days_count`, `reason`, `handover_to`, `substitute_plan`, `attachment_path`, `is_paid`, `return_confirmed`, `return_date`) VALUES
(1, 'annual', '2026-03-02', '2026-03-13', 10, 'Annual leave for family matters', NULL, NULL, NULL, 1, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `letters`
--

CREATE TABLE `letters` (
  `id` int(11) NOT NULL,
  `ref_no` varchar(40) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `letter_type` enum('internal_memo','external_letter','clearance_letter','certificate_letter','transfer_letter','pension_letter','resignation_letter','maternal_letter','service_certificate') NOT NULL,
  `subject` varchar(200) NOT NULL,
  `body` mediumtext NOT NULL,
  `recipient_name` varchar(150) DEFAULT NULL,
  `recipient_org` varchar(200) DEFAULT NULL,
  `recipient_type` enum('internal','external') NOT NULL DEFAULT 'internal',
  `recipient_user_id` int(11) DEFAULT NULL,
  `related_clearance_id` int(11) DEFAULT NULL,
  `related_request_id` int(11) DEFAULT NULL,
  `status` enum('draft','sent','archived') NOT NULL DEFAULT 'draft',
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `doc_no` varchar(50) DEFAULT NULL,
  `memo_no` varchar(50) DEFAULT NULL,
  `subject_am` varchar(200) DEFAULT NULL,
  `recipient_am` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `letters`
--

INSERT INTO `letters` (`id`, `ref_no`, `sender_id`, `letter_type`, `subject`, `body`, `recipient_name`, `recipient_org`, `recipient_type`, `recipient_user_id`, `related_clearance_id`, `related_request_id`, `status`, `file_path`, `created_at`, `sent_at`, `archived_at`, `deleted_at`, `doc_no`, `memo_no`, `subject_am`, `recipient_am`) VALUES
(1, 'LTR-20261014-CA7A33', 2, 'certificate_letter', 'Certificate of Completion — Meron Tadesse', 'To Whom It May Concern,\n\nThis is to certify that Meron Tadesse (student, Department: Information Technology) has completed all required clearance procedures at Misrak Polytechnic College.\n\nReference: CLR-S-20261014-B9ACFF\nReason: graduation\n\nAll approving departments have signed off. We wish the applicant success in their future engagement.\n\nSincerely,\nMisrak Polytechnic College\nRegistrar\'s Office\nOctober 14, 2026', 'Meron Tadesse', NULL, 'external', NULL, 1, NULL, 'sent', NULL, '2026-10-14 15:59:52', '2026-10-14 18:59:52', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'LTR-20261019-AD8BB1', 2, 'transfer_letter', 'Transfer / Release Letter — Dr. Meseret Tadesse', 'To Whom It May Concern,\n\nThis is to certify that Dr. Meseret Tadesse (employee, Department: Administration) has completed all required clearance procedures at Misrak Polytechnic College.\n\nReference: CLR-E-20261019-E258D1\nReason: graduation\n\nAll approving departments have signed off. We wish the applicant success in their future engagement.\n\nSincerely,\nMisrak Polytechnic College\nRegistrar\'s Office\nOctober 19, 2026', 'Dr. Meseret Tadesse', NULL, 'external', NULL, 2, NULL, 'sent', NULL, '2026-10-19 14:27:37', '2026-10-19 17:27:37', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `letter_transfers`
--

CREATE TABLE `letter_transfers` (
  `id` bigint(20) NOT NULL,
  `letter_id` int(11) NOT NULL,
  `from_user` int(11) DEFAULT NULL,
  `to_user` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `letter_transfers`
--

INSERT INTO `letter_transfers` (`id`, `letter_id`, `from_user`, `to_user`, `action`, `comment`, `created_at`) VALUES
(1, 1, 2, NULL, 'generated', 'Auto-generated from clearance CLR-S-20261014-B9ACFF', '2026-10-14 15:59:52'),
(2, 2, 2, NULL, 'generated', 'Auto-generated from clearance CLR-E-20261019-E258D1', '2026-10-19 14:27:37'),
(3, 2, 1, 2, 'forwarded', '', '2026-10-19 14:54:17');

-- --------------------------------------------------------

--
-- Table structure for table `maternal_details`
--

CREATE TABLE `maternal_details` (
  `request_id` int(11) NOT NULL,
  `type` enum('maternity','paternity','miscarriage','adoption') NOT NULL,
  `spouse_name` varchar(150) DEFAULT NULL,
  `spouse_national_id` varchar(50) DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `actual_delivery_date` date DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_count` int(11) NOT NULL,
  `hospital_name` varchar(200) DEFAULT NULL,
  `medical_cert_path` varchar(255) DEFAULT NULL,
  `birth_cert_path` varchar(255) DEFAULT NULL,
  `return_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `return_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `maternal_details`
--

INSERT INTO `maternal_details` (`request_id`, `type`, `spouse_name`, `spouse_national_id`, `expected_delivery_date`, `actual_delivery_date`, `start_date`, `end_date`, `days_count`, `hospital_name`, `medical_cert_path`, `birth_cert_path`, `return_confirmed`, `return_date`) VALUES
(2, 'maternity', 'Dawit Haile', NULL, '2026-04-20', NULL, '2026-03-22', '2026-07-19', 120, 'Black Lion Hospital', 'uploads/mat_cert_001.pdf', NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` varchar(500) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `type` enum('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pension_details`
--

CREATE TABLE `pension_details` (
  `request_id` int(11) NOT NULL,
  `retirement_date` date NOT NULL,
  `birth_date` date NOT NULL,
  `age_at_retirement` int(11) NOT NULL,
  `hire_date` date NOT NULL,
  `years_of_service` decimal(4,1) NOT NULL,
  `last_salary` decimal(12,2) NOT NULL,
  `avg_monthly_earnings` decimal(12,2) DEFAULT NULL,
  `pension_number` varchar(60) DEFAULT NULL,
  `pension_office` varchar(200) DEFAULT NULL,
  `calculated_pension` decimal(12,2) DEFAULT NULL,
  `service_certificate_issued` tinyint(1) NOT NULL DEFAULT 0,
  `clearance_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pension_details`
--

INSERT INTO `pension_details` (`request_id`, `retirement_date`, `birth_date`, `age_at_retirement`, `hire_date`, `years_of_service`, `last_salary`, `avg_monthly_earnings`, `pension_number`, `pension_office`, `calculated_pension`, `service_certificate_issued`, `clearance_id`) VALUES
(3, '2035-04-19', '1975-04-19', 60, '2005-09-01', 29.6, 17000.00, NULL, NULL, 'Ethiopian Social Security Agency (ESSA)', NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `request_id` int(11) NOT NULL,
  `plan_type` enum('annual','quarterly','semester') NOT NULL,
  `fiscal_year` varchar(20) NOT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `total_budget` decimal(14,2) NOT NULL DEFAULT 0.00,
  `objectives` text DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `published_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `plans`
--

INSERT INTO `plans` (`request_id`, `plan_type`, `fiscal_year`, `period_from`, `period_to`, `total_budget`, `objectives`, `summary`, `published_at`) VALUES
(5, 'annual', '2025-26', '2025-07-08', '2026-07-07', 550000.00, 'Improve student competency in software, networking, and hardware', 'Aligned with TVET Proclamation 954/2016 and TVET Strategy 2025', '2026-10-14 18:59:52');

-- --------------------------------------------------------

--
-- Table structure for table `plan_items`
--

CREATE TABLE `plan_items` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `activity` varchar(255) NOT NULL,
  `indicator` varchar(255) DEFAULT NULL,
  `target` varchar(120) DEFAULT NULL,
  `responsible` varchar(200) DEFAULT NULL,
  `budget` decimal(14,2) NOT NULL DEFAULT 0.00,
  `timeline` varchar(120) DEFAULT NULL,
  `progress_pct` int(11) NOT NULL DEFAULT 0,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `plan_items`
--

INSERT INTO `plan_items` (`id`, `request_id`, `activity`, `indicator`, `target`, `responsible`, `budget`, `timeline`, `progress_pct`, `remarks`) VALUES
(1, 5, 'Conduct Level 4 competency assessment', '% students certified', '90%', 'Dept. Head', 30000.00, 'Q2', 0, NULL),
(2, 5, 'Upgrade IT lab equipment', 'Laptops installed', '10', 'Store + ICT', 450000.00, 'Q1', 0, NULL),
(3, 5, 'Teacher training on new curriculum', 'Teachers trained', '15', 'HR', 60000.00, 'Q3', 0, NULL),
(4, 5, 'Industry partnership MoUs', 'MoUs signed', '3', 'Dept. Head', 10000.00, 'Q4', 0, NULL);

--
-- Triggers `plan_items`
--
DELIMITER $$
CREATE TRIGGER `trg_plan_items_after_delete` AFTER DELETE ON `plan_items` FOR EACH ROW BEGIN
    UPDATE plans
    SET total_budget = (SELECT IFNULL(SUM(budget),0) FROM plan_items WHERE request_id = OLD.request_id)
    WHERE request_id = OLD.request_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_plan_items_after_insert` AFTER INSERT ON `plan_items` FOR EACH ROW BEGIN
    UPDATE plans
    SET total_budget = (SELECT IFNULL(SUM(budget),0) FROM plan_items WHERE request_id = NEW.request_id)
    WHERE request_id = NEW.request_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_plan_items_after_update` AFTER UPDATE ON `plan_items` FOR EACH ROW BEGIN
    UPDATE plans
    SET total_budget = (SELECT IFNULL(SUM(budget),0) FROM plan_items WHERE request_id = NEW.request_id)
    WHERE request_id = NEW.request_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `policy_constants`
--

CREATE TABLE `policy_constants` (
  `id` int(11) NOT NULL,
  `code` varchar(60) NOT NULL,
  `value_num` decimal(12,2) DEFAULT NULL,
  `value_text` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `legal_basis` varchar(255) DEFAULT NULL,
  `effective_from` date NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `policy_constants`
--

INSERT INTO `policy_constants` (`id`, `code`, `value_num`, `value_text`, `description`, `legal_basis`, `effective_from`, `updated_at`) VALUES
(1, 'MATERNITY_DAYS', 120.00, NULL, 'Total paid maternity days', 'Labour Proclamation 1156/2019', '2019-01-01', '2026-10-14 15:52:46'),
(2, 'PATERNITY_DAYS', 3.00, NULL, 'Paid paternity days (Labour)', 'Labour Proclamation 1156/2019', '2019-01-01', '2026-10-14 15:52:46'),
(3, 'PATERNITY_DAYS_CIVIL', 10.00, NULL, 'Paid paternity days (Civil Servants)', 'Civil Servants Proclamation 1353/2024', '2024-01-01', '2026-10-14 15:52:46'),
(4, 'ANNUAL_LEAVE_BASE_DAYS', 20.00, NULL, 'Base annual leave days (year 1)', 'Civil Servants Proclamation 1353/2024', '2024-01-01', '2026-10-14 15:52:46'),
(5, 'ANNUAL_LEAVE_MAX_DAYS', 30.00, NULL, 'Maximum annual leave days', 'Civil Servants Proclamation 1353/2024', '2024-01-01', '2026-10-14 15:52:46'),
(6, 'SICK_LEAVE_FULL_DAYS', 90.00, NULL, 'Sick leave full-pay days', 'Civil Servants Proclamation 1353/2024', '2024-01-01', '2026-10-14 15:52:46'),
(7, 'SICK_LEAVE_HALF_DAYS', 90.00, NULL, 'Sick leave half-pay days', 'Civil Servants Proclamation 1353/2024', '2024-01-01', '2026-10-14 15:52:46'),
(8, 'SICK_LEAVE_UNPAID_DAYS', 60.00, NULL, 'Sick leave unpaid days', 'Civil Servants Proclamation 1353/2024', '2024-01-01', '2026-10-14 15:52:46'),
(9, 'SPECIAL_LEAVE_DAYS', 7.00, NULL, 'Special (mourning/marriage) leave days', 'Civil Servants Proclamation 1353/2024', '2024-01-01', '2026-10-14 15:52:46'),
(10, 'RETIREMENT_AGE', 60.00, NULL, 'Standard retirement age', 'Pension Proclamation 714/2011', '2011-01-01', '2026-10-14 15:52:46'),
(11, 'EARLY_RETIREMENT_AGE', 55.00, NULL, 'Earliest early retirement age', 'Pension Proclamation 714/2011', '2011-01-01', '2026-10-14 15:52:46'),
(12, 'EARLY_RETIREMENT_MIN_YEARS', 25.00, NULL, 'Min years for early retirement', 'Pension Proclamation 714/2011', '2011-01-01', '2026-10-14 15:52:46'),
(13, 'NOTICE_PERIOD_DAYS', 30.00, NULL, 'Resignation notice period (days)', 'Civil Servants Proclamation 1353/2024', '2024-01-01', '2026-10-14 15:52:46'),
(14, 'PROCUREMENT_DIRECTOR_THRESHOLD', 25000.00, NULL, 'Director approval threshold (ETB)', 'Procurement Directive 1073/2025', '2025-01-01', '2026-10-14 15:52:46'),
(15, 'WORKING_HOURS_PER_DAY', 8.00, NULL, 'Working hours per day', 'Civil Service Working Hours Directive', '2020-01-01', '2026-10-14 15:52:46'),
(16, 'SLA_CLEARANCE_DEPT_DAYS', 3.00, NULL, 'SLA: Department clearance step', 'MPC Internal Policy', '2024-01-01', '2026-10-14 15:52:46'),
(17, 'SLA_LEAVE_SHORT_DAYS', 2.00, NULL, 'SLA: Leave <= 5 days', 'MPC Internal Policy', '2024-01-01', '2026-10-14 15:52:46'),
(18, 'SLA_LEAVE_LONG_DAYS', 3.00, NULL, 'SLA: Leave > 5 days', 'MPC Internal Policy', '2024-01-01', '2026-10-14 15:52:46'),
(19, 'SLA_MATERNAL_DAYS', 3.00, NULL, 'SLA: Maternal request', 'MPC Internal Policy', '2024-01-01', '2026-10-14 15:52:46'),
(20, 'SLA_PENSION_DAYS', 10.00, NULL, 'SLA: Pension out', 'MPC Internal Policy', '2024-01-01', '2026-10-14 15:52:46'),
(21, 'SLA_RESIGNATION_DAYS', 5.00, NULL, 'SLA: Resignation', 'MPC Internal Policy', '2024-01-01', '2026-10-14 15:52:46'),
(22, 'SLA_PROCUREMENT_DAYS', 5.00, NULL, 'SLA: Procurement (routine)', 'MPC Internal Policy', '2024-01-01', '2026-10-14 15:52:46'),
(23, 'SLA_PLANNING_DAYS', 15.00, NULL, 'SLA: Planning', 'MPC Internal Policy', '2024-01-01', '2026-10-14 15:52:46');

-- --------------------------------------------------------

--
-- Table structure for table `procurement_items`
--

CREATE TABLE `procurement_items` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `item_name` varchar(200) NOT NULL,
  `specification` text DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit` varchar(30) NOT NULL DEFAULT 'pcs',
  `est_unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `est_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `purpose` varchar(255) DEFAULT NULL,
  `procurement_method` enum('open_bid','restricted_bid','request_for_quotation','direct_purchase','framework','other') DEFAULT NULL,
  `stock_available` tinyint(1) NOT NULL DEFAULT 0,
  `collected` tinyint(1) NOT NULL DEFAULT 0,
  `collected_at` datetime DEFAULT NULL,
  `collected_by` int(11) DEFAULT NULL,
  `collection_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `procurement_items`
--

INSERT INTO `procurement_items` (`id`, `request_id`, `item_name`, `specification`, `quantity`, `unit`, `est_unit_price`, `est_total`, `purpose`, `procurement_method`, `stock_available`, `collected`, `collected_at`, `collected_by`, `collection_note`) VALUES
(1, 4, 'Laptop', 'Core i7, 16GB RAM, 512GB SSD', 10.00, 'pcs', 45000.00, 450000.00, 'IT lab practical sessions', NULL, 0, 1, '2026-10-14 18:59:52', 12, 'Received in good condition'),
(2, 4, 'Projector', 'Full HD, 3500 lumens', 5.00, 'pcs', 22000.00, 110000.00, 'Classroom teaching', NULL, 0, 1, '2026-10-14 18:59:52', 12, 'Received in good condition');

--
-- Triggers `procurement_items`
--
DELIMITER $$
CREATE TRIGGER `trg_pi_before_insert` BEFORE INSERT ON `procurement_items` FOR EACH ROW BEGIN
    SET NEW.est_total = NEW.quantity * NEW.est_unit_price;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pi_before_update` BEFORE UPDATE ON `procurement_items` FOR EACH ROW BEGIN
    SET NEW.est_total = NEW.quantity * NEW.est_unit_price;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `resignation_details`
--

CREATE TABLE `resignation_details` (
  `request_id` int(11) NOT NULL,
  `resignation_date` date NOT NULL,
  `last_working_day` date NOT NULL,
  `notice_period_days` int(11) NOT NULL DEFAULT 30,
  `is_waived_notice` tinyint(1) NOT NULL DEFAULT 0,
  `waiver_approved_by` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `clearance_id` int(11) DEFAULT NULL,
  `exit_interview_done` tinyint(1) NOT NULL DEFAULT 0,
  `exit_interview_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `code`, `name`, `description`, `is_system`, `created_at`) VALUES
(1, 'admin', 'Administrator', 'Full system access', 1, '2026-10-14 15:57:14'),
(2, 'registrar', 'Registrar', 'Student clearance & letters', 1, '2026-10-14 15:57:14'),
(3, 'hr', 'HR Officer', 'HR processes', 1, '2026-10-14 15:57:14'),
(4, 'department_head', 'Department Head', 'First-step approver', 1, '2026-10-14 15:57:14'),
(5, 'library', 'Library Officer', 'Library clearance', 1, '2026-10-14 15:57:14'),
(6, 'dormitory', 'Dormitory Officer', 'Dormitory clearance', 1, '2026-10-14 15:57:14'),
(7, 'store', 'Store Officer', 'Store & procurement', 1, '2026-10-14 15:57:14'),
(8, 'finance', 'Finance Officer', 'Financial approval', 1, '2026-10-14 15:57:14'),
(9, 'planning', 'Planning Officer', 'Plan review', 1, '2026-10-14 15:57:14'),
(10, 'medical', 'Medical Officer', 'Medical verification', 1, '2026-10-14 15:57:14'),
(11, 'director', 'Director / Dean', 'Final approval', 1, '2026-10-14 15:57:14'),
(12, 'employee', 'Employee', 'Regular staff', 1, '2026-10-14 15:57:14'),
(13, 'student', 'Student', 'Trainee student', 1, '2026-10-14 15:57:14'),
(14, 'auditor', 'Auditor', 'Read-only audit access', 1, '2026-10-14 15:57:14');

-- --------------------------------------------------------

--
-- Table structure for table `service_requests`
--

CREATE TABLE `service_requests` (
  `id` int(11) NOT NULL,
  `ref_no` varchar(50) NOT NULL,
  `request_type` enum('leave','maternal','pension','resignation','promotion','training','overtime','procurement','plan','disciplinary','grievance','other') NOT NULL,
  `requester_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `summary` varchar(500) DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status` enum('draft','submitted','in_progress','approved','rejected','cancelled','completed') NOT NULL DEFAULT 'draft',
  `current_step` int(11) NOT NULL DEFAULT 0,
  `total_steps` int(11) NOT NULL DEFAULT 0,
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `sla_due_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `service_requests`
--

INSERT INTO `service_requests` (`id`, `ref_no`, `request_type`, `requester_id`, `department_id`, `title`, `summary`, `priority`, `status`, `current_step`, `total_steps`, `submitted_at`, `approved_at`, `completed_at`, `cancelled_at`, `cancel_reason`, `sla_due_at`, `created_at`, `updated_at`) VALUES
(1, 'REQ-LEAVE-20261014-DA9444', 'leave', 12, 1, 'Annual Leave Request — 10 days', 'Family vacation and personal matters', 'normal', 'approved', 2, 2, '2026-10-14 18:59:52', '2026-10-14 18:59:52', NULL, NULL, NULL, '2026-10-16 18:59:52', '2026-10-14 15:59:52', '2026-10-14 15:59:52'),
(2, 'REQ-MATERNAL-20261014-F294F6', 'maternal', 12, 1, 'Maternity Leave Request', 'Expected delivery in April 2026', 'high', 'approved', 4, 4, '2026-10-14 18:59:52', '2026-10-14 18:59:52', NULL, NULL, NULL, '2026-10-17 18:59:52', '2026-10-14 15:59:52', '2026-10-14 15:59:52'),
(3, 'REQ-PENSION-20261014-CD972F', 'pension', 12, 1, 'Pension-out application', 'Reaching retirement age 60', 'high', 'approved', 4, 4, '2026-10-14 18:59:52', '2026-10-14 18:59:52', NULL, NULL, NULL, '2026-10-24 18:59:52', '2026-10-14 15:59:52', '2026-10-14 15:59:52'),
(4, 'REQ-PROCUREMENT-20261014-1AE6BA', 'procurement', 12, 1, 'Lab equipment for IT department', '10 laptops, 5 projectors', 'normal', 'approved', 4, 4, '2026-10-14 18:59:52', '2026-10-14 18:59:52', NULL, NULL, NULL, '2026-10-19 18:59:52', '2026-10-14 15:59:52', '2026-10-14 15:59:52'),
(5, 'REQ-PLAN-20261014-DA92F8', 'plan', 8, 1, 'IT Department Annual Plan 2025-26', 'Yearly activities, targets and budget', 'normal', 'approved', 3, 3, '2026-10-14 18:59:52', '2026-10-14 18:59:52', NULL, NULL, NULL, '2026-10-29 18:59:52', '2026-10-14 15:59:52', '2026-10-14 15:59:52');

--
-- Triggers `service_requests`
--
DELIMITER $$
CREATE TRIGGER `trg_sr_before_insert` BEFORE INSERT ON `service_requests` FOR EACH ROW BEGIN
    DECLARE v_days INT DEFAULT 5;
    DECLARE v_code VARCHAR(60);

    SET v_code = CASE NEW.request_type
        WHEN 'leave'       THEN 'SLA_LEAVE_SHORT_DAYS'
        WHEN 'maternal'    THEN 'SLA_MATERNAL_DAYS'
        WHEN 'pension'     THEN 'SLA_PENSION_DAYS'
        WHEN 'resignation' THEN 'SLA_RESIGNATION_DAYS'
        WHEN 'procurement' THEN 'SLA_PROCUREMENT_DAYS'
        WHEN 'plan'        THEN 'SLA_PLANNING_DAYS'
        ELSE NULL
    END;

    IF v_code IS NOT NULL THEN
        SELECT value_num INTO v_days FROM policy_constants WHERE code = v_code LIMIT 1;
    END IF;

    SET NEW.sla_due_at = DATE_ADD(NOW(), INTERVAL v_days DAY);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `sessions_log`
--

CREATE TABLE `sessions_log` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `login_at` datetime NOT NULL DEFAULT current_timestamp(),
  `logout_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('doc_no_external', 'OF/MPTC/058'),
('doc_no_internal', 'OF/MPTC/072'),
('org_address', 'አዲስ አበባ Ethiopia / Addis Ababa - Ethiopia'),
('org_email', 'misrakptc961@gmail.com'),
('org_name_am', 'ምስራቅ ፖሊ ቴክኒክ ኮሌጅ'),
('org_name_en', 'MISRAK POLYTECHNIC COLLEGE'),
('org_phones', '011-6-61-03-92, 011-6-18-19-49, 011-6-18-94-98'),
('org_pobox', '785');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `full_name_am` varchar(200) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `employee_code` varchar(40) DEFAULT NULL,
  `student_code` varchar(40) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `position` varchar(120) DEFAULT NULL,
  `employment_type` enum('civil_servant','contract','daily_labor','student') NOT NULL DEFAULT 'civil_servant',
  `gender` enum('male','female','other') NOT NULL DEFAULT 'male',
  `birth_date` date DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `status` enum('active','inactive','suspended','retired','resigned') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `full_name_am`, `email`, `phone`, `national_id`, `employee_code`, `student_code`, `department_id`, `position`, `employment_type`, `gender`, `birth_date`, `hire_date`, `salary`, `status`, `last_login_at`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'admin', '$2y$10$jwDoidvUqQBSizNix6k5IeKVjn07.lvwPzCkleC.FWkzHf/6LraXK', 'System Administrator', NULL, 'admin@misrak.edu.et', NULL, NULL, 'MPC-0001', NULL, 19, 'System Admin', 'civil_servant', 'male', '1985-04-12', '2015-09-01', 18000.00, 'active', '2026-10-19 18:02:08', '2026-10-14 15:57:14', '2026-10-19 15:02:08', NULL),
(2, 'registrar', '$2y$10$jwDoidvUqQBSizNix6k5IeKVjn07.lvwPzCkleC.FWkzHf/6LraXK', 'Abebe Kebede', NULL, 'registrar@misrak.edu.et', NULL, NULL, 'MPC-0010', NULL, 11, 'Registrar', 'civil_servant', 'male', '1980-02-20', '2010-07-01', 22000.00, 'active', '2026-10-19 17:59:44', '2026-10-14 15:57:14', '2026-10-19 14:59:44', NULL),
(3, 'hr', '$2y$10$jwDoidvUqQBSizNix6k5IeKVjn07.lvwPzCkleC.FWkzHf/6LraXK', 'Selam Tesfaye', NULL, 'hr@misrak.edu.et', NULL, NULL, 'MPC-0020', NULL, 12, 'HR Officer', 'civil_servant', 'female', '1987-06-15', '2014-01-10', 19000.00, 'active', '2026-10-19 18:00:05', '2026-10-14 15:57:14', '2026-10-19 15:00:05', NULL),
(4, 'finance', '$2y$10$jwDoidvUqQBSizNix6k5IeKVjn07.lvwPzCkleC.FWkzHf/6LraXK', 'Mulugeta Alemu', NULL, 'finance@misrak.edu.et', NULL, NULL, 'MPC-0030', NULL, 13, 'Finance Officer', 'civil_servant', 'male', '1983-11-05', '2012-03-15', 20000.00, 'active', NULL, '2026-10-14 15:57:14', '2026-10-19 14:26:37', NULL),
(5, 'library', '$2y$10$jwDoidvUqQBSizNix6k5IeKVjn07.lvwPzCkleC.FWkzHf/6LraXK', 'Hanna Girma', NULL, 'library@misrak.edu.et', NULL, NULL, 'MPC-0040', NULL, 14, 'Library Officer', 'civil_servant', 'female', '1990-09-22', '2018-09-01', 15000.00, 'active', NULL, '2026-10-14 15:57:14', '2026-10-19 14:26:44', NULL),
(6, 'dormitory', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Tesfaye Bekele', NULL, 'dorm@misrak.edu.et', NULL, NULL, 'MPC-0050', NULL, 15, 'Dormitory Officer', 'civil_servant', 'male', '1988-01-30', '2016-04-12', 14000.00, 'active', NULL, '2026-10-14 15:57:14', '2026-10-14 15:57:14', NULL),
(7, 'store', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Biruk Assefa', NULL, 'store@misrak.edu.et', NULL, NULL, 'MPC-0060', NULL, 16, 'Store Officer', 'civil_servant', 'male', '1986-05-18', '2013-10-01', 16000.00, 'active', NULL, '2026-10-14 15:57:14', '2026-10-14 15:57:14', NULL),
(8, 'dept_head', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Yohannes Haile', NULL, 'cs@misrak.edu.et', NULL, NULL, 'MPC-0070', NULL, 1, 'Department Head', 'civil_servant', 'male', '1975-07-08', '2008-09-01', 28000.00, 'active', NULL, '2026-10-14 15:57:14', '2026-10-14 15:57:14', NULL),
(9, 'planning', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Genet Alemu', NULL, 'planning@misrak.edu.et', NULL, NULL, 'MPC-0080', NULL, 17, 'Planning Officer', 'civil_servant', 'female', '1985-03-14', '2011-09-01', 21000.00, 'active', NULL, '2026-10-14 15:57:14', '2026-10-14 15:57:14', NULL),
(10, 'medical', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nurse Tigist Bekele', NULL, 'clinic@misrak.edu.et', NULL, NULL, 'MPC-0090', NULL, 18, 'Medical Officer', 'civil_servant', 'female', '1991-12-01', '2019-01-15', 15000.00, 'active', NULL, '2026-10-14 15:57:14', '2026-10-14 15:57:14', NULL),
(11, 'director', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Meseret Tadesse', NULL, 'director@misrak.edu.et', NULL, NULL, 'MPC-0002', NULL, 20, 'College Director', 'civil_servant', 'female', '1972-08-25', '2005-01-01', 45000.00, 'active', NULL, '2026-10-14 15:57:14', '2026-10-14 15:57:14', NULL),
(12, 'employee1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Kalkidan Worku', NULL, 'kalkidan@misrak.edu.et', NULL, NULL, 'MPC-0101', NULL, 1, 'Lecturer', 'civil_servant', 'female', '1992-04-19', '2019-09-01', 17000.00, 'retired', NULL, '2026-10-14 15:57:14', '2026-10-14 15:59:52', NULL),
(13, 'student1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Meron Tadesse', NULL, 'meron@student.misrak.edu.et', NULL, NULL, NULL, 'MPC-STU-2023-0101', 1, 'Level 4 Trainee', 'student', 'female', '2005-06-10', '2023-09-01', NULL, 'active', NULL, '2026-10-14 15:57:14', '2026-10-14 15:57:14', NULL);

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `trg_users_after_update` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
    IF (OLD.full_name <> NEW.full_name
        OR OLD.status <> NEW.status
        OR OLD.department_id <> NEW.department_id
        OR OLD.position <> NEW.position) THEN
        INSERT INTO audit_log (user_id, action, entity, entity_id, old_values, new_values)
        VALUES (
            NEW.id, 'UPDATE', 'users', NEW.id,
            JSON_OBJECT('full_name', OLD.full_name, 'status', OLD.status,
                        'department_id', OLD.department_id, 'position', OLD.position),
            JSON_OBJECT('full_name', NEW.full_name, 'status', NEW.status,
                        'department_id', NEW.department_id, 'position', NEW.position)
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`, `assigned_at`, `assigned_by`) VALUES
(1, 1, '2026-10-14 15:57:14', NULL),
(2, 2, '2026-10-14 15:57:14', NULL),
(3, 3, '2026-10-14 15:57:14', NULL),
(4, 8, '2026-10-14 15:57:14', NULL),
(5, 5, '2026-10-14 15:57:14', NULL),
(6, 6, '2026-10-14 15:57:14', NULL),
(7, 7, '2026-10-14 15:57:14', NULL),
(8, 4, '2026-10-14 15:57:14', NULL),
(9, 9, '2026-10-14 15:57:14', NULL),
(10, 10, '2026-10-14 15:57:14', NULL),
(11, 11, '2026-10-14 15:57:14', NULL),
(12, 12, '2026-10-14 15:57:14', NULL),
(13, 13, '2026-10-14 15:57:14', NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_clearance_status`
-- (See below for the actual view)
--
CREATE TABLE `v_clearance_status` (
`id` int(11)
,`ref_no` varchar(40)
,`applicant_type` enum('student','employee')
,`reason` enum('graduation','class_completion','transfer_college','transfer_region','release_private','release_government','pension','resignation','other')
,`applicant_name` varchar(150)
,`status` enum('pending','in_progress','approved','rejected','completed','cancelled')
,`current_step` int(11)
,`total_steps` int(11)
,`approved_steps` decimal(22,0)
,`pending_steps` decimal(22,0)
,`rejected_steps` decimal(22,0)
,`request_date` date
,`completed_at` datetime
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_dashboard_counts`
-- (See below for the actual view)
--
CREATE TABLE `v_dashboard_counts` (
`active_users` bigint(21)
,`open_clearances` bigint(21)
,`completed_clearances` bigint(21)
,`sent_letters` bigint(21)
,`open_requests` bigint(21)
,`pending_steps` bigint(21)
,`unread_notifications` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_leave_balances_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_leave_balances_summary` (
`user_id` int(11)
,`full_name` varchar(150)
,`employee_code` varchar(40)
,`department` varchar(150)
,`fiscal_year` varchar(20)
,`annual_entitled` int(11)
,`annual_used` int(11)
,`annual_remaining` bigint(13)
,`sick_full_days` int(11)
,`sick_half_days` int(11)
,`sick_unpaid_days` int(11)
,`special_used` int(11)
,`maternity_used` int(11)
,`paternity_used` int(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_letters_overview`
-- (See below for the actual view)
--
CREATE TABLE `v_letters_overview` (
`id` int(11)
,`ref_no` varchar(40)
,`letter_type` enum('internal_memo','external_letter','clearance_letter','certificate_letter','transfer_letter','pension_letter','resignation_letter','maternal_letter','service_certificate')
,`subject` varchar(200)
,`status` enum('draft','sent','archived')
,`recipient_name` varchar(150)
,`recipient_org` varchar(200)
,`recipient_type` enum('internal','external')
,`sender_name` varchar(150)
,`created_at` timestamp
,`sent_at` datetime
,`transfer_count` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_pending_approvals`
-- (See below for the actual view)
--
CREATE TABLE `v_pending_approvals` (
`kind` varchar(9)
,`step_id` int(11)
,`record_id` int(11)
,`ref_no` varchar(50)
,`requester_id` int(11)
,`target_department` varchar(120)
,`target_role` varchar(60)
,`status` varchar(8)
,`created_at` timestamp
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_pending_sla`
-- (See below for the actual view)
--
CREATE TABLE `v_pending_sla` (
`kind` varchar(9)
,`step_id` int(11)
,`ref_no` varchar(50)
,`target` varchar(120)
,`created_at` timestamp
,`hours_waiting` bigint(21)
,`sla_hours` int(2)
,`sla_state` varchar(8)
);

-- --------------------------------------------------------

--
-- Table structure for table `workflow_steps`
--

CREATE TABLE `workflow_steps` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `role_required` varchar(60) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `approver_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected','skipped') NOT NULL DEFAULT 'pending',
  `comment` varchar(255) DEFAULT NULL,
  `sla_due_at` datetime DEFAULT NULL,
  `acted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `workflow_steps`
--

INSERT INTO `workflow_steps` (`id`, `request_id`, `step_order`, `role_required`, `department_id`, `approver_id`, `status`, `comment`, `sla_due_at`, `acted_at`, `created_at`) VALUES
(1, 1, 1, 'department_head', NULL, 8, 'approved', 'Handover plan is clear.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(2, 1, 2, 'hr', NULL, 3, 'approved', 'Balance sufficient.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(3, 2, 1, 'department_head', NULL, 8, 'approved', 'Substitute arranged.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(4, 2, 2, 'hr', NULL, 3, 'approved', 'Verified employment.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(5, 2, 3, 'medical', NULL, 10, 'approved', 'Medical certificate valid.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(6, 2, 4, 'hr', NULL, 3, 'approved', 'Maternity letter issued.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(7, 3, 1, 'department_head', NULL, 8, 'approved', 'Confirmed.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(8, 3, 2, 'hr', NULL, 3, 'approved', 'Service years verified.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(9, 3, 3, 'finance', NULL, 4, 'approved', 'Final settlement done.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(10, 3, 4, 'director', NULL, 11, 'approved', 'Approved.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(11, 4, 1, 'department_head', NULL, 8, 'approved', 'Justified for IT lab.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(12, 4, 2, 'store', NULL, 7, 'approved', 'Not in stock — purchase needed.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(13, 4, 3, 'finance', NULL, 4, 'approved', 'Budget available.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(14, 4, 4, 'director', NULL, 11, 'approved', 'Approved.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(15, 5, 1, 'planning', NULL, 9, 'approved', 'Technically sound.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(16, 5, 2, 'finance', NULL, 4, 'approved', 'Budget feasible.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52'),
(17, 5, 3, 'director', NULL, 11, 'approved', 'Approved for implementation.', NULL, '2026-10-14 18:59:52', '2026-10-14 15:59:52');

-- --------------------------------------------------------

--
-- Structure for view `v_clearance_status`
--
DROP TABLE IF EXISTS `v_clearance_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_clearance_status`  AS SELECT `cr`.`id` AS `id`, `cr`.`ref_no` AS `ref_no`, `cr`.`applicant_type` AS `applicant_type`, `cr`.`reason` AS `reason`, `u`.`full_name` AS `applicant_name`, `cr`.`status` AS `status`, `cr`.`current_step` AS `current_step`, `cr`.`total_steps` AS `total_steps`, sum(case when `ca`.`status` = 'approved' then 1 else 0 end) AS `approved_steps`, sum(case when `ca`.`status` = 'pending' then 1 else 0 end) AS `pending_steps`, sum(case when `ca`.`status` = 'rejected' then 1 else 0 end) AS `rejected_steps`, `cr`.`request_date` AS `request_date`, `cr`.`completed_at` AS `completed_at` FROM ((`clearance_requests` `cr` join `users` `u` on(`u`.`id` = `cr`.`applicant_id`)) left join `clearance_approvals` `ca` on(`ca`.`clearance_id` = `cr`.`id`)) GROUP BY `cr`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_dashboard_counts`
--
DROP TABLE IF EXISTS `v_dashboard_counts`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_dashboard_counts`  AS SELECT (select count(0) from `users` where `users`.`status` = 'active') AS `active_users`, (select count(0) from `clearance_requests` where `clearance_requests`.`status` in ('pending','in_progress')) AS `open_clearances`, (select count(0) from `clearance_requests` where `clearance_requests`.`status` = 'completed') AS `completed_clearances`, (select count(0) from `letters` where `letters`.`status` = 'sent') AS `sent_letters`, (select count(0) from `service_requests` where `service_requests`.`status` in ('submitted','in_progress')) AS `open_requests`, (select count(0) from `workflow_steps` where `workflow_steps`.`status` = 'pending') AS `pending_steps`, (select count(0) from `notifications` where `notifications`.`is_read` = 0) AS `unread_notifications` ;

-- --------------------------------------------------------

--
-- Structure for view `v_leave_balances_summary`
--
DROP TABLE IF EXISTS `v_leave_balances_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_leave_balances_summary`  AS SELECT `u`.`id` AS `user_id`, `u`.`full_name` AS `full_name`, `u`.`employee_code` AS `employee_code`, `d`.`name` AS `department`, `hb`.`fiscal_year` AS `fiscal_year`, `hb`.`annual_entitled` AS `annual_entitled`, `hb`.`annual_used` AS `annual_used`, `hb`.`annual_entitled`+ `hb`.`annual_carried` - `hb`.`annual_used` AS `annual_remaining`, `hb`.`sick_full_days` AS `sick_full_days`, `hb`.`sick_half_days` AS `sick_half_days`, `hb`.`sick_unpaid_days` AS `sick_unpaid_days`, `hb`.`special_used` AS `special_used`, `hb`.`maternity_used` AS `maternity_used`, `hb`.`paternity_used` AS `paternity_used` FROM ((`hr_balances` `hb` join `users` `u` on(`u`.`id` = `hb`.`user_id`)) left join `departments` `d` on(`d`.`id` = `u`.`department_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_letters_overview`
--
DROP TABLE IF EXISTS `v_letters_overview`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_letters_overview`  AS SELECT `l`.`id` AS `id`, `l`.`ref_no` AS `ref_no`, `l`.`letter_type` AS `letter_type`, `l`.`subject` AS `subject`, `l`.`status` AS `status`, `l`.`recipient_name` AS `recipient_name`, `l`.`recipient_org` AS `recipient_org`, `l`.`recipient_type` AS `recipient_type`, `s`.`full_name` AS `sender_name`, `l`.`created_at` AS `created_at`, `l`.`sent_at` AS `sent_at`, (select count(0) from `letter_transfers` `t` where `t`.`letter_id` = `l`.`id`) AS `transfer_count` FROM (`letters` `l` join `users` `s` on(`s`.`id` = `l`.`sender_id`)) WHERE `l`.`deleted_at` is null ;

-- --------------------------------------------------------

--
-- Structure for view `v_pending_approvals`
--
DROP TABLE IF EXISTS `v_pending_approvals`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_pending_approvals`  AS SELECT 'clearance' AS `kind`, `ca`.`id` AS `step_id`, `cr`.`id` AS `record_id`, `cr`.`ref_no` AS `ref_no`, `cr`.`applicant_id` AS `requester_id`, `ca`.`department` AS `target_department`, NULL AS `target_role`, `ca`.`status` AS `status`, `ca`.`created_at` AS `created_at` FROM (`clearance_approvals` `ca` join `clearance_requests` `cr` on(`cr`.`id` = `ca`.`clearance_id`)) WHERE `ca`.`status` = 'pending' AND `cr`.`status` in ('pending','in_progress')union all select 'workflow' AS `workflow`,`ws`.`id` AS `id`,`sr`.`id` AS `id`,`sr`.`ref_no` AS `ref_no`,`sr`.`requester_id` AS `requester_id`,NULL AS `NULL`,`ws`.`role_required` AS `role_required`,`ws`.`status` AS `status`,`ws`.`created_at` AS `created_at` from (`workflow_steps` `ws` join `service_requests` `sr` on(`sr`.`id` = `ws`.`request_id`)) where `ws`.`status` = 'pending' and `sr`.`status` in ('submitted','in_progress')  ;

-- --------------------------------------------------------

--
-- Structure for view `v_pending_sla`
--
DROP TABLE IF EXISTS `v_pending_sla`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_pending_sla`  AS SELECT 'clearance' AS `kind`, `ca`.`id` AS `step_id`, `cr`.`ref_no` AS `ref_no`, `ca`.`department` AS `target`, `ca`.`created_at` AS `created_at`, timestampdiff(HOUR,`ca`.`created_at`,current_timestamp()) AS `hours_waiting`, 72 AS `sla_hours`, CASE WHEN timestampdiff(HOUR,`ca`.`created_at`,current_timestamp()) > 72 THEN 'BREACHED' WHEN timestampdiff(HOUR,`ca`.`created_at`,current_timestamp()) > 60 THEN 'AT_RISK' ELSE 'OK' END AS `sla_state` FROM (`clearance_approvals` `ca` join `clearance_requests` `cr` on(`cr`.`id` = `ca`.`clearance_id`)) WHERE `ca`.`status` = 'pending' AND `cr`.`status` in ('pending','in_progress')union all select 'workflow' AS `workflow`,`ws`.`id` AS `id`,`sr`.`ref_no` AS `ref_no`,`ws`.`role_required` AS `role_required`,`ws`.`created_at` AS `created_at`,timestampdiff(HOUR,`ws`.`created_at`,current_timestamp()) AS `TIMESTAMPDIFF(HOUR, ws.created_at, NOW())`,72 AS `72`,case when timestampdiff(HOUR,`ws`.`created_at`,current_timestamp()) > 72 then 'BREACHED' when timestampdiff(HOUR,`ws`.`created_at`,current_timestamp()) > 60 then 'AT_RISK' else 'OK' end AS `Name_exp_8` from (`workflow_steps` `ws` join `service_requests` `sr` on(`sr`.`id` = `ws`.`request_id`)) where `ws`.`status` = 'pending' and `sr`.`status` in ('submitted','in_progress')  ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attachments`
--
ALTER TABLE `attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_att_user` (`uploaded_by`),
  ADD KEY `idx_att_entity` (`entity`,`entity_id`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_al_entity` (`entity`,`entity_id`),
  ADD KEY `idx_al_user` (`user_id`),
  ADD KEY `idx_al_created` (`created_at`);

--
-- Indexes for table `clearance_approvals`
--
ALTER TABLE `clearance_approvals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ca_step` (`clearance_id`,`step_order`),
  ADD KEY `fk_ca_approver` (`approver_id`),
  ADD KEY `idx_ca_status` (`status`),
  ADD KEY `idx_ca_dept` (`department`);

--
-- Indexes for table `clearance_requests`
--
ALTER TABLE `clearance_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ref_no` (`ref_no`),
  ADD KEY `fk_cr_created` (`created_by`),
  ADD KEY `idx_cr_status` (`status`),
  ADD KEY `idx_cr_type` (`applicant_type`),
  ADD KEY `idx_cr_applicant` (`applicant_id`),
  ADD KEY `idx_cr_dept` (`department_id`),
  ADD KEY `idx_cr_created` (`created_at`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_dept_type` (`type`),
  ADD KEY `idx_dept_active` (`is_active`);

--
-- Indexes for table `hr_balances`
--
ALTER TABLE `hr_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_hb_user_year` (`user_id`,`fiscal_year`),
  ADD KEY `idx_hb_year` (`fiscal_year`);

--
-- Indexes for table `leave_details`
--
ALTER TABLE `leave_details`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `fk_ld_handover` (`handover_to`),
  ADD KEY `idx_ld_type` (`leave_type`),
  ADD KEY `idx_ld_dates` (`start_date`,`end_date`);

--
-- Indexes for table `letters`
--
ALTER TABLE `letters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ref_no` (`ref_no`),
  ADD KEY `fk_l_recipient` (`recipient_user_id`),
  ADD KEY `fk_l_clearance` (`related_clearance_id`),
  ADD KEY `idx_l_type` (`letter_type`),
  ADD KEY `idx_l_status` (`status`),
  ADD KEY `idx_l_sender` (`sender_id`),
  ADD KEY `idx_l_created` (`created_at`);
ALTER TABLE `letters` ADD FULLTEXT KEY `ft_l_search` (`subject`,`body`);

--
-- Indexes for table `letter_transfers`
--
ALTER TABLE `letter_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lt_from` (`from_user`),
  ADD KEY `fk_lt_to` (`to_user`),
  ADD KEY `idx_lt_letter` (`letter_id`),
  ADD KEY `idx_lt_created` (`created_at`);

--
-- Indexes for table `maternal_details`
--
ALTER TABLE `maternal_details`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_md_type` (`type`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_n_user_unread` (`user_id`,`is_read`),
  ADD KEY `idx_n_created` (`created_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pr_user` (`user_id`),
  ADD KEY `idx_pr_expires` (`expires_at`);

--
-- Indexes for table `pension_details`
--
ALTER TABLE `pension_details`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `fk_pd_clearance` (`clearance_id`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_p_type` (`plan_type`),
  ADD KEY `idx_p_year` (`fiscal_year`);

--
-- Indexes for table `plan_items`
--
ALTER TABLE `plan_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pi_request` (`request_id`);

--
-- Indexes for table `policy_constants`
--
ALTER TABLE `policy_constants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `procurement_items`
--
ALTER TABLE `procurement_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pr_collector` (`collected_by`),
  ADD KEY `idx_pr_collect` (`collected`),
  ADD KEY `idx_pr_request` (`request_id`);

--
-- Indexes for table `resignation_details`
--
ALTER TABLE `resignation_details`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `fk_rd_clearance` (`clearance_id`),
  ADD KEY `fk_rd_waiver` (`waiver_approved_by`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ref_no` (`ref_no`),
  ADD KEY `idx_sr_type` (`request_type`),
  ADD KEY `idx_sr_status` (`status`),
  ADD KEY `idx_sr_requester` (`requester_id`),
  ADD KEY `idx_sr_dept` (`department_id`),
  ADD KEY `idx_sr_sla` (`sla_due_at`),
  ADD KEY `idx_sr_created` (`created_at`);

--
-- Indexes for table `sessions_log`
--
ALTER TABLE `sessions_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sl_user` (`user_id`),
  ADD KEY `idx_sl_login` (`login_at`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD UNIQUE KEY `student_code` (`student_code`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_dept` (`department_id`),
  ADD KEY `idx_users_emp_type` (`employment_type`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `fk_ur_role` (`role_id`),
  ADD KEY `fk_ur_assigned` (`assigned_by`);

--
-- Indexes for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ws_step` (`request_id`,`step_order`),
  ADD KEY `fk_ws_dept` (`department_id`),
  ADD KEY `fk_ws_approver` (`approver_id`),
  ADD KEY `idx_ws_status` (`status`),
  ADD KEY `idx_ws_role` (`role_required`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attachments`
--
ALTER TABLE `attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `clearance_approvals`
--
ALTER TABLE `clearance_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `clearance_requests`
--
ALTER TABLE `clearance_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `hr_balances`
--
ALTER TABLE `hr_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `letters`
--
ALTER TABLE `letters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `letter_transfers`
--
ALTER TABLE `letter_transfers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plan_items`
--
ALTER TABLE `plan_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `policy_constants`
--
ALTER TABLE `policy_constants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `procurement_items`
--
ALTER TABLE `procurement_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `service_requests`
--
ALTER TABLE `service_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sessions_log`
--
ALTER TABLE `sessions_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attachments`
--
ALTER TABLE `attachments`
  ADD CONSTRAINT `fk_att_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `clearance_approvals`
--
ALTER TABLE `clearance_approvals`
  ADD CONSTRAINT `fk_ca_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ca_clearance` FOREIGN KEY (`clearance_id`) REFERENCES `clearance_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `clearance_requests`
--
ALTER TABLE `clearance_requests`
  ADD CONSTRAINT `fk_cr_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cr_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cr_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `hr_balances`
--
ALTER TABLE `hr_balances`
  ADD CONSTRAINT `fk_hb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_details`
--
ALTER TABLE `leave_details`
  ADD CONSTRAINT `fk_ld_handover` FOREIGN KEY (`handover_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ld_request` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `letters`
--
ALTER TABLE `letters`
  ADD CONSTRAINT `fk_l_clearance` FOREIGN KEY (`related_clearance_id`) REFERENCES `clearance_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_l_recipient` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_l_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `letter_transfers`
--
ALTER TABLE `letter_transfers`
  ADD CONSTRAINT `fk_lt_from` FOREIGN KEY (`from_user`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_lt_letter` FOREIGN KEY (`letter_id`) REFERENCES `letters` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lt_to` FOREIGN KEY (`to_user`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `maternal_details`
--
ALTER TABLE `maternal_details`
  ADD CONSTRAINT `fk_md_request` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_n_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pension_details`
--
ALTER TABLE `pension_details`
  ADD CONSTRAINT `fk_pd_clearance` FOREIGN KEY (`clearance_id`) REFERENCES `clearance_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pd_request` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `plans`
--
ALTER TABLE `plans`
  ADD CONSTRAINT `fk_p_request` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `plan_items`
--
ALTER TABLE `plan_items`
  ADD CONSTRAINT `fk_pi_request` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `procurement_items`
--
ALTER TABLE `procurement_items`
  ADD CONSTRAINT `fk_pr_collector` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pr_request` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `resignation_details`
--
ALTER TABLE `resignation_details`
  ADD CONSTRAINT `fk_rd_clearance` FOREIGN KEY (`clearance_id`) REFERENCES `clearance_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rd_request` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rd_waiver` FOREIGN KEY (`waiver_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD CONSTRAINT `fk_sr_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sr_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions_log`
--
ALTER TABLE `sessions_log`
  ADD CONSTRAINT `fk_sl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_ur_assigned` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  ADD CONSTRAINT `fk_ws_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ws_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ws_request` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
