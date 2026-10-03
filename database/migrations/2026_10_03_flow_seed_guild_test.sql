-- Seed do fluxo de teste "Atendimento Loja/Unidade": inativo por padrão.
-- Idempotente: apaga a seed anterior (estados primeiro, por causa da FK) e recria.
SET @old = (SELECT id FROM flows WHERE name = 'Atendimento Loja/Unidade (teste)' LIMIT 1);
DELETE FROM conversation_flow_states WHERE flow_id = @old;
DELETE FROM flow_answers WHERE flow_node_id IN (SELECT id FROM flow_nodes WHERE flow_id = @old);
DELETE FROM flow_execution_logs WHERE flow_id = @old;
DELETE FROM flows WHERE id = @old;

SET @admin = (SELECT id FROM users ORDER BY id LIMIT 1);

INSERT INTO flows (name, description, channel_scope, is_active, created_by)
VALUES ('Atendimento Loja/Unidade (teste)', 'Identifica loja e unidade do contato via Guild antes do atendimento', 'all', 0, @admin);
SET @f = LAST_INSERT_ID();

INSERT INTO flow_nodes (flow_id, node_key, node_type, title, content, position_x, position_y)
VALUES (@f, UUID(), 'start', 'Início', '', 0, 0);
SET @n_start = LAST_INSERT_ID();

INSERT INTO flow_nodes (flow_id, node_key, node_type, title, content, config, position_x, position_y)
VALUES (@f, UUID(), 'guild_select', 'Loja/Unidade', 'Olá! Para agilizar seu atendimento, vamos identificar sua loja.',
    JSON_OBJECT('presentation', 'list_menu',
                'store_prompt', 'Qual loja deseja atendimento?',
                'unit_prompt', 'Qual unidade?',
                'invalid_message', 'Opção inválida. Por favor, escolha uma opção válida:',
                'error_message', 'Falha ao buscar as opções. Tente novamente.',
                'max_attempts', 3,
                'save_unit', TRUE,
                'no_store_node_id', 0,
                'next_node_id', 0),
    0, 100);
SET @n_guild = LAST_INSERT_ID();

INSERT INTO flow_nodes (flow_id, node_key, node_type, title, content, config, position_x, position_y)
VALUES (@f, UUID(), 'collect_field', 'CNPJ', 'Pode me informar o CNPJ da sua empresa?',
    JSON_OBJECT('save_field', 'document'), 0, 200);
SET @n_cnpj = LAST_INSERT_ID();

INSERT INTO flow_nodes (flow_id, node_key, node_type, title, content, position_x, position_y)
VALUES (@f, UUID(), 'message', 'Aviso', 'Obrigado! Localizando seu cadastro.', 0, 300);
SET @n_msg = LAST_INSERT_ID();

INSERT INTO flow_nodes (flow_id, node_key, node_type, title, content, position_x, position_y)
VALUES (@f, UUID(), 'handoff', 'Humano', 'Um atendente vai te ajudar em instantes.', 0, 400);
SET @n_hand = LAST_INSERT_ID();

INSERT INTO flow_nodes (flow_id, node_key, node_type, title, content, position_x, position_y)
VALUES (@f, UUID(), 'message', 'Confirmado', 'Unidade registrada! Seguimos com seu atendimento.', 0, 500);
SET @n_ok = LAST_INSERT_ID();

INSERT INTO flow_nodes (flow_id, node_key, node_type, title, content, position_x, position_y)
VALUES (@f, UUID(), 'end', 'Fim', '', 0, 600);
SET @n_end = LAST_INSERT_ID();

UPDATE flow_nodes SET config = JSON_OBJECT('presentation', 'list_menu',
    'store_prompt', 'Qual loja deseja atendimento?',
    'unit_prompt', 'Qual unidade?',
    'invalid_message', 'Opção inválida. Por favor, escolha uma opção válida:',
    'error_message', 'Falha ao buscar as opções. Tente novamente.',
    'max_attempts', 3,
    'save_unit', TRUE,
    'no_store_node_id', @n_cnpj,
    'next_node_id', @n_ok) WHERE id = @n_guild;

INSERT INTO flow_options (node_id, label, value, sort_order, next_node_id)
VALUES (@n_start, 'go', 'go', 0, @n_guild);

INSERT INTO flow_options (node_id, label, value, sort_order, next_node_id)
VALUES (@n_cnpj, 'Continuar', 'next', 0, @n_msg);

INSERT INTO flow_options (node_id, label, value, sort_order, next_node_id)
VALUES (@n_msg, 'Continuar', 'next', 0, @n_hand);

INSERT INTO flow_options (node_id, label, value, sort_order, next_node_id)
VALUES (@n_ok, 'Continuar', 'next', 0, @n_end);

UPDATE flows SET start_node_id = @n_start WHERE id = @f;
