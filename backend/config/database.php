<?php

require_once __DIR__ . '/../utils/EnvHelper.php';

class Database
{
    private static $connection = null;
    private const SCHEMA_CACHE_VERSION = '20260612_google_oauth';
    private const SCHEMA_CACHE_MAX_AGE = 2592000;

    public static function connection()
    {
        if (self::$connection instanceof mysqli) {
            return self::$connection;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $host = EnvHelper::get('DB_HOST', 'localhost');
        $user = EnvHelper::get('DB_USER', 'root');
        $password = EnvHelper::get('DB_PASSWORD', '');
        $database = EnvHelper::get('DB_NAME', 'sndrapark_db');
        $port = (int) EnvHelper::get('DB_PORT', 3306);

        $connection = mysqli_init();

        if (!$connection) {
            throw new RuntimeException('Unable to initialize MySQL connection.');
        }

        try {
            $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $connection->real_connect($host, $user, $password, '', $port);
            $connection->set_charset('utf8mb4');

            $databaseName = self::escapeIdentifier($database);
            $connection->query("CREATE DATABASE IF NOT EXISTS `{$databaseName}`");
            $connection->select_db($database);

            self::$connection = $connection;

            if (!self::isSchemaCacheFresh($database)) {
                self::ensureSchema();
                self::markSchemaCacheReady($database);
            }

            return self::$connection;
        } catch (Throwable $exception) {
            self::$connection = null;
            throw $exception;
        }
    }

    private static function ensureSchema()
    {
        $userIdColumnType = 'INT';
        $vehicleIdColumnType = 'INT';

        self::$connection->query("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                full_name VARCHAR(150) NOT NULL,
                birth_date DATE NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                google_id VARCHAR(64) NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('user', 'admin', 'booth') NOT NULL DEFAULT 'user',
                last_login_at DATETIME NULL,
                session_expires_at DATETIME NULL,
                status ENUM('Active', 'Disabled') NOT NULL DEFAULT 'Active',
                warning_count INT NOT NULL DEFAULT 0,
                first_warning_at DATETIME NULL,
                account_locked_until DATETIME NULL,
                account_status ENUM('active', 'locked') NOT NULL DEFAULT 'active',
                reset_otp_hash VARCHAR(255) NULL,
                reset_otp_expires_at DATETIME NULL,
                reset_otp_verified_at DATETIME NULL,
                vehicle_type ENUM('Motorcycle', 'Car') NULL DEFAULT NULL,
                plate_number VARCHAR(20) NULL DEFAULT NULL,
                vehicle_brand VARCHAR(100) NULL DEFAULT NULL,
                vehicle_model VARCHAR(100) NULL DEFAULT NULL,
                vehicle_color VARCHAR(50) NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        try {
            $userIdColumn = self::$connection->query("SHOW COLUMNS FROM users LIKE 'id'")->fetch_assoc();
            if (stripos((string) ($userIdColumn['Type'] ?? ''), 'unsigned') !== false) {
                $userIdColumnType = 'INT UNSIGNED';
                $vehicleIdColumnType = 'INT UNSIGNED';
            }
        } catch (Throwable $exception) {
            $userIdColumnType = 'INT';
            $vehicleIdColumnType = 'INT';
        }

        self::$connection->query("
            CREATE TABLE IF NOT EXISTS parking_slots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slot_code VARCHAR(20) NOT NULL UNIQUE,
                status ENUM('available', 'reserved', 'occupied') DEFAULT 'available',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        self::$connection->query("
            CREATE TABLE IF NOT EXISTS reservations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                barcode_value VARCHAR(120) NULL,
                barcode_status VARCHAR(20) NOT NULL DEFAULT 'active',
                parking_floor VARCHAR(50) NULL,
                parking_slot VARCHAR(50) NULL,
                parking_slot_id INT NULL,
                reservation_date DATE NOT NULL,
                reserved_time_in TIME NULL,
                reserved_time_out TIME NULL,
                reservation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(20) NOT NULL DEFAULT 'Reserved',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_reservations_user
                    FOREIGN KEY (user_id) REFERENCES users(id),
                CONSTRAINT fk_reservations_parking_slot
                    FOREIGN KEY (parking_slot_id) REFERENCES parking_slots(id)
            )
        ");

        self::$connection->query("
            CREATE TABLE IF NOT EXISTS booth_teller_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                teller_name VARCHAR(150) NOT NULL,
                teller_details VARCHAR(255) NULL,
                pin_code VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        self::$connection->query("
            CREATE TABLE IF NOT EXISTS vehicles (
                vehicle_id {$vehicleIdColumnType} AUTO_INCREMENT PRIMARY KEY,
                user_id {$userIdColumnType} NOT NULL,
                vehicle_type ENUM('Car', 'Motorcycle') NOT NULL,
                plate_number VARCHAR(20) NOT NULL,
                brand VARCHAR(100) NULL,
                model VARCHAR(100) NULL,
                color VARCHAR(50) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_vehicles_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            )
        ");

        self::$connection->query("
            CREATE TABLE IF NOT EXISTS parking_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reservation_id INT NOT NULL UNIQUE,
                actual_time_in DATETIME NULL,
                actual_time_out DATETIME NULL,
                total_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                overtime_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                booth_status VARCHAR(20) NOT NULL DEFAULT 'Reserved',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_transactions_reservation
                    FOREIGN KEY (reservation_id) REFERENCES reservations(id)
            )
        ");

        self::$connection->query("
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

        self::$connection->query("
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

        self::$connection->query("
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

        self::ensureColumn('users', 'first_name', "ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER id");
        self::ensureColumn('users', 'last_name', "ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name");
        self::ensureColumn('users', 'full_name', "ALTER TABLE users ADD COLUMN full_name VARCHAR(150) NOT NULL DEFAULT '' AFTER last_name");
        self::ensureColumn('users', 'birth_date', "ALTER TABLE users ADD COLUMN birth_date DATE NULL AFTER full_name");
        self::ensureColumn('users', 'google_id', "ALTER TABLE users ADD COLUMN google_id VARCHAR(64) NULL AFTER email");
        self::ensureColumn('users', 'password_hash', "ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER email");
        self::ensureColumn('users', 'role', "ALTER TABLE users ADD COLUMN role ENUM('user', 'admin', 'booth') NOT NULL DEFAULT 'user' AFTER password_hash");
        self::ensureColumn('users', 'last_login_at', "ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER role");
        self::ensureColumn('users', 'session_expires_at', "ALTER TABLE users ADD COLUMN session_expires_at DATETIME NULL AFTER last_login_at");
        self::ensureColumn('users', 'status', "ALTER TABLE users ADD COLUMN status ENUM('Active', 'Disabled') NOT NULL DEFAULT 'Active' AFTER birth_date");
        self::ensureColumn('users', 'warning_count', "ALTER TABLE users ADD COLUMN warning_count INT NOT NULL DEFAULT 0 AFTER status");
        self::ensureColumn('users', 'first_warning_at', "ALTER TABLE users ADD COLUMN first_warning_at DATETIME NULL AFTER warning_count");
        self::ensureColumn('users', 'account_locked_until', "ALTER TABLE users ADD COLUMN account_locked_until DATETIME NULL AFTER first_warning_at");
        self::ensureColumn('users', 'account_status', "ALTER TABLE users ADD COLUMN account_status ENUM('active', 'locked') NOT NULL DEFAULT 'active' AFTER account_locked_until");
        self::ensureColumn('users', 'reset_otp_hash', "ALTER TABLE users ADD COLUMN reset_otp_hash VARCHAR(255) NULL AFTER password_hash");
        self::ensureColumn('users', 'reset_otp_expires_at', "ALTER TABLE users ADD COLUMN reset_otp_expires_at DATETIME NULL AFTER reset_otp_hash");
        self::ensureColumn('users', 'reset_otp_verified_at', "ALTER TABLE users ADD COLUMN reset_otp_verified_at DATETIME NULL AFTER reset_otp_expires_at");
        self::ensureColumn('users', 'vehicle_type', "ALTER TABLE users ADD COLUMN vehicle_type ENUM('Motorcycle', 'Car') NULL DEFAULT NULL AFTER birth_date");
        self::ensureColumn('users', 'plate_number', "ALTER TABLE users ADD COLUMN plate_number VARCHAR(20) NULL DEFAULT NULL AFTER vehicle_type");
        self::ensureColumn('users', 'vehicle_brand', "ALTER TABLE users ADD COLUMN vehicle_brand VARCHAR(100) NULL DEFAULT NULL AFTER plate_number");
        self::ensureColumn('users', 'vehicle_model', "ALTER TABLE users ADD COLUMN vehicle_model VARCHAR(100) NULL DEFAULT NULL AFTER vehicle_brand");
        self::ensureColumn('users', 'vehicle_color', "ALTER TABLE users ADD COLUMN vehicle_color VARCHAR(50) NULL DEFAULT NULL AFTER vehicle_brand");
        self::ensureColumn('vehicles', 'model', "ALTER TABLE vehicles ADD COLUMN model VARCHAR(100) NULL AFTER brand");
        self::ensureColumn('reservations', 'vehicle_id', "ALTER TABLE reservations ADD COLUMN vehicle_id {$vehicleIdColumnType} NULL AFTER user_id");
        self::ensureColumn('reservations', 'barcode_value', "ALTER TABLE reservations ADD COLUMN barcode_value VARCHAR(120) NULL AFTER user_id");
        self::ensureColumn('reservations', 'barcode_status', "ALTER TABLE reservations ADD COLUMN barcode_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER barcode_value");
        self::ensureColumn('reservations', 'parking_floor', "ALTER TABLE reservations ADD COLUMN parking_floor VARCHAR(50) NULL AFTER barcode_value");
        self::ensureColumn('reservations', 'parking_slot', "ALTER TABLE reservations ADD COLUMN parking_slot VARCHAR(50) NULL AFTER parking_floor");
        self::ensureColumn('reservations', 'reserved_time_in', "ALTER TABLE reservations ADD COLUMN reserved_time_in TIME NULL AFTER reservation_date");
        self::ensureColumn('reservations', 'reserved_time_out', "ALTER TABLE reservations ADD COLUMN reserved_time_out TIME NULL AFTER reserved_time_in");
        self::ensureColumn('reservations', 'reservation_fee', "ALTER TABLE reservations ADD COLUMN reservation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER reserved_time_out");
        self::ensureColumn('reservations', 'updated_at', "ALTER TABLE reservations ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        self::ensureColumn('parking_transactions', 'actual_time_in', "ALTER TABLE parking_transactions ADD COLUMN actual_time_in DATETIME NULL AFTER reservation_id");
        self::ensureColumn('parking_transactions', 'actual_time_out', "ALTER TABLE parking_transactions ADD COLUMN actual_time_out DATETIME NULL AFTER actual_time_in");
        self::ensureColumn('parking_transactions', 'total_hours', "ALTER TABLE parking_transactions ADD COLUMN total_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER actual_time_out");
        self::ensureColumn('parking_transactions', 'overtime_fee', "ALTER TABLE parking_transactions ADD COLUMN overtime_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_hours");
        self::ensureColumn('parking_transactions', 'total_payment', "ALTER TABLE parking_transactions ADD COLUMN total_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER overtime_fee");
        self::ensureColumn('parking_transactions', 'booth_status', "ALTER TABLE parking_transactions ADD COLUMN booth_status VARCHAR(20) NOT NULL DEFAULT 'Reserved' AFTER total_payment");
        self::ensureColumn('parking_transactions', 'updated_at', "ALTER TABLE parking_transactions ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        self::ensureColumn('user_violations', 'related_reservation_id', "ALTER TABLE user_violations ADD COLUMN related_reservation_id INT NULL AFTER description");
        self::ensureColumn('user_violations', 'created_by', "ALTER TABLE user_violations ADD COLUMN created_by VARCHAR(50) NOT NULL DEFAULT 'system' AFTER related_reservation_id");
        self::ensureColumn('user_violations', 'created_at', "ALTER TABLE user_violations ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by");
        self::ensureColumn('booth_teller_accounts', 'teller_details', "ALTER TABLE booth_teller_accounts ADD COLUMN teller_details VARCHAR(255) NULL AFTER teller_name");
        self::ensureColumn('booth_teller_accounts', 'is_active', "ALTER TABLE booth_teller_accounts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER pin_code");
        self::ensureColumn('booth_teller_accounts', 'last_login_at', "ALTER TABLE booth_teller_accounts ADD COLUMN last_login_at DATETIME NULL AFTER is_active");
        self::ensureColumn('booth_teller_accounts', 'updated_at', "ALTER TABLE booth_teller_accounts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

        self::ensureIndex('reservations', 'uq_reservations_barcode_value', "CREATE UNIQUE INDEX uq_reservations_barcode_value ON reservations (barcode_value)");
        self::$connection->query("
            INSERT IGNORE INTO vehicles (user_id, vehicle_type, plate_number, brand, model, color)
            SELECT id, vehicle_type, UPPER(TRIM(plate_number)), vehicle_brand, vehicle_model, vehicle_color
            FROM users
            WHERE vehicle_type IN ('Car', 'Motorcycle')
              AND plate_number IS NOT NULL
              AND TRIM(plate_number) <> ''
        ");
        self::ensureIndex('vehicles', 'uq_vehicles_user_plate', "CREATE UNIQUE INDEX uq_vehicles_user_plate ON vehicles (user_id, plate_number)");
        self::ensureIndex('users', 'idx_users_google_id', "CREATE INDEX idx_users_google_id ON users (google_id)");
        self::ensureIndex('reservations', 'idx_reservations_vehicle_id', "CREATE INDEX idx_reservations_vehicle_id ON reservations (vehicle_id)");
        self::ensureIndex('parking_transactions', 'uq_parking_transactions_reservation_id', "CREATE UNIQUE INDEX uq_parking_transactions_reservation_id ON parking_transactions (reservation_id)");
        self::ensureIndex('booth_teller_accounts', 'idx_booth_teller_accounts_active', "CREATE INDEX idx_booth_teller_accounts_active ON booth_teller_accounts (is_active, created_at)");
        self::ensureIndex('user_violations', 'idx_user_violations_user_created', "CREATE INDEX idx_user_violations_user_created ON user_violations (user_id, created_at)");
        self::ensureIndex('user_violations', 'idx_user_violations_type_created', "CREATE INDEX idx_user_violations_type_created ON user_violations (violation_type, created_at)");

        self::$connection->query("
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

        self::$connection->query("
            ALTER TABLE users
            MODIFY full_name VARCHAR(150) NOT NULL,
            MODIFY email VARCHAR(100) NOT NULL,
            MODIFY password_hash VARCHAR(255) NOT NULL DEFAULT '',
            MODIFY role ENUM('user', 'admin', 'booth') NOT NULL DEFAULT 'user',
            MODIFY status ENUM('Active', 'Disabled') NOT NULL DEFAULT 'Active',
            MODIFY warning_count INT NOT NULL DEFAULT 0,
            MODIFY account_status ENUM('active', 'locked') NOT NULL DEFAULT 'active'
        ");

        self::ensureReservationStatusEnum();
        self::ensureBoothStatusEnum();
    }

    private static function ensureColumn($tableName, $columnName, $alterSql)
    {
        $table = self::escapeIdentifier($tableName);
        $column = self::escapeStringLiteral($columnName);
        $result = self::$connection->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        if ($result->num_rows === 0) {
            self::$connection->query($alterSql);
        }
    }

    private static function ensureIndex($tableName, $indexName, $createSql)
    {
        $table = self::escapeIdentifier($tableName);
        $index = self::escapeStringLiteral($indexName);
        $result = self::$connection->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");

        if ($result->num_rows === 0) {
            self::$connection->query($createSql);
        }
    }

    private static function ensureReservationStatusEnum()
    {
        self::$connection->query("
            ALTER TABLE reservations
            MODIFY status ENUM(
                'pending',
                'confirmed',
                'cancelled',
                'Reserved',
                'Parked',
                'Exited',
                'Unpaid',
                'Paid',
                'Completed',
                'Cancelled'
            ) NOT NULL DEFAULT 'Reserved'
        ");

        self::$connection->query("
            UPDATE reservations
            SET status = CASE
                WHEN status IN ('pending', 'confirmed') THEN 'Reserved'
                WHEN status = 'cancelled' THEN 'Cancelled'
                WHEN status IS NULL OR status = '' THEN 'Reserved'
                ELSE status
            END
        ");

        self::$connection->query("
            ALTER TABLE reservations
            MODIFY status ENUM(
                'Reserved',
                'Parked',
                'Exited',
                'Unpaid',
                'Paid',
                'Completed',
                'Cancelled'
            ) NOT NULL DEFAULT 'Reserved'
        ");
    }

    private static function ensureBoothStatusEnum()
    {
        self::$connection->query("
            ALTER TABLE parking_transactions
            MODIFY booth_status ENUM(
                'Reserved',
                'Parked',
                'Exited',
                'Unpaid',
                'Paid',
                'Completed'
            ) NOT NULL DEFAULT 'Reserved'
        ");
    }

    private static function escapeIdentifier($identifier)
    {
        return str_replace('`', '``', $identifier);
    }

    private static function escapeStringLiteral($value)
    {
        return self::$connection->real_escape_string($value);
    }

    private static function schemaCacheFile(string $database): string
    {
        $cacheDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }

        return $cacheDirectory . DIRECTORY_SEPARATOR . 'schema-' . md5($database) . '.json';
    }

    private static function isSchemaCacheFresh(string $database): bool
    {
        $cacheFile = self::schemaCacheFile($database);

        if (!is_file($cacheFile)) {
            return false;
        }

        $payload = json_decode((string) file_get_contents($cacheFile), true);

        if (!is_array($payload)) {
            return false;
        }

        if (($payload['version'] ?? null) !== self::SCHEMA_CACHE_VERSION) {
            return false;
        }

        $generatedAt = strtotime((string) ($payload['generated_at'] ?? ''));
        if ($generatedAt === false) {
            return true;
        }

        return (time() - $generatedAt) < self::SCHEMA_CACHE_MAX_AGE;
    }

    private static function markSchemaCacheReady(string $database): void
    {
        file_put_contents(self::schemaCacheFile($database), json_encode([
            'version' => self::SCHEMA_CACHE_VERSION,
            'database' => $database,
            'generated_at' => date('c')
        ], JSON_UNESCAPED_SLASHES));
    }
}
