<?php

declare(strict_types=1);

if (!function_exists('admin_audit_label_from_type')) {
    function admin_audit_label_from_type(string $actionType): string
    {
        $normalized = trim($actionType);

        if ($normalized === '') {
            return 'Admin Action';
        }

        $label = str_replace('_', ' ', strtolower($normalized));
        return ucwords($label);
    }
}

if (!function_exists('admin_audit_resolve_ip_address')) {
    function admin_audit_resolve_ip_address(): string
    {
        $candidates = [
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim(explode(',', $candidate)[0] ?? '');

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'unknown';
    }
}

if (!function_exists('admin_audit_trigger_exists')) {
    function admin_audit_trigger_exists(mysqli $connection, string $triggerName): bool
    {
        $safeTrigger = $connection->real_escape_string($triggerName);
        $result = $connection->query("
            SELECT 1
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND TRIGGER_NAME = '{$safeTrigger}'
            LIMIT 1
        ");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}

if (!function_exists('admin_audit_ensure_schema')) {
    function admin_audit_ensure_schema(mysqli $connection): void
    {
        $connection->query("
            CREATE TABLE IF NOT EXISTS admin_audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NULL,
                admin_email VARCHAR(150) NULL,
                admin_name VARCHAR(150) NULL,
                action_type VARCHAR(100) NOT NULL,
                action_label VARCHAR(150) NOT NULL,
                description TEXT NOT NULL,
                target_type VARCHAR(100) NULL,
                target_id VARCHAR(100) NULL,
                ip_address VARCHAR(45) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'success',
                metadata_json LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin_audit_created_at (created_at),
                INDEX idx_admin_audit_admin_id (admin_id),
                INDEX idx_admin_audit_action_type (action_type),
                INDEX idx_admin_audit_status (status)
            )
        ");

        if (!admin_audit_trigger_exists($connection, 'trg_admin_audit_logs_block_update')) {
            $connection->query("
                CREATE TRIGGER trg_admin_audit_logs_block_update
                BEFORE UPDATE ON admin_audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'admin_audit_logs is append-only and cannot be updated.';
                END
            ");
        }

        if (!admin_audit_trigger_exists($connection, 'trg_admin_audit_logs_block_delete')) {
            $connection->query("
                CREATE TRIGGER trg_admin_audit_logs_block_delete
                BEFORE DELETE ON admin_audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'admin_audit_logs is append-only and cannot be deleted.';
                END
            ");
        }
    }
}

if (!function_exists('admin_audit_log')) {
    function admin_audit_log(mysqli $connection, ?array $admin, string $actionType, string $description, array $options = []): int
    {
        admin_audit_ensure_schema($connection);

        $adminId = isset($options['admin_id'])
            ? (int) $options['admin_id']
            : (isset($admin['id']) ? (int) $admin['id'] : null);
        $adminEmail = isset($options['admin_email'])
            ? trim((string) $options['admin_email'])
            : trim((string) ($admin['email'] ?? ''));
        $adminName = isset($options['admin_name'])
            ? trim((string) $options['admin_name'])
            : trim((string) ($admin['fullName'] ?? $admin['full_name'] ?? ''));
        $targetType = isset($options['target_type']) ? trim((string) $options['target_type']) : null;
        $targetId = isset($options['target_id']) ? trim((string) $options['target_id']) : null;
        $status = trim((string) ($options['status'] ?? 'success')) ?: 'success';
        $ipAddress = trim((string) ($options['ip_address'] ?? admin_audit_resolve_ip_address())) ?: 'unknown';
        $metadata = isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [];

        $metadata = array_merge([
            'request_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? '')
        ], $metadata);

        $metadataJson = $metadata !== []
            ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $actionType = strtoupper(trim($actionType));
        $actionLabel = trim((string) ($options['action_label'] ?? admin_audit_label_from_type($actionType)));

        $statement = $connection->prepare("
            INSERT INTO admin_audit_logs (
                admin_id,
                admin_email,
                admin_name,
                action_type,
                action_label,
                description,
                target_type,
                target_id,
                ip_address,
                status,
                metadata_json
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $statement->bind_param(
            'issssssssss',
            $adminId,
            $adminEmail,
            $adminName,
            $actionType,
            $actionLabel,
            $description,
            $targetType,
            $targetId,
            $ipAddress,
            $status,
            $metadataJson
        );
        $statement->execute();

        return (int) $connection->insert_id;
    }
}

if (!function_exists('admin_audit_fetch_grouped')) {
    function admin_audit_fetch_grouped(mysqli $connection, int $limit = 250): array
    {
        admin_audit_ensure_schema($connection);

        $limit = max(1, min($limit, 500));
        $statement = $connection->prepare("
            SELECT
                id,
                admin_id,
                admin_email,
                admin_name,
                action_type,
                action_label,
                description,
                target_type,
                target_id,
                ip_address,
                status,
                metadata_json,
                created_at
            FROM admin_audit_logs
            ORDER BY created_at DESC, id DESC
            LIMIT ?
        ");
        $statement->bind_param('i', $limit);
        $statement->execute();
        $result = $statement->get_result();

        $groups = [];
        $totalEntries = 0;

        while ($row = $result->fetch_assoc()) {
            $timestamp = strtotime((string) ($row['created_at'] ?? ''));

            if ($timestamp === false) {
                continue;
            }

            $dateKey = date('Y-m-d', $timestamp);
            $dateLabel = date('F j, Y', $timestamp);

            if (!isset($groups[$dateKey])) {
                $groups[$dateKey] = [
                    'date' => $dateKey,
                    'dateLabel' => $dateLabel,
                    'count' => 0,
                    'logs' => []
                ];
            }

            $metadata = json_decode((string) ($row['metadata_json'] ?? 'null'), true);
            $groups[$dateKey]['count']++;
            $groups[$dateKey]['logs'][] = [
                'id' => (int) ($row['id'] ?? 0),
                'admin_id' => isset($row['admin_id']) ? (int) $row['admin_id'] : null,
                'admin_email' => $row['admin_email'] ?? '',
                'admin_name' => $row['admin_name'] ?? '',
                'action_type' => $row['action_type'] ?? '',
                'action_label' => $row['action_label'] ?? '',
                'description' => $row['description'] ?? '',
                'target_type' => $row['target_type'] ?? '',
                'target_id' => $row['target_id'] ?? '',
                'ip_address' => $row['ip_address'] ?? '',
                'status' => $row['status'] ?? 'success',
                'status_label' => ucfirst((string) ($row['status'] ?? 'success')),
                'metadata' => is_array($metadata) ? $metadata : [],
                'log_time' => $row['created_at'] ?? ''
            ];
            $totalEntries++;
        }

        return [
            'totalEntries' => $totalEntries,
            'groups' => array_values($groups)
        ];
    }
}
