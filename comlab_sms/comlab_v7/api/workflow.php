<?php
// COMLAB - Workflow Integration API
// Handles CRAD Unit requests, PMED verification/approval, and HR employee sync

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');

// For HR Sync, we might need to bypass normal auth if it's a system-to-system call
// But for now, we'll assume it's triggered by an Admin or via a shared token
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'status';

        if ($action === 'status') {
            // GET /unit-request/status
            $user = requireAuth('requests');
            $uid = $user['user_id'];
            $role = $user['role'];

            $where = ($role === ROLE_FACULTY) ? 'WHERE submitted_by = ?' : '';
            $params = ($role === ROLE_FACULTY) ? [$uid] : [];

            $stmt = $db->prepare("SELECT request_id, request_type, status, pmed_status, crad_ref, created_at FROM requests $where ORDER BY created_at DESC");
            $stmt->execute($params);
            echo json_encode(['success' => true, 'requests' => $stmt->fetchAll()]);
            exit;
        }

        if ($action === 'sync_employees') {
            // GET /hr/employees (sync)
            $user = requireAuth('integration');

            // Pull from HR's integration-ready employee directory view.
            $stmt = $db->prepare("
                SELECT
                    employee_id::text AS employee_id,
                    COALESCE(employee_number::text, employee_id::text) AS employee_key,
                    employee_number::text AS employee_number,
                    first_name,
                    last_name,
                    employee_name,
                    email,
                    phone,
                    department_name,
                    department_code,
                    position_title,
                    employment_status,
                    primary_app_role,
                    integration_status
                FROM public.employee_directory
                WHERE integration_ready = true
                  AND employment_status IN ('active', 'probation', 'on_leave')
                ORDER BY last_name, first_name, employee_number
            ");
            $stmt->execute();
            $employees = $stmt->fetchAll();

            $syncedCount = 0;
            $syncedPreview = [];
            foreach ($employees as $emp) {
                $employeeKey = (string) ($emp['employee_key'] ?? '');
                if ($employeeKey === '') {
                    continue;
                }

                // Build deterministic unique username from name + employee key.
                $baseUsername = strtolower(trim(($emp['first_name'] ?? '') . '.' . ($emp['last_name'] ?? '')));
                $baseUsername = preg_replace('/[^a-z0-9.]/', '', $baseUsername);
                if ($baseUsername === '') {
                    $baseUsername = 'employee';
                }
                $username = $baseUsername . '.' . substr(md5($employeeKey), 0, 6);

                $stmt = $db->prepare("
                    INSERT INTO users (username, email, password_hash, first_name, last_name, role, hr_employee_id, synced_from_hr, department, contact_number, is_active) 
                    VALUES (?, ?, 'synced', ?, ?, 'Faculty', ?, 1, ?, ?, 1)
                    ON CONFLICT (hr_employee_id) DO UPDATE SET 
                    first_name = EXCLUDED.first_name, 
                    last_name = EXCLUDED.last_name, 
                    email = EXCLUDED.email, 
                    department = EXCLUDED.department,
                    contact_number = EXCLUDED.contact_number,
                    updated_at = NOW()
                ");
                $stmt->execute([
                    $username, 
                    $emp['email'] ?: null,
                    $emp['first_name'], 
                    $emp['last_name'], 
                    $employeeKey,
                    $emp['department_name'],
                    $emp['phone']
                ]);
                $syncedCount++;

                if (count($syncedPreview) < 50) {
                    $syncedPreview[] = [
                        'employee_id' => $emp['employee_id'],
                        'employee_number' => $emp['employee_number'],
                        'employee_name' => $emp['employee_name'],
                        'first_name' => $emp['first_name'],
                        'last_name' => $emp['last_name'],
                        'email' => $emp['email'],
                        'phone' => $emp['phone'],
                        'department_name' => $emp['department_name'],
                        'department_code' => $emp['department_code'],
                        'position_title' => $emp['position_title'],
                        'employment_status' => $emp['employment_status'],
                        'primary_app_role' => $emp['primary_app_role'],
                        'integration_status' => $emp['integration_status'],
                    ];
                }
            }

            auditLog($db, $user['user_id'], 'HR Sync Completed', 'System', null, "Employee sync completed. Synced $syncedCount employees.", $ip, $ua);

            echo json_encode([
                'success' => true,
                'message' => "Successfully synced $syncedCount employees from HR.",
                'employee_columns' => [
                    'employee_id', 'employee_number', 'employee_name', 'first_name', 'last_name',
                    'email', 'phone', 'department_name', 'department_code', 'position_title',
                    'employment_status', 'primary_app_role', 'integration_status',
                ],
                'total_from_hr' => count($employees),
                'synced_to_comlab_users' => $syncedCount,
                'employees_preview' => $syncedPreview,
            ]);
            exit;
        }
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = $input['action'] ?? '';

        // 1. CRAD → Send unit request to COMLAB (POST /unit-request)
        if ($action === 'unit-request') {
            // This might be called by CRAD system with a token
            // For now, assume it's a manual entry or authenticated session
            $user = requireAuth('requests');
            $uid = $user['user_id'];

            $cradRef = $input['crad_ref'] ?? null;
            $desc = $input['description'] ?? 'Unit request from CRAD';
            $dept = $input['department'] ?? 'CRAD';

            $newId = insertReturningId(
                $db,
                "INSERT INTO requests (request_type, submitted_by, department, issue_description, crad_ref, status, pmed_status) 
                 VALUES ('Unit', ?, ?, ?, ?, 'Pending', 'Awaiting Forward')",
                [$uid, $dept, $desc, $cradRef],
                'request_id'
            );

            auditLog($db, $uid, 'Unit Request Created', 'Request', $newId, "Unit request #{$newId} created (CRAD Ref: {$cradRef})", $ip, $ua);
            echo json_encode(['success' => true, 'request_id' => $newId, 'message' => 'Unit request sent to COMLAB.']);
            exit;
        }

        // 2. Send request to PMED queue
        if ($action === 'forward-to-pmed' || $action === 'pmed-send') {
            $user = requireAuth('requests');
            $isPmedUser = (($user['department'] ?? '') === 'PMED');
            if ($action === 'forward-to-pmed' && $user['role'] !== ROLE_ADMIN) {
                echo json_encode(['success' => false, 'message' => 'Only Admins can forward requests to PMED.']);
                exit;
            }
            if ($action === 'pmed-send' && !$isPmedUser && $user['role'] !== ROLE_ADMIN) {
                echo json_encode(['success' => false, 'message' => 'Only PMED or Admin can send this request to PMED queue.']);
                exit;
            }

            $requestId = (int) ($input['request_id'] ?? 0);
            $stmt = $db->prepare(
                "UPDATE requests
                 SET pmed_status = 'Pending', updated_at = NOW()
                 WHERE request_id = ?
                   AND request_type = 'Unit'
                   AND status = 'Pending'
                   AND pmed_status IN ('Awaiting Forward', 'Pending')"
            );
            $stmt->execute([$requestId]);
            if ($stmt->rowCount() < 1) {
                echo json_encode(['success' => false, 'message' => 'Request is not eligible for PMED sending.']);
                exit;
            }

            $auditAction = ($action === 'pmed-send') ? 'PMED Sent' : 'Forwarded to PMED';
            auditLog($db, $user['user_id'], $auditAction, 'Request', $requestId, "Request #{$requestId} sent to PMED queue.", $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'Request sent to PMED queue.']);
            exit;
        }

        // 3. PMED → Verify request (POST /pmed/verify)
        if ($action === 'pmed-verify') {
            $user = requireAuth('requests');
            if ($user['department'] !== 'PMED' && $user['role'] !== ROLE_ADMIN) {
                echo json_encode(['success' => false, 'message' => 'Only PMED can verify requests.']);
                exit;
            }

            $requestId = (int) ($input['request_id'] ?? 0);
            $stmt = $db->prepare(
                "UPDATE requests
                 SET pmed_status = 'Verified', updated_at = NOW()
                 WHERE request_id = ?
                   AND request_type = 'Unit'
                   AND status = 'Pending'
                   AND pmed_status = 'Pending'"
            );
            $stmt->execute([$requestId]);
            if ($stmt->rowCount() < 1) {
                echo json_encode(['success' => false, 'message' => 'Only pending PMED unit requests can be verified.']);
                exit;
            }

            auditLog($db, $user['user_id'], 'PMED Verified', 'Request', $requestId, "Request #{$requestId} verified by PMED", $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'Request verified by PMED.']);
            exit;
        }

        // 4. PMED → Approve request (POST /pmed/approve)
        if ($action === 'pmed-approve') {
            $user = requireAuth('requests');
            if ($user['department'] !== 'PMED' && $user['role'] !== ROLE_ADMIN) {
                echo json_encode(['success' => false, 'message' => 'Only PMED can approve requests.']);
                exit;
            }

            $requestId = (int) ($input['request_id'] ?? 0);
            // When PMED approves, the overall status also becomes Approved
            $stmt = $db->prepare(
                "UPDATE requests
                 SET pmed_status = 'Approved', status = 'Approved', updated_at = NOW()
                 WHERE request_id = ?
                   AND request_type = 'Unit'
                   AND status = 'Pending'
                   AND pmed_status = 'Verified'"
            );
            $stmt->execute([$requestId]);
            if ($stmt->rowCount() < 1) {
                echo json_encode(['success' => false, 'message' => 'Only verified PMED unit requests can be approved.']);
                exit;
            }

            auditLog($db, $user['user_id'], 'PMED Approved', 'Request', $requestId, "Request #{$requestId} approved by PMED", $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'Request approved by PMED.']);
            exit;
        }

        // 5. COMLAB receives approved request
        if ($action === 'comlab-receive') {
            $user = requireAuth('requests');
            if ($user['role'] !== ROLE_ADMIN) {
                echo json_encode(['success' => false, 'message' => 'Only COMLAB Admin can receive approved requests.']);
                exit;
            }

            $requestId = (int) ($input['request_id'] ?? 0);
            $stmt = $db->prepare(
                "UPDATE requests
                 SET status = 'Completed', updated_at = NOW()
                 WHERE request_id = ?
                   AND request_type = 'Unit'
                   AND status = 'Approved'
                   AND pmed_status = 'Approved'"
            );
            $stmt->execute([$requestId]);
            if ($stmt->rowCount() < 1) {
                echo json_encode(['success' => false, 'message' => 'Only PMED-approved requests can be received by COMLAB.']);
                exit;
            }

            auditLog($db, $user['user_id'], 'COMLAB Received', 'Request', $requestId, "Request #{$requestId} marked as received/completed by COMLAB.", $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'Request received by COMLAB and marked completed.']);
            exit;
        }
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Action not supported.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
