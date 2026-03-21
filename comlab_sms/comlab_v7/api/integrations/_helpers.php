<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/require_auth.php';

const COMLAB_INTEGRATION_DEPARTMENT_CODE = 'COMLAB';

function integrationBootstrap(): void {
    header('Content-Type: application/json');
    securityHeaders(true);
    initSession();
}

function integrationJson(array $payload, int $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function integrationMethod(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function integrationInput(): array {
    $method = integrationMethod();
    if ($method === 'GET') {
        return $_GET;
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            integrationJson(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
        }

        return $decoded;
    }

    return $_POST;
}

function integrationSharedToken(): string {
    return trim((string) (comlabEnv('DEPARTMENT_INTEGRATION_SHARED_TOKEN', '') ?: comlabEnv('COMLAB_INTEGRATION_SHARED_TOKEN', '')));
}

function integrationProvidedToken(): string {
    return trim((string) ($_SERVER['HTTP_X_INTEGRATION_TOKEN'] ?? $_SERVER['HTTP_X_DEPARTMENT_INTEGRATION_TOKEN'] ?? ''));
}

function integrationHasValidToken(): bool {
    $expected = integrationSharedToken();
    $provided = integrationProvidedToken();
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function integrationNormalizeDepartmentCode(?string $value): string {
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/[^A-Z0-9_]/', '', $value);
    return is_string($value) ? $value : '';
}

function integrationNormalizeRecordType(?string $value): string {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9_]/', '', $value);
    return is_string($value) ? $value : '';
}

function integrationNormalizeStatus(?string $value): string {
    $value = strtolower(trim((string) $value));
    $map = [
        'draft' => 'draft',
        'sent' => 'sent',
        'received' => 'received',
        'acknowledged' => 'acknowledged',
        'archived' => 'archived',
    ];

    return $map[$value] ?? '';
}

function integrationDecodeJsonValue($value) {
    if (!is_string($value)) {
        return $value;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $decoded = json_decode($trimmed, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

function integrationParsePayloadValue($value): array {
    if ($value === null || $value === '') {
        return [];
    }

    if (is_array($value)) {
        return $value;
    }

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    integrationJson(['success' => false, 'message' => 'Payload must be a JSON object or array.'], 422);
}

function integrationAllowedSubjectTypes(): array {
    return ['student', 'staff', 'faculty', 'general', 'system'];
}

function integrationAllowedDocumentStatuses(): array {
    return ['draft', 'sent', 'received', 'acknowledged', 'archived'];
}

function integrationCurrentUser(): ?array {
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        return null;
    }

    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        destroySession();
        return null;
    }
    $_SESSION['last_activity'] = $now;

    if (!empty($_SESSION['auth_token'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare(
                'SELECT s.session_id
                 FROM user_sessions s
                 JOIN users u ON u.user_id = s.user_id
                 WHERE s.auth_token = ?
                   AND s.user_id = ?
                   AND s.is_active = 1
                   AND u.is_active = 1
                   AND s.expires_at >= CURRENT_TIMESTAMP
                 LIMIT 1'
            );
            $stmt->execute([$_SESSION['auth_token'], $_SESSION['user_id']]);
            if (!$stmt->fetch()) {
                destroySession();
                return null;
            }
        } catch (Throwable $e) {
            error_log('[COMLAB Integration Auth] ' . $e->getMessage());
            return null;
        }
    }

    return [
        'user_id' => (int) $_SESSION['user_id'],
        'username' => (string) ($_SESSION['username'] ?? ''),
        'first_name' => (string) ($_SESSION['first_name'] ?? ''),
        'last_name' => (string) ($_SESSION['last_name'] ?? ''),
        'role' => (string) $_SESSION['role'],
        'department' => (string) ($_SESSION['department'] ?? ''),
    ];
}

function integrationRequireAccess(bool $allowPublicRead = false, bool $write = false): array {
    $user = integrationCurrentUser();
    $hasToken = integrationHasValidToken();

    if ($allowPublicRead && !$write) {
        return ['user' => $user, 'via_token' => $hasToken, 'via_public' => true];
    }

    if ($hasToken) {
        return ['user' => $user, 'via_token' => true, 'via_public' => false];
    }

    if ($user !== null && $user['role'] === ROLE_ADMIN) {
        return ['user' => $user, 'via_token' => false, 'via_public' => false];
    }

    $message = $write
        ? 'Administrator access or X-Integration-Token is required for write access.'
        : 'Administrator access or X-Integration-Token is required to view integration records.';

    integrationJson(['success' => false, 'message' => $message], $user === null ? 401 : 403);
}

function integrationEndpointUrls(): array {
    $base = getBasePath();
    return [
        'map' => $base . 'api/integrations/departments/map.php',
        'records' => $base . 'api/integrations/departments/records.php',
        'report' => $base . 'api/integrations/departments/report.php',
    ];
}

function integrationDepartmentByCode(PDO $db, string $code): ?array {
    $stmt = $db->prepare(
        'SELECT department_id, department_code, department_name, description, is_active
         FROM departments
         WHERE UPPER(department_code) = ?
         LIMIT 1'
    );
    $stmt->execute([strtoupper($code)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function integrationRecordTypeByCode(PDO $db, string $code): ?array {
    $stmt = $db->prepare(
        'SELECT record_type_id, record_type_code, record_type_name, data_domain, description, is_active
         FROM integration_record_types
         WHERE LOWER(record_type_code) = ?
         LIMIT 1'
    );
    $stmt->execute([strtolower($code)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function integrationRouteByCodes(PDO $db, string $senderCode, string $receiverCode, string $recordTypeCode): ?array {
    $stmt = $db->prepare(
        'SELECT rt.route_id, rt.flow_order, rt.notes, rt.is_active,
                sd.department_id AS sender_department_id, sd.department_code AS sender_department_code, sd.department_name AS sender_department_name,
                rd.department_id AS receiver_department_id, rd.department_code AS receiver_department_code, rd.department_name AS receiver_department_name,
                irt.record_type_id, irt.record_type_code, irt.record_type_name, irt.data_domain, irt.description AS record_type_description
         FROM integration_routes rt
         JOIN departments sd ON sd.department_id = rt.sender_department_id
         JOIN departments rd ON rd.department_id = rt.receiver_department_id
         JOIN integration_record_types irt ON irt.record_type_id = rt.record_type_id
         WHERE UPPER(sd.department_code) = ?
           AND UPPER(rd.department_code) = ?
           AND LOWER(irt.record_type_code) = ?
           AND rt.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([strtoupper($senderCode), strtoupper($receiverCode), strtolower($recordTypeCode)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function integrationFormatDepartment(array $row): array {
    return [
        'id' => isset($row['department_id']) ? (int) $row['department_id'] : null,
        'code' => (string) ($row['department_code'] ?? ''),
        'key' => strtolower((string) ($row['department_code'] ?? '')),
        'name' => (string) ($row['department_name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'is_active' => isset($row['is_active']) ? (int) $row['is_active'] === 1 : true,
    ];
}

function integrationHydrateDocument(PDO $db, string $documentId): ?array {
    $stmt = $db->prepare(
        "SELECT d.document_id, d.route_id, d.subject_type, d.subject_ref, d.title,
                d.source_system, d.source_reference, d.status, d.payload,
                d.sent_at, d.received_at, d.acknowledged_at, d.created_at, d.updated_at,
                irt.record_type_code, irt.record_type_name, irt.data_domain,
                sd.department_code AS sender_department_code, sd.department_name AS sender_department_name,
                rd.department_code AS receiver_department_code, rd.department_name AS receiver_department_name,
                CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN ' ' || u.last_name ELSE '' END) AS created_by_name
         FROM integration_documents d
         JOIN integration_record_types irt ON irt.record_type_id = d.record_type_id
         JOIN departments sd ON sd.department_id = d.sender_department_id
         JOIN departments rd ON rd.department_id = d.receiver_department_id
         LEFT JOIN users u ON u.user_id = d.created_by_user_id
         WHERE d.document_id = ?
         LIMIT 1"
    );
    $stmt->execute([$documentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'document_id' => (string) $row['document_id'],
        'route_id' => $row['route_id'] !== null ? (int) $row['route_id'] : null,
        'record_type' => [
            'code' => (string) $row['record_type_code'],
            'name' => (string) $row['record_type_name'],
            'domain' => (string) $row['data_domain'],
        ],
        'sender_department' => [
            'code' => (string) $row['sender_department_code'],
            'key' => strtolower((string) $row['sender_department_code']),
            'name' => (string) $row['sender_department_name'],
        ],
        'receiver_department' => [
            'code' => (string) $row['receiver_department_code'],
            'key' => strtolower((string) $row['receiver_department_code']),
            'name' => (string) $row['receiver_department_name'],
        ],
        'subject_type' => (string) $row['subject_type'],
        'subject_ref' => $row['subject_ref'],
        'title' => (string) $row['title'],
        'source_system' => $row['source_system'],
        'source_reference' => $row['source_reference'],
        'status' => (string) $row['status'],
        'payload' => integrationDecodeJsonValue($row['payload']),
        'sent_at' => $row['sent_at'],
        'received_at' => $row['received_at'],
        'acknowledged_at' => $row['acknowledged_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'created_by' => trim((string) ($row['created_by_name'] ?? '')) ?: null,
    ];
}

function integrationAudit(PDO $db, ?int $userId, string $actionType, string $description, ?string $targetType = 'System', $targetId = null): void {
    $numericId = is_numeric($targetId) ? (int) $targetId : null;
    $finalDescription = $description;
    if ($targetId !== null && !is_numeric($targetId)) {
        $finalDescription .= ' (Ref: ' . $targetId . ')';
    }

    try {
        $db->prepare(
            'INSERT INTO audit_logs (user_id, action_type, target_type, target_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $actionType,
            $targetType,
            $numericId,
            $finalDescription,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[COMLAB Integration Audit] ' . $e->getMessage());
    }
}

function integrationBuildUsageReport(PDO $db): array {
    $stats = $db->query(
        "SELECT
            (SELECT COUNT(*) FROM locations WHERE is_active = 1) AS active_labs,
            (SELECT COALESCE(SUM(capacity), 0) FROM locations WHERE is_active = 1) AS total_capacity,
            (SELECT COUNT(*) FROM devices) AS total_devices,
            (SELECT COUNT(*) FROM devices WHERE status = 'Available') AS devices_available,
            (SELECT COUNT(*) FROM devices WHERE status = 'Under Repair') AS devices_under_repair,
            (SELECT COUNT(*) FROM devices WHERE status = 'Damaged') AS devices_damaged,
            (SELECT COUNT(*) FROM faculty_schedules WHERE is_active = 1) AS active_schedules,
            (SELECT COUNT(*) FROM requests WHERE status = 'Pending') AS pending_requests,
            (SELECT COUNT(*) FROM schedule_attendance WHERE attendance_date = CURRENT_DATE AND status = 'Present') AS present_today,
            (SELECT COUNT(*) FROM schedule_attendance WHERE attendance_date = CURRENT_DATE AND status = 'Absent') AS absent_today,
            (SELECT COUNT(*) FROM users WHERE role = 'Faculty' AND is_active = 1) AS active_faculty,
            (SELECT COUNT(*) FROM lab_usage_logs WHERE usage_date = CURRENT_DATE) AS usage_sessions_today,
            (SELECT COUNT(*) FROM lab_usage_logs WHERE usage_date >= CURRENT_DATE - 7) AS usage_sessions_7d,
            (SELECT COALESCE(SUM(participant_count), 0) FROM lab_usage_logs WHERE usage_date >= CURRENT_DATE - 7) AS participants_7d,
            (SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(session_end, session_start) - session_start))) / 3600.0, 0) FROM lab_usage_logs WHERE usage_date >= CURRENT_DATE - 7) AS usage_hours_7d,
            (SELECT COUNT(*) FROM device_maintenance_logs WHERE start_datetime >= CURRENT_DATE - INTERVAL '30 days') AS equipment_logs_30d"
    )->fetch();

    $labBreakdown = $db->query(
        "SELECT l.lab_code, l.lab_name, l.capacity,
                COALESCE(d.device_count, 0) AS device_count,
                COALESCE(d.available_devices, 0) AS available_devices,
                COALESCE(u.usage_sessions_7d, 0) AS usage_sessions_7d,
                COALESCE(u.participants_7d, 0) AS participants_7d,
                u.last_used_at
         FROM locations l
         LEFT JOIN (
             SELECT location_id,
                    COUNT(*) AS device_count,
                    SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available_devices
             FROM devices
             GROUP BY location_id
         ) d ON d.location_id = l.location_id
         LEFT JOIN (
             SELECT location_id,
                    COUNT(*) AS usage_sessions_7d,
                    COALESCE(SUM(participant_count), 0) AS participants_7d,
                    MAX(session_start) AS last_used_at
             FROM lab_usage_logs
             WHERE usage_date >= CURRENT_DATE - 7
             GROUP BY location_id
         ) u ON u.location_id = l.location_id
         WHERE l.is_active = 1
         ORDER BY l.lab_code"
    )->fetchAll();

    $recentRequests = $db->query(
        "SELECT request_id, request_type, department, status, created_at
         FROM requests
         ORDER BY created_at DESC
         LIMIT 5"
    )->fetchAll();

    $recentUsageLogs = $db->query(
        "SELECT ul.usage_log_id, ul.usage_date, ul.session_start, ul.session_end, ul.participant_count,
                ul.subject_code, ul.source_system, ul.source_reference, ul.notes,
                l.lab_code, l.lab_name,
                CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN ' ' || u.last_name ELSE '' END) AS faculty_name
         FROM lab_usage_logs ul
         JOIN locations l ON l.location_id = ul.location_id
         LEFT JOIN users u ON u.user_id = ul.faculty_id
         ORDER BY ul.session_start DESC
         LIMIT 5"
    )->fetchAll();

    $recentEquipmentLogs = $db->query(
        "SELECT ml.maintenance_id, ml.maintenance_type, ml.issue_description, ml.action_taken,
                ml.status_before, ml.status_after, ml.start_datetime, ml.end_datetime,
                d.device_code, d.device_type,
                CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN ' ' || u.last_name ELSE '' END) AS performed_by_name
         FROM device_maintenance_logs ml
         JOIN devices d ON d.device_id = ml.device_id
         LEFT JOIN users u ON u.user_id = ml.performed_by
         ORDER BY ml.start_datetime DESC
         LIMIT 5"
    )->fetchAll();

    return [
        'generated_at' => gmdate('c'),
        'summary' => [
            'active_labs' => (int) ($stats['active_labs'] ?? 0),
            'total_capacity' => (int) ($stats['total_capacity'] ?? 0),
            'total_devices' => (int) ($stats['total_devices'] ?? 0),
            'devices_available' => (int) ($stats['devices_available'] ?? 0),
            'devices_under_repair' => (int) ($stats['devices_under_repair'] ?? 0),
            'devices_damaged' => (int) ($stats['devices_damaged'] ?? 0),
            'active_schedules' => (int) ($stats['active_schedules'] ?? 0),
            'pending_requests' => (int) ($stats['pending_requests'] ?? 0),
            'present_today' => (int) ($stats['present_today'] ?? 0),
            'absent_today' => (int) ($stats['absent_today'] ?? 0),
            'active_faculty' => (int) ($stats['active_faculty'] ?? 0),
            'usage_sessions_today' => (int) ($stats['usage_sessions_today'] ?? 0),
            'usage_sessions_7d' => (int) ($stats['usage_sessions_7d'] ?? 0),
            'participants_7d' => (int) ($stats['participants_7d'] ?? 0),
            'usage_hours_7d' => round((float) ($stats['usage_hours_7d'] ?? 0), 2),
            'equipment_logs_30d' => (int) ($stats['equipment_logs_30d'] ?? 0),
        ],
        'labs' => array_map(static function (array $row): array {
            return [
                'lab_code' => (string) $row['lab_code'],
                'lab_name' => (string) $row['lab_name'],
                'capacity' => (int) $row['capacity'],
                'device_count' => (int) $row['device_count'],
                'available_devices' => (int) $row['available_devices'],
                'usage_sessions_7d' => (int) ($row['usage_sessions_7d'] ?? 0),
                'participants_7d' => (int) ($row['participants_7d'] ?? 0),
                'last_used_at' => $row['last_used_at'],
            ];
        }, $labBreakdown),
        'recent_requests' => array_map(static function (array $row): array {
            return [
                'request_id' => (int) $row['request_id'],
                'request_type' => (string) $row['request_type'],
                'department' => (string) $row['department'],
                'status' => (string) $row['status'],
                'created_at' => $row['created_at'],
            ];
        }, $recentRequests),
        'recent_usage_logs' => array_map(static function (array $row): array {
            return [
                'usage_log_id' => (int) $row['usage_log_id'],
                'lab_code' => (string) $row['lab_code'],
                'lab_name' => (string) $row['lab_name'],
                'faculty_name' => trim((string) ($row['faculty_name'] ?? '')) ?: null,
                'usage_date' => $row['usage_date'],
                'session_start' => $row['session_start'],
                'session_end' => $row['session_end'],
                'participant_count' => (int) ($row['participant_count'] ?? 0),
                'subject_code' => $row['subject_code'],
                'source_system' => $row['source_system'],
                'source_reference' => $row['source_reference'],
                'notes' => $row['notes'],
            ];
        }, $recentUsageLogs),
        'recent_equipment_logs' => array_map(static function (array $row): array {
            return [
                'maintenance_id' => (int) $row['maintenance_id'],
                'device_code' => (string) $row['device_code'],
                'device_type' => (string) $row['device_type'],
                'maintenance_type' => (string) $row['maintenance_type'],
                'issue_description' => $row['issue_description'],
                'action_taken' => $row['action_taken'],
                'status_before' => (string) $row['status_before'],
                'status_after' => (string) $row['status_after'],
                'start_datetime' => $row['start_datetime'],
                'end_datetime' => $row['end_datetime'],
                'performed_by' => trim((string) ($row['performed_by_name'] ?? '')) ?: null,
            ];
        }, $recentEquipmentLogs),
    ];
}
