-- Protocolo do atendimento: numero unico por conversa (AAAAMM + sequencial = id).
ALTER TABLE conversations ADD COLUMN protocol VARCHAR(20) NULL AFTER public_id;

-- Preenche as conversas existentes
UPDATE conversations SET protocol = CONCAT(YEAR(created_at), LPAD(id, 6, '0')) WHERE protocol IS NULL;

-- Garante unicidade
ALTER TABLE conversations ADD UNIQUE KEY uq_conversations_protocol (protocol);
