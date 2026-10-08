-- Add second-party confirmation for shared-drawer handoffs.
-- Existing one-person handoffs remain marked unverified so their history is preserved.
ALTER TABLE `cash_drawer_handoffs`
  ADD COLUMN `status` ENUM('pending','confirmed','disputed','unverified') NOT NULL DEFAULT 'unverified' AFTER `variance`,
  ADD COLUMN `confirmed_by` INT UNSIGNED DEFAULT NULL AFTER `status`,
  ADD COLUMN `confirmation_amount` DECIMAL(10,2) DEFAULT NULL AFTER `confirmed_by`,
  ADD COLUMN `confirmation_variance` DECIMAL(10,2) DEFAULT NULL COMMENT 'Incoming count minus outgoing count' AFTER `confirmation_amount`,
  ADD COLUMN `confirmation_note` VARCHAR(255) DEFAULT NULL AFTER `confirmation_variance`,
  ADD COLUMN `confirmed_at` DATETIME DEFAULT NULL AFTER `confirmation_note`,
  ADD CONSTRAINT `fk_drawer_handoff_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL;
