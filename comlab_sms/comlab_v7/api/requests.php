<?php
// COMLAB - Requests API
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');

$user = requireAuth('requests');
$role = $user['role'];
$uid  = $user['user_id'];
$ip   = $_SERVER['REMOTE_ADDR'] ?? null;
$ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['export'])) {
            exportCsv($db, $role, $uid);
            exit;
        }

        $where  = ($role === ROLE_FACULTY) ? 'WHERE r.submitted_by = ?' : '';
        $params = ($role === ROLE_FACULTY) ? [$uid] : [];
        $statusOrder = sqlOrderCase('r.status', ['Pending', 'Approved', 'Completed', 'Rejected']);

        $requests = $db->prepare(
            "SELECT r.request_id, r.request_type, r.department, r.status,
                    r.issue_description, r.date_needed,
                    r.location_text,
                    r.device_type_needed, r.specifications_needed, r.quantity, r.justification,
                    r.rejection_reason, r.reviewed_at, r.created_at,
                    CONCAT(us.first_name, ' ', us.last_name) AS submitted_by_name,
                    CONCAT(ur.first_name, ' ', ur.last_name) AS reviewed_by_name,
                    d.device_code, d.brand, d.model,
                    l.lab_name, l.lab_code
             FROM requests r
             JOIN users us ON r.submitted_by = us.user_id
             LEFT JOIN users ur ON r.reviewed_by = ur.user_id
             LEFT JOIN devices d ON r.device_id = d.device_id
             LEFT JOIN locations l ON r.location_id = l.location_id
             $where
             ORDER BY {$statusOrder}, r.created_at DESC"
        );
        $requests->execute($params);
        $rows = $requests->fetchAll();

        $statsQ = ($role === ROLE_FACULTY)
            ? $db->prepare(
                "SELECT
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected,
                    COUNT(*) AS total
                 FROM requests
                 WHERE submitted_by = ?"
            )
            : $db->prepare(
                "SELECT
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected,
                    COUNT(*) AS total
                 FROM requests"
            );
        $statsQ->execute($role === ROLE_FACULTY ? [$uid] : []);
        $stats = $statsQ->fetch();

        echo json_encode(['success' => true, 'requests' => $rows, 'stats' => $stats]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }

        $action = $_POST['action'] ?? 'submit';

        if ($action === 'submit') {
            $type = $_POST['request_type'] ?? '';
            $dept = trim($_POST['department'] ?? '');

            if (!in_array($type, ['Maintenance', 'Unit'], true) || !$dept) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
                exit;
            }

            if ($type === 'Maintenance') {
                $locationText = trim($_POST['location_text'] ?? '');
                $issue        = trim($_POST['issue_description'] ?? '');
                $dateNeeded   = $_POST['date_needed'] ?: null;

                if (!$issue) {
                    echo json_encode(['success' => false, 'message' => 'Issue description is required.']);
                    exit;
                }
                if (!$locationText) {
                    echo json_encode(['success' => false, 'message' => 'Please specify the location of the issue.']);
                    exit;
                }
                if ($dateNeeded && $dateNeeded < date('Y-m-d')) {
                    echo json_encode(['success' => false, 'message' => 'Date needed cannot be in the past.']);
                    exit;
                }

                $newId = insertReturningId(
                    $db,
                    'INSERT INTO requests
                     (request_type, submitted_by, department, location_text, issue_description, date_needed, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$type, $uid, $dept, $locationText, $issue, $dateNeeded, 'Pending'],
                    'request_id'
                );
            } else {
                $locationText  = trim($_POST['location_text'] ?? '');
                $devTypeNeeded = $_POST['device_type_needed'] ?? '';
                $qty           = max(1, (int) ($_POST['quantity'] ?? 1));
                $specs         = trim($_POST['specifications_needed'] ?? '');
                $justification = trim($_POST['justification'] ?? '');

                if (!$devTypeNeeded || !$justification) {
                    echo json_encode(['success' => false, 'message' => 'Device type and justification are required.']);
                    exit;
                }

                $newId = insertReturningId(
                    $db,
                    'INSERT INTO requests
                     (request_type, submitted_by, department, location_text, device_type_needed, specifications_needed, quantity, justification, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$type, $uid, $dept, $locationText, $devTypeNeeded, $specs, $qty, $justification, 'Pending'],
                    'request_id'
                );
            }

            auditLog($db, $uid, 'Request Submitted', 'Request', (int) $newId, "$type request #{$newId} submitted by user {$uid}.", $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'Request submitted.', 'request_id' => $newId]);
            exit;
        }

        if ($action === 'review') {
            if ($role === ROLE_FACULTY) {
                echo json_encode(['success' => false, 'message' => 'Faculty cannot review requests.']);
                exit;
            }

            $requestId = (int) ($_POST['request_id'] ?? 0);
            $decision  = $_POST['decision'] ?? '';
            $notes     = trim($_POST['rejection_reason'] ?? '');

            if (!$requestId || !in_array($decision, ['Approved', 'Rejected'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid request or decision.']);
                exit;
            }
            if ($decision === 'Rejected' && !$notes) {
                echo json_encode(['success' => false, 'message' => 'Rejection reason is required.']);
                exit;
            }

            $req = $db->prepare(
                'SELECT request_id, request_type, device_id, status, submitted_by FROM requests WHERE request_id = ?'
            );
            $req->execute([$requestId]);
            $r = $req->fetch();
            if (!$r) {
                echo json_encode(['success' => false, 'message' => 'Request not found.']);
                exit;
            }
            if ($r['status'] !== 'Pending') {
                echo json_encode(['success' => false, 'message' => 'Request is no longer pending.']);
                exit;
            }

            if ((int) $r['submitted_by'] === (int) $uid) {
                echo json_encode(['success' => false, 'message' => 'You cannot review a request you submitted yourself.']);
                exit;
            }

            $db->prepare(
                'UPDATE requests
                 SET status = ?, reviewed_by = ?, rejection_reason = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE request_id = ?'
            )->execute([$decision, $uid, $notes ?: null, $requestId]);

            if ($decision === 'Approved' && $r['request_type'] === 'Maintenance' && $r['device_id']) {
                $db->prepare(
                    "UPDATE devices SET status = 'Under Repair', updated_at = CURRENT_TIMESTAMP WHERE device_id = ?"
                )->execute([$r['device_id']]);
                auditLog($db, $uid, 'Device Status Changed', 'Device', $r['device_id'], "Device marked Under Repair on maintenance request approval. Request #{$requestId}.", $ip, $ua);
            }

            $auditAction = ($decision === 'Approved') ? 'Request Approved' : 'Request Rejected';
            auditLog($db, $uid, $auditAction, 'Request', $requestId, "Request #{$requestId} {$decision} by reviewer {$uid}." . ($notes ? " Reason: $notes" : ''), $ip, $ua);

            echo json_encode(['success' => true, 'message' => "Request {$decision}."]);
            exit;
        }
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    error_log('[COMLAB Requests API] ' . $e->getMessage());
}

function auditLog(PDO $db, ?int $uid, string $action, ?string $tt, ?int $tid, string $desc, ?string $ip = null, ?string $ua = null): void {
    $auditTable = comlabAuditLogTable();
    try {
        $db->prepare(
            "INSERT INTO {$auditTable}(user_id, action_type, target_type, target_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$uid, $action, $tt, $tid, $desc, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('[AuditLog] ' . $e->getMessage());
    }
}

function exportCsv(PDO $db, string $role, int $uid): void {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="requests_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Type', 'Submitted By', 'Department', 'Device/Item', 'Description', 'Date Needed', 'Status', 'Reviewed By', 'Created']);
    $where  = ($role === ROLE_FACULTY) ? 'WHERE r.submitted_by = ?' : '';
    $params = ($role === ROLE_FACULTY) ? [$uid] : [];
    $stmt = $db->prepare(
        "SELECT r.request_id, r.request_type, CONCAT(us.first_name, ' ', us.last_name), r.department,
         COALESCE(d.device_code, r.device_type_needed, '-') AS device_or_item,
         COALESCE(r.issue_description, r.justification, '-') AS description,
         r.date_needed, r.status,
         COALESCE(CONCAT(ur.first_name, ' ', ur.last_name), '-') AS reviewed_by_name,
         r.created_at
         FROM requests r
         JOIN users us ON r.submitted_by = us.user_id
         LEFT JOIN users ur ON r.reviewed_by = ur.user_id
         LEFT JOIN devices d ON r.device_id = d.device_id
         $where
         ORDER BY r.created_at DESC"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
}
