<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils/EnvHelper.php';

if (!function_exists('booth_json_response')) {
    function booth_json_response(array $payload, int $status = 200): never
    {
        if (ob_get_level() > 0) {
            ob_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_PRETTY_PRINT);
        exit;
    }
}

if (!function_exists('booth_success')) {
    function booth_success(string $message, array $data = [], int $status = 200): never
    {
        booth_json_response([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $status);
    }
}

if (!function_exists('booth_error')) {
    function booth_error(string $message, int $status = 500, array $data = []): never
    {
        booth_json_response([
            'success' => false,
            'message' => $message,
            'data' => $data
        ], $status);
    }
}

if (!function_exists('booth_log')) {
    function booth_log(string $message, array $context = []): void
    {
        $suffix = empty($context) ? '' : ' ' . json_encode($context);
        error_log('[parking-booth] ' . $message . $suffix);
    }
}

if (!function_exists('booth_schema_cache_version')) {
    function booth_schema_cache_version(): string
    {
        return '20260402_1';
    }
}

if (!function_exists('booth_schema_cache_file')) {
    function booth_schema_cache_file(string $database): string
    {
        $cacheDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }

        return $cacheDirectory . DIRECTORY_SEPARATOR . 'schema-' . md5($database) . '.json';
    }
}

if (!function_exists('booth_schema_is_fresh')) {
    function booth_schema_is_fresh(string $database, int $maxAgeSeconds = 900): bool
    {
        $cacheFile = booth_schema_cache_file($database);

        if (!is_file($cacheFile)) {
            return false;
        }

        $payload = json_decode((string) file_get_contents($cacheFile), true);

        if (!is_array($payload)) {
            return false;
        }

        if (($payload['version'] ?? null) !== booth_schema_cache_version()) {
            return false;
        }

        $generatedAt = strtotime((string) ($payload['generated_at'] ?? ''));
        if ($generatedAt === false) {
            return true;
        }

        $safeMaxAge = max($maxAgeSeconds, 2592000);
        return (time() - $generatedAt) < $safeMaxAge;
    }
}

