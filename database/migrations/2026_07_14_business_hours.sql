-- Business Hours per inbox
CREATE TABLE IF NOT EXISTS inbox_business_hours (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inbox_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '0=Sunday, 1=Monday ... 6=Saturday',
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    open_time TIME NULL,
    close_time TIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inbox_day (inbox_id, day_of_week),
    FOREIGN KEY (inbox_id) REFERENCES inboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Away / auto-reply messages per inbox
CREATE TABLE IF NOT EXISTS inbox_away_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inbox_id BIGINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    message_type ENUM('outside_hours', 'away', 'holiday') NOT NULL DEFAULT 'outside_hours',
    title VARCHAR(255) NULL,
    message TEXT NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inbox_id) REFERENCES inboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Notifications (mentions, assignments, etc)
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_type ENUM('mention', 'assignment', 'transfer', 'new_message', 'status_change', 'system') NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    conversation_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notify_user (user_id, is_read),
    INDEX idx_notify_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Online presence tracking
CREATE TABLE IF NOT EXISTS user_presence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    is_online TINYINT(1) NOT NULL DEFAULT 0,
    last_seen_at DATETIME NULL,
    current_inbox_id BIGINT UNSIGNED NULL,
    current_conversation_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (current_inbox_id) REFERENCES inboxes(id) ON DELETE SET NULL,
    FOREIGN KEY (current_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Business hours default config (inbox-level)
ALTER TABLE inboxes ADD COLUMN timezone VARCHAR(50) NULL DEFAULT 'America/Sao_Paulo' AFTER is_active;
ALTER TABLE inboxes ADD COLUMN away_message_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone;
ALTER TABLE inboxes ADD COLUMN away_message TEXT NULL AFTER away_message_enabled;
ALTER TABLE inboxes ADD COLUMN greeting_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER away_message;
ALTER TABLE inboxes ADD COLUMN greeting_message VARCHAR(500) NULL AFTER greeting_enabled;
