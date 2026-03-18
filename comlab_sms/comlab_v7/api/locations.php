<?php
// COMLAB - Locations API
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');

$user = requireAuth('locations');
$uid  = $user['user_id'];
$ip   = $_SERVER['REMOTE_ADDR'] ?? null;
$ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $todayDow = date('l');
        $locs = $db->prepare(
            "SELECT l.*,
                    COUNT(d.device_id) AS total_devices,
                    SUM(CASE WHEN d.status = 'Available' THEN 1 ELSE 0 END) AS available_devices,
                    (
                        SELECT COUNT(*)
                        FROM faculty_schedules fs
                        WHERE fs.location_id = l.location_id
                          AND fs.is_active = 1
                          AND " . sqlCsvContainsDay('fs.day_of_week') . "
                    ) AS today_schedules
             FROM locations l
             LEFT JOIN devices d ON l.location_id = d.location_id
             GROUP BY l.location_id
             ORDER BY l.lab_code"
        );
        $locs->execute([$todayDow]);
        echo json_encode(['success' => true, 'locations' => $locs->fetchAll()]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF.']);
            exit;
        }
        if ($user['role'] !== ROLE_ADMIN) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Administrators only.']);
            exit;
        }

        $action = $_POST['action'] ?? 'save';

        if ($action === 'toggle') {
            $locId = (int) ($_POST['location_id'] ?? 0);

            $cur = $db->prepare('SELECT is_active, lab_name FROM locations WHERE location_id = ?');
            $cur->execute([$locId]);
            $row = $cur->fetch();
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Lab not found.']);
                exit;
            }

            $new = $row['is_active'] ? 0 : 1;

            $db->prepare('UPDATE locations SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE location_id = ?')
               ->execute([$new, $locId]);

            $cancelledCount = 0;
            if (!$new) {
                $cancelStmt = $db->prepare(
                    'UPDATE faculty_schedules
                     SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                     WHERE location_id = ? AND is_active = 1'
                );
                $cancelStmt->execute([$locId]);
                $cancelledCount = $cancelStmt->rowCount();

                if ($cancelledCount > 0) {
                    auditLog(
                        $db,
                        $uid,
                        'Schedules Deactivated',
                        'Location',
                        $locId,
                        "Lab '{$row['lab_name']}' deactivated. {$cancelledCount} recurring schedule(s) set inactive.",
                        $ip,
                        $ua
                    );
                }
            }

            auditLog(
                $db,
                $uid,
                'Location Updated',
                'Location',
                $locId,
                "Lab '{$row['lab_name']}' " . ($new ? 'activated' : 'deactivated') . '.',
                $ip,
                $ua
            );

            $msg = 'Lab ' . ($new ? 'activated' : 'deactivated') . '.';
            if ($cancelledCount > 0) {
                $msg .= " {$cancelledCount} upcoming booking(s) were automatically cancelled.";
            }

            echo json_encode(['success' => true, 'message' => $msg, 'cancelled_bookings' => $cancelledCount]);
            exit;
        }

        $editId = (int) ($_POST['location_id'] ?? 0);
        $name   = trim($_POST['lab_name'] ?? '');
        $code   = strtoupper(trim($_POST['lab_code'] ?? ''));
        $cap    = (int) ($_POST['capacity'] ?? 0);
        $bldg   = trim($_POST['building'] ?? '');
        $floor  = trim($_POST['floor'] ?? '');
        $room   = trim($_POST['room_number'] ?? '');
        $open   = $_POST['operating_hours_start'] ?? '08:00';
        $close  = $_POST['operating_hours_end'] ?? '18:00';
        $desc   = trim($_POST['description'] ?? '');

        if (!$name || !$code || !$cap) {
            echo json_encode(['success' => false, 'message' => 'Name, code and capacity are required.']);
            exit;
        }
        if ($cap <= 0) {
            echo json_encode(['success' => false, 'message' => 'Capacity must be greater than zero.']);
            exit;
        }
        if ($open >= $close) {
            echo json_encode(['success' => false, 'message' => 'Operating hours end must be after start.']);
            exit;
        }

        if ($editId) {
            $dup = $db->prepare('SELECT location_id FROM locations WHERE lab_code = ? AND location_id <> ?');
            $dup->execute([$code, $editId]);
            if ($dup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Lab code already in use.']);
                exit;
            }

            $db->prepare(
                'UPDATE locations
                 SET lab_name = ?, lab_code = ?, building = ?, floor = ?, room_number = ?, capacity = ?,
                     operating_hours_start = ?, operating_hours_end = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE location_id = ?'
            )->execute([$name, $code, $bldg, $floor, $room, $cap, $open, $close, $desc, $editId]);

            auditLog($db, $uid, 'Location Updated', 'Location', $editId, "Lab '$name' updated.", $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'Lab updated.']);
            exit;
        }

        $dup = $db->prepare('SELECT location_id FROM locations WHERE lab_code = ?');
        $dup->execute([$code]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Lab code already exists.']);
            exit;
        }

        $newId = insertReturningId(
            $db,
            'INSERT INTO locations
             (lab_name, lab_code, building, floor, room_number, capacity,
              operating_hours_start, operating_hours_end, description, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [$name, $code, $bldg, $floor, $room, $cap, $open, $close, $desc],
            'location_id'
        );

        auditLog($db, $uid, 'Location Added', 'Location', (int) $newId, "New lab '$name' ($code) added.", $ip, $ua);
        echo json_encode(['success' => true, 'message' => 'Lab added.', 'location_id' => $newId]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    error_log('[COMLAB Locations API] ' . $e->getMessage());
}

function auditLog(PDO $db, ?int $uid, string $action, ?string $tt, ?int $tid, string $desc, ?string $ip = null, ?string $ua = null): void {
    try {
        $db->prepare(
            'INSERT INTO audit_logs(user_id, action_type, target_type, target_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uid, $action, $tt, $tid, $desc, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('[AuditLog] ' . $e->getMessage());
    }
}
