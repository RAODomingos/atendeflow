<?php
// Trava de regressão: valida a sintaxe dos <script> inline das views.
// (Erros aqui matam TODOS os botões/AJAX da página — ex.: Enter vira quebra
// de linha e o "Tentar de novo" não funciona.) Pula se node indisponível.
require __DIR__ . '/../bootstrap.php';

$ok = true;
function check(bool $cond, string $msg): void {
    global $ok;
    echo ($cond ? "ok" : "FAIL") . ": $msg\n";
    if (!$cond) $ok = false;
}

$node = trim((string) shell_exec('where node 2>nul'));
if ($node === '') {
    echo "SKIP: node indisponível\n";
    exit(0);
}
$node = explode("\n", $node)[0];

$views = [
    'app/Views/inbox/panel.php',
    'app/Views/inbox/show.php',
    'app/Views/layouts/main.php',
    'app/Views/contacts/index.php',
    'app/Views/settings/notifications.php',
    'app/Views/whatsapp/groups.php',
    'app/Views/whatsapp/group_show.php',
];
$base = dirname(__DIR__, 2);
foreach ($views as $rel) {
    $html = file_get_contents($base . '/' . $rel);
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $m);
    $i = 0;
    foreach ($m[1] as $code) {
        if (trim($code) === '') continue;
        // Neutraliza tags PHP de echo e blocos para o node validar.
        $QT = chr(63) . chr(62);
        $code = preg_replace('/<\?=\s.*?' . preg_quote($QT, '/') . '/s', '1', $code);
        $code = preg_replace('/<\?php.*?' . preg_quote($QT, '/') . '/s', '', $code);
        $tmp = sys_get_temp_dir() . '/inline_check_' . md5($rel) . '_' . $i . '.js';
        file_put_contents($tmp, $code);
        $out = [];
        $ret = 0;
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $ret);
        check($ret === 0, $rel . ' bloco ' . $i . ($ret === 0 ? '' : ' -> ' . implode(' | ', array_slice($out, 0, 3))));
        @unlink($tmp);
        $i++;
    }
}
echo $ok ? "PASS inline JS OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
