-- COMLAB seed data for Supabase / PostgreSQL
-- Run after schema.sql.

BEGIN;

SET search_path TO comlab, public;

-- Directory seeds (departments + integration catalog)

INSERT INTO comlab.departments (department_code, department_name, description)
VALUES
  ('REGISTRAR', 'Registrar', 'Student enrollment and academic records hub.'),
  ('CASHIER', 'Cashier', 'Payments and financial clearing.'),
  ('CLINIC', 'Clinic', 'Medical and health services office.'),
  ('GUIDANCE', 'Guidance Office', 'Counseling and student support.'),
  ('PREFECT', 'Prefect Office', 'Discipline and student conduct.'),
  ('COMLAB', 'Computer Laboratory', 'Computer laboratory operations.'),
  ('CRAD', 'CRAD Management', 'Student activities, laboratory programs, and co-curricular coordination.'),
  ('HR', 'HR Department', 'Human resources and staffing.'),
  ('PMED', 'PMED Department', 'Monitoring, evaluation, and development.'),
  ('ADMIN', 'School Administration', 'School-wide administration and approvals.')
ON CONFLICT (department_code) DO UPDATE
SET
  department_name = EXCLUDED.department_name,
  description = EXCLUDED.description,
  updated_at = NOW();

INSERT INTO comlab.integration_record_types (record_type_code, record_type_name, data_domain, description)
VALUES
  ('student_enrollment_data', 'Student Enrollment Data', 'student', 'Enrollment details sent from Registrar to Cashier.'),
  ('payment_confirmation', 'Payment Confirmation', 'finance', 'Cashier confirmation sent to Registrar.'),
  ('medical_clearance', 'Medical Clearance', 'health', 'Clinic clearance sent to Registrar.'),
  ('counseling_reports', 'Counseling Reports', 'health', 'Counseling reports received by Registrar.'),
  ('discipline_records', 'Discipline Records', 'discipline', 'Discipline information received by Registrar.'),
  ('activity_participation_records', 'Activity Participation Records', 'student', 'Activity participation records from CRAD.'),
  ('student_personal_information', 'Student Personal Information', 'student', 'Shared student profile information.'),
  ('student_list', 'Student List', 'student', 'Student listing shared with recipient offices.'),
  ('enrollment_statistics', 'Enrollment Statistics', 'student', 'Enrollment counts and trends.'),
  ('payroll_data', 'Payroll Data', 'staff', 'Payroll-related data for Cashier.'),
  ('financial_reports', 'Financial Reports', 'finance', 'Financial reports sent to PMED.'),
  ('health_incident_reports', 'Health Incident Reports', 'health', 'Incident reports from Prefect to Clinic.'),
  ('health_reports', 'Health Reports', 'health', 'Health reports from Clinic to Guidance.'),
  ('medical_service_reports', 'Medical Service Reports', 'health', 'Clinic reporting to PMED.'),
  ('student_academic_records', 'Student Academic Records', 'student', 'Academic records used by Guidance.'),
  ('health_concerns', 'Health Concerns', 'health', 'Concerns forwarded from Guidance to Clinic.'),
  ('discipline_reports', 'Discipline Reports', 'discipline', 'Reports shared between Guidance and Prefect.'),
  ('incident_reports', 'Incident Reports', 'discipline', 'Incident reports shared by Prefect.'),
  ('discipline_statistics', 'Discipline Statistics', 'discipline', 'Discipline statistics sent to PMED.'),
  ('staff_list', 'Staff List', 'staff', 'Staff list received by the Computer Laboratory.'),
  ('user_accounts', 'User Accounts', 'staff', 'User account data for laboratory access.'),
  ('pmed_faculty_attendance', 'PMED Faculty Attendance', 'staff', 'Faculty attendance information from PMED.'),
  ('faculty_schedule_assignments', 'Faculty Schedule Assignments', 'staff', 'HR-managed faculty schedule assignments sent to COMLAB.'),
  ('student_account_information', 'Student Account Information', 'student', 'Registrar-managed student account identities and access details for COMLAB.'),
  ('class_schedule_feed', 'Class Schedule Feed', 'student', 'Registrar schedule feed used by COMLAB for laboratory planning.'),
  ('subject_lab_assignments', 'Subject and Lab Assignments', 'student', 'Registrar subject and lab assignment plan routed to COMLAB.'),
  ('laboratory_usage_reports', 'Laboratory Usage Reports', 'lab', 'Usage reports sent to PMED.'),
  ('laboratory_attendance_records', 'Laboratory Attendance Records', 'lab', 'Laboratory attendance records sent from COMLAB to Registrar.'),
  ('equipment_log_reports', 'Equipment Log Reports', 'lab', 'Equipment maintenance and readiness logs sent from COMLAB to PMED.'),
  ('laboratory_activity_reports', 'Laboratory Activity Reports', 'program', 'Laboratory activity summaries sent from COMLAB to CRAD Management.'),
  ('lab_fee_assessment', 'Lab Fee Assessment', 'finance', 'Computer Laboratory billing assessment sent to Cashier.'),
  ('facility_access_report', 'Facility Access Report', 'staff', 'Facility access and utilization report sent to HR.'),
  ('student_recommendations', 'Student Recommendations', 'student', 'Recommendations coming from Guidance.'),
  ('student_activity_records', 'Student Activity Records', 'student', 'Activity records sent back to Registrar.'),
  ('program_reports', 'Program Reports', 'program', 'Program-level reports from CRAD.'),
  ('program_activity_reports', 'Program Activity Reports', 'program', 'Program activity reports sent to PMED.'),
  ('staff_evaluation_feedback', 'Staff Evaluation Feedback', 'staff', 'Feedback received by HR from PMED.'),
  ('faculty_list', 'Faculty List', 'staff', 'Faculty list shared with Registrar.'),
  ('employee_performance_records', 'Employee Performance Records', 'staff', 'Performance records reported by HR.'),
  ('evaluation_reports', 'Evaluation Reports', 'program', 'School administration evaluation reports from PMED.')
