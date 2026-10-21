-- Opening funds and same-day drawer reopens are controlled by admins.
ALTER TABLE `cash_drawer_days`
  MODIFY COLUMN `opened_by` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `opened_by_admin_id` INT UNSIGNED DEFAULT NULL AFTER `opened_by`,
  ADD CONSTRAINT `fk_cash_drawer_opened_by_admin` FOREIGN KEY (`opened_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

ALTER TABLE `cash_drawer_reopens`
  ADD COLUMN `reopened_by_admin_id` INT UNSIGNED DEFAULT NULL AFTER `reopened_by`,
  ADD CONSTRAINT `fk_drawer_reopen_admin` FOREIGN KEY (`reopened_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;
