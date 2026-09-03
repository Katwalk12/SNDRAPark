-- ============================================================
-- SNDRA Park - Password & Login Security Policy
-- Adds failed-login lockout tracking and password ageing data.
-- Safe to run multiple times (MariaDB IF NOT EXISTS support).
-- ============================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts INT NOT NULL DEFAULT 0 AFTER password_hash;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_failed_login_at DATETIME NULL AFTER failed_login_attempts;
ALTER TABLE users ADD COLUMN IF NOT EXISTS login_locked_until DATETIME NULL AFTER last_failed_login_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER login_locked_until;

-- Treat existing accounts as if the password was set when the account was created
-- so the "change your password periodically" reminder has a baseline to work from.
UPDATE users
SET password_changed_at = created_at
WHERE password_changed_at IS NULL;

-- Password strength / ageing policy knobs used by PasswordPolicy.
INSERT INTO security_settings (setting_key, setting_value, setting_type, description, is_system) VALUES
    ('password_require_special_chars', 'true', 'boolean', 'Require at least one special character in passwords', 1),
    ('password_max_age_days', '90', 'int', 'Days before users are reminded to change their password (0 disables)', 1),
    ('password_expiry_warning_days', '14', 'int', 'Days before expiry that the change-password reminder starts showing', 1),
    ('password_block_personal_info', 'true', 'boolean', 'Reject passwords containing the user name, email or birth date', 1)
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    setting_type = VALUES(setting_type),
    description = VALUES(description);

-- Failed login lockout policy (max_login_attempts / login_lockout_minutes already exist,
-- this keeps them present on fresh installs).
INSERT INTO security_settings (setting_key, setting_value, setting_type, description, is_system) VALUES
    ('max_login_attempts', '5', 'int', 'Failed login attempts allowed before the account is temporarily locked', 1),
    ('login_lockout_minutes', '15', 'int', 'Minutes an account stays locked after too many failed logins', 1)
ON DUPLICATE KEY UPDATE
    setting_type = VALUES(setting_type),
    description = VALUES(description);

CREATE INDEX IF NOT EXISTS idx_users_login_locked_until ON users (login_locked_until);
