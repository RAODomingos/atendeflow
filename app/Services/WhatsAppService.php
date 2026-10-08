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
            // Nunca apaga número salvo com null: provedor pode omitir o owner.
            if (!empty($state['phone_number'])) {
                $newNorm = preg_replace('/\D/', '', (string) $state['phone_number']);
                $curNorm = preg_replace('/\D/', '', (string) ($connection['phone_number'] ?? ''));
                if ($newNorm !== '' && $newNorm !== $curNorm) {
                    error_log("WhatsApp phone_number atualizado ({$curNorm} -> {$newNorm}) no status poll.");
                }
                if ($newNorm !== '') {
                    $update['phone_number'] = $newNorm;
                }
            }
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
    public function handleWebhook(array $payload, string $rawBody = ''): void
    {
        $this->logWebhook('RECEIVED', [
            'event' => $payload['EventType'] ?? $payload['event'] ?? null,
            'has_message' => isset($payload['message']),
            'has_data' => isset($payload['data']),
            'has_payload' => isset($payload['payload']),
        ]);

        $providerName = $this->resolveProviderFromPayload($payload);
        $provider = WhatsAppManager::provider($providerName);

        // Eventos de conexão/status da instância (EventType: connection, qrcode, status...).
        $event = strtolower((string) ($payload['EventType'] ?? $payload['event'] ?? ($payload['data']['event'] ?? '')));
        if (in_array($event, ['connection', 'connections.update', 'status', 'qrcode', 'qrcode.update', 'qrcode.updated', 'state', 'session.status'], true)) {
            $this->handleConnectionEvent($payload, $providerName, $rawBody);
            return;
        }

        $message = $provider->parseWebhook($payload);
        if (!$message) {
            $dbgMsg = $payload['data']['message'] ?? $payload['message'] ?? [];
            $this->logWebhook('FILTERED', [
                'reason' => 'parseWebhook retornou null (fromMe/evento nao-mensagem)',
                'event' => $payload['EventType'] ?? $payload['event'] ?? null,
                'fromMe' => $dbgMsg['fromMe'] ?? null,
                'wasSentByApi' => $dbgMsg['wasSentByApi'] ?? null,
                'isGroup' => $dbgMsg['isGroup'] ?? null,
                'chatid' => $dbgMsg['chatid'] ?? null,
                'messageType' => $dbgMsg['messageType'] ?? ($payload['data']['messageType'] ?? null),
            ]);
            return;
        }

        $this->logWebhook('PARSED', [
            'providerId' => $message->providerId,
            'from' => $message->from,
            'type' => $message->type,
            'content' => substr($message->content, 0, 120),
        ]);

        // Aprende pares LID -> telefone do payload (essencial p/ menções em
        // grupo, onde tudo chega como @lid). Provedores nunca tocam o banco.
        $this->learnLidMappings($payload);

        $connection = WhatsAppConnection::findByProviderId($providerName, $message->providerId);
        if (!$connection) {
            $this->logWebhook('NO_CONNECTION', ['providerId' => $message->providerId, 'provider' => $providerName]);
            return;
        }

        // Validação do segredo do webhook: ?secret= (legado) ou HMAC-SHA256
        // do corpo no header X-Webhook-Signature. Obrigatório quando a
        // conexão tem webhook_secret configurado.
        if (!$this->validateWebhookSecret($connection, $rawBody)) {
            $this->logWebhook('SECRET_FAIL', ['providerId' => $message->providerId]);
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

        // Mensagens de grupo: caixa de grupos (sem criar conversa no inbox).
        if (!empty($message->extra['is_group'])) {
            $this->handleGroupMessage($connection, $message);
            return;
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
                    'url'  => $resolved['url'],
                    'name' => $resolved['name'],
                    'size' => $resolved['size'],
                    'mime' => $resolved['mime'],
                    'path' => $resolved['path'] ?? null,
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
        } elseif ($message->type === 'contact') {
            // Cartão de contato (vCard): content já é JSON {name,phone,organization?}.
            $type = 'contact';
            $content = $message->content !== '' ? $message->content : json_encode(['name' => '', 'phone' => ''], JSON_UNESCAPED_UNICODE);
        } else {
            $type = 'text';
            $content = $message->content;
        }

        // Citação recebida: quoted_id do provedor → mensagem local citada.
        $replyToLocal = $this->resolveInboundReplyTo((int) $conversation['id'], (string) ($message->extra['quoted_id'] ?? ''));

        $msgData = [
            'type' => $type,
            'content' => $content,
            'direction' => 'inbound',
            'channel_message_id' => $message->messageId,
        ];
        if ($replyToLocal) {
            $msgData['reply_to'] = $replyToLocal;
        }
        $msgId = Conversation::addMessage($conversation['id'], $msgData);

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
            if ($channel && $this->shouldAutoStartFlow($conversation, $channel)) {
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
            : ($type === 'contact'
                ? ('📇 Contato: ' . ((json_decode((string) $content, true)['name'] ?? '') ?: 'sem nome'))
                : ($message->caption ?: (['image' => '📷 Imagem', 'audio' => '🎵 Áudio', 'video' => '🎬 Vídeo', 'file' => '📎 Arquivo', 'sticker' => '🖼️ Sticker'][$type] ?? 'Mídia')));

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
     * Decide se uma mensagem inbound pode (re)iniciar o fluxo do canal.
     * Regra: fluxo só inicia em conversa NOVA (status 'new', sem responsável
     * e sem passagem anterior por fluxo). Conversa Aberta / em atendimento
     * (open, waiting_*) ou com tag 'Aberto' / histórico de fluxo fica com
     * o humano e NUNCA volta ao bot sozinha.
     */
    private function shouldAutoStartFlow(array $conversation, array $channel): bool
    {
        if (empty($channel['flow_id'])) {
            return false;
        }
        // Com responsável → dono humano.
        if (!empty($conversation['assigned_user_id'])) {
            return false;
        }
        // Finalizadas → nunca.
        if (in_array($conversation['status'] ?? '', ['closed', 'resolved', 'spam'], true)) {
            return false;
        }
        // Em atendimento (Aberta / aguardando) → humano, nunca o bot.
        if (in_array($conversation['status'] ?? '', ['open', 'waiting_customer', 'waiting_internal'], true)) {
            error_log("FLOW_SKIPPED: conversation_id={$conversation['id']} em atendimento (status={$conversation['status']})");
            return false;
        }
        // Já passou pelo fluxo antes (ativo ou finalizado) → não repete.
        try {
            $hist = Database::getInstance()->fetch(
                "SELECT 1 FROM conversation_flow_states WHERE conversation_id = ? LIMIT 1",
                [$conversation['id']]
            );
            if ($hist) {
                error_log("FLOW_SKIPPED: conversation_id={$conversation['id']} já executou fluxo antes");
                return false;
            }
        } catch (\Throwable $e) {
            // Tabela ausente em installs antigos: segue para checagem de tag.
        }
        // Tag 'Aberto' (fluxo encerrado aguardando humano) → não repete.
        try {
            $tag = \App\Models\Tag::findByName('Aberto');
            if ($tag) {
                $has = Database::getInstance()->fetch(
                    "SELECT 1 FROM conversation_tags WHERE conversation_id = ? AND tag_id = ? LIMIT 1",
                    [$conversation['id'], $tag['id']]
                );
                if ($has) {
                    error_log("FLOW_SKIPPED: conversation_id={$conversation['id']} com tag Aberto");
                    return false;
                }
            }
        } catch (\Throwable $e) {
        }
        return true;
    }

    /**
     * Caixa de grupos: registra o grupo (upsert), espelha a conversa na caixa
     * selecionada na gestão de grupos (grupo.inbox_id ou caixa do canal) e,
     * quando alguém marcar o número da conexão, grava a menção e dispara
     * alerta no sino para os membros daquela caixa.
     */
    public function handleGroupMessage(array $connection, IncomingMessage $message): void
    {
        $extra = $message->extra;
        $groupJid = (string) ($extra['group_jid'] ?? '');
        if ($groupJid === '') {
            $this->logWebhook('GROUP_NO_JID', ['from' => $message->from]);
            return;
        }

        $group = \App\Models\WhatsAppGroup::upsertFromWebhook((int) $connection['id'], $groupJid, [
            'name' => $extra['group_name'] ?? null,
            'avatar_url' => $message->avatarUrl,
            'participant_count' => $extra['participant_count'] ?? null,
        ]);

        $connDigits = preg_replace('/\D/', '', (string) ($connection['phone_number'] ?? ''));
        $senderDigits = \App\Models\WhatsAppLidMap::resolve((string) $message->from) ?? '';
        $isSelf = $connDigits !== '' && $senderDigits !== '' && self::samePhoneDigits($connDigits, $senderDigits);

        $text = $message->type === 'text' ? (string) $message->content : (string) ($message->caption ?? '');
        if ($text === '' && $message->type !== 'text') {
            $text = '[' . $message->type . ']';
        }

        // Reação em grupo: atualiza o balão da mensagem original (igual no 1:1),
        // sem criar mensagem nova, sem unread e sem sino.
        if ($message->type === 'text' && str_starts_with($text, '{"reaction":')) {
            $this->handleGroupReaction($connection, $group, $message, $text);
            return;
        }

        // Detecta menção ANTES de espelhar: só menção gera não-lida + sino.
        // Mensagem comum de grupo entra na conversa como lida (histórico sem
        // contador, sem som, sem toast).
        $isMention = false;
        $mentionEveryone = false;
        if (!$isSelf && !empty($group['mention_alert'])) {
            $rawMentioned = (array) ($extra['mentioned'] ?? []);
            $mentioned = $this->resolveMentionedPhones($connection, $rawMentioned);
            $mentionEveryone = self::isEveryoneMentioned($rawMentioned, $text);
            $isMention = $mentionEveryone || self::connectionMentioned($connDigits, $mentioned, $text);
            if (!$isMention && !$mentionEveryone && !empty($rawMentioned)) {
                // Menção como @lid sem mapeamento: busca participantes do grupo
                // UMA vez, aprende os pares e tenta de novo (self-healing).
                $learned = $this->learnGroupLidMappings($connection, $groupJid);
                if ($learned > 0) {
                    $mentioned = $this->resolveMentionedPhones($connection, $rawMentioned);
                    $isMention = self::connectionMentioned($connDigits, $mentioned, $text);
                }
            }
            if (!$isMention) {
                // Diagnóstico: menção perdida — registra por que não detectou.
                $this->logWebhook('GROUP_MENTION_MISS', [
                    'group_id' => $group['id'] ?? null,
                    'conn_empty' => $connDigits === '',
                    'mentioned_raw' => count($rawMentioned),
                    'mentioned_resolved' => count($mentioned),
                    'has_at' => str_contains($text, '@'),
                ]);
            }
        } elseif (!$isSelf && empty($group['mention_alert'])) {
            $this->logWebhook('GROUP_MENTION_OFF', ['group_id' => $group['id'] ?? null]);
        }

        // 1) Espelha na caixa selecionada (toda msg de grupo vira mensagem da
        // conversa do grupo — é assim que aparece no inbox).
        $conversation = null;
        try {
            $conversation = $this->ensureGroupConversation($connection, $group, $text);
        } catch (\Throwable $e) {
            $this->logWebhook('GROUP_CONV_ERROR', ['error' => $e->getMessage(), 'group_id' => $group['id'] ?? null]);
        }
        if ($conversation) {
            try {
                $providerMsgId = $message->messageId !== ''
                    ? $message->messageId
                    : ('g' . md5($groupJid . '|' . $senderDigits . '|' . $text . '|' . ($message->timestamp ?? time())));
                $dup = Database::getInstance()->fetch(
                    "SELECT id FROM messages WHERE conversation_id = ? AND channel_message_id = ? LIMIT 1",
                    [(int) $conversation['id'], $providerMsgId]
                );
                if (!$dup) {
                    $groupReplyTo = $this->resolveInboundReplyTo((int) $conversation['id'], (string) ($extra['quoted_id'] ?? ''));
                    $groupMsgData = [
                        'type' => 'text',
                        'content' => $text !== '' ? $text : ('[' . $message->type . ']'),
                        'direction' => $isSelf ? 'outbound' : 'inbound',
                        'channel_message_id' => $providerMsgId,
                        'sender_name' => $message->senderName ?: null,
                        'sender_phone' => $senderDigits ?: null,
                        // Só menção fica não-lida (contador/sino). Comum = lida.
                        'is_read' => ($isMention && !$isSelf) ? 0 : 1,
                        'read_at' => ($isMention && !$isSelf) ? null : date('Y-m-d H:i:s'),
                    ];
                    if ($groupReplyTo) {
                        $groupMsgData['reply_to'] = $groupReplyTo;
                    }
                    Conversation::addMessage((int) $conversation['id'], $groupMsgData);
                }
            } catch (\Throwable $e) {
                $this->logWebhook('GROUP_MSG_ERROR', ['error' => $e->getMessage()]);
            }
        }

        // Mensagem do próprio número: só aprende o grupo + espelha, sem alerta.
        if ($isSelf) {
            $this->logWebhook('GROUP_SELF', ['group_jid' => $groupJid]);
            return;
        }

        if (!$isMention) {
            return;
        }

        $providerMsgId = $message->messageId !== ''
            ? $message->messageId
            : ('g' . md5($groupJid . '|' . $senderDigits . '|' . $text . '|' . ($message->timestamp ?? time())));
        $mention = [
            'sender_name' => $message->senderName,
            'sender_phone' => $senderDigits,
            'content' => $text !== '' ? $text : ('[' . $message->type . ']'),
            'provider_message_id' => $providerMsgId,
            'mentioned_digits' => $connDigits,
            'conversation_id' => $conversation ? (int) $conversation['id'] : null,
            'mention_kind' => $mentionEveryone ? 'everyone' : 'direct',
        ];
        $mentionId = \App\Models\WhatsAppGroup::logMention((int) $group['id'], $mention);
        if (!$mentionId) {
            return; // webhook duplicado
        }
        $this->logWebhook('GROUP_MENTION', ['group_id' => $group['id'], 'mention_id' => $mentionId]);

        try {
            \App\Services\NotificationService::notifyGroupMention($group, $connection, $mention);
        } catch (\Throwable $e) {
            $this->logWebhook('GROUP_NOTIF_ERROR', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve a mensagem local citada por uma resposta recebida: busca o
     * channel_message_id do provedor dentro da conversa. Retorna o id
     * local (para reply_to) ou null.
     */
    private function resolveInboundReplyTo(int $conversationId, string $quotedProviderId): ?int
    {
        if ($quotedProviderId === '') {
            return null;
        }
        try {
            $parent = Database::getInstance()->fetch(
                "SELECT id FROM messages WHERE conversation_id = ? AND channel_message_id = ? LIMIT 1",
                [$conversationId, $quotedProviderId]
            );
            return $parent ? (int) $parent['id'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Reação recebida numa conversa de grupo: aplica/remova o emoji no
     * balão original (busca por channel_message_id dentro da conversa do
     * grupo). Nunca cria mensagem, nunca conta unread, nunca notifica.
     */
    private function handleGroupReaction(array $connection, array $group, IncomingMessage $message, string $text): void
    {
        $meta = json_decode($text, true);
        $reaction = (string) ($meta['reaction'] ?? '');
        $parentMsgId = (string) ($meta['parent_message_id'] ?? '');
        if ($parentMsgId === '') {
            $this->logWebhook('GROUP_REACTION_NO_PARENT', ['group_id' => $group['id'] ?? null]);
            return;
        }
        $conv = Database::getInstance()->fetch(
            "SELECT id FROM conversations WHERE group_id = ? AND status NOT IN ('closed','resolved','spam') ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT 1",
            [(int) ($group['id'] ?? 0)]
        );
        if (!$conv) {
            $this->logWebhook('GROUP_REACTION_NO_CONV', ['group_id' => $group['id'] ?? null]);
            return;
        }
        $parentMsg = Database::getInstance()->fetch(
            "SELECT id, reactions FROM messages WHERE channel_message_id = ? AND conversation_id = ? LIMIT 1",
            [$parentMsgId, (int) $conv['id']]
        );
        if (!$parentMsg) {
            $this->logWebhook('GROUP_REACTION_PARENT_NOT_FOUND', ['parent_channel_msg_id' => $parentMsgId]);
            return;
        }
        $senderDigits = \App\Models\WhatsAppLidMap::resolve((string) $message->from) ?? '';
        $existing = json_decode((string) ($parentMsg['reactions'] ?? '[]'), true) ?: [];
        // Mesma semântica do 1:1: troca a reação do mesmo remetente.
        $existing = array_values(array_filter($existing, fn($r) => ($r['from'] ?? '') !== $senderDigits));
        if ($reaction !== '') {
            $existing[] = [
                'emoji' => $reaction,
                'from' => $senderDigits,
                'sender_name' => $message->senderName ?? '',
                'sender_phone' => $senderDigits,
                'timestamp' => time(),
            ];
        }
        Database::getInstance()->update('messages', [
            'reactions' => json_encode($existing, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$parentMsg['id']]);
        $this->logWebhook('GROUP_REACTION_UPDATED', ['parent_msg_id' => $parentMsg['id'], 'emoji' => $reaction]);
    }

    /**
     * Garante contato + conversa persistente do grupo na caixa selecionada:
     * grupo.inbox_id (escolhida na gestão de grupos) ou caixa do canal.
     * Uma conversa por grupo (reabre se fechada). Atualiza inbox/assunto
     * quando a gestão trocar a caixa ou o nome do grupo mudar.
     */
    private function ensureGroupConversation(array $connection, array $group, string $lastText = ''): ?array
    {
        $db = Database::getInstance();
        $groupId = (int) $group['id'];
        $channelId = (int) $connection['channel_id'];
        $groupName = trim((string) ($group['name'] ?? '')) !== '' ? (string) $group['name'] : 'Grupo WhatsApp';
        $groupJid = (string) ($group['group_jid'] ?? '');

        $targetInbox = !empty($group['inbox_id'])
            ? (int) $group['inbox_id']
            : \App\Models\Inbox::resolveInboxForChannel($channelId);

        // Contato sintético do grupo (phone = JID para não colidir com 1:1).
        $contact = $groupJid !== '' ? $db->fetch("SELECT * FROM contacts WHERE phone = ? LIMIT 1", [$groupJid]) : null;
        if (!$contact) {
            $contactId = Contact::create(['name' => $groupName, 'phone' => $groupJid ?: null]);
            $contact = Contact::find($contactId);
        } elseif (!empty($group['name']) && ($contact['name'] === null || $contact['name'] === '' || $contact['name'] !== $group['name'])) {
            // Mantém o nome do contato sincronizado com o nome do grupo.
            Contact::update((int) $contact['id'], ['name' => $groupName]);
            $contact['name'] = $groupName;
        }
        if (!$contact) {
            return null;
        }

        $conv = $db->fetch(
            "SELECT * FROM conversations WHERE group_id = ? AND status NOT IN ('closed','resolved','spam') ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT 1",
            [$groupId]
        );
        if ($conv) {
            $upd = [];
            if ($targetInbox && (int) ($conv['inbox_id'] ?? 0) !== $targetInbox) {
                $upd['inbox_id'] = $targetInbox;
            }
            if (!empty($group['name']) && ($conv['subject'] ?? '') !== $group['name']) {
                $upd['subject'] = $group['name'];
            }
            if ((int) ($conv['contact_id'] ?? 0) !== (int) $contact['id']) {
                $upd['contact_id'] = (int) $contact['id'];
            }
            if ($upd) {
                Conversation::update((int) $conv['id'], $upd);
                $conv = array_merge($conv, $upd);
            }
            Contact::touchActivity((int) $contact['id']);
            return Conversation::find((int) $conv['id']) ?: $conv;
        }

        $channel = $db->fetch("SELECT * FROM channels WHERE id = ?", [$channelId]);
        $conversationId = Conversation::create([
            'contact_id' => (int) $contact['id'],
            'channel_id' => $channelId,
            'department_id' => $channel['department_id'] ?? null,
            'inbox_id' => $targetInbox,
            'group_id' => $groupId,
            'subject' => $groupName,
            'status' => 'new',
            'source' => 'whatsapp_group',
        ]);
        Conversation::addEvent($conversationId, 'created', 'Conversa do grupo criada automaticamente (' . $groupName . ')');
        Contact::touchActivity((int) $contact['id']);
        return Conversation::find($conversationId);
    }

    /**
     * Compara dígitos de telefone pelo sufixo (tolerando DDI/DDD e 9º dígito).
     */
    public static function samePhoneDigits(string $a, string $b, int $minLen = 8): bool
    {
        $a = preg_replace('/\D/', '', $a);
        $b = preg_replace('/\D/', '', $b);
        if (strlen($a) < $minLen || strlen($b) < $minLen) {
            return $a !== '' && $a === $b;
        }
        $n = min(strlen($a), strlen($b), 11);
        return substr($a, -$n) === substr($b, -$n);
    }

    /**
     * Verifica se a conexão foi mencionada: lista explícita do provedor OU
     * "@<dígitos>" no texto. LIDs são resolvidos via mapa aprendido.
     * "@todos"/"@all"/"@everyone" menciona todo mundo — inclui a conexão.
     */
    public static function connectionMentioned(string $connDigits, array $mentioned, string $text): bool
    {
        if (self::isEveryoneMentioned($mentioned, $text)) {
            return true;
        }
        $connDigits = preg_replace('/\D/', '', $connDigits);
        if ($connDigits === '') {
            return false;
        }
        foreach ($mentioned as $m) {
            $resolved = \App\Models\WhatsAppLidMap::resolve((string) $m) ?? (string) $m;
            if (self::samePhoneDigits($connDigits, $resolved)) {
                return true;
            }
        }
        // Fallback textual: "@5511999998888" ou "@11999998888" (ou LID).
        if (preg_match_all('/@(\d{8,16})/', $text, $mm)) {
            foreach ($mm[1] as $digits) {
                $resolved = \App\Models\WhatsAppLidMap::resolve($digits) ?? $digits;
                if (self::samePhoneDigits($connDigits, $resolved)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * "@todos"/"@all"/"@everyone" no texto ou na lista do provedor.
     */
    public static function isEveryoneMentioned(array $mentioned, string $text): bool
    {
        foreach ($mentioned as $m) {
            $t = strtolower(trim((string) $m, "@ \t"));
            if (in_array($t, ['todos', 'todo', 'all', 'everyone', 'everybody'], true)) {
                return true;
            }
        }
        return (bool) preg_match('/@(?:todos?|all|everyone|everybody)\b/i', $text);
    }

    /**
     * Aprende pares LID -> telefone observados no payload bruto (Uazapi envia
     * sender_lid/sender_pn e chat.wa_chatlid/phone). Vale p/ 1:1 e grupos.
     */
    private function learnLidMappings(array $payload): void
    {
        $pairs = [];
        $msgs = [];
        if (isset($payload['data']['message']) && is_array($payload['data']['message'])) {
            $msgs[] = $payload['data']['message'];
        }
        if (isset($payload['message']) && is_array($payload['message'])) {
            $msgs[] = $payload['message'];
        }
        foreach ($msgs as $msg) {
            foreach ([['sender_lid', 'sender_pn'], ['chatlid', 'phone']] as [$lidKey, $phKey]) {
                if (!empty($msg[$lidKey]) && !empty($msg[$phKey])) {
                    $pairs[] = [$msg[$lidKey], $msg[$phKey]];
                }
            }
        }
        $chat = $payload['chat'] ?? [];
        if (is_array($chat)) {
            if (!empty($chat['wa_chatlid']) && !empty($chat['phone'])) {
                $pairs[] = [$chat['wa_chatlid'], $chat['phone']];
            }
            if (!empty($chat['wa_chatlid']) && !empty($chat['wa_contactName'])) {
                // sem telefone: ignora
            }
        }
        foreach ($pairs as [$lid, $phone]) {
            \App\Models\WhatsAppLidMap::learn((string) $lid, (string) $phone);
        }
    }

    /**
     * Resolve os mencionados para telefones: mapa aprendido + fallback ao
     * endpoint resolvePhone do provedor (WAHA resolve @lid).
     *
     * @return string[] dígitos
     */
    private function resolveMentionedPhones(array $connection, array $mentioned): array
    {
        $out = [];
        $needApi = [];
        foreach ($mentioned as $m) {
            $digits = preg_replace('/\D/', '', (string) $m);
            if ($digits === '') {
                continue;
            }
            $resolved = \App\Models\WhatsAppLidMap::resolve($digits);
            if ($resolved && !\App\Models\WhatsAppLidMap::isLid($resolved)) {
                $out[] = $resolved;
            } elseif (\App\Models\WhatsAppLidMap::isLid($digits)) {
                $needApi[] = $digits;
            } else {
                $out[] = $digits;
            }
        }
        if (!empty($needApi)) {
            try {
                $provider = WhatsAppManager::forConnection($connection);
                foreach (array_unique($needApi) as $lid) {
                    $phone = $provider->resolvePhone($connection, $lid . '@lid');
                    if ($phone) {
                        \App\Models\WhatsAppLidMap::learn($lid, $phone);
                        $out[] = preg_replace('/\D/', '', $phone);
                    }
                }
            } catch (\Throwable $e) {
                $this->logWebhook('LID_RESOLVE_ERROR', ['error' => $e->getMessage()]);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Aprende pares LID -> telefone a partir dos participantes do grupo.
     * Retorna quantos pares novos foram aprendidos. Uma chamada por grupo
     * por request (cache estático) para não estourar rate limit.
     */
    private static array $groupLidLearned = [];

    private function learnGroupLidMappings(array $connection, string $groupJid): int
    {
        if ($groupJid === '' || isset(self::$groupLidLearned[$groupJid])) {
            return 0;
        }
        self::$groupLidLearned[$groupJid] = true;
        try {
            $provider = WhatsAppManager::forConnection($connection);
            if (!method_exists($provider, 'fetchGroupParticipants')) {
                return 0;
            }
            $n = 0;
            foreach ($provider->fetchGroupParticipants($connection, $groupJid) as $row) {
                // Shape novo: ['phone','lid','name','is_admin']; legado: [lid, phone].
                if (is_array($row) && array_key_exists('lid', $row)) {
                    $lid = (string) ($row['lid'] ?? '');
                    $phone = (string) ($row['phone'] ?? '');
                } else {
                    [$lid, $phone] = [(string) ($row[0] ?? ''), (string) ($row[1] ?? '')];
                }
                if ($lid === '' || $phone === '') {
                    continue;
                }
                $before = \App\Models\WhatsAppLidMap::resolve($lid);
                \App\Models\WhatsAppLidMap::learn($lid, $phone);
                if ($before !== $phone) {
                    $n++;
                }
            }
            if ($n > 0) {
                $this->logWebhook('GROUP_LID_LEARNED', ['group_id' => $groupJid, 'pairs' => $n]);
            }
            return $n;
        } catch (\Throwable $e) {
            $this->logWebhook('GROUP_LID_LEARN_ERROR', ['error' => substr($e->getMessage(), 0, 120)]);
            return 0;
        }
    }

    /**
     * Envia texto para um grupo gerenciado, com menções opcionais.
     *
     * @param string[] $mentions Dígitos a mencionar (ou 'all' p/ todos).
     * @return array{provider_message_id:?string}
     */
    public function sendGroupMessage(int $groupId, string $text, array $mentions = []): array
    {
        $group = \App\Models\WhatsAppGroup::find($groupId);
        if (!$group) {
            throw new \RuntimeException('Grupo não encontrado.');
        }
        $connection = WhatsAppConnection::find((int) $group['connection_id']);
        if (!$connection) {
            throw new \RuntimeException('Conexão do grupo não encontrada.');
        }
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Mensagem vazia.');
        }
        // Normaliza menções: dígitos válidos ou 'all'; garante @ no texto (WAHA exige).
        $clean = [];
        foreach ($mentions as $m) {
            $m = trim((string) $m, "@ \t");
            if (strtolower($m) === 'all') {
                $clean[] = 'all';
                continue;
            }
            $digits = preg_replace('/\D/', '', $m);
            if ($digits !== '' && strlen($digits) >= 8) {
                $clean[] = $digits;
            }
        }
        $clean = array_values(array_unique($clean));
        foreach ($clean as $c) {
            if ($c === 'all') {
                if (!preg_match('/@todos\b/i', $text)) {
                    $text .= ' @todos';
                }
                continue;
            }
            if (!str_contains($text, '@' . $c)) {
                $text .= ' @' . $c;
            }
        }
        $provider = WhatsAppManager::forConnection($connection);
        if (!method_exists($provider, 'sendGroupText')) {
            throw new \RuntimeException('Provedor não suporta envio para grupos.');
        }
        $result = $provider->sendGroupText($connection, $group['group_jid'], $text, $clean);
        // Espelha o envio na conversa do grupo (caixa selecionada).
        try {
            $conv = $this->ensureGroupConversation($connection, $group, $text);
            if ($conv) {
                Conversation::addMessage((int) $conv['id'], [
                    'type' => 'text',
                    'content' => $text,
                    'direction' => 'outbound',
                    'channel_message_id' => $result['provider_message_id'] ?? null,
                    'user_id' => \App\Core\Auth::id() ?: null,
                    'is_read' => 1,
                    'read_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logWebhook('GROUP_SEND_LOG_ERROR', ['error' => $e->getMessage()]);
        }
        return $result;
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

        // Foto: preenche se vazia OU se for URL remota (pps.whatsapp.net expira).
        // URL local avatars/* é permanente e não precisa refresh.
        $avatar = (string) ($contact['avatar'] ?? '');
        $needsAvatar = $avatar === '' || str_starts_with($avatar, 'http');
        if ($needsAvatar) {
            $originalFrom = $message->extra['original_from'] ?? '';
            // Candidatos p/ lookup: original (@c.us/@lid) + fone resolvido.
            // WAHA precisa do ID com sufixo; Uazapi só usa dígitos.
            $digits = preg_replace('/\D/', '', $message->from);
            $candidates = array_values(array_unique(array_filter([
                $originalFrom,
                $digits !== '' ? $digits . '@c.us' : '',
                $digits !== '' ? $digits . '@s.whatsapp.net' : '',
                $digits,
            ])));
            if ($originalFrom !== '' || $digits !== '') {
                try {
                    $conn = WhatsAppConnection::findByProviderId($providerName, $message->providerId);
                    if ($conn) {
                        $picProvider = WhatsAppManager::forConnection($conn);
                        $picResult = null;
                        foreach ($candidates as $cand) {
                            $picResult = $picProvider->getProfilePicture($conn, $cand);
                            if ($picResult) break;
                        }
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
            'path' => 'messages/' . $newName,
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
            'image/bmp' => 'bmp', 'image/heic' => 'heic', 'image/heif' => 'heif',
            'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
            'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
            'audio/webm' => 'webm', 'audio/x-m4a' => 'm4a', 'audio/3gpp' => '3gp',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/3gpp' => '3gp',
            'application/pdf' => 'pdf', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zip' => 'zip', 'application/x-rar-compressed' => 'rar',
            'text/plain' => 'txt', 'text/csv' => 'csv',
        ];
        return $map[strtolower($mime)] ?? null;
    }

    private function extFromUrl(string $url): ?string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : null;
    }

    /**
     * Valida a autenticidade do webhook para a conexão.
     * Aceita (qualquer um vale):
     *   a) ?secret= na query string (Uazapi pode sufixar /messages/text);
     *   b) HMAC-SHA256 do corpo bruto nos headers X-Webhook-Signature,
     *      X-Signature ou X-Hub-Signature-256 (prefixo "sha256=" opcional).
     * Sem webhook_secret configurado na conexão, permite (compatibilidade).
     */
    private function validateWebhookSecret(array $connection, string $rawBody): bool
    {
        $stored = (string) ($connection['webhook_secret'] ?? '');
        if ($stored === '') {
            // Compat: conexão legada sem secret. Não bloqueia, mas alerta para configurar.
            error_log('WhatsApp webhook sem webhook_secret configurado (connection_id=' . ($connection['id'] ?? '?') . '). Configure para exigir HMAC.');
            return true;
        }

        $secret = (string) ($_GET['secret'] ?? '');
        // Uazapi pode adicionar /messages/text ao final da URL; extrai apenas a parte hex
        if (preg_match('/^([a-f0-9]{32})/i', $secret, $m)) {
            $secret = $m[1];
        }
        if ($secret !== '' && hash_equals($stored, $secret)) {
            return true;
        }

        $sig = '';
        foreach (['HTTP_X_WEBHOOK_SIGNATURE', 'HTTP_X_SIGNATURE', 'HTTP_X_HUB_SIGNATURE_256'] as $h) {
            if (!empty($_SERVER[$h])) {
                $sig = (string) $_SERVER[$h];
                break;
            }
        }
        if ($sig !== '') {
            $sig = strtolower(preg_replace('/^sha256=/i', '', trim($sig)));
            if (preg_match('/^[a-f0-9]{64}$/', $sig)) {
                $expected = hash_hmac('sha256', $rawBody, $stored);
                if (hash_equals($expected, $sig)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function handleConnectionEvent(array $payload, string $providerName, string $rawBody = ''): void
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

        // Eventos de conexão também exigem o segredo (antes passavam sem validar).
        if (!$this->validateWebhookSecret($connection, $rawBody)) {
            $this->logWebhook('SECRET_FAIL', ['providerId' => $providerId, 'event' => 'connection']);
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

        // Salva o phone_number do owner (disponível no webhook da Uazapi em instance.owner).
        // Atualiza SEMPRE que mudar: se a instância for pareada com outro número,
        // manter o antigo quebra a detecção de menção e o filtro de self-message.
        $instanceRawOwner = is_array($payload['instance'] ?? null) ? ($payload['instance']['owner'] ?? null) : null;
        $owner = $instanceRawOwner ?? $payload['owner'] ?? ($payload['me']['id'] ?? ($payload['data']['owner'] ?? ($payload['chat']['owner'] ?? null)));
        if ($owner) {
            $ownerNorm = preg_replace('/\D/', '', (string) $owner);
            $currentNorm = preg_replace('/\D/', '', (string) ($connection['phone_number'] ?? ''));
            if ($ownerNorm !== '' && $ownerNorm !== $currentNorm) {
                $update['phone_number'] = $ownerNorm;
                $this->logWebhook('PHONE_CHANGED', ['old' => $currentNorm, 'new' => $ownerNorm]);
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
     *
     * Otimizações de performance:
     *  - Carrega conversation+channel+connection+contact em UMA única query (JOIN)
     *  - Cache estático local de conexões válidas (evita re-fetch no mesmo request)
     *  - A chamada HTTP ao provedor usa `delay: 0`, então a Uazapi responde rápido.
     */
    private static array $connectionCache = [];

    public function sendOutbound(int $conversationId, int $messageId, string $type, string $content): ?string
    {
        $cacheKey = "conn:{$conversationId}";
        if (isset(self::$connectionCache[$cacheKey])) {
            $bundle = self::$connectionCache[$cacheKey];
            $conversation = $bundle['conversation'];
            $channel = $bundle['channel'];
            $connection = $bundle['connection'];
            $contact = $bundle['contact'];
        } else {
            // Single JOIN: 1 round-trip ao banco em vez de 4
            $row = Database::getInstance()->fetch(
                "SELECT
                    c.id AS conv_id, c.contact_id, c.status AS conv_status,
                    ch.id AS ch_id, ch.type AS ch_type, ch.name AS ch_name,
                    wc.id AS wc_id, wc.provider, wc.instance_name, wc.instance_token,
                    wc.instance_id, wc.status AS wc_status, wc.phone_number,
                    ct.phone AS ct_phone, ct.name AS ct_name
                 FROM conversations c
                 JOIN channels ch ON ch.id = c.channel_id
                 LEFT JOIN whatsapp_connections wc ON wc.channel_id = ch.id
                 LEFT JOIN contacts ct ON ct.id = c.contact_id
                 WHERE c.id = ?",
                [$conversationId]
            );
            if (!$row || $row['ch_type'] !== 'whatsapp') {
                return null;
            }
            $conversation = ['id' => $row['conv_id'], 'contact_id' => $row['contact_id']];
            $channel = ['id' => $row['ch_id'], 'type' => $row['ch_type'], 'name' => $row['ch_name']];
            $connection = $row['wc_id'] === null ? null : [
                'id' => $row['wc_id'],
                'channel_id' => $row['ch_id'],
                'provider' => $row['provider'],
                'instance_name' => $row['instance_name'],
                'instance_token' => $row['instance_token'],
                'instance_id' => $row['instance_id'],
                'status' => $row['wc_status'],
                'phone_number' => $row['phone_number'],
            ];
            $contact = $row['ct_phone'] ? ['id' => $row['contact_id'], 'phone' => $row['ct_phone'], 'name' => $row['ct_name']] : null;

            if (!$connection || !$contact) {
                return null;
            }
            self::$connectionCache[$cacheKey] = compact('conversation', 'channel', 'connection', 'contact');
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
                    // atualiza o cache
                    self::$connectionCache[$cacheKey]['connection'] = $connection;
                }
            } catch (\Throwable $e) {
                error_log("sendOutbound: {$conversationId}/{$messageId} getStatus falhou: " . $e->getMessage());
            }
        }

        $provider = WhatsAppManager::forConnection($connection);

        $options = [];
        if ($type !== 'text') {
            $meta = json_decode($content, true);
            if (is_array($meta)) {
                $caption = $meta['caption'] ?? null;
                if (!is_string($caption) || trim($caption) === '') {
                    $caption = null;
                }
                $options['mimetype'] = $meta['mime'] ?? null;
                $options['caption'] = $caption;
                $options['file_name'] = $meta['name'] ?? null;
            }
        }

        // Citação: reply_to local → ID da mensagem original no provedor
        // (Uazapi usa `replyid`, WAHA usa `reply_to` — cada provider lê a sua).
        try {
            $own = Database::getInstance()->fetch(
                "SELECT reply_to FROM messages WHERE id = ?",
                [$messageId]
            );
            if ($own && !empty($own['reply_to'])) {
                $parent = Database::getInstance()->fetch(
                    "SELECT channel_message_id FROM messages WHERE id = ? AND conversation_id = ?",
                    [(int) $own['reply_to'], $conversationId]
                );
                $quotedId = $parent['channel_message_id'] ?? null;
                if (is_string($quotedId) && $quotedId !== '') {
                    $options['quoted_id'] = $quotedId;
                    $options['reply_to'] = $quotedId;
                }
            }
        } catch (\Throwable $e) {
            error_log("sendOutbound: {$conversationId}/{$messageId} quote lookup falhou: " . $e->getMessage());
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
            } elseif ($type === 'contact') {
                // Cartão de contato: content é JSON {name,phone,organization?}.
                $card = json_decode($content, true) ?: ['name' => '', 'phone' => ''];
                $result = $provider->sendContact($connection, $contact['phone'], [
                    'name' => (string) ($card['name'] ?? ''),
                    'phone' => (string) ($card['phone'] ?? ''),
                    'organization' => $card['organization'] ?? null,
                ]);
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
            Database::getInstance()->update(
                'messages',
                ['delivery_status' => 'failed'],
                'id = ?',
                [$messageId]
            );
            return null;
        }
        Database::getInstance()->update(
            'messages',
            ['channel_message_id' => $providerMessageId, 'delivery_status' => 'sent'],
            'id = ?',
            [$messageId]
        );

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

        // Uazapi exige o número do chat em /message/react (number + id + text).
        $to = null;
        $conversation = Conversation::find($conversationId);
        if ($conversation && !empty($conversation['contact_phone'])) {
            $to = (string) $conversation['contact_phone'];
        }

        try {
            return WhatsAppManager::forConnection($conn)->sendReaction($conn, $waId, $reaction, $to);
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
