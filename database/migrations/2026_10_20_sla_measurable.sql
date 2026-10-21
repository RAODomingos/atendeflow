-- SLA mensurável: medição de compliance dos dois SLAs.
--
-- 1) conversation_slas — uma linha por conversa, com o resultado da
--    medição (primeira resposta e resolução), os limites que estavam em
--    vigor no momento (snapshot: mudar a config não reescreve o
--    histórico) e o período em aberto (bola com a gente).
-- 2) inboxes — overrides por caixa: limites de primeira resposta e de
--    resolução, uso do horário comercial e os 4 toggles de escalonamento.
--    NULL = herda o global (mesmo padrão de sla_use_global).
-- 3) departments — telefone de plantão para o aviso de escalonamento.
-- 4) conversation_events — tipos sla_breached e sla_escalated.
--
-- Idempotente: pode ser re-aplicada sem efeito colateral.

CREATE TABLE IF NOT EXISTS conversation_slas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    response_seconds INT NULL,
    response_at DATETIME NULL,
    response_breached TINYINT(1) NOT NULL DEFAULT 0,
    response_threshold INT NULL,
    resolution_seconds INT NOT NULL DEFAULT 0,
    resolution_breached TINYINT(1) NOT NULL DEFAULT 0,
    resolution_threshold INT NULL,
    period_started_at DATETIME NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slas_conversation (conversation_id),
    INDEX idx_slas_breached (response_breached, resolution_breached),
    INDEX idx_slas_updated (updated_at),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE inboxes ADD COLUMN sla_response_minutes INT NULL DEFAULT NULL;

ALTER TABLE inboxes ADD COLUMN sla_resolution_minutes INT NULL DEFAULT NULL;

ALTER TABLE inboxes ADD COLUMN sla_business_hours TINYINT(1) NULL DEFAULT NULL;

ALTER TABLE inboxes ADD COLUMN sla_escalate_managers TINYINT(1) NULL DEFAULT NULL;

ALTER TABLE inboxes ADD COLUMN sla_escalate_tag TINYINT(1) NULL DEFAULT NULL;

ALTER TABLE inboxes ADD COLUMN sla_escalate_autoassign TINYINT(1) NULL DEFAULT NULL;

ALTER TABLE inboxes ADD COLUMN sla_escalate_whatsapp TINYINT(1) NULL DEFAULT NULL;

ALTER TABLE departments ADD COLUMN sla_escalation_phone VARCHAR(20) NULL DEFAULT NULL;

-- O enum precisa listar TODOS os valores em uso: o schema.sql estava
-- defasado (12 valores) e o código grava flow_stopped, flow_timeout,
-- flow_notification, snoozed, csat e merged. Sem eles, o MODIFY
-- trunca as linhas existentes e a migration falha.
ALTER TABLE conversation_events
    MODIFY COLUMN event_type ENUM(
        'created', 'assigned', 'transferred', 'status_changed',
        'priority_changed', 'note_added', 'flow_started',
        'flow_completed', 'tag_added', 'tag_removed',
        'department_changed', 'reopened',
        'flow_stopped', 'flow_timeout', 'flow_notification',
        'snoozed', 'csat', 'merged',
        'sla_breached', 'sla_escalated'
    ) NOT NULL;
