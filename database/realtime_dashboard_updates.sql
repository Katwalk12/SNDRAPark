CREATE TABLE IF NOT EXISTS parking_floors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    floor_name VARCHAR(50) NOT NULL UNIQUE,
    floor_label VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS parking_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    floor_name VARCHAR(50) NOT NULL,
    slot_code VARCHAR(50) NOT NULL,
    row_label VARCHAR(20) NULL,
    status ENUM('Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Available',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    manual_status ENUM('Auto', 'Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Auto',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_parking_slots_floor_slot (floor_name, slot_code)
);

ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS full_name VARCHAR(150) NULL AFTER barcode_value,
    ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS parking_floor VARCHAR(50) NULL AFTER email,
    ADD COLUMN IF NOT EXISTS parking_slot VARCHAR(50) NULL AFTER parking_floor,
    ADD COLUMN IF NOT EXISTS reserved_time_in TIME NULL AFTER reservation_date,
    ADD COLUMN IF NOT EXISTS reservation_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER reserved_time_in;

ALTER TABLE parking_slots
    ADD COLUMN IF NOT EXISTS row_label VARCHAR(20) NULL AFTER slot_code,
    ADD COLUMN IF NOT EXISTS status ENUM('Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Available' AFTER row_label,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN IF NOT EXISTS manual_status ENUM('Auto', 'Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Auto' AFTER is_active;

CREATE INDEX idx_parking_slots_floor_status
    ON parking_slots (floor_name, status, is_active);

CREATE INDEX idx_reservations_dashboard
    ON reservations (reservation_date, status, updated_at);
