<?php
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/lib/autoload.php';
require_once BASE_PATH . '/app/Core/Helper.php';

$db = \App\Core\Database::getInstance();

echo "=== messages table columns ===\n";
$cols = $db->fetchAll("DESCRIBE messages");
foreach ($cols as $c) {
    echo "  {$c['Field']} - {$c['Type']}" . PHP_EOL;
}

echo "\n=== unread-conversations check ===\n";
$unread = $db->fetchAll("SELECT COUNT(*) as cnt FROM messages WHERE direction='inbound' AND is_read=0");
echo "  Inbound unread messages: " . ($unread[0]['cnt'] ?? 0) . "\n";

echo "\n=== Notifications check ===\n";
$notifs = $db->fetchAll("SELECT COUNT(*) as cnt FROM notifications WHERE is_read=0");
echo "  Unread notifications: " . ($notifs[0]['cnt'] ?? 0) . "\n";
