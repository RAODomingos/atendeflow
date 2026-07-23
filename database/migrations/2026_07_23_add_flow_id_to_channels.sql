-- Add flow_id column to channels table if not exists
ALTER TABLE channels ADD COLUMN IF NOT EXISTS flow_id BIGINT UNSIGNED NULL AFTER department_id;
