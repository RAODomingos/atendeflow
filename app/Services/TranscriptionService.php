<?php

namespace App\Services;

use App\Core\Database;
use App\Services\Transcription\OpenAiWhisperProvider;
use App\Services\Transcription\TranscriptionProviderInterface;

/**
 * Orquestra a transcrição sob demanda de mensagens de áudio.
 * Cache 1:1 em messages.transcription — 2ª chamada não custa chamada à API.
 */
class TranscriptionService
{
    /**
     * @return array{text:string,cached:bool}
     * @throws \RuntimeException mensagem sem PII (mapeada p/ HTTP no controller)
     */
    public static function transcribeMessage(int $messageId, int $userId, ?TranscriptionProviderInterface $provider = null): array
    {
        $db = Database::getInstance();
        $msg = $db->fetch(
            "SELECT id, type, content, transcription FROM messages WHERE id = ?",
            [$messageId]
        );
        if (!$msg) {
            throw new \RuntimeException('Mensagem não encontrada.');
        }
        if (!empty($msg['transcription'])) {
            return ['text' => (string) $msg['transcription'], 'cached' => true];
        }

        $isAudio = ($msg['type'] ?? '') === 'audio'
            || media_type_from_content((string) ($msg['content'] ?? '')) === 'audio';
        if (!$isAudio) {
            throw new \RuntimeException('Mensagem não é áudio.');
        }

        $provider ??= new OpenAiWhisperProvider(
            env('OPENAI_API_KEY') ?: null,
            (string) (env('TRANSCRIPTION_MODEL', 'whisper-1') ?: 'whisper-1')
        );
        $lang = (string) (env('TRANSCRIPTION_LANG', 'pt') ?: 'pt');
        $maxMb = (int) (env('TRANSCRIPTION_MAX_MB', 25) ?: 25);

        [$localPath, $isTmp] = self::resolveLocalAudio((int) $msg['id'], (string) ($msg['content'] ?? ''));
        try {
            $size = is_file($localPath) ? filesize($localPath) : false;
            if ($size === false || $size <= 0) {
                throw new \RuntimeException('Arquivo de áudio não encontrado.');
            }
            if ($size > $maxMb * 1024 * 1024) {
                throw new \RuntimeException('Áudio muito longo (limite ' . $maxMb . 'MB).');
            }
            $mime = (string) @mime_content_type($localPath);
            $mime = strtolower(trim(explode(';', $mime)[0]));
            if ($mime !== '' && !str_starts_with($mime, 'audio/') && !in_array($mime, ['video/ogg', 'application/ogg'], true)) {
                throw new \RuntimeException('Arquivo de áudio inválido.');
            }

            $text = $provider->transcribe($localPath, $lang);
            if (trim($text) === '') {
                throw new \RuntimeException('Falha na transcrição.');
            }
            $db->update(
                'messages',
                ['transcription' => $text, 'transcribed_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$messageId]
            );
            return ['text' => $text, 'cached' => false];
        } finally {
            if ($isTmp && is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }

    /**
     * Resolve o áudio da mensagem para um caminho local.
     * @return array{0:string,1:bool} [caminho, é tmp (apagar após uso)]
     * @throws \RuntimeException
     */
    private static function resolveLocalAudio(int $messageId, string $content): array
    {
        $db = Database::getInstance();

        $att = $db->fetch(
            "SELECT file_path FROM message_attachments WHERE message_id = ? ORDER BY id DESC LIMIT 1",
            [$messageId]
        );
        if ($att && !empty($att['file_path'])) {
            $p = (string) $att['file_path'];
            if (is_file($p)) {
                return [$p, false];
            }
            $local = upload_dir() . '/' . ltrim($p, '/');
            if (is_file($local)) {
                return [$local, false];
            }
        }

        $meta = message_file_meta($content);
        $url = $meta['url'] ?? '';
        if ($url === '') {
            throw new \RuntimeException('Arquivo de áudio não encontrado.');
        }

        // URL absoluta do nosso próprio uploads/ → caminho local
        // (message_file_meta() pode duplicar o prefixo: uploads/uploads/x — normaliza)
        $ownBase = base_url('uploads/');
        if (str_starts_with($url, $ownBase)) {
            $rel = ltrim(substr($url, strlen($ownBase)), '/');
            $rel = preg_replace('#^uploads/#', '', $rel) ?? $rel;
            $local = upload_dir() . '/' . $rel;
            if (is_file($local)) {
                return [$local, false];
            }
        }
        // Caminho relativo (uploads/...) → local
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $rel = preg_replace('#^.*/uploads/#', '', $url) ?? $url;
            $local = upload_dir() . '/' . ltrim($rel, '/');
            if (is_file($local)) {
                return [$local, false];
            }
            $direct = dirname(__DIR__, 2) . '/' . ltrim($url, '/');
            if (is_file($direct)) {
                return [$direct, false];
            }
        }

        // URL remota (provedor WhatsApp): baixa p/ tmp com timeout 30s
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $tmp = sys_get_temp_dir() . '/transc_' . bin2hex(random_bytes(8));
            $ch = curl_init($url);
            $fh = fopen($tmp, 'wb');
            if ($fh === false) {
                throw new \RuntimeException('Arquivo de áudio não encontrado.');
            }
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);
            $ok = curl_exec($ch);
            curl_close($ch);
            fclose($fh);
            if ($ok === false || !is_file($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                throw new \RuntimeException('Arquivo de áudio não encontrado.');
            }
            return [$tmp, true];
        }

        throw new \RuntimeException('Arquivo de áudio não encontrado.');
    }
}
