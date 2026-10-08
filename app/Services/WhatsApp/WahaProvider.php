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

    public function sendButton(array $connection, string $to, string $text, array $buttons): array
    {
        $session = $connection['instance_name'] ?? '';
        $phone = $this->normalizePhone($to);
        $headers = $this->authHeaders();
        $engine = strtoupper($connection['engine'] ?? 'WEBJS');

        $sendWithSuffix = function (string $suffix) use ($engine, $session, $phone, $text, $buttons, $headers) {
            $chatId = $phone . $suffix;
            $waButtons = [];
            foreach ($buttons as $b) {
                $waButtons[] = ['type' => 'reply', 'text' => $b['label']];
            }

            if ($engine === 'NOWEB') {
                $resp = $this->client->post('/api/sendButtons', [
                    'session' => $session,
                    'chatId' => $chatId,
                    'title' => $text,
                    'buttons' => $waButtons,
                ], $headers);
            } else {
                $resp = $this->client->post('/api/send/buttons/reply', [
                    'session' => $session,
                    'chatId' => $chatId,
                    'body' => $text,
                    'buttons' => $waButtons,
                ], $headers);
            }
            return $resp;
        };

        $resp = $sendWithSuffix('@c.us');
        if (($resp['status'] ?? 0) === 500) {
            $body = $resp['body'] ?? [];
            $errMsg = is_array($body) ? (string) ($body['exception']['message'] ?? '') : '';
            if (str_contains($errMsg, 'No LID for user')) {
                $resp = $sendWithSuffix('@lid');
            }
        }

        if (($resp['status'] ?? 0) >= 400) {
            error_log("WAHA sendButton error (status={$resp['status']}): " . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
        }

        return [
            'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
            'raw' => $resp['body'],
        ];
    }

    public function sendList(array $connection, string $to, string $text, string $title, array $items): array
    {
        $engine = $connection['engine'] ?? 'WEBJS';
        if (strtoupper($engine) === 'NOWEB') {
            error_log("WAHA sendList not supported on NOWEB engine (use WEBJS/GOWS). Falling back to text.");
            return ['provider_message_id' => null, 'raw' => [], 'status' => 400];
        }

        $session = $connection['instance_name'] ?? '';
        $phone = $this->normalizePhone($to);
        $headers = $this->authHeaders();

        $sendWithSuffix = function (string $suffix) use ($session, $phone, $text, $title, $items, $headers) {
            $chatId = $phone . $suffix;

            $rows = [];
            foreach ($items as $i => $item) {
                $rows[] = [
                    'rowId' => (string) ($item['id'] ?? $i),
                    'title' => $item['label'] ?? '',
                    'description' => '',
                ];
            }

            $resp = $this->client->post('/api/sendList', [
                'session' => $session,
                'chatId' => $chatId,
                'message' => [
                    'title' => $title,
                    'description' => $text,
                    'button' => 'Ver opções',
                    'sections' => [
                        ['title' => 'Opções', 'rows' => $rows],
                    ],
                ],
            ], $headers);
            return $resp;
        };

        $resp = $sendWithSuffix('@c.us');
        if (($resp['status'] ?? 0) === 500) {
            $body = $resp['body'] ?? [];
            $errMsg = is_array($body) ? (string) ($body['exception']['message'] ?? '') : '';
            if (str_contains($errMsg, 'No LID for user')) {
                $resp = $sendWithSuffix('@lid');
            }
        }

        if (($resp['status'] ?? 0) >= 400) {
            error_log("WAHA sendList error (status={$resp['status']}): " . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
        }

        return [
            'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
            'raw' => $resp['body'],
        ];
    }

    public function send(array $connection, string $to, string $type, string $content, array $options = []): array
    {
        $session = $connection['instance_name'] ?? '';
        $phone = $this->normalizePhone($to);
        $headers = $this->authHeaders();

        $antiBan = $options['anti_ban'] ?? false;
        if ($antiBan && $phone) {
            $chatId = $phone . '@c.us';
            $this->sendSeen($connection, $chatId);
            $this->startTyping($connection, $chatId);
            $this->stopTyping($connection, $chatId);
        }

        $sendWithSuffix = function (string $suffix) use ($session, $phone, $type, $content, $options, $headers) {
            $chatId = $phone . $suffix;

            if ($type === 'text') {
                $textBody = [
                    'session' => $session,
                    'chatId' => $chatId,
                    'text' => $content,
                ];
                // Citação nativa (responder): ID da mensagem original.
                $quotedText = $options['reply_to'] ?? null;
                if (is_string($quotedText) && $quotedText !== '') {
                    $textBody['reply_to'] = $quotedText;
                }
                $resp = $this->client->post('/api/sendText', $textBody, $headers);
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

                if ($type === 'video' || (is_file($absPath) && filesize($absPath) > 2 * 1024 * 1024)) {
                    $filePayload = [
                        'url' => $this->absoluteUrl((string) $mediaRef),
                        'mimetype' => $mime ?: 'video/mp4',
                        'filename' => basename((string) $mediaRef),
                    ];
                } elseif (is_file($absPath)) {
                    $filePayload = [
                        'url' => $this->absoluteUrl((string) $mediaRef),
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
            // Citação nativa em mídia (responder com contexto).
            $quotedFile = $options['reply_to'] ?? null;
            if (is_string($quotedFile) && $quotedFile !== '') {
                $body['reply_to'] = $quotedFile;
            }

            $endpoint = $this->wahaSendEndpoint($type);
            $resp = $this->client->post($endpoint, $body, $headers);

            if ($type === 'video' && ($resp['status'] ?? 0) !== 200) {
                $errMsg = '';
                $errBody = $resp['body'] ?? [];
                if (is_array($errBody)) {
                    $errMsg = (string) ($errBody['message'] ?? $errBody['error'] ?? json_encode($errBody));
                } else {
                    $errMsg = (string) $errBody;
                }
                error_log("WAHA sendVideo failed (status={$resp['status']}): {$errMsg}");

                $body['convert'] = true;
                $resp = $this->client->post($endpoint, $body, $headers);
                if (($resp['status'] ?? 0) === 200) {
                    return $resp;
                }

                error_log('WAHA sendVideo failed, falling back to sendFile');
                unset($body['convert']);
                $resp = $this->client->post('/api/sendFile', $body, $headers);
            }
            return $resp;
        };

        $resp = $sendWithSuffix('@c.us');
        if (($resp['status'] ?? 0) === 500) {
            $body = $resp['body'] ?? [];
            $errMsg = is_array($body) ? (string) ($body['exception']['message'] ?? '') : '';
            if (str_contains($errMsg, 'No LID for user')) {
                $resp = $sendWithSuffix('@lid');
            }
        }

        if (($resp['status'] ?? 0) >= 400) {
            error_log("WAHA send error (session={$session}, type={$type}, status={$resp['status']}): " . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
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
        $isGroup = is_string($from) && str_ends_with($from, '@g.us');
        // Grupos: não descartar — monta contexto (jid, participante, menções).
        $groupExtra = [];
        if ($isGroup) {
            $groupExtra = $this->extractGroupContext($from, $msg);
        }

        $messageId = $msg['id'] ?? '';
        $timestamp = isset($msg['timestamp']) ? (int) $msg['timestamp'] : null;

        // Para edit/revoke, o from pode ser o ID da própria conta (fromMe),
        // então usamos 'to' para identificar o contato. Em grupo, o remetente
        // é o participante.
        if ($isGroup && !empty($groupExtra['participant_phone'])) {
            $fromPhone = $groupExtra['participant_phone'];
        } else {
            $effectiveFrom = $isFromMe ? ($msg['to'] ?? $from) : $from;
            $fromPhone = $this->normalizePhone($effectiveFrom);
        }
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

        // Cartão de contato (vCard): type=contact, _data.type=vcard/contact ou
        // corpo BEGIN:VCARD. Vira IncomingMessage type='contact' (JSON).
        $contactCard = $this->extractContactCard($msg, $dataType);
        if ($contactCard !== null) {
            if (($contactCard['name'] ?? '') === '') {
                $contactCard['name'] = $senderName ?? $fromPhone;
            }
            return IncomingMessage::contact(
                $session, $messageId, $fromPhone, $contactCard, $timestamp, false,
                $senderName, null, ['original_from' => $originalFrom]
            );
        }

        // Ignora notificações do sistema (notification_template, notification, etc.)
        // que não são mensagens de usuário e criariam conversas indevidamente.
        if (in_array($dataType, ['notification_template', 'notification', 'e2e_notification', 'call_log', 'protocol'], true)) {
            return null;
        }

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

            $extra = array_merge(['original_from' => $originalFrom], $groupExtra);
            if ($cdnUrl) {
                $extra['cdn_url'] = $cdnUrl;
            }
            $quotedMedia = self::extractQuotedId($msg);
            if ($quotedMedia !== null) {
                $extra['quoted_id'] = $quotedMedia;
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
        $textExtra = array_merge(['original_from' => $originalFrom], $groupExtra);
        $quotedText = self::extractQuotedId($msg);
        if ($quotedText !== null) {
            $textExtra['quoted_id'] = $quotedText;
        }

        return IncomingMessage::text(
            $session,
            $messageId,
            $fromPhone,
            $body,
            $timestamp,
            false,
            $senderName,
            null,
            $textExtra
        );
    }

    /**
     * Extrai cartão de contato do payload WAHA (vários formatos por versão).
     * Retorna ['name'=>?string,'phone'=>?string] ou null se não for contato.
     */
    private function extractContactCard(array $msg, string $dataType): ?array
    {
        $isContactType = ($msg['type'] ?? '') === 'contact'
            || in_array(strtolower($dataType), ['vcard', 'contact'], true);
        $vcard = $msg['_data']['contactVcard'] ?? $msg['_data']['vcard'] ?? $msg['vcard'] ?? null;
        $contact = $msg['contact'] ?? $msg['_data']['contact'] ?? null;
        $body = (string) ($msg['body'] ?? '');
        $isVcardBody = str_starts_with(ltrim($body), 'BEGIN:VCARD');
        if (!$isContactType && !is_string($vcard) && !is_array($contact) && !$isVcardBody) {
            return null;
        }
        $name = is_array($contact) ? (string) ($contact['name'] ?? $contact['fullName'] ?? '') : '';
        $phone = is_array($contact) ? (string) ($contact['phone'] ?? $contact['phoneNumber'] ?? '') : '';
        if (is_string($vcard) && $vcard !== '') {
            $parsed = IncomingMessage::parseVcard($vcard);
            if ($name === '' && $parsed['name'] !== null) {
                $name = $parsed['name'];
            }
            if ($phone === '' && $parsed['phone'] !== null) {
                $phone = $parsed['phone'];
            }
        }
        if ($isVcardBody) {
            $parsed = IncomingMessage::parseVcard($body);
            if ($name === '' && $parsed['name'] !== null) {
                $name = $parsed['name'];
            }
            if ($phone === '' && $parsed['phone'] !== null) {
                $phone = $parsed['phone'];
            }
        }
        $phone = preg_replace('/\D/', '', $phone);
        if ($name === '' && $phone === '') {
            return null;
        }
        return ['name' => $name !== '' ? $name : null, 'phone' => $phone !== '' ? $phone : null];
    }

    /**
     * Extrai o ID da mensagem citada (resposta): replyTo / quotedMsg
     * (vários formatos conforme a versão do WAHA).
     */
    private static function extractQuotedId(array $msg): ?string
    {
        foreach (['replyTo', 'quotedId', 'quoted_id'] as $k) {
            if (!empty($msg[$k]) && is_string($msg[$k])) {
                return $msg[$k];
            }
        }
        foreach (['quotedMsg', 'quotedMessage'] as $k) {
            $q = $msg[$k] ?? $msg['_data'][$k] ?? null;
            if (is_string($q) && $q !== '') {
                return $q;
            }
            if (is_array($q)) {
                foreach (['id', '_serialized', 'messageId'] as $ik) {
                    if (!empty($q[$ik]) && is_string($q[$ik])) {
                        return $q[$ik];
                    }
                }
            }
        }
        $data = $msg['_data'] ?? [];
        if (is_array($data)) {
            foreach (['replyTo', 'quotedId', 'quoted_id'] as $k) {
                if (!empty($data[$k]) && is_string($data[$k])) {
                    return $data[$k];
                }
            }
        }
        return null;
    }

    /**
     * Contexto de mensagem em grupo (WAHA): JID do grupo, participante e
     * mencionados. Busca defensiva — formato varia por versão do WAHA.
     */
    private function extractGroupContext(string $groupJid, array $msg): array
    {
        $participantRaw = (string) ($msg['participant'] ?? $msg['author'] ?? $msg['_data']['author'] ?? '');
        $participantPhone = $this->normalizePhone($participantRaw) ?: null;

        $mentioned = $this->collectMentionedDigits($msg);

        $groupName = $msg['groupName'] ?? $msg['_data']['groupName'] ?? $msg['chatName'] ?? null;
        if (!is_string($groupName) || $groupName === '') {
            $groupName = null;
        }

        return [
            'is_group' => true,
            'group_jid' => $groupJid,
            'group_name' => $groupName,
            'participant_phone' => $participantPhone,
            'participant_raw' => $participantRaw,
            'mentioned' => $mentioned,
            'participant_count' => null,
        ];
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

    /**
     * Envia texto para um grupo (chatId @g.us preservado), com menções opcionais.
     * WAHA exige @numero no texto E mentions ["xxx@c.us"] (ou ["all"] p/ todos).
     */
    public function sendGroupText(array $connection, string $groupJid, string $text, array $mentions = []): array
    {
        $session = $connection['instance_name'] ?? '';
        $body = [
            'session' => $session,
            'chatId' => $groupJid,
            'text' => $text,
        ];
        $normalized = $this->normalizeGroupMentions($mentions);
        if ($normalized !== []) {
            $body['mentions'] = $normalized;
        }
        $resp = $this->client->post('/api/sendText', $body, $this->authHeaders());
        $this->guard($resp);
        $respBody = $resp['body'] ?? [];
        return [
            'provider_message_id' => $this->extractMessageId($respBody),
            'raw' => $respBody,
        ];
    }

    /**
     * Normaliza menções p/ o formato WAHA: dígitos -> xxx@c.us; 'all' preservado.
     *
     * @return string[]
     */
    private function normalizeGroupMentions(array $mentions): array
    {
        $out = [];
        foreach ($mentions as $m) {
            $m = trim((string) $m, "@ \t");
            if (strtolower($m) === 'all') {
                $out[] = 'all';
                continue;
            }
            if (str_contains($m, '@')) {
                $out[] = $m;
                continue;
            }
            $digits = preg_replace('/\D/', '', $m);
            if ($digits !== '' && strlen($digits) >= 8) {
                $out[] = $digits . '@c.us';
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Lista participantes do grupo em tempo real (sem persistir).
     * Tenta GET /api/{session}/groups/{groupJid}; cai p/ listagem + filtro.
     * Qualquer falha retorna [] (nunca throw) — a UI degrada p/ "indisponível".
     *
     * @return array<int, array{phone:string, lid:?string, name:?string, is_admin:bool}>
     */
    public function fetchGroupParticipants(array $connection, string $groupJid): array
    {
        $session = $connection['instance_name'] ?? '';
        if ($session === '' || $groupJid === '') {
            return [];
        }
        try {
            $resp = $this->client->get('/api/' . rawurlencode($session) . '/groups/' . rawurlencode($groupJid), $this->authHeaders());
            $group = ($resp['status'] ?? 0) === 200 ? ($resp['body'] ?? null) : null;
            $participants = is_array($group) ? ($group['participants'] ?? null) : null;
            if (!is_array($participants)) {
                // Fallback: lista todos e filtra pelo id.
                $listResp = $this->client->get('/api/' . rawurlencode($session) . '/groups', $this->authHeaders());
                $list = ($listResp['status'] ?? 0) === 200 ? ($listResp['body'] ?? []) : [];
                if (is_array($list)) {
                    foreach ($list as $g) {
                        if (is_array($g) && (($g['id'] ?? '') === $groupJid)) {
                            $participants = $g['participants'] ?? null;
                            break;
                        }
                    }
                }
            }
            if (!is_array($participants)) {
                return [];
            }
            return $this->mapGroupParticipants($participants);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Mapeia participantes WAHA p/ shape padrão (tolerando variações de campo).
     *
     * @return array<int, array{phone:string, lid:?string, name:?string, is_admin:bool}>
     */
    private function mapGroupParticipants(array $participants): array
    {
        $out = [];
        $seen = [];
        foreach ($participants as $p) {
            if (!is_array($p)) {
                $p = ['id' => (string) $p];
            }
            $rawId = (string) ($p['id'] ?? $p['jid'] ?? $p['phone'] ?? '');
            $name = $p['name'] ?? $p['pushName'] ?? $p['notifyName'] ?? null;
            $admin = $p['isAdmin'] ?? $p['is_admin'] ?? $p['admin'] ?? false;
            $phone = '';
            $lid = null;
            if (str_ends_with($rawId, '@lid')) {
                $lid = $this->normalizePhone($rawId);
            } elseif ($rawId !== '') {
                $phone = (string) ($this->normalizePhone($rawId) ?? '');
            }
            if (($p['lid'] ?? '') !== '') {
                $lid = (string) ($this->normalizePhone((string) $p['lid']) ?? $lid);
            }
            if (($p['phone'] ?? '') !== '' && $phone === '') {
                $phone = (string) ($this->normalizePhone((string) $p['phone']) ?? '');
            }
            $key = ($phone !== '' ? $phone : 'lid:' . (string) $lid);
            if ($key === '' || $key === 'lid:' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'phone' => $phone,
                'lid' => $lid,
                'name' => is_string($name) && $name !== '' ? $name : null,
                'is_admin' => (bool) $admin,
            ];
        }
        return $out;
    }

    /**
     * Envia um cartão de contato (vCard) para conversa 1:1.
     */
    public function sendContact(array $connection, string $to, array $contact): array
    {
        $session = $connection['instance_name'] ?? '';
        $phone = $this->normalizePhone($to);
        $contactPhone = preg_replace('/\D/', '', (string) ($contact['phone'] ?? ''));
        if (!$phone || $contactPhone === '') {
            return ['provider_message_id' => null, 'raw' => []];
        }
        $name = trim((string) ($contact['name'] ?? ''));
        if ($name === '') {
            $name = $contactPhone;
        }
        $vcard = IncomingMessage::buildVcard($name, $contactPhone, $contact['organization'] ?? null);
        $resp = $this->client->post('/api/sendContactVcard', [
            'session' => $session,
            'chatId' => $phone . '@c.us',
            'contacts' => [['vcard' => $vcard]],
        ], $this->authHeaders());
        $this->guard($resp);
        $body = $resp['body'] ?? [];
        return [
            'provider_message_id' => $this->extractMessageId($body),
            'raw' => $body,
        ];
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

    public function sendReaction(array $connection, string $messageId, string $reaction, ?string $to = null): bool
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

    public function sendSeen(array $connection, string $chatId): void
    {
        $session = $connection['instance_name'] ?? '';
        if (!$session || !$chatId) {
            return;
        }
        $this->presenceRequest('/api/sendSeen', [
            'session' => $session,
            'chatId' => $chatId,
        ]);
    }

    public function startTyping(array $connection, string $chatId): void
    {
        $session = $connection['instance_name'] ?? '';
        if (!$session || !$chatId) {
            return;
        }
        $this->presenceRequest('/api/startTyping', [
            'session' => $session,
            'chatId' => $chatId,
        ]);
    }

    public function stopTyping(array $connection, string $chatId): void
    {
        $session = $connection['instance_name'] ?? '';
        if (!$session || !$chatId) {
            return;
        }
        $this->presenceRequest('/api/stopTyping', [
            'session' => $session,
            'chatId' => $chatId,
        ]);
    }

    private function presenceRequest(string $path, array $body): void
    {
        $url = rtrim($this->config['base_url'] ?? 'http://localhost:3000', '/') . $path;
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = $this->authHeaders();
        $formatted = [];
        foreach ($headers as $k => $v) {
            $formatted[] = is_int($k) ? $v : "{$k}: {$v}";
        }
        $formatted[] = 'Content-Type: application/json';

        $ch = @curl_init($url);
        if ($ch === false) {
            return;
        }
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_POST, true);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        @curl_setopt($ch, CURLOPT_TIMEOUT_MS, 3000);
        @curl_exec($ch);
        @curl_close($ch);
    }

    private function calculateTypingDelay(string $text): int
    {
        $len = mb_strlen($text);
        $base = random_int(400, 800);
        $perChar = min($len * 30, 1200);
        return min($base + $perChar, 2000);
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
        $phone = preg_replace('/@c\.us$|@s\.whatsapp\.net$|@lid$|@g\.us$/', '', $phone);
        // Sufixo de dispositivo do LID (ex.: 225962742546599:93@lid -> base)
        $phone = preg_replace('/:\d+$/', '', $phone);
        $digits = preg_replace('/\D/', '', $phone);
        return $digits === '' ? null : $digits;
    }

    /**
     * Coleta JIDs mencionados varrendo o payload de forma case-insensitive
     * (mentionedJidList x mentionedJID x mentionedIds, qualquer profundidade).
     *
     * @return string[] dígitos únicos
     */
    private function collectMentionedDigits(array $node): array
    {
        $found = [];
        $leaves = function ($value) use (&$leaves, &$found) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $leaves($v);
                }
                return;
            }
            if (is_string($value) && $value !== '') {
                $digits = preg_replace('/\D/', '', $value);
                if ($digits !== '' && strlen($digits) >= 8) {
                    $found[] = $digits;
                }
            }
        };
        $walk = function ($value) use (&$walk, $leaves) {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $k => $v) {
                if (is_string($k) && preg_match('/^mentionedjid(list|ids)?$/i', $k)) {
                    $leaves($v);
                } else {
                    $walk($v);
                }
            }
        };
        $walk($node);
        return array_values(array_unique($found));
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
