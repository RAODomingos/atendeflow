-- Dedup de notificações indexável.
--
-- O dedup de "nova mensagem" comparava o message_id dentro do JSON do
-- metadata:
--   JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.message_id')) = ?
-- Não há índice para expressão JSON no MySQL, então cada mensagem
-- recebida varria as notificações do usuário+conversa.
--
-- Agora o message_id vira uma coluna real (preenchida pela aplicação),
-- com índice composto para o dedup.
ALTER TABLE notifications
    ADD COLUMN message_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER conversation_id;

-- Índice composto para o dedup: (user_id, conversation_id, message_id).
ALTER TABLE notifications
    ADD INDEX idx_notif_msg (user_id, conversation_id, message_id);

-- Backfill: preenche a coluna a partir do metadata já gravado.
-- (idempotente: WHERE message_id IS NULL)
UPDATE notifications
SET message_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.message_id')) AS UNSIGNED)
WHERE message_id IS NULL
  AND JSON_EXTRACT(metadata, '$.message_id') IS NOT NULL;
