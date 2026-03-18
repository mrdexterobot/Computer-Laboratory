<?php
// COMLAB - Dashboard API (Supabase/Postgres ready)
// Uses faculty_schedules + schedule_attendance
// Response keys: success, role, stats, today_schedules, pending_requests

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');

$user = requireAuth('dashboard');
$role = $user['role'];
$uid  = (int) $user['user_id'];

try {
    $db = getDB();

    $todayDow  = date('l');
    $todayDate = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('-7 days'));

    $extra = ($role === ROLE_FACULTY) ? 'AND fs.faculty_id = ?' : '';
    $params = array_merge([$todayDate, $todayDow], ($role === ROLE_FACULTY ? [$uid] : []));

    $stmt = $db->prepare(
        "SELECT fs.schedule_id, fs.class_name,
                fs.start_time, fs.end_time,
                l.lab_name, l.lab_code,
                CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                COALESCE(sa.status, 'Scheduled') AS status
         FROM faculty_schedules fs
         JOIN locations l ON fs.location_id = l.location_id
         JOIN users u ON fs.faculty_id = u.user_id
         LEFT JOIN schedule_attendance sa
                ON sa.schedule_id = fs.schedule_id AND sa.attendance_date = ?
         WHERE " . sqlCsvContainsDay('fs.day_of_week') . "
           AND fs.is_active = 1
         $extra
         ORDER BY fs.start_time"
    );
    $stmt->execute($params);
    $todaySchedules = $stmt->fetchAll();

    if ($role === ROLE_ADMIN) {
        $stats = $db->query(
            "SELECT
               (SELECT COUNT(*) FROM users WHERE is_active = 1) AS total_users,
               (SELECT COUNT(*) FROM users WHERE role = 'Faculty') AS total_faculty,
               (SELECT COUNT(*) FROM devices WHERE status = 'Available') AS devices_available,
               (SELECT COUNT(*) FROM requests WHERE status = 'Pending') AS pending_requests,
               (SELECT COUNT(*) FROM faculty_schedules WHERE is_active = 1) AS active_schedules,
               (SELECT COUNT(*) FROM locations WHERE is_active = 1) AS total_labs"
        )->fetch();

        $stmt2 = $db->query(
            "SELECT r.request_id, r.request_type,
                    CONCAT(u.first_name, ' ', u.last_name) AS submitted_by_name,
                    r.issue_description, r.created_at
             FROM requests r
             JOIN users u ON r.submitted_by = u.user_id
             WHERE r.status = 'Pending'
             ORDER BY r.created_at ASC
             LIMIT 8"
        );
        $pendingReqs = $stmt2->fetchAll();
    } else {
        $s = $db->prepare(
            "SELECT
               (SELECT COUNT(*) FROM faculty_schedules WHERE faculty_id = ? AND is_active = 1) AS active_schedules,
               (SELECT COUNT(*) FROM requests WHERE submitted_by = ? AND status = 'Pending') AS my_pending_requests,
               (SELECT COUNT(*)
                FROM schedule_attendance
                WHERE status = 'Present'
                  AND attendance_date >= ?
                  AND schedule_id IN (SELECT schedule_id FROM faculty_schedules WHERE faculty_id = ?)
               ) AS present_this_week,
               (SELECT COUNT(*) FROM devices WHERE status = 'Available') AS devices_available"
        );
        $s->execute([$uid, $uid, $weekStart, $uid]);
        $stats = $s->fetch();
        $pendingReqs = [];
    }

    echo json_encode([
        'success'          => true,
        'role'             => $role,
        'stats'            => $stats,
        'today_schedules'  => $todaySchedules,
        'pending_requests' => $pendingReqs,
    ]);
} catch (Throwable $e) {
    error_log('[Dashboard API] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
