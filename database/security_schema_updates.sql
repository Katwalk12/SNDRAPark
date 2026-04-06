-- SNDRA Park Security Schema Updates
-- Add audit logging and rate limiting tables

USE sndrapark_db;

-- Audit log table for tracking admin actions and security events
CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    admin_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    resource_id VARCHAR(100) NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    session_id VARCHAR(128) NULL,
    status ENUM('success', 'failure', 'warning') NOT NULL DEFAULT 'success',
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_admin_id (admin_id),
    INDEX idx_action (action),
    INDEX idx_resource_type (resource_type),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status)
);

-- Rate limiting table for tracking API calls and login attempts
CREATE TABLE IF NOT EXISTS rate_limit_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL, -- IP address, user ID, or email
    action_type VARCHAR(50) NOT NULL, -- 'login', 'api_call', 'admin_action', etc.
    attempt_count INT NOT NULL DEFAULT 1,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    window_end TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP NULL,
    is_blocked BOOLEAN NOT NULL DEFAULT FALSE,
    last_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_identifier_action_window (identifier, action_type, window_start),
    INDEX idx_identifier (identifier),
    INDEX idx_action_type (action_type),
    INDEX idx_window_end (window_end),
    INDEX idx_blocked_until (blocked_until),
    INDEX idx_is_blocked (is_blocked)
);

-- Add booth_staff table for booth authentication
CREATE TABLE IF NOT EXISTS booth_staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    api_key VARCHAR(64) NULL UNIQUE,
    session_token VARCHAR(64) NULL UNIQUE,
    token_expires TIMESTAMP NULL,
    booth_location VARCHAR(100) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_booth_staff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_api_key (api_key),
    INDEX idx_session_token (session_token),
    INDEX idx_token_expires (token_expires),
    INDEX idx_is_active (is_active)
);

-- Add session management columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('user', 'admin', 'booth_staff') NOT NULL DEFAULT 'user' AFTER password_hash;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP NULL AFTER role;
ALTER TABLE users ADD COLUMN IF NOT EXISTS session_expires_at TIMESTAMP NULL AFTER last_login_at;

-- Add security settings table for configurable security parameters
CREATE TABLE IF NOT EXISTS security_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    setting_type ENUM('string', 'int', 'boolean', 'json') NOT NULL DEFAULT 'string',
    description TEXT NULL,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key),
    INDEX idx_is_system (is_system)
);

-- Insert default security settings
INSERT IGNORE INTO security_settings (setting_key, setting_value, setting_type, description, is_system) VALUES
('session_timeout_minutes', '30', 'int', 'Session timeout in minutes', TRUE),
('max_login_attempts', '5', 'int', 'Maximum login attempts before lockout', TRUE),
('login_lockout_minutes', '15', 'int', 'Lockout duration in minutes after failed attempts', TRUE),
('rate_limit_login_attempts', '5', 'int', 'Rate limit for login attempts per window', TRUE),
('rate_limit_login_window_minutes', '15', 'int', 'Rate limit window for login attempts in minutes', TRUE),
('rate_limit_api_calls', '100', 'int', 'Rate limit for API calls per window', TRUE),
('rate_limit_api_window_minutes', '1', 'int', 'Rate limit window for API calls in minutes', TRUE),
('rate_limit_admin_actions', '50', 'int', 'Rate limit for admin actions per window', TRUE),
('rate_limit_admin_window_minutes', '5', 'int', 'Rate limit window for admin actions in minutes', TRUE),
('password_min_length', '8', 'int', 'Minimum password length', TRUE),
('password_require_uppercase', 'true', 'boolean', 'Require uppercase letters in password', TRUE),
('password_require_lowercase', 'true', 'boolean', 'Require lowercase letters in password', TRUE),
('password_require_numbers', 'true', 'boolean', 'Require numbers in password', TRUE),
('password_require_special_chars', 'false', 'boolean', 'Require special characters in password', TRUE),
('csrf_token_lifetime_seconds', '3600', 'int', 'CSRF token lifetime in seconds', TRUE),
('audit_log_retention_days', '90', 'int', 'Audit log retention period in days', TRUE),
('enable_audit_logging', 'true', 'boolean', 'Enable audit logging', TRUE),
('enable_rate_limiting', 'true', 'boolean', 'Enable rate limiting', TRUE),
('enable_session_timeout', 'true', 'boolean', 'Enable session timeout enforcement', TRUE);