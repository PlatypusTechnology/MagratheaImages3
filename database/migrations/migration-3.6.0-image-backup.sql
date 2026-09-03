-- Migration: R2 backup state on the `images` table
--
-- `backed_up_at IS NULL` means "needs backup"; existing rows backfill to NULL,
-- which is exactly the initial seed set for the push job.
--
-- `backup_etag` is written on a successful push and otherwise unused for now. It
-- exists so a future integrity sweep (HEAD each object, compare against the stored
-- ETag) does not need a second migration on this table -- the reconcile job only
-- checks that a key exists, not that its contents are still correct.

ALTER TABLE `images`
	ADD COLUMN `backed_up_at`    DATETIME NULL DEFAULT NULL AFTER `upload_key`,
	ADD COLUMN `backup_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `backed_up_at`,
	ADD COLUMN `backup_error`    VARCHAR(255) NULL DEFAULT NULL AFTER `backup_attempts`,
	ADD COLUMN `backup_etag`     VARCHAR(64) NULL DEFAULT NULL AFTER `backup_error`;

-- Supports the push job's pending query:
--   WHERE backed_up_at IS NULL AND backup_attempts < {max_attempts} ORDER BY id ASC
CREATE INDEX `idx_images_backup_pending`
	ON `images` (`backed_up_at`, `backup_attempts`, `id`);
