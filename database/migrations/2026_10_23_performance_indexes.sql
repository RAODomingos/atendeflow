-- Indices das consultas quentes.
--
-- Sem eles, cada consulta varre a tabela inteira:
--  - o worker de SLA ordenava por closed_at / period_started_at
--  - o webhook procurava a mensagem pelo id do canal
--  - o processador de jobs varreria a fila inteira a cada 2 segundos
--
-- Idempotente: pode ser re-aplicada sem efeito colateral.

ALTER TABLE messages
    ADD INDEX IF NOT EXISTS idx_messages_channel_msg (channel_message_id);

ALTER TABLE conversations
    ADD INDEX IF NOT EXISTS idx_conv_closed_at (closed_at);

ALTER TABLE conversation_slas
    ADD INDEX IF NOT EXISTS idx_slas_period (period_started_at);

ALTER TABLE jobs
    ADD INDEX IF NOT EXISTS idx_jobs_scan (failed_at, reserved_at, priority, created_at);
