<?php
// COMLAB - Faculty Schedules API
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/supabase_hr_staff_request.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');

$user = requireAuth('scheduling');
$role = $user['role'];
$uid  = $user['user_id'];
$ip   = $_SERVER['REMOTE_ADDR'] ?? null;
$ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

define('CHECKIN_BEFORE_MIN', 15);
define('CHECKIN_AFTER_MIN', 30);
define('HR_SCHEDULE_RECORD_TYPE', 'faculty_schedule_assignments');

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list';

        if (!empty($_GET['export'])) {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="schedules_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Faculty', 'Lab', 'Class', 'Days', 'Start', 'End', 'Semester Start', 'Semester End', 'Dept', 'Status']);
            $rows = $db->query(
                "SELECT fs.schedule_id,
                        CONCAT(u.first_name, ' ', u.last_name),
                        l.lab_name, fs.class_name, fs.day_of_week,
                        fs.start_time, fs.end_time,
                        fs.semester_start, fs.semester_end,
                        fs.department, fs.is_active
                 FROM faculty_schedules fs
                 JOIN users u ON fs.faculty_id = u.user_id
                 JOIN locations l ON fs.location_id = l.location_id
                 ORDER BY fs.semester_start DESC, u.last_name"
            )->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
            exit;
        }

        if ($action === 'today') {
            $dayName = date('l');
            $today   = date('Y-m-d');
            $nowTime = date('H:i:s');
            $dayExpr = sqlCsvContainsDay('fs.day_of_week');

            $params = [$today, $today, $dayName];
            $userFilter = '';
            if ($role === ROLE_FACULTY) {
                $userFilter = 'AND fs.faculty_id = ?';
                $params[]   = $uid;
            }

            $stmt = $db->prepare(
                "SELECT fs.schedule_id, fs.class_name, fs.day_of_week,
                        fs.start_time, fs.end_time, fs.department, fs.notes,
                        l.lab_name, l.lab_code,
                        CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                        fs.faculty_id,
                        sa.status AS attendance_status,
                        sa.checked_in_at,
                        sa.attendance_id
                 FROM faculty_schedules fs
                 JOIN locations l ON fs.location_id = l.location_id
                 JOIN users u ON fs.faculty_id = u.user_id
                 LEFT JOIN schedule_attendance sa
                   ON sa.schedule_id = fs.schedule_id AND sa.attendance_date = ?
                 WHERE fs.is_active = 1
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND $dayExpr
                   $userFilter
                 ORDER BY fs.start_time"
            );
            $orderedParams = [$today, $today, $today, $dayName];
            if ($role === ROLE_FACULTY) {
                $orderedParams[] = $uid;
            }
            $stmt->execute($orderedParams);
            $schedules = $stmt->fetchAll();

            foreach ($schedules as &$s) {
                $startTs = strtotime($today . ' ' . $s['start_time']);
                $windowOpen  = date('H:i:s', $startTs - CHECKIN_BEFORE_MIN * 60);
                $windowClose = date('H:i:s', $startTs + CHECKIN_AFTER_MIN * 60);
                $s['checkin_window_open']  = $windowOpen;
                $s['checkin_window_close'] = $windowClose;
                $s['can_checkin'] = ($role === ROLE_FACULTY)
                    && !$s['attendance_status']
                    && $nowTime >= $windowOpen
                    && $nowTime <= $windowClose;
            }
            unset($s);

            echo json_encode(['success' => true, 'date' => $today, 'schedules' => $schedules]);
            exit;
        }

        if ($action === 'attendance') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $schedId = (int) ($_GET['schedule_id'] ?? 0);
            if (!$schedId) {
                echo json_encode(['success' => false, 'message' => 'schedule_id required.']);
                exit;
            }

            $stmt = $db->prepare(
                "SELECT sa.attendance_id, sa.attendance_date, sa.status,
                        sa.checked_in_at, sa.marked_by_system,
                        CONCAT(u.first_name, ' ', u.last_name) AS faculty_name
                 FROM schedule_attendance sa
                 JOIN faculty_schedules fs ON sa.schedule_id = fs.schedule_id
                 JOIN users u ON fs.faculty_id = u.user_id
                 WHERE sa.schedule_id = ?
                 ORDER BY sa.attendance_date DESC"
            );
            $stmt->execute([$schedId]);

            $summary = $db->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present,
                        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                        SUM(CASE WHEN status = 'Excused' THEN 1 ELSE 0 END) AS excused
                 FROM schedule_attendance
                 WHERE schedule_id = ?"
            );
            $summary->execute([$schedId]);

            echo json_encode([
                'success'    => true,
                'attendance' => $stmt->fetchAll(),
                'summary'    => $summary->fetch(),
            ]);
            exit;
        }

        if ($action === 'check') {
            $locId    = (int) ($_GET['location_id'] ?? 0);
            $days     = trim($_GET['day_of_week'] ?? '');
            $start    = $_GET['start_time'] ?? '';
            $end      = $_GET['end_time'] ?? '';
            $semStart = $_GET['semester_start'] ?? '';
            $semEnd   = $_GET['semester_end'] ?? '';
            $exclude  = (int) ($_GET['exclude_id'] ?? 0);

            if (!$locId || !$days || !$start || !$end || !$semStart || !$semEnd) {
                echo json_encode(['success' => false, 'conflict' => false, 'message' => 'Missing params.']);
                exit;
            }

            $dayList = array_map('trim', explode(',', $days));
            $dayChecks = implode(' OR ', array_fill(0, count($dayList), sqlCsvContainsDay('fs.day_of_week')));
            $params = $dayList;
            $params[] = $locId;
            $params[] = $semEnd;
            $params[] = $semStart;
            $params[] = $end;
            $params[] = $start;

            $excludeClause = $exclude ? 'AND fs.schedule_id <> ?' : '';
            if ($exclude) {
                $params[] = $exclude;
            }

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM faculty_schedules fs
                 WHERE ($dayChecks)
                   AND fs.location_id = ?
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND fs.start_time < ?
                   AND fs.end_time > ?
                   AND fs.is_active = 1
                   $excludeClause"
            );
            $stmt->execute($params);
            echo json_encode(['success' => true, 'conflict' => (bool) $stmt->fetchColumn()]);
            exit;
        }

        if ($action === 'hr_status') {
            $status = latestHrScheduleDocument($db);
            $lastRequest = latestHrScheduleSyncRequest($db);
            $lastEmployeeRequest = latestHrEmployeeRequest($db);
            echo json_encode([
                'success' => true,
                'hr_feed' => $status,
                'last_request' => $lastRequest,
                'last_employee_request' => $lastEmployeeRequest,
            ]);
            exit;
        }

        $params = [];
        $userFilter = '';
        if ($role === ROLE_FACULTY) {
            $userFilter = 'WHERE fs.faculty_id = ?';
            $params[] = $uid;
        }

        $stmt = $db->prepare(
            "SELECT fs.schedule_id, fs.class_name, fs.day_of_week,
                    fs.start_time, fs.end_time, fs.department, fs.notes,
                    fs.semester_start, fs.semester_end, fs.is_active,
                    fs.faculty_id,
                    l.lab_name, l.lab_code, l.capacity,
                    l.operating_hours_start, l.operating_hours_end,
                    CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                    u.department AS faculty_dept,
                    CONCAT(a.first_name, ' ', a.last_name) AS assigned_by_name,
                    (SELECT COUNT(*) FROM schedule_attendance sa WHERE sa.schedule_id = fs.schedule_id) AS total_sessions,
                    (SELECT SUM(CASE WHEN sa.status = 'Present' THEN 1 ELSE 0 END) FROM schedule_attendance sa WHERE sa.schedule_id = fs.schedule_id) AS present_count
             FROM faculty_schedules fs
             JOIN locations l ON fs.location_id = l.location_id
             JOIN users u ON fs.faculty_id = u.user_id
             JOIN users a ON fs.assigned_by = a.user_id
             $userFilter
             ORDER BY fs.is_active DESC, fs.semester_start DESC, u.last_name"
        );
        $stmt->execute($params);
        echo json_encode(['success' => true, 'schedules' => $stmt->fetchAll()]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF.']);
            exit;
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $facultyId = (int) ($_POST['faculty_id'] ?? 0);
            $locId     = (int) ($_POST['location_id'] ?? 0);
            $className = trim($_POST['class_name'] ?? '');
            $days      = trim($_POST['day_of_week'] ?? '');
            $start     = $_POST['start_time'] ?? '';
            $end       = $_POST['end_time'] ?? '';
            $semStart  = $_POST['semester_start'] ?? '';
            $semEnd    = $_POST['semester_end'] ?? '';
            $dept      = trim($_POST['department'] ?? '');
            $notes     = trim($_POST['notes'] ?? '');

            if (!$facultyId || !$locId || !$className || !$days || !$start || !$end || !$semStart || !$semEnd || !$dept) {
                echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
                exit;
            }

            $facCheck = $db->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'Faculty' AND is_active = 1");
            $facCheck->execute([$facultyId]);
            if (!$facCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Invalid or inactive faculty member.']);
                exit;
            }

            if ($start >= $end) {
                echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
                exit;
            }
            if ($semStart >= $semEnd) {
                echo json_encode(['success' => false, 'message' => 'Semester end must be after semester start.']);
                exit;
            }

            $labStmt = $db->prepare(
                'SELECT capacity, operating_hours_start, operating_hours_end
                 FROM locations
                 WHERE location_id = ? AND is_active = 1'
            );
            $labStmt->execute([$locId]);
            $lab = $labStmt->fetch();
            if (!$lab) {
                echo json_encode(['success' => false, 'message' => 'Lab not found or inactive.']);
                exit;
            }

            $opS = substr($lab['operating_hours_start'], 0, 5);
            $opE = substr($lab['operating_hours_end'], 0, 5);
            if (substr($start, 0, 5) < $opS || substr($end, 0, 5) > $opE) {
                echo json_encode(['success' => false, 'message' => "Lab operates {$opS}-{$opE}. Your time ({$start}-{$end}) falls outside."]);
                exit;
            }

            [$sh, $sm] = array_map('intval', explode(':', $start));
            [$eh, $em] = array_map('intval', explode(':', $end));
            $dur = round((($eh * 60 + $em) - ($sh * 60 + $sm)) / 60, 2);

            $dayList   = array_map('trim', explode(',', $days));
            $dayChecks = implode(' OR ', array_fill(0, count($dayList), sqlCsvContainsDay('fs.day_of_week')));
            $cParams   = $dayList;
            $cParams[] = $locId;
            $cParams[] = $semEnd;
            $cParams[] = $semStart;
            $cParams[] = $end;
            $cParams[] = $start;

            $chk = $db->prepare(
                "SELECT COUNT(*) FROM faculty_schedules fs
                 WHERE ($dayChecks) AND fs.location_id = ?
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND fs.start_time < ?
                   AND fs.end_time > ?
                   AND fs.is_active = 1"
            );
            $chk->execute($cParams);
            if ($chk->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Scheduling conflict: another class is assigned to this lab at overlapping days/times.']);
                exit;
            }

            $newId = insertReturningId(
                $db,
                'INSERT INTO faculty_schedules
                 (faculty_id, assigned_by, location_id, class_name, day_of_week,
                  start_time, end_time, duration_hours, semester_start, semester_end, department, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [$facultyId, $uid, $locId, $className, $days, $start, $end, $dur, $semStart, $semEnd, $dept, $notes],
                'schedule_id'
            );

            auditLog($db, $uid, 'Assignment Created', 'Assignment', (int) $newId,
                "Faculty schedule #{$newId} created: {$className} on {$days} {$start}-{$end} by admin {$uid}.", $ip, $ua);

            echo json_encode(['success' => true, 'schedule_id' => $newId, 'message' => "Schedule created for {$className} on {$days}."]);
            exit;
        }

        if ($action === 'sync_hr') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            // Attempt to find integration document first
            $document = latestHrScheduleDocument($db);
            
            $schedules = [];
            if ($document) {
                $payload = $document['payload'];
                if (is_array($payload) && isset($payload['schedules']) && is_array($payload['schedules'])) {
                    $schedules = $payload['schedules'];
                }
            }

            // If no integration document, fallback to direct Registrar schema query
            if ($schedules === []) {
                $registrarSchema = comlabEnv('REGISTRAR_DB_SCHEMA', 'registrar');
                try {
                    $stmt = $db->prepare("
                        SELECT 
                            i.employee_no as hr_employee_id,
                            i.first_name,
                            i.last_name,
                            c.title as class_name,
                            s.day as day_of_week,
                            s.time as time_range,
                            s.room as lab_code,
                            i.department
                        FROM $registrarSchema.instructor_class_assignments ica
                        JOIN $registrarSchema.instructors i ON ica.instructor_employee_no = i.employee_no
                        JOIN $registrarSchema.classes c ON ica.class_id = c.id
                        JOIN $registrarSchema.schedules s ON s.class_id = c.id
                        WHERE s.room LIKE 'Lab%' OR s.room LIKE 'Computer Lab%'
                    ");
                    $stmt->execute();
                    $rawSchedules = $stmt->fetchAll();

                    foreach ($rawSchedules as $rs) {
                        // Parse "08:00 AM - 10:00 AM"
                        $parts = explode(' - ', $rs['time_range']);
                        if (count($parts) !== 2) continue;
                        
                        $start = date('H:i:s', strtotime($parts[0]));
                        $end = date('H:i:s', strtotime($parts[1]));

                        // Map Registrar rooms to COMLAB lab codes
                        $roomMap = [
                            'Lab 1' => 'LAB-A',
                            'Lab 2' => 'LAB-B',
                            'Lab 3' => 'LAB-C',
                            'Computer Lab A' => 'LAB-A',
                            'Computer Lab B' => 'LAB-B',
                            'Computer Lab C' => 'LAB-C'
                        ];
                        $labCode = $roomMap[$rs['lab_code']] ?? $rs['lab_code'];

                        $schedules[] = [
                            'hr_employee_id' => $rs['hr_employee_id'],
                            'class_name' => $rs['class_name'],
                            'day_of_week' => $rs['day_of_week'],
                            'start_time' => $start,
                            'end_time' => $end,
                            'lab_code' => $labCode,
                            'department' => $rs['department'],
                            'semester_start' => date('Y-01-01'), // Default to current year
                            'semester_end' => date('Y-06-30'),
                            'source_reference' => 'REGISTRAR-DIRECT'
                        ];
                    }
                } catch (Exception $e) {
                    error_log('[COMLAB Scheduling Sync] Registrar fallback failed: ' . $e->getMessage());
                }
            }

            if ($schedules === []) {
                echo json_encode(['success' => false, 'message' => 'No HR schedule feed or Registrar data was found for COMLAB.']);
                exit;
            }

            $created = 0;
            $updated = 0;
            $skipped = [];
            $documentSourceRef = is_array($document) ? trim((string) ($document['source_reference'] ?? '')) : '';
            // ... (rest of the sync logic remains same)

            foreach ($schedules as $index => $item) {
                if (!is_array($item)) {
                    $skipped[] = 'Row ' . ($index + 1) . ' is not a valid schedule object.';
                    continue;
                }

                $facultyId = resolveFacultyIdFromHrPayload($db, $item);
                $locationId = resolveLocationIdFromHrPayload($db, $item);
                $className = trim((string) ($item['class_name'] ?? ''));
                $days = trim((string) ($item['day_of_week'] ?? ''));
                $start = trim((string) ($item['start_time'] ?? ''));
                $end = trim((string) ($item['end_time'] ?? ''));
                $semesterStart = trim((string) ($item['semester_start'] ?? ''));
                $semesterEnd = trim((string) ($item['semester_end'] ?? ''));
                $department = trim((string) ($item['department'] ?? ''));
                $notes = trim((string) ($item['notes'] ?? ''));
                $fallbackReference = $documentSourceRef !== '' ? ($documentSourceRef . ':' . ($index + 1)) : ('HR-SCHEDULE:' . ($index + 1));
                $sourceReference = trim((string) ($item['source_reference'] ?? $fallbackReference));

                if (!$facultyId || !$locationId || $className === '' || $days === '' || $start === '' || $end === '' || $semesterStart === '' || $semesterEnd === '' || $department === '') {
                    $skipped[] = 'Row ' . ($index + 1) . ' is missing a faculty match, lab match, or a required schedule field.';
                    continue;
                }

                if ($start >= $end || $semesterStart >= $semesterEnd) {
                    $skipped[] = 'Row ' . ($index + 1) . ' has an invalid time or semester range.';
                    continue;
                }

                [$sh, $sm] = array_map('intval', explode(':', substr($start, 0, 5)));
                [$eh, $em] = array_map('intval', explode(':', substr($end, 0, 5)));
                $duration = round((($eh * 60 + $em) - ($sh * 60 + $sm)) / 60, 2);

                $existing = $db->prepare(
                    'SELECT schedule_id
                     FROM faculty_schedules
                     WHERE faculty_id = ?
                       AND class_name = ?
                       AND semester_start = ?'
                );
                $existing->execute([$facultyId, $className, $semesterStart]);
                $existingId = $existing->fetchColumn();

                if ($existingId) {
                    $db->prepare(
                        'UPDATE faculty_schedules
                         SET location_id = ?,
                             day_of_week = ?,
                             start_time = ?,
                             end_time = ?,
                             duration_hours = ?,
                             semester_end = ?,
                             department = ?,
                             notes = ?,
                             source_system = ?,
                             source_reference = ?,
                             synced_from_hr = 1,
                             is_active = 1,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE schedule_id = ?'
                    )->execute([
                        $locationId,
                        $days,
                        $start,
                        $end,
                        $duration,
                        $semesterEnd,
                        $department,
                        $notes,
                        'HR',
                        $sourceReference,
                        $existingId,
                    ]);
                    $updated++;
                    continue;
                }

                insertReturningId(
                    $db,
                    'INSERT INTO faculty_schedules
                     (faculty_id, assigned_by, location_id, class_name, day_of_week,
                      start_time, end_time, duration_hours, semester_start, semester_end,
                      department, notes, source_system, source_reference, synced_from_hr, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)',
                    [
                        $facultyId,
                        $uid,
                        $locationId,
                        $className,
                        $days,
                        $start,
                        $end,
                        $duration,
                        $semesterStart,
                        $semesterEnd,
                        $department,
                        $notes,
                        'HR',
                        $sourceReference,
                    ],
                    'schedule_id'
                );
                $created++;
            }

            auditLog(
                $db,
                $uid,
                'HR Schedule Sync',
                'System',
                null,
                "HR schedule feed synced from document {$document['document_id']}. Created {$created}, updated {$updated}, skipped " . count($skipped) . '.',
                $ip,
                $ua
            );

            echo json_encode([
                'success' => true,
                'message' => "HR schedule sync completed. Created {$created}, updated {$updated}, skipped " . count($skipped) . '.',
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'document' => $document,
            ]);
            exit;
        }

        if ($action === 'request_hr_sync') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($reason === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Reason is required when requesting HR sync.',
                ]);
                exit;
            }

            $route = resolveHrSyncRequestRoute($db);
            if (!$route) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No active COMLAB to HR integration route for faculty schedule sync requests. Configure integration_routes first.',
                ]);
                exit;
            }

            $requestRef = 'COMLAB-HR-SYNC-' . gmdate('YmdHis');
            $payload = [
                'workflow_action' => 'request_hr_schedule_sync',
                'requested_by' => [
                    'user_id' => $uid,
                    'role' => $role,
                    'department' => 'COMLAB',
                ],
                'requested_at' => gmdate('c'),
                'request_ref' => $requestRef,
                'requested_record_type' => HR_SCHEDULE_RECORD_TYPE,
                'reason' => $reason,
                'notes' => 'COMLAB requested an updated faculty schedule package from HR.',
            ];

            $stmt = $db->prepare(
                'INSERT INTO integration_documents
                 (route_id, record_type_id, sender_department_id, receiver_department_id,
                  subject_type, subject_ref, title, source_system, source_reference, status,
                  payload, sent_at, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?, ?)
                 RETURNING document_id'
            );
            $stmt->execute([
                $route['route_id'],
                $route['record_type_id'],
                $route['sender_department_id'],
                $route['receiver_department_id'],
                'system',
                $requestRef,
                'COMLAB Request: HR Faculty Schedule Sync',
                'COMLAB',
                $requestRef,
                'sent',
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                gmdate('c'),
                $uid,
            ]);
            $documentId = (string) $stmt->fetchColumn();

            auditLog(
                $db,
                $uid,
                'HR Schedule Sync Requested',
                'System',
                null,
                "COMLAB requested HR schedule sync. Document {$documentId}, reference {$requestRef}. Reason: {$reason}",
                $ip,
                $ua
            );

            echo json_encode([
                'success' => true,
                'message' => 'HR sync request sent. HR can now dispatch an updated schedule package.',
                'document_id' => $documentId,
                'request_reference' => $requestRef,
            ]);
            exit;
        }

        if ($action === 'request_employee') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $reason = trim((string) ($_POST['request_notes'] ?? $_POST['reason'] ?? ''));
            $requestedRole = trim((string) ($_POST['requested_role'] ?? ''));
            $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
            $requestedByLabel = trim((string) ($_POST['requested_by'] ?? ''));
            if ($requestedByLabel === '') {
                $requestedByLabel = 'COMLAB Admin';
            }
            if ($reason === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Request notes are required when requesting employee support from HR.',
                ]);
                exit;
            }

            $route = resolveHrEmployeeRequestRoute($db);
            if (!$route) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unable to resolve COMLAB/HR integration metadata for employee request. Check departments and integration record types.',
                ]);
                exit;
            }

            $requestRef = 'COMLAB-EMP-REQ-' . gmdate('YmdHis');
            $payload = [
                'workflow_action' => 'request_employee_support',
                'requested_by' => [
                    'user_id' => $uid,
                    'role' => $role,
                    'department' => 'COMLAB',
                    'display_name' => $requestedByLabel,
                ],
                'requested_at' => gmdate('c'),
                'request_ref' => $requestRef,
                'reason' => $reason,
                'requested_role' => $requestedRole,
                'quantity' => $quantity,
                'notes' => 'COMLAB requested employee support due to manpower needs.',
                'requested_by_label' => $requestedByLabel,
            ];

            /** @var string|false $documentId */
            $documentId = false;
            try {
                $db->beginTransaction();

                $stmt = $db->prepare(
                    'INSERT INTO integration_documents
                     (route_id, record_type_id, sender_department_id, receiver_department_id,
                      subject_type, subject_ref, title, source_system, source_reference, status,
                      payload, sent_at, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?, ?)
                     RETURNING document_id'
                );
                $stmt->execute([
                    $route['route_id'] ?: null,
                    $route['record_type_id'],
                    $route['sender_department_id'],
                    $route['receiver_department_id'],
                    'staff',
                    $requestRef,
                    'COMLAB Request: Employee Support from HR',
                    'COMLAB',
                    $requestRef,
                    'sent',
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    gmdate('c'),
                    $uid,
                ]);
                $documentId = $stmt->fetchColumn();
                if ($documentId === false) {
                    throw new RuntimeException('integration_documents insert did not return document_id.');
                }
                $documentId = (string) $documentId;

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('[COMLAB Scheduling API] request_employee integration_documents failed: ' . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'Could not save the COMLAB integration record for this request.',
                ]);
                exit;
            }

            // Same path as PMED createHrStaffRequest: Supabase PostgREST + anon key → public.hr_staff_* (HR Job Postings).
            $hrStaffRef = null;
            try {
                $reasonForHr = $reason;
                if ($requestedByLabel !== '') {
                    $reasonForHr .= ' | Requested by: ' . $requestedByLabel;
                }

                $hrPush = comlab_push_hr_staff_request_via_supabase_rest_like_pmed(
                    $quantity,
                    $reasonForHr,
                    $requestedRole,
                    $requestRef,
                    $documentId,
                    $uid
                );
                $hrStaffRef = $hrPush['request_reference'] ?? null;
            } catch (Throwable $e) {
                error_log('[COMLAB Scheduling API] request_employee Supabase HR sync failed: ' . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'COMLAB saved the request locally, but HR did not receive it. ' . $e->getMessage(),
                    'document_id' => $documentId,
                    'request_reference' => $requestRef,
                ]);
                exit;
            }

            auditLog(
                $db,
                $uid,
                'HR Employee Requested',
                'System',
                null,
                "COMLAB requested employee support from HR. Document {$documentId}, reference {$requestRef}, qty {$quantity}. Reason: {$reason}",
                $ip,
                $ua
            );

            echo json_encode([
                'success' => true,
                'message' => 'Employee request sent to HR successfully.',
                'document_id' => $documentId,
                'request_reference' => $requestRef,
                'hr_staff_request_reference' => $hrStaffRef,
            ]);
            exit;
        }

        if ($action === 'cancel') {
            if ($role !== ROLE_ADMIN) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin only.']);
                exit;
            }

            $schedId = (int) ($_POST['schedule_id'] ?? 0);
            $chk = $db->prepare('SELECT schedule_id, class_name FROM faculty_schedules WHERE schedule_id = ? AND is_active = 1');
            $chk->execute([$schedId]);
            $sched = $chk->fetch();
            if (!$sched) {
                echo json_encode(['success' => false, 'message' => 'Schedule not found or already cancelled.']);
                exit;
            }

            $db->prepare('UPDATE faculty_schedules SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = ?')
               ->execute([$schedId]);

            auditLog($db, $uid, 'Assignment Cancelled', 'Assignment', $schedId,
                "Faculty schedule #{$schedId} ({$sched['class_name']}) cancelled by admin {$uid}.", $ip, $ua);

            echo json_encode(['success' => true, 'message' => 'Schedule cancelled.']);
            exit;
        }

        if ($action === 'checkin') {
            if ($role !== ROLE_FACULTY) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Faculty only.']);
                exit;
            }

            $schedId = (int) ($_POST['schedule_id'] ?? 0);
            $today   = date('Y-m-d');
            $dayName = date('l');
            $nowTime = date('H:i:s');
            $dayExpr = sqlCsvContainsDay('fs.day_of_week');

            $sched = $db->prepare(
                "SELECT fs.schedule_id, fs.start_time, fs.end_time, fs.class_name, fs.faculty_id
                 FROM faculty_schedules fs
                 WHERE fs.schedule_id = ?
                   AND fs.faculty_id = ?
                   AND fs.is_active = 1
                   AND fs.semester_start <= ?
                   AND fs.semester_end >= ?
                   AND $dayExpr"
             );
            $sched->execute([$schedId, $uid, $today, $today, $dayName]);
            $s = $sched->fetch();
            if (!$s) {
                echo json_encode(['success' => false, 'message' => 'No matching active schedule found for today.']);
                exit;
            }

            $startTs     = strtotime($today . ' ' . $s['start_time']);
            $windowOpen  = date('H:i:s', $startTs - CHECKIN_BEFORE_MIN * 60);
            $windowClose = date('H:i:s', $startTs + CHECKIN_AFTER_MIN * 60);

            if ($nowTime < $windowOpen) {
                echo json_encode(['success' => false, 'message' => 'Check-in window has not opened yet. Opens at ' . substr($windowOpen, 0, 5) . '.']);
                exit;
            }
            if ($nowTime > $windowClose) {
                echo json_encode(['success' => false, 'message' => 'Check-in window has closed. You have been marked Absent for this session.']);
                exit;
            }

            $dup = $db->prepare(
                "SELECT attendance_id FROM schedule_attendance
                 WHERE schedule_id = ? AND attendance_date = ? AND status = 'Present'"
            );
            $dup->execute([$schedId, $today]);
            if ($dup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You have already checked in for this session.']);
                exit;
            }

            $db->prepare(
                'INSERT INTO schedule_attendance
                 (schedule_id, faculty_id, attendance_date, status, checked_in_at, marked_by_system)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, 0)
                 ON CONFLICT (schedule_id, attendance_date) DO UPDATE
                 SET status = EXCLUDED.status,
                     checked_in_at = EXCLUDED.checked_in_at,
                     marked_by_system = EXCLUDED.marked_by_system'
            )->execute([$schedId, $uid, $today, 'Present']);

            auditLog($db, $uid, 'Other', 'Assignment', $schedId,
                "Faculty {$uid} checked in for schedule #{$schedId} ({$s['class_name']}) on {$today}.", $ip, $ua);

            echo json_encode(['success' => true, 'message' => 'Check-in recorded. You are marked Present for ' . $s['class_name'] . '.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    error_log('[COMLAB Scheduling API] ' . $e->getMessage());
}

function auditLog(PDO $db, ?int $uid, string $action, ?string $tt, ?int $tid, string $desc,
                  ?string $ip = null, ?string $ua = null): void {
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

function latestHrScheduleDocument(PDO $db): ?array {
    $stmt = $db->prepare(
        "SELECT d.document_id, d.source_reference, d.sent_at, d.received_at, d.acknowledged_at, d.created_at, d.payload
         FROM integration_documents d
         JOIN integration_record_types rt ON rt.record_type_id = d.record_type_id
         JOIN departments sd ON sd.department_id = d.sender_department_id
         JOIN departments rd ON rd.department_id = d.receiver_department_id
         WHERE sd.department_code = 'HR'
           AND rd.department_code = 'COMLAB'
           AND (
                rt.record_type_code = ?
                OR CAST(d.payload AS text) ILIKE '%\"schedules\"%'
           )
         ORDER BY COALESCE(d.sent_at, d.created_at) DESC
         LIMIT 1"
    );
    $stmt->execute([HR_SCHEDULE_RECORD_TYPE]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $payload = json_decode((string) $row['payload'], true);
    return [
        'document_id' => (string) $row['document_id'],
        'source_reference' => (string) ($row['source_reference'] ?? ''),
        'sent_at' => $row['sent_at'],
        'received_at' => $row['received_at'],
        'acknowledged_at' => $row['acknowledged_at'],
        'created_at' => $row['created_at'],
        'payload' => is_array($payload) ? $payload : [],
    ];
}

function latestHrScheduleSyncRequest(PDO $db): ?array {
    $stmt = $db->prepare(
        "SELECT d.document_id, d.source_reference, d.sent_at, d.created_at, d.payload
         FROM integration_documents d
         JOIN departments sd ON sd.department_id = d.sender_department_id
         JOIN departments rd ON rd.department_id = d.receiver_department_id
         WHERE sd.department_code = 'COMLAB'
           AND rd.department_code = 'HR'
           AND CAST(d.payload AS text) ILIKE '%request_hr_schedule_sync%'
         ORDER BY COALESCE(d.sent_at, d.created_at) DESC
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $payload = json_decode((string) $row['payload'], true);
    return [
        'document_id' => (string) $row['document_id'],
        'source_reference' => (string) ($row['source_reference'] ?? ''),
        'sent_at' => $row['sent_at'],
        'created_at' => $row['created_at'],
        'payload' => is_array($payload) ? $payload : [],
    ];
}

function latestHrEmployeeRequest(PDO $db): ?array {
    $stmt = $db->prepare(
        "SELECT d.document_id, d.source_reference, d.sent_at, d.created_at, d.payload
         FROM integration_documents d
         JOIN departments sd ON sd.department_id = d.sender_department_id
         JOIN departments rd ON rd.department_id = d.receiver_department_id
         WHERE sd.department_code = 'COMLAB'
           AND rd.department_code = 'HR'
           AND CAST(d.payload AS text) ILIKE '%request_employee_support%'
         ORDER BY COALESCE(d.sent_at, d.created_at) DESC
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $payload = json_decode((string) $row['payload'], true);
    return [
        'document_id' => (string) $row['document_id'],
        'source_reference' => (string) ($row['source_reference'] ?? ''),
        'sent_at' => $row['sent_at'],
        'created_at' => $row['created_at'],
        'payload' => is_array($payload) ? $payload : [],
    ];
}

function resolveHrSyncRequestRoute(PDO $db): ?array {
    $stmt = $db->prepare(
        "SELECT rt.route_id, rt.record_type_id, rt.sender_department_id, rt.receiver_department_id
         FROM integration_routes rt
         JOIN departments sd ON sd.department_id = rt.sender_department_id
         JOIN departments rd ON rd.department_id = rt.receiver_department_id
         JOIN integration_record_types irt ON irt.record_type_id = rt.record_type_id
         WHERE sd.department_code = 'COMLAB'
           AND rd.department_code = 'HR'
           AND rt.is_active = 1
           AND (
                irt.record_type_code = ?
                OR irt.record_type_code = 'staff_list'
                OR irt.record_type_code = 'user_accounts'
           )
         ORDER BY CASE WHEN irt.record_type_code = ? THEN 0 ELSE 1 END, rt.flow_order
         LIMIT 1"
    );
    $stmt->execute([HR_SCHEDULE_RECORD_TYPE, HR_SCHEDULE_RECORD_TYPE]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function resolveHrEmployeeRequestRoute(PDO $db): ?array {
    $stmt = $db->prepare(
        "SELECT rt.route_id, rt.record_type_id, rt.sender_department_id, rt.receiver_department_id
         FROM integration_routes rt
         JOIN departments sd ON sd.department_id = rt.sender_department_id
         JOIN departments rd ON rd.department_id = rt.receiver_department_id
         JOIN integration_record_types irt ON irt.record_type_id = rt.record_type_id
         WHERE sd.department_code = 'COMLAB'
           AND rd.department_code = 'HR'
           AND rt.is_active = 1
           AND (
                irt.record_type_code = 'staff_list'
                OR irt.record_type_code = 'user_accounts'
                OR irt.record_type_code = ?
           )
         ORDER BY rt.flow_order
         LIMIT 1"
    );
    $stmt->execute([HR_SCHEDULE_RECORD_TYPE]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // Fallback: allow employee request even if a direct active route is not configured.
    $senderDepartmentId = resolveDepartmentIdByCode($db, 'COMLAB');
    $receiverDepartmentId = resolveDepartmentIdByCode($db, 'HR');
    $recordTypeId = resolveHrEmployeeRequestRecordTypeId($db);
    if (!$senderDepartmentId || !$receiverDepartmentId || !$recordTypeId) {
        return null;
    }

    return [
        'route_id' => null,
        'record_type_id' => $recordTypeId,
        'sender_department_id' => $senderDepartmentId,
        'receiver_department_id' => $receiverDepartmentId,
    ];
}

function resolveDepartmentIdByCode(PDO $db, string $code): ?int {
    $stmt = $db->prepare('SELECT department_id FROM departments WHERE department_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function resolveHrEmployeeRequestRecordTypeId(PDO $db): ?int {
    $stmt = $db->prepare(
        "SELECT record_type_id
         FROM integration_record_types
         WHERE is_active = 1
           AND record_type_code IN ('staff_list', 'user_accounts', ?)
         ORDER BY CASE
                    WHEN record_type_code = 'staff_list' THEN 0
                    WHEN record_type_code = 'user_accounts' THEN 1
                    ELSE 2
                  END
         LIMIT 1"
    );
    $stmt->execute([HR_SCHEDULE_RECORD_TYPE]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    // Last fallback to any active record type so request can still be logged and tracked.
    $stmt = $db->query("SELECT record_type_id FROM integration_record_types WHERE is_active = 1 ORDER BY record_type_id LIMIT 1");
    $id = $stmt ? $stmt->fetchColumn() : false;
    return $id ? (int) $id : null;
}

function resolveFacultyIdFromHrPayload(PDO $db, array $item): ?int {
    $username = trim((string) ($item['faculty_username'] ?? ''));
    $email = trim((string) ($item['faculty_email'] ?? ''));
    $employeeId = trim((string) ($item['hr_employee_id'] ?? ''));

    if ($employeeId !== '') {
        $stmt = $db->prepare("SELECT user_id FROM users WHERE hr_employee_id = ? AND role = 'Faculty' LIMIT 1");
        $stmt->execute([$employeeId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    if ($username !== '') {
        $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ? AND role = 'Faculty' LIMIT 1");
        $stmt->execute([$username]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    if ($email !== '') {
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ? AND role = 'Faculty' LIMIT 1");
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    return null;
}

function resolveLocationIdFromHrPayload(PDO $db, array $item): ?int {
    $labCode = trim((string) ($item['lab_code'] ?? ''));
    $labName = trim((string) ($item['lab_name'] ?? ''));

    if ($labCode !== '') {
        $stmt = $db->prepare('SELECT location_id FROM locations WHERE lab_code = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$labCode]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    if ($labName !== '') {
        $stmt = $db->prepare('SELECT location_id FROM locations WHERE lab_name = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$labName]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    return null;
}
