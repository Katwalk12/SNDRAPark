-- Vehicle-Centric Update: Add vehicle columns to users table
-- Run this migration in phpMyAdmin on sndrapark_db

USE sndrapark_db;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS vehicle_type     ENUM('Motorcycle','Car') NULL DEFAULT NULL AFTER birth_date,
  ADD COLUMN IF NOT EXISTS plate_number     VARCHAR(20)              NULL DEFAULT NULL AFTER vehicle_type,
  ADD COLUMN IF NOT EXISTS vehicle_brand    VARCHAR(100)             NULL DEFAULT NULL AFTER plate_number,
  ADD COLUMN IF NOT EXISTS vehicle_color    VARCHAR(50)              NULL DEFAULT NULL AFTER vehicle_brand;
