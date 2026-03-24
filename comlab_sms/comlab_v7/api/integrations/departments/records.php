<?php

require_once __DIR__ . '/../_helpers.php';

integrationBootstrap();

function integrationFetchRecords(
    PDO $db,
    string $direction,
    string $departmentCode,
    string $recordTypeCode,
    string $status,
    string $subjectRef,
    string $documentId,
    string $sourceReference,
    int $limit
): array {
    $sql = 'SELECT d.document_id, d.route_id, d.subject_type, d.subject_ref, d.title,
                   d.source_system, d.source_reference, d.status, d.payload,
                   d.sent_at, d.received_at, d.acknowledged_at, d.created_at, d.updated_at,
                   irt.record_type_code, irt.record_type_name, irt.data_domain,
                   sd.department_code AS sender_department_code, sd.department_name AS sender_department_name,
                   rd.department_code AS receiver_department_code, rd.department_name AS receiver_department_name
            FROM integration_documents d
            JOIN integration_record_types irt ON irt.record_type_id = d.record_type_id
            JOIN departments sd ON sd.department_id = d.sender_department_id
            JOIN departments rd ON rd.department_id = d.receiver_department_id
            WHERE (UPPER(sd.department_code) = ? OR UPPER(rd.department_code) = ?)';
    $params = [COMLAB_INTEGRATION_DEPARTMENT_CODE, COMLAB_INTEGRATION_DEPARTMENT_CODE];

    if ($direction === 'incoming') {
        $sql .= ' AND UPPER(rd.department_code) = ?';
        $params[] = COMLAB_INTEGRATION_DEPARTMENT_CODE;
    } elseif ($direction === 'outgoing') {
        $sql .= ' AND UPPER(sd.department_code) = ?';
        $params[] = COMLAB_INTEGRATION_DEPARTMENT_CODE;
    }

    if ($departmentCode !== '') {
        $sql .= ' AND ((UPPER(sd.department_code) = ? AND UPPER(rd.department_code) = ?) OR (UPPER(sd.department_code) = ? AND UPPER(rd.department_code) = ?))';
        $params[] = COMLAB_INTEGRATION_DEPARTMENT_CODE;
        $params[] = $departmentCode;
        $params[] = $departmentCode;
        $params[] = COMLAB_INTEGRATION_DEPARTMENT_CODE;
    }

    if ($recordTypeCode !== '') {
        $sql .= ' AND LOWER(irt.record_type_code) = ?';
        $params[] = $recordTypeCode;
    }

    if ($status !== '') {
        $sql .= ' AND LOWER(d.status) = ?';
        $params[] = $status;
    }

    if ($subjectRef !== '') {
        $sql .= ' AND d.subject_ref = ?';
        $params[] = $subjectRef;
    }

    if ($documentId !== '') {
        $sql .= ' AND CAST(d.document_id AS text) = ?';
        $params[] = $documentId;
    }

    if ($sourceReference !== '') {
        $sql .= ' AND d.source_reference = ?';
        $params[] = $sourceReference;
    }

    $sql .= ' ORDER BY d.created_at DESC LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return array_map(static function (array $row): array {
        $senderCode = (string) $row['sender_department_code'];
        $direction = strtoupper($senderCode) === COMLAB_INTEGRATION_DEPARTMENT_CODE ? 'outgoing' : 'incoming';

        return [
            'document_id' => (string) $row['document_id'],
            'route_id' => $row['route_id'] !== null ? (int) $row['route_id'] : null,
            'direction' => $direction,
            'record_type' => [
                'code' => (string) $row['record_type_code'],
                'name' => (string) $row['record_type_name'],
                'domain' => (string) $row['data_domain'],
            ],
            'sender_department' => [
                'code' => $senderCode,
                'key' => strtolower($senderCode),
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
        ];
    }, $stmt->fetchAll());
}

