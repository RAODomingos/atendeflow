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

        $resp = $this->client->get("/api/{$session}/auth/qr", $this->authHeaders());
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

        $qr = null;
        if ($this->needsQr($status)) {
            try {
                $qrResp = $this->client->get("/api/{$session}/auth/qr", $this->authHeaders());
                $qrBody = $qrResp['body'] ?? null;
                $qr = $this->extractQr($qrBody);
            } catch (\Throwable $e) {
            }
        }

        return [
            'status' => $status,
            'phone_number' => $phone,
            'instance_id' => $body['name'] ?? null,
            'qr_code' => $qr,
        ];
    }

    private function needsQr(string $status): bool
    {
        return in_array($status, ['waiting_qr', 'disconnected'], true);
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
            $this->client->put("/api/sessions/{$session}", [
                'name' => $session,
                'config' => [
                    'webhooks' => [
                        [
                            'url' => $url,
                            'events' => ['message', 'message.reaction', 'message.edited', 'message.revoked', 'session.status'],
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
        $phone = $this->normalizePhone($to);
        $headers = $this->authHeaders();

        $sendWithSuffix = function (string $suffix) use ($session, $phone, $type, $content, $options, $headers) {
            $chatId = $phone . $suffix;

            if ($type === 'text') {
                $resp = $this->client->post('/api/sendText', [
                    'session' => $session,
                    'chatId' => $chatId,
                    'text' => $content,
                ], $headers);
                return $resp;
            }

            $meta = json_decode($content, true) ?: [];
            $mediaRef = $meta['url'] ?? $content;
            $mime = $options['mimetype'] ?? ($meta['mime'] ?? null);
            $caption = $options['caption'] ?? ($meta['name'] ?? '');
            if (!empty($options['caption'])) {
                $caption = $options['caption'];
            }

            $filePayload = [];
            $isLocalPath = !preg_match('#^https?://#', (string) $mediaRef);
            if ($isLocalPath) {
                $absPath = upload_dir() . '/' . ltrim((string) $mediaRef, '/');
                $mime = $mime ?: $this->guessMime((string) $mediaRef);

                // For video (large files), always use URL — base64 would exceed WAHA payload limits
                if ($type === 'video' || (is_file($absPath) && filesize($absPath) > 2 * 1024 * 1024)) {
                    $filePayload = [
                        'url' => $this->absoluteUrl((string) $mediaRef),
                        'mimetype' => $mime ?: 'video/mp4',
                        'filename' => basename((string) $mediaRef),
                    ];
                } elseif (is_file($absPath)) {
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

            // WAHA CORE runs on Docker; replace localhost with host.docker.internal
            $base = rtrim($this->config['base_url'] ?? 'http://localhost:3000', '/');
            if (str_contains($base, '//localhost') && !empty($filePayload['url'])) {
                $filePayload['url'] = str_replace('//localhost/', '//host.docker.internal/', $filePayload['url']);
            }

            $body = [
                'session' => $session,
                'chatId' => $chatId,
                'file' => $filePayload,
            ];
            if ($caption !== '') {
                $body['caption'] = $caption;
            }

            // Try the native endpoint first
            $endpoint = $this->wahaSendEndpoint($type);
            $resp = $this->client->post($endpoint, $body, $headers);

            // Video fallback: WAHA CORE (Chromium) lacks H.264/AAC codecs, so
            // sendVideo returns 422. Retry with convert=true, then sendFile.
            if ($type === 'video' && ($resp['status'] ?? 0) !== 200) {
                $errMsg = '';
                $errBody = $resp['body'] ?? [];
                if (is_array($errBody)) {
                    $errMsg = (string) ($errBody['message'] ?? $errBody['error'] ?? json_encode($errBody));
                } else {
                    $errMsg = (string) $errBody;
                }
                error_log("WAHA sendVideo failed (status={$resp['status']}): {$errMsg}");

                // Try with convert=true (requires Chrome image but worth a shot)
                $body['convert'] = true;
                $resp = $this->client->post($endpoint, $body, $headers);
                if (($resp['status'] ?? 0) === 200) {
                    return $resp;
                }

                // Final fallback: send as file
                error_log('WAHA sendVideo failed, falling back to sendFile');
                unset($body['convert']);
                $resp = $this->client->post('/api/sendFile', $body, $headers);
            }
            return $resp;
        };

        // Try @c.us first; fall back to @lid if WAHA returns "No LID for user"
        $resp = $sendWithSuffix('@c.us');
        if (($resp['status'] ?? 0) === 500) {
            $body = $resp['body'] ?? [];
            $errMsg = is_array($body) ? (string) ($body['exception']['message'] ?? '') : '';
            if (str_contains($errMsg, 'No LID for user')) {
                $resp = $sendWithSuffix('@lid');
            }
        }

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

        if (!in_array($event, ['message', 'message.reaction', 'message.edited', 'message.revoked'], true)) {
            return null;
        }

        // message.edited e message.revoked podem vir de mensagens próprias (fromMe=true)
        $isFromMe = !empty($msg['fromMe']);

        $from = $msg['from'] ?? ($msg['to'] ?? '');
        if (str_ends_with($from, '@g.us')) {
            return null;
        }

        $messageId = $msg['id'] ?? '';
        $timestamp = isset($msg['timestamp']) ? (int) $msg['timestamp'] : null;

        // Para edit/revoke, o from pode ser o ID da própria conta (fromMe),
        // então usamos 'to' para identificar o contato
        $effectiveFrom = $isFromMe ? ($msg['to'] ?? $from) : $from;
        $fromPhone = $this->normalizePhone($effectiveFrom);
        if (!$fromPhone) {
            return null;
        }

        // Nome do contato: WAHA 2026 envia em _data.notifyName
        $senderName = $msg['_data']['notifyName'] ?? $msg['pushName'] ?? ($payload['me']['pushName'] ?? null);

        // Preserva o ID original do remetente (com sufixo @lid/@c.us) para resolução de telefone
        $originalFrom = $from;

        // Reaction event
        if ($event === 'message.reaction') {
            $reaction = $msg['reaction']['text'] ?? $msg['reaction'] ?? $msg['text'] ?? '';
            $parentMsgId = $msg['reaction']['messageId'] ?? $msg['parentMessageId'] ?? $msg['key']['id'] ?? '';
            $content = json_encode([
                'reaction' => $reaction,
                'parent_message_id' => $parentMsgId,
            ], JSON_UNESCAPED_UNICODE);

            return IncomingMessage::text(
                $session,
                $messageId,
                $fromPhone,
                $content,
                $timestamp,
                false,
                $senderName,
                null,
                ['original_from' => $originalFrom, 'event_type' => $event]
            );
        }

        // Edit event: cliente editou a mensagem
        if ($event === 'message.edited') {
            $body = $msg['body'] ?? '';
            return IncomingMessage::text(
                $session,
                $messageId,
                $fromPhone,
                $body,
                $timestamp,
                false,
                $senderName,
                null,
                ['original_from' => $originalFrom, 'event_type' => $event]
            );
        }

        // Revoke event: cliente apagou a mensagem
        // WAHA envia o evento com o ID da mensagem original dentro de _data.protocolMessageKey
        if ($event === 'message.revoked') {
            // A mensagem original foi apagada; extraímos o ID original via protocolMessageKey
            $originalMsgId = $msg['_data']['protocolMessageKey']['_serialized'] ?? $messageId;
            return IncomingMessage::text(
                $session,
                $originalMsgId, // Usa o ID original para dar match no DB
                $fromPhone,
                '',
                $timestamp,
                $isFromMe,
                $senderName,
                null,
                ['original_from' => $originalFrom, 'event_type' => $event]
            );
        }

        // Pula mensagens enviadas pela própria API
        if ($isFromMe) {
            return null;
        }

        // Detect type from _data.type (WAHA 2026+ includes msg type in _data)
        $dataType = $msg['_data']['type'] ?? '';

        $hasMedia = !empty($msg['hasMedia']);
        $media = $msg['media'] ?? [];

        if ($hasMedia && !empty($media)) {
            $mediaUrl = $media['url'] ?? '';
            $mediaMime = $media['mimetype'] ?? '';
            $caption = $msg['body'] ?? '';

            // Sticker: detect by _data.type or by mime
            $type = ($dataType === 'sticker') ? 'sticker' : ($this->inferTypeFromMime($mediaMime) ?? 'file');

            // Extract WhatsApp CDN URL for fallback media download
            $cdnUrl = $msg['_data']['deprecatedMms3Url'] ?? '';

            $extra = ['original_from' => $originalFrom];
            if ($cdnUrl) {
                $extra['cdn_url'] = $cdnUrl;
            }
            if ($dataType === 'sticker') {
                $extra['is_animated'] = !empty($msg['_data']['isAnimated']);
                $extra['is_lottie'] = !empty($msg['_data']['isLottie']);
            }

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
                $senderName,
                null,
                $extra
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
            $senderName,
            null,
            ['original_from' => $originalFrom]
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

    public function resolvePhone(array $connection, string $contactId): ?string
    {
        if ($contactId === '' || !str_ends_with($contactId, '@lid')) {
            return null;
        }

        $session = $connection['instance_name'] ?? '';

        try {
            $resp = $this->client->get("/api/{$session}/contacts/{$contactId}", $this->authHeaders());
            if (($resp['status'] ?? 0) !== 200) {
                return null;
            }
            $body = $resp['body'] ?? [];
            $realId = $body['id'] ?? '';
            if ($realId === '' || !str_ends_with($realId, '@c.us')) {
                return null;
            }
            return $this->normalizePhone($realId);
        } catch (\Throwable $e) {
            error_log('WAHA resolvePhone error: ' . $e->getMessage());
            return null;
        }
    }

    public function sendReaction(array $connection, string $messageId, string $reaction): bool
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
            $resp = $this->client->put('/api/reaction', [
                'session' => $session,
                'chatId' => $chatId,
                'messageId' => $messageId,
                'reaction' => $reaction,
            ], $this->authHeaders());
            $this->guard($resp);
            return ($resp['status'] ?? 0) === 200;
        } catch (\Throwable $e) {
            error_log('WAHA sendReaction error: ' . $e->getMessage());
            return false;
        }
    }

    public function getProfilePicture(array $connection, string $contactId): ?string
    {
        $session = $connection['instance_name'] ?? '';
        try {
            $resp = $this->client->get("/api/{$session}/chats/{$contactId}/picture", $this->authHeaders());
            if (($resp['status'] ?? 0) !== 200) {
                return null;
            }
            $body = $resp['body'] ?? [];
            if (is_string($body)) {
                if (str_starts_with($body, 'http')) return $body;
                if (str_starts_with($body, 'data:')) return $body;
                return 'data:image/png;base64,' . base64_encode($body);
            }
            return $body['url'] ?? $body['data'] ?? null;
        } catch (\Throwable $e) {
            error_log('WAHA getProfilePicture error: ' . $e->getMessage());
            return null;
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

    private function extractQr(array|string|null $body): ?string
    {
        if (!$body) {
            return null;
        }

        // Raw binary PNG (WAHA 2026+ returns PNG directly)
        if (is_string($body) && str_starts_with($body, "\x89PNG")) {
            return 'data:image/png;base64,' . base64_encode($body);
        }

        if (!is_array($body)) {
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
        $id = $body['id'] ?? $body['messageId'] ?? $body['key']['id'] ?? null;
        if (is_array($id)) {
            return $id['_serialized'] ?? $id['id'] ?? null;
        }
        return $id;
    }

    private function normalizeStatus(array $body): string
    {
        $status = strtoupper((string) ($body['status'] ?? ''));
        return match ($status) {
            'WORKING' => 'connected',
            'STARTING', 'STOPPED', 'SCAN_QR_CODE' => 'waiting_qr',
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
            'sticker' => '/api/sendImage',
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
