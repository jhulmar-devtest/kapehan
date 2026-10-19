-- Preserve customer-selected add-ons and their inventory recipes on each order line.
CREATE TABLE `order_detail_addons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_detail_id` INT UNSIGNED NOT NULL,
  `addon_id` INT DEFAULT NULL,
  `addon_name_snapshot` VARCHAR(100) NOT NULL,
  `price_at_time` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity_per_product` DECIMAL(8,3) NOT NULL DEFAULT 1.000,
  PRIMARY KEY (`id`),
  KEY `idx_order_detail_addons_line` (`order_detail_id`),
  KEY `idx_order_detail_addons_addon` (`addon_id`),
  CONSTRAINT `fk_order_detail_addons_line` FOREIGN KEY (`order_detail_id`) REFERENCES `order_details` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_detail_addons_addon` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_detail_addon_ingredients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_detail_addon_id` BIGINT UNSIGNED NOT NULL,
  `inventory_item_id` INT UNSIGNED NOT NULL,
  `inventory_item_name_snapshot` VARCHAR(120) NOT NULL,
  `qty_per_addon` DECIMAL(12,3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_detail_addon_ingredients_line` (`order_detail_addon_id`),
  KEY `idx_order_detail_addon_ingredients_item` (`inventory_item_id`),
  CONSTRAINT `fk_order_detail_addon_ingredients_addon_line` FOREIGN KEY (`order_detail_addon_id`) REFERENCES `order_detail_addons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_detail_addon_ingredients_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One lock row per date/time serializes simultaneous checkouts for a pickup slot.
CREATE TABLE `pickup_slot_locks` (
  `pickup_date` DATE NOT NULL,
  `pickup_time` TIME NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pickup_date`, `pickup_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
