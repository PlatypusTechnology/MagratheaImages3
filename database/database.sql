-- magrathee tables:

CREATE TABLE `_magrathea_config` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`name` varchar(255) UNIQUE,
	`value` varchar(255) DEFAULT NULL,
	`is_system` BOOLEAN NULL DEFAULT FALSE,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ,
	`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE `_magrathea_roles` (
	`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
	`name` varchar(255) DEFAULT NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ,
	`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`)
);

INSERT INTO `_magrathea_roles`
( `name` ) VALUES ( "super_admin" );

CREATE TABLE `_magrathea_users` (
	`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
	`email` varchar(255) UNIQUE,
	`password` varchar(255) DEFAULT NULL,
	`last_login` timestamp,
	`role_id` int(11) NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`)
);

CREATE TABLE `_magrathea_logs` (
	`id` bigint(11) unsigned NOT NULL AUTO_INCREMENT,
	`user_id` int(11) NOT NULL,
	`action` varchar(255) NOT NULL,
	`victim` varchar(255) NULL,
	`info` text DEFAULT NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ,
	`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`)
);



-- images tables:

CREATE TABLE `apikey` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`private_key` varchar(255) NULL,
	`public_key` varchar(255) NULL,
	`folder` varchar(255) NULL,
	`uses` int(11) NULL,
	`usage_limit` int(11) NULL,
	`expiration` datetime NULL,
	`active` tinyint(1) NOT NULL DEFAULT 1,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE `images` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE,
	`name` varchar(255) NULL,
	`filename` varchar(255) NULL,
	`extension` varchar(255) NULL,
	`folder` varchar(255) NULL,
	`subfolder` varchar(255) NULL,
	`width` int(11) NULL,
	`height` int(11) NULL,
	`file_type` varchar(255) NULL,
	`size` int(11) NULL,
	`upload_key` varchar(255) NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- R2 backup state, kept off `images` -- see database/migrations/migration-3.6.1-image-backup-history.sql

CREATE TABLE `images_backup_status` (
	`image_id`        INT NOT NULL PRIMARY KEY,
	`backed_up_at`    DATETIME NULL DEFAULT NULL,
	`backup_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
	`backup_error`    VARCHAR(255) NULL DEFAULT NULL,
	`backup_etag`     VARCHAR(64) NULL DEFAULT NULL,
	FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE,
	INDEX `idx_images_backup_status_pending` (`backed_up_at`, `backup_attempts`, `image_id`)
);

CREATE TABLE `images_backup_attempts` (
	`id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	`image_id`     INT NOT NULL,
	`attempted_at` DATETIME NOT NULL,
	`succeeded`    TINYINT(1) NOT NULL,
	`error`        VARCHAR(255) NULL DEFAULT NULL,
	`etag`         VARCHAR(64) NULL DEFAULT NULL,
	KEY `idx_image_id` (`image_id`, `attempted_at`),
	FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE
);



