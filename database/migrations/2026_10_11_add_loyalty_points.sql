-- Ledger-backed loyalty points. Earn 1 point per P10 actually paid;
-- redeem each 100 points for P10 off.
CREATE TABLE `loyalty_points_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_type` ENUM('student','faculty') NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED DEFAULT NULL,
  `entry_type` ENUM('earn','redeem','earn_reversal','redeem_reversal','adjustment') NOT NULL,
  `points_delta` INT NOT NULL,
  `amount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loyalty_order_entry` (`order_id`, `entry_type`),
  KEY `idx_loyalty_customer_history` (`customer_type`, `customer_id`, `created_at`),
  CONSTRAINT `fk_loyalty_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
