<?php
/**
 * COMLAB → HR staff request via Supabase PostgREST (same contract as PMED createHrStaffRequest in
 * PMED/src/services/hrStaffRequests.ts). Uses anon/publishable key so rows are visible to the HR app.
 */

declare(strict_types=1);

/** Must match Computer-Laboratory/src/services/hrStaffRequests.ts DEPT_NAME */
const COMLAB_HR_STAFF_DEPT_NAME = 'Computer Laboratory';

/**
 * Resolve Supabase API base URL for PostgREST (no trailing slash).
 */
function comlab_resolve_supabase_rest_base_url(): string
{
    comlabLoadEnv();

    foreach (
        [
            comlabEnv('SUPABASE_URL', ''),
            comlabEnv('VITE_SUPABASE_URL', ''),
        ] as $u
    ) {
        $u = rtrim((string) $u, '/');
        if ($u !== '') {
            return $u;
        }
    }

    $projectId = trim((string) comlabEnv('VITE_SUPABASE_PROJECT_ID', ''));
    if ($projectId !== '' && preg_match('/^[a-z0-9]{15,25}$/i', $projectId) === 1) {
        return 'https://' . strtolower($projectId) . '.supabase.co';
    }

    foreach ([comlabEnv('DATABASE_URL', ''), comlabEnv('SUPABASE_DB_URL', '')] as $dsn) {
        if ($dsn === '') {
            continue;
        }
        $parts = parse_url((string) $dsn);
        if (!is_array($parts)) {
            continue;
        }
        $user = $parts['user'] ?? '';
        if (preg_match('/^postgres\.([a-z0-9]+)$/i', (string) $user, $m) === 1) {
            return 'https://' . strtolower($m[1]) . '.supabase.co';
        }
        $host = $parts['host'] ?? '';
        if (preg_match('/^db\.([a-z0-9]+)\.supabase\.co$/i', (string) $host, $m) === 1) {
            return 'https://' . strtolower($m[1]) . '.supabase.co';
        }
    }

    return '';
}

function comlab_resolve_hr_staff_role_type_from_requested_text(string $requestedRole): string
{
    $t = strtolower(trim($requestedRole));
    if ($t === 'lab_technician' || $t === 'it_staff') {
        return $t;
    }

    if (
        $requestedRole !== ''
        && preg_match('/\b(lab|technician|assistant)\b/i', $requestedRole) === 1
        && preg_match('/IT\s*Support|developer|programmer|specialist/i', $requestedRole) !== 1
    ) {
        return 'lab_technician';
    }

    return 'it_staff';
}

/**
 * @return array{request_reference: string, staff_id: int}
 */
