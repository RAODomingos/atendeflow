<?php

namespace App\Services\WhatsApp;

class WahaProvider implements WhatsAppProviderInterface
{
    private array $config;
    private WhatsAppHttpClient $client;

    public function __construct()
    {
        $this->config = $this->loadConfig();
        $this->client = new WhatsAppHttpClient(
            $this->config['base_url'] ?? 'http://localhost:3000',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json']
        );
    }

    public function getName(): string
    {
        return 'waha';
    }

    public function initConnection(array $config): array
    {
        $sessionName = $config['instance_name'] ?? ('atendeflow_' . bin2hex(random_bytes(4)));

        try {
            $check = $this->client->get("/api/sessions/{$sessionName}", $this->authHeaders());
            if (($check['status'] ?? 0) === 200) {
                $body = $check['body'] ?? [];
                return [
                    'provider_id' => $body['name'] ?? $sessionName,
                    'instance_id' => $body['name'] ?? null,
                    'token' => null,
                    'secret' => null,
                    'extra' => ['response' => $body],
                ];
            }
        } catch (\Throwable $e) {
        }

        $resp = $this->client->post('/api/sessions', [
            'name' => $sessionName,
            'start' => false,
        ], $this->authHeaders());
        $this->guard($resp);

        $body = $resp['body'] ?? [];

        return [
            'provider_id' => $body['name'] ?? $sessionName,
            'instance_id' => $body['name'] ?? null,
            'token' => null,
            'secret' => null,
            'extra' => ['response' => $body],
        ];
    }

    public function connect(array $connection): array
    {
        $session = $connection['instance_name'] ?? '';

        try {
            $this->client->post("/api/sessions/{$session}/start", null, $this->authHeaders());
        } catch (\Throwable $e) {
        }

        $resp = $this->client->post("/api/{$session}/auth/qr", null, $this->authHeaders());
        $this->guard($resp);

        $body = $resp['body'] ?? [];
        $qr = $this->extractQr($body);

        return [
            'qr_code' => $qr,
            'status' => 'waiting_qr',
        ];
    }

    public function getStatus(array $connection): array
    {
        $session = $connection['instance_name'] ?? '';

        $resp = $this->client->get("/api/sessions/{$session}", $this->authHeaders());
        $this->guard($resp);

        $body = $resp['body'] ?? [];
        $status = $this->normalizeStatus($body);

        $phone = null;
        if (!empty($body['me']['id'])) {
            $phone = $this->normalizePhone($body['me']['id']);
        }

        return [
            'status' => $status,
            'phone_number' => $phone,
            'instance_id' => $body['name'] ?? null,
            'qr_code' => null,
        ];
    }

    public function disconnect(array $connection): void
    {
        $session = $connection['instance_name'] ?? '';
        try {
            $this->client->post("/api/sessions/{$session}/logout", null, $this->authHeaders());
        } catch (\Throwable $e) {
        }
        try {
            $this->client->post("/api/sessions/{$session}/stop", null, $this->authHeaders());
        } catch (\Throwable $e) {
        }
    }

    public function deleteConnection(array $connection): void
    {
        $session = $connection['instance_name'] ?? '';
        try {
            $this->client->delete("/api/sessions/{$session}", null, $this->authHeaders());
        } catch (\Throwable $e) {
        }
    }

    public function setWebhook(array $connection, string $url): void
    {
        $session = $connection['instance_name'] ?? '';

        try {
            $this->client->post("/api/sessions/{$session}", [
                'name' => $session,
                'config' => [
                    'webhooks' => [
                        [
                            'url' => $url,
                            'events' => ['message', 'session.status'],
                        ],
                    ],
                ],
            ], $this->authHeaders());
        } catch (\Throwable $e) {
            error_log('WAHA setWebhook error: ' . $e->getMessage());
        }
    }

