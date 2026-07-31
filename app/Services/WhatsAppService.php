<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\IncomingMessage;
use App\Services\WhatsApp\WhatsAppAuthException;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppProviderInterface;

/**
 * Orquestra a integração de WhatsApp de forma independente do provedor.
 * Controladores e fluxos de negócio conversam apenas com este service.
 */
class WhatsAppService
{
    /**
     * Inicia (ou retoma) a conexão de um canal WhatsApp: cria a instância remota,
     * dispara o pareamento e registra o webhook.
     *
     * @return array{status:string, qr_code:?string, provider_id:?string}
     */
    public function connectChannel(int $channelId, ?string $instanceName = null): array
    {
        $channel = Database::getInstance()->fetch("SELECT * FROM channels WHERE id = ?", [$channelId]);
        if (!$channel || $channel['type'] !== 'whatsapp') {
            throw new \RuntimeException('Canal WhatsApp inválido.');
        }

        $connection = WhatsAppConnection::findByChannel($channelId);
        $providerName = $connection['provider'] ?? WhatsAppManager::defaultProviderName();
        $provider = WhatsAppManager::provider($providerName);

        if (!$connection) {
            $connection = [
                'channel_id' => $channelId,
                'provider' => $providerName,
            ];
            $id = WhatsAppConnection::create($connection);
            $connection = WhatsAppConnection::find($id);
        }

        // Garante uma sessão remota válida. Se a sessão foi deletada ou expirou,
        // recriamos a sessão no provedor.
        if (!$this->instanceTokenValid($connection, $provider)) {
            $connection = $this->createInstance($connection, $provider, $instanceName);
        }

        if (empty($connection['webhook_secret'])) {
            $secret = bin2hex(random_bytes(16));
            WhatsAppConnection::update((int) $connection['id'], ['webhook_secret' => $secret]);
            $connection['webhook_secret'] = $secret;
        }

        // Registra o webhook no provedor.
        $this->configureWebhook($connection, $provider);

        // Dispara o pareamento (QR Code). Se o token tiver expirado entre a checagem
        // e aqui, recria a instância e tenta novamente.
        try {
            $connect = $provider->connect($connection);
        } catch (WhatsAppAuthException $e) {
            $connection = $this->createInstance($connection, $provider, $instanceName);
            $this->configureWebhook($connection, $provider);
            $connect = $provider->connect($connection);
        }

        // Sempre consultamos o status para capturar o instance_id (necessário para
        // correlacionar os webhooks) e, se ainda não tivermos QR, obtê-lo aqui.
        $qr = $connect['qr_code'];
        $instanceId = null;
        try {
            $state = $provider->getStatus($connection);
            if (!$qr) {
                $qr = $state['qr_code'] ?? null;
            }
            $instanceId = $state['instance_id'] ?? null;
        } catch (\Throwable $e) {
            // Mantém o que já temos do connect().
        }

        WhatsAppConnection::update((int) $connection['id'], [
            'status' => $connect['status'],
            'qr_code' => $qr,
            'instance_id' => $instanceId ?? $connection['instance_id'],
            'error_message' => null,
        ]);

        return [
            'status' => $connect['status'],
            'qr_code' => $qr,
            'provider_id' => $connection['instance_name'],
        ];
    }

    /**
     * Consulta o estado atual da conexão e persiste mudanças.
     *
     * @return array{status:string, phone_number:?string, qr_code:?string}
     */
    public function status(int $channelId): array
    {
        $connection = WhatsAppConnection::findByChannel($channelId);
        if (!$connection) {
            return ['status' => 'disconnected', 'phone_number' => null, 'qr_code' => null];
        }

        $provider = WhatsAppManager::forConnection($connection);
        try {
            $state = $provider->getStatus($connection);
        } catch (WhatsAppAuthException $e) {
            // Token expirado: sinaliza para a UI reconectar (recría a instância).
            return [
                'status' => 'disconnected',
                'phone_number' => $connection['phone_number'],
                'qr_code' => null,
                'auth_expired' => true,
            ];
        }

        $update = ['status' => $state['status']];
        if (!empty($state['instance_id'])) {
            $update['instance_id'] = $state['instance_id'];
        }
        if ($state['status'] === 'connected') {
            $update['phone_number'] = $state['phone_number'];
            $update['last_connected_at'] = date('Y-m-d H:i:s');
            $update['qr_code'] = null;
            $update['error_message'] = null;
        } elseif ($state['status'] === 'error') {
            $update['error_message'] = $state['phone_number'] ?? 'Erro de conexão';
        } elseif ($state['status'] === 'waiting_qr' && !empty($state['qr_code'])) {
            $update['qr_code'] = $state['qr_code'];
        }
        WhatsAppConnection::update((int) $connection['id'], $update);

        return [
            'status' => $state['status'],
            'phone_number' => $state['phone_number'] ?? $connection['phone_number'],
            'qr_code' => $state['qr_code'] ?? ($state['status'] === 'waiting_qr' ? $connection['qr_code'] : null),
        ];
    }

    public function disconnectChannel(int $channelId): void
    {
        $connection = WhatsAppConnection::findByChannel($channelId);
        if (!$connection) {
            return;
        }
        $provider = WhatsAppManager::forConnection($connection);
        try {
            $provider->disconnect($connection);
        } catch (\Throwable $e) {
            // Mesmo que o provedor falhe, marcamos como desconectado localmente.
        }
        WhatsAppConnection::update((int) $connection['id'], [
            'status' => 'disconnected',
            'qr_code' => null,
            'last_connected_at' => null,
        ]);
    }

