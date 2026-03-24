<?php
/**
 * COMLAB → HR  –  Staff Request Integration API
 *
 * Writes directly into public.hr_staff_requests (Supabase / PostgreSQL)
 * using the same PDO connection already configured in config/db.php.
 *
 * Routes
 *   GET    – list requests  (paginated, filterable by status)
 *   POST   – submit a new staff request
 */

require_once __DIR__ . '/../_helpers.php';

integrationBootstrap();

$user    = requireAuth('integration');
$isAdmin = ($user['role'] === ROLE_ADMIN);
$db      = getDB();

$method = integrationMethod();

// ── GET ────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $statusFilter = trim($_GET['status'] ?? '');
    $page         = max(1, (int) ($_GET['page']    ?? 1));
    $perPage      = min(50, max(1, (int) ($_GET['per_page'] ?? 10)));
    $offset       = ($page - 1) * $perPage;

    $conditions = [];
    $bindings   = [];

    if ($statusFilter && $statusFilter !== 'all') {
        $conditions[] = 'r.request_status = ?';
        $bindings[]   = $statusFilter;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    try {
        $countStmt = $db->prepare(
            "SELECT COUNT(*) AS total
               FROM public.hr_staff_requests r
               $where"
        );
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $dataStmt = $db->prepare(
            "SELECT r.id, r.request_reference, r.staff_id, r.request_status,
                    r.request_notes, r.requested_by, r.decided_by, r.decided_at,
                    r.created_at, r.updated_at,
                    d.employee_no, d.full_name AS staff_name, d.role_type, d.department_name
               FROM public.hr_staff_requests r
               LEFT JOIN public.hr_staff_directory d ON d.id = r.staff_id
               $where
              ORDER BY r.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $dataStmt->execute(array_merge($bindings, [$perPage, $offset]));
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        integrationJson([
            'success' => true,
            'items'   => $rows,
            'meta'    => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    } catch (Throwable $e) {
        integrationJson(['success' => false, 'message' => 'Failed to load requests: ' . $e->getMessage()], 500);
    }
}

// ── POST ───────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!$isAdmin) {
        integrationJson(['success' => false, 'message' => 'Only admins can submit staff requests.'], 403);
    }

    $input        = integrationInput();
    $roleType     = trim($input['role_type']      ?? '');
    $requestCount = max(1, (int) ($input['requested_count'] ?? 1));
    $requestedBy  = trim($input['requested_by']   ?? 'ComLab Admin');
    $notes        = trim($input['request_notes']  ?? '');

    $allowedRoles = ['lab_technician', 'it_staff'];
    if (!in_array($roleType, $allowedRoles, true)) {
        integrationJson(['success' => false, 'message' => 'Invalid role_type. Allowed: ' . implode(', ', $allowedRoles)], 400);
    }

    try {
        // Upsert a pool placeholder in public.hr_staff_directory
        $poolKey  = 'HR-REQ-POOL-' . strtoupper($roleType);
        $poolName = ucwords(str_replace('_', ' ', $roleType));

        $db->prepare(
            "INSERT INTO public.hr_staff_directory
                (employee_no, full_name, role_type, department_name, employment_status)
             VALUES (?, ?, ?, 'Computer Laboratory', 'inactive')
             ON CONFLICT (employee_no) DO NOTHING"
        )->execute([$poolKey, "Open {$poolName} Hiring Request", $roleType]);

        $staffStmt = $db->prepare(
            "SELECT id FROM public.hr_staff_directory WHERE employee_no = ? LIMIT 1"
        );
        $staffStmt->execute([$poolKey]);
        $staffId = $staffStmt->fetchColumn();

        if (!$staffId) {
            integrationJson(['success' => false, 'message' => 'Failed to resolve staff placeholder.'], 500);
        }

        // Compose notes
        $fullNotes = $notes
            ? "{$notes} | Requested count: {$requestCount}"
            : "Requested count: {$requestCount}";

        // Generate reference
        $ref = 'HR-REQ-' . date('Y') . '-' . mt_rand(10000, 99999);

        $db->prepare(
            "INSERT INTO public.hr_staff_requests
                (request_reference, staff_id, request_status, request_notes, requested_by)
             VALUES (?, ?, 'pending', ?, ?)"
        )->execute([$ref, $staffId, $fullNotes, $requestedBy]);

        integrationJson([
            'success'           => true,
            'message'           => 'Staff request submitted to HR.',
            'request_reference' => $ref,
        ]);
    } catch (Throwable $e) {
        integrationJson(['success' => false, 'message' => 'Failed to submit request: ' . $e->getMessage()], 500);
    }
}

integrationJson(['success' => false, 'message' => 'Method not allowed.'], 405);
