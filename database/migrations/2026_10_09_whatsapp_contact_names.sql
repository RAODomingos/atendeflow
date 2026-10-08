-- Nomes de participantes aprendidos dos webhooks (pushName/notifyName).
-- O /group/info não retorna nomes; quem já falou no grupo tem nome conhecido.
CREATE TABLE IF NOT EXISTS whatsapp_contact_names (
    phone_digits VARCHAR(40) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
