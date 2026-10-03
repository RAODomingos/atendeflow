-- Módulo Wiki / Base de Conhecimento — OminiDesk
-- Tabelas próprias (prefixo wiki_) para não colidir com o core.
-- Baseado em wiki/BD.sql (tabelas articles, categories, article_views).

CREATE TABLE IF NOT EXISTS wiki_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  icon_svg TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_wiki_cat_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wiki_articles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(150) NOT NULL UNIQUE,
  category_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  content LONGTEXT NULL,
  cover_image VARCHAR(500) NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  published_at DATE NULL,
  view_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_wiki_art_cat (category_id),
  INDEX idx_wiki_art_featured (featured),
  INDEX idx_wiki_art_sort (sort_order),
  CONSTRAINT fk_wiki_art_cat FOREIGN KEY (category_id) REFERENCES wiki_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wiki_article_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  article_id BIGINT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  referrer VARCHAR(500) NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wiki_view_art (article_id),
  CONSTRAINT fk_wiki_view_art FOREIGN KEY (article_id) REFERENCES wiki_articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chave da API pública do Wiki (usada pelo front-end externo).
-- Gerada automaticamente pelo WikiController se não existir.
INSERT INTO settings (`key`, value)
SELECT 'wiki_api_key', SHA2(UUID(), 256)
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'wiki_api_key');

INSERT INTO settings (`key`, value)
SELECT 'wiki_enabled', '1'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'wiki_enabled');