function integrationDispatchRecord(PDO $db, array $input, array $access): array {
    $senderCode = integrationNormalizeDepartmentCode($input['sender_department_code'] ?? $input['sender_key'] ?? COMLAB_INTEGRATION_DEPARTMENT_CODE);
    $receiverCode = integrationNormalizeDepartmentCode($input['receiver_department_code'] ?? $input['receiver_key'] ?? $input['target_key'] ?? '');
    $recordTypeCode = integrationNormalizeRecordType($input['record_type_code'] ?? $input['record_type'] ?? '');
    $subjectType = strtolower(trim((string) ($input['subject_type'] ?? 'general')));
    $subjectRef = trim((string) ($input['subject_ref'] ?? ''));
    $title = trim((string) ($input['title'] ?? ''));
    $status = integrationNormalizeStatus($input['status'] ?? ($receiverCode === COMLAB_INTEGRATION_DEPARTMENT_CODE ? 'received' : 'sent'));
    $payload = integrationParsePayloadValue($input['payload'] ?? []);
    $sourceSystem = trim((string) ($input['source_system'] ?? $senderCode));
    $sourceReference = trim((string) ($input['source_reference'] ?? ''));

    if ($receiverCode === '' || $recordTypeCode === '' || $title === '') {
        integrationJson(['success' => false, 'message' => 'receiver_department_code, record_type_code, and title are required.'], 422);
    }

    if ($senderCode !== COMLAB_INTEGRATION_DEPARTMENT_CODE && $receiverCode !== COMLAB_INTEGRATION_DEPARTMENT_CODE) {
        integrationJson(['success' => false, 'message' => 'One side of the record flow must be COMLAB.'], 422);
    }

    if (!in_array($subjectType, integrationAllowedSubjectTypes(), true)) {
        integrationJson(['success' => false, 'message' => 'Invalid subject_type supplied.'], 422);
    }

    if ($status === '' || !in_array($status, integrationAllowedDocumentStatuses(), true)) {
        integrationJson(['success' => false, 'message' => 'Invalid integration document status supplied.'], 422);
    }

    $route = integrationRouteByCodes($db, $senderCode, $receiverCode, $recordTypeCode);
    if ($route === null) {
        integrationJson(['success' => false, 'message' => 'No active integration route exists for the provided sender, receiver, and record type.'], 422);
    }

    $now = gmdate('c');
    $sentAt = in_array($status, ['sent', 'received', 'acknowledged', 'archived'], true) ? ($input['sent_at'] ?? $now) : null;
    $receivedAt = in_array($status, ['received', 'acknowledged', 'archived'], true) ? ($input['received_at'] ?? $now) : null;
    $ackAt = in_array($status, ['acknowledged', 'archived'], true) ? ($input['acknowledged_at'] ?? $now) : null;

    $stmt = $db->prepare(
        'INSERT INTO integration_documents
            (route_id, record_type_id, sender_department_id, receiver_department_id, subject_type, subject_ref, title,
             source_system, source_reference, status, payload, sent_at, received_at, acknowledged_at, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?, ?, ?, ?)
         RETURNING document_id'
    );
    $stmt->execute([
        $route['route_id'],
        $route['record_type_id'],
        $route['sender_department_id'],
        $route['receiver_department_id'],
        $subjectType,
        $subjectRef !== '' ? $subjectRef : null,
        $title,
        $sourceSystem !== '' ? $sourceSystem : null,
        $sourceReference !== '' ? $sourceReference : null,
        $status,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $sentAt,
        $receivedAt,
        $ackAt,
        $access['user']['user_id'] ?? null,
    ]);

    $documentId = (string) $stmt->fetchColumn();
    integrationAudit(
        $db,
        $access['user']['user_id'] ?? null,
        'Integration Dispatch',
        sprintf('Integration record %s dispatched from %s to %s.', $recordTypeCode, $senderCode, $receiverCode),
        'System',
        $documentId
    );

    return integrationHydrateDocument($db, $documentId) ?? ['document_id' => $documentId];
}

