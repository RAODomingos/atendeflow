-- Cadastro de motivos de encerramento de conversa
-- Substitui a lista hardcoded no modal de status por uma tabela configurável.
CREATE TABLE IF NOT EXISTS close_reasons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    icon VARCHAR(40) NULL,
    color VARCHAR(7) NULL DEFAULT '#6c757d',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_close_reasons_active (is_active, sort_order)
) ENGINE=InnoDB;

INSERT IGNORE INTO close_reasons (code, label, description, icon, color, sort_order) VALUES
    ('resolved',         'Resolvido',                'Solicitação atendida com sucesso.',                'fa-check-circle',  '#2e7d32', 10),
    ('duplicate',         'Duplicado',                'Atendimento já realizado em outro chamado.',     'fa-copy',          '#6c757d', 20),
    ('no_response',       'Cliente não respondeu',    'Sem retorno do cliente após tentativas.',         'fa-user-clock',    '#f57c00', 30),
    ('out_of_scope',      'Fora de escopo',           'Solicitação não pertence a este setor/empresa.', 'fa-sign-out-alt',  '#7b1fa2', 40),
    ('cancelled',         'Solicitação cancelada',    'Cliente desistiu do atendimento.',                'fa-ban',           '#d32f2f', 50),
    ('invalid_contact',   'Contato inválido',         'Número/e-mail não existe ou está bloqueado.',    'fa-phone-slash',   '#d32f2f', 60),
    ('spam',              'Spam / Golpe',             'Tentativa de fraude ou mensagem automática.',    'fa-exclamation-triangle', '#d32f2f', 70),
    ('other',             'Outro',                    'Outro motivo não listado.',                        'fa-ellipsis-h',    '#5c6bc0', 90);
