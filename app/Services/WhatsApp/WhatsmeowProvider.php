<?php

namespace App\Services\WhatsApp;

/**
 * Provider de WhatsApp sobre o bridge do whatsmeow (Go).
 *
 * O whatsmeow é uma biblioteca (não um servidor), então o AtendeFlow fala com
 * uma service HTTP em Go — a "bridge" — que hospeda as sessões e encaminha os
 * eventos para o webhook do app. Este provider é o cliente HTTP dessa bridge.
 *
 * Contrato da bridge (services/whatsmeow-bridge):
 *   POST   /sessions                                cria/retoma a sessão
 *   GET    /sessions/{id}/qr                        QR em data-URL
 *   GET    /sessions/{id}/status                    estado + número
 *   POST   /sessions/{id}/disconnect                desconecta
 *   DELETE /sessions/{id}                           apaga a sessão
 *   POST   /sessions/{id}/webhook                   url + segredo do webhook
 *   POST   /sessions/{id}/send                      texto/mídia
 *   POST   /sessions/{id}/interactive/buttons       menu de botões
 *   POST   /sessions/{id}/interactive/list          lista interativa
 *   POST   /sessions/{id}/messages/{mid}/edit       editar
 *   POST   /sessions/{id}/messages/{mid}/delete     apagar p/ todos
 *   POST   /sessions/{id}/messages/{mid}/react      reagir
 *   POST   /sessions/{id}/messages/{mid}/seen       marcar visto
 *   POST   /sessions/{id}/typing                    digitando/parando
 *   POST   /sessions/{id}/groups/{jid}/text         texto em grupo
 *   GET    /sessions/{id}/groups/{jid}/participants  participantes
 *   POST   /sessions/{id}/contact                   cartão de contato
 *   GET    /sessions/{id}/contacts/{id}/avatar      foto de perfil
 *   GET    /sessions/{id}/resolve/{contact}         LID -> telefone
 *   GET    /sessions/{id}/media/{mid}               mídia recebida
 */
class WhatsmeowProvider implements WhatsAppProviderInterface
{
    private WhatsAppHttpClient $client;

