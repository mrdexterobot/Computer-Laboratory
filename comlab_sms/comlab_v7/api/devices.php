<?php
// COMLAB - Devices API
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$user = requireAuth('devices');
$role = $user['role'];
$uid  = $user['user_id'];
$ip   = $_SERVER['REMOTE_ADDR'] ?? null;
$ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['export'])) {
            exportCsv($db);
            exit;
        }

        $devices = $db->query(
            "SELECT d.device_id, d.device_code, d.device_type, d.brand, d.model,
                    d.serial_number, d.specifications, d.purchase_date, d.warranty_expiry,
                    d.status, d.location_id, d.notes, d.updated_at,
                    l.lab_name, l.lab_code
             FROM devices d
             LEFT JOIN locations l ON d.location_id = l.location_id
             ORDER BY d.device_code"
        )->fetchAll();

        $stats = $db->query(
            "SELECT COUNT(*) AS total,
              SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available,
              SUM(CASE WHEN status = 'In Use' THEN 1 ELSE 0 END) AS in_use,
              SUM(CASE WHEN status = 'Under Repair' THEN 1 ELSE 0 END) AS under_repair,
              SUM(CASE WHEN status = 'Damaged' THEN 1 ELSE 0 END) AS damaged,
              SUM(CASE WHEN status = 'Retired' THEN 1 ELSE 0 END) AS retired
             FROM devices"
        )->fetch();

        echo json_encode(['success' => true, 'devices' => $devices, 'stats' => $stats]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }

        if ($role === ROLE_FACULTY) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
            exit;
        }

        $action = $_POST['action'] ?? 'save';

        if ($action === 'update_status') {
            $deviceId  = (int) ($_POST['device_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';
            $notes     = trim($_POST['notes'] ?? '');

            $validStatuses = ['Available', 'In Use', 'Under Repair', 'Damaged', 'Retired'];
            if (!$deviceId || !in_array($newStatus, $validStatuses, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid device or status.']);
                exit;
            }

            $old = $db->prepare('SELECT status, device_code FROM devices WHERE device_id = ?');
            $old->execute([$deviceId]);
            $oldDevice = $old->fetch();
            if (!$oldDevice) {
                echo json_encode(['success' => false, 'message' => 'Device not found.']);
                exit;
            }

            $db->prepare('UPDATE devices SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE device_id = ?')
               ->execute([$newStatus, $deviceId]);

            auditLog(
                $db,
                $uid,
                'Device Status Changed',
                'Device',
                $deviceId,
                "Device {$oldDevice['device_code']} status changed from '{$oldDevice['status']}' to '$newStatus'" . ($notes ? ": $notes" : ''),
                $oldDevice['status'],
                $newStatus,
                $ip,
                $ua
            );

            echo json_encode(['success' => true, 'message' => 'Status updated.']);
            exit;
        }

        if ($action === 'delete') {
            if ($role !== ROLE_ADMIN) {
                echo json_encode(['success' => false, 'message' => 'Only administrators can delete devices.']);
                exit;
            }

            $deviceId = (int) ($_POST['device_id'] ?? 0);
            $dev = $db->prepare('SELECT device_code FROM devices WHERE device_id = ?');
            $dev->execute([$deviceId]);
            $d = $dev->fetch();
            if (!$d) {
                echo json_encode(['success' => false, 'message' => 'Device not found.']);
                exit;
            }

            $db->prepare('DELETE FROM devices WHERE device_id = ?')->execute([$deviceId]);
            auditLog($db, $uid, 'Device Deleted', 'Device', $deviceId, "Device {$d['device_code']} deleted by admin.", null, null, $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'Device deleted.']);
            exit;
        }

        $deviceId   = (int) ($_POST['device_id'] ?? 0);
        $deviceCode = trim($_POST['device_code'] ?? '');
        $deviceType = $_POST['device_type'] ?? '';
        $brand      = trim($_POST['brand'] ?? '');
        $model      = trim($_POST['model'] ?? '');
        $serial     = trim($_POST['serial_number'] ?? '');
        $purchDate  = $_POST['purchase_date'] ?: null;
        $locationId = (int) ($_POST['location_id'] ?? 0) ?: null;
        $status     = $_POST['status'] ?? 'Available';
        $specs      = trim($_POST['specifications'] ?? '');

        $validTypes = ['Desktop', 'Laptop', 'Monitor', 'Keyboard', 'Mouse', 'Printer', 'Other'];
        if (!$deviceCode) {
            echo json_encode(['success' => false, 'message' => 'Device code is required.']);
            exit;
        }
        if (!in_array($deviceType, $validTypes, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid device type.']);
            exit;
        }

        if ($locationId) {
            $labCheck = $db->prepare('SELECT is_active FROM locations WHERE location_id = ?');
            $labCheck->execute([$locationId]);
            $labRow = $labCheck->fetch();
            if (!$labRow) {
                echo json_encode(['success' => false, 'message' => 'Selected lab does not exist.']);
                exit;
            }
            if (!$labRow['is_active']) {
                echo json_encode(['success' => false, 'message' => 'Cannot assign a device to an inactive lab. Activate the lab first.']);
                exit;
            }
        }

        if ($deviceId) {
            $dup = $db->prepare('SELECT device_id FROM devices WHERE device_code = ? AND device_id <> ?');
            $dup->execute([$deviceCode, $deviceId]);
            if ($dup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Device code already in use.']);
                exit;
            }

            $db->prepare(
                'UPDATE devices
                 SET device_code = ?, device_type = ?, brand = ?, model = ?, serial_number = ?,
                     purchase_date = ?, location_id = ?, status = ?, specifications = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE device_id = ?'
            )->execute([$deviceCode, $deviceType, $brand, $model, $serial, $purchDate, $locationId, $status, $specs, $deviceId]);

            auditLog($db, $uid, 'Device Updated', 'Device', $deviceId, "Device $deviceCode updated.", null, null, $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'Device updated.']);
            exit;
        }

        $dup = $db->prepare('SELECT device_id FROM devices WHERE device_code = ?');
        $dup->execute([$deviceCode]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Device code already exists.']);
            exit;
        }

        $newId = insertReturningId(
            $db,
            'INSERT INTO devices
             (device_code, device_type, brand, model, serial_number, purchase_date, location_id, status, specifications)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$deviceCode, $deviceType, $brand, $model, $serial, $purchDate, $locationId, $status, $specs],
            'device_id'
        );

        auditLog($db, $uid, 'Device Added', 'Device', (int) $newId, "New device $deviceCode added.", null, null, $ip, $ua);
        echo json_encode(['success' => true, 'message' => 'Device added.', 'device_id' => $newId]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    error_log('[COMLAB Devices API] ' . $e->getMessage());
}

function auditLog(PDO $db, ?int $uid, string $action, ?string $targetType, ?int $targetId, string $desc,
                  ?string $oldVal = null, ?string $newVal = null, ?string $ip = null, ?string $ua = null): void {
    $auditTable = comlabAuditLogTable();
    try {
        $db->prepare(
            "INSERT INTO {$auditTable}(user_id, action_type, target_type, target_id, description, old_value, new_value, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$uid, $action, $targetType, $targetId, $desc, $oldVal, $newVal, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('[COMLAB AuditLog] ' . $e->getMessage());
    }
}

function exportCsv(PDO $db): void {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="devices_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Device Code', 'Type', 'Brand', 'Model', 'Serial No', 'Status', 'Location', 'Purchase Date', 'Updated']);
    $rows = $db->query(
        "SELECT d.device_code, d.device_type, d.brand, d.model, d.serial_number, d.status,
         COALESCE(l.lab_name, '-') AS lab_name, d.purchase_date, d.updated_at
         FROM devices d
         LEFT JOIN locations l ON d.location_id = l.location_id
         ORDER BY d.device_code"
    )->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, array_values($r));
    }
    fclose($out);
}
