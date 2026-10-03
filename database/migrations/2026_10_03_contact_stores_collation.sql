-- contact_stores herdou a collation default do servidor (utf8mb4_0900_ai_ci)
-- enquanto o banco usa utf8mb4_unicode_ci: o COALESCE com contacts.company
-- quebrava o UNION ALL da lista do inbox (erro 1271, HTTP 500).
ALTER TABLE contact_stores CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