ON CONFLICT (record_type_code) DO UPDATE
SET
  record_type_name = EXCLUDED.record_type_name,
  data_domain = EXCLUDED.data_domain,
  description = EXCLUDED.description,
  updated_at = NOW();

INSERT INTO comlab.integration_routes (flow_order, sender_department_id, receiver_department_id, record_type_id, notes)
SELECT v.flow_order, s.department_id, r.department_id, t.record_type_id, v.notes
FROM (
  VALUES
    (1,  'REGISTRAR', 'CASHIER', 'student_enrollment_data',       'Registrar sends enrollment data to Cashier.'),
    (2,  'CASHIER',   'REGISTRAR', 'payment_confirmation',        'Cashier sends payment confirmation to Registrar.'),
    (3,  'REGISTRAR', 'CLINIC',    'student_personal_information','Registrar shares student personal information with Clinic.'),
    (4,  'REGISTRAR', 'GUIDANCE',  'student_personal_information','Registrar shares student personal information with Guidance.'),
    (5,  'REGISTRAR', 'PREFECT',   'student_personal_information','Registrar shares student personal information with Prefect.'),
    (6,  'REGISTRAR', 'COMLAB',    'student_list',                'Registrar shares the student list with the Computer Laboratory.'),
    (7,  'REGISTRAR', 'CRAD',      'student_list',                'Registrar shares the student list with CRAD.'),
    (8,  'REGISTRAR', 'PMED',      'enrollment_statistics',       'Registrar sends enrollment statistics to PMED.'),
    (9,  'CLINIC',    'REGISTRAR', 'medical_clearance',           'Clinic sends medical clearance to Registrar.'),
    (10, 'GUIDANCE',  'REGISTRAR', 'counseling_reports',          'Guidance sends counseling reports to Registrar.'),
    (11, 'PREFECT',   'REGISTRAR', 'discipline_records',          'Prefect sends discipline records to Registrar.'),
    (12, 'PREFECT',   'CLINIC',    'health_incident_reports',     'Prefect sends health incident reports to Clinic.'),
    (13, 'CLINIC',    'GUIDANCE',  'health_reports',              'Clinic sends health reports to Guidance.'),
    (14, 'CLINIC',    'PMED',      'medical_service_reports',     'Clinic sends medical service reports to PMED.'),
    (15, 'GUIDANCE',  'CLINIC',    'health_concerns',             'Guidance sends health concerns to Clinic.'),
    (16, 'GUIDANCE',  'PREFECT',   'discipline_reports',          'Guidance sends discipline reports to Prefect.'),
    (17, 'PREFECT',   'GUIDANCE',  'discipline_reports',          'Prefect sends discipline reports to Guidance.'),
    (18, 'PREFECT',   'CLINIC',    'incident_reports',            'Prefect sends incident reports to Clinic.'),
    (19, 'PREFECT',   'PMED',      'discipline_statistics',       'Prefect sends discipline statistics to PMED.'),
    (20, 'HR',        'CASHIER',   'payroll_data',                'HR sends payroll data to Cashier.'),
    (21, 'HR',        'REGISTRAR', 'faculty_list',                'HR sends the faculty list to Registrar.'),
    (22, 'HR',        'PMED',      'employee_performance_records','HR sends employee performance records to PMED.'),
    (23, 'PMED',      'COMLAB',    'pmed_faculty_attendance',     'PMED sends faculty attendance data to the Computer Laboratory.'),
    (24, 'HR',        'COMLAB',    'faculty_schedule_assignments','HR sends faculty schedule assignments to the Computer Laboratory.'),
    (25, 'COMLAB',    'PMED',      'laboratory_usage_reports',    'Computer Laboratory sends laboratory usage reports to PMED.'),
    (26, 'COMLAB',    'CASHIER',   'lab_fee_assessment',          'Computer Laboratory sends lab fee assessments to Cashier.'),
    (27, 'COMLAB',    'HR',        'facility_access_report',      'Computer Laboratory sends facility access reports to HR.'),
    (28, 'CRAD',      'REGISTRAR', 'activity_participation_records','CRAD sends activity participation records to Registrar.'),
    (29, 'CRAD',      'REGISTRAR', 'student_activity_records',    'CRAD sends student activity records to Registrar.'),
    (30, 'CRAD',      'PMED',      'program_reports',             'CRAD sends program reports to PMED.'),
    (31, 'CRAD',      'PMED',      'program_activity_reports',    'CRAD sends program activity reports to PMED.'),
    (32, 'GUIDANCE',  'CRAD',      'student_recommendations',     'Guidance sends student recommendations to CRAD.'),
    (33, 'CASHIER',   'PMED',      'financial_reports',           'Cashier sends financial reports to PMED.'),
    (34, 'PMED',      'ADMIN',     'evaluation_reports',          'PMED sends evaluation reports to School Administration.'),
    (35, 'PMED',      'HR',        'staff_evaluation_feedback',   'PMED sends staff evaluation feedback to HR.'),
    (36, 'HR',        'COMLAB',    'staff_list',                  'HR sends the staff list to the Computer Laboratory.'),
    (37, 'HR',        'COMLAB',    'user_accounts',               'HR sends user account data to the Computer Laboratory.'),
    (38, 'REGISTRAR', 'COMLAB',    'student_account_information', 'Registrar sends student account information to the Computer Laboratory.'),
    (39, 'REGISTRAR', 'COMLAB',    'class_schedule_feed',         'Registrar sends class schedules to the Computer Laboratory.'),
    (40, 'REGISTRAR', 'COMLAB',    'subject_lab_assignments',     'Registrar sends subject and lab assignments to the Computer Laboratory.'),
    (41, 'COMLAB',    'REGISTRAR', 'laboratory_attendance_records','Computer Laboratory sends laboratory attendance records to Registrar.'),
    (42, 'COMLAB',    'PMED',      'equipment_log_reports',       'Computer Laboratory sends equipment maintenance logs to PMED.'),
    (43, 'COMLAB',    'CRAD',      'laboratory_activity_reports', 'Computer Laboratory sends laboratory activity reports to CRAD Management.')
) AS v(flow_order, sender_code, receiver_code, record_type_code, notes)
JOIN comlab.departments s ON s.department_code = v.sender_code
JOIN comlab.departments r ON r.department_code = v.receiver_code
JOIN comlab.integration_record_types t ON t.record_type_code = v.record_type_code
ON CONFLICT (sender_department_id, receiver_department_id, record_type_id) DO UPDATE
SET
  flow_order = EXCLUDED.flow_order,
  notes = EXCLUDED.notes,
  is_active = 1,
  updated_at = NOW();

