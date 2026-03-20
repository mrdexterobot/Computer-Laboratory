<?php
// ============================================
// COMLAB - Auth Middleware
// Include at the TOP of every protected page.
// Usage:
//   require_once __DIR__ . '/includes/require_auth.php';
//   requireAuth('devices');   // pass module key
// ============================================

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// Configure secure session BEFORE session_start
function initSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,           // browser-session cookie
            'path'     => '/',
            'secure'   => false,       // set true when on HTTPS
            'httponly' => true,        // prevent JS access to cookie
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Validate session and check module access.
 * Redirects to login.php if session invalid or expired.
 * Redirects to dashboard.php if access denied for module.
 *
 * @param string $module  The module key (e.g. 'devices')
 * @return array          The logged-in user data array
 */
function requireAuth(string $module = 'dashboard'): array {
    initSession();
    securityHeaders();

    // 1. Session existence check
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        redirectToLogin('Please log in to continue.');
    }

    // 2. Session timeout check (inactivity-based)
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        destroySession();
        redirectToLogin('Your session has expired. Please log in again.');
    }
    $_SESSION['last_activity'] = $now; // refresh timestamp

    // 3. Validate DB session token (prevents session fixation / stolen cookies)
    if (!empty($_SESSION['auth_token'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare(
                'SELECT s.session_id, s.expires_at, u.is_active
                 FROM user_sessions s
                 JOIN users u ON s.user_id = u.user_id
                 WHERE s.auth_token = ? AND s.user_id = ? AND s.is_active = 1'
            );
            $stmt->execute([$_SESSION['auth_token'], $_SESSION['user_id']]);
            $sess = $stmt->fetch();

            if (!$sess || !$sess['is_active'] || strtotime($sess['expires_at']) < $now) {
                destroySession();
                redirectToLogin('Session is invalid or account is inactive.');
            }

            // Extend DB session expiry on activity
            $newExpiry = date('Y-m-d H:i:s', $now + SESSION_TIMEOUT);
            $db->prepare('UPDATE user_sessions SET expires_at=? WHERE auth_token=?')
               ->execute([$newExpiry, $_SESSION['auth_token']]);

        } catch (RuntimeException $e) {
            // DB down - allow session to continue based on PHP session only (degrade gracefully)
            error_log('[COMLAB Auth] DB check failed: ' . $e->getMessage());
        }
    }

    // 4. Role-based module access check
    $role = $_SESSION['role'];
    if ($module !== 'dashboard' && !hasAccess($module, $role)) {
        // Redirect to dashboard with access denied message (no hard error)
        $_SESSION['flash_error'] = 'You do not have permission to access that module.';
        $base = getBasePath();
        header('Location: ' . $base . 'dashboard.php');
        exit;
    }

    return [
        'user_id'    => $_SESSION['user_id'],
        'username'   => $_SESSION['username'],
        'first_name' => $_SESSION['first_name'],
        'last_name'  => $_SESSION['last_name'],
        'role'       => $_SESSION['role'],
        'department' => $_SESSION['department'] ?? '',
    ];
}

/**
 * Redirect to login page with optional message.
 */
function redirectToLogin(string $message = ''): void {
    destroySession();
    if ($message) {
        // Store in a cookie (short-lived) since session is destroyed
        setcookie('comlab_msg', $message, time() + 30, '/', '', false, true); // httponly=true
    }
    $base = getBasePath();
    header('Location: ' . $base . 'login.php');
    exit;
}

/**
 * Destroy session cleanly.
 */
function destroySession(): void {
    initSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Compute base path relative to document root.
 * Works whether pages are in root or subfolders.
 */
function getBasePath(): string {
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $normalizePath = static function (string $path): string {
        if ($path === '') {
            return '';
        }

        $real = realpath($path);
        if ($real !== false) {
            $path = $real;
        }

        return rtrim(str_replace('\\', '/', $path), '/');
    };

    $projectRoot  = $normalizePath(dirname(__DIR__));
    $documentRoot = $normalizePath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($documentRoot !== '' && stripos($projectRoot . '/', $documentRoot . '/') === 0) {
        $relative = trim(substr($projectRoot, strlen($documentRoot)), '/');
        if ($relative === '') {
            return $basePath = '/';
        }

        $segments = array_map('rawurlencode', array_filter(explode('/', $relative), 'strlen'));
        return $basePath = '/' . implode('/', $segments) . '/';
    }

    $scriptDirFs  = $normalizePath(dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $scriptDirUrl = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/.');

    if (
        $scriptDirFs !== '' &&
        $scriptDirUrl !== '' &&
        stripos($scriptDirFs . '/', $projectRoot . '/') === 0
    ) {
        $relativeDir = trim(substr($scriptDirFs, strlen($projectRoot)), '/');
        $urlSegments = array_values(array_filter(explode('/', $scriptDirUrl), 'strlen'));
        $levelsUp    = $relativeDir === '' ? 0 : count(array_filter(explode('/', $relativeDir), 'strlen'));

        if ($levelsUp > 0) {
            $urlSegments = array_slice($urlSegments, 0, max(0, count($urlSegments) - $levelsUp));
        }

        return $basePath = empty($urlSegments) ? '/' : '/' . implode('/', $urlSegments) . '/';
    }

    return $basePath = '/';
}

/**
 * Send standard security response headers.
 * Call at the top of every page/API that returns HTML or JSON.
 */
function securityHeaders(bool $isApi = false): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
    if (!$isApi) {
        // CDN allowlist: jsdelivr for Font Awesome, Google Fonts for DM Sans/Mono
        header("Content-Security-Policy: " .
            "default-src 'self'; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
            "script-src 'self' 'unsafe-inline'; " .
            "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
            "img-src 'self' data:; " .
            "connect-src 'self';"
        );
    }
}

/**
 * Generate CSRF token and store in session.
 * Alias: generateCsrfToken() kept for compatibility.
 */
function generateCsrfToken(): string { return getCsrfToken(); }

function getCsrfToken(): string {
    initSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST request.
 */
function verifyCsrf(): bool {
    initSession();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
