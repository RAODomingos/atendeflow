<?php
require __DIR__ . '/../bootstrap.php';
$db = \App\Core\Database::getInstance();

$row = $db->fetch("SHOW COLUMNS FROM messages LIKE 'type'");
$type = (string) ($row['Type'] ?? '');
if (str_contains($type, "'contact'")) {
    echo "contact ja existe em messages.type\nOK\n";
    exit(0);
}
$sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_08_messages_type_contact.sql');
$db->query($sql);
echo "messages.type += contact\nOK\n";