    public function send(array $connection, string $to, string $type, string $content, array $options = []): array
    {
        $session = $connection['instance_name'] ?? '';
        $chatId = $this->normalizePhone($to) . '@c.us';
        $headers = $this->authHeaders();

        if ($type === 'text') {
            $resp = $this->client->post('/api/sendText', [
                'session' => $session,
                'chatId' => $chatId,
                'text' => $content,
            ], $headers);

            return [
                'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
                'raw' => $resp['body'],
            ];
        }

        $meta = json_decode($content, true) ?: [];
        $mediaRef = $meta['url'] ?? $content;
        $mime = $options['mimetype'] ?? ($meta['mime'] ?? null);
        $caption = $options['caption'] ?? ($meta['name'] ?? '');

        $filePayload = [];
        if (!preg_match('#^https?://#', (string) $mediaRef)) {
            $absPath = upload_dir() . '/' . ltrim((string) $mediaRef, '/');
            if (is_file($absPath)) {
                $mime = $mime ?: $this->guessMime((string) $mediaRef);
                $filePayload = [
                    'data' => base64_encode((string) file_get_contents($absPath)),
                    'mimetype' => $mime,
                    'filename' => basename((string) $mediaRef),
                ];
            }
        }
        if (empty($filePayload)) {
            $filePayload = [
                'url' => $this->absoluteUrl((string) $mediaRef),
                'mimetype' => $mime ?: 'application/octet-stream',
                'filename' => basename((string) $mediaRef),
            ];
        }

        $body = [
            'session' => $session,
            'chatId' => $chatId,
            'file' => $filePayload,
        ];
        if ($caption !== '') {
            $body['caption'] = $caption;
        }

        $resp = $this->client->post($this->wahaSendEndpoint($type), $body, $headers);

        return [
            'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
            'raw' => $resp['body'],
        ];
    }

    public function downloadMedia(array $connection, string $messageId): ?array
    {
        if ($messageId === '') {
            return null;
        }

        try {
            $resp = $this->client->get('/api/files/' . $messageId, $this->authHeaders());

            $body = $resp['body'];

            if (is_array($body)) {
                $url = $body['url'] ?? null;
                if ($url) {
                    return [
                        'fileURL' => $url,
                        'mime' => $body['mimetype'] ?? null,
                        'name' => $body['filename'] ?? null,
                    ];
                }
                return null;
            }

            if (is_string($body) && $body !== '') {
                return [
                    'base64' => base64_encode($body),
                    'mime' => null,
                    'name' => null,
                ];
            }
        } catch (\Throwable $e) {
            error_log('WAHA downloadMedia error: ' . $e->getMessage());
        }

        return null;
    }

    public function parseWebhook(array $payload): ?IncomingMessage
    {
        $event = $payload['event'] ?? '';
        $session = $payload['session'] ?? '';
        $msg = $payload['payload'] ?? [];

        if (!in_array($event, ['message', 'message.any'], true)) {
            return null;
        }

        if (!empty($msg['fromMe'])) {
            return null;
        }

        $from = $msg['from'] ?? '';
        if (str_ends_with($from, '@g.us')) {
            return null;
        }

        $messageId = $msg['id'] ?? '';
        $timestamp = isset($msg['timestamp']) ? (int) $msg['timestamp'] : null;

        $fromPhone = $this->normalizePhone($from);
        if (!$fromPhone) {
            return null;
        }

        $senderName = $msg['pushName'] ?? ($payload['me']['pushName'] ?? null);

        $hasMedia = !empty($msg['hasMedia']);
        $media = $msg['media'] ?? [];

        if ($hasMedia && !empty($media)) {
            $mediaUrl = $media['url'] ?? '';
            $mediaMime = $media['mimetype'] ?? '';
            $caption = $msg['body'] ?? '';
            $type = $this->inferTypeFromMime($mediaMime) ?? 'file';

            return IncomingMessage::media(
                $session,
                $messageId,
                $fromPhone,
                $type,
                $mediaUrl,
                $mediaMime,
                $caption,
                $timestamp,
                false,
                $senderName
            );
        }

        $body = $msg['body'] ?? '';

        return IncomingMessage::text(
            $session,
            $messageId,
            $fromPhone,
            $body,
            $timestamp,
            false,
            $senderName
        );
    }

