-- Ligação N:N entre caixas (inboxes) e canais (channels).
-- Referenciada em ~15 queries (Inbox::*, Conversation::*, UnreadConversationsController)
-- mas sem DDL versionado (AUDIT DB18). Criada a partir do banco de produção.
CREATE TABLE IF NOT EXISTS inbox_channels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  inbox_id BIGINT UNSIGNED NOT NULL,
  channel_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inbox_channel (inbox_id, channel_id),
  KEY channel_id (channel_id),
  CONSTRAINT inbox_channels_ibfk_1 FOREIGN KEY (inbox_id) REFERENCES inboxes (id) ON DELETE CASCADE,
  CONSTRAINT inbox_channels_ibfk_2 FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
