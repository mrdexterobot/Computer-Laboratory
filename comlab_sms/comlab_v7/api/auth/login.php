<?php
// COMLAB - Login API

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/require_auth.php';

header('Content-Type: application/json');
securityHeaders(true);
initSession();

if (!empty($_SESSION['user_id'])) {
    echo json_encode(['success' => true, 'redirect' => '/comlab/dashboard.php']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!verifyCsrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request. Refresh and try again.']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

if (!$username || !$password) {
    echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
    exit;
}

$rateBucket = 'login_attempts_' . md5($ip);
$attempts   = $_SESSION[$rateBucket] ?? ['count' => 0, 'window_start' => time()];
if (time() - $attempts['window_start'] > 600) {
    $attempts = ['count' => 0, 'window_start' => time()];
}
if ($attempts['count'] >= 5) {
    echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please wait 10 minutes.']);
    exit;
}

try {
    $db = getDB();

    $stmt = $db->prepare(
        "SELECT user_id, username, password_hash, first_name, last_name,
                role, department, is_active
         FROM users
         WHERE username = ?
           AND role IN ('Administrator', 'Faculty')
         LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $hash = $user['password_hash'] ?? '$2y$10$invalidsaltinvalidsaltinvalidsalt';
    $valid = $user && password_verify($password, $hash);

    if (!$valid) {
        $attempts['count']++;
        $_SESSION[$rateBucket] = $attempts;

        $uid = $user['user_id'] ?? null;
        try {
            $db->prepare(
                'INSERT INTO audit_logs (user_id, action_type, description, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$uid, 'Login Failed', "Failed login attempt for username: $username", $ip, $ua]);
        } catch (Exception $e) {
            // non-fatal
        }

        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        exit;
    }

    if (!$user['is_active']) {
        echo json_encode(['success' => false, 'message' => 'Your account is inactive. Contact an administrator.']);
        exit;
    }

    session_regenerate_id(true);

    $authToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_TIMEOUT);

    $db->prepare('UPDATE user_sessions SET is_active = 0 WHERE user_id = ?')->execute([$user['user_id']]);
    $db->prepare(
        'INSERT INTO user_sessions (user_id, auth_token, ip_address, user_agent, expires_at, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    )->execute([$user['user_id'], $authToken, $ip, $ua, $expiresAt]);
    $db->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([$user['user_id']]);

    $_SESSION['user_id']       = $user['user_id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['first_name']    = $user['first_name'];
    $_SESSION['last_name']     = $user['last_name'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['department']    = $user['department'];
    $_SESSION['auth_token']    = $authToken;
    $_SESSION['last_activity'] = time();

    unset($_SESSION[$rateBucket]);

    $db->prepare(
        'INSERT INTO audit_logs (user_id, action_type, description, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$user['user_id'], 'Login', "User '{$user['username']}' logged in successfully.", $ip, $ua]);

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    echo json_encode([
        'success'  => true,
        'redirect' => '/comlab/dashboard.php',
        'user'     => [
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'role' => $user['role'],
        ],
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    error_log('[COMLAB Login] ' . $e->getMessage());
}
