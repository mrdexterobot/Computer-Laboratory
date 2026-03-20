<?php
// COMLAB - Logs API (Admin only)
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');
$user = requireAuth('logs');

try {
    $db   = getDB();
    $date = $_GET['date'] ?? '';
    $auditTable = comlabAuditLogTable();

    if (!empty($_GET['export'])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Log ID', 'Timestamp', 'Action', 'Performed By', 'Role', 'Target Type', 'Target ID', 'Description', 'IP Address']);
        $rows = $db->query(
            "SELECT al.log_id, al.created_at, al.action_type,
                    CONCAT(u.first_name, ' ', u.last_name),
                    u.role, al.target_type, al.target_id, al.description, al.ip_address
             FROM {$auditTable} al
             LEFT JOIN users u ON al.user_id = u.user_id
             ORDER BY al.created_at DESC
             LIMIT 5000"
        )->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    $where  = $date ? 'WHERE DATE(al.created_at) = ?' : '';
    $params = $date ? [$date] : [];
    $stmt = $db->prepare(
        "SELECT al.log_id, al.action_type, al.target_type, al.target_id,
                al.description, al.ip_address, al.created_at,
                CONCAT(u.first_name, ' ', u.last_name) AS performed_by, u.role
         FROM {$auditTable} al
         LEFT JOIN users u ON al.user_id = u.user_id
         $where
         ORDER BY al.created_at DESC
         LIMIT 500"
    );
    $stmt->execute($params);
    echo json_encode(['success' => true, 'logs' => $stmt->fetchAll()]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    error_log('[COMLAB Logs API] ' . $e->getMessage());
}
