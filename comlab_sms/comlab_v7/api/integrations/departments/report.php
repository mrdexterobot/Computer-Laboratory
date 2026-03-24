<?php

require_once __DIR__ . '/../_helpers.php';

integrationBootstrap();

function integrationRoutesForDepartment(PDO $db, string $peerCode): array {
    $stmt = $db->prepare(
        'SELECT rt.route_id, rt.flow_order, rt.notes,
                rt.sender_department_id, rt.receiver_department_id, rt.record_type_id,
                sd.department_code AS sender_department_code, sd.department_name AS sender_department_name,
                rd.department_code AS receiver_department_code, rd.department_name AS receiver_department_name,
                irt.record_type_code, irt.record_type_name, irt.data_domain
         FROM integration_routes rt
         JOIN departments sd ON sd.department_id = rt.sender_department_id
         JOIN departments rd ON rd.department_id = rt.receiver_department_id
         JOIN integration_record_types irt ON irt.record_type_id = rt.record_type_id
         WHERE rt.is_active = 1
           AND ((UPPER(sd.department_code) = ? AND UPPER(rd.department_code) = ?)
             OR (UPPER(sd.department_code) = ? AND UPPER(rd.department_code) = ?))
         ORDER BY rt.flow_order, irt.record_type_name'
    );
    $stmt->execute([
        COMLAB_INTEGRATION_DEPARTMENT_CODE, $peerCode,
        $peerCode, COMLAB_INTEGRATION_DEPARTMENT_CODE,
    ]);

    return $stmt->fetchAll();
}

function integrationRecentDocumentsForDepartment(PDO $db, string $peerCode, int $limit = 8): array {
    $stmt = $db->prepare(
        'SELECT d.document_id, d.title, d.status, d.created_at, d.sent_at, d.received_at, d.acknowledged_at,
                irt.record_type_code, irt.record_type_name,
                sd.department_code AS sender_department_code, sd.department_name AS sender_department_name,
                rd.department_code AS receiver_department_code, rd.department_name AS receiver_department_name
         FROM integration_documents d
         JOIN integration_record_types irt ON irt.record_type_id = d.record_type_id
         JOIN departments sd ON sd.department_id = d.sender_department_id
         JOIN departments rd ON rd.department_id = d.receiver_department_id
         WHERE ((UPPER(sd.department_code) = ? AND UPPER(rd.department_code) = ?)
             OR (UPPER(sd.department_code) = ? AND UPPER(rd.department_code) = ?))
         ORDER BY d.created_at DESC
         LIMIT ' . $limit
    );
    $stmt->execute([
        COMLAB_INTEGRATION_DEPARTMENT_CODE, $peerCode,
        $peerCode, COMLAB_INTEGRATION_DEPARTMENT_CODE,
    ]);

    return array_map(static function (array $row): array {
        return [
            'document_id' => (string) $row['document_id'],
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
            'record_type' => [
                'code' => (string) $row['record_type_code'],
                'name' => (string) $row['record_type_name'],
            ],
            'sender_department' => [
                'code' => (string) $row['sender_department_code'],
                'name' => (string) $row['sender_department_name'],
            ],
            'receiver_department' => [
                'code' => (string) $row['receiver_department_code'],
                'name' => (string) $row['receiver_department_name'],
            ],
            'created_at' => $row['created_at'],
            'sent_at' => $row['sent_at'],
            'received_at' => $row['received_at'],
            'acknowledged_at' => $row['acknowledged_at'],
        ];
    }, $stmt->fetchAll());
}

