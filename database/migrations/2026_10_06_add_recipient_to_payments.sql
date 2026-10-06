-- ============================================================
-- 2026_10_06_add_recipient_to_payments.sql
--
-- The GCash recipient is now admin-editable (Admin > Settings), so each
-- online payment records which account the customer was shown at checkout.
-- Older rows stay NULL (recipient unknown / predates this change).
--
-- RUN THIS BEFORE deploying the matching PHP changes — checkout.php and
-- cashier/preorders.php read/write these columns.
-- ============================================================

ALTER TABLE `payments`
  ADD COLUMN `recipient_name`   VARCHAR(100) DEFAULT NULL AFTER `reference_number`,
  ADD COLUMN `recipient_number` VARCHAR(20)  DEFAULT NULL AFTER `recipient_name`;
