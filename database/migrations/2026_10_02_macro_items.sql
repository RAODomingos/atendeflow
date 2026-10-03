-- Macros multi-mensagem + mídia (arquivo, vídeo, foto)
-- Cada macro pode ter N itens ordenados (texto e/ou mídia com legenda).
CREATE TABLE IF NOT EXISTS macro_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    macro_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL DEFAULT 0,
    type ENUM('text', 'image', 'video', 'audio', 'file') NOT NULL DEFAULT 'text',
    content TEXT NULL COMMENT 'Texto da mensagem ou legenda da mídia (suporta variáveis)',
    media_url VARCHAR(500) NULL,
    media_name VARCHAR(255) NULL,
    media_mime VARCHAR(100) NULL,
    media_size INT UNSIGNED NULL DEFAULT 0,
    media_path VARCHAR(500) NULL COMMENT 'Caminho relativo no disco (ex.: macros/abc.mp4) p/ envio WhatsApp',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_macro_items_macro (macro_id, position),
    FOREIGN KEY (macro_id) REFERENCES macros(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
