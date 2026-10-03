-- Etiquetas de sistema para status de fluxo:
--   "Fluxo"  = conversa com fluxo ativo (bot atendendo)
--   "Aberto" = fluxo finalizado, conversa aguardando atendimento humano
-- Criadas automaticamente pelo Flow model em tempo de execução; este backfill
-- garante que as conversas JÁ em fluxo no momento da atualização ganhem a tag.

INSERT IGNORE INTO tags (name, color) VALUES ('Fluxo', '#8b5cf6');
INSERT IGNORE INTO tags (name, color) VALUES ('Aberto', '#22c55e');

-- Conversas com fluxo ativo existentes => tag "Fluxo"
INSERT IGNORE INTO conversation_tags (conversation_id, tag_id)
SELECT fs.conversation_id, t.id
FROM conversation_flow_states fs
JOIN tags t ON t.name = 'Fluxo'
WHERE fs.is_active = 1;
