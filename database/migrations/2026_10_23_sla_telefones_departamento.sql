-- SLA: lista de telefones de alerta por departamento.
--
-- Até aqui `departments.sla_escalation_phone` cabia UM número (VARCHAR(20)),
-- então só dava para avisar um plantão por vez. Como o alerta do SLA é
-- justamente para acionar o gerente/plantão, o departamento costuma ter
-- mais de um número na rotação.
--
-- Em vez de criar outra tabela, a mesma coluna passa a aceitar uma LISTA
-- de números separados por vírgula (a tela de departamento grava um por
-- linha). O tamanho 255 cabe com folga vários números com DDI.
--
-- O valor antigo (um número só) continua válido: a leitura normaliza
-- qualquer quantidade de números, inclusive um.
--
-- Idempotente: pode ser re-aplicada sem efeito colateral.

ALTER TABLE departments
    MODIFY COLUMN sla_escalation_phone VARCHAR(255) NULL DEFAULT NULL;
