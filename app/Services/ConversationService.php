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
        // Texto/nota: corpo todo. Mídia (image/video/audio/file): legenda (caption).
        if ($type === 'text' || $type === 'internal_note') {
            $content = TemplateService::render($content, $conversationId);
        } elseif (in_array($type, ['image', 'video', 'audio', 'file'], true)) {
            $content = TemplateService::renderMediaContent($content, $conversationId);
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

            // Parar fluxo ativo quando atendente responder.
            // applyAbertoTag=false: a tag "Fluxo" some e NÃO vira "Aberto"
            // (atendente assumiu a conversa).
            $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
            if ($flowState) {
                \App\Models\Flow::completeFlowState($conversationId, false);
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
     * Detecta @menções no formato @id (ex.: @12). O match por nome foi
     * removido: a regex anterior /@([\w\s]+)/ capturava frases inteiras,
     * gerava N queries e falsos-positivos.
     */
    private function processMentions(int $conversationId, string $content, int $fromUserId): void
    {
        if (preg_match_all('/@(\d+)/', $content, $matches)) {
            foreach (array_unique($matches[1]) as $mentionedUserId) {
                $mentionedUserId = (int) $mentionedUserId;
                if ($mentionedUserId !== $fromUserId && $mentionedUserId > 0) {
                    Notification::mention($fromUserId, $mentionedUserId, $conversationId, $content);
                }
            }
        }
    }

    public function receiveMessage(int $conversationId, string $content, string $type = 'text', ?string $channelMessageId = null, ?int $replyTo = null): int
    {
        $conv = Conversation::find($conversationId);

        // Captura resposta de CSAT (número de 1 a 5) sem reabrir a conversa.
        // Comentário é coletado pela página pública de CSAT (CsatController);
        // mensagem normal no chat após avaliado deve reabrir (ver smoke_csat_numeric).
        if ($conv && !empty($conv['csat_requested'])) {
            $csat = Conversation::getCsat($conversationId);
            if (!$csat) {
                $rating = $this->parseCsatRating($content);
                if ($rating !== null) {
                    Conversation::addCsat($conversationId, $rating, null);
                    $messageId = Conversation::addMessage($conversationId, [
                        'type' => $type,
                        'content' => $content,
                        'direction' => 'inbound',
                        'channel_message_id' => $channelMessageId,
                    ]);
                    Conversation::update($conversationId, [
                        'csat_requested' => 0,
                        'last_message_at' => date('Y-m-d H:i:s'),
                    ]);
                    $this->sendCsatThanks($conversationId);
                    return $messageId;
                }
            }
        }

        $msgData = [
            'type' => $type,
            'content' => $content,
            'direction' => 'inbound',
            'channel_message_id' => $channelMessageId,
        ];
        if ($replyTo) {
            $parent = Conversation::getMessage($replyTo);
            if ($parent && (int) ($parent['conversation_id'] ?? 0) === $conversationId) {
                $msgData['reply_to'] = $replyTo;
            }
        }
        $messageId = Conversation::addMessage($conversationId, $msgData);

        $conv = Conversation::find($conversationId);

        // Reabre conversa finalizada em nova mensagem do cliente (exceto spam).
        // Com responsável: vai para "Em atendimento" (waiting_customer);
        // sem responsável: volta para "Novo". Consistente com WebChatController.
        $isSpam = ($conv['status'] ?? '') === 'spam';
        if ($isSpam) {
            Conversation::update($conversationId, [
                'last_message_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $newStatus = $conv['assigned_user_id'] ? 'waiting_customer' : 'new';
            Conversation::update($conversationId, [
                'status' => $newStatus,
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

        // Parar fluxo ativo quando atendente atribuir conversa.
        // applyAbertoTag=false: a tag "Fluxo" some e NÃO vira "Aberto".
        $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
        if ($flowState) {
            \App\Models\Flow::completeFlowState($conversationId, false);
            Conversation::addEvent($conversationId, 'flow_stopped', 'Fluxo interrompido por atribuição manual', $assignedBy);
        }

        // Assumiu: sai a tag "Aberto" (fluxo encerrado aguardando humano).
        $abertoTag = \App\Models\Tag::findByName('Aberto');
        if ($abertoTag) {
            Conversation::removeTag($conversationId, (int) $abertoTag['id']);
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

        $prev = Conversation::find($conversationId);
        $prevDept = $prev['department_id'] ?? null;

        if ($inboxId) {
            $data['inbox_id'] = $inboxId;
            $inbox = \App\Models\Inbox::find($inboxId);
            $description .= " para caixa " . ($inbox['name'] ?? ('#' . $inboxId));
        }

        if ($departmentId) {
            $data['department_id'] = $departmentId;
            $dept = \App\Models\Department::find($departmentId);
            $description .= " para departamento " . ($dept['name'] ?? ('#' . $departmentId));
            // Trocou de setor sem caixa explícita: acompanha a caixa do setor.
            if (!$inboxId && (int) $departmentId !== (int) $prevDept) {
                $resolved = \App\Models\Inbox::resolveInboxForDepartment(
                    (int) $departmentId,
                    isset($prev['inbox_id']) ? (int) $prev['inbox_id'] : null
                );
                if ($resolved && $resolved !== (int) ($prev['inbox_id'] ?? 0)) {
                    $data['inbox_id'] = $resolved;
                    $newInbox = \App\Models\Inbox::find($resolved);
                    $description .= " (caixa " . ($newInbox['name'] ?? ('#' . $resolved)) . ")";
                }
            }
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

        // Trocou de departamento: permite nova validação de horário do destino.
        if ($departmentId && (int) $departmentId !== (int) $prevDept) {
            Conversation::update($conversationId, ['after_hours_notified' => 0]);
            $this->maybeSendAbsence($conversationId);
        }

        // Notifica o novo responsável (antes transferência era silenciosa).
        if ($userId && $userId !== Auth::id()) {
            try {
                Notification::assigned($userId, $conversationId, (int) Auth::id());
            } catch (\Throwable $e) {
                error_log('transfer notify error: ' . $e->getMessage());
            }
        } elseif (!$userId) {
            // Transferência para caixa/departamento sem responsável: avisa a caixa.
            try {
                \App\Services\NotificationService::notifyNewMessage($conversationId, 0, $description, Auth::id());
            } catch (\Throwable $e) {
                error_log('transfer broadcast notify error: ' . $e->getMessage());
            }
        }

        // Parar fluxo ativo quando atendente transferir conversa.
        // applyAbertoTag=false: a tag "Fluxo" some e NÃO vira "Aberto".
        $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
        if ($flowState) {
            \App\Models\Flow::completeFlowState($conversationId, false);
            Conversation::addEvent($conversationId, 'flow_stopped', 'Fluxo interrompido por transferência', Auth::id());
        }
    }

    public function changeStatus(int $conversationId, string $status, ?string $reason = null, ?string $description = null): void
    {
        $conv = Conversation::find($conversationId);
        $old = $conv['status'] ?? null;

        // Conversas de grupo WhatsApp são permanentes: não podem ser
        // encerradas/fechadas/spam (só histórico + menções).
        if (!empty($conv['group_id']) && in_array($status, ['resolved', 'closed', 'spam'], true)) {
            throw new \RuntimeException('Conversas de grupo não podem ser encerradas ou fechadas.');
        }

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

        // Reabertura (saída de resolved/closed/spam): permite nova validação
        // de horário do departamento e novo pedido de CSAT ao resolver de novo.
        $finals = ['resolved', 'closed', 'spam'];
        if (in_array($old, $finals, true) && !in_array($status, $finals, true)) {
            Conversation::update($conversationId, ['after_hours_notified' => 0, 'csat_requested' => 0]);
        }

        Conversation::addEvent($conversationId, 'status_changed', "Status alterado para: {$status}", Auth::id());

        // Parar fluxo ativo quando atendente mudar status manualmente.
        // applyAbertoTag=false: a tag "Fluxo" some e NÃO vira "Aberto".
        $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
        if ($flowState) {
            \App\Models\Flow::completeFlowState($conversationId, false);
            Conversation::addEvent($conversationId, 'flow_stopped', 'Fluxo interrompido por mudança de status manual', Auth::id());
        }

        // Dispara CSAT automático ao resolver OU fechar (transição para
        // 'resolved'/'closed'). Em 'spam' não há avaliação.
        if (in_array($status, ['resolved', 'closed'], true) && !in_array($old, ['resolved', 'closed'], true)) {
            $this->maybeSendCsat($conversationId);
        }
    }

    /**
     * Envia a solicitação de CSAT automática ao encerrar a conversa.
     *  - ChatWeb: cartão interativo (estrelas + campo de comentário);
     *  - demais canais (ex.: WhatsApp): texto com o link da página pública
     *    de avaliação (/csat/{public_id}). Suporta {{csat.link}} na mensagem
     *    configurada; se ausente, o link é anexado ao final.
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
            ?: 'Olá {{contact.name}}, sua conversa foi resolvida! Avalie seu atendimento aqui: {{csat.link}}';
        $text = TemplateService::render($message, $conversationId);

        if (($conv['channel_type'] ?? '') === 'webchat') {
            // Cartão de estrelas + comentário dentro do widget.
            $msgId = Conversation::addMessage($conversationId, [
                'type' => 'csat_request',
                'content' => json_encode(['title' => 'Avalie seu atendimento', 'prompt' => $text], JSON_UNESCAPED_UNICODE),
                'direction' => 'outbound',
            ]);
            $this->deliverOutbound($conversationId, $msgId, 'csat_request', $text);
        } else {
            $link = !empty($conv['public_id']) ? base_url('csat/' . $conv['public_id']) : '';
            if ($link !== '' && !str_contains($text, $link)) {
                $text = trim($text) . "\n" . $link;
            }
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
     * Agradecimento após a nota (fora do ChatWeb, que já agradece no
     * próprio cartão). Convida o cliente a deixar um comentário, capturado
     * na mensagem seguinte.
     */
    private function sendCsatThanks(int $conversationId): void
    {
        $conv = Conversation::find($conversationId);
        if (!$conv || ($conv['channel_type'] ?? '') === 'webchat') {
            return;
        }
        $text = TemplateService::render(
            'Obrigado pela sua avaliação, {{contact.name}}! 😊 Se quiser, deixe um comentário respondendo esta mensagem.',
            $conversationId
        );
        $msgId = Conversation::addMessage($conversationId, [
            'type' => 'text',
            'content' => $text,
            'direction' => 'outbound',
        ]);
        $this->deliverOutbound($conversationId, $msgId, 'text', $text);
    }

    /**
     * Janela de comentário: até 24h após a nota. Depois disso, mensagens
     * novas seguem o fluxo normal (não são engolidas como comentário).
     */
    private function csatCommentOpen(?array $csat): bool
    {
        if (!$csat || empty($csat['created_at'])) {
            return false;
        }
        return (time() - (int) strtotime((string) $csat['created_at'])) < 24 * 3600;
    }

    /**
     * Extrai uma nota de CSAT (1 a 5) de uma resposta do cliente.
     * Estrito: a mensagem deve conter APENAS o dígito (ex.: "5").
     * Evita falsos-positivos como "me liga às 3".
     */
    private function parseCsatRating(string $content): ?int
    {
        if (preg_match('/^([1-5])$/', trim($content))) {
            return (int) trim($content);
        }
        return null;
    }

    /**
     * Envia a mensagem de ausência quando o cliente escreve fora do horário
     * DO DEPARTAMENTO DA CONVERSA. Disparado uma única vez por conversa
     * (até troca de departamento/reabertura) e ignorado se há fluxo ativo.
     */
    public function maybeSendAbsence(int $conversationId): void
    {
        $conv = Conversation::find($conversationId);
        if (!$conv || !empty($conv['after_hours_notified'])) {
            return;
        }
        $deptId = !empty($conv['department_id']) ? (int) $conv['department_id'] : null;
        if (!$deptId) {
            return; // sem departamento => sem validação de horário
        }
        if (BusinessHoursService::isOpen($deptId)) {
            return;
        }
        if (Flow::getActiveFlowState($conversationId)) {
            return; // bot está atendendo
        }

        $text = BusinessHoursService::absenceMessage($deptId);
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
