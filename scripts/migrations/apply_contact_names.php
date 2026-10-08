<?php
require __DIR__ . '/../bootstrap.php';
$db = \App\Core\Database::getInstance();

try {
    $db->fetch("SELECT name FROM whatsapp_contact_names LIMIT 1");
    echo "whatsapp_contact_names ja existe\nOK\n";
} catch (\Throwable $e) {
    $sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_09_whatsapp_contact_names.sql');
    $db->query($sql);
    echo "whatsapp_contact_names criada\nOK\n";
}
