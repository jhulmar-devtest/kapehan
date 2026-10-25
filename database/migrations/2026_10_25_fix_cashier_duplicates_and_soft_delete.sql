-- Existing accounts are deduplicated by stable cashier ID before applying this migration.
-- Archive deleted logins so historical cashier-linked records remain readable.
ALTER TABLE `cashiers`
  ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `is_active`,
  DROP INDEX `idx_cashier_id`,
  DROP INDEX `idx_cashier_username`,
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cashiers_username` (`username`),
  ADD KEY `idx_cashiers_deleted_active` (`deleted_at`, `is_active`);
