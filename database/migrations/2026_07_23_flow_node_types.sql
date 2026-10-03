-- Add new node types for chatbot builder
-- (inclui finish/notify/day_of_week/time_range para manter o enum consistente
-- com o editor de fluxos; ver 2026_07_31_flow_node_types_extended.sql)
ALTER TABLE flow_nodes MODIFY COLUMN node_type ENUM(
    'start', 'message', 'menu', 'question', 'collect_field',
    'condition', 'assign_department', 'assign_user',
    'add_tag', 'handoff', 'end',
    'button_list', 'list_menu', 'send_file', 'delay',
    'image', 'audio', 'video',
    'finish', 'notify', 'day_of_week', 'time_range'
) NOT NULL;
