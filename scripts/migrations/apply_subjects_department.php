<?php
require __DIR__ . '/../bootstrap.php';
$db = \App\Core\Database::getInstance();

$cols = array_column($db->fetchAll("SHOW COLUMNS FROM conversation_subjects"), 'Field');
if (in_array('department_id', $cols, true)) {
    echo "department_id ja existe\nOK\n";
    exit(0);
}
$sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_09_subjects_department.sql');
$db->query($sql);
echo "department_id adicionada\nOK\n";
