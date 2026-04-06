ALTER TABLE feedback_messages
  ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS admin_reply TEXT NULL AFTER message,
  ADD COLUMN IF NOT EXISTS replied_at DATETIME NULL AFTER submitted_at;

ALTER TABLE feedback_messages
  MODIFY COLUMN status ENUM('Pending', 'Replied', 'Resolved') NOT NULL DEFAULT 'Pending';