-- Users (default login accounts)

INSERT INTO comlab.users (username, email, password_hash, first_name, last_name, role, department, is_active)
VALUES
  ('admin',   'admin@comlab.edu',   '$2y$10$Yg.VyJDbWcUTyApOfZA5JOTHS.FCPyy/S33iPbspMGU6mQjVs3Xky',   'System', 'Administrator', 'Administrator', 'IT Department', 1),
  ('msantos', 'msantos@comlab.edu', '$2y$10$e0SE3L/L2xL9wJCSrnyXpO2/jrWJciWW8jdjoWtVi0woJ5S0uR58e', 'Maria',  'Santos',        'Faculty',        'College of Computer Studies', 1),
  ('jreyes',  'jreyes@comlab.edu',  '$2y$10$e0SE3L/L2xL9wJCSrnyXpO2/jrWJciWW8jdjoWtVi0woJ5S0uR58e', 'Jose',   'Reyes',         'Faculty',        'College of Computer Studies', 1),
  ('acruz',   'acruz@comlab.edu',   '$2y$10$e0SE3L/L2xL9wJCSrnyXpO2/jrWJciWW8jdjoWtVi0woJ5S0uR58e', 'Ana',    'Cruz',          'Faculty',        'College of Information Technology', 1)
ON CONFLICT (username) DO UPDATE
SET
  email = EXCLUDED.email,
  password_hash = EXCLUDED.password_hash,
  first_name = EXCLUDED.first_name,
  last_name = EXCLUDED.last_name,
  role = EXCLUDED.role,
  department = EXCLUDED.department,
  is_active = EXCLUDED.is_active,
  updated_at = NOW();

-- Locations

INSERT INTO comlab.locations (lab_name, lab_code, building, floor, room_number, capacity, operating_hours_start, operating_hours_end, is_active)
VALUES
  ('Computer Lab A','LAB-A','Main Building','2nd Floor','201',30,'07:30:00','19:00:00',1),
  ('Computer Lab B','LAB-B','Main Building','2nd Floor','202',25,'07:30:00','19:00:00',1),
  ('Computer Lab C','LAB-C','Annex Building','1st Floor','101',20,'08:00:00','17:00:00',1)
ON CONFLICT (lab_code) DO UPDATE
SET
  lab_name = EXCLUDED.lab_name,
  building = EXCLUDED.building,
  floor = EXCLUDED.floor,
  room_number = EXCLUDED.room_number,
  capacity = EXCLUDED.capacity,
  operating_hours_start = EXCLUDED.operating_hours_start,
  operating_hours_end = EXCLUDED.operating_hours_end,
  is_active = EXCLUDED.is_active,
  updated_at = NOW();

-- Devices

WITH device_rows AS (
  SELECT * FROM (
    VALUES
      ('PC-A-001','Desktop','Dell','OptiPlex 7090','SN-A001','Available','LAB-A'),
      ('PC-A-002','Desktop','Dell','OptiPlex 7090','SN-A002','Available','LAB-A'),
      ('PC-A-003','Desktop','Dell','OptiPlex 7090','SN-A003','Under Repair','LAB-A'),
      ('PC-A-004','Desktop','Dell','OptiPlex 7090','SN-A004','Available','LAB-A'),
      ('PC-A-005','Desktop','Dell','OptiPlex 7090','SN-A005','Damaged','LAB-A'),
      ('PC-A-006','Desktop','Dell','OptiPlex 7090','SN-A006','Available','LAB-A'),
      ('PC-B-001','Desktop','HP','EliteDesk 805 G8','SN-B001','Available','LAB-B'),
      ('PC-B-002','Desktop','HP','EliteDesk 805 G8','SN-B002','Available','LAB-B'),
      ('PC-B-003','Desktop','HP','EliteDesk 805 G8','SN-B003','In Use','LAB-B'),
      ('PC-B-004','Desktop','HP','EliteDesk 805 G8','SN-B004','Available','LAB-B'),
      ('PC-C-001','Desktop','Lenovo','ThinkCentre M80s','SN-C001','Available','LAB-C'),
      ('PC-C-002','Desktop','Lenovo','ThinkCentre M80s','SN-C002','Available','LAB-C'),
      ('PRINT-A-001','Printer','Canon','iR-ADV 525i','SN-P001','Available','LAB-A'),
      ('MON-A-001','Monitor','Dell','P2422H 24"','SN-M001','Available','LAB-A'),
      ('KBD-A-001','Keyboard','Logitech','MK270 Wireless','SN-K001','Available','LAB-A')
  ) AS v(device_code, device_type, brand, model, serial_number, status, lab_code)
)
INSERT INTO comlab.devices (device_code, device_type, brand, model, serial_number, status, location_id, purchase_date)
SELECT
  r.device_code,
  r.device_type,
  r.brand,
  r.model,
  r.serial_number,
  r.status,
  l.location_id,
  DATE '2023-06-01'
FROM device_rows r
JOIN comlab.locations l ON l.lab_code = r.lab_code
ON CONFLICT (device_code) DO UPDATE
SET
  device_type = EXCLUDED.device_type,
  brand = EXCLUDED.brand,
  model = EXCLUDED.model,
  serial_number = EXCLUDED.serial_number,
  status = EXCLUDED.status,
  location_id = EXCLUDED.location_id,
  purchase_date = EXCLUDED.purchase_date,
  updated_at = NOW();

-- Faculty schedules (current-year Jan-Jun semester)

