-- Mapa LID -> telefone aprendido dos webhooks (necessário p/ menções em grupos,
-- onde participantes e mencionados chegam como @lid sem o telefone).
CREATE TABLE IF NOT EXISTS whatsapp_lid_map (
    lid_digits VARCHAR(40) NOT NULL PRIMARY KEY,
    phone_digits VARCHAR(40) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
