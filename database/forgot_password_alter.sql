USE sndrapark_db;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN reset_otp_hash VARCHAR(255) NULL AFTER password_hash',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'reset_otp_hash'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN reset_otp_expires_at DATETIME NULL AFTER reset_otp_hash',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'reset_otp_expires_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN reset_otp_verified_at DATETIME NULL AFTER reset_otp_expires_at',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'reset_otp_verified_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
