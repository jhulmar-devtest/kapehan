-- ============================================================
-- 2026_10_23_add_paymongo_gateway.sql
--
-- Adds what is needed to take GCash payments through PayMongo
-- (hosted checkout + webhook), alongside the existing manual
-- GCash-reference flow.
--
--   payments.gateway             'paymongo' for gateway payments, NULL for
--                                manual GCash / cash (older rows stay NULL)
--   payments.gateway_session_id  PayMongo checkout session id (cs_...)
--   payments.gateway_payment_id  PayMongo payment id (pay_...)
--   payment_webhook_events       remembers which webhook events were already
--                                processed, so retries never double-apply
--
-- RUN THIS BEFORE deploying the matching PHP changes: cashier/preorders.php
-- and api/checkout.php read these columns.
-- ============================================================

ALTER TABLE `payments`
  ADD COLUMN `gateway`            VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN `gateway_session_id` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `gateway_payment_id` VARCHAR(100) DEFAULT NULL,
  ADD KEY `idx_payments_gateway_session` (`gateway_session_id`);

CREATE TABLE `payment_webhook_events` (
  `event_key`   VARCHAR(190) NOT NULL,
  `event_type`  VARCHAR(100) NOT NULL,
  `received_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
