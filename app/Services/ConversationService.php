<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\Notification;
use App\Models\Setting;

class ConversationService
{
    public function createFromChannel(array $contactData, int $channelId, ?int $departmentId = null, ?string $subject = null): array
    {
        $contact = Contact::findOrCreate(
            $contactData['name'] ?? 'Visitante',
            $contactData['email'] ?? null,
            $contactData['phone'] ?? null
        );

        $conversationId = Conversation::create([
            'contact_id' => $contact['id'],
            'channel_id' => $channelId,
            'department_id' => $departmentId,
            'inbox_id' => \App\Models\Inbox::resolveInboxForChannel($channelId),
            'subject' => $subject,
            'status' => 'new',
            'source' => $contactData['source'] ?? 'webchat',
        ]);

        $conversation = Conversation::find($conversationId);

        Conversation::addEvent($conversationId, 'created', 'Conversa criada automaticamente');

        return $conversation;
    }

    public function sendMessage(int $conversationId, string $content, string $type = 'text', ?int $userId = null, ?string $channelMessageId = null, ?int $replyTo = null): int
    {
        $userId ??= Auth::id();

        // Resolve variáveis dinâmicas (ex.: {{contact.name}}) antes do envio.
        if ($type === 'text' || $type === 'internal_note') {
            $content = TemplateService::render($content, $conversationId);
        }

        $messageData = [
            'type' => $type,
            'content' => $content,
            'direction' => 'outbound',
            'user_id' => $userId,
        ];
        if ($channelMessageId) {
            $messageData['channel_message_id'] = $channelMessageId;
        }
        if ($replyTo) {
            $messageData['reply_to'] = $replyTo;
        }
        $messageId = Conversation::addMessage($conversationId, $messageData);

        $data = ['last_message_at' => date('Y-m-d H:i:s')];

        if ($type !== 'internal_note') {
            $conv = Conversation::find($conversationId);

            // Auto-assign to the agent if conversation was unassigned
            if (empty($conv['assigned_user_id'])) {
                Conversation::update($conversationId, [
                    'assigned_user_id' => $userId,
                ]);
                Conversation::addEvent($conversationId, 'assigned', 'Atendimento atribuído automaticamente', $userId, [
                    'user_id' => $userId,
                ]);
            }

            // Mark as being attended (Em atendimento)
            $data['status'] = 'waiting_customer';

            // Parar fluxo ativo quando atendente responder
            $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
            if ($flowState) {
                \App\Models\Flow::completeFlowState($conversationId);
                Conversation::addEvent($conversationId, 'flow_stopped', 'Fluxo interrompido por resposta do atendente', $userId);
            }
        }

        Conversation::update($conversationId, $data);

        // Detect @mentions in internal notes and create notifications
        if ($type === 'internal_note' && $userId) {
            $this->processMentions($conversationId, $content, $userId);
        }

        return $messageId;
    }

    /**
     * Detecta @menções no formato @NomeDoUsuario ou @id e cria notificações
     */
    private function processMentions(int $conversationId, string $content, int $fromUserId): void
    {
        if (preg_match_all('/@(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $mentionedUserId) {
                $mentionedUserId = (int) $mentionedUserId;
                if ($mentionedUserId !== $fromUserId) {
                    Notification::mention($fromUserId, $mentionedUserId, $conversationId, $content);
                }
            }
        }

        // Also try to match by @username (case-insensitive)
        if (preg_match_all('/@([\w\s]+)/u', $content, $nameMatches)) {
            foreach ($nameMatches[1] as $name) {
                $name = trim($name);
                if (is_numeric($name)) continue; // already handled by ID match
                $user = \App\Models\User::findByEmail($name);
                if (!$user) {
                    $user = Database::getInstance()->fetch(
                        "SELECT id FROM users WHERE LOWER(name) = LOWER(?) LIMIT 1",
                        [$name]
                    );
                }
                if ($user && (int) $user['id'] !== $fromUserId) {
                    Notification::mention($fromUserId, (int) $user['id'], $conversationId, $content);
                }
            }
        }
    }

