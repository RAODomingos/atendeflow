-- Estender o enum de node_type com os tipos que o editor oferece mas faltavam
-- no banco (MariaDB truncava para '' ao salvar): finish, notify, day_of_week,
-- time_range. Sem isso, o nó "Finalizar" era salvo como tipo vazio e o
-- FlowEngineService nunca executava o case 'finish' (a conversa não fechava).
ALTER TABLE flow_nodes MODIFY COLUMN node_type ENUM(
    'start', 'message', 'menu', 'question', 'collect_field',
    'condition', 'assign_department', 'assign_user',
    'add_tag', 'handoff', 'end', 'button_list', 'list_menu',
    'send_file', 'delay', 'image', 'audio', 'video',
    'finish', 'notify', 'day_of_week', 'time_range'
) NOT NULL;

-- Backfill: nós salvos com tipo vazio (truncados pelo MariaDB) recebem o tipo correto.
-- "Finalizar" => finish (fecha a conversa), "Notificar" => notify.
-- Tolerante a caixa/espaços no título para cobrir variações digitadas pelo usuário.
UPDATE flow_nodes SET node_type = 'finish' WHERE node_type = '' AND LOWER(TRIM(title)) = 'finalizar';
UPDATE flow_nodes SET node_type = 'notify'  WHERE node_type = '' AND LOWER(TRIM(title)) = 'notificar';
