-- Sanitized export generated from the live kapehan_db database on 2026-10-10.
-- Contains all base-table schemas and the product_rating_summary view definition.
-- Data is included only for public catalog and test inventory tables: categories, products, addons, product_addons, size_options, inventory_items, product_ingredients.
-- No account, customer, order, payment, session, audit, drawer, OTP, login-attempt, or settings rows are included.
-- Create/select a target database before importing this file.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Schema for `addons`
CREATE TABLE `addons` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `admins`
CREATE TABLE `admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `app_settings`
CREATE TABLE `app_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `audit_log`
CREATE TABLE `audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `actor_type` enum('admin','cashier','student','faculty') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` int unsigned NOT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. orders, products',
  `target_id` int unsigned DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor` (`actor_type`,`actor_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `cashiers`
CREATE TABLE `cashiers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` datetime DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `pos_pin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int unsigned NOT NULL COMMENT 'admin.id who created this account',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_cashier_admin` (`created_by`),
  CONSTRAINT `fk_cashier_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `categories`
CREATE TABLE `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned DEFAULT NULL,
  `name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `icon` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `fk_cat_parent` (`parent_id`),
  CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `email_otps`
CREATE TABLE `email_otps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_type` enum('student','faculty','cashier') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `otp` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` enum('verification','password_reset') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `attempts` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_lookup` (`user_type`,`user_id`,`purpose`),
  KEY `idx_otp_expires` (`expires_at`),
  KEY `idx_otp_email` (`email`,`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `faculty`
CREATE TABLE `faculty` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `faculty_id_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` datetime DEFAULT NULL,
  `id_declaration` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Faculty agreed to ID declaration',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `no_show_count` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `faculty_id_no` (`faculty_id_no`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `inventory_items`
CREATE TABLE `inventory_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `unit` varchar(20) NOT NULL COMMENT 'g, kg, ml, L, pcs',
  `quantity_on_hand` decimal(12,3) NOT NULL DEFAULT '0.000',
  `reorder_level` decimal(12,3) NOT NULL DEFAULT '0.000',
  `cost_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `inventory_log`
CREATE TABLE `inventory_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `inventory_item_id` int unsigned NOT NULL,
  `change_amount` decimal(12,3) NOT NULL COMMENT 'negative = deduction, positive = restock',
  `reason` varchar(30) NOT NULL COMMENT 'sale, restock, waste, correction',
  `supplier` varchar(150) DEFAULT NULL,
  `order_id` int unsigned DEFAULT NULL,
  `actor_role` varchar(20) DEFAULT NULL,
  `actor_id` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `inventory_item_id` (`inventory_item_id`),
  CONSTRAINT `inventory_log_ibfk_1` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `login_attempts`
CREATE TABLE `login_attempts` (
  `identifier_hash` char(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'md5(identifier) — same identifier the app already used as a session key',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`identifier_hash`),
  KEY `idx_login_attempts_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Persistent brute-force throttle, replaces the old session-only version';

-- Schema for `products`
CREATE TABLE `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_sizes` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = show Small/Medium/Large size picker',
  `has_sugar` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = show sugar level picker',
  `has_addons` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = show add-ons checkboxes',
  `price` decimal(8,2) NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `spotlight_pinned` tinyint(1) NOT NULL DEFAULT '0',
  `spotlight_pinned_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_product_category` (`category_id`),
  CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `size_options`
