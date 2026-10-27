-- O sistema passa a ter só caixas por categoria (time/setor).
--
-- A "Minha Caixa" (a visão "atribuídas a mim") e as caixas pessoais
-- (inboxes.type = 'personal') saem do sistema. O que fica:
--   - a caixa de entrada unificada (o recorte vem dos filtros da listagem,
--     inclusive o filtro de responsável "Atribuídas a mim");
--   - as caixas por categoria, que são visões salvas do mesmo escopo e
--     definem o perfil de SLA do time.
--
-- O que muda no banco:
--  - o grupo de WhatsApp apontado para uma caixa pessoal fica sem caixa;
--  - a conversa que apontava para uma caixa pessoal volta ao perfil do
--    setor (o inbox_id é o perfil de SLA, não o dono da fila);
--  - as caixas pessoais são apagadas com seus vínculos;
--  - o tipo 'personal' sai do enum de inboxes.type.
--
-- Idempotente: pode ser re-aplicada sem efeito colateral.

-- O grupo não fica apontando para uma caixa que não existe mais.
UPDATE whatsapp_groups SET inbox_id = NULL
WHERE inbox_id IN (SELECT id FROM inboxes WHERE type = 'personal');

-- A conversa segue o time: o perfil de SLA é o da caixa do setor.
UPDATE conversations c
JOIN inbox_departments idp ON idp.department_id = c.department_id
JOIN inboxes i ON i.id = idp.inbox_id AND i.type <> 'personal' AND i.is_active = 1
SET c.inbox_id = i.id
WHERE c.inbox_id IN (SELECT id FROM inboxes WHERE type = 'personal');

-- Os vínculos das caixas pessoais saem junto (as FKs já cascateiam; fica
-- explícito para bases sem FK).
DELETE FROM inbox_users WHERE inbox_id IN (SELECT id FROM inboxes WHERE type = 'personal');
DELETE FROM inbox_channels WHERE inbox_id IN (SELECT id FROM inboxes WHERE type = 'personal');
DELETE FROM inbox_departments WHERE inbox_id IN (SELECT id FROM inboxes WHERE type = 'personal');

DELETE FROM inboxes WHERE type = 'personal';

-- A caixa é sempre de time (setor): o tipo 'personal' sai do enum.
ALTER TABLE inboxes MODIFY COLUMN type ENUM('general', 'department') NOT NULL DEFAULT 'department';
