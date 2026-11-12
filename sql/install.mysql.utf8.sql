CREATE TABLE IF NOT EXISTS `#__iderlogin_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sub` varchar(191) NOT NULL,
  `user_id` int NOT NULL,
  `claims` mediumtext,
  `updated_fields` text,
  `created` datetime NOT NULL,
  `last_login` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_sub` (`sub`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
