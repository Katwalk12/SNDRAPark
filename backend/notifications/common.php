<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

function notifications_session_snapshot(): array
{
    static $snapshot = null;

    if ($snapshot !== null) {
        return $snapshot;
    }

    $snapshot = is_array($_SESSION ?? null) ? $_SESSION : [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return $snapshot;
}

function notifications_resolve_audiences(string $userType): array
{
    $normalized = strtolower(trim($userType));

    if ($normalized === 'booth') {
        return ['booth', ['Booth', 'Booth Staff', 'Staff', 'All']];
    }

    return ['user', ['Users', 'User', 'All']];
}

function notifications_get_viewer_context(string $resolvedUserType): array
{
    $session = notifications_session_snapshot();

    if ($resolvedUserType === 'booth') {
        $staffId = (int) ($session['sndra_admin']['id'] ?? 0);

        return [
            'reader_type' => 'booth',
            'reader_id' => $staffId,
            'session_user_id' => null
        ];
    }

    $userId = (int) ($session['user_id'] ?? 0);

    return [
        'reader_type' => 'user',
        'reader_id' => $userId,
        'session_user_id' => $userId
    ];
}

function notifications_require_viewer(string $resolvedUserType, array $viewer): void
{
    $readerId = (int) ($viewer['reader_id'] ?? 0);

    if ($readerId > 0) {
        return;
    }

    if ($resolvedUserType === 'booth') {
        booth_json_response([
            'success' => false,
            'message' => 'Booth session required.'
        ], 401);
    }

    booth_json_response([
        'success' => false,
        'message' => 'User session required.'
    ], 401);
}

function notifications_ensure_read_tracking_schema(mysqli $connection): void
{
    $connection->query("
        CREATE TABLE IF NOT EXISTS notification_reads (
            notification_id INT NOT NULL,
            reader_type VARCHAR(20) NOT NULL,
            reader_id INT NOT NULL DEFAULT 0,
            read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (notification_id, reader_type, reader_id),
            INDEX idx_notification_reads_reader (reader_type, reader_id)
        )
    ");
}