WITH semester AS (
  SELECT
    make_date(extract(year from current_date)::int, 1, 1) AS sem_start,
    make_date(extract(year from current_date)::int, 6, 30) AS sem_end
),
ids AS (
  SELECT
    (SELECT user_id FROM comlab.users WHERE username = 'admin') AS admin_id,
    (SELECT user_id FROM comlab.users WHERE username = 'msantos') AS santos_id,
    (SELECT user_id FROM comlab.users WHERE username = 'jreyes') AS reyes_id,
    (SELECT user_id FROM comlab.users WHERE username = 'acruz') AS cruz_id,
    (SELECT location_id FROM comlab.locations WHERE lab_code = 'LAB-A') AS lab_a,
    (SELECT location_id FROM comlab.locations WHERE lab_code = 'LAB-B') AS lab_b,
    (SELECT location_id FROM comlab.locations WHERE lab_code = 'LAB-C') AS lab_c
)
INSERT INTO comlab.faculty_schedules (
  faculty_id, assigned_by, location_id, class_name, day_of_week,
  start_time, end_time, duration_hours, semester_start, semester_end,
  department, notes, source_system, source_reference, synced_from_hr, is_active
)
SELECT
  v.faculty_id,
  v.assigned_by,
  v.location_id,
  v.class_name,
  v.day_of_week,
  v.start_time::time,
  v.end_time::time,
  v.duration_hours,
  v.semester_start::date,
  v.semester_end::date,
  v.department,
  v.notes,
  'HR',
  concat('seed-hr-sync-', v.faculty_id, '-', replace(lower(v.class_name), ' ', '-')),
  1,
  v.is_active
FROM (
  VALUES
    ((SELECT santos_id FROM ids), (SELECT admin_id FROM ids), (SELECT lab_a FROM ids),
      'CIS101 - Introduction to Computing', 'Monday,Wednesday', '08:00:00', '10:00:00', 2.0,
      (SELECT sem_start FROM semester), (SELECT sem_end FROM semester), 'College of Computer Studies', 'Core subject - 1st year', 1),

    ((SELECT santos_id FROM ids), (SELECT admin_id FROM ids), (SELECT lab_b FROM ids),
      'CS201 - Object-Oriented Programming', 'Tuesday,Thursday', '10:00:00', '12:00:00', 2.0,
      (SELECT sem_start FROM semester), (SELECT sem_end FROM semester), 'College of Computer Studies', '', 1),

    ((SELECT reyes_id FROM ids), (SELECT admin_id FROM ids), (SELECT lab_b FROM ids),
      'IT301 - Database Management Systems', 'Monday,Wednesday,Friday', '13:00:00', '15:00:00', 2.0,
      (SELECT sem_start FROM semester), (SELECT sem_end FROM semester), 'College of Computer Studies', 'SQL practicals included', 1),

    ((SELECT cruz_id FROM ids), (SELECT admin_id FROM ids), (SELECT lab_c FROM ids),
      'IT101 - Computer Fundamentals', 'Tuesday,Thursday', '08:00:00', '10:00:00', 2.0,
      (SELECT sem_start FROM semester), (SELECT sem_end FROM semester), 'College of Information Technology', '', 1)
) AS v(
  faculty_id, assigned_by, location_id, class_name, day_of_week,
  start_time, end_time, duration_hours, semester_start, semester_end,
  department, notes, is_active
)
WHERE v.faculty_id IS NOT NULL AND v.assigned_by IS NOT NULL AND v.location_id IS NOT NULL
ON CONFLICT (faculty_id, class_name, semester_start) DO NOTHING;

-- Operational data stored by COMLAB

WITH usage_rows AS (
  SELECT * FROM (
    VALUES
      ('LAB-A', 'msantos', 'CIS101 - Introduction to Computing', (current_date - interval '5 days')::date, '08:05:00', '09:55:00', 28, 'seed-usage-lab-a-001', 'Intro computing hands-on laboratory session.'),
      ('LAB-B', 'msantos', 'CS201 - Object-Oriented Programming', (current_date - interval '4 days')::date, '10:02:00', '11:48:00', 24, 'seed-usage-lab-b-001', 'Object-oriented programming coding drills.'),
      ('LAB-B', 'jreyes',  'IT301 - Database Management Systems', (current_date - interval '3 days')::date, '13:06:00', '14:52:00', 22, 'seed-usage-lab-b-002', 'Database practicals and SQL lab assessment.'),
      ('LAB-C', 'acruz',   'IT101 - Computer Fundamentals',      (current_date - interval '2 days')::date, '08:03:00', '09:45:00', 18, 'seed-usage-lab-c-001', 'Computer fundamentals orientation and lab walk-through.')
  ) AS v(lab_code, faculty_username, class_name, usage_date, start_time, end_time, participant_count, source_reference, notes)
)
INSERT INTO comlab.lab_usage_logs (
  location_id, faculty_id, schedule_id, recorded_by, usage_date, session_start, session_end,
  participant_count, subject_code, source_system, source_reference, notes
)
SELECT
  l.location_id,
  u.user_id,
  fs.schedule_id,
  admin_u.user_id,
  r.usage_date,
  (r.usage_date::timestamp + r.start_time::time)::timestamptz,
  (r.usage_date::timestamp + r.end_time::time)::timestamptz,
  r.participant_count,
  split_part(r.class_name, ' - ', 1),
  'COMLAB',
  r.source_reference,
  r.notes
FROM usage_rows r
JOIN comlab.locations l ON l.lab_code = r.lab_code
JOIN comlab.users u ON u.username = r.faculty_username
JOIN comlab.users admin_u ON admin_u.username = 'admin'
LEFT JOIN comlab.faculty_schedules fs
  ON fs.faculty_id = u.user_id
 AND fs.location_id = l.location_id
 AND fs.class_name = r.class_name
 AND fs.is_active = 1
WHERE NOT EXISTS (
  SELECT 1
  FROM comlab.lab_usage_logs ul
  WHERE ul.source_reference = r.source_reference
);

