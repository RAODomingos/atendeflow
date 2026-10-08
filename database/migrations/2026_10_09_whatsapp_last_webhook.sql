-- Rastreia o último evento recebido por conexão: status "connected" parado
-- não significa sessão saudável (quedas silenciosas enfileiram no provedor).
ALTER TABLE whatsapp_connections ADD COLUMN last_webhook_at DATETIME NULL AFTER last_connected_at;
