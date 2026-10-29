-- Track the preparation lifecycle for walk-in tickets separately from the
-- payment/order status used by sales, receipts, and loyalty accounting.
ALTER TABLE `orders`
  ADD COLUMN `walkin_prep_status` ENUM('preparing','completed') DEFAULT NULL AFTER `status`,
  ADD COLUMN `walkin_completed_at` DATETIME DEFAULT NULL AFTER `walkin_prep_status`,
  ADD KEY `idx_orders_walkin_prep_queue` (`order_type`,`walkin_prep_status`,`created_at`);

-- Historical walk-ins have already been served; leave pre-orders untracked.
UPDATE `orders`
   SET `walkin_prep_status` = 'completed',
       `walkin_completed_at` = COALESCE(`updated_at`, `created_at`)
 WHERE `order_type` = 'walk-in';
