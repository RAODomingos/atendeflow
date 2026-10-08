-- OminiDesk - Database Schema
-- MySQL 8+

CREATE DATABASE IF NOT EXISTS atendeflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE atendeflow;

-- Users
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'agent', 'viewer') NOT NULL DEFAULT 'agent',
    avatar VARCHAR(255) NULL,
    signature TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_email (email),
    INDEX idx_users_role (role)
);

-- User notification preferences
CREATE TABLE user_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_pref (user_id, preference_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Departments
CREATE TABLE departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    color VARCHAR(7) NULL DEFAULT '#4A90D9',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Department users (N:N)
CREATE TABLE department_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    is_manager TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dept_user (department_id, user_id),
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Contacts
CREATE TABLE contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    company VARCHAR(255) NULL,
    document VARCHAR(50) NULL,
    notes TEXT NULL,
    avatar VARCHAR(255) NULL,
    last_contact_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contacts_email (email),
    INDEX idx_contacts_phone (phone),
    INDEX idx_contacts_name (name)
);

-- Contact phones
CREATE TABLE contact_phones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    phone VARCHAR(50) NOT NULL,
    label VARCHAR(50) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
);

-- Contact emails
CREATE TABLE contact_emails (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    label VARCHAR(50) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
);

-- Tags
CREATE TABLE tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) NULL DEFAULT '#6C757D',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tags_name (name)
);

-- Contact tags (N:N)
CREATE TABLE contact_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contact_tag (contact_id, tag_id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- Channels (whatsapp, webchat, email)
CREATE TABLE channels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('whatsapp', 'webchat') NOT NULL,
    name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    config JSON NULL,
    department_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- WhatsApp connections
CREATE TABLE whatsapp_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'waha',
    instance_name VARCHAR(100) NULL,
    instance_id VARCHAR(100) NULL,
    instance_token VARCHAR(255) NULL,
    base_url VARCHAR(255) NULL,
    phone_number VARCHAR(50) NULL,
    status ENUM('disconnected', 'waiting_qr', 'connected', 'error') NOT NULL DEFAULT 'disconnected',
    qr_code TEXT NULL,
    session_data TEXT NULL,
    webhook_url VARCHAR(500) NULL,
    webhook_secret VARCHAR(100) NULL,
    last_connected_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wa_provider (provider, instance_name),
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

-- WebChat widgets
CREATE TABLE webchat_widgets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_id BIGINT UNSIGNED NOT NULL,
    widget_key CHAR(36) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL DEFAULT 'Atendimento',
    welcome_message TEXT NULL,
    color_primary VARCHAR(7) NOT NULL DEFAULT '#4A90D9',
    position ENUM('left', 'right') NOT NULL DEFAULT 'right',
    department_id BIGINT UNSIGNED NULL,
    flow_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    ask_name TINYINT(1) NOT NULL DEFAULT 1,
    require_name TINYINT(1) NOT NULL DEFAULT 1,
    ask_email TINYINT(1) NOT NULL DEFAULT 0,
    require_email TINYINT(1) NOT NULL DEFAULT 0,
    ask_phone TINYINT(1) NOT NULL DEFAULT 0,
    require_phone TINYINT(1) NOT NULL DEFAULT 0,
    ask_cnpj TINYINT(1) NOT NULL DEFAULT 0,
    require_cnpj TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- Inboxes
CREATE TABLE inboxes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type ENUM('general', 'department', 'personal') NOT NULL DEFAULT 'department',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Inbox departments
CREATE TABLE inbox_departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inbox_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (inbox_id) REFERENCES inboxes(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

-- Inbox users
CREATE TABLE inbox_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inbox_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (inbox_id) REFERENCES inboxes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Conversations
CREATE TABLE conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    contact_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    channel_id BIGINT UNSIGNED NOT NULL,
    inbox_id BIGINT UNSIGNED NULL,
    subject VARCHAR(255) NULL,
    status ENUM('new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam') NOT NULL DEFAULT 'new',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    source VARCHAR(50) NULL,
    signature_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_message_at DATETIME NULL,
    closed_at DATETIME NULL,
    close_reason VARCHAR(255) NULL,
    close_description TEXT NULL,
    csat_requested TINYINT(1) NOT NULL DEFAULT 0,
    message_count_cache INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conversation_status (status),
    INDEX idx_conversation_department (department_id),
    INDEX idx_conversation_assigned (assigned_user_id),
    INDEX idx_conversation_contact (contact_id),
    INDEX idx_conversation_channel (channel_id),
    INDEX idx_conversation_last_message (last_message_at),
    FOREIGN KEY (contact_id) REFERENCES contacts(id),
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    FOREIGN KEY (inbox_id) REFERENCES inboxes(id) ON DELETE SET NULL
);

-- Conversation CSAT ratings
CREATE TABLE IF NOT EXISTS conversation_csats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
);

