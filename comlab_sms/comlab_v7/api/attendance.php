<?php
// COMLAB - Attendance API
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');

$user = requireAuth('attendance');
$role = $user['role'];
$uid  = $user['user_id'];
$ip   = $_SERVER['REMOTE_ADDR'] ?? null;
$ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

define('ABSENCE_MARK_AFTER_MIN', 30);

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'summary';

        if ($action === 'my_history') {
            $stmt = $db->prepare(
                "SELECT sa.attendance_id, sa.attendance_date, sa.status,
                        sa.checked_in_at, sa.marked_by_system,
                        fs.class_name, fs.day_of_week, fs.start_time, fs.end_time,
                        l.lab_name, l.lab_code
                 FROM schedule_attendance sa
                 JOIN faculty_schedules fs ON sa.schedule_id = fs.schedule_id
                 JOIN locations l ON fs.location_id = l.location_id
                 WHERE sa.faculty_id = ?
                 ORDER BY sa.attendance_date DESC
                 LIMIT 120"
            );
            $stmt->execute([$uid]);

            $summary = $db->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present,
                        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                        SUM(CASE WHEN status = 'Excused' THEN 1 ELSE 0 END) AS excused
                 FROM schedule_attendance
                 WHERE faculty_id = ?"
            );
            $summary->execute([$uid]);

            echo json_encode([
                'success' => true,
                'records' => $stmt->fetchAll(),
                'summary' => $summary->fetch(),
            ]);
            exit;
        }

        if ($action === 'summary') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $rows = $db->query(
                "SELECT u.user_id, CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                        u.department,
                        COUNT(DISTINCT fs.schedule_id) AS active_schedules,
                        COUNT(sa.attendance_id) AS total_sessions,
                        SUM(CASE WHEN sa.status = 'Present' THEN 1 ELSE 0 END) AS present,
                        SUM(CASE WHEN sa.status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                        SUM(CASE WHEN sa.status = 'Excused' THEN 1 ELSE 0 END) AS excused,
                        ROUND(
                          100.0 * SUM(CASE WHEN sa.status = 'Present' THEN 1 ELSE 0 END) / NULLIF(COUNT(sa.attendance_id), 0),
                        1) AS attendance_rate
                 FROM users u
                 LEFT JOIN faculty_schedules fs ON fs.faculty_id = u.user_id AND fs.is_active = 1
                 LEFT JOIN schedule_attendance sa ON sa.faculty_id = u.user_id
                 WHERE u.role = 'Faculty' AND u.is_active = 1
                 GROUP BY u.user_id
                 ORDER BY u.last_name"
            )->fetchAll();

            echo json_encode(['success' => true, 'summary' => $rows]);
            exit;
        }

        if ($action === 'daily') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $date = $_GET['date'] ?? date('Y-m-d');
            $stmt = $db->prepare(
                "SELECT sa.attendance_id, sa.attendance_date, sa.status,
                        sa.checked_in_at, sa.marked_by_system,
                        fs.class_name, fs.start_time, fs.end_time,
                        l.lab_name,
                        CONCAT(u.first_name, ' ', u.last_name) AS faculty_name
                 FROM schedule_attendance sa
                 JOIN faculty_schedules fs ON sa.schedule_id = fs.schedule_id
                 JOIN locations l ON fs.location_id = l.location_id
                 JOIN users u ON sa.faculty_id = u.user_id
                 WHERE sa.attendance_date = ?
                 ORDER BY fs.start_time, u.last_name"
            );
            $stmt->execute([$date]);
            echo json_encode(['success' => true, 'date' => $date, 'records' => $stmt->fetchAll()]);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF.']);
            exit;
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'mark_excused') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $attId = (int) ($_POST['attendance_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');

            $rec = $db->prepare('SELECT attendance_id, status FROM schedule_attendance WHERE attendance_id = ?');
            $rec->execute([$attId]);
            $r = $rec->fetch();
            if (!$r) {
                echo json_encode(['success' => false, 'message' => 'Attendance record not found.']);
                exit;
            }
            if ($r['status'] === 'Present') {
                echo json_encode(['success' => false, 'message' => 'Cannot excuse a Present record.']);
                exit;
            }

            $db->prepare(
                'UPDATE schedule_attendance
                 SET status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE attendance_id = ?'
            )->execute(['Excused', $reason, $attId]);

            auditLog($db, $uid, 'Other', 'Assignment', $attId,
                "Attendance #{$attId} marked Excused by admin {$uid}. Reason: {$reason}", $ip, $ua);

            echo json_encode(['success' => true, 'message' => 'Marked as Excused.']);
            exit;
        }

        if ($action === 'run_absence_sweep') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $today = date('Y-m-d');
            $dayName = date('l');
            $cutoffTime = date('H:i:s', time() - ABSENCE_MARK_AFTER_MIN * 60);

            $overdue = $db->prepare(
                "SELECT fs.schedule_id, fs.faculty_id, fs.start_time
                 FROM faculty_schedules fs
                 WHERE fs.is_active = 1
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND " . sqlCsvContainsDay('fs.day_of_week') . "
                   AND fs.start_time < ?
                   AND NOT EXISTS (
                     SELECT 1
                     FROM schedule_attendance sa
                     WHERE sa.schedule_id = fs.schedule_id
                       AND sa.attendance_date = ?
                   )"
            );
            $overdue->execute([$today, $today, $dayName, $cutoffTime, $today]);
            $rows = $overdue->fetchAll();

            $count = 0;
            foreach ($rows as $r) {
                $db->prepare(
                    'INSERT INTO schedule_attendance
                     (schedule_id, faculty_id, attendance_date, status, marked_by_system)
                     VALUES (?, ?, ?, ?, 1)
                     ON CONFLICT (schedule_id, attendance_date) DO NOTHING'
                )->execute([$r['schedule_id'], $r['faculty_id'], $today, 'Absent']);
                $count++;
            }

            auditLog($db, $uid, 'Other', 'System', null,
                "Absence sweep run by admin {$uid}: {$count} absent record(s) inserted for {$today}.", $ip, $ua);

            echo json_encode([
                'success' => true,
                'message' => "{$count} absence record(s) automatically inserted for {$today}.",
                'count' => $count,
            ]);
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
    error_log('[COMLAB Attendance API] ' . $e->getMessage());
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
