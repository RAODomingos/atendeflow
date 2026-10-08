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

// Task 2 — provider + service com cache (FakeProvider, rollback p/ não poluir)
check(interface_exists(\App\Services\Transcription\TranscriptionProviderInterface::class), 'interface TranscriptionProvider existe');
check(class_exists(\App\Services\Transcription\OpenAiWhisperProvider::class), 'OpenAiWhisperProvider existe');
check(method_exists(\App\Services\TranscriptionService::class, 'transcribeMessage'), 'TranscriptionService::transcribeMessage existe');

if (interface_exists(\App\Services\Transcription\TranscriptionProviderInterface::class)
    && method_exists(\App\Services\TranscriptionService::class, 'transcribeMessage')) {
    $fake = new class implements \App\Services\Transcription\TranscriptionProviderInterface {
        public int $calls = 0;
        public function transcribe(string $filePath, string $lang): string {
            $this->calls++;
            return 'texto transcrito fake';
        }
    };
    $db = \App\Core\Database::getInstance();
    $conv = $db->fetch("SELECT id FROM conversations LIMIT 1");
    if ($conv) {
        // WAV mínimo válido no upload_dir real (snifa como audio/x-wav)
        $fname = 'smoke_transc_' . bin2hex(random_bytes(6)) . '.wav';
        $tmp = upload_dir() . '/' . $fname;
        $wav = "RIFF" . pack("V", 36) . "WAVE" . "fmt " . pack("V", 16) . pack("v", 1) . pack("v", 1) . pack("V", 8000) . pack("V", 8000) . pack("v", 1) . pack("v", 8) . "data" . pack("V", 0);
        file_put_contents($tmp, $wav);
        $db->beginTransaction();
        try {
            $mid = (int) $db->insert('messages', [
                'conversation_id' => $conv['id'],
                'type' => 'audio',
                'content' => json_encode(['url' => 'uploads/' . $fname, 'name' => $fname, 'mime' => 'audio/wav']),
                'direction' => 'inbound',
            ]);
            $r1 = \App\Services\TranscriptionService::transcribeMessage($mid, 1, $fake);
            $r2 = \App\Services\TranscriptionService::transcribeMessage($mid, 1, $fake);
            check(($r1['text'] ?? '') === 'texto transcrito fake', 'service retorna texto do provider');
            check(($r2['cached'] ?? false) === true, '2a chamada vem do cache');
            check($fake->calls === 1, 'provider chamado só 1x (cache)');
        } finally {
            $db->rollBack();
            @unlink($tmp);
        }
    } else {
        check(false, 'há conversa p/ teste de cache');
    }
}

// Task 3 — controller + rota (registro; View::json faz exit, sem teste HTTP in-process)
$routes = file_get_contents(__DIR__ . '/../../routes/web.php');
check(str_contains($routes, "/api/messages/{id}/transcribe"), 'rota transcribe registrada');
check(str_contains($routes, 'TranscriptionController'), 'rota usa TranscriptionController');
check(class_exists(\App\Controllers\Api\TranscriptionController::class), 'TranscriptionController existe');
check(method_exists(\App\Controllers\Api\TranscriptionController::class, 'transcribe'), 'TranscriptionController::transcribe existe');

exit($ok ? 0 : 1);
