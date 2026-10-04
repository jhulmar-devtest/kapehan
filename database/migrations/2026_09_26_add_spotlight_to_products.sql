-- ============================================================
-- 2026_09_26_add_spotlight_to_products.sql
--
-- Adds the manual-override half of the homepage "Spotlight" feature
-- (see includes/functions.php: getSpotlightProducts()).
--
-- spotlight_pinned         1 = admin has manually pinned this product
--                          to the homepage Spotlight carousel.
-- spotlight_pinned_until   Optional auto-expiry. NULL = stays pinned
--                          until an admin unpins it by hand. Once this
--                          timestamp passes, the product silently drops
--                          out of the pinned set and the automatic
--                          best-seller algorithm takes its slot back —
--                          no cleanup job needed.
--
-- Safe to run once. Run this against the live database, then this
-- file can stay here as a record (mirrored into database/kapehan_db.sql
-- for anyone re-creating the schema from scratch).
-- ============================================================

ALTER TABLE `products`
  ADD COLUMN `spotlight_pinned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_available`,
  ADD COLUMN `spotlight_pinned_until` DATETIME DEFAULT NULL AFTER `spotlight_pinned`;