-- Conversation tags (N:N)
CREATE TABLE conversation_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conv_tag (conversation_id, tag_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- Conversation assignments log
CREATE TABLE conversation_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    department_id BIGINT UNSIGNED NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id)
);

-- Messages
CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    message_id VARCHAR(255) NULL,
    type ENUM('text', 'image', 'audio', 'video', 'file', 'system', 'internal_note', 'csat_request', 'button_list', 'list_menu', 'contact') NOT NULL DEFAULT 'text',
    content TEXT NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    channel_message_id VARCHAR(255) NULL,
    user_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_conversation (conversation_id),
    INDEX idx_messages_created (created_at),
    INDEX idx_messages_conv_created_id (conversation_id, created_at, id),
    INDEX idx_messages_unread (conversation_id, direction, is_read, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Message attachments
CREATE TABLE message_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
);

-- Conversation events (history log)
CREATE TABLE conversation_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM(
        'created', 'assigned', 'transferred', 'status_changed',
        'priority_changed', 'note_added', 'flow_started',
        'flow_completed', 'tag_added', 'tag_removed',
        'department_changed', 'reopened'
    ) NOT NULL,
    description TEXT NULL,
    user_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_conversation (conversation_id),
    INDEX idx_events_type (event_type),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Internal notes
CREATE TABLE internal_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Flows (triage/automation)
CREATE TABLE flows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    channel_scope ENUM('all', 'webchat', 'whatsapp') NOT NULL DEFAULT 'all',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    start_node_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Flow nodes
CREATE TABLE flow_nodes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id BIGINT UNSIGNED NOT NULL,
    node_key CHAR(36) NOT NULL,
    node_type ENUM(
        'start', 'message', 'menu', 'question', 'collect_field',
        'condition', 'assign_department', 'assign_user',
        'add_tag', 'handoff', 'end', 'button_list', 'list_menu',
        'send_file', 'delay', 'image', 'audio', 'video',
        'finish', 'notify', 'day_of_week', 'time_range'
    ) NOT NULL,
    title VARCHAR(150) NOT NULL,
    content TEXT NULL,
    config JSON NULL,
    position_x INT NOT NULL DEFAULT 0,
    position_y INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_flow_node_key (node_key),
    INDEX idx_flow_nodes_flow (flow_id),
    FOREIGN KEY (flow_id) REFERENCES flows(id) ON DELETE CASCADE
);

-- Flow options (menu choices, conditions)
CREATE TABLE flow_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    node_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    value VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    next_node_id BIGINT UNSIGNED NULL,
    config JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_flow_options_node (node_id),
    FOREIGN KEY (node_id) REFERENCES flow_nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (next_node_id) REFERENCES flow_nodes(id) ON DELETE SET NULL
);

-- Flow start node reference
ALTER TABLE flows ADD FOREIGN KEY (start_node_id) REFERENCES flow_nodes(id) ON DELETE SET NULL;

-- Conversation flow state (active flow execution)
CREATE TABLE conversation_flow_states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    flow_id BIGINT UNSIGNED NOT NULL,
    current_node_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    INDEX idx_flow_state_conversation (conversation_id),
    INDEX idx_flow_state_active (is_active),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (flow_id) REFERENCES flows(id) ON DELETE CASCADE,
    FOREIGN KEY (current_node_id) REFERENCES flow_nodes(id) ON DELETE SET NULL
);