WITH maintenance_rows AS (
  SELECT * FROM (
    VALUES
      ('PC-A-003', 'admin', 'Repair', 'Random shutdown during classroom sessions.', 'Re-seated memory modules and scheduled PSU observation.', 'Under Repair', 'Under Repair', 1250.00, (current_date - interval '10 days')::date, (current_date - interval '10 days')::date),
      ('PC-A-005', 'admin', 'Inspection', 'Chassis dent and unstable keyboard port.', 'Logged physical damage and isolated the workstation from classroom use.', 'Damaged', 'Damaged', 0.00, (current_date - interval '7 days')::date, (current_date - interval '7 days')::date),
      ('PRINT-A-001', 'admin', 'Preventive Maintenance', 'Scheduled cleaning before heavy reporting cycle.', 'Cleaned rollers and recalibrated tray alignment.', 'Available', 'Available', 350.00, (current_date - interval '4 days')::date, (current_date - interval '4 days')::date)
  ) AS v(device_code, performed_by_username, maintenance_type, issue_description, action_taken, status_before, status_after, cost, start_date, end_date)
)
INSERT INTO comlab.device_maintenance_logs (
  device_id, performed_by, maintenance_type, issue_description, action_taken, status_before,
  status_after, cost, start_datetime, end_datetime
)
SELECT
  d.device_id,
  u.user_id,
  r.maintenance_type,
  r.issue_description,
  r.action_taken,
  r.status_before,
  r.status_after,
  r.cost,
  (r.start_date::timestamp + time '09:00')::timestamptz,
  (r.end_date::timestamp + time '11:00')::timestamptz
FROM maintenance_rows r
JOIN comlab.devices d ON d.device_code = r.device_code
JOIN comlab.users u ON u.username = r.performed_by_username
WHERE NOT EXISTS (
  SELECT 1
  FROM comlab.device_maintenance_logs ml
  WHERE ml.device_id = d.device_id
    AND ml.maintenance_type = r.maintenance_type
    AND ml.start_datetime = (r.start_date::timestamp + time '09:00')::timestamptz
);

-- Sample HR schedule integration document

INSERT INTO comlab.integration_documents (
  route_id, record_type_id, sender_department_id, receiver_department_id,
  subject_type, subject_ref, title, source_system, source_reference,
  status, payload, sent_at, received_at, acknowledged_at, created_by_user_id
)
SELECT
  rt.route_id,
  rt.record_type_id,
  rt.sender_department_id,
  rt.receiver_department_id,
  'faculty',
  'HR-SCHEDULE-SEED-001',
  'HR Faculty Schedule Assignments',
  'HR',
  'hr-schedule-seed-001',
  'acknowledged',
  jsonb_build_object(
    'source', 'HR',
    'generated_at', now(),
    'schedules', jsonb_build_array(
      jsonb_build_object(
        'faculty_username', 'msantos',
        'class_name', 'CIS101 - Introduction to Computing',
        'day_of_week', 'Monday,Wednesday',
        'start_time', '08:00:00',
        'end_time', '10:00:00',
        'semester_start', (SELECT make_date(extract(year from current_date)::int, 1, 1)),
        'semester_end', (SELECT make_date(extract(year from current_date)::int, 6, 30)),
        'department', 'College of Computer Studies',
        'lab_code', 'LAB-A',
        'notes', 'HR schedule feed'
      ),
      jsonb_build_object(
        'faculty_username', 'msantos',
        'class_name', 'CS201 - Object-Oriented Programming',
        'day_of_week', 'Tuesday,Thursday',
        'start_time', '10:00:00',
        'end_time', '12:00:00',
        'semester_start', (SELECT make_date(extract(year from current_date)::int, 1, 1)),
        'semester_end', (SELECT make_date(extract(year from current_date)::int, 6, 30)),
        'department', 'College of Computer Studies',
        'lab_code', 'LAB-B',
        'notes', 'HR schedule feed'
      ),
      jsonb_build_object(
        'faculty_username', 'jreyes',
        'class_name', 'IT301 - Database Management Systems',
        'day_of_week', 'Monday,Wednesday,Friday',
        'start_time', '13:00:00',
        'end_time', '15:00:00',
        'semester_start', (SELECT make_date(extract(year from current_date)::int, 1, 1)),
        'semester_end', (SELECT make_date(extract(year from current_date)::int, 6, 30)),
        'department', 'College of Computer Studies',
        'lab_code', 'LAB-B',
        'notes', 'HR schedule feed'
      ),
      jsonb_build_object(
        'faculty_username', 'acruz',
        'class_name', 'IT101 - Computer Fundamentals',
        'day_of_week', 'Tuesday,Thursday',
        'start_time', '08:00:00',
        'end_time', '10:00:00',
        'semester_start', (SELECT make_date(extract(year from current_date)::int, 1, 1)),
        'semester_end', (SELECT make_date(extract(year from current_date)::int, 6, 30)),
        'department', 'College of Information Technology',
        'lab_code', 'LAB-C',
        'notes', 'HR schedule feed'
      )
    )
  ),
  now(),
  now(),
  now(),
  (SELECT user_id FROM comlab.users WHERE username = 'admin')
FROM comlab.integration_routes rt
JOIN comlab.departments sd ON sd.department_id = rt.sender_department_id
JOIN comlab.departments rd ON rd.department_id = rt.receiver_department_id
JOIN comlab.integration_record_types irt ON irt.record_type_id = rt.record_type_id
WHERE sd.department_code = 'HR'
  AND rd.department_code = 'COMLAB'
  AND irt.record_type_code = 'faculty_schedule_assignments'
  AND NOT EXISTS (
    SELECT 1 FROM comlab.integration_documents d WHERE d.source_reference = 'hr-schedule-seed-001'
  );

-- Sample COMLAB department integration documents for Registrar, PMED, and CRAD Management

