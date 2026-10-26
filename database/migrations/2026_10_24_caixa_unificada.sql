-- Caixa de entrada unificada: a caixa deixa de ser container e passa a ser
-- visão + perfil do time.
--
-- O que muda no banco:
--  - conversation.inbox_id deixa de ser o ROTEIRO da conversa. Quem roteia é
--    o department_id (que já vem do canal, via channels.department_id).
--    O inbox_id passa a apontar apenas para o perfil de SLA do time que
--    atende — e é derivado do setor.
--  - a pertinência passa a ser calculada por setor / canal / responsável.
--    Isso pede índice nos três eixos, que é o que esta migração cria.
--
-- Idempotente: pode ser re-aplicada sem efeito colateral.

-- Índices dos eixos de pertinência.
ALTER TABLE conversations
    ADD INDEX IF NOT EXISTS idx_conv_department (department_id);

ALTER TABLE conversations
    ADD INDEX IF NOT EXISTS idx_conv_channel (channel_id);

ALTER TABLE conversations
    ADD INDEX IF NOT EXISTS idx_conv_assigned (assigned_user_id);

ALTER TABLE conversations
    ADD INDEX IF NOT EXISTS idx_conv_dept_status (department_id, status);

ALTER TABLE inbox_departments
    ADD INDEX IF NOT EXISTS idx_inbox_dept_department (department_id);

ALTER TABLE inbox_channels
    ADD INDEX IF NOT EXISTS idx_inbox_ch_channel (channel_id);

ALTER TABLE inbox_users
    ADD INDEX IF NOT EXISTS idx_inbox_user_user (user_id);

-- Perfil de SLA coerente: a conversa aponta para a caixa do seu setor.
-- Conversas que estavam em caixa pessoal ou em caixa de outro setor saem
-- de lá: o perfil segue o time, não o histórico.
UPDATE conversations c
JOIN inbox_departments idp ON idp.department_id = c.department_id
JOIN inboxes i ON i.id = idp.inbox_id AND i.type <> 'personal' AND i.is_active = 1
SET c.inbox_id = i.id
WHERE c.inbox_id IS NULL
   OR c.inbox_id NOT IN (SELECT id FROM inboxes)
   OR c.inbox_id IN (SELECT id FROM inboxes WHERE type = 'personal');

-- Tabelas legadas: horário comercial e mensagem de ausência já moram no
-- departamento (business_hours e departments.absence_message). Estas duas
-- eram o resquício do modelo antigo, em que a caixa era o dono da fila.
DROP TABLE IF EXISTS inbox_business_hours;
DROP TABLE IF EXISTS inbox_away_messages;
