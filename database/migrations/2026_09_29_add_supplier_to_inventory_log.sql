-- ============================================================
-- 2026_09_29_add_supplier_to_inventory_log.sql
--
-- Adds `supplier` to inventory_log so each restock entry can record
-- who it was bought from. Restock date and quantity already exist
-- (created_at, change_amount where reason='restock') — this is the
-- one genuinely missing piece needed for the Inventory item details
-- popup's restock history table (item 4).
--
-- Only meaningful for reason='restock' rows; left NULL for waste/
-- sale/correction entries, same as order_id already is for non-sale
-- rows in this table.
-- ============================================================

ALTER TABLE `inventory_log`
  ADD COLUMN `supplier` VARCHAR(150) DEFAULT NULL AFTER `reason`;
