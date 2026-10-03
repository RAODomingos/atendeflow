-- Tipo do nó dinâmico Loja/Unidade Guild (ramifica por contato em runtime).
ALTER TABLE flow_nodes MODIFY COLUMN node_type ENUM(
    'start', 'message', 'menu', 'question', 'collect_field',
    'condition', 'assign_department', 'assign_user',
    'add_tag', 'handoff', 'end', 'button_list', 'list_menu',
    'send_file', 'delay', 'image', 'audio', 'video',
    'finish', 'notify', 'day_of_week', 'time_range',
    'guild_select'
) NOT NULL;
