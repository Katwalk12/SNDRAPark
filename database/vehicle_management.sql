-- Vehicle Management System migration for SNDRA Park
-- Run in phpMyAdmin if your local database was created before this update.

USE sndrapark_db;

SET @user_id_type = (
  SELECT IF(LOWER(COLUMN_TYPE) LIKE '%unsigned%', 'INT UNSIGNED', 'INT')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'id'
  LIMIT 1
);

SET @create_vehicles_sql = CONCAT(
  'CREATE TABLE IF NOT EXISTS vehicles (',
  'vehicle_id ', COALESCE(@user_id_type, 'INT'), ' AUTO_INCREMENT PRIMARY KEY,',
  'user_id ', COALESCE(@user_id_type, 'INT'), ' NOT NULL,',
  'vehicle_type ENUM(''Car'', ''Motorcycle'') NOT NULL,',
  'plate_number VARCHAR(20) NOT NULL,',
  'brand VARCHAR(100) NULL,',
  'model VARCHAR(100) NULL,',
  'color VARCHAR(50) NULL,',
  'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,',
  'CONSTRAINT fk_vehicles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
  ')'
);
PREPARE stmt FROM @create_vehicles_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS vehicle_type ENUM('Motorcycle','Car') NULL DEFAULT NULL AFTER birth_date,
  ADD COLUMN IF NOT EXISTS plate_number VARCHAR(20) NULL DEFAULT NULL AFTER vehicle_type,
  ADD COLUMN IF NOT EXISTS vehicle_brand VARCHAR(100) NULL DEFAULT NULL AFTER plate_number,
  ADD COLUMN IF NOT EXISTS vehicle_model VARCHAR(100) NULL DEFAULT NULL AFTER vehicle_brand,
  ADD COLUMN IF NOT EXISTS vehicle_color VARCHAR(50) NULL DEFAULT NULL AFTER vehicle_model;

ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS vehicle_id INT NULL AFTER user_id;

INSERT IGNORE INTO vehicles (user_id, vehicle_type, plate_number, brand, model, color)
SELECT id, vehicle_type, UPPER(TRIM(plate_number)), vehicle_brand, vehicle_model, vehicle_color
FROM users
WHERE vehicle_type IN ('Car', 'Motorcycle')
  AND plate_number IS NOT NULL
  AND TRIM(plate_number) <> '';

CREATE UNIQUE INDEX IF NOT EXISTS uq_vehicles_user_plate ON vehicles (user_id, plate_number);
CREATE INDEX IF NOT EXISTS idx_reservations_vehicle_id ON reservations (vehicle_id);
