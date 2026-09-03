<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

// Thin alias so the booth UI can post to a named endpoint, the same way
// scan.php and pay.php front the shared payment handler.
$rawInput = file_get_contents('php://input') ?: '';
$decoded = json_decode($rawInput, true);
$payload = is_array($decoded) ? $decoded : (is_array($_POST) ? $_POST : []);
$payload['action'] = 'issue_walkin';
$GLOBALS['booth_request_data_override'] = $payload;

require __DIR__ . '/payment.php';