function integrationBuildDepartmentReport(PDO $db, array $targetDepartment, string $reportTypeCode = ''): array {
    $routes = integrationRoutesForDepartment($db, strtoupper((string) $targetDepartment['department_code']));
    $incoming = [];
    $outgoing = [];
    $defaultReport = null;

    foreach ($routes as $route) {
        $isOutbound = strtoupper((string) $route['sender_department_code']) === COMLAB_INTEGRATION_DEPARTMENT_CODE;
        $entry = [
            'route_id' => (int) $route['route_id'],
            'flow_order' => (int) $route['flow_order'],
            'record_type' => [
                'code' => (string) $route['record_type_code'],
                'name' => (string) $route['record_type_name'],
                'domain' => (string) $route['data_domain'],
            ],
            'notes' => (string) ($route['notes'] ?? ''),
        ];

        if ($isOutbound) {
            $outgoing[] = $entry;
            if ($defaultReport === null) {
                $defaultReport = $entry['record_type'];
            }
        } else {
            $incoming[] = $entry;
        }
    }

    $selectedReportType = $reportTypeCode !== '' ? $reportTypeCode : (string) ($defaultReport['code'] ?? '');
    $usageReport = integrationBuildUsageReport($db);
    $recentDocuments = integrationRecentDocumentsForDepartment($db, strtoupper((string) $targetDepartment['department_code']));

    return [
        'generated_at' => gmdate('c'),
        'source_department' => [
            'code' => COMLAB_INTEGRATION_DEPARTMENT_CODE,
            'key' => 'comlab',
            'name' => 'Computer Laboratory',
        ],
        'target_department' => integrationFormatDepartment($targetDepartment),
        'report_type_code' => $selectedReportType !== '' ? $selectedReportType : null,
        'routes' => [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
        ],
        'usage_report' => $usageReport,
        'recent_documents' => $recentDocuments,
        'dispatch_supported' => $selectedReportType !== '',
    ];
}

function integrationMergeReportRequest(array $package, array $payloadPatch): array {
    if ($payloadPatch === []) {
        return $package;
    }

    $merged = array_replace_recursive($package, $payloadPatch);
    $merged['request_context'] = $payloadPatch;

    return $merged;
}

function integrationDispatchReport(PDO $db, array $input, array $access): array {
    $targetCode = integrationNormalizeDepartmentCode($input['target_key'] ?? $input['department'] ?? $input['department_key'] ?? '');
    $reportTypeCode = integrationNormalizeRecordType($input['report_type'] ?? $input['record_type_code'] ?? '');
    if ($targetCode === '') {
        integrationJson(['success' => false, 'message' => 'target_key or department is required.'], 422);
    }

    $targetDepartment = integrationDepartmentByCode($db, $targetCode);
    if ($targetDepartment === null) {
        integrationJson(['success' => false, 'message' => 'Target department was not found.'], 404);
    }

    $routes = integrationRoutesForDepartment($db, $targetCode);
    $selectedRoute = null;
    foreach ($routes as $route) {
        $isOutbound = strtoupper((string) $route['sender_department_code']) === COMLAB_INTEGRATION_DEPARTMENT_CODE;
        if (!$isOutbound) {
            continue;
        }

        if ($reportTypeCode === '' || strtolower((string) $route['record_type_code']) === $reportTypeCode) {
            $selectedRoute = $route;
            break;
        }
    }

    if ($selectedRoute === null) {
        integrationJson(['success' => false, 'message' => 'No active outbound reporting route exists for the selected department and report type.'], 422);
    }

    $reportTypeCode = strtolower((string) $selectedRoute['record_type_code']);
    $package = integrationBuildDepartmentReport($db, $targetDepartment, $reportTypeCode);
    $payloadPatch = integrationParsePayloadValue($input['payload'] ?? []);
    $package = integrationMergeReportRequest($package, $payloadPatch);

    $title = trim((string) ($input['title'] ?? sprintf('COMLAB %s report for %s', $selectedRoute['record_type_name'], $targetDepartment['department_name'])));
    $subjectRef = trim((string) ($input['subject_ref'] ?? ('RPT-' . gmdate('YmdHis'))));
    $sourceReference = trim((string) ($input['source_reference'] ?? ('comlab-report-' . gmdate('YmdHis'))));
    $now = gmdate('c');
    $initialStatus = 'sent';
    $acknowledgedAt = null;

    $workflowInput = is_array($payloadPatch['workflow'] ?? null) ? $payloadPatch['workflow'] : [];
    $reportCategory = strtolower(trim((string) ($workflowInput['report_category'] ?? 'operations_report')));
    $allowedCategories = ['missing_computer', 'computer_sent', 'computer_received', 'operations_report'];
    if (!in_array($reportCategory, $allowedCategories, true)) {
        $reportCategory = 'operations_report';
    }
    $itemReference = trim((string) ($workflowInput['item_reference'] ?? ($payloadPatch['item_reference'] ?? '')));
    $quantity = max(1, (int) ($workflowInput['quantity'] ?? ($payloadPatch['quantity'] ?? 1)));
    $details = trim((string) ($workflowInput['details'] ?? ($payloadPatch['notes'] ?? '')));

    $workflowBlock = [
        'stage' => 'submitted_by_comlab',
        'report_category' => $reportCategory,
        'item_reference' => $itemReference !== '' ? $itemReference : null,
        'quantity' => $quantity,
        'details' => $details !== '' ? $details : null,
        'submitted_by_department' => 'COMLAB',
        'submitted_at' => $now,
        'pmed_verified_at' => null,
        'returned_to_comlab_at' => null,
        'comlab_confirmed_at' => null,
    ];

    $package['workflow'] = array_filter($workflowBlock, static fn($value) => $value !== null);
    $package['handoff'] = [
        'mode' => 'shared_database',
        'receiver_department_code' => $targetCode,
        'submitted_at' => $now,
    ];

    $stmt = $db->prepare(
        'INSERT INTO integration_documents
            (route_id, record_type_id, sender_department_id, receiver_department_id, subject_type, subject_ref, title,
             source_system, source_reference, status, payload, sent_at, received_at, acknowledged_at, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?, ?, ?, ?)
         RETURNING document_id'
    );
    $stmt->execute([
        $selectedRoute['route_id'],
        $selectedRoute['record_type_id'],
        $selectedRoute['sender_department_id'],
        $selectedRoute['receiver_department_id'],
        'system',
        $subjectRef,
        $title,
        'COMLAB',
        $sourceReference,
        $initialStatus,
        json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $now,
        null,
        $acknowledgedAt,
        $access['user']['user_id'] ?? null,
    ]);

    $documentId = (string) $stmt->fetchColumn();
    integrationAudit(
        $db,
        $access['user']['user_id'] ?? null,
        'Integration Report Dispatch',
        sprintf('COMLAB dispatched %s to %s.', $selectedRoute['record_type_name'], $targetCode),
        'System',
        $documentId
    );

    return [
        'document' => integrationHydrateDocument($db, $documentId),
        'package' => $package,
    ];
}