CREATE TABLE `size_options` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(30) NOT NULL,
  `price_adjustment` decimal(8,2) NOT NULL DEFAULT '0.00',
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `students`
CREATE TABLE `students` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_id_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `course` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` datetime DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `id_declaration` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Student agreed to ID declaration',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `no_show_count` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id_no` (`student_id_no`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `cash_drawer_days`
CREATE TABLE `cash_drawer_days` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `business_date` date NOT NULL,
  `opening_amount` decimal(10,2) NOT NULL,
  `opened_by` int unsigned NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('open','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `closing_amount` decimal(10,2) DEFAULT NULL,
  `expected_closing_amount` decimal(10,2) DEFAULT NULL,
  `variance` decimal(10,2) DEFAULT NULL COMMENT 'Counted cash minus expected cash',
  `closed_by` int unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_drawer_business_date` (`business_date`),
  KEY `idx_cash_drawer_status` (`status`),
  KEY `fk_cash_drawer_opened_by` (`opened_by`),
  KEY `fk_cash_drawer_closed_by` (`closed_by`),
  CONSTRAINT `fk_cash_drawer_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cash_drawer_opened_by` FOREIGN KEY (`opened_by`) REFERENCES `cashiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `cash_drawer_handoffs`
CREATE TABLE `cash_drawer_handoffs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `drawer_day_id` int unsigned NOT NULL,
  `handed_from_cashier_id` int unsigned NOT NULL,
  `recorded_by` int unsigned NOT NULL,
  `expected_amount` decimal(10,2) NOT NULL,
  `counted_amount` decimal(10,2) NOT NULL,
  `variance` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','disputed','unverified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unverified',
  `confirmed_by` int unsigned DEFAULT NULL,
  `confirmation_amount` decimal(10,2) DEFAULT NULL,
  `confirmation_variance` decimal(10,2) DEFAULT NULL COMMENT 'Incoming count minus outgoing count',
  `confirmation_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drawer_handoff_day_time` (`drawer_day_id`,`recorded_at`),
  KEY `fk_drawer_handoff_from_cashier` (`handed_from_cashier_id`),
  KEY `fk_drawer_handoff_cashier` (`recorded_by`),
  KEY `fk_drawer_handoff_confirmed_by` (`confirmed_by`),
  CONSTRAINT `fk_drawer_handoff_cashier` FOREIGN KEY (`recorded_by`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_drawer_handoff_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_drawer_handoff_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drawer_handoff_from_cashier` FOREIGN KEY (`handed_from_cashier_id`) REFERENCES `cashiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `cash_drawer_movements`
CREATE TABLE `cash_drawer_movements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `drawer_day_id` int unsigned NOT NULL,
  `cashier_id` int unsigned NOT NULL,
  `direction` enum('in','out') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drawer_movement_day_time` (`drawer_day_id`,`created_at`),
  KEY `fk_drawer_movement_cashier` (`cashier_id`),
  CONSTRAINT `fk_drawer_movement_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_drawer_movement_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `cashier_sessions`
CREATE TABLE `cashier_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cashier_id` int unsigned NOT NULL,
  `login_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` datetime DEFAULT NULL,
  `logout_at` datetime DEFAULT NULL COMMENT 'NULL = still logged in',
  `logout_reason` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cs_cashier` (`cashier_id`),
  KEY `idx_cs_login_at` (`login_at`),
  KEY `idx_cs_open` (`logout_at`,`last_activity_at`),
  CONSTRAINT `fk_cs_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `orders`
CREATE TABLE `orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable e.g. ORD-20240101-0001',
  `order_type` enum('walk-in','pre-order') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','preparing','ready','claimed','cancelled','no_show') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `student_id` int unsigned DEFAULT NULL COMMENT 'NULL for walk-in, can also reference faculty',
  `faculty_id` int unsigned DEFAULT NULL,
  `cashier_id` int unsigned DEFAULT NULL COMMENT 'NULL until cashier processes',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `locked_by` int unsigned DEFAULT NULL COMMENT 'Cashier ID who locked this order for preparation',
  `locked_at` datetime DEFAULT NULL COMMENT 'When the order was locked',
  `lock_expire_at` datetime DEFAULT NULL COMMENT 'When the lock expires (auto-unlock)',
  `pickup_time` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `customer_arrived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `fk_order_cashier` (`cashier_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_type` (`order_type`),
  KEY `idx_orders_created_at` (`created_at`),
  KEY `idx_orders_student` (`student_id`),
  KEY `idx_orders_locked` (`locked_by`,`locked_at`),
  KEY `idx_orders_lock_expire` (`lock_expire_at`),
  KEY `fk_order_faculty` (`faculty_id`),
  CONSTRAINT `fk_order_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`),
  CONSTRAINT `fk_order_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `payments`
CREATE TABLE `payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL COMMENT 'One payment per order',
  `drawer_day_id` int unsigned DEFAULT NULL,
  `payment_method` enum('cash','online','GCash','PayMaya','Online Banking') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `change_given` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('pending','paid','refunded','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For online payments (GCash, PayMaya, etc.)',
  `recipient_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  KEY `idx_payments_drawer_day` (`drawer_day_id`),
  CONSTRAINT `fk_payment_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_payments_drawer_day` FOREIGN KEY (`drawer_day_id`) REFERENCES `cash_drawer_days` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `product_addons`
CREATE TABLE `product_addons` (
  `product_id` int unsigned NOT NULL,
  `addon_id` int NOT NULL,
  PRIMARY KEY (`product_id`,`addon_id`),
  KEY `fk_pa_addon` (`addon_id`),
  CONSTRAINT `fk_pa_addon` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `product_ingredients`
CREATE TABLE `product_ingredients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `inventory_item_id` int unsigned NOT NULL,
  `size_label` varchar(30) NOT NULL DEFAULT '',
  `qty_per_unit` decimal(10,3) NOT NULL COMMENT 'amount consumed per 1 unit sold',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `inventory_item_id` (`inventory_item_id`),
  KEY `idx_product_ingredients_variant` (`product_id`,`size_label`,`inventory_item_id`),
  CONSTRAINT `product_ingredients_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_ingredients_ibfk_2` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `refund_requests`
CREATE TABLE `refund_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `student_id` int unsigned NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int unsigned DEFAULT NULL COMMENT 'admin.id',
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_refund_order` (`order_id`),
  KEY `fk_refund_student` (`student_id`),
  KEY `fk_refund_admin` (`reviewed_by`),
  CONSTRAINT `fk_refund_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`),
  CONSTRAINT `fk_refund_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_refund_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `inventory_order_deductions`
CREATE TABLE `inventory_order_deductions` (
  `order_id` int unsigned NOT NULL,
  `actor_role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_id` int unsigned DEFAULT NULL,
  `processed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`),
  CONSTRAINT `fk_inventory_deduction_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema for `order_details`
CREATE TABLE `order_details` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `quantity` tinyint NOT NULL,
  `price_at_time` decimal(8,2) NOT NULL COMMENT 'Snapshot price at time of order',
  `subtotal` decimal(10,2) NOT NULL COMMENT 'quantity * price_at_time',
  `customization_note` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. Large · Less Sugar · +Oat Milk, +Extra Shot',
  PRIMARY KEY (`id`),
  KEY `idx_od_order` (`order_id`),
  KEY `idx_od_product` (`product_id`),
  CONSTRAINT `fk_od_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_od_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `order_feedback`
CREATE TABLE `order_feedback` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL COMMENT 'One feedback per order',
  `student_id` int unsigned NOT NULL,
  `faculty_id` int unsigned DEFAULT NULL,
  `cashier_id` int unsigned DEFAULT NULL COMMENT 'NULL for walk-in orders processed by unknown cashier',
  `rating` tinyint NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  KEY `idx_fb_student` (`student_id`),
  KEY `idx_fb_cashier` (`cashier_id`),
  KEY `idx_fb_rating` (`rating`),
  KEY `idx_fb_created` (`created_at`),
  KEY `fk_feedback_faculty` (`faculty_id`),
  CONSTRAINT `fk_fb_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `cashiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fb_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fb_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedback_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Schema for `payment_denominations`
CREATE TABLE `payment_denominations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` int unsigned NOT NULL,
  `denomination` decimal(8,2) NOT NULL COMMENT 'e.g. 1000, 500, 0.50',
  `quantity` smallint unsigned NOT NULL DEFAULT '0',
  `subtotal` decimal(10,2) GENERATED ALWAYS AS ((`denomination` * `quantity`)) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_payment_id` (`payment_id`),
  CONSTRAINT `fk_denom_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bill and coin breakdown for cash payments';

-- Schema for `product_ratings`
CREATE TABLE `product_ratings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `feedback_id` int unsigned NOT NULL COMMENT 'Links to order_feedback.id',
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `student_id` int unsigned DEFAULT NULL,
  `faculty_id` int unsigned DEFAULT NULL,
  `rating` tinyint NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_order` (`product_id`,`order_id`),
  KEY `fk_pr_feedback` (`feedback_id`),
  KEY `fk_pr_order` (`order_id`),
  KEY `idx_pr_product` (`product_id`),
  KEY `idx_pr_student` (`student_id`),
  KEY `idx_pr_rating` (`rating`),
  KEY `fk_pr_faculty` (`faculty_id`),
  CONSTRAINT `fk_pr_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`),
  CONSTRAINT `fk_pr_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `order_feedback` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pr_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pr_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- View definer removed and security changed to INVOKER for portability.
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `product_rating_summary` AS select `p`.`id` AS `product_id`,`p`.`name` AS `product_name`,`p`.`image_path` AS `image_path`,`c`.`name` AS `category_name`,count(`pr`.`id`) AS `total_ratings`,round(avg(`pr`.`rating`),2) AS `avg_rating`,sum((`pr`.`rating` = 5)) AS `five_star`,sum((`pr`.`rating` = 4)) AS `four_star`,sum((`pr`.`rating` = 3)) AS `three_star`,sum((`pr`.`rating` = 2)) AS `two_star`,sum((`pr`.`rating` = 1)) AS `one_star`,coalesce((select sum(`od`.`quantity`) from `order_details` `od` where (`od`.`product_id` = `p`.`id`)),0) AS `total_sold` from ((`products` `p` left join `categories` `c` on((`p`.`category_id` = `c`.`id`))) left join `product_ratings` `pr` on((`pr`.`product_id` = `p`.`id`))) group by `p`.`id`,`p`.`name`,`p`.`image_path`,`c`.`name`;

SET FOREIGN_KEY_CHECKS=1;

-- Sanitized catalog/test rows for `categories`
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('1', NULL, 'Coffee', '1', NULL, '2026-04-28 19:11:46');
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('2', '1', 'Signature Coffee', '1', NULL, '2026-04-28 19:11:46');
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('3', '1', 'Hot Coffee', '2', NULL, '2026-04-28 19:21:02');
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('4', '1', 'Iced Coffee', '3', NULL, '2026-04-28 20:21:43');
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('5', NULL, 'Other Drinks', '1', NULL, '2026-04-28 22:05:03');
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('6', '5', 'Matcha Series', '1', NULL, '2026-04-28 22:05:03');
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('7', '5', 'Non-Coffee', '2', NULL, '2026-04-28 22:07:09');
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('8', '5', 'Milktea', '3', NULL, '2026-04-28 22:11:04');
INSERT INTO `categories` (`id`, `parent_id`, `name`, `sort_order`, `icon`, `created_at`) VALUES ('9', '5', 'Cocktails', '4', NULL, '2026-04-28 22:14:23');

-- Sanitized catalog/test rows for `addons`
INSERT INTO `addons` (`id`, `name`, `price`, `status`, `created_at`) VALUES ('1', 'Espresso', '30.00', 'active', '2026-04-28 19:05:46');
INSERT INTO `addons` (`id`, `name`, `price`, `status`, `created_at`) VALUES ('2', 'Syrup', '20.00', 'active', '2026-04-28 19:06:10');
INSERT INTO `addons` (`id`, `name`, `price`, `status`, `created_at`) VALUES ('3', 'Nata', '15.00', 'active', '2026-04-28 19:06:28');

-- Sanitized catalog/test rows for `size_options`
INSERT INTO `size_options` (`id`, `label`, `price_adjustment`, `sort_order`, `is_active`) VALUES ('1', '16oz', '0.00', '1', '1');
INSERT INTO `size_options` (`id`, `label`, `price_adjustment`, `sort_order`, `is_active`) VALUES ('2', '22oz', '20.00', '2', '1');

-- Sanitized catalog/test rows for `inventory_items`
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('1', 'Fresh milk', 'L', '20.000', '5.000', '105.00', '2026-10-09 09:06:32');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('2', '16oz hot cups', 'pcs', '0.000', '-1.000', '2.80', '2026-10-09 09:15:45');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('3', 'Espresso coffee beans', 'kg', '5.000', '1.500', '1000.00', '2026-10-09 09:06:38');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('4', 'Deacf coffee beans', 'kg', '1.000', '0.300', '1400.00', '2026-10-09 09:06:44');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('5', 'Oat milk', 'L', '6.000', '2.000', '180.00', '2026-10-09 09:06:26');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('6', 'Soy milk', 'L', '6.000', '2.000', '130.00', '2026-10-09 09:06:20');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('7', 'Condensed milk', 'L', '4.000', '1.000', '170.00', '2026-10-09 09:06:48');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('8', 'Evaporated milk', 'L', '4.000', '1.000', '120.00', '2026-10-09 09:04:52');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('9', 'Whipping cream', 'L', '3.000', '1.000', '250.00', '2026-10-09 09:05:04');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('10', 'Vanilla syrup', 'L', '2.000', '0.500', '350.00', '2026-10-09 09:05:18');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('11', 'Caramel syrup', 'L', '2.000', '0.500', '350.00', '2026-10-09 09:05:29');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('12', 'Hazelnut syrup', 'L', '2.000', '0.500', '380.00', '2026-10-09 09:05:42');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('13', 'Sugar syrup', 'L', '3.000', '1.000', '150.00', '2026-10-09 09:05:53');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('14', 'Caramel sauce', 'L', '2.000', '0.500', '450.00', '2026-10-09 09:06:15');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('15', 'Chocolate sauce', 'L', '2.000', '0.500', '400.00', '2026-10-09 09:07:07');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('16', 'White chocolate sauce', 'L', '2.000', '0.500', '450.00', '2026-10-09 09:07:26');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('17', 'Matcha powder', 'kh', '1.000', '0.250', '1800.00', '2026-10-09 09:07:57');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('18', 'Chocolate powder', 'kg', '1.000', '0.250', '500.00', '2026-10-09 09:08:10');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('19', 'Cocoa powder', 'kg', '0.500', '0.150', '700.00', '2026-10-09 09:08:49');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('20', 'Black tea leaves', 'kg', '0.500', '0.150', '800.00', '2026-10-09 09:09:07');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('21', 'Green tea leaves', 'kg', '0.500', '0.150', '1000.00', '2026-10-09 09:09:22');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('22', 'Chai concentrate', 'L', '2.000', '0.500', '250.00', '2026-10-09 09:09:35');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('23', 'Strawberry puree', 'L', '2.000', '0.500', '350.00', '2026-10-09 09:09:50');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('24', 'Blueberry syrup', 'L', '2.000', '0.500', '400.00', '2026-10-09 09:10:05');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('25', 'Fruit jelly or popping boba', 'kg', '2.000', '0.500', '250.00', '2026-10-09 09:10:19');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('26', 'Ice', 'kg', '20.000', '5.000', '5.00', '2026-10-09 09:10:41');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('27', '16oz cups', 'pcs', '1000.000', '250.000', '2.20', '2026-10-09 09:15:55');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('28', '22oz cups', 'pcs', '1000.000', '250.000', '2.80', '2026-10-09 09:17:07');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('29', 'Straws', 'pcs', '1000.000', '250.000', '0.45', '2026-10-09 09:18:21');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('30', 'Cup lids', 'pcs', '1500.000', '350.000', '0.90', '2026-10-09 09:18:45');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('31', 'Cup sleeves', 'pcs', '500.000', '100.000', '0.70', '2026-10-09 09:19:00');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('32', 'Stirrers', 'pcs', '500.000', '100.000', '0.20', '2026-10-09 09:19:43');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('33', 'Napkins', 'pcs', '2000.000', '500.000', '0.10', '2026-10-09 09:19:56');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('34', 'Cup carriers', 'pcs', '200.000', '50.000', '4.50', '2026-10-09 09:20:16');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('35', 'Takeaway bags', 'pcs', '300.000', '75.000', '3.00', '2026-10-09 09:20:31');
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `quantity_on_hand`, `reorder_level`, `cost_per_unit`, `updated_at`) VALUES ('36', 'Order seal stickers', 'pcs', '1000.000', '250.000', '0.15', '2026-10-09 09:20:53');

-- Sanitized catalog/test rows for `products`
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('1', '2', 'Salt Coffee Kluea', '', '1', '1', '0', '145.00', '5b5668ceb80c88c0f5bfd8b48e055234.jpg', '1', '0', NULL, '2026-04-28 19:11:46', '2026-04-28 19:11:46');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('2', '2', 'Vanilla Cold Foam', '', '1', '1', '0', '145.00', '8407445eb927a656b65b89cb95b9706e.jpg', '1', '0', NULL, '2026-04-28 19:11:46', '2026-04-28 19:11:46');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('3', '2', 'Barista Choice', '', '1', '1', '0', '155.00', NULL, '1', '0', NULL, '2026-04-28 19:11:46', '2026-10-07 11:18:17');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('4', '2', 'Chocolate Hazelnut', '', '1', '1', '0', '145.00', '41c8abdc5525fa6aa505b3df6323d992.jpg', '1', '0', NULL, '2026-04-28 19:11:46', '2026-04-28 19:11:46');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('5', '2', 'Tiramisu Latte', '', '1', '1', '0', '145.00', 'f5f5588d1adb9db3e61af166a9137c69.webp', '1', '0', NULL, '2026-04-28 19:11:46', '2026-04-28 19:11:46');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('6', '2', 'Biscoff Latte', '', '1', '1', '0', '155.00', 'b12ca8f51705c25bc422d8c5db7250f0.jpg', '1', '0', NULL, '2026-04-28 19:11:46', '2026-04-28 19:11:46');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('7', '3', 'Americano', '', '1', '1', '0', '75.00', 'e872e618806fdd77b5dfcf94574304aa.webp', '1', '0', NULL, '2026-04-28 19:21:02', '2026-04-28 19:21:02');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('8', '3', 'Cappuccino', '', '1', '1', '0', '85.00', 'c78b9446ef02ee615ca2d7c6f06ada6f.jpg', '1', '0', NULL, '2026-04-28 19:21:55', '2026-04-28 19:21:55');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('9', '3', 'Vietnamese', '', '1', '1', '0', '95.00', '91a778e2a35a18eaf43db18b6e1955ee.webp', '1', '0', NULL, '2026-04-28 19:22:52', '2026-04-28 19:22:52');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('10', '3', 'Hot Mocha', '', '1', '1', '0', '105.00', '77e0db82181247ec1c2fbe194c50b52f.png', '1', '0', NULL, '2026-04-28 20:01:11', '2026-04-28 20:01:11');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('11', '3', 'White Mocha', '', '1', '1', '0', '110.00', 'f574da67b282aae975f29d9752da7baf.jpg', '1', '0', NULL, '2026-04-28 20:05:34', '2026-04-28 20:05:45');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('12', '3', 'Hot Salted Latte', '', '1', '1', '0', '110.00', '1a987a0657182d0bc17adc1f8a0f27e0.jpg', '1', '0', NULL, '2026-04-28 20:06:40', '2026-04-28 20:06:40');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('13', '3', 'Hot Caramel Macchiato', '', '1', '1', '0', '110.00', '5907369f84859e186588e84d540b2a3e.jpg', '1', '0', NULL, '2026-04-28 20:07:24', '2026-04-28 20:07:24');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('14', '3', 'Hot Hazelnut', '', '1', '1', '0', '105.00', 'b3b5152f12c144012ac9443fb0ddc7a2.webp', '1', '0', NULL, '2026-04-28 20:09:51', '2026-04-28 20:09:51');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('15', '3', 'Hot Vanilla', '', '1', '1', '0', '105.00', '2352288ba9446ae7b8b2300e041b4c96.jpg', '1', '0', NULL, '2026-04-28 20:10:29', '2026-04-28 20:10:29');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('16', '3', 'Matcha', '', '1', '1', '0', '120.00', '6124719ed39e98ce8c9423ab34960257.webp', '1', '0', NULL, '2026-04-28 20:12:01', '2026-06-09 15:18:12');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('17', '4', 'Flat White', '', '1', '1', '0', '85.00', '39ad7d6d55f6a3433cafeb27ae472182.jpg', '1', '0', NULL, '2026-04-28 20:21:43', '2026-06-09 15:14:05');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('18', '4', 'Iced Americano', '', '1', '1', '0', '65.00', 'afb1df4b4869433ac55afd910d7a861a.webp', '1', '0', NULL, '2026-04-28 20:21:43', '2026-06-09 15:13:47');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('19', '4', 'Iced Latte', '', '1', '1', '0', '95.00', '510b8e568ea333ea9ee29010cf4be9fb.jpg', '1', '0', NULL, '2026-04-28 20:21:43', '2026-06-09 15:14:17');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('20', '4', 'Spanish Latte', '', '1', '1', '0', '115.00', '706d8c0e0326d2bac68e1b8b0201a195.jpg', '1', '0', NULL, '2026-04-28 20:21:43', '2026-04-28 20:21:43');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('21', '4', 'Iced Mocha', '', '1', '1', '0', '125.00', '22ebfad9dae580d58ff8412dfb5824c6.jpg', '1', '0', NULL, '2026-04-28 20:21:43', '2026-06-09 15:16:48');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('22', '4', 'Vanilla Iced', '', '1', '1', '0', '115.00', '9c715a08505c00d7ef2409c8ff088249.jpg', '1', '0', NULL, '2026-04-28 20:21:43', '2026-06-09 15:14:50');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('23', '4', 'White Mocha Latte', '', '1', '1', '0', '125.00', '125b5bc50b05df32409e32af3814ca47.png', '1', '0', NULL, '2026-04-28 20:21:43', '2026-06-09 15:16:07');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('24', '4', 'Caramel Macchiato', '', '1', '1', '0', '125.00', '503311bfbbcf7428a7c234922aa4a4e9.jpg', '1', '0', NULL, '2026-04-28 20:21:43', '2026-06-09 15:15:36');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('25', '4', 'Hazelnut Latte', '', '1', '1', '0', '125.00', '9b2f4c5477373c3592f31f895496901f.jpg', '1', '0', NULL, '2026-04-28 20:24:47', '2026-06-09 15:15:23');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('26', '4', 'Salted Caramel Latte', '', '1', '1', '0', '125.00', '6c1cf7a7e6ab59bd7164dc9e73c52aec.jpg', '1', '0', NULL, '2026-04-28 20:25:10', '2026-06-09 15:16:34');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('27', '4', 'Vietnamese Ice', '', '1', '1', '0', '120.00', '0ab9c6e6acd937e93bd6c9e9d8d57bf7.jpg', '1', '0', NULL, '2026-04-28 20:25:33', '2026-06-09 15:15:07');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('28', '6', 'White Matcha', '', '1', '1', '0', '145.00', '10e3617bf156dc2ab3d26f10db31443b.jpg', '1', '0', NULL, '2026-04-28 22:05:03', '2026-06-09 15:19:29');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('29', '6', 'Matcha Sea Salt', '', '1', '1', '0', '135.00', '4af41071142a32e1179632ef2cb32622.jpg', '1', '0', NULL, '2026-04-28 22:05:03', '2026-06-09 15:20:18');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('30', '6', 'Espresso Matcha', '', '1', '1', '0', '135.00', '32eb31755222089f976b24c93eb4fbd2.jpg', '1', '0', NULL, '2026-04-28 22:05:03', '2026-06-09 15:20:40');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('31', '6', 'Strawberry Matcha', '', '1', '1', '0', '145.00', '9d12e567f8ca4a8f9c5bcf5ae8db9e41.jpg', '1', '0', NULL, '2026-04-28 22:05:03', '2026-04-28 22:05:03');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('32', '6', 'Matcha Latte', '', '1', '1', '0', '125.00', '916f11b41fa6e6d72daeb7516544aa80.webp', '1', '0', NULL, '2026-04-28 22:05:03', '2026-06-09 15:19:44');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('33', '7', 'Dark Chocolate', '', '1', '1', '0', '105.00', '6ab6ec1dc71d89bd747e1ba4ad8b3fc5.jpg', '1', '0', NULL, '2026-04-28 22:07:09', '2026-04-28 22:07:09');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('34', '8', 'Cookies and Cream', '', '1', '1', '0', '79.00', 'da408520ce4194470dd746612e3a86d1.jpg', '1', '0', NULL, '2026-04-28 22:11:04', '2026-04-28 22:11:04');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('35', '8', 'Dark Chocolate', '', '1', '1', '0', '79.00', '2c9c1d8f49cedd401eb540e83bfec567.jpg', '1', '0', NULL, '2026-04-28 22:11:04', '2026-04-28 22:11:04');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('36', '8', 'Okinawa', '', '1', '1', '0', '79.00', '738599dbae4bdd3f37d20dfe6f7a3f00.webp', '1', '0', NULL, '2026-04-28 22:11:04', '2026-04-28 22:11:04');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('37', '8', 'Wintermelon', '', '1', '1', '0', '79.00', '897cef8a82fb5c148346b1f40d2b0322.jpg', '1', '0', NULL, '2026-04-28 22:11:04', '2026-04-28 22:11:04');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('38', '9', 'Blueberry', '', '1', '1', '0', '55.00', '13c8cc02dd90f85a391b4f2e02bfd786.jpg', '1', '0', NULL, '2026-04-28 22:14:23', '2026-04-28 22:14:23');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('39', '9', 'Strawberry', '', '1', '1', '0', '55.00', 'cfebedbde285a1874d77bafbe91422f6.jpg', '1', '0', NULL, '2026-04-28 22:14:23', '2026-04-28 22:14:23');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('40', '9', 'Green Apple', '', '1', '1', '0', '55.00', '8bc2e1413e39e46e6ebca52c4a166d54.jpg', '1', '0', NULL, '2026-04-28 22:14:23', '2026-04-28 22:14:23');
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `has_sizes`, `has_sugar`, `has_addons`, `price`, `image_path`, `is_available`, `spotlight_pinned`, `spotlight_pinned_until`, `created_at`, `updated_at`) VALUES ('41', '9', 'Lychee', '', '1', '1', '0', '55.00', '2698973bb550472079b9fcf46471b2d9.webp', '1', '0', NULL, '2026-04-28 22:14:23', '2026-04-28 22:14:23');