if (!function_exists('booth_mark_schema_ready')) {
    function booth_mark_schema_ready(string $database): void
    {
        $cacheFile = booth_schema_cache_file($database);
        file_put_contents($cacheFile, json_encode([
            'version' => booth_schema_cache_version(),
            'database' => $database,
            'generated_at' => date('c')
        ], JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('booth_db')) {
    function booth_db(): mysqli
    {
        static $connection = null;

        if ($connection instanceof mysqli) {
            return $connection;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $host = (string) EnvHelper::get('DB_HOST', 'localhost');
        $user = (string) EnvHelper::get('DB_USER', 'root');
        $password = (string) EnvHelper::get('DB_PASSWORD', '');
        $database = (string) EnvHelper::get('DB_NAME', 'sndrapark_db');
        $port = (int) EnvHelper::get('DB_PORT', 3306);

        try {
            $nextConnection = mysqli_init();
            if (!$nextConnection) {
                throw new RuntimeException('Unable to initialize MySQL connection.');
            }

            $nextConnection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $nextConnection->real_connect($host, $user, $password, '', $port);
            $nextConnection->set_charset('utf8mb4');
            $nextConnection->query('CREATE DATABASE IF NOT EXISTS `' . booth_escape_identifier($database) . '`');
            $nextConnection->select_db($database);
            if (!booth_schema_is_fresh($database)) {
                booth_ensure_schema($nextConnection);
                booth_mark_schema_ready($database);
            }

            $connection = $nextConnection;
            return $connection;
        } catch (Throwable $exception) {
            $connection = null;
            booth_log('database-connection-failed', [
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'error' => $exception->getMessage()
            ]);

            booth_error('Database connection failed.', 500, [
                'details' => $exception->getMessage()
            ]);
        }
    }
}

if (!function_exists('booth_escape_identifier')) {
    function booth_escape_identifier(string $value): string
    {
        return str_replace('`', '``', $value);
    }
}

if (!function_exists('booth_column_exists')) {
    function booth_column_exists(mysqli $connection, string $tableName, string $columnName): bool
    {
        $table = $connection->real_escape_string($tableName);
        $column = $connection->real_escape_string($columnName);
        $result = $connection->query("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND COLUMN_NAME = '{$column}'
            LIMIT 1
        ");

        return $result->num_rows > 0;
    }
}

if (!function_exists('booth_index_exists')) {
    function booth_index_exists(mysqli $connection, string $tableName, string $indexName): bool
    {
        $table = $connection->real_escape_string($tableName);
        $index = $connection->real_escape_string($indexName);
        $result = $connection->query("
            SELECT 1
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND INDEX_NAME = '{$index}'
            LIMIT 1
        ");

        return $result->num_rows > 0;
    }
}

if (!function_exists('booth_add_column_if_missing')) {
    function booth_add_column_if_missing(mysqli $connection, string $tableName, string $columnName, string $alterSql): void
    {
        if (!booth_column_exists($connection, $tableName, $columnName)) {
            $connection->query($alterSql);
        }
    }
}

if (!function_exists('booth_add_index_if_missing')) {
    function booth_add_index_if_missing(mysqli $connection, string $tableName, string $indexName, string $createSql): void
    {
        if (!booth_index_exists($connection, $tableName, $indexName)) {
            $connection->query($createSql);
        }
    }
}

if (!function_exists('booth_drop_index_if_exists')) {
    function booth_drop_index_if_exists(mysqli $connection, string $tableName, string $indexName): void
    {
        if (!booth_index_exists($connection, $tableName, $indexName)) {
            return;
        }

        $safeTable = $connection->real_escape_string($tableName);
        $safeIndex = $connection->real_escape_string($indexName);

        try {
            $connection->query("ALTER TABLE `{$safeTable}` DROP INDEX `{$safeIndex}`");
        } catch (mysqli_sql_exception $exception) {
            if (stripos($exception->getMessage(), 'check that it exists') === false) {
                throw $exception;
            }
        }
    }
}

if (!function_exists('booth_ensure_schema')) {
    function booth_ensure_schema(mysqli $connection): void
    {
        $connection->query("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NULL,
                password_hash VARCHAR(255) NOT NULL DEFAULT '',
                birth_date DATE NULL,
                status ENUM('Active', 'Disabled') NOT NULL DEFAULT 'Active',
                warning_count INT NOT NULL DEFAULT 0,
                first_warning_at DATETIME NULL,
                account_locked_until DATETIME NULL,
                account_status ENUM('active', 'locked') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reset_otp_hash VARCHAR(255) NULL,
                reset_otp_expires_at DATETIME NULL,
                reset_otp_verified_at DATETIME NULL
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS reservations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                barcode_value VARCHAR(120) NOT NULL,
                barcode_lookup VARCHAR(120) NOT NULL DEFAULT '',
                barcode_status VARCHAR(20) NOT NULL DEFAULT 'active',
                full_name VARCHAR(150) NULL,
                email VARCHAR(150) NULL,
                parking_floor VARCHAR(50) NULL,
                parking_slot VARCHAR(50) NULL,
                reservation_date DATE NOT NULL,
                reserved_time_in TIME NULL,
                reserved_time_out TIME NULL,
                reservation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(20) NOT NULL DEFAULT 'Reserved',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS parking_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reservation_id INT NOT NULL UNIQUE,
                actual_time_in DATETIME NULL,
                actual_time_out DATETIME NULL,
                total_hours_stayed DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                extra_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                payment_status VARCHAR(20) NOT NULL DEFAULT 'Reserved',
                booth_status VARCHAR(20) NOT NULL DEFAULT 'Reserved',
                paid_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reservation_id INT NOT NULL UNIQUE,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                payment_status ENUM('Unpaid', 'Paid', 'Void') NOT NULL DEFAULT 'Unpaid',
                paid_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS parking_floors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                floor_name VARCHAR(50) NOT NULL UNIQUE,
                floor_label VARCHAR(50) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS parking_slots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                floor_id INT NULL,
                floor_name VARCHAR(50) NOT NULL,
                slot_code VARCHAR(50) NOT NULL,
                row_label VARCHAR(20) NULL,
                status ENUM('Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Available',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                manual_status ENUM('Auto', 'Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Auto',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_parking_slots_floor_id_slot (floor_id, slot_code)
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS feedback_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                email VARCHAR(150) NOT NULL,
                message TEXT NOT NULL,
                admin_reply TEXT NULL,
                status ENUM('Pending', 'Replied', 'Resolved') NOT NULL DEFAULT 'Pending',
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                replied_at DATETIME NULL,
                resolved_at DATETIME NULL
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                reservation_id INT NULL,
                title VARCHAR(150) NOT NULL,
                message TEXT NOT NULL,
                audience VARCHAR(50) NOT NULL DEFAULT 'Users',
                notification_date DATE NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS system_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
                actor_name VARCHAR(150) NOT NULL DEFAULT 'System',
                action_type VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                related_barcode VARCHAR(120) NULL,
                related_floor VARCHAR(50) NULL,
                related_slot VARCHAR(50) NULL,
                amount DECIMAL(10,2) NULL DEFAULT NULL,
                status VARCHAR(50) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_system_logs_created_at (created_at),
                INDEX idx_system_logs_action_type (action_type),
                INDEX idx_system_logs_user_id (user_id),
                INDEX idx_system_logs_role_created (actor_role, created_at)
            )
        ");

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

        $connection->query("
            CREATE TABLE IF NOT EXISTS user_violations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                violation_type VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                related_reservation_id INT NULL,
                created_by VARCHAR(50) NOT NULL DEFAULT 'system',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $connection->query("
            CREATE TABLE IF NOT EXISTS staff_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                username VARCHAR(80) NULL,
                email VARCHAR(150) NOT NULL UNIQUE,
                password_hash CHAR(64) NOT NULL,
                role ENUM('admin', 'booth') NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        booth_add_column_if_missing($connection, 'reservations', 'barcode_value', "ALTER TABLE reservations ADD COLUMN barcode_value VARCHAR(120) NOT NULL DEFAULT '' AFTER user_id");
        booth_add_column_if_missing($connection, 'reservations', 'barcode_lookup', "ALTER TABLE reservations ADD COLUMN barcode_lookup VARCHAR(120) NOT NULL DEFAULT '' AFTER barcode_value");
        booth_add_column_if_missing($connection, 'reservations', 'full_name', "ALTER TABLE reservations ADD COLUMN full_name VARCHAR(150) NULL AFTER barcode_value");
        booth_add_column_if_missing($connection, 'reservations', 'email', "ALTER TABLE reservations ADD COLUMN email VARCHAR(150) NULL AFTER full_name");
        booth_add_column_if_missing($connection, 'reservations', 'parking_floor', "ALTER TABLE reservations ADD COLUMN parking_floor VARCHAR(50) NULL AFTER barcode_value");
        booth_add_column_if_missing($connection, 'reservations', 'parking_slot', "ALTER TABLE reservations ADD COLUMN parking_slot VARCHAR(50) NULL AFTER parking_floor");
        booth_add_column_if_missing($connection, 'reservations', 'reserved_time_in', "ALTER TABLE reservations ADD COLUMN reserved_time_in TIME NULL AFTER reservation_date");
        booth_add_column_if_missing($connection, 'reservations', 'reserved_time_out', "ALTER TABLE reservations ADD COLUMN reserved_time_out TIME NULL AFTER reserved_time_in");
        booth_add_column_if_missing($connection, 'reservations', 'reservation_fee', "ALTER TABLE reservations ADD COLUMN reservation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER reserved_time_out");
        booth_add_column_if_missing($connection, 'reservations', 'updated_at', "ALTER TABLE reservations ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        booth_add_column_if_missing($connection, 'parking_transactions', 'total_hours_stayed', "ALTER TABLE parking_transactions ADD COLUMN total_hours_stayed DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER actual_time_out");
        booth_add_column_if_missing($connection, 'parking_transactions', 'extra_fee', "ALTER TABLE parking_transactions ADD COLUMN extra_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_hours_stayed");
        booth_add_column_if_missing($connection, 'parking_transactions', 'payment_status', "ALTER TABLE parking_transactions ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'Reserved' AFTER total_payment");
        booth_add_column_if_missing($connection, 'parking_transactions', 'paid_at', "ALTER TABLE parking_transactions ADD COLUMN paid_at DATETIME NULL AFTER booth_status");
        booth_add_column_if_missing($connection, 'parking_transactions', 'updated_at', "ALTER TABLE parking_transactions ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        booth_add_column_if_missing($connection, 'users', 'first_name', "ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER id");
        booth_add_column_if_missing($connection, 'users', 'last_name', "ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name");
        booth_add_column_if_missing($connection, 'users', 'birth_date', "ALTER TABLE users ADD COLUMN birth_date DATE NULL AFTER email");
        booth_add_column_if_missing($connection, 'users', 'password_hash', "ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER email");
        booth_add_column_if_missing($connection, 'users', 'status', "ALTER TABLE users ADD COLUMN status ENUM('Active', 'Disabled') NOT NULL DEFAULT 'Active' AFTER birth_date");
        booth_add_column_if_missing($connection, 'users', 'warning_count', "ALTER TABLE users ADD COLUMN warning_count INT NOT NULL DEFAULT 0 AFTER status");
        booth_add_column_if_missing($connection, 'users', 'first_warning_at', "ALTER TABLE users ADD COLUMN first_warning_at DATETIME NULL AFTER warning_count");
        booth_add_column_if_missing($connection, 'users', 'account_locked_until', "ALTER TABLE users ADD COLUMN account_locked_until DATETIME NULL AFTER first_warning_at");
        booth_add_column_if_missing($connection, 'users', 'account_status', "ALTER TABLE users ADD COLUMN account_status ENUM('active', 'locked') NOT NULL DEFAULT 'active' AFTER account_locked_until");
        booth_add_column_if_missing($connection, 'users', 'reset_otp_hash', "ALTER TABLE users ADD COLUMN reset_otp_hash VARCHAR(255) NULL AFTER password_hash");
        booth_add_column_if_missing($connection, 'users', 'reset_otp_expires_at', "ALTER TABLE users ADD COLUMN reset_otp_expires_at DATETIME NULL AFTER reset_otp_hash");
        booth_add_column_if_missing($connection, 'users', 'reset_otp_verified_at', "ALTER TABLE users ADD COLUMN reset_otp_verified_at DATETIME NULL AFTER reset_otp_expires_at");
        booth_add_column_if_missing($connection, 'reservations', 'barcode_status', "ALTER TABLE reservations ADD COLUMN barcode_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER barcode_value");
        booth_add_column_if_missing($connection, 'user_violations', 'related_reservation_id', "ALTER TABLE user_violations ADD COLUMN related_reservation_id INT NULL AFTER description");
        booth_add_column_if_missing($connection, 'user_violations', 'created_by', "ALTER TABLE user_violations ADD COLUMN created_by VARCHAR(50) NOT NULL DEFAULT 'system' AFTER related_reservation_id");
        booth_add_column_if_missing($connection, 'user_violations', 'created_at', "ALTER TABLE user_violations ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by");
        booth_add_column_if_missing($connection, 'parking_slots', 'floor_name', "ALTER TABLE parking_slots ADD COLUMN floor_name VARCHAR(50) NULL AFTER id");
        booth_add_column_if_missing($connection, 'parking_slots', 'floor_id', "ALTER TABLE parking_slots ADD COLUMN floor_id INT NULL AFTER id");
        booth_add_column_if_missing($connection, 'parking_slots', 'row_label', "ALTER TABLE parking_slots ADD COLUMN row_label VARCHAR(20) NULL AFTER slot_code");
        booth_add_column_if_missing($connection, 'parking_slots', 'status', "ALTER TABLE parking_slots ADD COLUMN status ENUM('Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Available' AFTER row_label");
        booth_add_column_if_missing($connection, 'parking_slots', 'is_active', "ALTER TABLE parking_slots ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER slot_code");
        booth_add_column_if_missing($connection, 'parking_slots', 'manual_status', "ALTER TABLE parking_slots ADD COLUMN manual_status ENUM('Auto', 'Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Auto' AFTER is_active");
        booth_add_column_if_missing($connection, 'parking_slots', 'created_at', "ALTER TABLE parking_slots ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER manual_status");
        booth_add_column_if_missing($connection, 'parking_slots', 'updated_at', "ALTER TABLE parking_slots ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        booth_add_column_if_missing($connection, 'feedback_messages', 'user_id', "ALTER TABLE feedback_messages ADD COLUMN user_id INT NULL AFTER id");
        booth_add_column_if_missing($connection, 'feedback_messages', 'admin_reply', "ALTER TABLE feedback_messages ADD COLUMN admin_reply TEXT NULL AFTER message");
        booth_add_column_if_missing($connection, 'feedback_messages', 'replied_at', "ALTER TABLE feedback_messages ADD COLUMN replied_at DATETIME NULL AFTER submitted_at");
        booth_add_column_if_missing($connection, 'staff_accounts', 'username', "ALTER TABLE staff_accounts ADD COLUMN username VARCHAR(80) NULL AFTER full_name");
        booth_add_column_if_missing($connection, 'notifications', 'audience', "ALTER TABLE notifications ADD COLUMN audience VARCHAR(50) NOT NULL DEFAULT 'Users' AFTER message");
        booth_add_column_if_missing($connection, 'notifications', 'notification_date', "ALTER TABLE notifications ADD COLUMN notification_date DATE NULL AFTER audience");
        booth_add_column_if_missing($connection, 'notifications', 'updated_at', "ALTER TABLE notifications ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

        // Keep compatibility with the older column names that already exist in this project.
        booth_add_column_if_missing($connection, 'parking_transactions', 'total_hours', "ALTER TABLE parking_transactions ADD COLUMN total_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER actual_time_out");
        booth_add_column_if_missing($connection, 'parking_transactions', 'overtime_fee', "ALTER TABLE parking_transactions ADD COLUMN overtime_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_hours");

        booth_add_index_if_missing($connection, 'reservations', 'uq_reservations_barcode_value', "CREATE UNIQUE INDEX uq_reservations_barcode_value ON reservations (barcode_value)");
        booth_add_index_if_missing($connection, 'reservations', 'idx_reservations_barcode_lookup', "CREATE INDEX idx_reservations_barcode_lookup ON reservations (barcode_lookup)");
        booth_add_index_if_missing($connection, 'reservations', 'idx_reservations_dashboard', "CREATE INDEX idx_reservations_dashboard ON reservations (reservation_date, status, updated_at)");
        booth_add_index_if_missing($connection, 'parking_transactions', 'uq_parking_transactions_reservation_id', "CREATE UNIQUE INDEX uq_parking_transactions_reservation_id ON parking_transactions (reservation_id)");
        booth_add_index_if_missing($connection, 'parking_transactions', 'idx_parking_transactions_status_lookup', "CREATE INDEX idx_parking_transactions_status_lookup ON parking_transactions (payment_status, booth_status, updated_at)");
        booth_add_index_if_missing($connection, 'parking_transactions', 'idx_parking_transactions_paid_at', "CREATE INDEX idx_parking_transactions_paid_at ON parking_transactions (paid_at)");
        booth_add_index_if_missing($connection, 'parking_slots', 'uq_parking_slots_floor_id_slot', "CREATE UNIQUE INDEX uq_parking_slots_floor_id_slot ON parking_slots (floor_id, slot_code)");
        booth_add_index_if_missing($connection, 'parking_slots', 'uq_parking_slots_floor_slot', "CREATE UNIQUE INDEX uq_parking_slots_floor_slot ON parking_slots (floor_name, slot_code)");
        booth_add_index_if_missing($connection, 'parking_slots', 'idx_parking_slots_floor_id_active', "CREATE INDEX idx_parking_slots_floor_id_active ON parking_slots (floor_id, is_active, manual_status)");
        booth_add_index_if_missing($connection, 'parking_slots', 'idx_parking_slots_floor_id_status', "CREATE INDEX idx_parking_slots_floor_id_status ON parking_slots (floor_id, status, is_active)");
        booth_add_index_if_missing($connection, 'parking_slots', 'idx_parking_slots_floor_active', "CREATE INDEX idx_parking_slots_floor_active ON parking_slots (floor_name, is_active, manual_status)");
        booth_add_index_if_missing($connection, 'parking_slots', 'idx_parking_slots_floor_status', "CREATE INDEX idx_parking_slots_floor_status ON parking_slots (floor_name, status, is_active)");
        booth_add_index_if_missing($connection, 'feedback_messages', 'idx_feedback_messages_status_submitted', "CREATE INDEX idx_feedback_messages_status_submitted ON feedback_messages (status, submitted_at)");
        booth_add_index_if_missing($connection, 'feedback_messages', 'idx_feedback_messages_user_status', "CREATE INDEX idx_feedback_messages_user_status ON feedback_messages (user_id, status)");
        booth_add_index_if_missing($connection, 'notifications', 'idx_notifications_date_created', "CREATE INDEX idx_notifications_date_created ON notifications (notification_date, created_at)");
        booth_add_index_if_missing($connection, 'staff_accounts', 'idx_staff_accounts_role_active', "CREATE INDEX idx_staff_accounts_role_active ON staff_accounts (role, is_active)");
        booth_add_index_if_missing($connection, 'user_violations', 'idx_user_violations_user_created', "CREATE INDEX idx_user_violations_user_created ON user_violations (user_id, created_at)");
        booth_add_index_if_missing($connection, 'user_violations', 'idx_user_violations_type_created', "CREATE INDEX idx_user_violations_type_created ON user_violations (violation_type, created_at)");

        $connection->query("
            ALTER TABLE feedback_messages
            MODIFY COLUMN status ENUM('Pending', 'Replied', 'Resolved') NOT NULL DEFAULT 'Pending'
        ");

        $connection->query("
            UPDATE reservations
            SET status = CASE
                WHEN status IN ('pending', 'confirmed') THEN 'Reserved'
                WHEN status = 'cancelled' THEN 'Cancelled'
                WHEN status IS NULL OR status = '' THEN 'Reserved'
                ELSE status
            END
        ");

        $connection->query("
            UPDATE reservations
            SET
                barcode_value = UPPER(TRIM(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(barcode_value, CHAR(13), ''),
                                CHAR(10), ''),
                            CHAR(9), ''),
                        ' ', ''),
                    CHAR(160), '')
                )),
                barcode_lookup = REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        UPPER(TRIM(barcode_value)),
                                    CHAR(13), ''),
                                CHAR(10), ''),
                            CHAR(9), ''),
                        ' ', ''),
                    '-', ''),
                CHAR(160), '')
            WHERE barcode_value IS NOT NULL
              AND barcode_value <> ''
        ");

        $connection->query("
            UPDATE reservations r
            LEFT JOIN users u ON u.id = r.user_id
            SET
                r.full_name = COALESCE(NULLIF(r.full_name, ''), u.full_name, r.full_name),
                r.email = COALESCE(NULLIF(r.email, ''), u.email, r.email)
        ");

        $connection->query("
            ALTER TABLE reservations
            MODIFY status ENUM('Reserved', 'Parked', 'Exited', 'Unpaid', 'Paid', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Reserved'
        ");

        $connection->query("
            ALTER TABLE parking_transactions
            MODIFY payment_status ENUM('Reserved', 'Pending', 'Unpaid', 'Paid') NOT NULL DEFAULT 'Reserved'
        ");

        $connection->query("
            ALTER TABLE parking_transactions
            MODIFY booth_status ENUM('Reserved', 'Parked', 'Exited', 'Unpaid', 'Paid', 'Completed') NOT NULL DEFAULT 'Reserved'
        ");

        $connection->query("
            ALTER TABLE parking_slots
            MODIFY floor_id INT NULL,
            MODIFY floor_name VARCHAR(50) NULL,
            MODIFY slot_code VARCHAR(50) NOT NULL,
            MODIFY row_label VARCHAR(20) NULL,
            MODIFY status ENUM('Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Available',
            MODIFY is_active TINYINT(1) NOT NULL DEFAULT 1,
            MODIFY manual_status ENUM('Auto', 'Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Auto'
        ");

        $connection->query("
            UPDATE parking_slots s
            INNER JOIN parking_floors f ON f.floor_name = s.floor_name
            SET s.floor_id = f.id
            WHERE s.floor_id IS NULL OR s.floor_id = 0
        ");

        $connection->query("
            UPDATE parking_slots s
            INNER JOIN parking_floors f ON f.id = s.floor_id
            SET s.floor_name = f.floor_name
            WHERE s.floor_name IS NULL OR s.floor_name = '' OR s.floor_name <> f.floor_name
        ");

        $connection->query("
            UPDATE parking_slots
            SET row_label = CASE
                WHEN row_label IS NOT NULL AND row_label <> '' THEN row_label
                WHEN slot_code <> '' THEN UPPER(LEFT(slot_code, 1))
                ELSE 'ROW'
            END
        ");

        $connection->query("
            ALTER TABLE notifications
            MODIFY user_id INT NULL,
            MODIFY reservation_id INT NULL,
            MODIFY notification_date DATE NULL
        ");

        $connection->query("
            UPDATE users
            SET
                first_name = CASE
                    WHEN (first_name IS NULL OR first_name = '') AND full_name IS NOT NULL AND full_name <> ''
                        THEN SUBSTRING_INDEX(full_name, ' ', 1)
                    ELSE first_name
                END,
                last_name = CASE
                    WHEN (last_name IS NULL OR last_name = '') AND full_name IS NOT NULL AND full_name <> '' AND LOCATE(' ', full_name) > 0
                        THEN TRIM(SUBSTRING(full_name, LOCATE(' ', full_name) + 1))
                    ELSE last_name
                END
        ");

        $connection->query("
            ALTER TABLE users
            MODIFY full_name VARCHAR(150) NOT NULL,
            MODIFY email VARCHAR(150) NULL,
            MODIFY password_hash VARCHAR(255) NOT NULL DEFAULT '',
            MODIFY status ENUM('Active', 'Disabled') NOT NULL DEFAULT 'Active',
            MODIFY warning_count INT NOT NULL DEFAULT 0,
            MODIFY account_status ENUM('active', 'locked') NOT NULL DEFAULT 'active'
        ");

        $connection->query("
            UPDATE parking_transactions
            SET
                total_hours_stayed = COALESCE(NULLIF(total_hours_stayed, 0), total_hours, 0),
                extra_fee = COALESCE(NULLIF(extra_fee, 0), overtime_fee, 0),
                payment_status = CASE
                    WHEN paid_at IS NOT NULL THEN 'Paid'
                    WHEN actual_time_out IS NOT NULL THEN 'Unpaid'
                    WHEN actual_time_in IS NOT NULL THEN 'Pending'
                    ELSE payment_status
                END
        ");

        $connection->query("
            INSERT INTO payments (reservation_id, amount, payment_status, paid_at)
            SELECT
                pt.reservation_id,
                COALESCE(pt.total_payment, 0),
                CASE
                    WHEN pt.paid_at IS NOT NULL OR pt.payment_status = 'Paid' THEN 'Paid'
                    WHEN pt.actual_time_out IS NOT NULL OR pt.payment_status = 'Unpaid' THEN 'Unpaid'
                    ELSE 'Unpaid'
                END,
                pt.paid_at
            FROM parking_transactions pt
            LEFT JOIN payments p ON p.reservation_id = pt.reservation_id
            WHERE p.id IS NULL
        ");

        $connection->query("
            UPDATE payments p
            INNER JOIN parking_transactions pt ON pt.reservation_id = p.reservation_id
            SET
                p.amount = COALESCE(pt.total_payment, p.amount),
                p.payment_status = CASE
                    WHEN pt.paid_at IS NOT NULL OR pt.payment_status = 'Paid' THEN 'Paid'
                    WHEN pt.actual_time_out IS NOT NULL OR pt.payment_status = 'Unpaid' THEN 'Unpaid'
                    ELSE p.payment_status
                END,
                p.paid_at = COALESCE(pt.paid_at, p.paid_at)
        ");

        booth_seed_default_parking_layout($connection);
        booth_seed_default_staff_accounts($connection);
        booth_seed_default_settings($connection);
    }
}

if (!function_exists('booth_seed_default_parking_layout')) {
    function booth_seed_default_parking_layout(mysqli $connection): void
    {
        $result = $connection->query("SELECT COUNT(*) AS total FROM parking_floors");
        $row = $result->fetch_assoc();
        $floorCount = (int) ($row['total'] ?? 0);

        if ($floorCount === 0) {
            $defaults = [
                ['LG', 'LG', 1, 'L', 20],
                ['1st Floor', '1st Floor', 2, 'A', 20],
                ['2nd Floor', '2nd Floor', 3, 'B', 20],
                ['3rd Floor', '3rd Floor', 4, 'C', 20],
                ['4th Floor', '4th Floor', 5, 'D', 20],
                ['5th Floor', '5th Floor', 6, 'E', 20]
            ];

            $floorStatement = $connection->prepare("
                INSERT IGNORE INTO parking_floors (floor_name, floor_label, sort_order, is_active)
                VALUES (?, ?, ?, 1)
            ");
            $slotStatement = $connection->prepare("
                INSERT IGNORE INTO parking_slots (floor_name, slot_code, row_label, status, is_active, manual_status)
                VALUES (?, ?, ?, 'Available', 1, 'Auto')
            ");

            foreach ($defaults as [$floorName, $floorLabel, $sortOrder, $prefix, $slotCount]) {
                $floorStatement->bind_param('ssi', $floorName, $floorLabel, $sortOrder);
                $floorStatement->execute();

                for ($index = 1; $index <= $slotCount; $index++) {
                    $slotCode = $prefix . $index;
                    $rowLabel = chr(64 + (int) ceil($index / 5));
                    $slotStatement->bind_param('sss', $floorName, $slotCode, $rowLabel);
                    $slotStatement->execute();
                }
            }
        }
    }
}

if (!function_exists('booth_sync_slots_from_reservations')) {
    function booth_sync_slots_from_reservations(mysqli $connection): void
    {
        $connection->query("
            INSERT IGNORE INTO parking_floors (floor_name, floor_label, sort_order, is_active)
            SELECT DISTINCT
                r.parking_floor,
                r.parking_floor,
                99,
                1
            FROM reservations r
            WHERE r.parking_floor IS NOT NULL
              AND r.parking_floor <> ''
        ");

        $connection->query("
            INSERT IGNORE INTO parking_slots (floor_name, slot_code, row_label, status, is_active, manual_status)
            SELECT DISTINCT
                r.parking_floor,
                r.parking_slot,
                CASE
                    WHEN r.parking_slot <> '' THEN UPPER(LEFT(r.parking_slot, 1))
                    ELSE 'ROW'
                END,
                'Available',
                1,
                'Auto'
            FROM reservations r
            WHERE r.parking_floor IS NOT NULL
              AND r.parking_floor <> ''
              AND r.parking_slot IS NOT NULL
              AND r.parking_slot <> ''
        ");
    }
}

if (!function_exists('booth_seed_default_staff_accounts')) {
    function booth_seed_default_staff_accounts(mysqli $connection): void
    {
        $adminPassword = hash('sha256', 'Admin123!');
        $boothPassword = hash('sha256', 'Booth123!');

        $connection->query("
            INSERT INTO staff_accounts (full_name, username, email, password_hash, role, is_active)
            VALUES
                ('SNDRA Park Administrator', 'admin', 'admin@sndrapark.com', '{$adminPassword}', 'admin', 1),
                ('Booth Teller', 'booth', 'booth@sndrapark.com', '{$boothPassword}', 'booth', 1)
            ON DUPLICATE KEY UPDATE
                full_name = VALUES(full_name),
                username = COALESCE(NULLIF(VALUES(username), ''), username),
                password_hash = VALUES(password_hash),
                role = VALUES(role),
                is_active = VALUES(is_active)
        ");
    }
}

if (!function_exists('booth_seed_default_settings')) {
    function booth_seed_default_settings(mysqli $connection): void
    {
        $defaults = [
            'system_name' => 'SNDRA Park',
            'contact_number' => '+63 917 555 0142',
            'gmail_address' => 'sndraparkemulator@gmail.com',
            'parking_base_rate' => '20',
            'extra_hourly_rate' => '10'
        ];

        $statement = $connection->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = setting_value
        ");

        foreach ($defaults as $key => $value) {
            $statement->bind_param('ss', $key, $value);
            $statement->execute();
        }
    }
}
