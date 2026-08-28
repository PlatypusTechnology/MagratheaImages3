-- Migration: split R2 backup state off `images` into two dedicated tables
--
-- Replaces the four `backup_*` columns added in 3.6.0 with:
--   `images_backup_status`    -- one row per image, current state only, upserted
--                                 lazily on the first push attempt (no row = never
--                                 attempted)
--   `images_backup_attempts`  -- append-only, one row per push attempt, never
--                                 updated or deleted, kept indefinitely
--
-- Landed before any instance's phase-5 seed push has run (see
-- future-plans/r2-backup-attempt-history.md), so no instance has real backup
-- state to carry over: no backfill needed, a missing `images_backup_status`
-- row already means "never attempted" by design.
--
-- `images.id` is a signed `int(11)`, so the `image_id` FK column below is a plain
-- `INT` (signed) to match -- InnoDB requires matching signedness for a foreign key.

CREATE TABLE `images_backup_status` (
	`image_id`        INT NOT NULL PRIMARY KEY,
	`backed_up_at`    DATETIME NULL DEFAULT NULL,
	`backup_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
	`backup_error`    VARCHAR(255) NULL DEFAULT NULL,
	`backup_etag`     VARCHAR(64) NULL DEFAULT NULL,
	FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE
);

-- Supports the push job's pending query (`GetPending()`'s LEFT JOIN filters on
-- these two columns from the status side).
CREATE INDEX `idx_images_backup_status_pending`
	ON `images_backup_status` (`backed_up_at`, `backup_attempts`, `image_id`);

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

DROP INDEX `idx_images_backup_pending` ON `images`;

ALTER TABLE `images`
	DROP COLUMN `backed_up_at`,
	DROP COLUMN `backup_attempts`,
	DROP COLUMN `backup_error`,
	DROP COLUMN `backup_etag`;
