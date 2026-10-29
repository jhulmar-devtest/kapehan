-- Pause ASAP online pickup when walk-in and active online preparation reaches the configured workload.
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('asap_preparation_workload_limit', '8')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
