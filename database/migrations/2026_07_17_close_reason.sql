-- ============================================
-- Add close_reason and close_description columns
-- ============================================

ALTER TABLE conversations ADD COLUMN IF NOT EXISTS `close_reason` VARCHAR(255) NULL AFTER `closed_at`;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS `close_description` TEXT NULL AFTER `close_reason`;
