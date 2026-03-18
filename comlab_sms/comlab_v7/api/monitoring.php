<?php
// COMLAB - Monitoring API
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');
requireAuth('monitoring');

try {
    $db        = getDB();
    $todayDow  = date('l');
    $todayDate = date('Y-m-d');

    $labs = $db->prepare(
        "SELECT l.location_id, l.lab_name, l.lab_code, l.capacity,
                l.building, l.floor, l.room_number,
                l.is_active, l.operating_hours_start, l.operating_hours_end,
                COUNT(CASE WHEN d.status = 'Available' THEN 1 END) AS devices_available,
                COUNT(CASE WHEN d.status = 'Under Repair' THEN 1 END) AS devices_repair,
                COUNT(d.device_id) AS device_count
         FROM locations l
         LEFT JOIN devices d ON l.location_id = d.location_id
         WHERE l.is_active = 1
         GROUP BY l.location_id
         ORDER BY l.lab_code"
    );
    $labs->execute();
    $labs = $labs->fetchAll();

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
         ORDER BY fs.start_time"
    );
    $stmt->execute([$todayDate, $todayDow]);
    $todaySchedules = $stmt->fetchAll();

    $deviceIssues = $db->query(
        "SELECT d.device_code, d.device_type, d.brand, d.model, d.status, l.lab_name
         FROM devices d
         LEFT JOIN locations l ON d.location_id = l.location_id
         WHERE d.status IN ('Under Repair', 'Damaged')
         ORDER BY d.status, d.device_code"
    )->fetchAll();

    echo json_encode([
        'success' => true,
        'labs' => $labs,
        'today_schedules' => $todaySchedules,
        'device_issues' => $deviceIssues,
    ]);
} catch (Throwable $e) {
    error_log('[Monitoring API] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
