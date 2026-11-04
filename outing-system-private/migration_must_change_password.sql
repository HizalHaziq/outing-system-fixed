-- Adds the must_change_password flag expected by auth.php (MariaDB/XAMPP: IF NOT EXISTS is supported)
-- 0 = normal login, 1 = force password change on next login

ALTER TABLE staff
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

ALTER TABLE student
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- If your auth.php reads the `user` table instead, run this too (skip if the table doesn't exist):
-- ALTER TABLE `user`
--   ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
