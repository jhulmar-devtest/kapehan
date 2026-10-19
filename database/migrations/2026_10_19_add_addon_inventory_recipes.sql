-- Track the inventory consumed by a selected paid add-on.
CREATE TABLE `addon_ingredients` (
  `addon_id` INT NOT NULL,
  `inventory_item_id` INT UNSIGNED NOT NULL,
  `qty_per_unit` DECIMAL(12,3) NOT NULL COMMENT 'amount consumed per add-on selection',
  PRIMARY KEY (`addon_id`, `inventory_item_id`),
  KEY `idx_addon_ingredients_item` (`inventory_item_id`),
  CONSTRAINT `fk_addon_ingredients_addon` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_addon_ingredients_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Initial estimates for the current add-ons. Administrators can tune these in
-- Admin > Product Recipes > Add-on Inventory after checking actual portions.
INSERT INTO `addon_ingredients` (`addon_id`, `inventory_item_id`, `qty_per_unit`)
SELECT a.id, i.id,
       CASE LOWER(a.name)
         WHEN 'espresso' THEN 0.009
         WHEN 'syrup' THEN 0.015
         WHEN 'nata' THEN 0.040
       END
FROM `addons` a
JOIN `inventory_items` i ON
  (LOWER(a.name) = 'espresso' AND LOWER(i.name) = 'espresso coffee beans') OR
  (LOWER(a.name) = 'syrup' AND LOWER(i.name) = 'sugar syrup') OR
  (LOWER(a.name) = 'nata' AND LOWER(i.name) = 'fruit jelly or popping boba')
WHERE LOWER(a.name) IN ('espresso', 'syrup', 'nata');
