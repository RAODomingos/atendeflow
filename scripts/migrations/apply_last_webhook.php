<?php
require __DIR__ . '/../bootstrap.php';
$db = \App\Core\Database::getInstance();

$cols = array_column($db->fetchAll("SHOW COLUMNS FROM whatsapp_connections"), 'Field');
if (in_array('last_webhook_at', $cols, true)) {
    echo "last_webhook_at ja existe\nOK\n";
    exit(0);
}
$sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_09_whatsapp_last_webhook.sql');
$db->query($sql);
echo "last_webhook_at adicionada\nOK\n";
