<?php

namespace App\Services\WhatsApp;

/**
 * DTO que representa uma mensagem recebida de forma neutra, independente do provedor.
 */
class IncomingMessage
{
    public function __construct(
        public string $providerId,
        public string $messageId,
        public string $from,
        public string $type,
        public string $content,
        public ?string $mediaUrl = null,
        public ?string $mediaMime = null,
        public ?string $caption = null,
        public ?int $timestamp = null,
        public bool $fromMe = false,
        public ?string $senderName = null,
        public ?string $avatarUrl = null,
        public array $extra = []
    ) {
    }

    public static function text(string $providerId, string $messageId, string $from, string $body, ?int $timestamp = null, bool $fromMe = false, ?string $senderName = null, ?string $avatarUrl = null, array $extra = []): self
    {
        return new self($providerId, $messageId, $from, 'text', $body, null, null, null, $timestamp, $fromMe, $senderName, $avatarUrl, $extra);
    }

    public static function media(string $providerId, string $messageId, string $from, string $type, string $mediaUrl, ?string $mediaMime = null, ?string $caption = null, ?int $timestamp = null, bool $fromMe = false, ?string $senderName = null, ?string $avatarUrl = null, array $extra = []): self
    {
        return new self($providerId, $messageId, $from, $type, $caption ?? '', $mediaUrl, $mediaMime, $caption, $timestamp, $fromMe, $senderName, $avatarUrl, $extra);
    }

    /**
     * Cartão de contato (vCard): content é JSON {name, phone, organization?}.
     */
    public static function contact(string $providerId, string $messageId, string $from, array $card, ?int $timestamp = null, bool $fromMe = false, ?string $senderName = null, ?string $avatarUrl = null, array $extra = []): self
    {
        $content = json_encode([
            'name' => (string) ($card['name'] ?? ''),
            'phone' => preg_replace('/\D/', '', (string) ($card['phone'] ?? '')),
            'organization' => isset($card['organization']) && $card['organization'] !== '' ? (string) $card['organization'] : null,
        ], JSON_UNESCAPED_UNICODE);
        return new self($providerId, $messageId, $from, 'contact', $content, null, null, null, $timestamp, $fromMe, $senderName, $avatarUrl, $extra);
    }

    /**
     * Diz se um cartão pode ser compartilhado (nome resolvido + telefone
     * com ao menos 8 dígitos — mesmo corte dos providers). Usado pelo
     * inbox para 422 ANTES de criar a mensagem.
     */
    public static function isShareableContact(array $card): bool
    {
        $phone = preg_replace('/\D/', '', (string) ($card['phone'] ?? ''));
        return $phone !== '' && strlen($phone) >= 8;
    }

    /**
     * Gera o vCard 3.0 de um contato (usado no envio aos provedores).
     */
    public static function buildVcard(string $name, string $phone, ?string $organization = null): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'FN:' . trim($name)];
        if ($organization !== null && $organization !== '') {
            $lines[] = 'ORG:' . trim($organization);
        }
        $lines[] = 'TEL;TYPE=CELL:' . preg_replace('/\D/', '', $phone);
        $lines[] = 'END:VCARD';
        return implode("\n", $lines);
    }

    /**
     * Extrai nome/telefone de um vCard real do WhatsApp. Cobre:
     * - `FN:` (prioridade) e `N:Sobrenome;Nome;;;` (ignora `N:;;;;` vazio);
     * - `TEL:`, `TEL;TYPE=...:` e `item1.TEL;waid=DDI...:` (waid como fallback);
     * - linhas dobradas (folding: continuação com espaço inicial).
     * Retorna ['name'=>?string, 'phone'=>?string] (null quando ausente).
     */
    public static function parseVcard(string $vcard): array
    {
        $name = null;
        $structured = null;
        $phone = null;
        $waid = null;
        $prev = null;
        $lines = [];
        foreach (preg_split('/\r?\n/', $vcard) as $line) {
            if ($prev !== null && preg_match('/^[ \t]/', $line)) {
                $lines[count($lines) - 1] .= substr($line, 1);
                continue;
            }
            $prev = $line;
            $lines[] = $line;
        }
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $prop = substr($line, 0, $pos);
            $value = trim(substr($line, $pos + 1));
            $base = strtoupper((string) preg_replace('/^\w+\./', '', strtok($prop, ';')));
            if ($base === 'FN' && $name === null && $value !== '') {
                $name = $value;
            } elseif ($base === 'N' && $structured === null) {
                $parts = array_values(array_filter(array_map('trim', explode(';', $value)), fn($p) => $p !== ''));
                if ($parts !== []) {
                    // N:Sobrenome;Nome;... -> "Nome Sobrenome".
                    $structured = trim(($parts[1] ?? '') . ' ' . ($parts[0] ?? '')) !== ''
                        ? trim(($parts[1] ?? '') . ' ' . ($parts[0] ?? ''))
                        : implode(' ', $parts);
                }
            } elseif ($base === 'TEL') {
                if ($phone === null) {
                    $digits = preg_replace('/\D/', '', $value);
                    if ($digits !== '') {
                        $phone = $digits;
                    }
                }
                if ($waid === null && preg_match('/waid=(\d+)/i', $prop, $m)) {
                    $waid = $m[1];
                }
            }
        }
        if ($name === null) {
            $name = $structured;
        }
        if (($phone === null || strlen((string) $phone) < 8) && $waid !== null) {
            $phone = $waid;
        }
        return ['name' => $name, 'phone' => $phone];
    }
}
