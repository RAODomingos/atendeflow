-- Fila de jobs: dead-letter e diagnóstico.
--
-- O processador marcava o job como "processado" (e apagava a linha) mesmo
-- quando o handler não existia, e o ramo que removia o job após o limite
-- de tentativas nunca era alcançado (o SELECT já exigia
-- attempts < max_attempts). O resultado: job que falha para sempre
-- some da fila sem deixar rastro, ou fica nela para sempre sem status.
--
-- failed_at: data em que o job esgotou as tentativas (NULL = ainda na fila)
-- last_error: último erro registrado, para diagnóstico
--
-- Idempotente: pode ser re-aplicada sem efeito colateral.

ALTER TABLE jobs
    ADD COLUMN IF NOT EXISTS failed_at DATETIME NULL AFTER reserved_at,
    ADD COLUMN IF NOT EXISTS last_error TEXT NULL AFTER failed_at;
