-- Conversas de grupo: cada grupo WhatsApp tem UMA conversa persistente na caixa
-- da conexão (via inbox_channels) ou na caixa vinculada ao grupo.
ALTER TABLE conversations ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER contact_id;
ALTER TABLE conversations ADD INDEX idx_conv_group (group_id);
ALTER TABLE conversations ADD CONSTRAINT conversations_ibfk_group
    FOREIGN KEY (group_id) REFERENCES whatsapp_groups (id) ON DELETE SET NULL;

-- Autoria de mensagens em grupo (participante que enviou).
ALTER TABLE messages ADD COLUMN sender_name VARCHAR(255) NULL AFTER user_id;
ALTER TABLE messages ADD COLUMN sender_phone VARCHAR(40) NULL AFTER sender_name;
