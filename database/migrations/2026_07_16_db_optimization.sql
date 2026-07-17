-- ============================================
-- DB Optimization: Indexes & computed columns
-- ============================================

-- Conversations: index for faster inbox listing
ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_conv_status_updated (`status`, `updated_at`);
ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_conv_assigned (`assigned_user_id`, `status`);
ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_conv_contact (`contact_id`, `status`);
ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_conv_department (`department_id`, `status`);
ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_conv_priority (`priority`, `status`);
ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_conv_created (`created_at`);
ALTER TABLE conversations ADD INDEX IF NOT EXISTS idx_conv_last_message (`last_message_at`);

-- Messages: index for faster message loading
ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_msg_conv_created (`conversation_id`, `created_at`);
ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_msg_type (`conversation_id`, `type`);
ALTER TABLE messages ADD INDEX IF NOT EXISTS idx_msg_user (`user_id`, `created_at`);

-- Tags: faster lookup
ALTER TABLE tags ADD INDEX IF NOT EXISTS idx_tag_name (`name`);
ALTER TABLE conversation_tags ADD INDEX IF NOT EXISTS idx_convtag_conv (`conversation_id`);
ALTER TABLE conversation_tags ADD INDEX IF NOT EXISTS idx_convtag_tag (`tag_id`);

-- Contacts: faster search
ALTER TABLE contacts ADD INDEX IF NOT EXISTS idx_contact_email (`email`);
ALTER TABLE contacts ADD INDEX IF NOT EXISTS idx_contact_phone (`phone`);
ALTER TABLE contacts ADD INDEX IF NOT EXISTS idx_contact_name (`name`);

-- Users: faster lookup
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_user_email (`email`);

-- Notifications: faster queries (if table exists)
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_notif_created (`created_at`);

-- Events: faster history lookups
ALTER TABLE conversation_events ADD INDEX IF NOT EXISTS idx_ce_conv (`conversation_id`, `created_at`);

-- CSAT: faster lookups
ALTER TABLE csat_ratings ADD INDEX IF NOT EXISTS idx_csat_conv (`conversation_id`);
ALTER TABLE csat_ratings ADD INDEX IF NOT EXISTS idx_csat_created (`created_at`);

-- Add message_count as a computed column (if not exists)
-- This avoids COUNT(*) queries on large message tables
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS message_count_cache INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_message_at`;

-- Trigger to auto-update message_count_cache on insert
DROP TRIGGER IF EXISTS trg_messages_after_insert;
DELIMITER //
CREATE TRIGGER trg_messages_after_insert
AFTER INSERT ON messages
FOR EACH ROW
BEGIN
    UPDATE conversations
    SET message_count_cache = message_count_cache + 1
    WHERE id = NEW.conversation_id;
END//
DELIMITER ;

-- Trigger to auto-update message_count_cache on delete
DROP TRIGGER IF EXISTS trg_messages_after_delete;
DELIMITER //
CREATE TRIGGER trg_messages_after_delete
AFTER DELETE ON messages
FOR EACH ROW
BEGIN
    UPDATE conversations
    SET message_count_cache = IF(message_count_cache > 0, message_count_cache - 1, 0)
    WHERE id = OLD.conversation_id;
END//
DELIMITER ;
