
-- Migration: Add `subfolder` column to `images` table
ALTER TABLE `images`
	ADD COLUMN `subfolder` varchar(255) NULL AFTER `folder`;


