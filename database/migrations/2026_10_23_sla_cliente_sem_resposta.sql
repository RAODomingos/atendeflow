-- SLA: alerta de "cliente sem resposta" em conversas já ativas.
--
-- Até aqui o escalonamento disparava UMA vez por conversa (na violação da
-- 1ª resposta). Depois da 1ª resposta, o flag `response_breached` ficava
-- em 1 e nada mais era escalonado: se o cliente escrevesse de novo e
-- ninguém respondesse, o atendimento ficava parado sem alerta nenhum.
--
-- A coluna `pending_message_id` guarda a mensagem do cliente que está
-- sem resposta agora (a 1ª mensagem dele desde a última resposta nossa);
-- `pending_alerted_message_id` guarda a mensagem pela qual o alerta já
-- disparou. O worker só escala quando as duas diferem — assim o alerta
-- acontece uma vez por mensagem do cliente, e não uma vez por conversa.
--
-- Idempotente: pode ser re-aplicada sem efeito colateral.

ALTER TABLE conversation_slas
    ADD COLUMN pending_message_id BIGINT UNSIGNED NULL;

ALTER TABLE conversation_slas
    ADD COLUMN pending_alerted_message_id BIGINT UNSIGNED NULL;

ALTER TABLE conversation_slas
    ADD COLUMN pending_seconds INT NULL;

ALTER TABLE conversation_slas
    ADD COLUMN pending_breached TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE conversation_slas
    ADD COLUMN pending_threshold INT NULL;

-- Limite próprio do alerta de "cliente sem resposta" (herda o global).
ALTER TABLE inboxes
    ADD COLUMN sla_pending_response_minutes INT NULL DEFAULT NULL;