    /**
     * Cria (ou recria) a sessão remota no provedor e persiste nome/id.
     * Usado quando não há sessão ou quando ela foi deletada/expirada.
     */
    private function createInstance(array $connection, object $provider, ?string $instanceName): array
    {
        $init = $provider->initConnection([
            'instance_name' => $instanceName ?: ($connection['instance_name'] ?? null),
        ]);
        $update = [
            'instance_name' => $init['provider_id'],
            'instance_id' => $init['instance_id'] ?? null,
            'instance_token' => $init['token'],
            'status' => 'disconnected',
            'qr_code' => null,
            'error_message' => null,
        ];
        if (!empty($init['secret'])) {
            $update['webhook_secret'] = $init['secret'];
        }
        WhatsAppConnection::update((int) $connection['id'], $update);
        return array_merge($connection, $update);
    }

    /**
     * Verifica se a sessão ainda é válida no provedor.
     * Erros de rede são ignorados (assumimos válido); só 401 invalida.
     */
    private function instanceTokenValid(array $connection, object $provider): bool
    {
        if (empty($connection['instance_name'])) {
            return false;
        }
        try {
            $state = $provider->getStatus($connection);
            if (in_array($state['status'] ?? '', ['disconnected', 'error'], true)) {
                return false;
            }
            return true;
        } catch (WhatsAppAuthException $e) {
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function configureWebhook(array $connection, ?object $provider = null): void
    {
        $provider ??= WhatsAppManager::forConnection($connection);
        $integrations = require dirname(__DIR__, 2) . '/config/integrations.php';
        $path = $integrations['whatsapp']['webhook_path'] ?? '/webhooks/whatsapp';
        $secret = $connection['webhook_secret'] ?? bin2hex(random_bytes(16));
        $url = base_url(ltrim($path, '/')) . '?secret=' . $secret;
        $url = str_replace('://localhost/', '://host.docker.internal/', $url);

        try {
            $provider->setWebhook($connection, $url);
            WhatsAppConnection::update((int) $connection['id'], ['webhook_url' => $url]);
        } catch (\Throwable $e) {
            // Webhook será reconfigurado em nova tentativa de conexão.
        }
    }

    /**
     * Recebe o payload bruto do webhook, normaliza e cria/atualiza a conversa.
     */
    public function handleWebhook(array $payload): void
    {
        $this->logWebhook('RECEIVED', [
            'query' => $_GET,
            'event' => $payload['EventType'] ?? $payload['event'] ?? null,
            'instance' => $payload['instanceName'] ?? $payload['instance'] ?? $payload['session'] ?? null,
            'has_message' => isset($payload['message']),
            'has_data' => isset($payload['data']),
            'has_payload' => isset($payload['payload']),
        ]);

        $providerName = $this->resolveProviderFromPayload($payload);
        $provider = WhatsAppManager::provider($providerName);

        // Eventos de conexão/status da instância (EventType: connection, qrcode, status...).
        $event = strtolower((string) ($payload['EventType'] ?? $payload['event'] ?? ($payload['data']['event'] ?? '')));
        if (in_array($event, ['connection', 'connections.update', 'status', 'qrcode', 'qrcode.update', 'qrcode.updated', 'state', 'session.status'], true)) {
            $this->handleConnectionEvent($payload, $providerName);
            return;
        }

        $message = $provider->parseWebhook($payload);
        if (!$message) {
            $this->logWebhook('FILTERED', ['reason' => 'parseWebhook retornou null (fromMe/evento nao-mensagem/grupo)']);
            return;
        }

        $this->logWebhook('PARSED', [
            'providerId' => $message->providerId,
            'from' => $message->from,
            'type' => $message->type,
            'content' => substr($message->content, 0, 120),
        ]);

        $connection = WhatsAppConnection::findByProviderId($providerName, $message->providerId);
        if (!$connection) {
            $this->logWebhook('NO_CONNECTION', ['providerId' => $message->providerId, 'provider' => $providerName]);
            return;
        }

        // Validação opcional do segredo do webhook.
        $secret = $_GET['secret'] ?? '';
        // Uazapi pode adicionar /messages/text ao final da URL; extrai apenas a parte hex
        if (preg_match('/^([a-f0-9]{32})/i', $secret, $m)) {
            $secret = $m[1];
        }
        if (!empty($connection['webhook_secret']) && !hash_equals((string) $connection['webhook_secret'], (string) $secret)) {
            $this->logWebhook('SECRET_FAIL', ['got' => $secret, 'expected_len' => strlen($connection['webhook_secret'])]);
            return;
        }

        // Tráfego real da instância implica que ela está conectada: destrava o envio.
        if ($connection['status'] !== 'connected') {
            WhatsAppConnection::update((int) $connection['id'], [
                'status' => 'connected',
                'last_connected_at' => date('Y-m-d H:i:s'),
                'error_message' => null,
            ]);
        }

        // Se o número for LID, tenta resolver o telefone real via WAHA
        $phone = $message->from;
        $originalFrom = $message->extra['original_from'] ?? '';
        if ($originalFrom !== '' && str_ends_with($originalFrom, '@lid')) {
            $realPhone = $provider->resolvePhone($connection, $originalFrom);
            if ($realPhone) {
                $phone = $realPhone;
                $this->logWebhook('PHONE_RESOLVED', ['lid' => $originalFrom, 'phone' => $realPhone]);
            }
        }

        // Ignora mensagens do próprio número conectado
        $connectedPhone = preg_replace('/\D/', '', (string) ($connection['phone_number'] ?? ''));
        $senderPhone = preg_replace('/\D/', '', $phone);
        if ($connectedPhone !== '' && $senderPhone === $connectedPhone) {
            $this->logWebhook('SELF_MESSAGE_IGNORED', ['phone' => $phone, 'connected_phone' => $connection['phone_number']]);
            return;
        }

        $contact = Contact::findOrCreate($message->senderName ?? preg_replace('/\D/', '', $phone), null, $phone);

        // Atualiza nome e foto de perfil do WhatsApp (se disponíveis e ainda não definidos).
        $this->syncContactProfile($contact, $message, $providerName);

        $conversation = $this->findOrCreateConversation((int) $connection['channel_id'], $contact['id']);

        // Reaction message: atualiza o balão da mensagem original
        if ($message->type === 'text' && str_starts_with($message->content, '{"reaction":')) {
            $meta = json_decode($message->content, true);
            $reaction = $meta['reaction'] ?? '';
            $parentMsgId = $meta['parent_message_id'] ?? '';

            if ($parentMsgId) {
                $parentMsg = Database::getInstance()->fetch(
                    "SELECT id, reactions FROM messages WHERE channel_message_id = ? AND conversation_id = ? LIMIT 1",
                    [$parentMsgId, $conversation['id']]
                );
                if ($parentMsg) {
                    $existing = json_decode((string) ($parentMsg['reactions'] ?? '[]'), true) ?: [];
                    // Remove reaction from same sender if exists (toggle)
                    $existing = array_values(array_filter($existing, fn($r) => ($r['from'] ?? '') !== $phone));
                    if ($reaction !== '') {
                        $existing[] = [
                            'emoji' => $reaction,
                            'from' => $phone,
                            'sender_name' => $message->senderName ?? $contact['name'] ?? '',
                            'timestamp' => time(),
                        ];
                    }
                    Database::getInstance()->update('messages', [
                        'reactions' => json_encode($existing, JSON_UNESCAPED_UNICODE),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], 'id = ?', [$parentMsg['id']]);
                    $this->logWebhook('REACTION_UPDATED', ['parent_msg_id' => $parentMsg['id'], 'emoji' => $reaction, 'from' => $phone]);
                } else {
                    $this->logWebhook('REACTION_PARENT_NOT_FOUND', ['parent_channel_msg_id' => $parentMsgId]);
                }
            }
            return;
        }

        // Message edit (cliente editou a mensagem)
        if ($message->extra['event_type'] ?? '' === 'message.edited') {
            $existing = Database::getInstance()->fetch(
                "SELECT id FROM messages WHERE channel_message_id = ? AND conversation_id = ? LIMIT 1",
                [$message->messageId, $conversation['id']]
            );
            if ($existing) {
                Database::getInstance()->update('messages', [
                    'content' => $message->content,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
                $this->logWebhook('MESSAGE_EDITED', ['msg_id' => $existing['id']]);
            }
            return;
        }

        // Message revoke (cliente/agente apagou a mensagem)
        if ($message->extra['event_type'] ?? '' === 'message.revoked') {
            $existing = Database::getInstance()->fetch(
                "SELECT id FROM messages WHERE channel_message_id = ? AND conversation_id = ? LIMIT 1",
                [$message->messageId, $conversation['id']]
            );
            if ($existing) {
                Database::getInstance()->update('messages', [
                    'content' => '',
                    'deleted_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
                $this->logWebhook('MESSAGE_REVOKED', ['msg_id' => $existing['id']]);
            }
            // Insere mensagem de sistema informando que a mensagem foi apagada
            $who = $message->fromMe ? 'Você' : ($message->senderName ?? 'O cliente');
            Conversation::addMessage($conversation['id'], [
                'type' => 'system',
                'content' => $who . ' apagou uma mensagem',
                'direction' => $message->fromMe ? 'outbound' : 'inbound',
            ]);
            return;
        }

        if (in_array($message->type, ['image', 'audio', 'video', 'file', 'sticker'], true)) {
            $resolved = $this->resolveInboundMedia($provider, $connection, $message);
            if ($resolved) {
                $type = $resolved['type'];
                $content = json_encode([
                    'url' => $resolved['url'],
                    'name' => $resolved['name'],
                    'size' => $resolved['size'],
                    'mime' => $resolved['mime'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $this->logWebhook('MEDIA_UNRESOLVED', [
                    'mid' => $message->messageId,
                    'type' => $message->type,
                    'raw_url' => $message->mediaUrl,
                ]);
                $type = $message->type;
                $content = json_encode([
                    'error' => 'download_failed',
                    'name' => ($message->caption ?: $message->type) . '.' . ($this->extFromMime((string) $message->mediaMime) ?: 'bin'),
                    'size' => 0,
                    'mime' => $message->mediaMime ?? 'application/octet-stream',
                    'url' => $message->mediaUrl ?? '',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } else {
            $type = 'text';
            $content = $message->content;
        }

        $msgId = Conversation::addMessage($conversation['id'], [
            'type' => $type,
            'content' => $content,
            'direction' => 'inbound',
            'channel_message_id' => $message->messageId,
        ]);

        // Detecta se o fluxo está ou será executado (antes de executar,
        // pois o fluxo pode completar e setar is_active=0 antes da notificação).
        $wasFlowActive = false;
        $flowState = \App\Models\Flow::getActiveFlowState($conversation['id']);
        if ($flowState) {
            $wasFlowActive = true;
            error_log("FLOW_ACTIVE: conversation_id={$conversation['id']}, flow_id={$flowState['flow_id']}");
            $flowEngine = new FlowEngineService();
            $flowEngine->handleCustomerMessage($conversation['id'], $type === 'text' ? $message->content : '');
        } else {
            $channel = Database::getInstance()->fetch("SELECT * FROM channels WHERE id = ?", [$connection['channel_id']]);
            error_log("FLOW_CHECK: channel_id={$connection['channel_id']}, channel_exists=" . ($channel ? 'yes' : 'no') . ", assigned_user_id=" . ($conversation['assigned_user_id'] ?? 'null') . ", channel_flow_id=" . ($channel['flow_id'] ?? 'null'));
            if ($channel && empty($conversation['assigned_user_id']) && !in_array($conversation['status'], ['closed', 'resolved', 'spam'])) {
                if (!empty($channel['flow_id'])) {
                    $flow = \App\Models\Flow::find((int) $channel['flow_id']);
                    if ($flow && $flow['is_active']) {
                        $wasFlowActive = true;
                        error_log("FLOW_STARTED: conversation_id={$conversation['id']}, flow_id={$flow['id']}");
                        $flowEngine = new FlowEngineService();
                        $flowEngine->start($conversation['id'], $flow['id']);
                    } else {
                        error_log("FLOW_SKIPPED: flow_id={$channel['flow_id']} não encontrado ou inativo");
                    }
                } else {
                    error_log("FLOW_SKIPPED: canal sem flow_id definido");
                }
            }
        }

        // Marca contato como ativo.
        Contact::touchActivity($contact['id']);

        $this->logWebhook('STORED', ['message_id' => $msgId ?? null, 'conversation_id' => $conversation['id'] ?? null]);

        // Notifica o(s) usuário(s) responsável(eis) sobre a nova mensagem.
        // O NotificationService resolve automaticamente os destinatários
        // (atendente atribuído -> membros da caixa -> admins) e deduplica
        // reentradas do mesmo webhook.
        $preview = $type === 'text'
            ? (string) $content
            : ($message->caption ?: (['image' => '📷 Imagem', 'audio' => '🎵 Áudio', 'video' => '🎬 Vídeo', 'file' => '📎 Arquivo', 'sticker' => '🖼️ Sticker'][$type] ?? 'Mídia'));

        try {
            \App\Services\NotificationService::notifyNewMessage(
                (int) $conversation['id'],
                (int) $msgId,
                $preview
            );
        } catch (\Throwable $e) {
            $this->logWebhook('NOTIF_ERROR', ['error' => $e->getMessage()]);
        }

        // Mensagem de ausência fora do horário de funcionamento.
        try {
            (new \App\Services\ConversationService())->maybeSendAbsence((int) $conversation['id']);
        } catch (\Throwable $e) {
            $this->logWebhook('ABSENCE_ERROR', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sincroniza o nome e a foto de perfil do WhatsApp no contato.
     * Só preenche quando ainda não há valor (não sobrescreve dados manuais).
     */
    private function syncContactProfile(array $contact, IncomingMessage $message, string $providerName = 'uazapi'): void
    {
        $upd = [];

        // Preenche o nome se estiver vazio ou se for apenas o número do telefone
        // (valor automático usado na criação do contato via WhatsApp).
        $rawName = (string) $contact['name'];
        $rawPhone = (string) $contact['phone'];
        $autoName = $rawPhone !== '' && (
            $rawName === $rawPhone
            || preg_match('/^\d+@(c\.us|s\.whatsapp\.net|lid)$/', $rawName)
        );
        if ((empty($contact['name']) || $autoName) && !empty($message->senderName)) {
            $upd['name'] = $message->senderName;
        }

        if (empty($contact['avatar'])) {
            $originalFrom = $message->extra['original_from'] ?? '';
            if ($originalFrom !== '') {
                try {
                    $conn = WhatsAppConnection::findByProviderId($providerName, $message->providerId);
                    if ($conn) {
                        $picProvider = WhatsAppManager::forConnection($conn);
                        $picResult = $picProvider->getProfilePicture($conn, $originalFrom);
                        if ($picResult) {
                            if (str_starts_with($picResult, 'data:')) {
                                $raw = base64_decode(explode(',', $picResult, 2)[1] ?? '');
                                if ($raw !== '') {
                                    $ext = 'jpg';
                                    $dir = upload_dir() . '/avatars';
                                    if (!is_dir($dir)) @mkdir($dir, 0755, true);
                                    $name = bin2hex(random_bytes(12)) . '.' . $ext;
                                    if (file_put_contents($dir . '/' . $name, $raw) !== false) {
                                        $upd['avatar'] = 'avatars/' . $name;
                                    }
                                }
                            } else {
                                $local = \download_remote_image($picResult, 'avatars');
                                if ($local) {
                                    $upd['avatar'] = $local;
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('syncContactProfile getProfilePicture error: ' . $e->getMessage());
                }
            }
            if (empty($upd['avatar'] ?? null) && !empty($message->avatarUrl)) {
                $local = \download_remote_image($message->avatarUrl, 'avatars');
                if ($local) {
                    $upd['avatar'] = $local;
                }
            }
        }

        if ($upd) {
            Contact::update((int) $contact['id'], $upd);
        }
    }

    /**
     * Baixa a mídia recebida e a armazena localmente (uploads/messages),
     * retornando os metadados no mesmo formato usado no envio — assim ela
     * renderiza de forma confiável e persistente, independente do provedor.
     */
    private function resolveInboundMedia(object $provider, array $connection, IncomingMessage $message): ?array
    {
        $info = null;
        try {
            if (method_exists($provider, 'downloadMedia')) {
                $info = $provider->downloadMedia($connection, $message->messageId);
            }
        } catch (\Throwable $e) {
            $this->logWebhook('MEDIA_DOWNLOAD_ERR', ['error' => $e->getMessage(), 'mid' => $message->messageId]);
            $info = null;
        }

        $sourceUrl = ($info['fileURL'] ?? null);
        $base64 = ($info['base64'] ?? null);

        // Fallback: algumas configurações entregam um link direto no próprio webhook.
        if (!$sourceUrl && !$base64) {
            $sourceUrl = $message->mediaUrl;
        }

        // Fallback: WhatsApp CDN URL presente no _data.deprecatedMms3Url
        if (!$sourceUrl && !$base64 && !empty($message->extra['cdn_url'])) {
            $sourceUrl = $message->extra['cdn_url'];
        }

        $data = null;
        $size = 0;
        if (is_string($base64) && $base64 !== '') {
            $data = base64_decode($base64);
            $size = strlen($data);
        } elseif (is_string($sourceUrl) && preg_match('#^https?://#', $sourceUrl)) {
            $dl = \download_remote_file($sourceUrl, 0);
            if ($dl) {
                $data = $dl['data'];
                $size = $dl['size'];
            } else {
                $this->logWebhook('MEDIA_FETCH_FAIL', [
                    'mid' => $message->messageId,
                    'url' => $sourceUrl,
                    'had_fileURL' => !empty($info['fileURL']),
                ]);
            }
        }

        if ($data === null || $data === '') {
            return null;
        }

        // Valida se o conteúdo baixado corresponde ao tipo esperado
        $mime = ($info['mime'] ?? null) ?: $message->mediaMime;
        if (!$this->isValidContent($data, $mime, $message->type, $sourceUrl ?? '')) {
            $this->logWebhook('MEDIA_INVALID_CONTENT', [
                'mid' => $message->messageId,
                'type' => $message->type,
                'mime' => $mime,
                'size' => strlen($data),
                'url' => $sourceUrl ?? '',
            ]);
            return null;
        }

        $ext = $this->extFromMime((string) $mime) ?: ($this->extFromUrl($sourceUrl ?? '') ?: 'bin');

        $dir = upload_dir() . '/messages';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $newName = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $dir . '/' . $newName;
        if (file_put_contents($dest, $data) === false) {
            return null;
        }

        $realMime = mime_content_type($dest) ?: 'application/octet-stream';
        $name = ($info['name'] ?? null) ?: (basename((string) parse_url($sourceUrl ?? '', PHP_URL_PATH)) ?: ('arquivo.' . $ext));

        return [
            'type' => $message->type,
            'url' => 'messages/' . $newName,
            'name' => $name,
            'size' => $size,
            'mime' => $realMime,
        ];
    }

    private function isValidContent(string $data, ?string $expectedMime, string $msgType, string $sourceUrl): bool
    {
        if (strlen($data) < 12) {
            return false;
        }
        $first4 = substr($data, 0, 4);
        $magicMap = [
            "\xff\xd8\xff"      => ['image/jpeg', 'image'],
            "\x89\x50\x4e\x47"  => ['image/png', 'image'],
            "\x47\x49\x46\x38"  => ['image/gif', 'image'],
            "\x52\x49\x46\x46"  => ['image/webp', 'image'],
            "%PDF"              => ['application/pdf', 'file'],
            "PK\x03\x04"        => ['application/zip', 'file'],
        ];
        foreach ($magicMap as $magic => [$mimeMatch, $typeMatch]) {
            $len = strlen($magic);
            if (substr($data, 0, $len) === $magic) {
                if ($expectedMime && stripos($expectedMime, $mimeMatch) === false && stripos($mimeMatch, $expectedMime) === false) {
                    return false;
                }
                return true;
            }
        }
        if (in_array($msgType, ['image', 'sticker'], true)) {
            return false;
        }
        return true;
    }

    private function extFromMime(string $mime): ?string
    {
        static $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
            'application/pdf' => 'pdf', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/zip' => 'zip', 'text/plain' => 'txt',
        ];
        return $map[strtolower($mime)] ?? null;
    }

    private function extFromUrl(string $url): ?string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : null;
    }

    private function handleConnectionEvent(array $payload, string $providerName): void
    {
        $instanceRaw = $payload['instance'] ?? null;
        $instanceName = is_array($instanceRaw) ? ($instanceRaw['name'] ?? '') : '';
        $providerId = (string) (
            $payload['session']
            ?? $payload['instanceName']
            ?? $instanceName
            ?? $instanceRaw
            ?? ($payload['data']['session'] ?? ($payload['data']['instanceName'] ?? ($payload['data']['name'] ?? ($payload['data']['instance'] ?? ''))))
        );
        if (!$providerId) {
            return;
        }

        $connection = WhatsAppConnection::findByProviderId($providerName, $providerId);
        if (!$connection) {
            $this->logWebhook('NO_CONNECTION', ['providerId' => $providerId, 'provider' => $providerName]);
            return;
        }

        // O provedor costuma enviar o estado em `state`/`status` (no payload, payload.payload ou em `data`).
        $raw = strtolower((string) (
            $payload['instance']['status'] ?? $payload['state'] ?? $payload['status']
            ?? ($payload['payload']['state'] ?? ($payload['payload']['status'] ?? ''))
            ?? ($payload['data']['state'] ?? ($payload['data']['status'] ?? ''))
        ));

        $status = match (true) {
            // IMPORTANTE: "disconnected" contém "connect", então precisa vir ANTES de "connected"
            $raw === 'failed' || $raw === 'stopped' || str_contains($raw, 'disconnect') || $raw === 'close' || $raw === 'offline'
                => 'disconnected',
            $raw === '' || $raw === 'working' || str_contains($raw, 'connect') || $raw === 'open' || $raw === 'authenticated' || $raw === 'online'
                => 'connected',
            $raw === 'starting' || str_contains($raw, 'qr') || str_contains($raw, 'pair') || str_contains($raw, 'wait') || $raw === 'connecting'
                => 'waiting_qr',
            default => null,
        };

        if ($status === null) {
            return;
        }

        $update = ['status' => $status];

        // Salva o token da instância vindo no webhook (Uazapi envia token)
        $token = $payload['token'] ?? ($payload['data']['token'] ?? '');
        if ($token && empty($connection['instance_token'])) {
            $update['instance_token'] = $token;
        }

        // Salva o phone_number do owner (disponível no webhook da Uazapi em instance.owner)
        $instanceRawOwner = is_array($payload['instance'] ?? null) ? ($payload['instance']['owner'] ?? null) : null;
        $owner = $instanceRawOwner ?? $payload['owner'] ?? ($payload['me']['id'] ?? ($payload['data']['owner'] ?? ($payload['chat']['owner'] ?? null)));
        if ($owner && empty($connection['phone_number'])) {
            $ownerNorm = preg_replace('/\D/', '', (string) $owner);
            if ($ownerNorm !== '') {
                $update['phone_number'] = $ownerNorm;
            }
        }

        if ($status === 'connected') {
            $update['last_connected_at'] = date('Y-m-d H:i:s');
            $update['error_message'] = null;
            try {
                $this->configureWebhook($connection);
            } catch (\Throwable $e) {
                $this->logWebhook('CONFIG_WEBHOOK_ERR', ['error' => $e->getMessage()]);
            }
        } elseif ($status === 'waiting_qr') {
            $qr = $payload['instance']['qrcode'] ?? $payload['qrcode'] ?? ($payload['qrCode'] ?? ($payload['data']['qrcode'] ?? null));
            if ($qr) {
                $update['qr_code'] = is_string($qr) ? $qr : json_encode($qr);
            }
        }

        WhatsAppConnection::update((int) $connection['id'], $update);
        $this->logWebhook('CONNECTION', ['providerId' => $providerId, 'status' => $status]);
    }

    private function logWebhook(string $stage, array $ctx = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] WH [$stage] " . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents(dirname(__DIR__, 2) . '/storage/logs/webhook.log', $line, FILE_APPEND);
    }

    /**
     * Envia uma mensagem outbound de uma conversa pelo provedor conectado.
     * O registro da mensagem já existe no banco; aqui apenas entregamos ao WhatsApp
     * e gravamos o channel_message_id.
     */
    public function sendOutbound(int $conversationId, int $messageId, string $type, string $content): ?string
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            return null;
        }

        $channel = Database::getInstance()->fetch(
            "SELECT ch.* FROM channels ch WHERE ch.id = ?",
            [$conversation['channel_id']]
        );
        if (!$channel || $channel['type'] !== 'whatsapp') {
            return null;
        }

        $connection = WhatsAppConnection::findByChannel((int) $channel['id']);
        if (!$connection) {
            return null;
        }

        // Sincroniza status rapidamente se estiver desconectado
        if ($connection['status'] !== 'connected') {
            try {
                $state = WhatsAppManager::forConnection($connection)->getStatus($connection);
                if ($state['status'] === 'connected') {
                    $connection['status'] = 'connected';
                    $connection['phone_number'] = $state['phone_number'] ?? $connection['phone_number'];
                    WhatsAppConnection::update((int) $connection['id'], [
                        'status' => 'connected',
                        'phone_number' => $connection['phone_number'],
                        'last_connected_at' => date('Y-m-d H:i:s'),
                        'error_message' => null,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log("sendOutbound: {$conversationId}/{$messageId} getStatus falhou: " . $e->getMessage());
            }
        }

        // Tenta enviar mesmo se status não for 'connected'; o provider retornará erro se offline.
        if ($connection['status'] !== 'connected') {
            error_log("sendOutbound: {$conversationId}/{$messageId} tentando enviar mesmo com status={$connection['status']}");
        }

        $contact = Contact::find((int) $conversation['contact_id']);
        if (!$contact || empty($contact['phone'])) {
            error_log("sendOutbound: {$conversationId}/{$messageId} contato sem telefone");
            return null;
        }

        $provider = WhatsAppManager::forConnection($connection);

        $options = [];
        if ($type !== 'text') {
            $meta = json_decode($content, true);
            $options['mimetype'] = $meta['mime'] ?? null;
            $options['caption'] = $meta['name'] ?? null;
        }

        try {
            if ($type === 'button_list' && method_exists($provider, 'sendButton')) {
                $meta = json_decode($content, true) ?: ['text' => '', 'buttons' => []];
                $result = $provider->sendButton($connection, $contact['phone'], $meta['text'] ?? '', $meta['buttons'] ?? []);
                if (empty($result['provider_message_id'])) {
                    error_log("sendOutbound: {$conversationId}/{$messageId} sendButton falhou, fallback para texto");
                    $optionLabels = array_column($meta['buttons'] ?? [], 'label');
                    $fallbackText = ($meta['text'] ?? '') . "\n\n" . implode("\n", array_map(fn($i, $l) => ($i+1) . ' - ' . $l, array_keys($optionLabels), $optionLabels));
                    $result = $provider->send($connection, $contact['phone'], 'text', $fallbackText, []);
                }
            } elseif ($type === 'list_menu' && method_exists($provider, 'sendList')) {
                $meta = json_decode($content, true) ?: ['text' => '', 'title' => '', 'items' => []];
                $result = $provider->sendList($connection, $contact['phone'], $meta['text'] ?? '', $meta['title'] ?? '', $meta['items'] ?? []);
                if (empty($result['provider_message_id'])) {
                    error_log("sendOutbound: {$conversationId}/{$messageId} sendList falhou, fallback para texto");
                    $optionLabels = array_column($meta['items'] ?? [], 'label');
                    $fallbackText = ($meta['text'] ?? '') . "\n\n" . ($meta['title'] ?? 'Opções') . ":\n" . implode("\n", array_map(fn($i, $l) => ($i+1) . ' - ' . $l, array_keys($optionLabels), $optionLabels));
                    $result = $provider->send($connection, $contact['phone'], 'text', $fallbackText, []);
                }
            } else {
                $result = $provider->send($connection, $contact['phone'], $type, $content, $options);
            }
        } catch (\Throwable $e) {
            error_log("sendOutbound: {$conversationId}/{$messageId} exception: " . $e->getMessage());
            return null;
        }

        $providerMessageId = $result['provider_message_id'] ?? null;
        if (!$providerMessageId) {
            $raw = $result['raw'] ?? [];
            $errMsg = is_array($raw) ? (json_encode($raw, JSON_UNESCAPED_UNICODE)) : (string) $raw;
            error_log("sendOutbound: {$conversationId}/{$messageId} sem provider_message_id. Resposta: {$errMsg}");
        }
        if ($providerMessageId) {
            Database::getInstance()->update(
                'messages',
                ['channel_message_id' => $providerMessageId],
                'id = ?',
                [$messageId]
            );
        }

        return $providerMessageId;
    }

    /**
     * Envia uma mensagem de texto diretamente para um número de telefone,
     * sem vincular a uma conversa existente. Útil para notificações de fluxo.
     */
    public function sendToPhone(int $channelId, string $phone, string $text): ?string
    {
        $channel = Database::getInstance()->fetch(
            "SELECT ch.* FROM channels ch WHERE ch.id = ? AND ch.type = 'whatsapp'",
            [$channelId]
        );
        if (!$channel) {
            error_log("sendToPhone: channel {$channelId} não encontrado ou não é WhatsApp");
            return null;
        }

        $connection = WhatsAppConnection::findByChannel($channelId);
        if (!$connection) {
            error_log("sendToPhone: conexão WhatsApp não encontrada para channel {$channelId}");
            return null;
        }

        if ($connection['status'] !== 'connected') {
            error_log("sendToPhone: conexão WhatsApp não está conectada (status={$connection['status']})");
            return null;
        }

        try {
            $provider = WhatsAppManager::forConnection($connection);
            $result = $provider->send($connection, $phone, 'text', $text, []);
            $providerMessageId = $result['provider_message_id'] ?? null;
            if (!$providerMessageId) {
                error_log("sendToPhone: sem provider_message_id para {$phone}");
            }
            return $providerMessageId;
        } catch (\Throwable $e) {
            error_log("sendToPhone: erro ao enviar para {$phone}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Edita no WhatsApp uma mensagem de texto outbound já enviada.
     * Não quebra a operação local caso o provedor falhe (ex.: fora da janela de edição).
     */
    public function editMessage(int $conversationId, int $messageId, string $newText): bool
    {
        $connection = $this->resolveOutboundTextConnection($conversationId, $messageId);
        if ($connection === null) {
            return false;
        }
        [$conn, $waId] = $connection;
        try {
            return WhatsAppManager::forConnection($conn)->editMessage($conn, $waId, $newText);
        } catch (\Throwable $e) {
            error_log('WhatsApp editMessage error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Apaga no WhatsApp (para todos) uma mensagem outbound já enviada.
     * Não quebra a operação local caso o provedor falhe.
     */
    public function deleteMessage(int $conversationId, int $messageId): bool
    {
        $connection = $this->resolveOutboundTextConnection($conversationId, $messageId);
        if ($connection === null) {
            return false;
        }
        [$conn, $waId] = $connection;
        try {
            return WhatsAppManager::forConnection($conn)->deleteMessage($conn, $waId);
        } catch (\Throwable $e) {
            error_log('WhatsApp deleteMessage error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envia uma reação (emoji) a uma mensagem do WhatsApp.
     */
    public function sendReaction(int $conversationId, int $messageId, string $reaction): bool
    {
        $connection = $this->resolveOutboundTextConnection($conversationId, $messageId);
        if ($connection === null) {
            // Tenta resolver como inbound também (reagir a mensagens recebidas)
            $conn = $this->resolveReactionConnection($conversationId, $messageId);
            if ($conn === null) {
                return false;
            }
            [$conn, $waId] = $conn;
        } else {
            [$conn, $waId] = $connection;
        }

        try {
            return WhatsAppManager::forConnection($conn)->sendReaction($conn, $waId, $reaction);
        } catch (\Throwable $e) {
            error_log('WhatsApp sendReaction error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve conexão para mensagens inbound (recebidas).
     */
    private function resolveReactionConnection(int $conversationId, int $messageId): ?array
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            return null;
        }
        $channel = Database::getInstance()->fetch(
            "SELECT ch.* FROM channels ch WHERE ch.id = ?",
            [$conversation['channel_id']]
        );
        if (!$channel || $channel['type'] !== 'whatsapp') {
            return null;
        }
        $msg = Conversation::getMessage($messageId);
        if (!$msg || ($msg['conversation_id'] ?? null) != $conversationId) {
            return null;
        }
        $waId = $msg['channel_message_id'] ?? '';
        if ($waId === '') {
            return null;
        }
        $conn = WhatsAppConnection::findByChannel((int) $channel['id']);
        if (!$conn) {
            return null;
        }
        return [$conn, $waId];
    }

    /**
     * Resolve a conexão WhatsApp e o ID do provedor para uma mensagem outbound de texto.
     * Retorna null quando a edição/exclusão remota não é aplicável.
     *
     * @return array{0:array,1:string}|null [conexão, channel_message_id]
     */
    private function resolveOutboundTextConnection(int $conversationId, int $messageId): ?array
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            return null;
        }
        $channel = Database::getInstance()->fetch(
            "SELECT ch.* FROM channels ch WHERE ch.id = ?",
            [$conversation['channel_id']]
        );
        if (!$channel || $channel['type'] !== 'whatsapp') {
            return null;
        }
        $msg = Conversation::getMessage($messageId);
        if (!$msg
            || ($msg['conversation_id'] ?? null) != $conversationId
            || ($msg['direction'] ?? null) !== 'outbound'
            || ($msg['type'] ?? null) !== 'text'
        ) {
            return null;
        }
        $waId = $msg['channel_message_id'] ?? null;
        if (!$waId) {
            return null;
        }
        $connection = WhatsAppConnection::findByChannel((int) $channel['id']);
        if (!$connection) {
            return null;
        }
        return [$connection, $waId];
    }

    // ----------------------------------------------------------------

    private function resolveProviderFromPayload(array $payload): string
    {
        if (!empty($payload['provider']) && is_string($payload['provider'])) {
            return $payload['provider'];
        }
        // Uazapi envia BaseUrl nos webhooks (ex.: https://free.uazapi.com)
        if (!empty($payload['BaseUrl']) && is_string($payload['BaseUrl'])) {
            return 'uazapi';
        }
        // Uazapi usa nomes de evento específicos
        $event = $payload['event'] ?? $payload['EventType'] ?? '';
        if (in_array($event, ['connection', 'messages', 'qrcode', 'connections.update', 'messages.upsert', 'qrcode.updated'], true)) {
            return 'uazapi';
        }
        return WhatsAppManager::defaultProviderName();
    }

    private function findOrCreateConversation(int $channelId, int $contactId): array
    {
        $existing = Database::getInstance()->fetch(
            "SELECT c.* FROM conversations c
             WHERE c.channel_id = ? AND c.contact_id = ?
               AND c.status NOT IN ('closed', 'resolved', 'spam')
             ORDER BY c.last_message_at DESC LIMIT 1",
            [$channelId, $contactId]
        );
        if ($existing) {
            return $existing;
        }

        $channel = Database::getInstance()->fetch("SELECT * FROM channels WHERE id = ?", [$channelId]);
        $departmentId = $channel['department_id'] ?? null;

        // Cria a conversa já vinculada ao contato real resolvido no webhook
        // (não cria um contato vazio novo a cada mensagem).
        $conversationId = Conversation::create([
            'contact_id' => $contactId,
            'channel_id' => $channelId,
            'department_id' => $departmentId,
            'inbox_id' => \App\Models\Inbox::resolveInboxForChannel($channelId),
            'subject' => null,
            'status' => 'new',
            'source' => 'whatsapp',
        ]);

        Conversation::addEvent($conversationId, 'created', 'Conversa criada automaticamente (WhatsApp)');

        return Conversation::find($conversationId);
    }
}
