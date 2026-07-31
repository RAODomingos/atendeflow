-- Adicionar campo priority para suportar múltiplos fluxos com priorização
ALTER TABLE flows ADD COLUMN priority INT NOT NULL DEFAULT 0 AFTER is_active;
ALTER TABLE flows ADD COLUMN config JSON NULL AFTER priority;
ALTER TABLE flows ADD INDEX idx_flows_priority (priority);
