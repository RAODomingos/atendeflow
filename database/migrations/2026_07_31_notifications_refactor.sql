-- Refatora a tabela de notificações para suportar novos tipos e melhorar performance
-- Novidades:
--  * Adiciona `new_conversation` ao enum de notification_type
--  * Adiciona índice composto para consultas por tipo
--  * Adiciona índice para consultas por conversa
ALTER TABLE notifications
    MODIFY COLUMN notification_type ENUM(
        'mention', 'assignment', 'transfer',
        'new_message', 'new_conversation',
        'status_change', 'system'
    ) NOT NULL;

ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notif_user_type (user_id, notification_type, is_read);

ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notif_conversation (conversation_id, is_read);
