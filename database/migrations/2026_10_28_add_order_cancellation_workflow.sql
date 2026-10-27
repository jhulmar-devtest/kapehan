-- Customer cancellation requests for pre-orders.
-- A request blocks preparation until staff confirms whether payment arrived.
CREATE TABLE `order_cancellation_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `requester_role` VARCHAR(20) NOT NULL,
  `requester_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `status` ENUM('pending','rejected','cancelled_unpaid','refund_required','refunded') NOT NULL DEFAULT 'pending',
  `refund_reference` VARCHAR(100) DEFAULT NULL,
  `handled_by_cashier_id` INT UNSIGNED DEFAULT NULL,
  `handled_by_admin_id` INT UNSIGNED DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cancellation_order` (`order_id`),
  KEY `idx_cancellation_status` (`status`,`created_at`),
  CONSTRAINT `fk_cancellation_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_cancellation_cashier` FOREIGN KEY (`handled_by_cashier_id`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_cancellation_admin` FOREIGN KEY (`handled_by_admin_id`) REFERENCES `admins` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
