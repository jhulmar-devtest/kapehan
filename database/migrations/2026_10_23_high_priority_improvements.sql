ALTER TABLE `students`
  ADD COLUMN `privacy_notice_accepted_at` DATETIME DEFAULT NULL AFTER `id_declaration`;

ALTER TABLE `faculty`
  ADD COLUMN `privacy_notice_accepted_at` DATETIME DEFAULT NULL AFTER `id_declaration`;

ALTER TABLE `orders`
  ADD COLUMN `delivery_location` VARCHAR(255) DEFAULT NULL AFTER `notes`;

ALTER TABLE `inventory_items`
  ADD COLUMN `category` VARCHAR(80) NOT NULL DEFAULT 'Other' AFTER `name`;

UPDATE `inventory_items`
SET `category` = CASE
  WHEN LOWER(`name`) REGEXP 'milk|cream|butter|cheese' THEN 'Dairy & Alternatives'
  WHEN LOWER(`name`) REGEXP 'coffee|bean|espresso|tea' THEN 'Coffee & Tea'
  WHEN LOWER(`name`) REGEXP 'syrup|sauce|vanilla|caramel' THEN 'Syrups & Sauces'
  WHEN LOWER(`name`) REGEXP 'cup|lid|straw|napkin|bag|packag' THEN 'Cups & Packaging'
  WHEN LOWER(`name`) REGEXP 'powder|matcha|cocoa|chocolate|sugar' THEN 'Dry Goods & Baking'
  ELSE 'Other'
END;

CREATE TABLE `cash_register_readings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drawer_day_id` INT UNSIGNED NOT NULL,
  `reading_type` ENUM('X','Z') NOT NULL,
  `business_date` DATE NOT NULL,
  `issued_at` DATETIME NOT NULL,
  `activity_through` DATETIME NOT NULL,
  `issued_by_role` ENUM('admin','cashier') NOT NULL,
  `issued_by_id` INT UNSIGNED NOT NULL,
  `sales_total` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  `transaction_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `cash_sales` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  `non_cash_sales` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  `expected_cash` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  `cash_counted` DECIMAL(12,2) DEFAULT NULL,
  `cash_variance` DECIMAL(12,2) DEFAULT NULL,
  `cash_in` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  `cash_out` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_register_readings_day_type` (`drawer_day_id`,`reading_type`,`issued_at`),
  CONSTRAINT `fk_register_readings_drawer_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
