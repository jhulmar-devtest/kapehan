ALTER TABLE `students`
  ADD COLUMN `registration_expires_at` DATETIME DEFAULT NULL AFTER `email_verified_at`;

ALTER TABLE `faculty`
  ADD COLUMN `registration_expires_at` DATETIME DEFAULT NULL AFTER `email_verified_at`;

UPDATE `students`
SET `registration_expires_at` = DATE_ADD(`created_at`, INTERVAL 24 HOUR)
WHERE `email_verified` = 0 AND `email_verified_at` IS NULL;

UPDATE `faculty`
SET `registration_expires_at` = DATE_ADD(`created_at`, INTERVAL 24 HOUR)
WHERE `email_verified` = 0 AND `email_verified_at` IS NULL;

CREATE TABLE `registration_conflict_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type` ENUM('student','faculty') NOT NULL,
  `school_id_no` VARCHAR(30) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `contact_email` VARCHAR(150) NOT NULL,
  `details` VARCHAR(1000) DEFAULT NULL,
  `status` ENUM('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `account_id` INT UNSIGNED DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `resolution_note` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conflict_status_created` (`status`,`created_at`),
  KEY `idx_conflict_claimed_id` (`user_type`,`school_id_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
