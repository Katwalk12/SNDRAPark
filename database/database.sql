CREATE DATABASE IF NOT EXISTS sndrapark_db;
USE sndrapark_db;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  full_name VARCHAR(150) NOT NULL,
  birth_date DATE NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS parking_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slot_code VARCHAR(20) NOT NULL UNIQUE,
  status ENUM('available', 'reserved', 'occupied') DEFAULT 'available',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  parking_slot_id INT NOT NULL,
  reservation_date DATE NOT NULL,
  status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'confirmed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservations_user
    FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_reservations_parking_slot
    FOREIGN KEY (parking_slot_id) REFERENCES parking_slots(id)
);
