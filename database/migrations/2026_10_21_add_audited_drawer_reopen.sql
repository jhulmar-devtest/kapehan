-- Preserve every drawer close and allow an auditable same-day reopen.
CREATE TABLE `cash_drawer_closures` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drawer_day_id` INT UNSIGNED NOT NULL,
  `closed_by` INT UNSIGNED DEFAULT NULL,
  `closing_amount` DECIMAL(10,2) NOT NULL,
  `expected_closing_amount` DECIMAL(10,2) NOT NULL,
  `variance` DECIMAL(10,2) NOT NULL,
  `closed_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_drawer_closure_day_time` (`drawer_day_id`,`closed_at`),
  CONSTRAINT `fk_drawer_closure_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drawer_closure_cashier` FOREIGN KEY (`closed_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_drawer_reopens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drawer_day_id` INT UNSIGNED NOT NULL,
  `closure_id` INT UNSIGNED NOT NULL,
  `reopened_by` INT UNSIGNED DEFAULT NULL,
  `reopened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` VARCHAR(255) NOT NULL,
  `expected_amount` DECIMAL(10,2) NOT NULL,
  `recounted_amount` DECIMAL(10,2) NOT NULL,
  `variance` DECIMAL(10,2) NOT NULL COMMENT 'Recounted cash minus expected cash at reopen',
  PRIMARY KEY (`id`),
  KEY `idx_drawer_reopen_day_time` (`drawer_day_id`,`reopened_at`),
  KEY `idx_drawer_reopen_closure` (`closure_id`),
  CONSTRAINT `fk_drawer_reopen_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drawer_reopen_closure` FOREIGN KEY (`closure_id`) REFERENCES `cash_drawer_closures` (`id`),
  CONSTRAINT `fk_drawer_reopen_cashier` FOREIGN KEY (`reopened_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a closure event for each drawer day already closed before this migration.
INSERT INTO `cash_drawer_closures`
  (`drawer_day_id`,`closed_by`,`closing_amount`,`expected_closing_amount`,`variance`,`closed_at`)
SELECT `id`,`closed_by`,`closing_amount`,`expected_closing_amount`,`variance`,`closed_at`
  FROM `cash_drawer_days`
 WHERE `status` = 'closed' AND `closed_at` IS NOT NULL
   AND `closing_amount` IS NOT NULL AND `expected_closing_amount` IS NOT NULL AND `variance` IS NOT NULL;