INSERT INTO comlab.integration_documents (
  route_id, record_type_id, sender_department_id, receiver_department_id,
  subject_type, subject_ref, title, source_system, source_reference,
  status, payload, sent_at, received_at, created_by_user_id
)
SELECT
  rt.route_id,
  rt.record_type_id,
  rt.sender_department_id,
  rt.receiver_department_id,
  'student',
  'STU-2026-001',
  'Registrar Student Account Information Feed',
  'Registrar',
  'registrar-student-account-seed-001',
  'received',
  jsonb_build_object(
    'source', 'Registrar',
    'generated_at', now(),
    'students', jsonb_build_array(
      jsonb_build_object(
        'student_number', '2026-0001',
        'full_name', 'Maria Santos',
        'username', 'msantos',
        'email', 'msantos@comlab.edu',
        'program', 'BSCS',
        'year_level', 2,
        'account_status', 'active',
        'lab_access', true
      ),
      jsonb_build_object(
        'student_number', '2026-0002',
        'full_name', 'Jose Reyes',
        'username', 'jreyes',
        'email', 'jreyes@comlab.edu',
        'program', 'BSIT',
        'year_level', 3,
        'account_status', 'active',
        'lab_access', true
      )
    )
  ),
  now() - interval '3 days',
  now() - interval '3 days',
  (SELECT user_id FROM comlab.users WHERE username = 'admin')
FROM comlab.integration_routes rt
JOIN comlab.departments sd ON sd.department_id = rt.sender_department_id
JOIN comlab.departments rd ON rd.department_id = rt.receiver_department_id
JOIN comlab.integration_record_types irt ON irt.record_type_id = rt.record_type_id
WHERE sd.department_code = 'REGISTRAR'
  AND rd.department_code = 'COMLAB'
  AND irt.record_type_code = 'student_account_information'
  AND NOT EXISTS (
    SELECT 1 FROM comlab.integration_documents d WHERE d.source_reference = 'registrar-student-account-seed-001'
  );

INSERT INTO comlab.integration_documents (
  route_id, record_type_id, sender_department_id, receiver_department_id,
  subject_type, subject_ref, title, source_system, source_reference,
  status, payload, sent_at, received_at, created_by_user_id
)
SELECT
  rt.route_id,
  rt.record_type_id,
  rt.sender_department_id,
  rt.receiver_department_id,
  'general',
  'SCH-2026-SEM1',
  'Registrar Class Schedule Feed',
  'Registrar',
  'registrar-schedule-feed-seed-001',
  'received',
  jsonb_build_object(
    'source', 'Registrar',
    'semester', '2026 First Semester',
    'generated_at', now(),
    'schedules', jsonb_build_array(
      jsonb_build_object('section', 'BSCS-2A', 'subject_code', 'CIS101', 'day_of_week', 'Monday,Wednesday', 'start_time', '08:00:00', 'end_time', '10:00:00'),
      jsonb_build_object('section', 'BSCS-2B', 'subject_code', 'CS201', 'day_of_week', 'Tuesday,Thursday', 'start_time', '10:00:00', 'end_time', '12:00:00'),
      jsonb_build_object('section', 'BSIT-3A', 'subject_code', 'IT301', 'day_of_week', 'Monday,Wednesday,Friday', 'start_time', '13:00:00', 'end_time', '15:00:00')
    )
  ),
  now() - interval '2 days',
  now() - interval '2 days',
  (SELECT user_id FROM comlab.users WHERE username = 'admin')
FROM comlab.integration_routes rt
JOIN comlab.departments sd ON sd.department_id = rt.sender_department_id
JOIN comlab.departments rd ON rd.department_id = rt.receiver_department_id
JOIN comlab.integration_record_types irt ON irt.record_type_id = rt.record_type_id
WHERE sd.department_code = 'REGISTRAR'
  AND rd.department_code = 'COMLAB'
  AND irt.record_type_code = 'class_schedule_feed'
  AND NOT EXISTS (
    SELECT 1 FROM comlab.integration_documents d WHERE d.source_reference = 'registrar-schedule-feed-seed-001'
  );

INSERT INTO comlab.integration_documents (
  route_id, record_type_id, sender_department_id, receiver_department_id,
  subject_type, subject_ref, title, source_system, source_reference,
  status, payload, sent_at, received_at, acknowledged_at, created_by_user_id
)
SELECT
  rt.route_id,
  rt.record_type_id,
  rt.sender_department_id,
  rt.receiver_department_id,
  'general',
  'LABMAP-2026-001',
  'Registrar Subject and Lab Assignments',
  'Registrar',
  'registrar-lab-assignment-seed-001',
  'acknowledged',
  jsonb_build_object(
    'source', 'Registrar',
    'generated_at', now(),
    'assignments', jsonb_build_array(
      jsonb_build_object('subject_code', 'CIS101', 'section', 'BSCS-2A', 'lab_code', 'LAB-A', 'lab_name', 'Computer Lab A'),
      jsonb_build_object('subject_code', 'CS201', 'section', 'BSCS-2B', 'lab_code', 'LAB-B', 'lab_name', 'Computer Lab B'),
      jsonb_build_object('subject_code', 'IT301', 'section', 'BSIT-3A', 'lab_code', 'LAB-B', 'lab_name', 'Computer Lab B'),
      jsonb_build_object('subject_code', 'IT101', 'section', 'BSIT-1A', 'lab_code', 'LAB-C', 'lab_name', 'Computer Lab C')
    )
  ),
  now() - interval '2 days',
  now() - interval '2 days',
  now() - interval '2 days',
  (SELECT user_id FROM comlab.users WHERE username = 'admin')
FROM comlab.integration_routes rt
JOIN comlab.departments sd ON sd.department_id = rt.sender_department_id
JOIN comlab.departments rd ON rd.department_id = rt.receiver_department_id
JOIN comlab.integration_record_types irt ON irt.record_type_id = rt.record_type_id
WHERE sd.department_code = 'REGISTRAR'
  AND rd.department_code = 'COMLAB'
  AND irt.record_type_code = 'subject_lab_assignments'
  AND NOT EXISTS (
    SELECT 1 FROM comlab.integration_documents d WHERE d.source_reference = 'registrar-lab-assignment-seed-001'
  );

