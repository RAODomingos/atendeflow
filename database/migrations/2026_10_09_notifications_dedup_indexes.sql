-- Limpeza notificações: remove índices duplicados/redundantes (sino usa só idx_notif_*).
-- Mantidos: idx_notif_user_type (user_id, notification_type, is_read),
-- idx_notif_conversation (conversation_id, is_read), idx_notif_created (created_at).
-- Removidos: idx_notify_created (= idx_notif_created), idx_notify_user (prefixo de idx_notif_user_type),
-- conversation_id (prefixo de idx_notif_conversation). Runner tolera 1091 em re-run.
ALTER TABLE notifications DROP INDEX idx_notify_created;
ALTER TABLE notifications DROP INDEX idx_notify_user;
ALTER TABLE notifications DROP INDEX conversation_id;
