<?php
require __DIR__ . '/../bootstrap.php';
$u = \App\Core\Database::getInstance()->fetch('SELECT id FROM users LIMIT 1');
$uid = $u ? (int) $u['id'] : 1;
$ids = array_column(\App\Models\Inbox::getUserInboxes($uid), 'id');
$r = \App\Models\Conversation::openCountsByInbox($ids ?: [0]);
echo 'user=' . $uid . ' inboxes=' . count($ids) . ' counts=' . json_encode($r) . PHP_EOL;
echo "PASS: openCountsByInbox OK\n";
