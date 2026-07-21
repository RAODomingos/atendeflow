-- Remove funcionalidade de e-mail do sistema

-- Remove colunas de email da tabela messages
ALTER TABLE messages DROP COLUMN IF EXISTS email_message_id;
ALTER TABLE messages DROP COLUMN IF EXISTS in_reply_to;
ALTER TABLE messages DROP COLUMN IF EXISTS references_header;

-- Remove tabelas de email
DROP TABLE IF EXISTS conversation_email_categories;
DROP TABLE IF EXISTS email_categories;
DROP TABLE IF EXISTS email_accounts;

-- Remove canais do tipo email (conversas em cascata)
DELETE FROM channels WHERE type = 'email';

-- Altera o enum da tabela channels (MySQL exige recriar a coluna)
ALTER TABLE channels MODIFY COLUMN type ENUM('whatsapp', 'webchat') NOT NULL;

-- Altera o enum da tabela flows
ALTER TABLE flows MODIFY COLUMN channel_scope ENUM('all', 'webchat', 'whatsapp') NOT NULL DEFAULT 'all';
