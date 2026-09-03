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
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  manual_status ENUM('Auto', 'Available', 'Reserved', 'Occupied', 'Inactive') NOT NULL DEFAULT 'Auto',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_parking_slots_floor_slot (floor_name, slot_code)
);

CREATE TABLE IF NOT EXISTS feedback_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('Pending', 'Resolved') NOT NULL DEFAULT 'Pending',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

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
);

INSERT INTO system_settings (setting_key, setting_value)
VALUES
  ('system_name', 'SNDRA Park - Web-Based Smart Parking Reservation System'),
  ('contact_number', '+63 917 555 0142'),
  ('gmail_address', 'sndraparksupport@gmail.com'),
  ('parking_base_rate', '20'),
  ('extra_hourly_rate', '10')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