$method = integrationMethod();

try {
    $db = getDB();

    if ($method === 'GET') {
        integrationRequireAccess(false, false);

        $departmentCode = integrationNormalizeDepartmentCode($_GET['department'] ?? $_GET['department_key'] ?? 'PMED');
        if ($departmentCode === '') {
            integrationJson(['success' => false, 'message' => 'department is required.'], 422);
        }

        $targetDepartment = integrationDepartmentByCode($db, $departmentCode);
        if ($targetDepartment === null) {
            integrationJson(['success' => false, 'message' => 'Requested department was not found.'], 404);
        }

        $reportTypeCode = integrationNormalizeRecordType($_GET['report_type'] ?? $_GET['record_type_code'] ?? '');
        integrationJson([
            'success' => true,
            'report' => integrationBuildDepartmentReport($db, $targetDepartment, $reportTypeCode),
            'endpoints' => integrationEndpointUrls(),
        ]);
    }

    if ($method !== 'POST') {
        integrationJson(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $access = integrationRequireAccess(false, true);
    $input = integrationInput();
    $action = strtolower(trim((string) ($input['action'] ?? 'dispatch_report')));
    if ($action !== 'dispatch_report') {
        integrationJson(['success' => false, 'message' => 'Only action=dispatch_report is supported.'], 422);
    }

    $result = integrationDispatchReport($db, $input, $access);
    integrationJson([
        'success' => true,
        'message' => 'Department report dispatched successfully.',
        'document' => $result['document'],
        'package' => $result['package'],
    ], 201);
} catch (Throwable $e) {
    error_log('[COMLAB Integration Report] ' . $e->getMessage());
    integrationJson(['success' => false, 'message' => 'Unable to process the integration report request.'], 500);
}
