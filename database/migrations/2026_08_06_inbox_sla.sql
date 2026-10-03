-- SLA configurável por caixa de entrada.
-- NULL em qualquer coluna = usar o valor global salvo em `settings` (sla_*).
-- Quando o usuário marca "Usar padrão do sistema" no formulário da caixa,
-- essas colunas ficam em NULL.
ALTER TABLE inboxes
    ADD COLUMN sla_enabled TINYINT(1) NULL DEFAULT NULL AFTER greeting_message,
    ADD COLUMN sla_attention_minutes INT NULL DEFAULT NULL AFTER sla_enabled,
    ADD COLUMN sla_alert_minutes INT NULL DEFAULT NULL AFTER sla_attention_minutes,
    ADD COLUMN sla_color_normal VARCHAR(7) NULL DEFAULT NULL AFTER sla_alert_minutes,
    ADD COLUMN sla_color_attention VARCHAR(7) NULL DEFAULT NULL AFTER sla_color_normal,
    ADD COLUMN sla_color_alert VARCHAR(7) NULL DEFAULT NULL AFTER sla_color_attention,
    ADD COLUMN sla_color_normal_text VARCHAR(7) NULL DEFAULT NULL AFTER sla_color_alert,
    ADD COLUMN sla_color_attention_text VARCHAR(7) NULL DEFAULT NULL AFTER sla_color_normal_text,
    ADD COLUMN sla_color_alert_text VARCHAR(7) NULL DEFAULT NULL AFTER sla_color_attention_text,
    ADD COLUMN sla_sound_attention VARCHAR(20) NULL DEFAULT NULL AFTER sla_color_alert_text,
    ADD COLUMN sla_sound_alert VARCHAR(20) NULL DEFAULT NULL AFTER sla_sound_attention;
