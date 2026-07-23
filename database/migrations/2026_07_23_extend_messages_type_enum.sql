ALTER TABLE messages MODIFY COLUMN `type` enum('text','image','audio','video','file','system','internal_note','csat_request','button_list','list_menu') NOT NULL DEFAULT 'text';
