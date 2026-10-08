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

        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($filePath),
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
            throw new \RuntimeException('Falha de rede na transcrição.');
        }
        $data = json_decode((string) $raw, true) ?: [];
        if ($http !== 200 || empty($data['text'])) {
            throw new \RuntimeException('Falha na transcrição.');
        }
        return trim((string) $data['text']);
    }
}
