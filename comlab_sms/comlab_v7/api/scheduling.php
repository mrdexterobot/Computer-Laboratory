<?php
// COMLAB - Faculty Schedules API
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');

$user = requireAuth('scheduling');
$role = $user['role'];
$uid  = $user['user_id'];
$ip   = $_SERVER['REMOTE_ADDR'] ?? null;
$ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

define('CHECKIN_BEFORE_MIN', 15);
define('CHECKIN_AFTER_MIN', 30);

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list';

        if (!empty($_GET['export'])) {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="schedules_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Faculty', 'Lab', 'Class', 'Days', 'Start', 'End', 'Semester Start', 'Semester End', 'Dept', 'Status']);
            $rows = $db->query(
                "SELECT fs.schedule_id,
                        CONCAT(u.first_name, ' ', u.last_name),
                        l.lab_name, fs.class_name, fs.day_of_week,
                        fs.start_time, fs.end_time,
                        fs.semester_start, fs.semester_end,
                        fs.department, fs.is_active
                 FROM faculty_schedules fs
                 JOIN users u ON fs.faculty_id = u.user_id
                 JOIN locations l ON fs.location_id = l.location_id
                 ORDER BY fs.semester_start DESC, u.last_name"
            )->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
            exit;
        }

        if ($action === 'today') {
            $dayName = date('l');
            $today   = date('Y-m-d');
            $nowTime = date('H:i:s');
            $dayExpr = sqlCsvContainsDay('fs.day_of_week');

            $params = [$today, $today, $dayName];
            $userFilter = '';
            if ($role === ROLE_FACULTY) {
                $userFilter = 'AND fs.faculty_id = ?';
                $params[]   = $uid;
            }

            $stmt = $db->prepare(
                "SELECT fs.schedule_id, fs.class_name, fs.day_of_week,
                        fs.start_time, fs.end_time, fs.department, fs.notes,
                        l.lab_name, l.lab_code,
                        CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                        fs.faculty_id,
                        sa.status AS attendance_status,
                        sa.checked_in_at,
                        sa.attendance_id
                 FROM faculty_schedules fs
                 JOIN locations l ON fs.location_id = l.location_id
                 JOIN users u ON fs.faculty_id = u.user_id
                 LEFT JOIN schedule_attendance sa
                   ON sa.schedule_id = fs.schedule_id AND sa.attendance_date = ?
                 WHERE fs.is_active = 1
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND $dayExpr
                   $userFilter
                 ORDER BY fs.start_time"
            );
            $orderedParams = [$today, $today, $today, $dayName];
            if ($role === ROLE_FACULTY) {
                $orderedParams[] = $uid;
            }
            $stmt->execute($orderedParams);
            $schedules = $stmt->fetchAll();

            foreach ($schedules as &$s) {
                $startTs = strtotime($today . ' ' . $s['start_time']);
                $windowOpen  = date('H:i:s', $startTs - CHECKIN_BEFORE_MIN * 60);
                $windowClose = date('H:i:s', $startTs + CHECKIN_AFTER_MIN * 60);
                $s['checkin_window_open']  = $windowOpen;
                $s['checkin_window_close'] = $windowClose;
                $s['can_checkin'] = ($role === ROLE_FACULTY)
                    && !$s['attendance_status']
                    && $nowTime >= $windowOpen
                    && $nowTime <= $windowClose;
            }
            unset($s);

            echo json_encode(['success' => true, 'date' => $today, 'schedules' => $schedules]);
            exit;
        }

        if ($action === 'attendance') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $schedId = (int) ($_GET['schedule_id'] ?? 0);
            if (!$schedId) {
                echo json_encode(['success' => false, 'message' => 'schedule_id required.']);
                exit;
            }

            $stmt = $db->prepare(
                "SELECT sa.attendance_id, sa.attendance_date, sa.status,
                        sa.checked_in_at, sa.marked_by_system,
                        CONCAT(u.first_name, ' ', u.last_name) AS faculty_name
                 FROM schedule_attendance sa
                 JOIN faculty_schedules fs ON sa.schedule_id = fs.schedule_id
                 JOIN users u ON fs.faculty_id = u.user_id
                 WHERE sa.schedule_id = ?
                 ORDER BY sa.attendance_date DESC"
            );
            $stmt->execute([$schedId]);

            $summary = $db->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present,
                        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                        SUM(CASE WHEN status = 'Excused' THEN 1 ELSE 0 END) AS excused
                 FROM schedule_attendance
                 WHERE schedule_id = ?"
            );
            $summary->execute([$schedId]);

            echo json_encode([
                'success'    => true,
                'attendance' => $stmt->fetchAll(),
                'summary'    => $summary->fetch(),
            ]);
            exit;
        }

        if ($action === 'check') {
            $locId    = (int) ($_GET['location_id'] ?? 0);
            $days     = trim($_GET['day_of_week'] ?? '');
            $start    = $_GET['start_time'] ?? '';
            $end      = $_GET['end_time'] ?? '';
            $semStart = $_GET['semester_start'] ?? '';
            $semEnd   = $_GET['semester_end'] ?? '';
            $exclude  = (int) ($_GET['exclude_id'] ?? 0);

            if (!$locId || !$days || !$start || !$end || !$semStart || !$semEnd) {
                echo json_encode(['success' => false, 'conflict' => false, 'message' => 'Missing params.']);
                exit;
            }

            $dayList = array_map('trim', explode(',', $days));
            $dayChecks = implode(' OR ', array_fill(0, count($dayList), sqlCsvContainsDay('fs.day_of_week')));
            $params = $dayList;
            $params[] = $locId;
            $params[] = $semEnd;
            $params[] = $semStart;
            $params[] = $end;
            $params[] = $start;

            $excludeClause = $exclude ? 'AND fs.schedule_id <> ?' : '';
            if ($exclude) {
                $params[] = $exclude;
            }

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM faculty_schedules fs
                 WHERE ($dayChecks)
                   AND fs.location_id = ?
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND fs.start_time < ?
                   AND fs.end_time > ?
                   AND fs.is_active = 1
                   $excludeClause"
            );
            $stmt->execute($params);
            echo json_encode(['success' => true, 'conflict' => (bool) $stmt->fetchColumn()]);
            exit;
        }

        $params = [];
        $userFilter = '';
        if ($role === ROLE_FACULTY) {
            $userFilter = 'WHERE fs.faculty_id = ?';
            $params[] = $uid;
        }

        $stmt = $db->prepare(
            "SELECT fs.schedule_id, fs.class_name, fs.day_of_week,
                    fs.start_time, fs.end_time, fs.department, fs.notes,
                    fs.semester_start, fs.semester_end, fs.is_active,
                    fs.faculty_id,
                    l.lab_name, l.lab_code, l.capacity,
                    l.operating_hours_start, l.operating_hours_end,
                    CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                    u.department AS faculty_dept,
                    CONCAT(a.first_name, ' ', a.last_name) AS assigned_by_name,
                    (SELECT COUNT(*) FROM schedule_attendance sa WHERE sa.schedule_id = fs.schedule_id) AS total_sessions,
                    (SELECT SUM(CASE WHEN sa.status = 'Present' THEN 1 ELSE 0 END) FROM schedule_attendance sa WHERE sa.schedule_id = fs.schedule_id) AS present_count
             FROM faculty_schedules fs
             JOIN locations l ON fs.location_id = l.location_id
             JOIN users u ON fs.faculty_id = u.user_id
             JOIN users a ON fs.assigned_by = a.user_id
             $userFilter
             ORDER BY fs.is_active DESC, fs.semester_start DESC, u.last_name"
        );
        $stmt->execute($params);
        echo json_encode(['success' => true, 'schedules' => $stmt->fetchAll()]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF.']);
            exit;
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $facultyId = (int) ($_POST['faculty_id'] ?? 0);
            $locId     = (int) ($_POST['location_id'] ?? 0);
            $className = trim($_POST['class_name'] ?? '');
            $days      = trim($_POST['day_of_week'] ?? '');
            $start     = $_POST['start_time'] ?? '';
            $end       = $_POST['end_time'] ?? '';
            $semStart  = $_POST['semester_start'] ?? '';
            $semEnd    = $_POST['semester_end'] ?? '';
            $dept      = trim($_POST['department'] ?? '');
            $notes     = trim($_POST['notes'] ?? '');

            if (!$facultyId || !$locId || !$className || !$days || !$start || !$end || !$semStart || !$semEnd || !$dept) {
                echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
                exit;
            }

            $facCheck = $db->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'Faculty' AND is_active = 1");
            $facCheck->execute([$facultyId]);
            if (!$facCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Invalid or inactive faculty member.']);
                exit;
            }

            if ($start >= $end) {
                echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
                exit;
            }
            if ($semStart >= $semEnd) {
                echo json_encode(['success' => false, 'message' => 'Semester end must be after semester start.']);
                exit;
            }

            $labStmt = $db->prepare(
                'SELECT capacity, operating_hours_start, operating_hours_end
                 FROM locations
                 WHERE location_id = ? AND is_active = 1'
            );
            $labStmt->execute([$locId]);
            $lab = $labStmt->fetch();
            if (!$lab) {
                echo json_encode(['success' => false, 'message' => 'Lab not found or inactive.']);
                exit;
            }

            $opS = substr($lab['operating_hours_start'], 0, 5);
            $opE = substr($lab['operating_hours_end'], 0, 5);
            if (substr($start, 0, 5) < $opS || substr($end, 0, 5) > $opE) {
                echo json_encode(['success' => false, 'message' => "Lab operates {$opS}-{$opE}. Your time ({$start}-{$end}) falls outside."]);
                exit;
            }

            [$sh, $sm] = array_map('intval', explode(':', $start));
            [$eh, $em] = array_map('intval', explode(':', $end));
            $dur = round((($eh * 60 + $em) - ($sh * 60 + $sm)) / 60, 2);

            $dayList   = array_map('trim', explode(',', $days));
            $dayChecks = implode(' OR ', array_fill(0, count($dayList), sqlCsvContainsDay('fs.day_of_week')));
            $cParams   = $dayList;
            $cParams[] = $locId;
            $cParams[] = $semEnd;
            $cParams[] = $semStart;
            $cParams[] = $end;
            $cParams[] = $start;

            $chk = $db->prepare(
                "SELECT COUNT(*) FROM faculty_schedules fs
                 WHERE ($dayChecks) AND fs.location_id = ?
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND fs.start_time < ?
                   AND fs.end_time > ?
                   AND fs.is_active = 1"
            );
            $chk->execute($cParams);
            if ($chk->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Scheduling conflict: another class is assigned to this lab at overlapping days/times.']);
                exit;
            }

            $newId = insertReturningId(
                $db,
                'INSERT INTO faculty_schedules
                 (faculty_id, assigned_by, location_id, class_name, day_of_week,
                  start_time, end_time, duration_hours, semester_start, semester_end, department, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [$facultyId, $uid, $locId, $className, $days, $start, $end, $dur, $semStart, $semEnd, $dept, $notes],
                'schedule_id'
            );

            auditLog($db, $uid, 'Assignment Created', 'Assignment', (int) $newId,
                "Faculty schedule #{$newId} created: {$className} on {$days} {$start}-{$end} by admin {$uid}.", $ip, $ua);

            echo json_encode(['success' => true, 'schedule_id' => $newId, 'message' => "Schedule created for {$className} on {$days}."]);
            exit;
        }

        if ($action === 'cancel') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $schedId = (int) ($_POST['schedule_id'] ?? 0);
            $chk = $db->prepare('SELECT schedule_id, class_name FROM faculty_schedules WHERE schedule_id = ? AND is_active = 1');
            $chk->execute([$schedId]);
            $sched = $chk->fetch();
            if (!$sched) {
                echo json_encode(['success' => false, 'message' => 'Schedule not found or already cancelled.']);
                exit;
            }

            $db->prepare('UPDATE faculty_schedules SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = ?')
               ->execute([$schedId]);

            auditLog($db, $uid, 'Assignment Cancelled', 'Assignment', $schedId,
                "Faculty schedule #{$schedId} ({$sched['class_name']}) cancelled by admin {$uid}.", $ip, $ua);

            echo json_encode(['success' => true, 'message' => 'Schedule cancelled.']);
            exit;
        }

        if ($action === 'checkin') {
            if ($role !== ROLE_FACULTY) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Faculty only.']);
                exit;
            }

            $schedId = (int) ($_POST['schedule_id'] ?? 0);
            $today   = date('Y-m-d');
            $dayName = date('l');
            $nowTime = date('H:i:s');
            $dayExpr = sqlCsvContainsDay('fs.day_of_week');

            $sched = $db->prepare(
                "SELECT fs.schedule_id, fs.start_time, fs.end_time, fs.class_name, fs.faculty_id
                 FROM faculty_schedules fs
                 WHERE fs.schedule_id = ?
                   AND fs.faculty_id = ?
                   AND fs.is_active = 1
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND $dayExpr"
             );
            $sched->execute([$schedId, $uid, $today, $today, $dayName]);
            $s = $sched->fetch();
            if (!$s) {
                echo json_encode(['success' => false, 'message' => 'No matching active schedule found for today.']);
                exit;
            }

            $startTs     = strtotime($today . ' ' . $s['start_time']);
            $windowOpen  = date('H:i:s', $startTs - CHECKIN_BEFORE_MIN * 60);
            $windowClose = date('H:i:s', $startTs + CHECKIN_AFTER_MIN * 60);

            if ($nowTime < $windowOpen) {
                echo json_encode(['success' => false, 'message' => 'Check-in window has not opened yet. Opens at ' . substr($windowOpen, 0, 5) . '.']);
                exit;
            }
            if ($nowTime > $windowClose) {
                echo json_encode(['success' => false, 'message' => 'Check-in window has closed. You have been marked Absent for this session.']);
                exit;
            }

            $dup = $db->prepare(
                "SELECT attendance_id FROM schedule_attendance
                 WHERE schedule_id = ? AND attendance_date = ? AND status = 'Present'"
            );
            $dup->execute([$schedId, $today]);
            if ($dup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You have already checked in for this session.']);
                exit;
            }

            $db->prepare(
                'INSERT INTO schedule_attendance
                 (schedule_id, faculty_id, attendance_date, status, checked_in_at, marked_by_system)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, 0)
                 ON CONFLICT (schedule_id, attendance_date) DO UPDATE
                 SET status = EXCLUDED.status,
                     checked_in_at = EXCLUDED.checked_in_at,
                     marked_by_system = EXCLUDED.marked_by_system'
            )->execute([$schedId, $uid, $today, 'Present']);

            auditLog($db, $uid, 'Other', 'Assignment', $schedId,
                "Faculty {$uid} checked in for schedule #{$schedId} ({$s['class_name']}) on {$today}.", $ip, $ua);

            echo json_encode(['success' => true, 'message' => 'Check-in recorded. You are marked Present for ' . $s['class_name'] . '.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    error_log('[COMLAB Scheduling API] ' . $e->getMessage());
}

function auditLog(PDO $db, ?int $uid, string $action, ?string $tt, ?int $tid, string $desc,
                  ?string $ip = null, ?string $ua = null): void {
    try {
        $db->prepare(
            'INSERT INTO audit_logs(user_id, action_type, target_type, target_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uid, $action, $tt, $tid, $desc, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('[AuditLog] ' . $e->getMessage());
    }
}
