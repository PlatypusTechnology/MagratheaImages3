
-- Migration: Add `uuid` column to `images` table
ALTER TABLE `images`
	ADD COLUMN `uuid` char(36) NULL UNIQUE AFTER `id`;

-- Backfill existing rows (MySQL has no native UUIDv7; UUID() here is v4 --
-- fine for a one-time backfill, new rows going forward get v7 via the model)
UPDATE `images` SET `uuid` = UUID() WHERE `uuid` IS NULL;

-- Enforce at the DB level now that every row has one
ALTER TABLE `images`
	MODIFY COLUMN `uuid` char(36) NOT NULL;

