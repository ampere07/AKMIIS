-- Create pppoe_username_patterns table
CREATE TABLE IF NOT EXISTS `pppoe_username_patterns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pattern_name` varchar(255) NOT NULL,
  `sequence` json NOT NULL,
  `created_by` varchar(255) NOT NULL DEFAULT 'system',
  `updated_by` varchar(255) NOT NULL DEFAULT 'system',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
