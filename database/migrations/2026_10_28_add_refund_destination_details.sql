ALTER TABLE `order_cancellation_requests`
  ADD COLUMN `refund_gcash_name` VARCHAR(100) DEFAULT NULL AFTER `refund_reference`,
  ADD COLUMN `refund_gcash_number` VARCHAR(20) DEFAULT NULL AFTER `refund_gcash_name`,
  ADD COLUMN `refund_qr_filename` VARCHAR(64) DEFAULT NULL AFTER `refund_gcash_number`;
