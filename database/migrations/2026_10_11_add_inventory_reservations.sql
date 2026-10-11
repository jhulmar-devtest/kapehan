-- Reserve ingredient quantities while online pre-orders await payment/preparation.
-- Cancellation releases reservations and restores stock already deducted.
CREATE TABLE `inventory_order_reservations` (
  `order_id` INT UNSIGNED NOT NULL,
  `inventory_item_id` INT UNSIGNED NOT NULL,
  `reserved_quantity` DECIMAL(12,3) NOT NULL,
  `actor_role` VARCHAR(20) DEFAULT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`, `inventory_item_id`),
  KEY `idx_inventory_reservation_item` (`inventory_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `inventory_order_reservations`
  ADD CONSTRAINT `fk_inventory_reservation_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_reservation_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE;

CREATE TABLE `inventory_order_restorations` (
  `order_id` INT UNSIGNED NOT NULL,
  `actor_role` VARCHAR(20) DEFAULT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `restored_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `inventory_order_restorations`
  ADD CONSTRAINT `fk_inventory_restoration_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

-- Hold stock for active orders created before this migration. For size-specific
-- recipes, a size row overrides the default amount for the same ingredient.
INSERT INTO `inventory_order_reservations`
  (`order_id`, `inventory_item_id`, `reserved_quantity`, `actor_role`, `actor_id`)
SELECT active_orders.order_id, recipe.inventory_item_id,
       SUM(recipe.qty_per_unit * active_orders.quantity),
       active_orders.actor_role, active_orders.actor_id
FROM (
  SELECT o.id AS order_id, od.product_id, od.quantity,
         CASE WHEN o.student_id IS NOT NULL THEN 'student' ELSE 'faculty' END AS actor_role,
         COALESCE(o.student_id, o.faculty_id) AS actor_id,
         TRIM(SUBSTRING_INDEX(REPLACE(COALESCE(od.customization_note, ''), ' · ', ' - '), ' - ', 1)) AS size_label
  FROM `orders` o
  JOIN `order_details` od ON od.order_id = o.id
  WHERE o.order_type = 'pre-order'
    AND o.status IN ('pending', 'preparing', 'ready')
    AND NOT EXISTS (
      SELECT 1 FROM `inventory_order_deductions` d WHERE d.order_id = o.id
    )
) AS active_orders
JOIN `product_ingredients` recipe ON recipe.product_id = active_orders.product_id
WHERE recipe.qty_per_unit > 0
  AND (
    recipe.size_label COLLATE utf8mb4_unicode_ci = active_orders.size_label
    OR (
      recipe.size_label = ''
      AND NOT EXISTS (
        SELECT 1 FROM `product_ingredients` override_recipe
        WHERE override_recipe.product_id = recipe.product_id
          AND override_recipe.inventory_item_id = recipe.inventory_item_id
          AND override_recipe.size_label COLLATE utf8mb4_unicode_ci = active_orders.size_label
      )
    )
  )
GROUP BY active_orders.order_id, recipe.inventory_item_id, active_orders.actor_role, active_orders.actor_id;
