-- Migration para melhorias no sistema de fluxos
-- Data: 2026-07-23

-- 1. Adicionar versionamento de fluxos
ALTER TABLE flows ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER is_active;
ALTER TABLE flows ADD COLUMN parent_flow_id BIGINT UNSIGNED NULL AFTER version;
ALTER TABLE flows ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_flow_id;
ALTER TABLE flows ADD INDEX idx_flows_parent (parent_flow_id);
ALTER TABLE flows ADD INDEX idx_flows_version (version);
ALTER TABLE flows ADD FOREIGN KEY (parent_flow_id) REFERENCES flows(id) ON DELETE SET NULL;

-- 2. Adicionar timeout em estados de fluxo
ALTER TABLE conversation_flow_states ADD COLUMN timeout_at DATETIME NULL AFTER current_node_id;
ALTER TABLE conversation_flow_states ADD COLUMN last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER timeout_at;
ALTER TABLE conversation_flow_states ADD COLUMN retry_count INT NOT NULL DEFAULT 0 AFTER last_activity_at;
ALTER TABLE conversation_flow_states ADD INDEX idx_flow_state_timeout (timeout_at);
ALTER TABLE conversation_flow_states ADD INDEX idx_flow_state_activity (last_activity_at);

-- 3. Adicionar campos de retry em messages
ALTER TABLE messages ADD COLUMN retry_count INT NOT NULL DEFAULT 0 AFTER channel_message_id;
ALTER TABLE messages ADD COLUMN next_retry_at DATETIME NULL AFTER retry_count;
ALTER TABLE messages ADD COLUMN delivery_status ENUM('pending', 'sent', 'delivered', 'failed') NOT NULL DEFAULT 'pending' AFTER next_retry_at;
ALTER TABLE messages ADD INDEX idx_messages_retry (next_retry_at);
ALTER TABLE messages ADD INDEX idx_messages_status (delivery_status);

-- 4. Criar tabela de logs de execução de fluxo
CREATE TABLE flow_execution_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    flow_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_data JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_flow_logs_conversation (conversation_id),
    INDEX idx_flow_logs_flow (flow_id),
    INDEX idx_flow_logs_node (node_id),
    INDEX idx_flow_logs_event (event_type),
    INDEX idx_flow_logs_created (created_at),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (flow_id) REFERENCES flows(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES flow_nodes(id) ON DELETE CASCADE
);

-- 5. Atualizar enum de flow_nodes para incluir novos tipos
-- (inclui finish/notify/day_of_week/time_range; sem isso o MariaDB trunca
-- esses tipos para '' ao salvar e os nós não executam)
ALTER TABLE flow_nodes MODIFY COLUMN node_type ENUM(
    'start', 'message', 'menu', 'question', 'collect_field',
    'condition', 'assign_department', 'assign_user',
    'add_tag', 'handoff', 'end', 'delay', 'button_list',
    'list_menu', 'image', 'audio', 'video', 'send_file',
    'finish', 'notify', 'day_of_week', 'time_range'
) NOT NULL;

-- 6. Adicionar configurações globais de fluxo
CREATE TABLE flow_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) NOT NULL UNIQUE,
    value TEXT NOT NULL,
    description TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Inserir configurações padrão
INSERT INTO flow_settings (key_name, value, description) VALUES
('flow_timeout_minutes', '30', 'Timeout padrão para fluxos em minutos'),
('flow_max_retries', '3', 'Número máximo de retries para mensagens outbound'),
('flow_retry_backoff_seconds', '60', 'Tempo base de backoff para retries em segundos'),
('flow_cleanup_days', '90', 'Dias para manter logs de execução de fluxo');