function integrationUpdateRecordStatus(PDO $db, array $input, array $access, string $defaultAction): array {
    $documentId = trim((string) ($input['document_id'] ?? ''));
    if ($documentId === '') {
        integrationJson(['success' => false, 'message' => 'document_id is required.'], 422);
    }

    $existing = integrationHydrateDocument($db, $documentId);
    if ($existing === null) {
        integrationJson(['success' => false, 'message' => 'Integration document not found.'], 404);
    }

    if (
        strtoupper((string) $existing['sender_department']['code']) !== COMLAB_INTEGRATION_DEPARTMENT_CODE &&
        strtoupper((string) $existing['receiver_department']['code']) !== COMLAB_INTEGRATION_DEPARTMENT_CODE
    ) {
        integrationJson(['success' => false, 'message' => 'The selected record does not belong to COMLAB routes.'], 403);
    }

    $status = integrationNormalizeStatus($input['status'] ?? $defaultAction);
    if ($status === '' || !in_array($status, integrationAllowedDocumentStatuses(), true)) {
        integrationJson(['success' => false, 'message' => 'Invalid integration document status supplied.'], 422);
    }

    $payloadPatch = integrationParsePayloadValue($input['payload'] ?? []);
    $payload = is_array($existing['payload']) ? $existing['payload'] : [];
    if ($payloadPatch !== []) {
        $payload = array_replace_recursive($payload, $payloadPatch);
    }

    $now = gmdate('c');
    $sentAt = $existing['sent_at'];
    $receivedAt = $existing['received_at'];
    $ackAt = $existing['acknowledged_at'];

    if ($status === 'sent' && $sentAt === null) {
        $sentAt = $now;
    }
    if (in_array($status, ['received', 'acknowledged', 'archived'], true) && $receivedAt === null) {
        $receivedAt = $now;
    }
    if (in_array($status, ['acknowledged', 'archived'], true) && $ackAt === null) {
        $ackAt = $now;
    }

    $db->prepare(
        'UPDATE integration_documents
         SET status = ?,
             payload = CAST(? AS jsonb),
             sent_at = ?,
             received_at = ?,
             acknowledged_at = ?
         WHERE document_id = ?'
    )->execute([
        $status,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $sentAt,
        $receivedAt,
        $ackAt,
        $documentId,
    ]);

    integrationAudit(
        $db,
        $access['user']['user_id'] ?? null,
        'Integration Status Update',
        sprintf('Integration document %s marked as %s.', $documentId, $status),
        'System',
        $documentId
    );

    return integrationHydrateDocument($db, $documentId) ?? ['document_id' => $documentId];
}