-- Sanitized catalog/test rows for `product_addons`
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('1', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('2', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('3', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('4', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('5', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('6', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('7', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('8', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('9', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('10', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('11', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('12', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('13', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('14', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('15', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('16', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('17', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('18', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('19', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('20', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('21', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('22', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('23', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('24', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('25', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('26', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('27', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('28', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('29', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('30', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('31', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('32', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('33', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('34', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('35', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('36', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('37', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('38', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('39', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('40', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('41', '1');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('1', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('2', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('3', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('4', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('5', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('6', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('7', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('8', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('9', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('10', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('11', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('12', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('13', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('14', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('15', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('16', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('17', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('18', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('19', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('20', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('21', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('22', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('23', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('24', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('25', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('26', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('27', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('28', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('29', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('30', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('31', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('32', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('33', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('34', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('35', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('36', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('37', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('38', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('39', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('40', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('41', '2');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('1', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('2', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('3', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('4', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('5', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('6', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('7', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('8', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('9', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('10', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('11', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('12', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('13', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('14', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('15', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('16', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('17', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('18', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('19', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('20', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('21', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('22', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('23', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('24', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('25', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('26', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('27', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('28', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('29', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('30', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('31', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('32', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('33', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('34', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('35', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('36', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('37', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('38', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('39', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('40', '3');
INSERT INTO `product_addons` (`product_id`, `addon_id`) VALUES ('41', '3');

-- Sanitized catalog/test rows for `product_ingredients`
-- No rows in live database.

