<?php
require __DIR__ . '/../bootstrap.php';
$db = \App\Core\Database::getInstance();

$cols = array_column($db->fetchAll("SHOW COLUMNS FROM conversations"), 'Field');

if (!in_array('close_reason', $cols, true)) {
    $db->query("ALTER TABLE conversations ADD COLUMN close_reason VARCHAR(120) NULL AFTER closed_at");
    echo "close_reason adicionado\n";
} else {
    echo "close_reason ja existe\n";
}

if (!in_array('close_description', $cols, true)) {
    $db->query("ALTER TABLE conversations ADD COLUMN close_description TEXT NULL AFTER close_reason");
    echo "close_description adicionado\n";
} else {
    echo "close_description ja existe\n";
}

echo "OK\n";
