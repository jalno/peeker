
peeker_handchecks_md5 	CREATE TABLE `peeker_handchecks_md5` (
	`md5` varchar(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
	`reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
	`update_at` int(11) NOT NULL,
	`action` tinyint(4) NOT NULL,
	PRIMARY KEY (`md5`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;