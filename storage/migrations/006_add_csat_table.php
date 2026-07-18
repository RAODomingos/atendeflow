<?php
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/app/Core/Helper.php';
$db = \App\Core\Database::getInstance();

$tables = $db->fetchAll("SHOW TABLES");
$tableNames = array_map('current', $tables);

if (!in_array('conversation_csats', $tableNames)) {
    $db->query("CREATE TABLE conversation_csats (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        rating TINYINT NOT NULL,
        comment TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: conversation_csats table created\n";
} else {
    echo "OK: conversation_csats already exists\n";
}
echo "Done.\n";
