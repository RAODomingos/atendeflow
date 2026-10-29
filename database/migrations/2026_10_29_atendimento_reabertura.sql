-- Reabertura de atendimento: o que acontece quando o mesmo cliente
-- escreve de novo no WhatsApp depois de o atendimento anterior ter
-- sido encerrado.
--
-- NULL nas colunas da caixa = "usar o padrão do sistema" (mesma
-- semântica das colunas de SLA por caixa).
ALTER TABLE inboxes
  ADD COLUMN atendimento_modo VARCHAR(20) NULL DEFAULT NULL,
  ADD COLUMN atendimento_janela_minutos INT NULL DEFAULT NULL;

-- Padrão do sistema: cada atendimento é único (comportamento de hoje).
-- O código também usa este valor como fallback quando a linha não existe.
INSERT INTO settings (`key`, value) VALUES
  ('atendimento_reabertura_modo', 'unique'),
  ('atendimento_reabertura_janela_minutos', '120')
ON DUPLICATE KEY UPDATE `key` = `key`;
