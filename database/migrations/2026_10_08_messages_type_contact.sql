-- Cartão de contato (vCard) nas conversas 1:1: novo tipo 'contact' em messages.
ALTER TABLE messages MODIFY COLUMN `type` ENUM('text','image','audio','video','file','system','internal_note','csat_request','button_list','list_menu','contact') NOT NULL DEFAULT 'text';
