<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    booth_error('Method not allowed.', 405);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}

session_destroy();

booth_success('Booth session ended successfully.');