    public function receiveMessage(int $conversationId, string $content, string $type = 'text', ?string $channelMessageId = null): int
    {
        $conv = Conversation::find($conversationId);

        // Captura resposta de CSAT (número de 1 a 5) sem reabrir a conversa.
        if ($conv && !empty($conv['csat_requested']) && !Conversation::getCsat($conversationId)) {
            $rating = $this->parseCsatRating($content);
            if ($rating !== null) {
                Conversation::addCsat($conversationId, $rating, null);
                $messageId = Conversation::addMessage($conversationId, [
                    'type' => $type,
                    'content' => $content,
                    'direction' => 'inbound',
                    'channel_message_id' => $channelMessageId,
                ]);
                Conversation::update($conversationId, ['last_message_at' => date('Y-m-d H:i:s')]);
                return $messageId;
            }
        }

        $messageId = Conversation::addMessage($conversationId, [
            'type' => $type,
            'content' => $content,
            'direction' => 'inbound',
            'channel_message_id' => $channelMessageId,
        ]);

        $conv = Conversation::find($conversationId);

        // Só reabre se não estiver num estado final
        $isFinal = in_array($conv['status'], ['closed', 'resolved', 'spam'], true);
        if (!$isFinal) {
            $newStatus = $conv['assigned_user_id'] ? 'open' : 'new';
            Conversation::update($conversationId, [
                'status' => $newStatus,
                'last_message_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            Conversation::update($conversationId, [
                'last_message_at' => date('Y-m-d H:i:s'),
            ]);
        }

        Contact::update($conv['contact_id'], ['last_contact_at' => date('Y-m-d H:i:s')]);

        $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
        $flowWillRespond = (bool) $flowState;
        if ($flowState) {
            $flowEngine = new FlowEngineService();
            $flowEngine->handleCustomerMessage($conversationId, $content);
        }

        // Garante que o atendente seja notificado mesmo em conversas em
        // chatbot: o NotificationService escolhe o tipo correto
        // (new_message x new_conversation) conforme o estado da conversa.
        try {
            \App\Services\NotificationService::notifyNewMessage(
                $conversationId,
                $messageId,
                $content
            );
        } catch (\Throwable $e) {
            error_log('receiveMessage notify error: ' . $e->getMessage());
        }

        $this->maybeSendAbsence($conversationId);

        return $messageId;
    }

    public function assign(int $conversationId, int $userId, ?int $assignedBy = null): void
    {
        $assignedBy ??= Auth::id();

        Conversation::update($conversationId, [
            'assigned_user_id' => $userId,
            'status' => 'open',
        ]);

        Conversation::addEvent($conversationId, 'assigned', 'Atendimento atribuído', $assignedBy, [
            'user_id' => $userId,
        ]);

        // Parar fluxo ativo quando atendente atribuir conversa
        $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
        if ($flowState) {
            \App\Models\Flow::completeFlowState($conversationId);
            Conversation::addEvent($conversationId, 'flow_stopped', 'Fluxo interrompido por atribuição manual', $assignedBy);
        }

        // Notify the assigned user
        if ($userId !== $assignedBy) {
            Notification::assigned($userId, $conversationId, $assignedBy);
        }
    }

    public function transfer(int $conversationId, ?int $departmentId = null, ?int $userId = null, ?int $inboxId = null): void
    {
        $data = [];
        $description = 'Transferido';

        if ($inboxId) {
            $data['inbox_id'] = $inboxId;
            $inbox = \App\Models\Inbox::find($inboxId);
            $description .= " para caixa {$inbox['name']}";
        }

        if ($departmentId) {
            $data['department_id'] = $departmentId;
            $dept = \App\Models\Department::find($departmentId);
            $description .= " para departamento {$dept['name']}";
        }

        if ($userId) {
            $data['assigned_user_id'] = $userId;
            $data['status'] = 'open';
            $description .= " para usuário #{$userId}";
        } else {
            $data['assigned_user_id'] = null;
            $data['status'] = 'new';
            $description .= " (sem responsável)";
        }

        Conversation::update($conversationId, $data);
        Conversation::addEvent($conversationId, 'transferred', $description, Auth::id());

        // Parar fluxo ativo quando atendente transferir conversa
        $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
        if ($flowState) {
            \App\Models\Flow::completeFlowState($conversationId);
            Conversation::addEvent($conversationId, 'flow_stopped', 'Fluxo interrompido por transferência', Auth::id());
        }
    }

    public function changeStatus(int $conversationId, string $status, ?string $reason = null, ?string $description = null): void
    {
        $conv = Conversation::find($conversationId);
        $old = $conv['status'] ?? null;

        $data = ['status' => $status];

        if (in_array($status, ['resolved', 'closed'])) {
            $data['closed_at'] = date('Y-m-d H:i:s');
            if ($reason !== null) {
                $data['close_reason'] = $reason;
            }
            if ($description !== null) {
                $data['close_description'] = $description;
            }

            // Registra a descrição como comentário interno para consulta futura
            $note = '';
            if ($reason !== null && $reason !== '') {
                $note .= "Motivo: {$reason}\n";
            }
            if ($description !== null && $description !== '') {
                $note .= $description;
            }
            $note = trim($note);
            if ($note !== '') {
                Conversation::addMessage($conversationId, [
                    'type' => 'internal_note',
                    'content' => $note,
                    'direction' => 'outbound',
                    'user_id' => Auth::id(),
                ]);
            }
        }

        Conversation::update($conversationId, $data);

        Conversation::addEvent($conversationId, 'status_changed', "Status alterado para: {$status}", Auth::id());

        // Parar fluxo ativo quando atendente mudar status manualmente
        $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
        if ($flowState) {
            \App\Models\Flow::completeFlowState($conversationId);
            Conversation::addEvent($conversationId, 'flow_stopped', 'Fluxo interrompido por mudança de status manual', Auth::id());
        }

        // Dispara CSAT automático ao resolver (transição para 'resolved')
        if ($status === 'resolved' && $old !== 'resolved') {
            $this->maybeSendCsat($conversationId);
        }
    }

