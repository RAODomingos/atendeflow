<?php

namespace App\Services\WhatsApp;

class UazapiProvider implements WhatsAppProviderInterface
{
    private array $config;
    private WhatsAppHttpClient $client;

    public function __construct()
    {
        $this->config = $this->loadConfig();
        $this->client = new WhatsAppHttpClient(
            $this->config['base_url'] ?? 'https://free.uazapi.com',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json']
        );
    }

    public function getName(): string
    {
        return 'uazapi';
    }

    public function initConnection(array $config): array
    {
        $instanceName = $config['instance_name'] ?? ('atendeflow_' . bin2hex(random_bytes(4)));
        $headers = $this->adminAuthHeaders();
        error_log('Uazapi initConnection: admintoken=' . ($headers['admintoken'] ?? ($headers['token'] ?? 'NONE')));

        try {
            $resp = $this->client->post('/instance/create', [
                'Name' => $instanceName,
            ], $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi initConnection response: ' . json_encode($body, JSON_UNESCAPED_UNICODE));

            $instance = $body['instance'] ?? $body;
            $returnedName = $instance['name'] ?? $instance['instanceName'] ?? $instanceName;
            $returnedToken = $instance['token'] ?? $body['token'] ?? null;

            // Se o create não retornou token, busca na lista de instâncias
            if (!$returnedToken) {
                error_log('Uazapi initConnection: token not in create response, trying fetchInstances');
                $returnedToken = $this->fetchInstanceToken($returnedName);
                error_log('Uazapi initConnection: fetchInstances returned token=' . ($returnedToken ? substr($returnedToken, 0, 10) . '...' : 'NULL'));
            }

            return [
                'provider_id' => $returnedName,
                'instance_id' => $returnedName,
                'token' => $returnedToken,
                'secret' => null,
                'extra' => ['response' => $body],
            ];
        } catch (\Throwable $e) {
            error_log('Uazapi initConnection exception: ' . $e->getMessage());
            throw new \RuntimeException('Uazapi initConnection falhou: ' . $e->getMessage());
        }
    }

    private function fetchInstanceToken(string $instanceName): ?string
    {
        try {
            $headers = $this->adminAuthHeaders();
            $resp = $this->client->get('/instance/fetchInstances', $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi fetchInstances response: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            if (!is_array($body)) {
                return null;
            }
            foreach ($body as $inst) {
                if (($inst['name'] ?? '') === $instanceName) {
                    return $inst['token'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            error_log('Uazapi fetchInstances exception: ' . $e->getMessage());
        }
        return null;
    }



    public function connect(array $connection): array
    {
        $headers = $this->instanceAuthHeaders($connection);
        error_log('Uazapi connect: token=' . ($headers['token'] ?? ($headers['admintoken'] ?? 'NONE')) . ' instance_name=' . ($connection['instance_name'] ?? 'UNKNOWN'));

        try {
            $resp = $this->client->post('/instance/connect', null, $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi connect response: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            $this->guard($body);

            $qr = $this->extractQr($body);

            if (!$qr) {
                error_log('Uazapi connect: QR code not found in response: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            }

            return [
                'qr_code' => $qr,
                'status' => 'waiting_qr',
            ];
        } catch (WhatsAppAuthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('Uazapi connect exception: ' . $e->getMessage());
            return [
                'qr_code' => null,
                'status' => 'error',
            ];
        }
    }

    public function getStatus(array $connection): array
    {
        $headers = $this->instanceAuthHeaders($connection);

        try {
            $resp = $this->client->get('/instance/status', $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi status response: ' . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->guard($body);

            $status = $this->normalizeStatus($body);

            $phone = null;
            if (!empty($connection['phone_number'])) {
                $phone = preg_replace('/\D/', '', (string) $connection['phone_number']);
            }
            if (!$phone) {
                $instanceInfo = $body['instance'] ?? $body;
                $owner = $instanceInfo['owner'] ?? $body['owner'] ?? null;
                if ($owner) {
                    $phone = preg_replace('/\D/', '', (string) $owner);
                }
            }

            $qr = null;
            if ($this->needsQr($status)) {
                $qr = $this->extractQr($body);
            }

            $instanceInfo = $body['instance'] ?? $body;
            $instanceId = $instanceInfo['name'] ?? $instanceInfo['instanceName'] ?? null;

            return [
                'status' => $status,
                'phone_number' => $phone,
                'instance_id' => $instanceId,
                'qr_code' => $qr,
            ];
        } catch (WhatsAppAuthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return [
                'status' => 'disconnected',
                'phone_number' => null,
                'instance_id' => null,
                'qr_code' => null,
            ];
        }
    }

    public function disconnect(array $connection): void
    {
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $this->client->post('/instance/disconnect', null, $headers);
        } catch (\Throwable $e) {
        }
    }

    public function deleteConnection(array $connection): void
    {
        $name = $connection['instance_name'] ?? '';
        if (!$name) {
            return;
        }
        $headers = $this->adminAuthHeaders();
        try {
            $this->client->delete('/instance/delete/' . urlencode($name), null, $headers);
        } catch (\Throwable $e) {
        }
    }

    public function setWebhook(array $connection, string $url): void
    {
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/webhook', [
                'url' => $url,
                'enabled' => true,
                'addUrlEvents' => true,
                'addUrlTypesMessages' => true,
                'events' => ['messages', 'connection', 'qrcode'],
            ], $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi setWebhook response: ' . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            error_log('Uazapi setWebhook error: ' . $e->getMessage());
        }
    }

    public function send(array $connection, string $to, string $type, string $content, array $options = []): array
    {
        $phone = preg_replace('/\D/', '', $to);
        if (!$phone) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $headers = $this->instanceAuthHeaders($connection);

        if ($type === 'text') {
            $resp = $this->client->post('/send/text', [
                'number' => $phone,
                'text' => $content,
                'delay' => 1000,
            ], $headers);
            $this->guard($resp['body'] ?? []);
            return [
                'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
                'raw' => $resp['body'],
            ];
        }

        $meta = json_decode($content, true) ?: [];
        $mediaRef = $meta['url'] ?? $content;
        $caption = $options['caption'] ?? ($meta['name'] ?? '');
        $name = $meta['name'] ?? '';

        $file = $this->absoluteUrl((string) $mediaRef);
        $uazapiType = $this->uazapiMediaType($type);

        $body = [
            'number' => $phone,
            'type' => $uazapiType,
            'file' => $file,
            'delay' => 1000,
        ];
        if ($caption !== '') {
            $body['text'] = $caption;
        }
        if ($uazapiType === 'document' && $name !== '') {
            $body['docName'] = $name;
        }

        $resp = $this->client->post('/send/media', $body, $headers);

        if (($resp['status'] ?? 0) >= 400) {
            error_log("Uazapi send error (type={$type}, status={$resp['status']}): " . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
        }

        return [
            'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
            'raw' => $resp['body'],
        ];
    }

    public function sendButton(array $connection, string $to, string $text, array $buttons): array
    {
        $phone = preg_replace('/\D/', '', $to);
        if (!$phone) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $headers = $this->instanceAuthHeaders($connection);

        $choices = [];
        foreach ($buttons as $b) {
            $id = $b['id'] ?? $b['label'] ?? '';
            $label = $b['label'] ?? '';
            $choices[] = ($label ?: 'Opção') . '|' . $id;
        }

        try {
            $resp = $this->client->post('/send/menu', [
                'number' => $phone,
                'type' => 'button',
                'text' => $text,
                'choices' => $choices,
                'delay' => 1000,
            ], $headers);

            if (($resp['status'] ?? 0) >= 400) {
                error_log("Uazapi sendButton error (status={$resp['status']}): " . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
            }

            return [
                'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
                'raw' => $resp['body'],
            ];
        } catch (\Throwable $e) {
            error_log('Uazapi sendButton exception: ' . $e->getMessage());
            return ['provider_message_id' => null, 'raw' => []];
        }
    }

    public function sendList(array $connection, string $to, string $text, string $title, array $items): array
    {
        $phone = preg_replace('/\D/', '', $to);
        if (!$phone) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $headers = $this->instanceAuthHeaders($connection);

        $choices = [];
        foreach ($items as $i => $item) {
            if (!empty($item['section_name'])) {
                $choices[] = '[' . $item['section_name'] . ']';
                $hasSection = true;
                if (!empty($item['rows'])) {
                    foreach ($item['rows'] as $row) {
                        $desc = $row['description'] ?? '';
                        $id = $row['id'] ?? '';
                        $label = $row['title'] ?? '';
                        $choices[] = $label . '|' . $id . ($desc ? '|' . $desc : '');
                    }
                }
            } else {
                $id = $item['id'] ?? $i;
                $label = $item['label'] ?? $item['title'] ?? '';
                $desc = $item['description'] ?? '';
                $choices[] = $label . '|' . $id . ($desc ? '|' . $desc : '');
            }
        }

        try {
            $resp = $this->client->post('/send/menu', [
                'number' => $phone,
                'type' => 'list',
                'text' => $text,
                'footerText' => $title ?: 'Opções',
                'listButton' => 'Ver opções',
                'choices' => $choices,
                'delay' => 1000,
            ], $headers);

            if (($resp['status'] ?? 0) >= 400) {
                error_log("Uazapi sendList error (status={$resp['status']}): " . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
            }

            return [
                'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
                'raw' => $resp['body'],
            ];
        } catch (\Throwable $e) {
            error_log('Uazapi sendList exception: ' . $e->getMessage());
            return ['provider_message_id' => null, 'raw' => []];
        }
    }

    public function downloadMedia(array $connection, string $messageId): ?array
    {
        if ($messageId === '') {
            return null;
        }

        $headers = $this->instanceAuthHeaders($connection);

        try {
            $resp = $this->client->post('/message/download', [
                'id' => $messageId,
                'return_base64' => true,
                'return_link' => false,
            ], $headers);

            $body = $resp['body'] ?? [];

            if (is_array($body)) {
                $base64 = $body['base64Data'] ?? $body['base64'] ?? null;
                if (is_string($base64) && $base64 !== '') {
                    return [
                        'base64' => $base64,
                        'mime' => $body['mimetype'] ?? $body['mime'] ?? null,
                        'name' => $body['filename'] ?? $body['name'] ?? null,
                    ];
                }

                $url = $body['fileURL'] ?? $body['url'] ?? $body['URL'] ?? null;
                if ($url) {
                    return [
                        'fileURL' => $url,
                        'mime' => $body['mimetype'] ?? $body['mime'] ?? null,
                        'name' => $body['filename'] ?? $body['name'] ?? null,
                    ];
                }
            }

            if (is_string($body) && $body !== '') {
                return [
                    'base64' => base64_encode($body),
                    'mime' => null,
                    'name' => null,
                ];
            }
        } catch (\Throwable $e) {
            error_log('Uazapi downloadMedia error: ' . $e->getMessage());
        }

        return null;
    }

    public function parseWebhook(array $payload): ?IncomingMessage
    {
        $event = $payload['EventType'] ?? $payload['event'] ?? '';
        $isMessageEvent = $event === 'messages' || $event === 'messages.upsert';
        if (!$isMessageEvent) {
            return null;
        }

        // Uazapi coloca os dados em data.message
        $data = $payload['data'] ?? [];
        $msg = $data['message'] ?? $payload['message'] ?? [];

        if (empty($msg)) {
            return null;
        }

        $isFromMe = !empty($msg['fromMe']) || !empty($data['key']['fromMe']);
        $isGroup = !empty($msg['isGroup']) || !empty($data['key']['remoteJid']) && str_contains($data['key']['remoteJid'], '@g.us');
        if ($isGroup) {
            return null;
        }
        if ($isFromMe) {
            return null;
        }

        $instanceName = $payload['instanceName'] ?? ($payload['instance']['name'] ?? '');
        $providerId = $instanceName ?: '';

        $rawFrom = $data['key']['remoteJid'] ?? $msg['chatid'] ?? $msg['sender'] ?? '';
        $originalFrom = $rawFrom;
        $fromPhone = $this->normalizePhone($rawFrom);
        if (!$fromPhone) {
            return null;
        }

        $messageId = $data['key']['id'] ?? $msg['messageid'] ?? $msg['id'] ?? '';
        $timestamp = isset($data['messageTimestamp']) ? (int) ($data['messageTimestamp']) : (isset($msg['messageTimestamp']) ? (int) ($msg['messageTimestamp'] / 1000) : null);
        $senderName = $data['pushName'] ?? $msg['senderName'] ?? '';
        $messageType = $data['messageType'] ?? $msg['messageType'] ?? '';
        $extra = ['original_from' => $originalFrom];

        // Avatar URL do chat webhook (imagePreview = preview, image = full)
        $chat = $payload['chat'] ?? [];
        $avatarUrl = $chat['imagePreview'] ?? $chat['image'] ?? null;
        if ($avatarUrl === '') $avatarUrl = null;

        // Uazapi: message.content pode ser string (texto) ou array (mídia/interativo)
        $content = $msg['content'] ?? null;

        // Mídia: content é array com URL, mimetype, caption
        $mediaTypes = ['ImageMessage', 'AudioMessage', 'VideoMessage', 'DocumentMessage', 'StickerMessage', 'PttMessage'];
        if (in_array($messageType, $mediaTypes, true) && is_array($content)) {
            $mediaUrl = $content['URL'] ?? $content['url'] ?? '';
            $mediaMime = $content['mimetype'] ?? $content['mimeType'] ?? '';
            $caption = is_string($content['caption'] ?? null) ? $content['caption'] : '';
            $mediaName = $content['name'] ?? $content['filename'] ?? $msg['mediaType'] ?? '';
            $mediaSize = isset($content['fileLength']) ? (int) $content['fileLength'] : 0;
            $extra = array_merge($extra, array_filter([
                'media_name' => $mediaName,
                'media_size' => $mediaSize,
            ]));

            $type = match ($messageType) {
                'ImageMessage' => 'image',
                'AudioMessage', 'PttMessage' => 'audio',
                'VideoMessage' => 'video',
                'DocumentMessage' => 'file',
                'StickerMessage' => 'sticker',
                default => 'file',
            };

            return IncomingMessage::media(
                $providerId, $messageId, $fromPhone, $type, $mediaUrl, $mediaMime,
                $caption, $timestamp, false, $senderName, $avatarUrl, $extra
            );
        }

        // Text message: content é string
        return IncomingMessage::text(
            $providerId, $messageId, $fromPhone,
            is_string($content) ? $content : '',
            $timestamp, false, $senderName, $avatarUrl, $extra
        );
    }

    public function editMessage(array $connection, string $messageId, string $text): bool
    {
        if ($messageId === '') {
            return false;
        }

        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/message/edit', [
                'messageid' => $messageId,
                'text' => $text,
            ], $headers);
            $this->guard($resp['body'] ?? []);
            return ($resp['status'] ?? 0) === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function deleteMessage(array $connection, string $messageId): bool
    {
        if ($messageId === '') {
            return false;
        }

        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/message/delete', [
                'messageid' => $messageId,
            ], $headers);
            $this->guard($resp['body'] ?? []);
            return ($resp['status'] ?? 0) === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function resolvePhone(array $connection, string $contactId): ?string
    {
        // Uazapi não possui endpoint específico para resolução LID -> telefone.
        // O telefone real já é resolvido via sender/chatid no webhook.
        return null;
    }

    public function sendReaction(array $connection, string $messageId, string $reaction): bool
    {
        if ($messageId === '') {
            return false;
        }

        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/message/react', [
                'messageid' => $messageId,
                'reaction' => $reaction,
            ], $headers);
            $this->guard($resp['body'] ?? []);
            return ($resp['status'] ?? 0) === 200;
        } catch (\Throwable $e) {
            error_log('Uazapi sendReaction error: ' . $e->getMessage());
            return false;
        }
    }

    public function getProfilePicture(array $connection, string $contactId): ?string
    {
        $phone = preg_replace('/\D/', '', $contactId);
        if (!$phone) {
            return null;
        }

        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/chat/details', [
                'number' => $phone,
                'preview' => true,
            ], $headers);

            $body = $resp['body'] ?? [];
            $url = $body['imagePreview'] ?? $body['image'] ?? null;
            if ($url && is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable $e) {
            error_log('Uazapi getProfilePicture error: ' . $e->getMessage());
        }

        return null;
    }

    public function sendSeen(array $connection, string $chatId): void
    {
        $phone = preg_replace('/\D/', '', $chatId);
        if (!$phone) {
            return;
        }
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $this->client->post('/message/markread', ['number' => $phone], $headers);
        } catch (\Throwable $e) {
        }
    }

    public function startTyping(array $connection, string $chatId): void
    {
        $phone = preg_replace('/\D/', '', $chatId);
        if (!$phone) {
            return;
        }
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $this->client->post('/instance/presence', [
                'number' => $phone,
                'type' => 'typing',
            ], $headers);
        } catch (\Throwable $e) {
        }
    }

