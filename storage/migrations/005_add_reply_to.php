<?php
/**
 * Migration: add reply_to column to messages table
 */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/app/Core/Helper.php';
$db = \App\Core\Database::getInstance();

$cols = $db->fetchAll("SHOW COLUMNS FROM messages");
$colNames = array_column($cols, 'Field');

if (!in_array('reply_to', $colNames)) {
    $db->query("ALTER TABLE messages ADD COLUMN reply_to INT(11) DEFAULT NULL AFTER reactions");
    echo "OK: reply_to column added\n";
} else {
    echo "OK: reply_to already exists\n";
}

// Ensure sticker_type exists too (for sticker metadata)
if (!in_array('sticker_type', $colNames)) {
    $db->query("ALTER TABLE messages ADD COLUMN sticker_type VARCHAR(20) DEFAULT NULL AFTER reply_to");
    echo "OK: sticker_type column added\n";
} else {
    echo "OK: sticker_type already exists\n";
}

echo "Done.\n";
