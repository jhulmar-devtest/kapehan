-- Repair a database whose migration history records the recipient migration
-- as applied even though the live payments table is missing both columns.
ALTER TABLE `payments`
  ADD COLUMN `recipient_name` VARCHAR(100) DEFAULT NULL AFTER `reference_number`,
  ADD COLUMN `recipient_number` VARCHAR(20) DEFAULT NULL AFTER `recipient_name`;
