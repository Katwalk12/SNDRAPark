CREATE TABLE IF NOT EXISTS staff_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash CHAR(64) NOT NULL,
  role ENUM('admin', 'booth') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS staff_login_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id INT UNSIGNED NOT NULL,
  action ENUM('login', 'logout') NOT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_staff_login_logs_staff
    FOREIGN KEY (staff_id) REFERENCES staff_accounts(id)
    ON DELETE CASCADE
);

INSERT INTO staff_accounts (
  full_name,
  email,
  password_hash,
  role,
  is_active
)
VALUES
  (
    'SNDRA Park Administrator',
    'admin@sndrapark.com',
    '3eb3fe66b31e3b4d10fa70b5cad49c7112294af6ae4e476a1c405155d45aa121',
    'admin',
    1
  ),
  (
    'Booth Teller',
    'booth@sndrapark.com',
    'b08e384c9c7162e50dd5249cf82ebec93c8ab396480015749ad807e272bceda3',
    'booth',
    1
  )
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  password_hash = VALUES(password_hash),
  role = VALUES(role),
  is_active = VALUES(is_active);
