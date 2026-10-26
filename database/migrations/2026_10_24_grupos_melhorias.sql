-- Melhorias no tratamento de grupos de WhatsApp:
--  1) grupo deixa de ser "cliente": contacts.is_group marca o contato sintético
--  2) mensagem marcada como menção (messages.is_mention): base do SLA da menção
--  3) menção aponta para a mensagem (whatsapp_group_mentions.message_id)
--  4) cache de participantes do grupo + janela de novo tópico
--  5) linha de SLA distingue conversa de grupo (conversation_slas.sla_kind)
--  6) limite do SLA da menção por caixa

ALTER TABLE contacts ADD COLUMN is_group TINYINT(1) NOT NULL DEFAULT 0 AFTER avatar;
ALTER TABLE contacts ADD INDEX idx_contacts_is_group (is_group);

ALTER TABLE messages ADD COLUMN is_mention TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read;
ALTER TABLE messages ADD INDEX idx_msg_conv_mention (conversation_id, is_mention);

ALTER TABLE whatsapp_group_mentions ADD COLUMN message_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE whatsapp_group_mentions ADD INDEX idx_gm_message (message_id);

ALTER TABLE whatsapp_groups ADD COLUMN members_json LONGTEXT NULL AFTER participant_count;
ALTER TABLE whatsapp_groups ADD COLUMN members_synced_at DATETIME NULL AFTER members_json;
ALTER TABLE whatsapp_groups ADD COLUMN topic_window_hours INT NOT NULL DEFAULT 0 AFTER members_synced_at;

ALTER TABLE conversation_slas ADD COLUMN sla_kind VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER conversation_id;
ALTER TABLE conversation_slas ADD INDEX idx_sla_kind (sla_kind);

ALTER TABLE inboxes ADD COLUMN sla_group_mention_minutes INT NULL DEFAULT NULL AFTER sla_pending_response_minutes;
