<?php

declare(strict_types=1);

if (!function_exists('admin_feedback_columns')) {
    function admin_feedback_columns(mysqli $connection): array
    {
        static $cache = [];
        $cacheKey = spl_object_hash($connection);

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $columns = [];
        $result = $connection->query("SHOW COLUMNS FROM feedback_messages");

        while ($row = $result->fetch_assoc()) {
            $columns[strtolower((string) ($row['Field'] ?? ''))] = true;
        }

        $cache[$cacheKey] = $columns;
        return $columns;
    }
}

if (!function_exists('admin_feedback_has_column')) {
    function admin_feedback_has_column(array $columns, string $columnName): bool
    {
        return isset($columns[strtolower($columnName)]);
    }
}

if (!function_exists('admin_feedback_text_expression')) {
    function admin_feedback_text_expression(array $columns, array $candidates, string $alias): string
    {
        $parts = [];

        foreach ($candidates as $candidate) {
            if (admin_feedback_has_column($columns, $candidate)) {
                $parts[] = "NULLIF({$candidate}, '')";
            }
        }

        if ($parts === []) {
            return "'' AS {$alias}";
        }

        return 'COALESCE(' . implode(', ', $parts) . ") AS {$alias}";
    }
}

if (!function_exists('admin_feedback_datetime_expression')) {
    function admin_feedback_datetime_expression(array $columns, array $candidates, string $alias): string
    {
        $parts = [];

        foreach ($candidates as $candidate) {
            if (admin_feedback_has_column($columns, $candidate)) {
                $parts[] = $candidate;
            }
        }

        if ($parts === []) {
            return "NULL AS {$alias}";
        }

        return 'COALESCE(' . implode(', ', $parts) . ") AS {$alias}";
    }
}

if (!function_exists('admin_feedback_primary_message_column')) {
    function admin_feedback_primary_message_column(array $columns): string
    {
        if (admin_feedback_has_column($columns, 'message')) {
            return 'message';
        }

        if (admin_feedback_has_column($columns, 'concern_message')) {
            return 'concern_message';
        }

        return 'message';
    }
}

if (!function_exists('admin_feedback_primary_date_column')) {
    function admin_feedback_primary_date_column(array $columns): string
    {
        if (admin_feedback_has_column($columns, 'submitted_at')) {
            return 'submitted_at';
        }

        if (admin_feedback_has_column($columns, 'created_at')) {
            return 'created_at';
        }

        return 'submitted_at';
    }
}

if (!function_exists('admin_feedback_normalize_status')) {
    function admin_feedback_normalize_status(?string $status, string $fallback = 'Pending'): string
    {
        $normalized = ucfirst(strtolower(trim((string) ($status ?? ''))));
        $allowed = ['Pending', 'Replied', 'Resolved'];

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }
}

if (!function_exists('admin_feedback_fetch_record')) {
    function admin_feedback_fetch_record(mysqli $connection, int $feedbackId): ?array
    {
        if ($feedbackId <= 0) {
            return null;
        }

        $columns = admin_feedback_columns($connection);
        $statement = $connection->prepare("
            SELECT
                id,
                " . (admin_feedback_has_column($columns, 'user_id') ? 'user_id' : 'NULL') . " AS user_id,
                " . (admin_feedback_has_column($columns, 'email') ? 'email' : "''") . " AS email,
                " . admin_feedback_text_expression($columns, ['concern_message', 'message'], 'concern_message') . ",
                " . admin_feedback_datetime_expression($columns, ['submitted_at', 'created_at'], 'date_submitted') . ",
                " . (admin_feedback_has_column($columns, 'status') ? "COALESCE(NULLIF(status, ''), 'Pending')" : "'Pending'") . " AS status,
                " . admin_feedback_text_expression($columns, ['admin_reply'], 'admin_reply') . ",
                " . admin_feedback_datetime_expression($columns, ['replied_at', 'resolved_at'], 'replied_at') . ",
                " . (admin_feedback_has_column($columns, 'category') ? "COALESCE(NULLIF(category, ''), 'General')" : "'General'") . " AS category
            FROM feedback_messages
            WHERE id = ?
            LIMIT 1
        ");
        $statement->bind_param('i', $feedbackId);
        $statement->execute();

        $record = $statement->get_result()->fetch_assoc() ?: null;

        if (!$record) {
            return null;
        }

        $record['id'] = (int) ($record['id'] ?? 0);
        $record['user_id'] = isset($record['user_id']) ? (int) $record['user_id'] : null;
        $record['message'] = (string) ($record['concern_message'] ?? '');
        $record['submitted_at'] = $record['date_submitted'] ?? null;

        return $record;
    }
}

if (!function_exists('admin_feedback_payload')) {
    function admin_feedback_payload(mysqli $connection): array
    {
        $columns = admin_feedback_columns($connection);
        $messages = [];
        $orderBy = admin_feedback_has_column($columns, 'submitted_at')
            ? 'submitted_at DESC, id DESC'
            : (admin_feedback_has_column($columns, 'created_at') ? 'created_at DESC, id DESC' : 'id DESC');

        $result = $connection->query("
            SELECT
                id,
                " . (admin_feedback_has_column($columns, 'user_id') ? 'user_id' : 'NULL') . " AS user_id,
                " . (admin_feedback_has_column($columns, 'email') ? 'email' : "''") . " AS email,
                " . admin_feedback_text_expression($columns, ['concern_message', 'message'], 'concern_message') . ",
                " . admin_feedback_datetime_expression($columns, ['submitted_at', 'created_at'], 'date_submitted') . ",
                " . (admin_feedback_has_column($columns, 'status') ? "COALESCE(NULLIF(status, ''), 'Pending')" : "'Pending'") . " AS status,
                " . admin_feedback_text_expression($columns, ['admin_reply'], 'admin_reply') . ",
                " . admin_feedback_datetime_expression($columns, ['replied_at', 'resolved_at'], 'replied_at') . ",
                " . (admin_feedback_has_column($columns, 'category') ? "COALESCE(NULLIF(category, ''), 'General')" : "'General'") . " AS category
            FROM feedback_messages
            ORDER BY {$orderBy}
        ");

        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['user_id'] = isset($row['user_id']) ? (int) $row['user_id'] : null;
            $row['message'] = (string) ($row['concern_message'] ?? '');
            $row['submitted_at'] = $row['date_submitted'] ?? null;
            $messages[] = $row;
        }

        return ['feedback' => $messages];
    }
}
