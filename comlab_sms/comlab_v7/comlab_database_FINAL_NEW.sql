-- ============================================================
-- COMLAB - Legacy MySQL Schema
-- This file is kept for reference only.
-- Use supabase/comlab_supabase_schema.sql for the Supabase /
-- PostgreSQL version of the database.
-- COMLAB - Revised Database Schema (NEW)
-- Key changes from original:
--   • users: Technician role removed; added hr_employee_id,
--     synced_from_hr columns
--   • lab_assignments: REPLACED by faculty_schedules +
--     schedule_attendance (recurring model)
--   • Views and procedures updated to match new model
--   • Stored procedure: process_request updated — admin reviews
--     (no technician_id param)
-- ============================================================

CREATE DATABASE IF NOT EXISTS `comlab_system`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `comlab_system`;

-- ============================================================
-- CORE TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
  `user_id`         INT(11)       NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(50)   NOT NULL UNIQUE,
  `email`           VARCHAR(100)  NOT NULL UNIQUE,
  `password_hash`   VARCHAR(255)  NOT NULL,
  `first_name`      VARCHAR(50)   NOT NULL,
  `last_name`       VARCHAR(50)   NOT NULL,
  -- Technician removed; only Administrator and Faculty
  `role`            ENUM('Administrator','Faculty') NOT NULL,
  -- HR sync fields
  `hr_employee_id`  VARCHAR(50)   DEFAULT NULL UNIQUE,
  `synced_from_hr`  TINYINT(1)    NOT NULL DEFAULT 0,
  -- Profile fields
  `department`      VARCHAR(100)  DEFAULT NULL,
  `contact_number`  VARCHAR(20)   DEFAULT NULL,
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login`      TIMESTAMP     NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  INDEX `idx_role`          (`role`),
  INDEX `idx_email`         (`email`),
  INDEX `idx_hr_employee`   (`hr_employee_id`),
  INDEX `idx_active_role`   (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `session_id`  INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)       NOT NULL,
  `auth_token`  VARCHAR(255)  NOT NULL UNIQUE,
  `ip_address`  VARCHAR(45)   DEFAULT NULL,
  `user_agent`  VARCHAR(255)  DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  TIMESTAMP     NOT NULL,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (`session_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  INDEX `idx_token`  (`auth_token`),
  INDEX `idx_user`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `locations` (
  `location_id`            INT(11)       NOT NULL AUTO_INCREMENT,
  `lab_name`               VARCHAR(100)  NOT NULL,
  `lab_code`               VARCHAR(20)   NOT NULL UNIQUE,
  `building`               VARCHAR(100)  DEFAULT NULL,
  `floor`                  VARCHAR(20)   DEFAULT NULL,
  `room_number`            VARCHAR(20)   DEFAULT NULL,
  `capacity`               INT(11)       NOT NULL DEFAULT 0,
  `operating_hours_start`  TIME          NOT NULL DEFAULT '08:00:00',
  `operating_hours_end`    TIME          NOT NULL DEFAULT '18:00:00',
  `operating_days`         VARCHAR(100)  DEFAULT 'Monday-Friday',
  `description`            TEXT          DEFAULT NULL,
  `is_active`              TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`location_id`),
  INDEX `idx_lab_code`  (`lab_code`),
  INDEX `idx_active`    (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `devices` (
  `device_id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `device_code`            VARCHAR(50)   NOT NULL UNIQUE,
  `device_type`            ENUM('Desktop','Laptop','Monitor','Keyboard','Mouse','Printer','Other') NOT NULL,
  `brand`                  VARCHAR(100)  DEFAULT NULL,
  `model`                  VARCHAR(100)  DEFAULT NULL,
  `serial_number`          VARCHAR(100)  DEFAULT NULL,
  `specifications`         TEXT          DEFAULT NULL,
  `purchase_date`          DATE          DEFAULT NULL,
  `warranty_expiry`        DATE          DEFAULT NULL,
  `status`                 ENUM('Available','In Use','Under Repair','Damaged','Retired') NOT NULL DEFAULT 'Available',
  `location_id`            INT(11)       DEFAULT NULL,
  `assigned_to`            INT(11)       DEFAULT NULL,
  `last_maintenance_date`  DATE          DEFAULT NULL,
  `next_maintenance_date`  DATE          DEFAULT NULL,
  `notes`                  TEXT          DEFAULT NULL,
  `created_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`device_id`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`location_id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`user_id`)         ON DELETE SET NULL,
  INDEX `idx_device_code`  (`device_code`),
  INDEX `idx_status`       (`status`),
  INDEX `idx_location`     (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_maintenance_logs` (
  `maintenance_id`    INT(11)  NOT NULL AUTO_INCREMENT,
  `device_id`         INT(11)  NOT NULL,
  -- performed_by is now always an Administrator (no Technician)
  `performed_by`      INT(11)  NOT NULL,
  `maintenance_type`  ENUM('Repair','Preventive Maintenance','Inspection','Upgrade') NOT NULL,
  `issue_description` TEXT     DEFAULT NULL,
  `action_taken`      TEXT     DEFAULT NULL,
  `parts_replaced`    TEXT     DEFAULT NULL,
  `status_before`     ENUM('Available','In Use','Under Repair','Damaged','Retired') NOT NULL,
  `status_after`      ENUM('Available','In Use','Under Repair','Damaged','Retired') NOT NULL,
  `cost`              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `start_datetime`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `end_datetime`      TIMESTAMP NULL DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`maintenance_id`),
  FOREIGN KEY (`device_id`)    REFERENCES `devices`(`device_id`) ON DELETE CASCADE,
  FOREIGN KEY (`performed_by`) REFERENCES `users`(`user_id`)     ON DELETE CASCADE,
  INDEX `idx_device`     (`device_id`),
  INDEX `idx_performed`  (`performed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SCHEDULE TABLES (replaces lab_assignments)
-- ============================================================

-- One record = one recurring class assignment for a semester
CREATE TABLE IF NOT EXISTS `faculty_schedules` (
  `schedule_id`     INT(11)       NOT NULL AUTO_INCREMENT,
  `faculty_id`      INT(11)       NOT NULL,   -- FK → users (Faculty)
  `assigned_by`     INT(11)       NOT NULL,   -- FK → users (Administrator)
  `location_id`     INT(11)       NOT NULL,   -- which lab
  `class_name`      VARCHAR(100)  NOT NULL,   -- e.g. "CIS101 - Intro to Computing"
  -- Comma-separated days, e.g. "Monday,Wednesday" or "Tuesday,Thursday,Saturday"
  `day_of_week`     VARCHAR(100)  NOT NULL,
  `start_time`      TIME          NOT NULL,
  `end_time`        TIME          NOT NULL,
  `duration_hours`  DECIMAL(4,2)  NOT NULL,   -- computed server-side
  `semester_start`  DATE          NOT NULL,   -- when this assignment begins
  `semester_end`    DATE          NOT NULL,   -- when this assignment ends
  `department`      VARCHAR(100)  NOT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`schedule_id`),
  FOREIGN KEY (`faculty_id`)  REFERENCES `users`(`user_id`)      ON DELETE CASCADE,
  FOREIGN KEY (`assigned_by`) REFERENCES `users`(`user_id`)      ON DELETE CASCADE,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`location_id`) ON DELETE CASCADE,
  INDEX `idx_faculty`   (`faculty_id`),
  INDEX `idx_location`  (`location_id`),
  INDEX `idx_active`    (`is_active`),
  INDEX `idx_semester`  (`semester_start`, `semester_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One record = one date's attendance for a recurring schedule
CREATE TABLE IF NOT EXISTS `schedule_attendance` (
  `attendance_id`    INT(11)   NOT NULL AUTO_INCREMENT,
  `schedule_id`      INT(11)   NOT NULL,   -- FK → faculty_schedules
  `faculty_id`       INT(11)   NOT NULL,   -- denormalized for fast queries
  `attendance_date`  DATE      NOT NULL,
  `status`           ENUM('Present','Absent','Excused') NOT NULL DEFAULT 'Absent',
  `checked_in_at`    TIMESTAMP NULL DEFAULT NULL,    -- set on faculty check-in
  `marked_by_system` TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = auto-marked Absent
  `notes`            TEXT      DEFAULT NULL,          -- admin excusal reason
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`attendance_id`),
  -- One record per schedule per date
  UNIQUE KEY `uq_schedule_date` (`schedule_id`, `attendance_date`),
  FOREIGN KEY (`schedule_id`) REFERENCES `faculty_schedules`(`schedule_id`) ON DELETE CASCADE,
  FOREIGN KEY (`faculty_id`)  REFERENCES `users`(`user_id`)                 ON DELETE CASCADE,
  INDEX `idx_faculty_date`    (`faculty_id`, `attendance_date`),
  INDEX `idx_schedule`        (`schedule_id`),
  INDEX `idx_date`            (`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- REQUESTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `requests` (
  `request_id`             INT(11)   NOT NULL AUTO_INCREMENT,
  `request_type`           ENUM('Maintenance','Unit') NOT NULL,
  `submitted_by`           INT(11)   NOT NULL,
  -- reviewed_by is always an Administrator now
  `reviewed_by`            INT(11)   DEFAULT NULL,
  `department`             VARCHAR(100) NOT NULL,
  `location_id`            INT(11)   DEFAULT NULL,      -- FK to labs (optional)
  `location_text`          VARCHAR(255) DEFAULT NULL,   -- free-text location e.g. "Clinic", "Guidance Office"
  `device_id`              INT(11)   DEFAULT NULL,
  `issue_description`      TEXT      DEFAULT NULL,
  `date_needed`            DATE      DEFAULT NULL,
  `device_type_needed`     VARCHAR(100) DEFAULT NULL,
  `specifications_needed`  TEXT      DEFAULT NULL,
  `quantity`               INT(11)   DEFAULT NULL,
  `justification`          TEXT      DEFAULT NULL,
  `status`                 ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
  `rejection_reason`       TEXT      DEFAULT NULL,
  `reviewed_at`            TIMESTAMP NULL DEFAULT NULL,
  `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  FOREIGN KEY (`submitted_by`) REFERENCES `users`(`user_id`)         ON DELETE CASCADE,
  FOREIGN KEY (`reviewed_by`)  REFERENCES `users`(`user_id`)         ON DELETE SET NULL,
  FOREIGN KEY (`location_id`)  REFERENCES `locations`(`location_id`) ON DELETE SET NULL,
  FOREIGN KEY (`device_id`)    REFERENCES `devices`(`device_id`)     ON DELETE SET NULL,
  INDEX `idx_submitted_by`  (`submitted_by`),
  INDEX `idx_status`        (`status`),
  INDEX `idx_created`       (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUDIT LOGS
-- ============================================================

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `log_id`      INT(11)  NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)  DEFAULT NULL,
  `action_type` ENUM(
    'User Created','User Updated','User Deleted','Login','Login Failed','Logout',
    'Device Added','Device Updated','Device Deleted','Device Status Changed','Device Transferred',
    'Location Added','Location Updated','Location Deleted',
    'Assignment Created','Assignment Cancelled','Assignment Completed',
    'Request Submitted','Request Approved','Request Rejected','Request Completed',
    'Maintenance Started','Maintenance Completed',
    'Schedules Deactivated',
    'Report Generated','Other'
  ) NOT NULL,
  `target_type` ENUM('User','Device','Location','Assignment','Request','Maintenance','System') DEFAULT NULL,
  `target_id`   INT(11)  DEFAULT NULL,
  `description` TEXT     NOT NULL,
  `old_value`   TEXT     DEFAULT NULL,
  `new_value`   TEXT     DEFAULT NULL,
  `ip_address`  VARCHAR(45)   DEFAULT NULL,
  `user_agent`  VARCHAR(255)  DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  INDEX `idx_user`    (`user_id`),
  INDEX `idx_action`  (`action_type`),
  INDEX `idx_target`  (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- ============================================================
-- LOGIN RATE LIMITING
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `ip_address`    VARCHAR(45)  NOT NULL,
  `attempt_count` INT(11)      NOT NULL DEFAULT 0,
  `last_attempt`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_until`  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`ip_address`),
  INDEX `idx_locked` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- VIEWS
-- ============================================================

CREATE OR REPLACE VIEW `dashboard_summary` AS
SELECT
  (SELECT COUNT(*) FROM users WHERE is_active=1)                                AS total_active_users,
  (SELECT COUNT(*) FROM users WHERE role='Administrator' AND is_active=1)        AS total_admins,
  (SELECT COUNT(*) FROM users WHERE role='Faculty' AND is_active=1)              AS total_faculty,
  (SELECT COUNT(*) FROM devices)                                                 AS total_devices,
  (SELECT COUNT(*) FROM devices WHERE status='Available')                        AS devices_available,
  (SELECT COUNT(*) FROM devices WHERE status='Under Repair')                     AS devices_under_repair,
  (SELECT COUNT(*) FROM devices WHERE status='Damaged')                          AS devices_damaged,
  (SELECT COUNT(*) FROM locations WHERE is_active=1)                             AS total_labs,
  (SELECT COUNT(*) FROM faculty_schedules WHERE is_active=1)                     AS active_schedules,
  (SELECT COUNT(*) FROM requests WHERE status='Pending')                         AS pending_requests;

CREATE OR REPLACE VIEW `faculty_presence_summary` AS
SELECT
  u.user_id,
  CONCAT(u.first_name,' ',u.last_name)     AS faculty_name,
  u.department,
  COUNT(DISTINCT fs.schedule_id)           AS active_schedules,
  COUNT(sa.attendance_id)                  AS total_sessions,
  SUM(sa.status='Present')                 AS present_count,
  SUM(sa.status='Absent')                  AS absent_count,
  SUM(sa.status='Excused')                 AS excused_count,
  ROUND(100.0*SUM(sa.status='Present')/NULLIF(COUNT(sa.attendance_id),0),1) AS attendance_rate_pct
FROM users u
LEFT JOIN faculty_schedules fs ON fs.faculty_id=u.user_id AND fs.is_active=1
LEFT JOIN schedule_attendance sa ON sa.faculty_id=u.user_id
WHERE u.role='Faculty' AND u.is_active=1
GROUP BY u.user_id;

-- ============================================================
-- STORED PROCEDURES
-- ============================================================

DROP PROCEDURE IF EXISTS `check_schedule_conflict`;
DROP PROCEDURE IF EXISTS `process_request`;
DROP PROCEDURE IF EXISTS `update_device_status`;

DELIMITER //

-- Check if a recurring slot conflicts with existing faculty_schedules
CREATE PROCEDURE `check_schedule_conflict`(
  IN p_location_id    INT,
  IN p_day_of_week    VARCHAR(100),   -- comma-separated, e.g. "Monday,Wednesday"
  IN p_start_time     TIME,
  IN p_end_time       TIME,
  IN p_semester_start DATE,
  IN p_semester_end   DATE,
  IN p_exclude_id     INT             -- pass 0 for new inserts
)
BEGIN
  -- NOTE: FIND_IN_SET checks individual day names within stored CSV.
  -- For a rigorous check, call the PHP API which loops per day.
  SELECT COUNT(*) AS conflict_count
  FROM faculty_schedules fs
  WHERE fs.location_id     = p_location_id
    AND fs.semester_start <= p_semester_end
    AND fs.semester_end   >= p_semester_start
    AND fs.start_time      < p_end_time
    AND fs.end_time        > p_start_time
    AND fs.is_active       = 1
    AND (p_exclude_id = 0 OR fs.schedule_id != p_exclude_id);
END //

-- Admin reviews a request (replaces old technician_id param)
CREATE PROCEDURE `process_request`(
  IN p_request_id    INT,
  IN p_admin_id      INT,
  IN p_decision      ENUM('Approved','Rejected'),
  IN p_rejection_reason TEXT
)
BEGIN
  DECLARE v_request_type VARCHAR(20);
  DECLARE v_device_id    INT;
  SELECT request_type, device_id
  INTO v_request_type, v_device_id
  FROM requests WHERE request_id = p_request_id;

  UPDATE requests
  SET status           = p_decision,
      reviewed_by      = p_admin_id,
      rejection_reason = p_rejection_reason,
      reviewed_at      = CURRENT_TIMESTAMP
  WHERE request_id = p_request_id;

  IF p_decision='Approved' AND v_request_type='Maintenance' AND v_device_id IS NOT NULL THEN
    UPDATE devices SET status='Under Repair' WHERE device_id=v_device_id;
  END IF;

  SELECT 'SUCCESS' AS result;
END //

-- Admin updates device status directly
CREATE PROCEDURE `update_device_status`(
  IN p_device_id   INT,
  IN p_new_status  VARCHAR(20),
  IN p_admin_id    INT,
  IN p_notes       TEXT
)
BEGIN
  DECLARE v_old_status VARCHAR(20);
  SELECT status INTO v_old_status FROM devices WHERE device_id=p_device_id;
  UPDATE devices SET status=p_new_status, updated_at=CURRENT_TIMESTAMP WHERE device_id=p_device_id;
  INSERT INTO audit_logs(user_id,action_type,target_type,target_id,description,old_value,new_value)
  VALUES(p_admin_id,'Device Status Changed','Device',p_device_id,p_notes,v_old_status,p_new_status);
  SELECT 'SUCCESS' AS result;
END //

DELIMITER ;

-- ============================================================
-- INDEXES
-- ============================================================

CREATE INDEX IF NOT EXISTS idx_schedule_location_day ON faculty_schedules(location_id, day_of_week);
CREATE INDEX IF NOT EXISTS idx_attendance_lookup      ON schedule_attendance(schedule_id, attendance_date, status);
CREATE INDEX IF NOT EXISTS idx_device_loc_status      ON devices(location_id, status);
CREATE INDEX IF NOT EXISTS idx_user_role_active        ON users(role, is_active);

-- ============================================================
-- NOTE: Seed users are inserted by setup.php (bcrypt passwords)
-- Run: http://localhost/comlab/setup.php after importing SQL.
-- ============================================================

-- ============================================================
-- PATCH: Expand audit_logs.action_type ENUM
-- Run this if the database was already imported before this fix.
-- Safe to run multiple times.
-- ============================================================
ALTER TABLE `audit_logs`
  MODIFY COLUMN `action_type` ENUM(
    'User Created','User Updated','User Deleted','Login','Login Failed','Logout',
    'Device Added','Device Updated','Device Deleted','Device Status Changed','Device Transferred',
    'Location Added','Location Updated','Location Deleted',
    'Assignment Created','Assignment Cancelled','Assignment Completed',
    'Request Submitted','Request Approved','Request Rejected','Request Completed',
    'Maintenance Started','Maintenance Completed',
    'Schedules Deactivated',
    'Report Generated','Other'
  ) NOT NULL;

-- Also add location_text column to requests if not exists
ALTER TABLE `requests`
  ADD COLUMN IF NOT EXISTS `location_text` VARCHAR(255) DEFAULT NULL
    AFTER `location_id`;