    public function editMessage(array $connection, string $messageId, string $text): bool
    {
        if ($messageId === '') {
            return false;
        }

        $session = $connection['instance_name'] ?? '';
        $chatId = $this->chatIdFromMessageId($messageId);
        if (!$chatId) {
            return false;
        }

        try {
            $resp = $this->client->put(
                "/api/{$session}/chats/{$chatId}/messages/{$messageId}",
                ['text' => $text],
                $this->authHeaders()
            );
            $this->guard($resp);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function deleteMessage(array $connection, string $messageId): bool
    {
        if ($messageId === '') {
            return false;
        }

        $session = $connection['instance_name'] ?? '';
        $chatId = $this->chatIdFromMessageId($messageId);
        if (!$chatId) {
            return false;
        }

        try {
            $resp = $this->client->delete(
                "/api/{$session}/chats/{$chatId}/messages/{$messageId}",
                null,
                $this->authHeaders()
            );
            $this->guard($resp);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function chatIdFromMessageId(string $messageId): ?string
    {
        $parts = explode('_', $messageId);
        return $parts[1] ?? null;
    }

    private function authHeaders(): array
    {
        $apiKey = $this->config['api_key'] ?? '';
        return $apiKey ? ['X-Api-Key' => $apiKey] : [];
    }

    private function guard(array $resp): void
    {
        $status = (int) ($resp['status'] ?? 200);
        $body = $resp['body'] ?? [];
        $message = is_array($body) ? strtolower((string) ($body['message'] ?? ($body['error'] ?? ''))) : '';

        if ($status === 401 || $status === 403) {
            throw new WhatsAppAuthException('WAHA API Key inválida ou não autorizada.');
        }
    }

    private function extractQr(?array $body): ?string
    {
        if (!$body) {
            return null;
        }
        $raw = $body['qr'] ?? $body['data'] ?? ($body['base64'] ?? null);
        if (!$raw) {
            return null;
        }
        if (is_string($raw)) {
            if (str_starts_with($raw, 'data:image')) {
                return $raw;
            }
            if (str_starts_with($raw, 'data:')) {
                return $raw;
            }
            return 'data:image/png;base64,' . $raw;
        }
        if (is_array($raw) && !empty($raw['base64'])) {
            return 'data:image/png;base64,' . $raw['base64'];
        }
        return null;
    }

    private function extractMessageId($body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        return $body['id'] ?? $body['messageId'] ?? $body['key']['id'] ?? null;
    }

    private function normalizeStatus(array $body): string
    {
        $status = strtoupper((string) ($body['status'] ?? ''));
        return match ($status) {
            'WORKING' => 'connected',
            'STARTING', 'STOPPED' => 'waiting_qr',
            'FAILED' => 'error',
            default => 'disconnected',
        };
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }
        $phone = preg_replace('/@c\.us$|@s\.whatsapp\.net$|@lid$/', '', $phone);
        $digits = preg_replace('/\D/', '', $phone);
        return $digits === '' ? null : $digits;
    }

    private function wahaSendEndpoint(string $type): string
    {
        return match ($type) {
            'image' => '/api/sendImage',
            'audio' => '/api/sendVoice',
            'video' => '/api/sendVideo',
            'file', 'document' => '/api/sendFile',
            default => '/api/sendText',
        };
    }

    private function guessMime(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#', $url)) {
            return $url;
        }
        return base_url(ltrim($url, '/'));
    }

    private function inferTypeFromMime(string $mime): ?string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'application/') || str_starts_with($mime, 'text/')) {
            return 'file';
        }
        return null;
    }

    private function loadConfig(): array
    {
        $integrations = require dirname(__DIR__, 3) . '/config/integrations.php';
        return $integrations['whatsapp']['providers']['waha'] ?? [];
    }
}
