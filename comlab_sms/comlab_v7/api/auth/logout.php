<?php
// COMLAB - Logout

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/require_auth.php';

initSession();
$auditTable = comlabAuditLogTable();

if (!empty($_SESSION['auth_token'])) {
    try {
        $db = getDB();
        $db->prepare('UPDATE user_sessions SET is_active = 0 WHERE auth_token = ?')
           ->execute([$_SESSION['auth_token']]);
        if (!empty($_SESSION['user_id'])) {
            $db->prepare(
                "INSERT INTO {$auditTable} (user_id, action_type, description, ip_address)
                 VALUES (?, ?, ?, ?)"
            )->execute([$_SESSION['user_id'], 'Logout', 'User logged out.', $_SERVER['REMOTE_ADDR'] ?? null]);
        }
    } catch (Exception $e) {
        // non-fatal
    }
}

destroySession();
header('Location: ' . getBasePath() . 'login.php?msg=loggedout');
exit;
