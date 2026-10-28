-- Preserve the per-unit cost that was in effect when an inventory movement
-- was recorded. Existing rows stay NULL because their historical cost cannot
-- be reconstructed reliably from today's inventory_items.cost_per_unit.
ALTER TABLE `inventory_log`
  ADD COLUMN `unit_cost_snapshot` DECIMAL(10,2) DEFAULT NULL AFTER `change_amount`;
