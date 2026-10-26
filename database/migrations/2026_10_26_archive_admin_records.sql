-- Preserve admin-managed records when they are removed from active screens.
ALTER TABLE `products`
  ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
  ADD KEY `idx_products_archived` (`archived_at`);

ALTER TABLE `categories`
  ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
  ADD KEY `idx_categories_archived` (`archived_at`);

ALTER TABLE `inventory_items`
  ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
  ADD KEY `idx_inventory_items_archived` (`archived_at`);

ALTER TABLE `inventory_categories`
  ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
  ADD KEY `idx_inventory_categories_archived` (`archived_at`);

ALTER TABLE `size_options`
  ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
  ADD KEY `idx_size_options_archived` (`archived_at`);

ALTER TABLE `addons`
  ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
  ADD KEY `idx_addons_archived` (`archived_at`);

