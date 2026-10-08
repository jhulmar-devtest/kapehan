-- Track cashier session activity and why a session ended, so the drawer audit
-- trail no longer shows "Still logged in" for cashiers who were logged out
-- automatically (inactivity timeout, deactivated account, closed browser).
--
-- last_activity_at : refreshed (at most once a minute) while the cashier is active.
--                    Sessions idle longer than SESSION_TIMEOUT are closed by
--                    closeStaleCashierSessions() in includes/auth.php.
-- logout_reason    : manual | timeout | deactivated  (NULL on rows created before this migration)
--
-- NOTE: run this when no cashier is mid-shift. Any session still open at the
-- time of the migration is treated as idle since its login time and will be
-- closed by the next sweep.

ALTER TABLE `cashier_sessions`
  ADD COLUMN `last_activity_at` DATETIME DEFAULT NULL AFTER `login_at`,
  ADD COLUMN `logout_reason` VARCHAR(20) DEFAULT NULL AFTER `logout_at`,
  ADD KEY `idx_cs_open` (`logout_at`, `last_activity_at`);

UPDATE `cashier_sessions`
   SET `last_activity_at` = `login_at`
 WHERE `logout_at` IS NULL AND `last_activity_at` IS NULL;