    /**
     * Envia a solicitação de CSAT automática ao marcar a conversa como resolvida.
     * - ChatWeb: widget de estrelas exibido no cliente (tipo csat_request).
     * - Outros canais: mensagem com link para avaliação pública.
     */
    public function maybeSendCsat(int $conversationId): void
    {
        if (Setting::get('csat_enabled', '1') !== '1') {
            return;
        }

        $conv = Conversation::find($conversationId);
        if (!$conv || !empty($conv['csat_requested'])) {
            return;
        }
        if (Conversation::getCsat($conversationId)) {
            return; // já avaliou
        }

        $message = trim((string) Setting::get('csat_message', ''))
            ?: 'Olá {{contact.name}}, sua conversa foi resolvida! Por favor, avalie seu atendimento de 1 a 5 estrelas.';
        $content = TemplateService::render($message, $conversationId);

        if (($conv['channel_type'] ?? null) === 'webchat') {
            $payload = json_encode([
                'title' => 'Avalie seu atendimento',
                'prompt' => $content,
                'url' => base_url('csat/' . $conv['public_id']),
            ]);
            Conversation::addMessage($conversationId, [
                'type' => 'csat_request',
                'content' => $payload,
                'direction' => 'outbound',
            ]);
        } else {
            $link = base_url('csat/' . $conv['public_id']);
            $text = $content . "\n\nResponda com um número de 1 a 5 para avaliar, ou acesse: " . $link;
            $msgId = Conversation::addMessage($conversationId, [
                'type' => 'text',
                'content' => $text,
                'direction' => 'outbound',
            ]);
            $this->deliverOutbound($conversationId, $msgId, 'text', $text);
        }

        Conversation::update($conversationId, ['csat_requested' => 1]);
    }

    /**
     * Extrai uma nota de CSAT (1 a 5) de uma resposta textual do cliente.
     * Aceita "5", "5 ⭐", "★4★" etc., ignorando outros textos.
     */
    private function parseCsatRating(string $content): ?int
    {
        $digits = trim(preg_replace('/[^0-9]/', '', $content));
        if (strlen($digits) === 1 && $digits >= '1' && $digits <= '5') {
            return (int) $digits;
        }
        return null;
    }

    /**
     * Envia a mensagem de ausência quando o cliente escreve fora do horário.
     * Disparado uma única vez por conversa e ignorado se há um fluxo ativo.
     */
    public function maybeSendAbsence(int $conversationId): void
    {
        if (!BusinessHoursService::isEnabled()) {
            return;
        }

        $conv = Conversation::find($conversationId);
        if (!$conv || !empty($conv['after_hours_notified'])) {
            return;
        }
        if (BusinessHoursService::isOpen($conv['department_id'] ?? null)) {
            return;
        }
        if (Flow::getActiveFlowState($conversationId)) {
            return; // bot está atendendo
        }

        $text = BusinessHoursService::absenceMessage();
        $msgId = Conversation::addMessage($conversationId, [
            'type' => 'text',
            'content' => $text,
            'direction' => 'outbound',
        ]);
        $this->deliverOutbound($conversationId, $msgId, 'text', $text);

        Conversation::update($conversationId, ['after_hours_notified' => 1]);
    }

    /**
     * Entrega uma mensagem outbound ao WhatsApp ou E-mail (se aplicável).
     */
    private function deliverOutbound(int $conversationId, int $messageId, string $type, string $content): void
    {
        try {
            $conv = Conversation::find($conversationId);
            if (!$conv) return;

            $channelType = $conv['channel_type'] ?? null;

            if ($channelType === 'whatsapp') {
                (new \App\Services\WhatsAppService())->sendOutbound($conversationId, $messageId, $type, $content);
            }
        } catch (\Throwable $e) {
            error_log('Outbound delivery error: ' . $e->getMessage());
        }
    }

    public function getInboxData(?int $userId = null, ?int $departmentId = null, string $view = 'all'): array
    {
        $filters = [];

        switch ($view) {
            case 'mine':
                $userId = Auth::id();
                break;
            case 'department':
                $departmentId = $departmentId ?: (Auth::user()['department_id'] ?? null);
                break;
            case 'unassigned':
                $filters['unassigned'] = true;
                break;
        }

        $conversations = Conversation::getInboxConversations($userId, $departmentId, null, $filters);

        return [
            'conversations' => $conversations,
            'counts' => Conversation::countByStatus(Auth::id()),
            'unread' => Conversation::getUnreadCount(Auth::id()),
            'departments' => \App\Models\Department::all(),
        ];
    }
}
