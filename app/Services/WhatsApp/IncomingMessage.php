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
        public ?string $avatarUrl = null
    ) {
    }

    public static function text(string $providerId, string $messageId, string $from, string $body, ?int $timestamp = null, bool $fromMe = false, ?string $senderName = null, ?string $avatarUrl = null): self
    {
        return new self($providerId, $messageId, $from, 'text', $body, null, null, null, $timestamp, $fromMe, $senderName, $avatarUrl);
    }

    public static function media(string $providerId, string $messageId, string $from, string $type, string $mediaUrl, ?string $mediaMime = null, ?string $caption = null, ?int $timestamp = null, bool $fromMe = false, ?string $senderName = null, ?string $avatarUrl = null): self
    {
        return new self($providerId, $messageId, $from, $type, $caption ?? '', $mediaUrl, $mediaMime, $caption, $timestamp, $fromMe, $senderName, $avatarUrl);
    }
}
