<?php
// COMLAB - Session / CSRF check
// Called by login.php before submitting credentials
// Returns CSRF token and session status

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/require_auth.php';

header('Content-Type: application/json');
initSession();

echo json_encode([
    'csrf_token'     => getCsrfToken(),
    'logged_in'      => !empty($_SESSION['user_id']),
]);
