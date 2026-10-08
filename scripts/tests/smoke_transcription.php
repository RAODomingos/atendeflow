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

// Task 4 — inbox UI (helper + renders + handler global)
check(function_exists('audio_transcription_html'), 'helper audio_transcription_html existe');
$panel = file_get_contents(__DIR__ . '/../../app/Views/inbox/panel.php');
$show = file_get_contents(__DIR__ . '/../../app/Views/inbox/show.php');
$enh = file_get_contents(__DIR__ . '/../../public/assets/js/app-enhancements.js');
check(str_contains($panel, 'btn-transcribe') || str_contains($panel, 'audio_transcription_html'), 'panel tem transcrição');
check(str_contains($show, 'btn-transcribe') || str_contains($show, 'audio_transcription_html'), 'show tem transcrição');
check(str_contains($enh, 'transcribe'), 'app-enhancements tem handler transcribe');
if (function_exists('audio_transcription_html')) {
    $with = audio_transcription_html(['id' => 7, 'transcription' => 'olá mundo']);
    check(str_contains($with, 'olá mundo'), 'helper imprime texto salvo');
    $without = audio_transcription_html(['id' => 7, 'transcription' => null]);
    check(str_contains($without, 'data-transcribe-id="7"'), 'helper imprime botão quando sem texto');
}

// Task 6 — erros: sem chave → 501 (service lança 'Transcrição desabilitada.');
// não-áudio → 422; >25MB → 422
try {
    (new \App\Services\Transcription\OpenAiWhisperProvider(null))->transcribe(__FILE__, 'pt');
    check(false, 'provider sem chave lança');
} catch (\RuntimeException $e) {
    check($e->getMessage() === 'Transcrição desabilitada.', 'sem chave → Transcrição desabilitada (501)');
}
if (isset($conv) && $conv) {
    $db->beginTransaction();
    try {
        $tid = (int) $db->insert('messages', [
            'conversation_id' => $conv['id'],
            'type' => 'text',
            'content' => 'só texto',
            'direction' => 'inbound',
        ]);
        try {
            \App\Services\TranscriptionService::transcribeMessage($tid, 1, $fake ?? new class implements \App\Services\Transcription\TranscriptionProviderInterface {
                public function transcribe(string $f, string $l): string { return 'x'; }
            });
            check(false, 'texto não transcreve');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'Mensagem não é áudio.', 'não-áudio → Mensagem não é áudio (422)');
        }
    } finally {
        $db->rollBack();
    }
}

// Fix review — allowlist + extensão do tmp remoto
check(\App\Services\TranscriptionService::isTranscribableMime('audio/ogg'), 'mime audio/ogg ok');
check(\App\Services\TranscriptionService::isTranscribableMime('video/webm'), 'mime video/webm ok (WebChat)');
check(\App\Services\TranscriptionService::isTranscribableMime('audio/webm'), 'mime audio/webm ok');
check(\App\Services\TranscriptionService::isTranscribableMime('video/ogg'), 'mime video/ogg ok');
check(!\App\Services\TranscriptionService::isTranscribableMime('text/plain'), 'mime text/plain rejeitado');
check(!\App\Services\TranscriptionService::isTranscribableMime('application/pdf'), 'mime pdf rejeitado');
check(\App\Services\TranscriptionService::extensionForAudio('https://x/audio.ogg', '') === '.ogg', 'ext preserva .ogg da URL');
check(\App\Services\TranscriptionService::extensionForAudio('https://x/file?x=1', 'audio/mpeg') === '.mp3', 'ext via mime audio/mpeg');
check(\App\Services\TranscriptionService::extensionForAudio('https://x/file', 'video/webm') === '.webm', 'ext via mime video/webm');

// Chave via config (DB settings tem prioridade; .env como fallback; nunca reexibida)
check(method_exists(\App\Services\TranscriptionService::class, 'apiKey'), 'TranscriptionService::apiKey existe');
if (method_exists(\App\Services\TranscriptionService::class, 'apiKey')) {
    $prev = \App\Models\Setting::get('openai_api_key', '');
    \App\Models\Setting::set('openai_api_key', 'sk-test-config');
    check(\App\Services\TranscriptionService::apiKey() === 'sk-test-config', 'apiKey lê do config (DB)');
    \App\Models\Setting::set('openai_api_key', $prev);
    check(\App\Services\TranscriptionService::apiKey() === (env('OPENAI_API_KEY') ?: null), 'apiKey volta p/ .env sem config');
}

// Task 5 — PDFs exibem transcrição abaixo de "Áudio"
$pdfInbox = file_get_contents(__DIR__ . '/../../app/Views/inbox/pdf.php');
$pdfFull = file_get_contents(__DIR__ . '/../../app/Views/contacts/pdf_full.php');
$pdfTimeline = file_get_contents(__DIR__ . '/../../app/Views/reports/timeline_pdf.php');
check(str_contains($pdfInbox, 'transcription'), 'inbox/pdf exibe transcrição');
check(str_contains($pdfFull, 'transcription'), 'contacts/pdf_full exibe transcrição');
check(str_contains($pdfTimeline, 'transcription'), 'timeline_pdf exibe transcrição');

exit($ok ? 0 : 1);
