<?php

declare(strict_types=1);

$target = './frontend/pages/parking-booth.html';
$queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));

if ($queryString !== '') {
    $target .= '?' . $queryString;
}

header('Location: ' . $target, true, 302);
exit;