function comlab_push_hr_staff_request_via_supabase_rest_like_pmed(
    int $quantity,
    string $reason,
    string $requestedRole,
    string $comlabIntegrationRef,
    string $integrationDocumentId,
    int $userId
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Enable the PHP curl extension to send HR requests via Supabase (same as PMED).');
    }

    comlabLoadEnv();

    $base = comlab_resolve_supabase_rest_base_url();
    $key = (string) (
        comlabEnv('SUPABASE_ANON_KEY', '')
        ?: comlabEnv('SUPABASE_PUBLISHABLE_KEY', '')
        ?: comlabEnv('VITE_SUPABASE_ANON_KEY', '')
        ?: comlabEnv('VITE_SUPABASE_PUBLISHABLE_KEY', '')
        ?: ''
    );

    if ($base === '' || $key === '') {
        $missing = [];
        if ($base === '') {
            $missing[] = 'SUPABASE_URL (or VITE_SUPABASE_URL, or postgres.<projectref> in DATABASE_URL)';
        }
        if ($key === '') {
            $missing[] = 'SUPABASE_ANON_KEY or VITE_SUPABASE_PUBLISHABLE_KEY (copy from HR .env), or place HR/.env beside the monorepo so it is auto-loaded';
        }
        throw new RuntimeException('Missing: ' . implode('; ', $missing) . '.');
    }

    $roleType = comlab_resolve_hr_staff_role_type_from_requested_text($requestedRole);
    $poolKey = 'HR-REQ-POOL-' . strtoupper($roleType);
    $poolName = ucwords(str_replace('_', ' ', $roleType));

    $directoryBody = [
        'employee_no' => $poolKey,
        'full_name' => 'Open ' . $poolName . ' Hiring Request',
        'role_type' => $roleType,
        'department_name' => COMLAB_HR_STAFF_DEPT_NAME,
        'employment_status' => 'inactive',
        'contact_email' => null,
        'contact_phone' => null,
        'hired_at' => null,
    ];

    $poolLookupPath = 'hr_staff_directory?employee_no=eq.' . rawurlencode($poolKey) . '&select=id&limit=1';

    $staffRows = comlab_supabase_rest_json(
        $base,
        $key,
        'GET',
        $poolLookupPath,
        null,
        ''
    );

    if (!is_array($staffRows) || !isset($staffRows[0]['id'])) {
        try {
            comlab_supabase_rest(
                $base,
                $key,
                'POST',
                'hr_staff_directory',
                $directoryBody,
                'return=representation'
            );
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, ' 409') === false && strpos($msg, '23505') === false) {
                throw $e;
            }
        }

        $staffRows = comlab_supabase_rest_json(
            $base,
            $key,
            'GET',
            $poolLookupPath,
            null,
            ''
        );
    }

    if (!is_array($staffRows) || !isset($staffRows[0]['id'])) {
        throw new RuntimeException('Failed to resolve hr_staff_directory pool row (' . $poolKey . ').');
    }

    $staffId = (int) $staffRows[0]['id'];

    $year = (int) gmdate('Y');
    $hrRef = 'HR-REQ-' . $year . '-' . random_int(10000, 99999);

    $rl = strtolower(trim($requestedRole));
    $roleHuman = [
        'it_staff' => 'IT Staff',
        'lab_technician' => 'Lab Technician',
    ][$rl] ?? ($requestedRole !== '' ? $requestedRole : '');
    $positionLine = $roleHuman !== ''
        ? $roleHuman
        : 'IT Support Specialist (Computer Laboratory / IT Services)';

    $notesParts = [
        $reason,
        'Requested count: ' . max(1, $quantity),
        'COMLAB ref: ' . $comlabIntegrationRef,
        'Integration document: ' . $integrationDocumentId,
        'Position / role: ' . $positionLine,
    ];
    $notes = implode(' | ', array_filter($notesParts, static fn ($p) => $p !== ''));

    $metadata = [
        'source' => 'comlab_scheduling_request_employee',
        'integration_document_id' => $integrationDocumentId,
        'request_ref' => $comlabIntegrationRef,
        'requested_role_text' => $requestedRole,
    ];

    $requestBody = [
        'request_reference' => $hrRef,
        'staff_id' => $staffId,
        'request_status' => 'pending',
        'request_notes' => $notes,
        'requested_by' => 'COMLAB Scheduling (user_id ' . $userId . ')',
        'metadata' => $metadata,
    ];

    comlab_supabase_rest(
        $base,
        $key,
        'POST',
        'hr_staff_requests',
        $requestBody,
        'return=representation'
    );

    return ['request_reference' => $hrRef, 'staff_id' => $staffId];
}

/**
 * @return mixed decoded JSON (typically array) for GET; array from POST when representation returned
 */
function comlab_supabase_rest_json(
    string $base,
    string $key,
    string $method,
    string $pathQuery,
    ?array $body,
    string $prefer
): mixed {
    $raw = comlab_supabase_rest($base, $key, $method, $pathQuery, $body, $prefer);
    if ($raw === '' || $raw === 'null') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON from Supabase: ' . json_last_error_msg());
    }

    return $decoded;
}

function comlab_supabase_rest(
    string $base,
    string $key,
    string $method,
    string $pathQuery,
    ?array $body,
    string $prefer
): string {
    $url = $base . '/rest/v1/' . ltrim($pathQuery, '/');

    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
    ];
    if ($prefer !== '') {
        $headers[] = 'Prefer: ' . $prefer;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed.');
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 30,
    ];

    if ($body !== null && in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('Supabase HTTP error: ' . $err);
    }

    if ($response === false) {
        throw new RuntimeException('Empty response from Supabase.');
    }

    if ($status >= 400) {
        throw new RuntimeException('Supabase REST ' . $status . ': ' . $response);
    }

    return (string) $response;
}
