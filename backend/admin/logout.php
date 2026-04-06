<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$admin = admin_require_auth('admin');
$connection = admin_db();

admin_audit_log($connection, $admin, 'ADMIN_LOGOUT', 'Admin logged out of the dashboard.', [
    'target_type' => 'auth',
    'status' => 'success'
]);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}

session_destroy();

admin_success('Admin session ended successfully.');
