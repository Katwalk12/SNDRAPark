CREATE DATABASE IF NOT EXISTS sndrapark_db;
USE sndrapark_db;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  birth_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reservations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  barcode_value VARCHAR(120) NOT NULL UNIQUE,
  parking_floor VARCHAR(50) NOT NULL,
  parking_slot VARCHAR(50) NOT NULL,
  reservation_date DATE NOT NULL,
  reserved_time_in TIME NOT NULL,
  reserved_time_out TIME NOT NULL,
  reservation_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  status ENUM('Reserved', 'Parked', 'Exited', 'Unpaid', 'Paid', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Reserved',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservations_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS parking_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT UNSIGNED NOT NULL UNIQUE,
  actual_time_in DATETIME NULL,
  actual_time_out DATETIME NULL,
  total_hours DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  overtime_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  total_payment DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  booth_status ENUM('Reserved', 'Parked', 'Exited', 'Unpaid', 'Paid', 'Completed') NOT NULL DEFAULT 'Reserved',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_transactions_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT UNSIGNED NOT NULL UNIQUE,
  amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('Unpaid', 'Paid', 'Void') NOT NULL DEFAULT 'Unpaid',
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  reservation_id INT UNSIGNED NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_notifications_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN birth_date DATE NULL AFTER full_name',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'birth_date'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE reservations ADD COLUMN barcode_value VARCHAR(120) NULL AFTER user_id',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'barcode_value'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE reservations ADD COLUMN parking_floor VARCHAR(50) NULL AFTER barcode_value',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'parking_floor'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE reservations ADD COLUMN parking_slot VARCHAR(50) NULL AFTER parking_floor',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'parking_slot'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE reservations ADD COLUMN reserved_time_in TIME NULL AFTER reservation_date',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'reserved_time_in'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE reservations ADD COLUMN reserved_time_out TIME NULL AFTER reserved_time_in',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'reserved_time_out'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE reservations ADD COLUMN reservation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER reserved_time_out',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'reservation_fee'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE reservations ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'updated_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) > 0,
    'ALTER TABLE reservations MODIFY parking_slot_id INT NULL',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'parking_slot_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE reservations
MODIFY status ENUM('Reserved', 'Parked', 'Exited', 'Unpaid', 'Paid', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Reserved';

UPDATE reservations
SET barcode_value = CONCAT('SP-LEGACY-', id)
WHERE barcode_value IS NULL OR barcode_value = '';

UPDATE reservations
SET parking_floor = COALESCE(parking_floor, 'LG'),
    parking_slot = COALESCE(parking_slot, CONCAT('L', id)),
    reserved_time_in = COALESCE(reserved_time_in, '09:00:00'),
    reserved_time_out = COALESCE(reserved_time_out, '12:00:00'),
    reservation_fee = COALESCE(reservation_fee, 20.00),
    status = CASE
      WHEN status IS NULL OR status = '' THEN 'Reserved'
      ELSE status
    END;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE reservations MODIFY barcode_value VARCHAR(120) NOT NULL',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND COLUMN_NAME = 'barcode_value'
    AND IS_NULLABLE = 'NO'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'CREATE UNIQUE INDEX uq_reservations_barcode_value ON reservations (barcode_value)',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reservations'
    AND INDEX_NAME = 'uq_reservations_barcode_value'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO users (
  id,
  full_name,
  email,
  password_hash,
  birth_date
)
VALUES
  (1, 'Mark Almocera', 'mark@example.com', 'demo-user-hash', '2002-01-12'),
  (2, 'Princess Jhaydee', 'princess@example.com', 'demo-user-hash', '2001-08-19')
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  password_hash = VALUES(password_hash),
  birth_date = VALUES(birth_date);

INSERT INTO reservations (
  id,
  user_id,
  barcode_value,
  parking_floor,
  parking_slot,
  reservation_date,
  reserved_time_in,
  reserved_time_out,
  reservation_fee,
  status
)
VALUES
  (1, 1, 'SP-3RDFLOOR-C4-100001', '3rd Floor', 'C4', '2026-03-26', '09:00:00', '12:00:00', 20.00, 'Reserved'),
  (2, 2, 'SP-LG-L8-100002', 'LG', 'L8', '2026-03-26', '13:00:00', '16:00:00', 20.00, 'Reserved')
ON DUPLICATE KEY UPDATE
  barcode_value = VALUES(barcode_value),
  parking_floor = VALUES(parking_floor),
  parking_slot = VALUES(parking_slot),
  reservation_date = VALUES(reservation_date),
  reserved_time_in = VALUES(reserved_time_in),
  reserved_time_out = VALUES(reserved_time_out),
  reservation_fee = VALUES(reservation_fee),
  status = VALUES(status);

INSERT INTO parking_transactions (
  reservation_id,
  actual_time_in,
  actual_time_out,
  total_hours,
  overtime_fee,
  total_payment,
  booth_status
)
VALUES
  (1, NULL, NULL, 0.00, 0.00, 0.00, 'Reserved'),
  (2, NULL, NULL, 0.00, 0.00, 0.00, 'Reserved')
ON DUPLICATE KEY UPDATE
  booth_status = VALUES(booth_status),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO payments (
  reservation_id,
  amount,
  payment_status,
  paid_at
)
VALUES
  (1, 20.00, 'Unpaid', NULL),
  (2, 20.00, 'Unpaid', NULL)
ON DUPLICATE KEY UPDATE
  amount = VALUES(amount),
  payment_status = VALUES(payment_status),
  paid_at = VALUES(paid_at),
  updated_at = CURRENT_TIMESTAMP;