    public function __construct(?WhatsAppHttpClient $client = null, ?array $config = null)
    {
        if ($config === null) {
            $integrations = require dirname(__DIR__, 3) . '/config/integrations.php';
            $config = $integrations['whatsapp']['providers']['whatsmeow'] ?? [];
        }
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'http://localhost:8090'), '/');
        $this->client = $client ?? new WhatsAppHttpClient($baseUrl, $this->authHeaders($config));
    }

    public function getName(): string
    {
        return 'whatsmeow';
    }

    // ------------------------------------------------------------------
    // Ciclo de vida da sessão
    // ------------------------------------------------------------------

    public function initConnection(array $config): array
    {
        $name = (string) ($config['instance_name'] ?? ($config['name'] ?? 'atendeflow'));
        $resp = $this->client->post('/sessions', ['name' => $name]);
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        if (($resp['status'] ?? 0) >= 400) {
            $message = (string) ($body['error'] ?? 'falha ao criar a sessão na bridge');
            error_log("Whatsmeow initConnection error (status={$resp['status']}): {$message}");
            return ['provider_id' => $name, 'token' => null, 'secret' => null, 'extra' => ['error' => $message]];
        }

        return [
            'provider_id' => (string) ($body['id'] ?? $name),
            'token' => isset($body['token']) ? (string) $body['token'] : null,
            'secret' => isset($body['secret']) ? (string) $body['secret'] : null,
            'extra' => ['status' => $this->mapStatus($body['status'] ?? null)],
        ];
    }

    public function connect(array $connection): array
    {
        $resp = $this->client->get('/sessions/' . rawurlencode($this->sessionId($connection)) . '/qr');
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        return [
            'qr_code' => $this->stringOrNull($body['qr_code'] ?? $body['qr'] ?? null),
            'status' => $this->mapStatus($body['status'] ?? null),
        ];
    }

    public function getStatus(array $connection): array
    {
        $resp = $this->client->get('/sessions/' . rawurlencode($this->sessionId($connection)) . '/status');
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        return [
            'status' => $this->mapStatus($body['status'] ?? null),
            'phone_number' => $this->stringOrNull($body['phone_number'] ?? null),
            'qr_code' => $this->stringOrNull($body['qr_code'] ?? null),
        ];
    }

    /**
     * Traduz o status bruto da bridge para o vocabulario do app.
     *
     * A coluna `whatsapp_connections.status` e um ENUM com quatro valores
     * (disconnected, waiting_qr, connected, error). A bridge tem um
     * vocabulario proprio e devolve `connecting` enquanto o QR ainda esta
     * sendo gerado — se esse valor fosse repassado direto, o MySQL
     * truncava a coluna ("Data truncated for column 'status'") e a tela
     * mostrava erro em vez do QR.
     *
     * `disconnected` tambem vira `waiting_qr` (igual ao UAZAPI): uma sessao
     * sem pareamento precisa do QR para ser conectada.
     */
    private function mapStatus(mixed $raw): string
    {
        $status = strtolower(trim((string) $raw));

        return match (true) {
            $status === 'connected' => 'connected',
            $status === 'error' || $status === 'failed' => 'error',
            $status === 'waiting_qr' || $status === 'connecting' || $status === 'starting' => 'waiting_qr',
            $status === 'disconnected' || $status === '' => 'waiting_qr',
            default => 'error',
        };
    }

    public function disconnect(array $connection): void
    {
        $this->client->post('/sessions/' . rawurlencode($this->sessionId($connection)) . '/disconnect');
    }

    public function deleteConnection(array $connection): void
    {
        $this->client->delete('/sessions/' . rawurlencode($this->sessionId($connection)));
    }

    public function setWebhook(array $connection, string $url): void
    {
        $this->client->post('/sessions/' . rawurlencode($this->sessionId($connection)) . '/webhook', [
            'url' => $url,
            'secret' => (string) ($connection['webhook_secret'] ?? ''),
        ]);
    }

    // ------------------------------------------------------------------
    // Envio
    // ------------------------------------------------------------------

    public function send(array $connection, string $to, string $type, string $content, array $options = []): array
    {
        $target = $this->target($to);
        if ($target === null) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $payload = [
            'to' => $target,
            'type' => $type,
            // Objeto, nunca array: a bridge declara `options` como struct. Um
            // array PHP vazio vira [] no JSON e o Go responde 400
            // ("cannot unmarshal array into Go struct field"), o que fazia
            // TODO envio falhar em silêncio.
            'options' => (object) $this->buildOptions($options),
        ];

        if ($type === 'text') {
            $payload['text'] = $content;
        } else {
            $meta = json_decode($content, true);
            $meta = is_array($meta) ? $meta : [];
            $payload['media'] = $this->buildMedia($meta, $content, $type, $options);
        }

        $resp = $this->client->post('/sessions/' . rawurlencode($this->sessionId($connection)) . '/send', $payload);
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        if (($resp['status'] ?? 0) >= 400) {
            $message = (string) ($body['error'] ?? 'falha ao enviar');
            error_log("Whatsmeow send error (type={$type}, status={$resp['status']}): {$message}");
            return [
                'provider_message_id' => null,
                'raw' => $body,
                'error' => $message,
                'http_status' => $resp['status'] ?? 0,
            ];
        }

        return [
            'provider_message_id' => $this->extractMessageId($body),
            'raw' => $body,
        ];
    }

    public function sendButton(array $connection, string $to, string $text, array $buttons): array
    {
        $target = $this->target($to);
        if ($target === null) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $options = [];
        foreach (WhatsAppInteractive::normalizeOptions($buttons) as $button) {
            $options[] = ['id' => $button['id'], 'label' => $button['label']];
        }

        $resp = $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/interactive/buttons',
            ['to' => $target, 'text' => $text, 'buttons' => $options]
        );
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        // Degradação: sem interativo nativo, a bridge devolve native=false e o
        // provider manda a apresentação em texto numerado (o fluxo responde
        // pela posição, então o menu continua funcionando).
        if (($resp['status'] ?? 0) >= 400 || (isset($body['native']) && $body['native'] === false)) {
            $fallback = $this->send($connection, $to, 'text', WhatsAppInteractive::renderButtonsAsText($text, $buttons));
            if (($fallback['provider_message_id'] ?? null) !== null) {
                return $fallback;
            }
            return [
                'provider_message_id' => null,
                'raw' => $body,
                'error' => (string) ($body['error'] ?? 'menu interativo indisponível'),
                'http_status' => $resp['status'] ?? 0,
            ];
        }

        return ['provider_message_id' => $this->extractMessageId($body), 'raw' => $body];
    }

    public function sendList(array $connection, string $to, string $text, string $title, array $items): array
    {
        $target = $this->target($to);
        if ($target === null) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $options = [];
        foreach (WhatsAppInteractive::normalizeOptions($items) as $item) {
            $options[] = ['id' => $item['id'], 'label' => $item['label']];
        }

        $resp = $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/interactive/list',
            ['to' => $target, 'text' => $text, 'title' => $title, 'items' => $options]
        );
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        if (($resp['status'] ?? 0) >= 400 || (isset($body['native']) && $body['native'] === false)) {
            $fallback = $this->send($connection, $to, 'text', WhatsAppInteractive::renderListAsText($text, $title, $items));
            if (($fallback['provider_message_id'] ?? null) !== null) {
                return $fallback;
            }
            return [
                'provider_message_id' => null,
                'raw' => $body,
                'error' => (string) ($body['error'] ?? 'lista interativa indisponível'),
                'http_status' => $resp['status'] ?? 0,
            ];
        }

        return ['provider_message_id' => $this->extractMessageId($body), 'raw' => $body];
    }

    public function sendContact(array $connection, string $to, array $contact): array
    {
        $target = $this->target($to);
        if ($target === null) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $resp = $this->client->post('/sessions/' . rawurlencode($this->sessionId($connection)) . '/contact', [
            'to' => $target,
            'name' => (string) ($contact['name'] ?? ''),
            'phone' => (string) preg_replace('/\D/', '', (string) ($contact['phone'] ?? '')),
            'organization' => (string) ($contact['organization'] ?? ''),
        ]);
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        return [
            'provider_message_id' => $this->extractMessageId($body),
            'raw' => $body,
        ];
    }

    public function sendGroupText(array $connection, string $groupJid, string $text, array $mentions = []): array
    {
        $jid = $this->groupJid($groupJid);
        if ($jid === null) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $payloadMentions = [];
        foreach ($mentions as $mention) {
            if (!is_string($mention) || trim($mention) === '') {
                continue;
            }
            $mention = trim($mention);
            if (strtolower($mention) === 'all') {
                $payloadMentions[] = 'all';
                continue;
            }
            if (str_contains($mention, '@')) {
                $payloadMentions[] = $mention;
                continue;
            }
            $digits = preg_replace('/\D/', '', $mention);
            if ($digits !== '') {
                $payloadMentions[] = $digits . '@s.whatsapp.net';
            }
        }

        $resp = $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/groups/' . rawurlencode($jid) . '/text',
            ['text' => $text, 'mentions' => $payloadMentions]
        );
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        if (($resp['status'] ?? 0) >= 400) {
            $message = (string) ($body['error'] ?? 'falha ao enviar no grupo');
            error_log("Whatsmeow sendGroupText error (status={$resp['status']}): {$message}");
            return [
                'provider_message_id' => null,
                'raw' => $body,
                'error' => $message,
                'http_status' => $resp['status'] ?? 0,
            ];
        }

        return ['provider_message_id' => $this->extractMessageId($body), 'raw' => $body];
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    public function parseWebhook(array $payload): ?IncomingMessage
    {
        $event = (string) ($payload['event'] ?? '');
        if ($event !== 'message' && $event !== 'messages.upsert') {
            return null;
        }

        $msg = $payload['message'] ?? [];
        if (!is_array($msg) || $msg === []) {
            return null;
        }

        if (!empty($msg['from_me'])) {
            return null;
        }

        $providerId = (string) ($payload['session'] ?? '');
        $isGroup = !empty($msg['is_group']) || str_contains((string) ($msg['chat_id'] ?? ''), '@g.us');
        $chatId = (string) ($msg['chat_id'] ?? $msg['from'] ?? '');

        $fromPhone = (string) ($msg['from_phone'] ?? '');
        $fromLid = (string) ($msg['from_lid'] ?? '');
        $participantPhone = (string) ($msg['participant_phone'] ?? '');
        $participantLid = (string) ($msg['participant_lid'] ?? '');
        $participant = (string) ($msg['participant'] ?? '');

        $extra = [];
        if ($isGroup) {
            $extra['is_group'] = true;
            $extra['group_jid'] = $chatId;
            $extra['participant_phone'] = $participantPhone !== '' ? $participantPhone : $fromPhone;
            $extra['participant_lid'] = $participantLid !== '' ? $participantLid : $fromLid;
            $extra['original_from'] = $participant !== '' ? $participant : $chatId;
            $from = $extra['participant_phone'];
        } else {
            $extra['original_from'] = (string) ($msg['from'] ?? '');
            $from = $fromPhone;
            // Só chegou LID: mantém o id como from (o service resolve o telefone
            // real via resolvePhone quando original_from termina em @lid).
            if ($from === '' && $fromLid !== '') {
                $from = $fromLid;
                $extra['original_from'] = $fromLid . '@lid';
            }
        }

        if ($from === '') {
            return null;
        }

        $messageId = (string) ($msg['id'] ?? '');
        $timestamp = isset($msg['timestamp']) ? (int) $msg['timestamp'] : null;
        $senderName = (string) ($msg['sender_name'] ?? '');
        $type = (string) ($msg['type'] ?? 'text');
        $body = (string) ($msg['body'] ?? '');

        $quoted = $msg['quoted_id'] ?? null;
        if (is_string($quoted) && $quoted !== '') {
            $extra['quoted_id'] = $quoted;
        }

        // Reação: o app espera o mesmo JSON do UAZAPI.
        if ($type === 'reaction' || isset($msg['reaction'])) {
            $reaction = is_array($msg['reaction'] ?? null) ? $msg['reaction'] : [];
            $emoji = (string) ($reaction['emoji'] ?? $body);
            $parent = (string) ($reaction['parent_message_id'] ?? $msg['parent_message_id'] ?? '');
            return IncomingMessage::text(
                $providerId, $messageId, $from,
                json_encode(['reaction' => $emoji, 'parent_message_id' => $parent], JSON_UNESCAPED_UNICODE),
                $timestamp, false, $senderName, null, $extra
            );
        }

        // Cartão de contato.
        if ($type === 'contact' && isset($msg['contact']) && is_array($msg['contact'])) {
            $card = $msg['contact'];
            if (($card['name'] ?? '') === '') {
                $card['name'] = $senderName !== '' ? $senderName : $from;
            }
            return IncomingMessage::contact(
                $providerId, $messageId, $from, $card, $timestamp, false, $senderName, null, $extra
            );
        }

        // Mídia.
        if (in_array($type, ['image', 'audio', 'video', 'file', 'document', 'sticker'], true) && isset($msg['media']) && is_array($msg['media'])) {
            $media = $msg['media'];
            $extra = array_merge($extra, array_filter([
                'media_name' => $media['filename'] ?? null,
                'media_size' => isset($media['size']) ? (int) $media['size'] : null,
            ]));

            return IncomingMessage::media(
                $providerId, $messageId, $from,
                $type === 'document' ? 'file' : $type,
                (string) ($media['url'] ?? ''),
                $this->stringOrNull($media['mime'] ?? null),
                (string) ($msg['caption'] ?? ''),
                $timestamp, false, $senderName, null, $extra
            );
        }

        // Resposta de botão/lista: o texto é o rótulo escolhido.
        if ($type === 'button' || $type === 'list' || isset($msg['interactive'])) {
            $interactive = is_array($msg['interactive'] ?? null) ? $msg['interactive'] : [];
            $label = (string) ($interactive['label'] ?? $body);
            if ($label === '') {
                $label = (string) ($interactive['id'] ?? '');
            }
            return IncomingMessage::text(
                $providerId, $messageId, $from, $label, $timestamp, false, $senderName, null, $extra
            );
        }

        return IncomingMessage::text(
            $providerId, $messageId, $from, $body, $timestamp, false, $senderName, null, $extra
        );
    }

    public function downloadMedia(array $connection, string $messageId): ?array
    {
        $resp = $this->client->get(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/media/' . rawurlencode($messageId)
        );
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        if (($resp['status'] ?? 0) >= 400 || empty($body['url'])) {
            return null;
        }

        return [
            'fileURL' => (string) $body['url'],
            'mime' => $this->stringOrNull($body['mime'] ?? null),
            'name' => $this->stringOrNull($body['name'] ?? null),
        ];
    }

    // ------------------------------------------------------------------
    // Ações em mensagens existentes
    // ------------------------------------------------------------------

    public function editMessage(array $connection, string $messageId, string $text): bool
    {
        $resp = $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/messages/' . rawurlencode($messageId) . '/edit',
            ['text' => $text]
        );
        return ($resp['status'] ?? 0) < 400;
    }

    public function deleteMessage(array $connection, string $messageId): bool
    {
        $resp = $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/messages/' . rawurlencode($messageId) . '/delete'
        );
        return ($resp['status'] ?? 0) < 400;
    }

    public function sendReaction(array $connection, string $messageId, string $reaction, ?string $to = null): bool
    {
        $payload = ['reaction' => $reaction];
        if ($to !== null && $to !== '') {
            $target = $this->target($to);
            if ($target !== null) {
                $payload['to'] = $target;
            }
        }

        $resp = $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/messages/' . rawurlencode($messageId) . '/react',
            $payload
        );
        return ($resp['status'] ?? 0) < 400;
    }

    // ------------------------------------------------------------------
    // Contatos, grupos e presença
    // ------------------------------------------------------------------

    public function resolvePhone(array $connection, string $contactId): ?string
    {
        $resp = $this->client->get(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/resolve/' . rawurlencode($contactId)
        );
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        if (($resp['status'] ?? 0) >= 400) {
            return null;
        }

        $phone = (string) ($body['phone'] ?? '');
        if ($phone !== '') {
            return $phone;
        }

        // A bridge pode devolver só o LID: nesse caso não há telefone real.
        return null;
    }

    public function fetchGroupParticipants(array $connection, string $groupJid): array
    {
        $jid = $this->groupJid($groupJid);
        if ($jid === null) {
            return [];
        }

        $resp = $this->client->get(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/groups/' . rawurlencode($jid) . '/participants'
        );
        $body = $resp['body'] ?? [];
        if (($resp['status'] ?? 0) >= 400 || !is_array($body)) {
            return [];
        }

        // A bridge já devolve no shape padrão; normaliza de novo para
        // garantir o contrato (phone/lid/name/is_admin) independentemente
        // da versão da bridge.
        return WhatsAppIds::mapParticipants($body);
    }

    public function getProfilePicture(array $connection, string $contactId): ?string
    {
        $resp = $this->client->get(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/contacts/' . rawurlencode($contactId) . '/avatar'
        );
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];

        if (($resp['status'] ?? 0) >= 400) {
            return null;
        }

        return $this->stringOrNull($body['url'] ?? null);
    }

    // Extras usados pelo WhatsAppService (method_exists).

    public function sendSeen(array $connection, string $chatId): void
    {
        $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/messages/seen',
            ['to' => $chatId]
        );
    }

    public function startTyping(array $connection, string $chatId): void
    {
        $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/typing',
            ['to' => $chatId, 'state' => 'composing']
        );
    }

    public function stopTyping(array $connection, string $chatId): void
    {
        $this->client->post(
            '/sessions/' . rawurlencode($this->sessionId($connection)) . '/typing',
            ['to' => $chatId, 'state' => 'paused']
        );
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Identificador da sessão na bridge. Usa o instance_name porque é ele que
     * casa com a coluna whatsapp_connections.instance_name no webhook.
     */
    private function sessionId(array $connection): string
    {
        return (string) ($connection['instance_name'] ?? ($connection['instance_id'] ?? 'default'));
    }

    /**
     * Destinatário: preserva JID (@g.us/@lid/@s.whatsapp.net) e normaliza
     * telefone puro para dígitos.
     */
    private function target(string $to): ?string
    {
        $to = trim($to);
        if ($to === '') {
            return null;
        }
        if (str_contains($to, '@')) {
            return $to;
        }
        $digits = preg_replace('/\D/', '', $to);
        return $digits !== '' ? $digits : null;
    }

    /**
     * JID de grupo: obrigatório e sempre @g.us.
     */
    private function groupJid(string $groupJid): ?string
    {
        $groupJid = trim($groupJid);
        if ($groupJid === '') {
            return null;
        }
        if (str_contains($groupJid, '@g.us')) {
            return $groupJid;
        }
        $digits = preg_replace('/\D/', '', $groupJid);
        return $digits !== '' ? $digits . '@g.us' : null;
    }

    /**
     * Opções extras do envio (caption, citação, nome do arquivo).
     */
    private function buildOptions(array $options): array
    {
        $out = [];
        if (isset($options['caption']) && is_string($options['caption']) && trim($options['caption']) !== '') {
            $out['caption'] = $options['caption'];
        }
        if (isset($options['quoted_id']) && is_string($options['quoted_id']) && $options['quoted_id'] !== '') {
            $out['quoted_id'] = $options['quoted_id'];
        }
        if (isset($options['file_name']) && is_string($options['file_name']) && $options['file_name'] !== '') {
            $out['file_name'] = $options['file_name'];
        }
        if (isset($options['mimetype']) && is_string($options['mimetype']) && $options['mimetype'] !== '') {
            $out['mimetype'] = $options['mimetype'];
        }
        if (isset($options['as_voice']) && $options['as_voice']) {
            $out['as_voice'] = true;
        }
        return $out;
    }

    /**
     * Monta a mídia para envio. A bridge não alcança a URL pública do app,
     * então o arquivo local é lido aqui e enviado em base64 (data URI).
     */
    private function buildMedia(array $meta, string $rawContent, string $type, array $options): array
    {
        $mime = (string) ($options['mimetype'] ?? ($meta['mime'] ?? ''));
        $name = (string) ($meta['name'] ?? ($options['file_name'] ?? ''));
        $path = isset($meta['path']) && is_string($meta['path']) ? $meta['path'] : null;

        if ($path !== null && $path !== '' && is_file($path)) {
            $data = @file_get_contents($path);
            if ($data !== false) {
                if ($mime === '') {
                    $mime = $this->mimeFromPath($path);
                }
                if ($name === '') {
                    $name = basename($path);
                }
                return [
                    'filename' => $name,
                    'mimetype' => $mime !== '' ? $mime : 'application/octet-stream',
                    'data' => base64_encode($data),
                    'encoding' => 'base64',
                ];
            }
        }

        // Sem arquivo local: tenta a URL (a bridge baixa).
        $url = (string) ($meta['url'] ?? '');
        if ($url === '' && str_starts_with(trim($rawContent), 'http')) {
            $url = trim($rawContent);
        }

        return [
            'filename' => $name,
            'mimetype' => $mime !== '' ? $mime : 'application/octet-stream',
            'url' => $url,
            'type' => $type,
        ];
    }

    private function mimeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'ogg', 'opus' => 'audio/ogg; codecs=opus',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function extractMessageId(array $body): ?string
    {
        foreach (['message_id', 'id', 'messageId'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }
        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,string>
     */
    private function authHeaders(array $config): array
    {
        $token = (string) ($config['bridge_token'] ?? '');
        return $token === '' ? [] : ['X-Bridge-Token' => $token];
    }
}
