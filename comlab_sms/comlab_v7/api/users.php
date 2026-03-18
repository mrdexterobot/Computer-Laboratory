<?php
// COMLAB - Users API (Admin only)
// Supabase/Postgres ready

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/require_auth.php';

header('Content-Type: application/json');

$user = requireAuth('users');
if ($user['role'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrators only.']);
    exit;
}

$uid = $user['user_id'];
$ip  = $_SERVER['REMOTE_ADDR'] ?? null;
$ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['export'])) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="users_' . date('Ymd') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Username', 'Email', 'First Name', 'Last Name', 'Role', 'Department', 'Active', 'Last Login']);
            foreach ($db->query(
                "SELECT user_id, username, email, first_name, last_name, role, department, is_active, last_login
                 FROM users
                 WHERE role IN ('Administrator', 'Faculty')
                 ORDER BY role, last_name"
            )->fetchAll(PDO::FETCH_NUM) as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
            exit;
        }

        if (($_GET['action'] ?? '') === 'schedule_summary') {
            $tid = (int) ($_GET['user_id'] ?? 0);
            if (!$tid) {
                echo json_encode(['success' => false, 'message' => 'user_id required.']);
                exit;
            }

            $stmt = $db->prepare(
                "SELECT fs.schedule_id, fs.class_name, fs.day_of_week,
                        fs.start_time, fs.end_time, fs.semester_start, fs.semester_end,
                        fs.is_active, l.lab_name, l.lab_code,
                        COUNT(sa.attendance_id) AS total_sessions,
                        SUM(CASE WHEN sa.status = 'Present' THEN 1 ELSE 0 END) AS present_count,
                        SUM(CASE WHEN sa.status = 'Absent' THEN 1 ELSE 0 END) AS absent_count
                 FROM faculty_schedules fs
                 JOIN locations l ON fs.location_id = l.location_id
                 LEFT JOIN schedule_attendance sa ON sa.schedule_id = fs.schedule_id
                 WHERE fs.faculty_id = ?
                 GROUP BY fs.schedule_id, l.location_id
                 ORDER BY fs.is_active DESC, fs.semester_start DESC"
            );
            $stmt->execute([$tid]);
            echo json_encode(['success' => true, 'schedules' => $stmt->fetchAll()]);
            exit;
        }

        $users = $db->query(
            "SELECT user_id, username, email, first_name, last_name, role, department, is_active, last_login
             FROM users
             WHERE role IN ('Administrator', 'Faculty')
             ORDER BY role, last_name"
        )->fetchAll();

        $counts = [
            'total' => count($users),
            'administrator' => count(array_filter($users, fn ($u) => $u['role'] === 'Administrator')),
            'faculty' => count(array_filter($users, fn ($u) => $u['role'] === 'Faculty')),
            'active' => count(array_filter($users, fn ($u) => (int) $u['is_active'] === 1)),
        ];

        echo json_encode(['success' => true, 'users' => $users, 'counts' => $counts]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }

        $action = $_POST['action'] ?? 'save';

        if ($action === 'toggle') {
            $tid = (int) ($_POST['user_id'] ?? 0);
            if ($tid === (int) $uid) {
                echo json_encode(['success' => false, 'message' => 'Cannot deactivate your own account.']);
                exit;
            }

            $row = $db->prepare('SELECT is_active, username, role FROM users WHERE user_id = ?');
            $row->execute([$tid]);
            $row = $row->fetch();
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit;
            }

            $newActive = $row['is_active'] ? 0 : 1;
            if (!$newActive && $row['role'] === ROLE_ADMIN) {
                $cnt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'Administrator' AND is_active = 1")->fetchColumn();
                if ($cnt <= 1) {
                    echo json_encode(['success' => false, 'message' => 'Cannot deactivate the last active Administrator.']);
                    exit;
                }
            }

            $db->prepare('UPDATE users SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?')
               ->execute([$newActive, $tid]);
            if (!$newActive) {
                $db->prepare('UPDATE user_sessions SET is_active = 0 WHERE user_id = ?')->execute([$tid]);
            }
            auditLog($db, $uid, 'User Updated', 'User', $tid, "User '{$row['username']}' " . ($newActive ? 'activated' : 'deactivated') . '.', $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'User ' . ($newActive ? 'activated' : 'deactivated') . '.']);
            exit;
        }

        if ($action === 'delete') {
            $tid = (int) ($_POST['user_id'] ?? 0);
            if ($tid === (int) $uid) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete your own account.']);
                exit;
            }

            $row = $db->prepare('SELECT username, role FROM users WHERE user_id = ?');
            $row->execute([$tid]);
            $row = $row->fetch();
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit;
            }
            if ($row['role'] === ROLE_ADMIN) {
                $cnt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'Administrator' AND is_active = 1")->fetchColumn();
                if ($cnt <= 1) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete the last active Administrator.']);
                    exit;
                }
            }

            $db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$tid]);
            auditLog($db, $uid, 'User Deleted', 'User', $tid, "User '{$row['username']}' deleted.", $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'User deleted.']);
            exit;
        }

        $editId    = (int) ($_POST['user_id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = $_POST['role'] ?? 'Faculty';
        $dept      = trim($_POST['department'] ?? '');

        if (!$firstName || !$lastName || !$username || !$email) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
            exit;
        }
        if (!in_array($role, ['Administrator', 'Faculty'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid role.']);
            exit;
        }
        if (!$editId && !$password) {
            echo json_encode(['success' => false, 'message' => 'Password is required for new users.']);
            exit;
        }
        if ($password && strlen($password) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
            exit;
        }

        if ($editId) {
            $exRow = $db->prepare('SELECT role FROM users WHERE user_id = ?');
            $exRow->execute([$editId]);
            $exRow = $exRow->fetchColumn();
            if ($exRow === ROLE_ADMIN && $role !== ROLE_ADMIN) {
                $cnt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'Administrator' AND is_active = 1")->fetchColumn();
                if ($cnt <= 1) {
                    echo json_encode(['success' => false, 'message' => 'Cannot change the role of the last active Administrator.']);
                    exit;
                }
            }

            $dup = $db->prepare('SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id <> ?');
            $dup->execute([$username, $email, $editId]);
            if ($dup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Username or email already in use.']);
                exit;
            }

            $sql    = 'UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, role = ?, department = ?, updated_at = CURRENT_TIMESTAMP';
            $params = [$firstName, $lastName, $username, $email, $role, $dept];
            if ($password) {
                $sql .= ', password_hash = ?';
                $params[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            }
            $sql .= ' WHERE user_id = ?';
            $params[] = $editId;
            $db->prepare($sql)->execute($params);
            auditLog($db, $uid, 'User Updated', 'User', $editId, "User '$username' updated.", $ip, $ua);
            echo json_encode(['success' => true, 'message' => 'User updated.']);
            exit;
        }

        $dup = $db->prepare('SELECT user_id FROM users WHERE username = ? OR email = ?');
        $dup->execute([$username, $email]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $newId = insertReturningId(
            $db,
            'INSERT INTO users (username, email, password_hash, first_name, last_name, role, department, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
            [$username, $email, $hash, $firstName, $lastName, $role, $dept],
            'user_id'
        );

        auditLog($db, $uid, 'User Created', 'User', (int) $newId, "New $role '$username' created.", $ip, $ua);
        echo json_encode(['success' => true, 'message' => 'User created.', 'user_id' => $newId]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    error_log('[COMLAB Users API] ' . $e->getMessage());
}

function auditLog(PDO $db, ?int $uid, string $action, ?string $tt, ?int $tid, string $desc, ?string $ip = null, ?string $ua = null): void {
    try {
        $db->prepare(
            'INSERT INTO audit_logs(user_id, action_type, target_type, target_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uid, $action, $tt, $tid, $desc, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('[AuditLog] ' . $e->getMessage());
    }
}
