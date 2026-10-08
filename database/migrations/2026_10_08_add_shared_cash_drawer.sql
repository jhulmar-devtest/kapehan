-- Shared physical drawer: one opening and closing record per business day,
-- optional cashier handoff counts, and auditable cash in/out movements.
CREATE TABLE `cash_drawer_days` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_date` DATE NOT NULL,
  `opening_amount` DECIMAL(10,2) NOT NULL,
  `opened_by` INT UNSIGNED NOT NULL,
  `opened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `closing_amount` DECIMAL(10,2) DEFAULT NULL,
  `expected_closing_amount` DECIMAL(10,2) DEFAULT NULL,
  `variance` DECIMAL(10,2) DEFAULT NULL COMMENT 'Counted cash minus expected cash',
  `closed_by` INT UNSIGNED DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_drawer_business_date` (`business_date`),
  KEY `idx_cash_drawer_status` (`status`),
  CONSTRAINT `fk_cash_drawer_opened_by` FOREIGN KEY (`opened_by`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_cash_drawer_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_drawer_handoffs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drawer_day_id` INT UNSIGNED NOT NULL,
  `handed_from_cashier_id` INT UNSIGNED NOT NULL,
  `recorded_by` INT UNSIGNED NOT NULL,
  `expected_amount` DECIMAL(10,2) NOT NULL,
  `counted_amount` DECIMAL(10,2) NOT NULL,
  `variance` DECIMAL(10,2) NOT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drawer_handoff_day_time` (`drawer_day_id`,`recorded_at`),
  CONSTRAINT `fk_drawer_handoff_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drawer_handoff_from_cashier` FOREIGN KEY (`handed_from_cashier_id`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_drawer_handoff_cashier` FOREIGN KEY (`recorded_by`) REFERENCES `cashiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_drawer_movements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drawer_day_id` INT UNSIGNED NOT NULL,
  `cashier_id` INT UNSIGNED NOT NULL,
  `direction` ENUM('in','out') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drawer_movement_day_time` (`drawer_day_id`,`created_at`),
  CONSTRAINT `fk_drawer_movement_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drawer_movement_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `payments`
  ADD COLUMN `drawer_day_id` INT UNSIGNED DEFAULT NULL AFTER `order_id`,
  ADD KEY `idx_payments_drawer_day` (`drawer_day_id`),
  ADD CONSTRAINT `fk_payments_drawer_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE SET NULL;
