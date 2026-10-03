-- Áudios personalizados de notificação (upload por usuário).
-- Referenciados nas preferências como sound_* = 'custom:<id>'.
CREATE TABLE IF NOT EXISTS notification_sounds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    path VARCHAR(255) NOT NULL COMMENT 'Relativo a public/uploads (ex.: sounds/3/ab12cd.mp3)',
    mime VARCHAR(100) NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sounds_user (user_id),
    CONSTRAINT notification_sounds_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
