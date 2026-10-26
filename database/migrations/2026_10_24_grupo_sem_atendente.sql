-- Conversa de grupo nao tem atendente e nao muda de status.
--
-- Conversa de WhatsApp em grupo nao e atendimento individual: nao exige
-- responsavel e fica sempre "em aberto". Quem sinaliza que ha trabalho e
-- a MENCAO no grupo (que acende o sino e a bolinha da caixa de grupo).
--
-- Antes, responder no grupo autoatribuia a conversa a quem respondeu e a
-- jogava para "aguardando cliente"; o SLA ainda escalava para o
-- atendente menos carregado. Isso obrigava a escolher um atendente para
-- um grupo que nao tem dono.

UPDATE conversations
SET status = 'open'
WHERE group_id IS NOT NULL
  AND status <> 'open';

UPDATE conversations
SET assigned_user_id = NULL
WHERE group_id IS NOT NULL
  AND assigned_user_id IS NOT NULL;