INSERT INTO comlab.integration_documents (
  route_id, record_type_id, sender_department_id, receiver_department_id,
  subject_type, subject_ref, title, source_system, source_reference,
  status, payload, sent_at, received_at, created_by_user_id
)
SELECT
  rt.route_id,
  rt.record_type_id,
  rt.sender_department_id,
  rt.receiver_department_id,
  'general',
  'ATT-2026-001',
  'COMLAB Laboratory Attendance Records',
  'COMLAB',
  'comlab-attendance-seed-001',
  'received',
  jsonb_build_object(
    'source', 'COMLAB',
    'generated_at', now(),
    'coverage_period', jsonb_build_object(
      'from', current_date - 7,
      'to', current_date - 1
    ),
    'summary', jsonb_build_object(
      'present', (SELECT COUNT(*) FROM comlab.schedule_attendance WHERE attendance_date >= current_date - 7 AND status = 'Present'),
      'absent', (SELECT COUNT(*) FROM comlab.schedule_attendance WHERE attendance_date >= current_date - 7 AND status = 'Absent'),
      'excused', (SELECT COUNT(*) FROM comlab.schedule_attendance WHERE attendance_date >= current_date - 7 AND status = 'Excused')
    )
  ),
  now() - interval '1 day',
  now() - interval '1 day',
  (SELECT user_id FROM comlab.users WHERE username = 'admin')
FROM comlab.integration_routes rt
JOIN comlab.departments sd ON sd.department_id = rt.sender_department_id
JOIN comlab.departments rd ON rd.department_id = rt.receiver_department_id
JOIN comlab.integration_record_types irt ON irt.record_type_id = rt.record_type_id
WHERE sd.department_code = 'COMLAB'
  AND rd.department_code = 'REGISTRAR'
  AND irt.record_type_code = 'laboratory_attendance_records'
  AND NOT EXISTS (
    SELECT 1 FROM comlab.integration_documents d WHERE d.source_reference = 'comlab-attendance-seed-001'
  );

INSERT INTO comlab.integration_documents (
  route_id, record_type_id, sender_department_id, receiver_department_id,
  subject_type, subject_ref, title, source_system, source_reference,
  status, payload, sent_at, received_at, created_by_user_id
)
SELECT
  rt.route_id,
  rt.record_type_id,
  rt.sender_department_id,
  rt.receiver_department_id,
  'system',
  'RPT-2026-001',
  'COMLAB Laboratory Usage Reports',
  'COMLAB',
  'comlab-usage-seed-001',
  'received',
  jsonb_build_object(
    'source', 'COMLAB',
    'generated_at', now(),
    'summary', jsonb_build_object(
      'usage_sessions_7d', (SELECT COUNT(*) FROM comlab.lab_usage_logs WHERE usage_date >= current_date - 7),
      'usage_hours_7d', (SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (session_end - session_start))) / 3600.0, 0) FROM comlab.lab_usage_logs WHERE usage_date >= current_date - 7),
      'participants_7d', (SELECT COALESCE(SUM(participant_count), 0) FROM comlab.lab_usage_logs WHERE usage_date >= current_date - 7)
    )
  ),
  now() - interval '12 hours',
  now() - interval '12 hours',
  (SELECT user_id FROM comlab.users WHERE username = 'admin')
FROM comlab.integration_routes rt
JOIN comlab.departments sd ON sd.department_id = rt.sender_department_id
JOIN comlab.departments rd ON rd.department_id = rt.receiver_department_id
JOIN comlab.integration_record_types irt ON irt.record_type_id = rt.record_type_id
WHERE sd.department_code = 'COMLAB'
  AND rd.department_code = 'PMED'
  AND irt.record_type_code = 'laboratory_usage_reports'
  AND NOT EXISTS (
    SELECT 1 FROM comlab.integration_documents d WHERE d.source_reference = 'comlab-usage-seed-001'
  );

INSERT INTO comlab.integration_documents (
  route_id, record_type_id, sender_department_id, receiver_department_id,
  subject_type, subject_ref, title, source_system, source_reference,
  status, payload, sent_at, received_at, created_by_user_id
)
SELECT
  rt.route_id,
  rt.record_type_id,
  rt.sender_department_id,
  rt.receiver_department_id,
  'system',
  'EQP-2026-001',
  'COMLAB Equipment Log Reports',
  'COMLAB',
  'comlab-equipment-log-seed-001',
  'received',
  jsonb_build_object(
    'source', 'COMLAB',
    'generated_at', now(),
    'equipment_logs', (
      SELECT COALESCE(
        jsonb_agg(
          jsonb_build_object(
            'device_code', d.device_code,
            'maintenance_type', ml.maintenance_type,
            'status_after', ml.status_after,
            'start_datetime', ml.start_datetime
          )
          ORDER BY ml.start_datetime DESC
        ),
        '[]'::jsonb
      )
      FROM comlab.device_maintenance_logs ml
      JOIN comlab.devices d ON d.device_id = ml.device_id
      WHERE ml.start_datetime >= current_date - interval '30 days'
    )
  ),
  now() - interval '8 hours',
  now() - interval '8 hours',
  (SELECT user_id FROM comlab.users WHERE username = 'admin')
FROM comlab.integration_routes rt
JOIN comlab.departments sd ON sd.department_id = rt.sender_department_id
JOIN comlab.departments rd ON rd.department_id = rt.receiver_department_id
JOIN comlab.integration_record_types irt ON irt.record_type_id = rt.record_type_id
WHERE sd.department_code = 'COMLAB'
  AND rd.department_code = 'PMED'
  AND irt.record_type_code = 'equipment_log_reports'
  AND NOT EXISTS (
    SELECT 1 FROM comlab.integration_documents d WHERE d.source_reference = 'comlab-equipment-log-seed-001'
  );

