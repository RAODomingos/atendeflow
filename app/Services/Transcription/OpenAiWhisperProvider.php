<?php

namespace App\Services\Transcription;

/**
 * Transcrição via OpenAI Whisper API (model=whisper-1).
 * Limite rígido da API: 25MB por request (sem compressão/chunking nesta versão).
 */
class OpenAiWhisperProvider implements TranscriptionProviderInterface
{
    public function __construct(
        private ?string $apiKey,
        private string $model = 'whisper-1'
    ) {}

    public function transcribe(string $filePath, string $lang): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Transcrição desabilitada.');
        }
        if (!is_file($filePath)) {
            throw new \RuntimeException('Arquivo de áudio não encontrado.');
        }

        $mime = (string) @mime_content_type($filePath);
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if ($mime === '') {
            $mime = 'audio/ogg';
        }
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($filePath, $mime, basename($filePath)),
                'model' => $this->model,
                'language' => $lang,
                'response_format' => 'json',
            ],
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            error_log('transcription whisper: falha de rede');
            throw new \RuntimeException('Falha de rede na transcrição.');
        }
        $data = json_decode((string) $raw, true) ?: [];
        if ($http !== 200 || empty($data['text'])) {
            // Log sem PII: só código + mensagem da API (sem o texto transcrito).
            $apiErr = mb_substr((string) ($data['error']['message'] ?? ''), 0, 200);
            error_log('transcription whisper: http=' . $http . ($apiErr !== '' ? ' err=' . $apiErr : ''));
            throw new \RuntimeException('Falha na transcrição.');
        }
        return trim((string) $data['text']);
    }
}