-- Flow answers
CREATE TABLE flow_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    flow_node_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED NULL,
    answer_text TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_flow_answers_conversation (conversation_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (flow_node_id) REFERENCES flow_nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES flow_options(id) ON DELETE SET NULL
);

-- Canned responses
CREATE TABLE IF NOT EXISTS macros (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    content TEXT NULL,
    department_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    actions JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS macro_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    macro_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL DEFAULT 0,
    type ENUM('text', 'image', 'video', 'audio', 'file') NOT NULL DEFAULT 'text',
    content TEXT NULL,
    media_url VARCHAR(500) NULL,
    media_name VARCHAR(255) NULL,
    media_mime VARCHAR(100) NULL,
    media_size INT UNSIGNED NULL DEFAULT 0,
    media_path VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_macro_items_macro (macro_id, position),
    FOREIGN KEY (macro_id) REFERENCES macros(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE canned_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit log
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Sessions table for custom session handling
CREATE TABLE sessions (
    id VARCHAR(255) PRIMARY KEY,
    data TEXT NOT NULL,
    last_accessed DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Jobs queue
CREATE TABLE jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(100) NOT NULL DEFAULT 'default',
    payload JSON NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    priority INT NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jobs_queue (queue),
    INDEX idx_jobs_available (available_at)
);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Admin user (password: Admin@123)
-- IMPORTANTE: gere um novo hash com password_hash('Admin@123', PASSWORD_BCRYPT) antes de importar
INSERT INTO users (name, email, password, role) VALUES
('Administrador', 'admin@atendeflow.local', '$2y$10$CO3U.hjy0Ss5DrcSaGYVP.lFj5fB/uayegiLt8/.B2vZOfINc8gN6', 'admin');

-- Departments
INSERT INTO departments (name, description, color) VALUES
('Comercial', 'Vendas e relacionamento comercial', '#28A745'),
('Suporte', 'Suporte técnico e atendimento', '#007BFF'),
('Financeiro', 'Cobrança, boletos e negociações', '#DC3545');

-- Default inboxes
INSERT INTO inboxes (name, type) VALUES
('Geral', 'general'),
('Comercial', 'department'),
('Suporte', 'department'),
('Financeiro', 'department');

-- Associate inboxes with departments
INSERT INTO inbox_departments (inbox_id, department_id)
SELECT i.id, d.id FROM inboxes i, departments d
WHERE (i.name = 'Geral' AND d.name = 'Comercial')
   OR (i.name = 'Comercial' AND d.name = 'Comercial')
   OR (i.name = 'Suporte' AND d.name = 'Suporte')
   OR (i.name = 'Financeiro' AND d.name = 'Financeiro');

-- Default channels
INSERT INTO channels (type, name, department_id)
SELECT 'webchat', 'ChatWeb Comercial', id FROM departments WHERE name = 'Comercial';

INSERT INTO channels (type, name, department_id)
SELECT 'webchat', 'ChatWeb Suporte', id FROM departments WHERE name = 'Suporte';

INSERT INTO channels (type, name, department_id)
SELECT 'webchat', 'ChatWeb Financeiro', id FROM departments WHERE name = 'Financeiro';

-- Default tags
INSERT INTO tags (name, color) VALUES
('Lead', '#28A745'),
('Cliente', '#007BFF'),
('Prioridade', '#DC3545'),
('Inadimplente', '#FFC107'),
('VIP', '#6F42C1');

-- Create default ChatWeb widget
INSERT INTO webchat_widgets (channel_id, widget_key, title, welcome_message, department_id)
SELECT c.id, UUID(), 'Atendimento Comercial', 'Olá! Como podemos ajudar?', d.id
FROM channels c JOIN departments d ON d.name = 'Comercial' AND c.name = 'ChatWeb Comercial';
