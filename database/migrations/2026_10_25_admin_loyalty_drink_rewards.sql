ALTER TABLE `loyalty_points_ledger`
  ADD COLUMN `reward_product_id` INT UNSIGNED DEFAULT NULL AFTER `order_id`,
  ADD KEY `idx_loyalty_reward_product` (`reward_product_id`);

CREATE TABLE `loyalty_product_rewards` (
  `product_id` INT UNSIGNED NOT NULL,
  `points_required` INT UNSIGNED NOT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`),
  KEY `idx_loyalty_rewards_active` (`is_active`,`product_id`),
  CONSTRAINT `fk_loyalty_reward_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `loyalty_product_reward_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `product_name_snapshot` VARCHAR(150) NOT NULL,
  `old_points_required` INT UNSIGNED DEFAULT NULL,
  `new_points_required` INT UNSIGNED NOT NULL,
  `old_discount_amount` DECIMAL(10,2) DEFAULT NULL,
  `new_discount_amount` DECIMAL(10,2) NOT NULL,
  `old_is_active` TINYINT(1) DEFAULT NULL,
  `new_is_active` TINYINT(1) NOT NULL,
  `actor_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_loyalty_reward_audit_product` (`product_id`,`created_at`),
  KEY `idx_loyalty_reward_audit_actor` (`actor_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
