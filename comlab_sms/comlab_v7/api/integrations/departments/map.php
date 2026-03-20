<?php

require_once __DIR__ . '/../_helpers.php';

integrationBootstrap();

if (integrationMethod() !== 'GET') {
    integrationJson(['success' => false, 'message' => 'Method not allowed.'], 405);
}

integrationRequireAccess(true, false);

try {
    $db = getDB();
    $filterDepartment = integrationNormalizeDepartmentCode($_GET['department'] ?? $_GET['department_key'] ?? '');
    $self = integrationDepartmentByCode($db, COMLAB_INTEGRATION_DEPARTMENT_CODE);
    if ($self === null) {
        integrationJson(['success' => false, 'message' => 'COMLAB department profile is not configured.'], 500);
    }

    $routes = $db->prepare(
        'SELECT rt.route_id, rt.flow_order, rt.notes, rt.is_active,
                sd.department_code AS sender_department_code, sd.department_name AS sender_department_name,
                rd.department_code AS receiver_department_code, rd.department_name AS receiver_department_name,
                irt.record_type_code, irt.record_type_name, irt.data_domain
         FROM integration_routes rt
         JOIN departments sd ON sd.department_id = rt.sender_department_id
         JOIN departments rd ON rd.department_id = rt.receiver_department_id
         JOIN integration_record_types irt ON irt.record_type_id = rt.record_type_id
         WHERE rt.is_active = 1
           AND (UPPER(sd.department_code) = ? OR UPPER(rd.department_code) = ?)
         ORDER BY rt.flow_order, irt.record_type_name'
    );
    $routes->execute([COMLAB_INTEGRATION_DEPARTMENT_CODE, COMLAB_INTEGRATION_DEPARTMENT_CODE]);
    $routeRows = $routes->fetchAll();

    $departments = $db->query(
        'SELECT department_id, department_code, department_name, description, is_active
         FROM departments
         WHERE is_active = 1
         ORDER BY department_name'
    )->fetchAll();

    $incoming = [];
    $outgoing = [];
    $connections = [];

    foreach ($routeRows as $row) {
        $isOutbound = strtoupper((string) $row['sender_department_code']) === COMLAB_INTEGRATION_DEPARTMENT_CODE;
        $peerCode = $isOutbound ? (string) $row['receiver_department_code'] : (string) $row['sender_department_code'];
        if ($filterDepartment !== '' && strtoupper($peerCode) !== $filterDepartment) {
            continue;
        }

        $direction = $isOutbound ? 'outgoing' : 'incoming';
        $peerName = $isOutbound ? (string) $row['receiver_department_name'] : (string) $row['sender_department_name'];
        $entry = [
            'route_id' => (int) $row['route_id'],
            'flow_order' => (int) $row['flow_order'],
            'direction' => $direction,
            'department' => [
                'code' => $peerCode,
                'key' => strtolower($peerCode),
                'name' => $peerName,
            ],
            'record_type' => [
                'code' => (string) $row['record_type_code'],
                'name' => (string) $row['record_type_name'],
                'domain' => (string) $row['data_domain'],
            ],
            'notes' => (string) ($row['notes'] ?? ''),
        ];

        if ($isOutbound) {
            $outgoing[] = $entry;
        } else {
            $incoming[] = $entry;
        }

        $connectionKey = strtoupper($peerCode);
        if (!isset($connections[$connectionKey])) {
            $connections[$connectionKey] = [
                'department' => [
                    'code' => $peerCode,
                    'key' => strtolower($peerCode),
                    'name' => $peerName,
                ],
                'incoming' => [],
                'outgoing' => [],
            ];
        }
        $connections[$connectionKey][$direction][] = $entry['record_type'];
    }

    integrationJson([
        'success' => true,
        'department' => integrationFormatDepartment($self),
        'endpoints' => integrationEndpointUrls(),
        'supported_methods' => [
            'map' => ['GET'],
            'records' => ['GET', 'POST'],
            'report' => ['GET', 'POST'],
        ],
        'auth' => [
            'map_public' => true,
            'records_and_report_require_admin_or_token' => true,
            'token_header' => 'X-Integration-Token',
        ],
        'summary' => [
            'incoming_routes' => count($incoming),
            'outgoing_routes' => count($outgoing),
            'connected_departments' => count($connections),
        ],
        'connected_departments' => array_values($connections),
        'incoming' => array_values($incoming),
        'outgoing' => array_values($outgoing),
        'directory' => array_map('integrationFormatDepartment', $departments),
    ]);
} catch (Throwable $e) {
    error_log('[COMLAB Integration Map] ' . $e->getMessage());
    integrationJson(['success' => false, 'message' => 'Unable to load integration map.'], 500);
}