    public function stopTyping(array $connection, string $chatId): void
    {
        $phone = preg_replace('/\D/', '', $chatId);
        if (!$phone) {
            return;
        }
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $this->client->post('/instance/presence', [
                'number' => $phone,
                'type' => 'stop',
            ], $headers);
        } catch (\Throwable $e) {
        }
    }

    // ----------------------------------------------------------------

    private function adminAuthHeaders(): array
    {
        $adminToken = $this->config['admin_token'] ?? '';
        return $adminToken ? ['admintoken' => $adminToken] : [];
    }

    private function instanceAuthHeaders(array $connection): array
    {
        $token = $connection['instance_token'] ?? '';
        if ($token) {
            return ['token' => $token];
        }
        // Fallback: usa admintoken como token de instância se não tiver token específico
        $adminToken = $this->config['admin_token'] ?? '';
        return $adminToken ? ['token' => $adminToken] : [];
    }

    private function guard(mixed $body): void
    {
        if (!is_array($body)) {
            return;
        }
        // Uazapi usa {code: 401, message: "..."} em vez de {status: 401}
        $errCode = $body['code'] ?? $body['status'] ?? null;
        if ($errCode !== null) {
            if ((int) $errCode === 401 || (int) $errCode === 403) {
                throw new WhatsAppAuthException('Uazapi token inválido ou não autorizado.');
            }
        }
        $errStatus = $body['status'] ?? '';
        if (is_string($errStatus) && strtolower($errStatus) === 'unauthorized') {
            throw new WhatsAppAuthException('Uazapi token inválido ou não autorizado.');
        }
    }

    private function normalizeStatus(array $body): string
    {
        // Se o body tem código de erro (ex.: 401 Missing token), retorna 'error'
        if (isset($body['code']) && (int) $body['code'] >= 400) {
            return 'error';
        }
        if (isset($body['error'])) {
            return 'error';
        }

        // Uazapi returns {instance: {status: "connected", ...}, status: {connected: bool}}
        $instance = $body['instance'] ?? $body;
        $raw = $instance['status'] ?? $instance['state'] ?? $instance['connectionStatus'] ?? null;
        if (!is_string($raw)) {
            $raw = $body['status'] ?? null;
        }
        if (!is_string($raw)) {
            $raw = '';
        }
        $status = strtolower($raw);
        return match (true) {
            $status === 'connected' || $status === 'open' || $status === 'authenticated' => 'connected',
            $status === 'waiting_qr' || $status === 'starting' || $status === 'connecting' || $status === 'disconnected' => 'waiting_qr',
            $status === 'failed' || $status === 'error' => 'error',
            default => 'error',
        };
    }

    private function needsQr(string $status): bool
    {
        return in_array($status, ['waiting_qr', 'disconnected'], true);
    }

    private function extractQr(mixed $body): ?string
    {
        if (!$body) {
            return null;
        }

        if (is_string($body)) {
            if (str_starts_with($body, "\x89PNG")) {
                return 'data:image/png;base64,' . base64_encode($body);
            }
            if (str_starts_with($body, 'data:')) {
                return $body;
            }
            return 'data:image/png;base64,' . $body;
        }

        if (!is_array($body)) {
            return null;
        }

        // Uazapi /instance/connect returns {qrcode: "data:image/png;base64,...", pairingCode: "..."}
        // Also check inside data wrapper: {status: "success", data: {qrcode: "..."}}
        $raw = $body['qrcode'] ?? $body['qrCode'] ?? null;
        if (!$raw) {
            $data = $body['data'] ?? null;
            if (is_array($data)) {
                $raw = $data['qrcode'] ?? $data['qrCode'] ?? null;
            }
        }

        // Check instance wrapper used by status endpoint
        if (!$raw) {
            $instance = $body['instance'] ?? null;
            if (is_array($instance)) {
                $raw = $instance['qrcode'] ?? $instance['qrCode'] ?? null;
            }
        }

        if (!$raw) {
            return null;
        }

        if (is_string($raw)) {
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

    private function extractMessageId(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        $data = $body['data'] ?? $body;
        return $data['messageid'] ?? $data['id'] ?? $body['messageid'] ?? $body['id'] ?? null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }
        $phone = preg_replace('/@s\.whatsapp\.net$|@c\.us$|@lid$/', '', $phone);
        $digits = preg_replace('/\D/', '', $phone);
        return $digits === '' ? null : $digits;
    }

    private function uazapiMediaType(string $type): string
    {
        return match ($type) {
            'image' => 'image',
            'audio' => 'audio',
            'video' => 'video',
            'file', 'document' => 'document',
            'sticker' => 'image',
            default => 'document',
        };
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#', $url)) {
            return $url;
        }
        return base_url(ltrim($url, '/'));
    }

    private function loadConfig(): array
    {
        $integrations = require dirname(__DIR__, 3) . '/config/integrations.php';
        return $integrations['whatsapp']['providers']['uazapi'] ?? [];
    }
}
