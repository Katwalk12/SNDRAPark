<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$boothUser = booth_bootstrap_endpoint('GET', 'view_monitor');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    booth_error('Method not allowed. Use GET.', 405);
}

booth_success('Booth session is active.', [
    'id' => (int) ($boothUser['id'] ?? 0),
    'role' => 'booth',
    'fullName' => (string) ($boothUser['full_name'] ?? 'Booth Teller'),
    'email' => (string) ($boothUser['email'] ?? '')
]);
