<?php

namespace App\Services\Transcription;

/**
 * Contrato para provedores de transcrição de áudio.
 * Implementação atual: OpenAiWhisperProvider. Futuro: LocalWhisperProvider.
 */
interface TranscriptionProviderInterface
{
    /**
     * Transcreve um arquivo de áudio local.
     *
     * @param string $filePath caminho local do áudio (<= limite do provedor)
     * @param string $lang código ISO (ex.: 'pt')
     * @return string texto transcrito (sem PII além do conteúdo falado)
     * @throws \RuntimeException em falha de rede/API (mensagem sem PII)
     */
    public function transcribe(string $filePath, string $lang): string;
}
