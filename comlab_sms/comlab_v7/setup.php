<?php
// ============================================
// COMLAB - Setup & Seed Script (NEW)
// URL: /comlab/setup.php?key=comlab_setup_2025
//
// Seeds: 1 Admin + 3 Faculty, 3 labs, 15 devices,
//        4 recurring schedules, lab usage + equipment logs,
//        ~3 weeks attendance, 3 sample requests.
//
// Delete this file after confirming login works!
// ============================================

define('SETUP_KEY', 'comlab_setup_2025');

if (!isset($_GET['key']) || $_GET['key'] !== SETUP_KEY) {
    http_response_code(403);
    die('<h2>403 Forbidden</h2><p>Access: <code>?key='.SETUP_KEY.'</code></p>');
}

require_once __DIR__.'/config/db.php';

$lockFile = __DIR__.'/setup.lock';
if (file_exists($lockFile) && !isset($_GET['force'])) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif;color:#dc2626;padding:40px">
         Setup already completed.<br>
         <small style="color:#6b7280;font-size:13px">
           Add <code>&force=1</code> to re-seed (skips existing records).
         </small></h2>');
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>COMLAB Setup</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  body{font-family:'DM Sans',sans-serif;background:#0f172a;color:#94a3b8;padding:2rem;max-width:860px;margin:0 auto}
  h2{color:#60a5fa;margin-bottom:1.25rem;font-size:1.3rem}
  pre{background:#1e293b;padding:1rem;border-radius:10px;overflow:auto;line-height:1.8;font-family:'DM Mono',monospace;font-size:.8rem}
  .ok{color:#34d399}.err{color:#f87171}.warn{color:#fbbf24}.skip{color:#475569}
  strong.h{color:#93c5fd}
  .done{background:#052e16;border:1px solid #166534;border-radius:10px;padding:1.5rem;margin-top:1.5rem}
  table{border-collapse:collapse;width:100%;font-size:.82rem}
  th{color:#60a5fa;text-align:left;padding:.35rem .75rem .35rem 0;border-bottom:1px solid #1e293b}
  td{padding:.35rem .75rem .35rem 0;color:#94a3b8;vertical-align:top}
  code{background:#1e293b;padding:.1rem .4rem;border-radius:4px;font-family:'DM Mono',monospace;font-size:.78rem}
  a{color:#60a5fa}
  ul{padding-left:1.2rem;line-height:2;font-size:.82rem;color:#64748b}
</style>
</head>
<body>
<h2><i>🛠</i> COMLAB — Setup & Seed</h2>
<?php

$log = []; $errors = 0;

function ok(string $m):   void { global $log; $log[] = "<span class='ok'>  ✓ {$m}</span>"; }
function warn(string $m): void { global $log; $log[] = "<span class='warn'>  ⚠ {$m}</span>"; }
function skip(string $m): void { global $log; $log[] = "<span class='skip'>  · {$m}</span>"; }
function fail(string $m): void { global $log, $errors; $errors++; $log[] = "<span class='err'>  ✗ {$m}</span>"; }
function hd(string $m):   void { global $log; $log[] = "\n<strong class='h'>→ {$m}</strong>"; }

try {
    $db = getDB();

    hd('Seeding department integration directory...');

    $departments = [
        ['REGISTRAR', 'Registrar', 'Student enrollment and academic records hub.'],
        ['CASHIER', 'Cashier', 'Payments and financial clearing.'],
        ['CLINIC', 'Clinic', 'Medical and health services office.'],
        ['GUIDANCE', 'Guidance Office', 'Counseling and student support.'],
        ['PREFECT', 'Prefect Office', 'Discipline and student conduct.'],
        ['COMLAB', 'Computer Laboratory', 'Computer laboratory operations.'],
        ['CRAD', 'CRAD Management', 'Student activities, laboratory programs, and co-curricular coordination.'],
        ['HR', 'HR Department', 'Human resources and staffing.'],
        ['PMED', 'PMED Department', 'Monitoring, evaluation, and development.'],
        ['ADMIN', 'School Administration', 'School-wide administration and approvals.'],
    ];

    $deptId = [];
    foreach ($departments as [$code, $name, $desc]) {
        $chk = $db->prepare('SELECT department_id FROM departments WHERE department_code = ?');
        $chk->execute([$code]);
        if ($existing = $chk->fetchColumn()) {
            skip("Department '{$code}' already exists.");
            $deptId[$code] = $existing;
            continue;
        }

        $deptId[$code] = insertReturningId(
            $db,
            'INSERT INTO departments (department_code, department_name, description, is_active)
             VALUES (?, ?, ?, 1)',
            [$code, $name, $desc],
            'department_id'
        );
        ok("Department route profile: {$code} - {$name}");
    }

    $recordTypes = [
        ['student_enrollment_data', 'Student Enrollment Data', 'student', 'Enrollment details sent from Registrar to Cashier.'],
        ['payment_confirmation', 'Payment Confirmation', 'finance', 'Cashier confirmation sent to Registrar.'],
        ['medical_clearance', 'Medical Clearance', 'health', 'Clinic clearance sent to Registrar.'],
        ['counseling_reports', 'Counseling Reports', 'health', 'Counseling reports received by Registrar.'],
        ['discipline_records', 'Discipline Records', 'discipline', 'Discipline information received by Registrar.'],
        ['activity_participation_records', 'Activity Participation Records', 'student', 'Activity participation records from CRAD.'],
        ['student_personal_information', 'Student Personal Information', 'student', 'Shared student profile information.'],
        ['student_list', 'Student List', 'student', 'Student listing shared with recipient offices.'],
        ['enrollment_statistics', 'Enrollment Statistics', 'student', 'Enrollment counts and trends.'],
        ['payroll_data', 'Payroll Data', 'staff', 'Payroll-related data for Cashier.'],
        ['financial_reports', 'Financial Reports', 'finance', 'Financial reports sent to PMED.'],
        ['health_incident_reports', 'Health Incident Reports', 'health', 'Incident reports from Prefect to Clinic.'],
        ['health_reports', 'Health Reports', 'health', 'Health reports from Clinic to Guidance.'],
        ['medical_service_reports', 'Medical Service Reports', 'health', 'Clinic reporting to PMED.'],
        ['student_academic_records', 'Student Academic Records', 'student', 'Academic records used by Guidance.'],
        ['health_concerns', 'Health Concerns', 'health', 'Concerns forwarded from Guidance to Clinic.'],
        ['discipline_reports', 'Discipline Reports', 'discipline', 'Reports shared between Guidance and Prefect.'],
        ['incident_reports', 'Incident Reports', 'discipline', 'Incident reports shared by Prefect.'],
        ['discipline_statistics', 'Discipline Statistics', 'discipline', 'Discipline statistics sent to PMED.'],
        ['staff_list', 'Staff List', 'staff', 'Staff list received by the Computer Laboratory.'],
        ['user_accounts', 'User Accounts', 'staff', 'User account data for laboratory access.'],
        ['pmed_faculty_attendance', 'PMED Faculty Attendance', 'staff', 'Faculty attendance information from PMED.'],
        ['faculty_schedule_assignments', 'Faculty Schedule Assignments', 'staff', 'HR-managed faculty schedule assignments sent to COMLAB.'],
        ['student_account_information', 'Student Account Information', 'student', 'Registrar-managed student account identities and access details for COMLAB.'],
        ['class_schedule_feed', 'Class Schedule Feed', 'student', 'Registrar schedule feed used by COMLAB for laboratory planning.'],
        ['subject_lab_assignments', 'Subject and Lab Assignments', 'student', 'Registrar subject and lab assignment plan routed to COMLAB.'],
        ['laboratory_usage_reports', 'Laboratory Usage Reports', 'lab', 'Usage reports sent to PMED.'],
        ['laboratory_attendance_records', 'Laboratory Attendance Records', 'lab', 'Laboratory attendance records sent from COMLAB to Registrar.'],
        ['equipment_log_reports', 'Equipment Log Reports', 'lab', 'Equipment maintenance and readiness logs sent from COMLAB to PMED.'],
        ['laboratory_activity_reports', 'Laboratory Activity Reports', 'program', 'Laboratory activity summaries sent from COMLAB to CRAD Management.'],
        ['lab_fee_assessment', 'Lab Fee Assessment', 'finance', 'Computer Laboratory billing assessment sent to Cashier.'],
        ['facility_access_report', 'Facility Access Report', 'staff', 'Facility access and utilization report sent to HR.'],
        ['student_recommendations', 'Student Recommendations', 'student', 'Recommendations coming from Guidance.'],
        ['student_activity_records', 'Student Activity Records', 'student', 'Activity records sent back to Registrar.'],
        ['program_reports', 'Program Reports', 'program', 'Program-level reports from CRAD.'],
        ['program_activity_reports', 'Program Activity Reports', 'program', 'Program activity reports sent to PMED.'],
        ['staff_evaluation_feedback', 'Staff Evaluation Feedback', 'staff', 'Feedback received by HR from PMED.'],
        ['faculty_list', 'Faculty List', 'staff', 'Faculty list shared with Registrar.'],
        ['employee_performance_records', 'Employee Performance Records', 'staff', 'Performance records reported by HR.'],
        ['evaluation_reports', 'Evaluation Reports', 'program', 'School administration evaluation reports from PMED.'],
    ];

    $recordTypeId = [];
    foreach ($recordTypes as [$code, $name, $domain, $desc]) {
        $chk = $db->prepare('SELECT record_type_id FROM integration_record_types WHERE record_type_code = ?');
        $chk->execute([$code]);
        if ($existing = $chk->fetchColumn()) {
            skip("Integration record type '{$code}' already exists.");
            $recordTypeId[$code] = $existing;
            continue;
        }

        $recordTypeId[$code] = insertReturningId(
            $db,
            'INSERT INTO integration_record_types (record_type_code, record_type_name, data_domain, description, is_active)
             VALUES (?, ?, ?, ?, 1)',
            [$code, $name, $domain, $desc],
            'record_type_id'
        );
        ok("Integration record type: {$code}");
    }

    $routes = [
        [1, 'REGISTRAR', 'CASHIER', 'student_enrollment_data', 'Registrar sends enrollment data to Cashier.'],
        [2, 'CASHIER', 'REGISTRAR', 'payment_confirmation', 'Cashier sends payment confirmation to Registrar.'],
        [3, 'REGISTRAR', 'CLINIC', 'student_personal_information', 'Registrar shares student personal information with Clinic.'],
        [4, 'REGISTRAR', 'GUIDANCE', 'student_personal_information', 'Registrar shares student personal information with Guidance.'],
        [5, 'REGISTRAR', 'PREFECT', 'student_personal_information', 'Registrar shares student personal information with Prefect.'],
        [6, 'REGISTRAR', 'COMLAB', 'student_list', 'Registrar shares the student list with the Computer Laboratory.'],
        [7, 'REGISTRAR', 'CRAD', 'student_list', 'Registrar shares the student list with CRAD.'],
        [8, 'REGISTRAR', 'PMED', 'enrollment_statistics', 'Registrar sends enrollment statistics to PMED.'],
        [9, 'CLINIC', 'REGISTRAR', 'medical_clearance', 'Clinic sends medical clearance to Registrar.'],
        [10, 'GUIDANCE', 'REGISTRAR', 'counseling_reports', 'Guidance sends counseling reports to Registrar.'],
        [11, 'PREFECT', 'REGISTRAR', 'discipline_records', 'Prefect sends discipline records to Registrar.'],
        [12, 'PREFECT', 'CLINIC', 'health_incident_reports', 'Prefect sends health incident reports to Clinic.'],
        [13, 'CLINIC', 'GUIDANCE', 'health_reports', 'Clinic sends health reports to Guidance.'],
        [14, 'CLINIC', 'PMED', 'medical_service_reports', 'Clinic sends medical service reports to PMED.'],
        [15, 'GUIDANCE', 'CLINIC', 'health_concerns', 'Guidance sends health concerns to Clinic.'],
        [16, 'GUIDANCE', 'PREFECT', 'discipline_reports', 'Guidance sends discipline reports to Prefect.'],
        [17, 'PREFECT', 'GUIDANCE', 'discipline_reports', 'Prefect sends discipline reports to Guidance.'],
        [18, 'PREFECT', 'CLINIC', 'incident_reports', 'Prefect sends incident reports to Clinic.'],
        [19, 'PREFECT', 'PMED', 'discipline_statistics', 'Prefect sends discipline statistics to PMED.'],
        [20, 'HR', 'CASHIER', 'payroll_data', 'HR sends payroll data to Cashier.'],
        [21, 'HR', 'REGISTRAR', 'faculty_list', 'HR sends the faculty list to Registrar.'],
        [22, 'HR', 'PMED', 'employee_performance_records', 'HR sends employee performance records to PMED.'],
        [23, 'PMED', 'COMLAB', 'pmed_faculty_attendance', 'PMED sends faculty attendance data to the Computer Laboratory.'],
        [24, 'HR', 'COMLAB', 'faculty_schedule_assignments', 'HR sends faculty schedule assignments to the Computer Laboratory.'],
        [25, 'COMLAB', 'PMED', 'laboratory_usage_reports', 'Computer Laboratory sends laboratory usage reports to PMED.'],
        [26, 'COMLAB', 'CASHIER', 'lab_fee_assessment', 'Computer Laboratory sends lab fee assessments to Cashier.'],
        [27, 'COMLAB', 'HR', 'facility_access_report', 'Computer Laboratory sends facility access reports to HR.'],
        [28, 'CRAD', 'REGISTRAR', 'activity_participation_records', 'CRAD sends activity participation records to Registrar.'],
        [29, 'CRAD', 'REGISTRAR', 'student_activity_records', 'CRAD sends student activity records to Registrar.'],
        [30, 'CRAD', 'PMED', 'program_reports', 'CRAD sends program reports to PMED.'],
        [31, 'CRAD', 'PMED', 'program_activity_reports', 'CRAD sends program activity reports to PMED.'],
        [32, 'GUIDANCE', 'CRAD', 'student_recommendations', 'Guidance sends student recommendations to CRAD.'],
        [33, 'CASHIER', 'PMED', 'financial_reports', 'Cashier sends financial reports to PMED.'],
        [34, 'PMED', 'ADMIN', 'evaluation_reports', 'PMED sends evaluation reports to School Administration.'],
        [35, 'PMED', 'HR', 'staff_evaluation_feedback', 'PMED sends staff evaluation feedback to HR.'],
        [36, 'HR', 'COMLAB', 'staff_list', 'HR sends the staff list to the Computer Laboratory.'],
        [37, 'HR', 'COMLAB', 'user_accounts', 'HR sends user account data to the Computer Laboratory.'],
        [38, 'REGISTRAR', 'COMLAB', 'student_account_information', 'Registrar sends student account information to the Computer Laboratory.'],
        [39, 'REGISTRAR', 'COMLAB', 'class_schedule_feed', 'Registrar sends class schedules to the Computer Laboratory.'],
        [40, 'REGISTRAR', 'COMLAB', 'subject_lab_assignments', 'Registrar sends subject and lab assignments to the Computer Laboratory.'],
        [41, 'COMLAB', 'REGISTRAR', 'laboratory_attendance_records', 'Computer Laboratory sends laboratory attendance records to Registrar.'],
        [42, 'COMLAB', 'PMED', 'equipment_log_reports', 'Computer Laboratory sends equipment maintenance logs to PMED.'],
        [43, 'COMLAB', 'CRAD', 'laboratory_activity_reports', 'Computer Laboratory sends laboratory activity reports to CRAD Management.'],
    ];

    foreach ($routes as [$flowOrder, $senderCode, $receiverCode, $recordCode, $notes]) {
        $senderId = $deptId[$senderCode] ?? null;
        $receiverId = $deptId[$receiverCode] ?? null;
        $typeId = $recordTypeId[$recordCode] ?? null;

        if (!$senderId || !$receiverId || !$typeId) {
            warn("Integration route {$senderCode} -> {$receiverCode} ({$recordCode}) skipped because a dependency is missing.");
            continue;
        }

        $chk = $db->prepare(
            'SELECT route_id
             FROM integration_routes
             WHERE sender_department_id = ? AND receiver_department_id = ? AND record_type_id = ?'
        );
        $chk->execute([$senderId, $receiverId, $typeId]);
        if ($chk->fetchColumn()) {
            skip("Integration route {$senderCode} -> {$receiverCode} ({$recordCode}) already exists.");
            continue;
        }

        $db->prepare(
            'INSERT INTO integration_routes (flow_order, sender_department_id, receiver_department_id, record_type_id, notes, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$flowOrder, $senderId, $receiverId, $typeId, $notes]);
        ok("Integration route: {$senderCode} -> {$receiverCode} ({$recordCode})");
    }

    // ── 1. USERS ──────────────────────────────────────────────────────────
    hd('Creating users…');

    $users = [
        ['username'=>'admin',   'email'=>'admin@comlab.edu',   'password'=>'Admin@123',
         'first_name'=>'System','last_name'=>'Administrator','role'=>'Administrator',
         'dept'=>'IT Department'],
        ['username'=>'msantos', 'email'=>'msantos@comlab.edu', 'password'=>'Faculty@123',
         'first_name'=>'Maria', 'last_name'=>'Santos','role'=>'Faculty',
         'dept'=>'College of Computer Studies'],
        ['username'=>'jreyes',  'email'=>'jreyes@comlab.edu',  'password'=>'Faculty@123',
         'first_name'=>'Jose',  'last_name'=>'Reyes', 'role'=>'Faculty',
         'dept'=>'College of Computer Studies'],
        ['username'=>'acruz',   'email'=>'acruz@comlab.edu',   'password'=>'Faculty@123',
         'first_name'=>'Ana',   'last_name'=>'Cruz',  'role'=>'Faculty',
         'dept'=>'College of Information Technology'],
    ];

    $uid = []; // username → user_id
    foreach ($users as $u) {
        $chk = $db->prepare('SELECT user_id FROM users WHERE username=? OR email=?');
        $chk->execute([$u['username'], $u['email']]);
        if ($existing = $chk->fetchColumn()) {
            skip("User '{$u['username']}' already exists — skipped.");
            $uid[$u['username']] = $existing; continue;
        }
        $hash = password_hash($u['password'], PASSWORD_BCRYPT, ['cost'=>10]);
        $uid[$u['username']] = insertReturningId(
            $db,
            'INSERT INTO users (username,email,password_hash,first_name,last_name,role,department,is_active)
             VALUES (?,?,?,?,?,?,?,1)',
            [$u['username'],$u['email'],$hash,$u['first_name'],$u['last_name'],$u['role'],$u['dept']],
            'user_id'
        );
        ok("[{$u['role']}] {$u['first_name']} {$u['last_name']} — <code>{$u['username']}</code> / <code>{$u['password']}</code>");
    }

    // ── 2. LAB LOCATIONS ──────────────────────────────────────────────────
    hd('Creating lab locations…');

    $labs = [
        ['Computer Lab A','LAB-A','Main Building',  '2nd Floor','201',30,'07:30:00','19:00:00'],
        ['Computer Lab B','LAB-B','Main Building',  '2nd Floor','202',25,'07:30:00','19:00:00'],
        ['Computer Lab C','LAB-C','Annex Building', '1st Floor','101',20,'08:00:00','17:00:00'],
    ];

    $lid = []; // lab_code → location_id
    foreach ($labs as $l) {
        $chk = $db->prepare('SELECT location_id FROM locations WHERE lab_code=?');
        $chk->execute([$l[1]]);
        if ($existing = $chk->fetchColumn()) {
            skip("Lab '{$l[1]}' already exists — skipped.");
            $lid[$l[1]] = $existing; continue;
        }
        $lid[$l[1]] = insertReturningId(
            $db,
            'INSERT INTO locations (lab_name,lab_code,building,floor,room_number,capacity,operating_hours_start,operating_hours_end,is_active)
             VALUES (?,?,?,?,?,?,?,?,1)',
            $l,
            'location_id'
        );
        ok("Lab: {$l[0]} ({$l[1]}) — cap {$l[5]}, {$l[6]}–{$l[7]}");
    }

    // ── 3. DEVICES ────────────────────────────────────────────────────────
    hd('Adding sample devices…');

    $devs = [
        ['PC-A-001','Desktop','Dell',   'OptiPlex 7090',       'SN-A001','Available',  $lid['LAB-A']??null],
        ['PC-A-002','Desktop','Dell',   'OptiPlex 7090',       'SN-A002','Available',  $lid['LAB-A']??null],
        ['PC-A-003','Desktop','Dell',   'OptiPlex 7090',       'SN-A003','Under Repair',$lid['LAB-A']??null],
        ['PC-A-004','Desktop','Dell',   'OptiPlex 7090',       'SN-A004','Available',  $lid['LAB-A']??null],
        ['PC-A-005','Desktop','Dell',   'OptiPlex 7090',       'SN-A005','Damaged',    $lid['LAB-A']??null],
        ['PC-A-006','Desktop','Dell',   'OptiPlex 7090',       'SN-A006','Available',  $lid['LAB-A']??null],
        ['PC-B-001','Desktop','HP',     'EliteDesk 805 G8',   'SN-B001','Available',  $lid['LAB-B']??null],
        ['PC-B-002','Desktop','HP',     'EliteDesk 805 G8',   'SN-B002','Available',  $lid['LAB-B']??null],
        ['PC-B-003','Desktop','HP',     'EliteDesk 805 G8',   'SN-B003','In Use',     $lid['LAB-B']??null],
        ['PC-B-004','Desktop','HP',     'EliteDesk 805 G8',   'SN-B004','Available',  $lid['LAB-B']??null],
        ['PC-C-001','Desktop','Lenovo', 'ThinkCentre M80s',   'SN-C001','Available',  $lid['LAB-C']??null],
        ['PC-C-002','Desktop','Lenovo', 'ThinkCentre M80s',   'SN-C002','Available',  $lid['LAB-C']??null],
        ['PRINT-A-001','Printer','Canon','iR-ADV 525i',       'SN-P001','Available',  $lid['LAB-A']??null],
        ['MON-A-001','Monitor','Dell',  'P2422H 24"',         'SN-M001','Available',  $lid['LAB-A']??null],
        ['KBD-A-001','Keyboard','Logitech','MK270 Wireless',  'SN-K001','Available',  $lid['LAB-A']??null],
    ];

    foreach ($devs as $d) {
        if (!$d[6]) { warn("Device '{$d[0]}' skipped — lab not found."); continue; }
        $chk = $db->prepare('SELECT device_id FROM devices WHERE device_code=?');
        $chk->execute([$d[0]]);
        if ($chk->fetchColumn()) { skip("Device '{$d[0]}' already exists."); continue; }
        $db->prepare(
            'INSERT INTO devices (device_code,device_type,brand,model,serial_number,status,location_id,purchase_date)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$d[0],$d[1],$d[2],$d[3],$d[4],$d[5],$d[6],'2023-06-01']);
        ok("Device: {$d[0]} ({$d[1]}) — {$d[5]}");
    }

    // ── 4. FACULTY SCHEDULES ──────────────────────────────────────────────
    hd('Creating recurring faculty schedules…');

    $adminId  = $uid['admin']   ?? null;
    $santosId = $uid['msantos'] ?? null;
    $reyesId  = $uid['jreyes']  ?? null;
    $cruzId   = $uid['acruz']   ?? null;
    $labA = $lid['LAB-A'] ?? null;
    $labB = $lid['LAB-B'] ?? null;
    $labC = $lid['LAB-C'] ?? null;

    $semStart = date('Y-01-01');
    $semEnd   = date('Y-06-30');

    // [faculty_id, assigned_by, location_id, class_name, day_of_week, start, end, dur, sem_start, sem_end, dept, notes]
    $scheds = [
        [$santosId,$adminId,$labA,'CIS101 - Introduction to Computing',
         'Monday,Wednesday','08:00:00','10:00:00',2.0,$semStart,$semEnd,
         'College of Computer Studies','Core subject — 1st year'],

        [$santosId,$adminId,$labB,'CS201 - Object-Oriented Programming',
         'Tuesday,Thursday','10:00:00','12:00:00',2.0,$semStart,$semEnd,
         'College of Computer Studies',''],

        [$reyesId,$adminId,$labB,'IT301 - Database Management Systems',
         'Monday,Wednesday,Friday','13:00:00','15:00:00',2.0,$semStart,$semEnd,
         'College of Computer Studies','SQL practicals included'],

        [$cruzId,$adminId,$labC,'IT101 - Computer Fundamentals',
         'Tuesday,Thursday','08:00:00','10:00:00',2.0,$semStart,$semEnd,
         'College of Information Technology',''],
    ];

    $schedIds = [];
    foreach ($scheds as $s) {
        if (!$s[0]||!$s[1]||!$s[2]) { warn("Schedule '{$s[3]}': missing ID — skipped."); continue; }
        $chk = $db->prepare('SELECT schedule_id FROM faculty_schedules WHERE faculty_id=? AND class_name=? AND semester_start=?');
        $chk->execute([$s[0],$s[3],$s[8]]);
        if ($existing = $chk->fetchColumn()) {
            skip("Schedule '{$s[3]}' already exists (id={$existing}).");
            $schedIds[] = $existing; continue;
        }
        $id = insertReturningId(
            $db,
            'INSERT INTO faculty_schedules
             (faculty_id,assigned_by,location_id,class_name,day_of_week,
              start_time,end_time,duration_hours,semester_start,semester_end,
              department,notes,source_system,source_reference,synced_from_hr,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)',
            array_merge(
                $s,
                [
                    'HR',
                    'setup-hr-sync-' . $s[0] . '-' . strtolower(preg_replace('/[^a-z0-9]+/', '-', $s[3])),
                    1,
                ]
            ),
            'schedule_id'
        );
        $schedIds[] = $id;
        ok("Schedule: {$s[3]} — {$s[4]}, {$s[5]}–{$s[6]}");
    }

    // ── 5. ATTENDANCE HISTORY (past 3 weeks) ──────────────────────────────
    hd('Seeding ~3 weeks of attendance history…');

    hd('Adding sample lab usage logs...');

    $usageLogs = [
        ['LAB-A', $santosId ?? null, 'CIS101 - Introduction to Computing', 'CIS101', date('Y-m-d', strtotime('-5 days')), '08:05:00', '09:55:00', 28, 'seed-usage-lab-a-001', 'Intro computing hands-on laboratory session.'],
        ['LAB-B', $santosId ?? null, 'CS201 - Object-Oriented Programming', 'CS201', date('Y-m-d', strtotime('-4 days')), '10:02:00', '11:48:00', 24, 'seed-usage-lab-b-001', 'Object-oriented programming coding drills.'],
        ['LAB-B', $reyesId ?? null, 'IT301 - Database Management Systems', 'IT301', date('Y-m-d', strtotime('-3 days')), '13:06:00', '14:52:00', 22, 'seed-usage-lab-b-002', 'Database practicals and SQL lab assessment.'],
        ['LAB-C', $cruzId ?? null, 'IT101 - Computer Fundamentals', 'IT101', date('Y-m-d', strtotime('-2 days')), '08:03:00', '09:45:00', 18, 'seed-usage-lab-c-001', 'Computer fundamentals orientation and lab walk-through.'],
    ];

    $usageCount = 0;
    foreach ($usageLogs as [$labCode, $facultyId, $className, $subjectCode, $usageDate, $startTime, $endTime, $participants, $sourceReference, $notes]) {
        $locationId = $lid[$labCode] ?? null;
        if (!$locationId || !$facultyId || !$adminId) {
            warn("Lab usage '{$sourceReference}' skipped - missing lab, faculty, or admin.");
            continue;
        }

        $chk = $db->prepare('SELECT usage_log_id FROM lab_usage_logs WHERE source_reference = ?');
        $chk->execute([$sourceReference]);
        if ($chk->fetchColumn()) {
            skip("Lab usage '{$sourceReference}' already exists.");
            continue;
        }

        $scheduleStmt = $db->prepare('SELECT schedule_id FROM faculty_schedules WHERE faculty_id = ? AND class_name = ? AND is_active = 1 LIMIT 1');
        $scheduleStmt->execute([$facultyId, $className]);
        $scheduleId = $scheduleStmt->fetchColumn() ?: null;

        $db->prepare(
            'INSERT INTO lab_usage_logs
             (location_id, faculty_id, schedule_id, recorded_by, usage_date, session_start, session_end, participant_count, subject_code, source_system, source_reference, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $locationId,
            $facultyId,
            $scheduleId,
            $adminId,
            $usageDate,
            $usageDate . ' ' . $startTime,
            $usageDate . ' ' . $endTime,
            $participants,
            $subjectCode,
            'COMLAB',
            $sourceReference,
            $notes,
        ]);
        $usageCount++;
    }
    ok("{$usageCount} lab usage log(s) seeded.");

    hd('Adding sample equipment maintenance logs...');

    $maintenanceLogs = [
        ['PC-A-003', 'Repair', 'Random shutdown during classroom sessions.', 'Re-seated memory modules and scheduled PSU observation.', 'Under Repair', 'Under Repair', 1250.00, date('Y-m-d', strtotime('-10 days')) . ' 09:00:00', date('Y-m-d', strtotime('-10 days')) . ' 11:00:00'],
        ['PC-A-005', 'Inspection', 'Chassis dent and unstable keyboard port.', 'Logged physical damage and isolated the workstation from classroom use.', 'Damaged', 'Damaged', 0.00, date('Y-m-d', strtotime('-7 days')) . ' 09:00:00', date('Y-m-d', strtotime('-7 days')) . ' 10:00:00'],
        ['PRINT-A-001', 'Preventive Maintenance', 'Scheduled cleaning before heavy reporting cycle.', 'Cleaned rollers and recalibrated tray alignment.', 'Available', 'Available', 350.00, date('Y-m-d', strtotime('-4 days')) . ' 09:00:00', date('Y-m-d', strtotime('-4 days')) . ' 10:30:00'],
    ];

    $maintenanceCount = 0;
    foreach ($maintenanceLogs as [$deviceCode, $maintenanceType, $issueDescription, $actionTaken, $statusBefore, $statusAfter, $cost, $startDateTime, $endDateTime]) {
        $deviceStmt = $db->prepare('SELECT device_id FROM devices WHERE device_code = ? LIMIT 1');
        $deviceStmt->execute([$deviceCode]);
        $deviceId = $deviceStmt->fetchColumn() ?: null;
        if (!$deviceId || !$adminId) {
            warn("Equipment log for '{$deviceCode}' skipped - missing device or admin.");
            continue;
        }

        $chk = $db->prepare(
            'SELECT maintenance_id
             FROM device_maintenance_logs
             WHERE device_id = ? AND maintenance_type = ? AND start_datetime = ?'
        );
        $chk->execute([$deviceId, $maintenanceType, $startDateTime]);
        if ($chk->fetchColumn()) {
            skip("Equipment log '{$deviceCode}' / '{$maintenanceType}' already exists.");
            continue;
        }

        $db->prepare(
            'INSERT INTO device_maintenance_logs
             (device_id, performed_by, maintenance_type, issue_description, action_taken, status_before, status_after, cost, start_datetime, end_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $deviceId,
            $adminId,
            $maintenanceType,
            $issueDescription,
            $actionTaken,
            $statusBefore,
            $statusAfter,
            $cost,
            $startDateTime,
            $endDateTime,
        ]);
        $maintenanceCount++;
    }
    ok("{$maintenanceCount} equipment maintenance log(s) seeded.");

    $allScheds = $db->query(
        "SELECT schedule_id, faculty_id, day_of_week, start_time, semester_start, semester_end
         FROM faculty_schedules WHERE is_active=1"
    )->fetchAll();

    $today = new DateTime(); $attCount = 0;

    foreach ($allScheds as $sc) {
        $days = array_map('trim', explode(',', $sc['day_of_week']));
        for ($dBack = 21; $dBack >= 1; $dBack--) {
            $dt      = (clone $today)->modify("-{$dBack} days");
            $dayName = $dt->format('l');
            if (!in_array($dayName, $days, true)) continue;
            $dateStr = $dt->format('Y-m-d');
            if ($dateStr < $sc['semester_start'] || $dateStr > $sc['semester_end']) continue;

            $chk = $db->prepare('SELECT attendance_id FROM schedule_attendance WHERE schedule_id=? AND attendance_date=?');
            $chk->execute([$sc['schedule_id'], $dateStr]);
            if ($chk->fetchColumn()) continue;

            $rand = mt_rand(1,100);
            if ($rand <= 78) {
                $status = 'Present';
                $checkIn = $dateStr.' '.date('H:i:s', strtotime($sc['start_time']) - mt_rand(0, 840));
                $auto = 0;
            } elseif ($rand <= 92) {
                $status = 'Absent'; $checkIn = null; $auto = 1;
            } else {
                $status = 'Excused'; $checkIn = null; $auto = 0;
            }

            $db->prepare(
                'INSERT INTO schedule_attendance
                 (schedule_id,faculty_id,attendance_date,status,checked_in_at,marked_by_system)
                 VALUES (?,?,?,?,?,?)
                 ON CONFLICT (schedule_id, attendance_date) DO NOTHING'
            )->execute([$sc['schedule_id'],$sc['faculty_id'],$dateStr,$status,$checkIn,$auto]);
            $attCount++;
        }
    }
    ok("{$attCount} attendance record(s) seeded across ".count($allScheds)." schedule(s).");

    // ── 6. SAMPLE REQUESTS ────────────────────────────────────────────────
    hd('Adding sample requests…');

    $devId = $db->query("SELECT device_id FROM devices WHERE device_code='PC-A-003'")->fetchColumn();

    $reqs = [
        ['Maintenance',$santosId??0,'College of Computer Studies',$labA??null,$devId??null,
         'PC-A-003 randomly shuts down mid-session. Needs hardware inspection.',
         date('Y-m-d',strtotime('+3 days')),'Pending'],
        ['Unit',$cruzId??0,'College of Information Technology',$labC??null,null,
         'Requesting 5 additional keyboards for Lab C — current units worn out.',
         date('Y-m-d',strtotime('+7 days')),'Pending'],
        ['Maintenance',$reyesId??0,'College of Computer Studies',$labB??null,null,
         'Lab B projector lamp flickering. Needs replacement.',
         date('Y-m-d',strtotime('+5 days')),'Approved'],
    ];

    foreach ($reqs as $r) {
        if (!$r[1]) { warn("Request skipped — no faculty ID."); continue; }
        $chk = $db->prepare('SELECT request_id FROM requests WHERE submitted_by=? AND issue_description=?');
        $chk->execute([$r[1],$r[5]]);
        if ($chk->fetchColumn()) { skip("Request already exists — skipped."); continue; }
        $db->prepare(
            'INSERT INTO requests (request_type,submitted_by,department,location_id,device_id,issue_description,date_needed,status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute($r);
        ok("Request ({$r[0]}): {$r[7]} — ".mb_substr($r[5],0,50).'…');
    }

    ok("\nSetup complete!");

} catch (Exception $e) {
    fail("ERROR: ".htmlspecialchars($e->getMessage()));
}

echo '<pre>'.implode("\n",$log).'</pre>';

if (!$errors): ?>
<div class="done">
  <h3 style="color:#34d399;margin:0 0 1rem">✅ System ready to test</h3>

  <p style="margin-bottom:.65rem;font-size:.82rem"><strong>Login Credentials</strong></p>
  <table>
    <tr><th>Role</th><th>Username</th><th>Password</th><th>What to test</th></tr>
    <tr>
      <td>Administrator</td><td><code>admin</code></td><td><code>Admin@123</code></td>
      <td>Assign schedules · manage devices · review requests · run absence sweep · view attendance summary</td>
    </tr>
    <tr>
      <td>Faculty</td><td><code>msantos</code></td><td><code>Faculty@123</code></td>
      <td>View assigned schedule · check-in · submit request · view own attendance</td>
    </tr>
    <tr>
      <td>Faculty</td><td><code>jreyes</code></td><td><code>Faculty@123</code></td>
      <td>Mon/Wed/Fri 13:00–15:00 Lab B schedule</td>
    </tr>
    <tr>
      <td>Faculty</td><td><code>acruz</code></td><td><code>Faculty@123</code></td>
      <td>Tue/Thu Lab C — different department</td>
    </tr>
  </table>

  <p style="margin:1rem 0 .4rem;font-size:.8rem"><strong>What was seeded</strong></p>
  <ul>
    <li>4 users — 1 Administrator, 3 Faculty</li>
    <li>3 lab locations — Lab A (30 seats), Lab B (25), Lab C (20)</li>
    <li>15 devices across all labs (mixed statuses)</li>
    <li>4 recurring faculty schedules across different days &amp; labs</li>
    <li><?= $attCount ?? 0 ?> attendance records — past 3 weeks of history (78% present rate)</li>
    <li>3 sample requests — 2 Pending, 1 Approved</li>
  </ul>

  <p style="margin-top:1rem;color:#fbbf24;font-size:.78rem">
    ⚠ <strong>Delete or rename setup.php</strong> after confirming login works.
  </p>
  <p style="margin-top:.5rem"><a href="login.php">→ Go to Login Page</a></p>
</div>
<?php endif; ?>
</body></html>
<?php
file_put_contents($lockFile, date('Y-m-d H:i:s'));
