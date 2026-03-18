<?php
// COMLAB - Inventory API
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');
requireAuth('inventory');

try {
    $db = getDB();

    $summary = $db->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN status = 'Under Repair' THEN 1 ELSE 0 END) AS under_repair,
                SUM(CASE WHEN status = 'Damaged' THEN 1 ELSE 0 END) AS damaged,
                SUM(CASE WHEN status = 'Retired' THEN 1 ELSE 0 END) AS retired
         FROM devices"
    )->fetch();

    $byLab = $db->query(
        "SELECT l.location_id, l.lab_name, l.lab_code, l.capacity,
                COUNT(d.device_id) AS total,
                SUM(CASE WHEN d.status = 'Available' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN d.status = 'Under Repair' THEN 1 ELSE 0 END) AS under_repair,
                SUM(CASE WHEN d.status = 'Damaged' THEN 1 ELSE 0 END) AS damaged,
                SUM(CASE WHEN d.status = 'Retired' THEN 1 ELSE 0 END) AS retired
         FROM locations l
         LEFT JOIN devices d ON l.location_id = d.location_id
         WHERE l.is_active = 1
         GROUP BY l.location_id
         ORDER BY l.lab_code"
    )->fetchAll();

    $byType = $db->query(
        "SELECT d.location_id, d.device_type,
                COUNT(*) AS total,
                SUM(CASE WHEN d.status = 'Available' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN d.status = 'Under Repair' THEN 1 ELSE 0 END) AS under_repair,
                SUM(CASE WHEN d.status = 'Damaged' THEN 1 ELSE 0 END) AS damaged,
                SUM(CASE WHEN d.status = 'Retired' THEN 1 ELSE 0 END) AS retired
         FROM devices d
         GROUP BY d.location_id, d.device_type"
    )->fetchAll();

    $typeMap = [];
    foreach ($byType as $bt) {
        $typeMap[$bt['location_id']][] = $bt;
    }
    foreach ($byLab as &$lab) {
        $lab['by_type'] = $typeMap[$lab['location_id']] ?? [];
    }
    unset($lab);

    $attention = $db->query(
        "SELECT d.device_code, d.device_type, d.brand, d.model, d.status, d.updated_at, l.lab_name
         FROM devices d
         LEFT JOIN locations l ON d.location_id = l.location_id
         WHERE d.status IN ('Under Repair', 'Damaged')
         ORDER BY d.status, d.device_code"
    )->fetchAll();

    echo json_encode(['success' => true, 'summary' => $summary, 'by_lab' => $byLab, 'attention' => $attention]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
