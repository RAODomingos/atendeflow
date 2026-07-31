-- Adiciona colunas de controle de entrega/leitura para mensagens outbound
-- Usado pelo indicador de "✓" (entregue) e "✓✓" (lida) no bubble
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS delivered_at DATETIME NULL AFTER is_read,
    ADD COLUMN IF NOT EXISTS read_at DATETIME NULL AFTER delivered_at;

-- Backfill: mensagens outbound que estão marcadas como lidas já são consideradas lidas
UPDATE messages SET read_at = updated_at
WHERE direction = 'outbound' AND is_read = 1 AND read_at IS NULL;

-- Mensagens outbound antigas são consideradas "entregues" no momento do envio
UPDATE messages SET delivered_at = created_at
WHERE direction = 'outbound' AND delivered_at IS NULL;

-- Índice para consultas por status de leitura em uma conversa
ALTER TABLE messages
    ADD INDEX IF NOT EXISTS idx_msg_read (conversation_id, direction, read_at);

-- Indicador de "está digitando" usado pelo cliente/chatbot
ALTER TABLE conversations
    ADD COLUMN IF NOT EXISTS last_typing_at DATETIME NULL AFTER last_message_at;

ALTER TABLE conversations
    ADD INDEX IF NOT EXISTS idx_conv_typing (last_typing_at);
