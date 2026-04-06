CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  barcode_value VARCHAR(120) NOT NULL,
  parking_floor VARCHAR(50) NULL,
  parking_slot VARCHAR(50) NULL,
  reservation_date DATE NOT NULL,
  reserved_time_in TIME NULL,
  reserved_time_out TIME NULL,
  reservation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('Reserved', 'Parked', 'Exited', 'Unpaid', 'Paid', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Reserved',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS parking_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL UNIQUE,
  actual_time_in DATETIME NULL,
  actual_time_out DATETIME NULL,
  total_hours_stayed DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  extra_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('Reserved', 'Pending', 'Unpaid', 'Paid') NOT NULL DEFAULT 'Reserved',
  booth_status ENUM('Reserved', 'Parked', 'Exited', 'Unpaid', 'Paid', 'Completed') NOT NULL DEFAULT 'Reserved',
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS barcode_value VARCHAR(120) NOT NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS parking_floor VARCHAR(50) NULL AFTER barcode_value,
  ADD COLUMN IF NOT EXISTS parking_slot VARCHAR(50) NULL AFTER parking_floor,
  ADD COLUMN IF NOT EXISTS reserved_time_in TIME NULL AFTER reservation_date,
  ADD COLUMN IF NOT EXISTS reserved_time_out TIME NULL AFTER reserved_time_in,
  ADD COLUMN IF NOT EXISTS reservation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER reserved_time_out,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE parking_transactions
  ADD COLUMN IF NOT EXISTS total_hours_stayed DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER actual_time_out,
  ADD COLUMN IF NOT EXISTS extra_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_hours_stayed,
  ADD COLUMN IF NOT EXISTS total_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER extra_fee,
  ADD COLUMN IF NOT EXISTS payment_status ENUM('Reserved', 'Pending', 'Unpaid', 'Paid') NOT NULL DEFAULT 'Reserved' AFTER total_payment,
  ADD COLUMN IF NOT EXISTS booth_status ENUM('Reserved', 'Parked', 'Exited', 'Unpaid', 'Paid', 'Completed') NOT NULL DEFAULT 'Reserved' AFTER payment_status,
  ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL AFTER booth_status;
