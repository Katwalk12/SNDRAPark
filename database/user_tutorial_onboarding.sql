-- Onboarding: remember that a driver has finished the dashboard tutorial.
-- Run this migration in phpMyAdmin on sndrapark_db.
--
-- Keep semicolons out of the comments in this file. run_security_migrations.php
-- splits on ';' and then skips a fragment only when it STARTS with '--', so a
-- semicolon mid-comment hands the rest of that sentence to the server as SQL.

USE sndrapark_db;

-- NULL means "has not finished it yet", which is what makes the tutorial open.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS tutorial_completed_at DATETIME NULL DEFAULT NULL AFTER created_at;

-- Everyone who already has an account is not a new user, so they are marked as
-- done rather than being shown a tutorial for a dashboard they have been using
-- for months. This runs once, at migration time. Accounts created afterwards
-- keep the NULL default and get the tutorial on their first visit.
--
-- The IS NULL guard matters if this file is ever re-run. Without it a second
-- run would overwrite the timestamp recording when each driver first finished
-- the tutorial, and would silently re-mark anyone who had replayed it.
UPDATE users
   SET tutorial_completed_at = NOW()
 WHERE tutorial_completed_at IS NULL;
