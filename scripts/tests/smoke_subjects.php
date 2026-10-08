<?php
require __DIR__ . '/../bootstrap.php';

use App\Core\Database;

Database::connect();
$db = Database::getInstance();
$ok = true;
function check(bool $cond, string $msg): void {
    global $ok;
    echo ($cond ? "ok" : "FAIL") . ": $msg\n";
    if (!$cond) $ok = false;
}

// 1. Migração: department_id em conversation_subjects
$cols = array_column($db->fetchAll("SHOW COLUMNS FROM conversation_subjects"), 'Field');
check(in_array('department_id', $cols, true), 'coluna department_id existe');

// 2. Modelo filtra por departamento (NULL = Todos)
check(method_exists(\App\Models\ConversationSubject::class, 'forDepartment'), 'model tem forDepartment()');
if (method_exists(\App\Models\ConversationSubject::class, 'forDepartment')) {
    $dept = $db->fetch("SELECT id FROM departments ORDER BY id LIMIT 1");
    $did = $dept ? (int) $dept['id'] : 0;
    $a = $db->insert('conversation_subjects', ['name' => 'Assunto Todos T', 'sort_order' => 0, 'is_active' => 1, 'department_id' => null]);
    $b = $did > 0 ? $db->insert('conversation_subjects', ['name' => 'Assunto Setor T', 'sort_order' => 0, 'is_active' => 1, 'department_id' => $did]) : 0;
    $names = array_column(\App\Models\ConversationSubject::forDepartment($did ?: null), 'name');
    check(in_array('Assunto Todos T', $names, true), 'filtro inclui Todos');
    if ($did > 0) {
        check(in_array('Assunto Setor T', $names, true), 'filtro inclui setor da conversa');
        $namesOther = array_column(\App\Models\ConversationSubject::forDepartment($did + 999999), 'name');
        check(!in_array('Assunto Setor T', $namesOther, true), 'filtro exclui setor alheio');
    }
    $db->delete('conversation_subjects', 'id = ?', [$a]);
    if ($b) $db->delete('conversation_subjects', 'id = ?', [$b]);
}

// 3. Biblioteca tem seção Assuntos estilo Respostas
$libSrc = file_get_contents(__DIR__ . '/../../app/Views/library/index.php');
check(str_contains($libSrc, 'subjectTableBody') || str_contains($libSrc, 'Assuntos'), 'library lista assuntos');

// 4. Inbox filtra assuntos pelo setor da conversa
$panelSrc = file_get_contents(__DIR__ . '/../../app/Views/inbox/panel.php');
check(str_contains($panelSrc, 'ConversationSubject::forDepartment'), 'inbox filtra assunto por setor');

echo $ok ? "PASS: subjects por departamento OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
