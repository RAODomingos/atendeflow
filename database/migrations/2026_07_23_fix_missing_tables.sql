-- ============================================
-- Fix: Create settings table (was missing)
-- ============================================
CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    value TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Fix: Add last_activity_at column to contacts
-- ============================================
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS `last_activity_at` DATETIME NULL AFTER `last_contact_at`;
