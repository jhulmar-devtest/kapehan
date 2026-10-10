-- Add size-aware product recipes and an idempotency record for order usage.
-- Recipe and stock amounts use three decimal places for partial units such
-- as 0.180 L milk per serving.

ALTER TABLE `inventory_items`
  MODIFY COLUMN `quantity_on_hand` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  MODIFY COLUMN `reorder_level` DECIMAL(12,3) NOT NULL DEFAULT 0.000;

ALTER TABLE `inventory_log`
  MODIFY COLUMN `change_amount` DECIMAL(12,3) NOT NULL COMMENT 'negative = deduction, positive = restock';

ALTER TABLE `product_ingredients`
  ADD COLUMN `size_label` VARCHAR(30) NOT NULL DEFAULT '' AFTER `inventory_item_id`,
  ADD KEY `idx_product_ingredients_variant` (`product_id`, `size_label`, `inventory_item_id`);

CREATE TABLE `inventory_order_deductions` (
  `order_id` INT UNSIGNED NOT NULL,
  `actor_role` VARCHAR(20) DEFAULT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `processed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`),
  CONSTRAINT `fk_inventory_deduction_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
