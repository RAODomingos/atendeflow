-- Provedor padrão das conexões WhatsApp: 'waha' -> 'uazapi'.
--
-- UAZAPI é o provedor em uso no AtendeFlow. O WAHA saiu da lista de seleção
-- de novos canais (config/integrations.php -> whatsapp.hidden_providers), mas
-- continua registrado no WhatsAppManager para que conexões antigas não
-- quebrem. Este ajuste alinha o DEFAULT da coluna ao provedor vigente.
--
-- Idempotente: pode ser executado mais de uma vez.

ALTER TABLE whatsapp_connections
    MODIFY COLUMN provider VARCHAR(50) NOT NULL DEFAULT 'uazapi';
