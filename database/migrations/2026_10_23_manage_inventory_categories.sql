CREATE TABLE `inventory_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `inventory_categories` (`name`, `sort_order`)
SELECT DISTINCT `category`, CASE `category`
  WHEN 'Coffee & Tea' THEN 1
  WHEN 'Dairy & Alternatives' THEN 2
  WHEN 'Syrups & Sauces' THEN 3
  WHEN 'Dry Goods & Baking' THEN 4
  WHEN 'Cups & Packaging' THEN 5
  ELSE 99
END
FROM `inventory_items`;

INSERT INTO `inventory_categories` (`name`, `sort_order`)
SELECT 'Other', 99
WHERE NOT EXISTS (SELECT 1 FROM `inventory_categories` WHERE `name` = 'Other');