INSERT INTO comlab.integration_documents (
  route_id, record_type_id, sender_department_id, receiver_department_id,
  subject_type, subject_ref, title, source_system, source_reference,
  status, payload, sent_at, received_at, created_by_user_id
)
SELECT
  rt.route_id,
  rt.record_type_id,
  rt.sender_department_id,
  rt.receiver_department_id,
  'general',
  'CRAD-2026-001',
  'COMLAB Laboratory Activity Reports',
  'COMLAB',
  'comlab-crad-activity-seed-001',
  'received',
  jsonb_build_object(
    'source', 'COMLAB',
    'generated_at', now(),
    'activities', jsonb_build_array(
      jsonb_build_object('activity_name', 'Programming Skills Clinic', 'lab_code', 'LAB-B', 'participant_count', 24),
      jsonb_build_object('activity_name', 'Digital Literacy Orientation', 'lab_code', 'LAB-C', 'participant_count', 18)
    )
  ),
  now() - interval '6 hours',
  now() - interval '6 hours',
  (SELECT user_id FROM comlab.users WHERE username = 'admin')
FROM comlab.integration_routes rt
JOIN comlab.departments sd ON sd.department_id = rt.sender_department_id
JOIN comlab.departments rd ON rd.department_id = rt.receiver_department_id
JOIN comlab.integration_record_types irt ON irt.record_type_id = rt.record_type_id
WHERE sd.department_code = 'COMLAB'
  AND rd.department_code = 'CRAD'
  AND irt.record_type_code = 'laboratory_activity_reports'
  AND NOT EXISTS (
    SELECT 1 FROM comlab.integration_documents d WHERE d.source_reference = 'comlab-crad-activity-seed-001'
  );

-- Attendance history (~3 weeks), deterministic distribution similar to setup.php

WITH sched AS (
  SELECT schedule_id, faculty_id, day_of_week, start_time, semester_start, semester_end
  FROM comlab.faculty_schedules
  WHERE is_active = 1
),
days AS (
  SELECT
    s.schedule_id,
    s.faculty_id,
    trim(d) AS day_name,
    s.start_time,
    s.semester_start,
    s.semester_end
  FROM sched s
  CROSS JOIN LATERAL unnest(regexp_split_to_array(s.day_of_week, ',')) AS d
),
dates AS (
  SELECT
    d.schedule_id,
    d.faculty_id,
    gs::date AS attendance_date,
    d.start_time
  FROM days d
  CROSS JOIN generate_series(current_date - interval '21 days', current_date - interval '1 day', interval '1 day') AS gs
  WHERE trim(to_char(gs, 'FMDay')) = d.day_name
    AND gs::date BETWEEN d.semester_start AND d.semester_end
),
scored AS (
  SELECT
    schedule_id,
    faculty_id,
    attendance_date,
    start_time,
    (abs(hashtext(schedule_id::text || ':' || attendance_date::text)) % 100) AS score,
    (abs(hashtext('m:' || schedule_id::text || ':' || attendance_date::text)) % 15) AS minutes_early
  FROM dates
)
INSERT INTO comlab.schedule_attendance (schedule_id, faculty_id, attendance_date, status, checked_in_at, marked_by_system)
SELECT
  schedule_id,
  faculty_id,
  attendance_date,
  CASE
    WHEN score < 78 THEN 'Present'
    WHEN score < 92 THEN 'Absent'
    ELSE 'Excused'
  END AS status,
  CASE
    WHEN score < 78 THEN ((attendance_date::timestamp + start_time) - (minutes_early * interval '1 minute'))::timestamptz
    ELSE NULL
  END AS checked_in_at,
  CASE
    WHEN score < 78 THEN 0
    WHEN score < 92 THEN 1
    ELSE 0
  END AS marked_by_system
FROM scored
ON CONFLICT (schedule_id, attendance_date) DO NOTHING;

UPDATE comlab.integration_documents
SET
  payload = jsonb_build_object(
    'source', 'COMLAB',
    'generated_at', now(),
    'coverage_period', jsonb_build_object(
      'from', current_date - 7,
      'to', current_date - 1
    ),
    'summary', jsonb_build_object(
      'present', (SELECT COUNT(*) FROM comlab.schedule_attendance WHERE attendance_date >= current_date - 7 AND status = 'Present'),
      'absent', (SELECT COUNT(*) FROM comlab.schedule_attendance WHERE attendance_date >= current_date - 7 AND status = 'Absent'),
      'excused', (SELECT COUNT(*) FROM comlab.schedule_attendance WHERE attendance_date >= current_date - 7 AND status = 'Excused')
    )
  ),
  updated_at = NOW()
WHERE source_reference = 'comlab-attendance-seed-001';

-- Sample requests

INSERT INTO comlab.requests (request_type, submitted_by, department, location_id, device_id, issue_description, date_needed, status)
SELECT * FROM (
  VALUES
    ('Maintenance',
      (SELECT user_id FROM comlab.users WHERE username = 'msantos'),
      'College of Computer Studies',
      (SELECT location_id FROM comlab.locations WHERE lab_code = 'LAB-A'),
      (SELECT device_id FROM comlab.devices WHERE device_code = 'PC-A-003'),
      'PC-A-003 randomly shuts down mid-session. Needs hardware inspection.',
      (current_date + interval '3 days')::date,
      'Pending'
    ),
    ('Unit',
      (SELECT user_id FROM comlab.users WHERE username = 'acruz'),
      'College of Information Technology',
      (SELECT location_id FROM comlab.locations WHERE lab_code = 'LAB-C'),
      NULL,
      'Requesting 5 additional keyboards for Lab C - current units worn out.',
      (current_date + interval '7 days')::date,
      'Pending'
    ),
    ('Maintenance',
      (SELECT user_id FROM comlab.users WHERE username = 'jreyes'),
      'College of Computer Studies',
      (SELECT location_id FROM comlab.locations WHERE lab_code = 'LAB-B'),
      NULL,
      'Lab B projector lamp flickering. Needs replacement.',
      (current_date + interval '5 days')::date,
      'Approved'
    )
) AS v(request_type, submitted_by, department, location_id, device_id, issue_description, date_needed, status)
WHERE v.submitted_by IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM comlab.requests r
    WHERE r.submitted_by = v.submitted_by
      AND r.issue_description = v.issue_description
  );

COMMIT;
