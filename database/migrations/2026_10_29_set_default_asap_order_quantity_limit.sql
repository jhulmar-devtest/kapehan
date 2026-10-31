-- Set the current ASAP per-order cap to 8 drinks. Admins can change it in Settings.
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('asap_order_quantity_limit', '8')
ON DUPLICATE KEY UPDATE `setting_value` = '8';
