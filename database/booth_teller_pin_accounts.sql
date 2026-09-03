CREATE TABLE IF NOT EXISTS booth_teller_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teller_name VARCHAR(150) NOT NULL,
  teller_details VARCHAR(255) NULL,
  pin_code VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_booth_teller_accounts_active (is_active, created_at)
);

-- The application also creates this table automatically from backend/config/db.php.
-- Create teller PINs from the Admin Dashboard so PINs are stored with password_hash().
