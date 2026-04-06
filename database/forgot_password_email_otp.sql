USE sndrapark_db;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_password_resets_email (email)
);

CREATE TABLE IF NOT EXISTS smtp_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    app_password VARCHAR(255) NOT NULL,
    host VARCHAR(255) NOT NULL DEFAULT 'smtp.gmail.com',
    port INT NOT NULL DEFAULT 587,
    encryption VARCHAR(50) NOT NULL DEFAULT 'tls'
);

INSERT INTO smtp_settings (email, app_password, host, port, encryption)
SELECT 'yourgmail@gmail.com', 'your_gmail_app_password', 'smtp.gmail.com', 587, 'tls'
WHERE NOT EXISTS (
    SELECT 1 FROM smtp_settings
);
