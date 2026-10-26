-- Separa as caixas de grupo das caixas de mensagens gerais.
--
-- Com a caixa unificada, a pertinencia passou a ser por setor/canal/responsavel
-- e as duas caixas passaram a mostrar o mesmo: "Grupos Suporte" e "Suporte"
-- cobrem o mesmo setor. O que separa as duas e a NATUREZA da conversa
-- (grupo de WhatsApp x mensagem avulsa), que morava no ponteiro inbox_id da
-- conversa e se perdeu quando a caixa deixou de ser container.
--
-- conversation_scope devolve essa natureza para a caixa:
--   all        - conversa normal e de grupo (padrao)
--   groups     - somente conversas de grupo do WhatsApp
--   non_groups - somente mensagens gerais (fora de grupo)

ALTER TABLE inboxes
    ADD COLUMN IF NOT EXISTS conversation_scope ENUM('all', 'groups', 'non_groups') NOT NULL DEFAULT 'all';

-- Configuracao das caixas que ja existem: a caixa de grupos fica com as
-- conversas de grupo, a caixa de suporte fica com as mensagens gerais.
UPDATE inboxes SET conversation_scope = 'groups' WHERE name = 'Grupos Suporte' AND type = 'department';
UPDATE inboxes SET conversation_scope = 'non_groups' WHERE name = 'Suporte' AND type = 'department';

-- As conversas de grupo passam a apontar para o perfil de SLA da caixa de
-- grupos (antes uma delas ficou no perfil do suporte).
UPDATE conversations c
JOIN whatsapp_groups g ON g.id = c.group_id
JOIN inboxes i ON i.id = g.inbox_id
SET c.inbox_id = i.id
WHERE c.inbox_id IS NULL OR c.inbox_id <> i.id;
