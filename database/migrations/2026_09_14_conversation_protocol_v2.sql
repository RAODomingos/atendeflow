-- Protocolo v2: AAMMDD + id, sem traco (ex.: 260914145).
-- Regrava todos (conversas antigas estavam em AAAAMM+id).
UPDATE conversations SET protocol = CONCAT(DATE_FORMAT(created_at, '%y%m%d'), id);
