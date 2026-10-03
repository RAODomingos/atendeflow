-- Lojas/unidades Guild vinculadas ao contato (1 linha por unidade).
CREATE TABLE contact_stores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    customer_id VARCHAR(64) NOT NULL COMMENT 'codigo Guild digitado',
    network_name VARCHAR(255) NOT NULL COMMENT 'loja',
    store_id BIGINT UNSIGNED NOT NULL COMMENT 'unidade',
    store_name VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contact_store (contact_id, store_id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
);
