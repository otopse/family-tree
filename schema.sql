CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `email_verified_at` DATETIME NULL,
  `phone_verified_at` DATETIME NULL,
  `email_verification_token` CHAR(64) NULL,
  `email_verification_sent_at` DATETIME NULL,
  `phone_verification_code` CHAR(64) NULL,
  `phone_verification_sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `last_login_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  UNIQUE KEY `uniq_users_phone` (`phone`),
  KEY `idx_users_email_token` (`email_verification_token`),
  KEY `idx_users_phone_code` (`phone_verification_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `family_trees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner` INT UNSIGNED NOT NULL,
  `tree_name` VARCHAR(255) NOT NULL,
  `tree_nodes` TEXT NULL,
  `created` DATETIME NOT NULL,
  `modified` DATETIME NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_family_trees_owner` (`owner`),
  CONSTRAINT `fk_family_trees_owner` FOREIGN KEY (`owner`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ft_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tree_id` INT UNSIGNED NOT NULL,
  `owner` INT UNSIGNED NOT NULL,
  `record_name` VARCHAR(255) NULL,
  `pattern` VARCHAR(50) NULL COMMENT 'Format example: MZDDD (Muz, Zena, 3 Deti)',
  `created` DATETIME NOT NULL,
  `modified` DATETIME NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_ft_records_tree` (`tree_id`),
  CONSTRAINT `fk_ft_records_tree` FOREIGN KEY (`tree_id`) REFERENCES `family_trees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ft_records_owner` FOREIGN KEY (`owner`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ft_elements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id` INT UNSIGNED NOT NULL,
  `type` ENUM('MUZ', 'ZENA', 'DIETA') NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `birth_date` VARCHAR(50) NULL,
  `birth_place` VARCHAR(255) NULL,
  `is_fictional_birth_date` TINYINT(1) NOT NULL DEFAULT 0,
  `death_date` VARCHAR(50) NULL,
  `death_place` VARCHAR(255) NULL,
  `gender` ENUM('M', 'F', 'U') NOT NULL DEFAULT 'U',
  `gedcom_id` VARCHAR(50) NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ft_elements_record` (`record_id`),
  CONSTRAINT `fk_ft_elements_record` FOREIGN KEY (`record_id`) REFERENCES `ft_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
