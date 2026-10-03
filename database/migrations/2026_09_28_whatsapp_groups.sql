-- Caixa de Grupos WhatsApp: registro de grupos + log de menções ao número da conexão.
CREATE TABLE IF NOT EXISTS whatsapp_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    group_jid VARCHAR(100) NOT NULL COMMENT 'Ex: 120363012345678@g.us',
    name VARCHAR(255) NULL,
    avatar_url VARCHAR(500) NULL,
    participant_count INT NULL DEFAULT NULL,
    mention_alert TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Alerta quando mencionarem o número da conexão',
    inbox_id BIGINT UNSIGNED NULL COMMENT 'Caixa que recebe os alertas (NULL = caixa do canal)',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_conn_jid (connection_id, group_jid),
    KEY idx_groups_connection (connection_id),
    CONSTRAINT whatsapp_groups_ibfk_1 FOREIGN KEY (connection_id) REFERENCES whatsapp_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_group_mentions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    provider_message_id VARCHAR(150) NULL,
    sender_name VARCHAR(255) NULL,
    sender_phone VARCHAR(40) NULL,
    content TEXT NULL,
    mentioned_digits VARCHAR(40) NULL COMMENT 'Dígitos do número mencionado (normalmente o da conexão)',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mention_group_msg (group_id, provider_message_id),
    KEY idx_mentions_group (group_id, is_read, created_at),
    CONSTRAINT whatsapp_group_mentions_ibfk_1 FOREIGN KEY (group_id) REFERENCES whatsapp_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Novo tipo de notificação para menção em grupo (sino + SSE genéricos já cobrem).
ALTER TABLE notifications
    MODIFY COLUMN notification_type ENUM(
        'mention', 'assignment', 'transfer',
        'new_message', 'new_conversation',
        'status_change', 'system', 'group_mention'
    ) NOT NULL;
