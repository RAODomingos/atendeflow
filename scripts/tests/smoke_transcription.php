<?php
require __DIR__ . '/../bootstrap.php';

$ok = true;
function check(bool $cond, string $msg): void {
    global $ok;
    echo ($cond ? "ok" : "FAIL") . ": $msg\n";
    if (!$cond) $ok = false;
}

// Task 1 — migration + config (RED primeiro: arquivos ainda não existem)
$mig = __DIR__ . '/../../database/migrations/2026_10_08_message_transcription.sql';
check(is_file($mig), 'migration transcription existe');
if (is_file($mig)) {
    $sql = file_get_contents($mig);
    check(str_contains($sql, 'transcription'), 'migration menciona transcription');
    check(str_contains($sql, 'transcribed_at'), 'migration menciona transcribed_at');
}
$envEx = __DIR__ . '/../../env/.env.example';
$env = is_file($envEx) ? file_get_contents($envEx) : '';
check(str_contains($env, 'OPENAI_API_KEY'), 'env.example tem OPENAI_API_KEY');
check(str_contains($env, 'TRANSCRIPTION_MODEL=whisper-1'), 'env.example tem TRANSCRIPTION_MODEL');
check(str_contains($env, 'TRANSCRIPTION_LANG=pt'), 'env.example tem TRANSCRIPTION_LANG');
check(str_contains($env, 'TRANSCRIPTION_MAX_MB=25'), 'env.example tem TRANSCRIPTION_MAX_MB=25');

exit($ok ? 0 : 1);
