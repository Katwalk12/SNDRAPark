USE sndrapark_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS smtp_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    host VARCHAR(255) NOT NULL DEFAULT 'smtp.gmail.com',
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    port INT NOT NULL DEFAULT 587,
    encryption VARCHAR(50) NOT NULL DEFAULT 'tls'
);

INSERT INTO smtp_settings (host, email, password, port, encryption)
SELECT 'smtp.gmail.com', 'yourgmail@gmail.com', 'your_gmail_app_password', 587, 'tls'
WHERE NOT EXISTS (
    SELECT 1 FROM smtp_settings
);