function integrationGetWorkflowDocument(PDO $db, string $documentId): ?array {
    $stmt = $db->prepare(
        'SELECT d.document_id, d.status, d.payload,
                sd.department_code AS sender_department_code,
                rd.department_code AS receiver_department_code
         FROM integration_documents d
         JOIN departments sd ON sd.department_id = d.sender_department_id
         JOIN departments rd ON rd.department_id = d.receiver_department_id
         WHERE d.document_id = ?
         LIMIT 1'
    );
    $stmt->execute([$documentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row['payload'] = integrationDecodeJsonValue($row['payload']);
    if (!is_array($row['payload'])) {
        $row['payload'] = [];
    }

    return $row;
}

function integrationCanActAsPmed(array $access): bool {
    $user = $access['user'] ?? null;
    if (($access['via_token'] ?? false) === true) {
        return true;
    }
    if (!is_array($user)) {
        return false;
    }

    return (($user['department'] ?? '') === 'PMED') || (($user['role'] ?? '') === ROLE_ADMIN);
}

function integrationCanActAsComlab(array $access): bool {
    $user = $access['user'] ?? null;
    if (($access['via_token'] ?? false) === true) {
        return true;
    }
    if (!is_array($user)) {
        return false;
    }

    return ($user['role'] ?? '') === ROLE_ADMIN;
}

function integrationUpdateWorkflowStage(PDO $db, string $documentId, string $status, array $payload): array {
    $now = gmdate('c');
    $sentAt = in_array($status, ['sent', 'received', 'acknowledged', 'archived'], true) ? $now : null;
    $receivedAt = in_array($status, ['received', 'acknowledged', 'archived'], true) ? $now : null;
    $ackAt = in_array($status, ['acknowledged', 'archived'], true) ? $now : null;

    $db->prepare(
        'UPDATE integration_documents
         SET status = ?,
             payload = CAST(? AS jsonb),
             sent_at = COALESCE(sent_at, ?),
             received_at = CASE WHEN ? IS NOT NULL THEN COALESCE(received_at, ?) ELSE received_at END,
             acknowledged_at = CASE WHEN ? IS NOT NULL THEN COALESCE(acknowledged_at, ?) ELSE acknowledged_at END
         WHERE document_id = ?'
    )->execute([
        $status,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $sentAt,
        $receivedAt,
        $receivedAt,
        $ackAt,
        $ackAt,
        $documentId,
    ]);

    return integrationHydrateDocument($db, $documentId) ?? ['document_id' => $documentId];
}

$method = integrationMethod();

try {
    $db = getDB();

    if ($method === 'GET') {
        integrationRequireAccess(false, false);

        $direction = strtolower(trim((string) ($_GET['direction'] ?? 'all')));
        if (!in_array($direction, ['all', 'incoming', 'outgoing'], true)) {
            integrationJson(['success' => false, 'message' => 'direction must be all, incoming, or outgoing.'], 422);
        }

        $departmentCode = integrationNormalizeDepartmentCode($_GET['department'] ?? $_GET['department_key'] ?? '');
        $recordTypeCode = integrationNormalizeRecordType($_GET['record_type'] ?? $_GET['record_type_code'] ?? '');
        $status = integrationNormalizeStatus($_GET['status'] ?? '');
        $subjectRef = trim((string) ($_GET['subject_ref'] ?? ''));
        $documentId = trim((string) ($_GET['document_id'] ?? ''));
        $sourceReference = trim((string) ($_GET['source_reference'] ?? ''));
        $limit = max(1, min(250, (int) ($_GET['limit'] ?? 50)));

        $records = integrationFetchRecords(
            $db,
            $direction,
            $departmentCode,
            $recordTypeCode,
            $status,
            $subjectRef,
            $documentId,
            $sourceReference,
            $limit
        );
        $counts = ['incoming' => 0, 'outgoing' => 0];
        foreach ($records as $record) {
            $counts[$record['direction']]++;
        }

        integrationJson([
            'success' => true,
            'filters' => [
                'direction' => $direction,
                'department' => $departmentCode !== '' ? strtolower($departmentCode) : null,
                'record_type' => $recordTypeCode !== '' ? $recordTypeCode : null,
                'status' => $status !== '' ? $status : null,
                'subject_ref' => $subjectRef !== '' ? $subjectRef : null,
                'document_id' => $documentId !== '' ? $documentId : null,
                'source_reference' => $sourceReference !== '' ? $sourceReference : null,
                'limit' => $limit,
            ],
            'totals' => [
                'records' => count($records),
                'incoming' => $counts['incoming'],
                'outgoing' => $counts['outgoing'],
            ],
            'records' => $records,
        ]);
    }

    if ($method !== 'POST') {
        integrationJson(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $access = integrationRequireAccess(false, true);
    $input = integrationInput();
    $action = strtolower(trim((string) ($input['action'] ?? 'dispatch_record')));

    if ($action === 'dispatch_record') {
        $document = integrationDispatchRecord($db, $input, $access);
        integrationJson([
            'success' => true,
            'message' => 'Integration record dispatched successfully.',
            'document' => $document,
        ], 201);
    }

    if ($action === 'receive_record') {
        $document = integrationUpdateRecordStatus($db, $input, $access, 'received');
        integrationJson([
            'success' => true,
            'message' => 'Integration record marked as received.',
            'document' => $document,
        ]);
    }

    if ($action === 'acknowledge_record') {
        $document = integrationUpdateRecordStatus($db, $input, $access, 'acknowledged');
        integrationJson([
            'success' => true,
            'message' => 'Integration record acknowledged successfully.',
            'document' => $document,
        ]);
    }

    if ($action === 'archive_record' || $action === 'update_status') {
        $document = integrationUpdateRecordStatus($db, $input, $access, $action === 'archive_record' ? 'archived' : (string) ($input['status'] ?? ''));
        integrationJson([
            'success' => true,
            'message' => 'Integration record updated successfully.',
            'document' => $document,
        ]);
    }

    if ($action === 'pmed_verify_report') {
        if (!integrationCanActAsPmed($access)) {
            integrationJson(['success' => false, 'message' => 'PMED verification requires PMED department access or integration token.'], 403);
        }

        $documentId = trim((string) ($input['document_id'] ?? ''));
        if ($documentId === '') {
            integrationJson(['success' => false, 'message' => 'document_id is required.'], 422);
        }

        $existing = integrationGetWorkflowDocument($db, $documentId);
        if ($existing === null) {
            integrationJson(['success' => false, 'message' => 'Integration document not found.'], 404);
        }
        if (strtoupper((string) $existing['sender_department_code']) !== COMLAB_INTEGRATION_DEPARTMENT_CODE || strtoupper((string) $existing['receiver_department_code']) !== 'PMED') {
            integrationJson(['success' => false, 'message' => 'Only COMLAB to PMED reports can be verified in this flow.'], 422);
        }
        if (!in_array((string) $existing['status'], ['sent', 'received'], true)) {
            integrationJson(['success' => false, 'message' => 'Only sent/received reports can be PMED verified.'], 422);
        }

        $now = gmdate('c');
        $payload = $existing['payload'];
        $workflow = is_array($payload['workflow'] ?? null) ? $payload['workflow'] : [];
        $workflow['stage'] = 'verified_by_pmed';
        $workflow['pmed_verified_at'] = $now;
        $workflow['pmed_verification_notes'] = trim((string) ($input['notes'] ?? ''));
        $payload['workflow'] = array_filter($workflow, static fn($v) => $v !== '');

        $document = integrationUpdateWorkflowStage($db, $documentId, 'acknowledged', $payload);
        integrationAudit($db, $access['user']['user_id'] ?? null, 'PMED Report Verified', "PMED verified COMLAB report {$documentId}.", 'System', $documentId);
        integrationJson(['success' => true, 'message' => 'Report verified by PMED.', 'document' => $document]);
    }

    if ($action === 'pmed_return_report') {
        if (!integrationCanActAsPmed($access)) {
            integrationJson(['success' => false, 'message' => 'PMED return requires PMED department access or integration token.'], 403);
        }

        $documentId = trim((string) ($input['document_id'] ?? ''));
        if ($documentId === '') {
            integrationJson(['success' => false, 'message' => 'document_id is required.'], 422);
        }

        $existing = integrationGetWorkflowDocument($db, $documentId);
        if ($existing === null) {
            integrationJson(['success' => false, 'message' => 'Integration document not found.'], 404);
        }
        if (strtoupper((string) $existing['sender_department_code']) !== COMLAB_INTEGRATION_DEPARTMENT_CODE || strtoupper((string) $existing['receiver_department_code']) !== 'PMED') {
            integrationJson(['success' => false, 'message' => 'Only COMLAB to PMED reports can be returned in this flow.'], 422);
        }
        if (!in_array((string) $existing['status'], ['acknowledged', 'received'], true)) {
            integrationJson(['success' => false, 'message' => 'Only PMED-verified reports can be returned to COMLAB.'], 422);
        }

        $now = gmdate('c');
        $payload = $existing['payload'];
        $workflow = is_array($payload['workflow'] ?? null) ? $payload['workflow'] : [];
        $workflow['stage'] = 'returned_to_comlab';
        $workflow['returned_to_comlab_at'] = $now;
        $workflow['pmed_return_notes'] = trim((string) ($input['notes'] ?? ''));
        $payload['workflow'] = array_filter($workflow, static fn($v) => $v !== '');

        $document = integrationUpdateWorkflowStage($db, $documentId, 'received', $payload);
        integrationAudit($db, $access['user']['user_id'] ?? null, 'PMED Report Returned', "PMED returned COMLAB report {$documentId} for confirmation.", 'System', $documentId);
        integrationJson(['success' => true, 'message' => 'Report returned to COMLAB for confirmation.', 'document' => $document]);
    }

    if ($action === 'comlab_confirm_report') {
        if (!integrationCanActAsComlab($access)) {
            integrationJson(['success' => false, 'message' => 'COMLAB confirmation requires admin access or integration token.'], 403);
        }

        $documentId = trim((string) ($input['document_id'] ?? ''));
        if ($documentId === '') {
            integrationJson(['success' => false, 'message' => 'document_id is required.'], 422);
        }

        $existing = integrationGetWorkflowDocument($db, $documentId);
        if ($existing === null) {
            integrationJson(['success' => false, 'message' => 'Integration document not found.'], 404);
        }
        if (strtoupper((string) $existing['sender_department_code']) !== COMLAB_INTEGRATION_DEPARTMENT_CODE || strtoupper((string) $existing['receiver_department_code']) !== 'PMED') {
            integrationJson(['success' => false, 'message' => 'Only COMLAB to PMED reports can be confirmed in this flow.'], 422);
        }
        $workflow = is_array(($existing['payload']['workflow'] ?? null)) ? $existing['payload']['workflow'] : [];
        if (($workflow['stage'] ?? '') !== 'returned_to_comlab') {
            integrationJson(['success' => false, 'message' => 'Report must be returned by PMED before COMLAB confirmation.'], 422);
        }

        $now = gmdate('c');
        $payload = $existing['payload'];
        $workflow['stage'] = 'confirmed_by_comlab';
        $workflow['comlab_confirmed_at'] = $now;
        $workflow['comlab_confirmation_notes'] = trim((string) ($input['notes'] ?? ''));
        $payload['workflow'] = array_filter($workflow, static fn($v) => $v !== '');

        $document = integrationUpdateWorkflowStage($db, $documentId, 'archived', $payload);
        integrationAudit($db, $access['user']['user_id'] ?? null, 'COMLAB Report Confirmed', "COMLAB confirmed and closed report {$documentId}.", 'System', $documentId);
        integrationJson(['success' => true, 'message' => 'Report confirmed by COMLAB and closed.', 'document' => $document]);
    }

    integrationJson(['success' => false, 'message' => 'Unsupported integration action.'], 422);
} catch (Throwable $e) {
    error_log('[COMLAB Integration Records] ' . $e->getMessage());
    integrationJson(['success' => false, 'message' => 'Unable to process integration records.'], 500);
}
