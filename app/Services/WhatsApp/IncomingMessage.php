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
     * Extrai nome/telefone de um vCard (linhas FN: e TEL:). Retorna
     * ['name'=>?string, 'phone'=>?string] (null quando ausente).
     */
    public static function parseVcard(string $vcard): array
    {
        $name = null;
        $phone = null;
        foreach (preg_split('/\r?\n/', $vcard) as $line) {
            if ($name === null && preg_match('/^(?:FN|N)[;:]/i', $line)) {
                $v = trim(substr($line, strpos($line, ':') + 1));
                if ($v !== '') {
                    $name = $v;
                }
            }
            if ($phone === null && preg_match('/^TEL[^:]*:(.+)$/i', $line, $m)) {
                $digits = preg_replace('/\D/', '', $m[1]);
                if ($digits !== '') {
                    $phone = $digits;
                }
            }
        }
        return ['name' => $name, 'phone' => $phone];
    }
}
