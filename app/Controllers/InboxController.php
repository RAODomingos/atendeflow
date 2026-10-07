<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Flow;
use App\Models\Contact;
use App\Models\Inbox;
use App\Models\Tag;
use App\Models\CannedResponse;
use App\Models\Macro;
use App\Services\ConversationService;

class InboxController
{
    /**
     * Checagem central de acesso a conversa: admin vê tudo; demais precisam
     * ser responsável, membro da inbox ou do departamento.
     */
    private function canUserSeeConversation(int $conversationId, int $userId): bool
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) {
            return false;
        }
        if (Auth::isAdmin()) {
            return true;
        }
        if (!empty($conv['assigned_user_id']) && (int) $conv['assigned_user_id'] === $userId) {
            return true;
        }
        if (!empty($conv['inbox_id']) && Inbox::canAccess((int) $conv['inbox_id'], $userId)) {
            return true;
        }
        if (!empty($conv['department_id'])) {
            $row = Database::getInstance()->fetch(
                "SELECT 1 FROM department_users WHERE department_id = ? AND user_id = ? LIMIT 1",
                [$conv['department_id'], $userId]
            );
            if ($row) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nega acesso a conversa sem permissão: 403 JSON p/ AJAX/API, redirect p/ web.
     * Retorna true se negou (chamador deve dar return).
     */
    private function denyUnlessCanAccess(Request $request, int $conversationId): bool
    {
        if ($this->canUserSeeConversation($conversationId, Auth::id())) {
            return false;
        }
        if ($request->isAjax() || $request->wantsJson() || str_starts_with($request->uri(), '/api/')) {
            View::json(['error' => 'Sem acesso a esta conversa.'], 403);
            return true;
        }
        Session::setFlash('error', 'Voce nao tem acesso a esta conversa.');
        View::redirect('/inbox');
        return true;
    }

    private function conversationData(int $id): ?array
    {
        $conversation = Conversation::find($id);
        if (!$conversation) return null;
        if (!$this->canUserSeeConversation($id, Auth::id())) return null;

        return [
            'conversation' => $conversation,
            'messages' => Conversation::getMessages($id),
            'events' => Conversation::getEvents($id),
            'contact' => Contact::find($conversation['contact_id']),
            'csat' => Conversation::getCsat($id),
            'allTags' => Tag::all(),
        ];
    }
    private ConversationService $conversationService;

    public function __construct()
    {
        $this->conversationService = new ConversationService();
    }

    public function index(Request $request): void
    {
        $inboxes = Inbox::getUserInboxes(Auth::id());
        $inboxId = $request->input('inbox');
        $search = trim((string) $request->input('search'));
        $statusFilter = $request->input('fstatus');
        if ($statusFilter === null) {
            $statusFilter = 'active';
        }

        $filters = [];
        if ($search) {
            $filters['search'] = $search;
        }
        if ($statusFilter && $statusFilter !== 'all') {
            $filters['status'] = $statusFilter;
        }

        $detail = null;
        $convId = $request->input('conv');
        if ($convId) {
            $detail = $this->getConversationDetail((int) $convId);
            if ($detail) {
                Conversation::markMessagesAsRead((int) $convId, Auth::id());
                \App\Models\Notification::markConversationNotificationsRead((int) $convId, Auth::id());
                $this->markGroupMentionRead($detail['conversation'] ?? null);
                $convStatus = $detail['conversation']['status'] ?? '';
                if (in_array($convStatus, ['resolved', 'closed', 'spam']) && $statusFilter === 'active') {
                    $statusFilter = 'resolved_closed';
                    $filters['status'] = 'resolved_closed';
                }
            }
        }

        if ($inboxId && Inbox::canAccess((int) $inboxId, Auth::id())) {
            $inbox = Inbox::find((int) $inboxId);
            $conversations = Conversation::getConversationsForInbox((int) $inboxId, Auth::id(), $filters);
            View::renderWithLayout('inbox/index', 'main', [
                'title' => 'Caixa: ' . $inbox['name'],
                'activePage' => 'inbox',
                'conversations' => $conversations,
                'counts' => Conversation::countByStatus(Auth::id()),
                'unread' => Conversation::getUnreadCount(Auth::id()),
                'departments' => Department::all(),
                'contacts' => Contact::all(['limit' => 200]),
                'inboxes' => $inboxes,
                'activeInbox' => (int) $inboxId,
                'search' => $search,
                'fstatus' => $statusFilter,
                'detail' => $detail,
                'channels' => \App\Models\Channel::getWhatsapp(),
            ]);
            return;
        }

        $ids = array_column($inboxes, 'id');
        $conversations = Conversation::getConversationsForInboxes($ids, Auth::id(), $filters);

        View::renderWithLayout('inbox/index', 'main', [
            'title' => 'Caixa de Entrada',
            'activePage' => 'inbox',
            'conversations' => $conversations,
            'counts' => Conversation::countByStatus(Auth::id()),
            'unread' => Conversation::getUnreadCount(Auth::id()),
            'departments' => Department::all(),
            'contacts' => Contact::all(['limit' => 200]),
            'inboxes' => $inboxes,
            'search' => $search,
            'fstatus' => $statusFilter,
            'detail' => $detail,
            'channels' => \App\Models\Channel::getWhatsapp(),
        ]);
    }

    /**
     * Carrega os dados de uma conversa (com checagem de acesso à caixa).
     * Retorna null se não encontrada ou sem acesso.
     */
    private function getConversationDetail(int $id): ?array
    {
        $conversation = Conversation::find($id);
        if (!$conversation) {
            return null;
        }
        if (!$this->canUserSeeConversation($id, Auth::id())) {
            return null;
        }
        $conversation['csat'] = Conversation::getCsat($id);
        $allMessages = Conversation::getMessages($id);
        $hasOlder = count($allMessages) > 30;
        $messages = $hasOlder ? array_slice($allMessages, -30) : $allMessages;
        $firstMid = $hasOlder ? (int) $messages[0]['id'] : 0;

        $internalNotes = [];
        try {
            $internalNotes = Database::getInstance()->fetchAll(
                "SELECT n.*, u.name AS user_name FROM internal_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.conversation_id = ? ORDER BY n.created_at DESC",
                [$id]
            );
        } catch (\Throwable $e) {}

        return [
            'conversation' => $conversation,
            'messages' => $messages,
            'hasOlder' => $hasOlder,
            'firstMid' => $firstMid,
            'events' => Conversation::getEvents($id),
            'departments' => Department::all(),
            'contact' => Contact::find($conversation['contact_id']),
            'otherConversations' => Conversation::getByContact($conversation['contact_id'], $id),
            'allTags' => Tag::all(),
            'signature' => Auth::user()['signature'] ?? '',
            'csat' => Conversation::getCsat($id),
            'internalNotes' => $internalNotes,
        ];
    }

    /**
     * Ao abrir conversa de grupo, zera também menções/sino do grupo.
     */
    private function markGroupMentionRead(?array $conversation): void
    {
        $groupId = (int) ($conversation['group_id'] ?? 0);
        if ($groupId > 0) {
            \App\Models\WhatsAppGroup::markMentionsRead($groupId);
            \App\Services\NotificationService::markGroupNotificationsRead($groupId, (int) Auth::id());
        }
    }

    /**
     * Fragmento HTML do detalhe da conversa (usado via AJAX na visão de 3 colunas).
     */
    public function conversationPanel(Request $request, int $id): void
    {
        $detail = $this->getConversationDetail($id);
        if (!$detail) {
            http_response_code(403);
            echo '<div class="detail-empty"><i class="fas fa-lock fa-3x"></i><p>Conversa não disponível.</p></div>';
            return;
        }
        Conversation::markMessagesAsRead($id, Auth::id());
        \App\Models\Notification::markConversationNotificationsRead($id, Auth::id());
        $this->markGroupMentionRead($detail['conversation'] ?? null);
        View::render('inbox/panel', $detail);
    }

    public function mine(Request $request): void
    {
        $owned = Inbox::getOwnedPersonalInboxes(Auth::id());
        $ids = array_column($owned, 'id');
        $conversations = Conversation::getConversationsForInboxes($ids, Auth::id());

        View::renderWithLayout('inbox/index', 'main', [
            'title' => 'Minha Caixa',
            'activePage' => 'inbox_mine',
            'conversations' => $conversations,
            'counts' => Conversation::countByStatus(Auth::id()),
            'unread' => Conversation::getUnreadCount(Auth::id()),
            'departments' => Department::all(),
            'inboxes' => Inbox::getUserInboxes(Auth::id()),
            'activeTab' => 'mine',
        ]);
    }

    public function unassigned(Request $request): void
    {
        $conversations = Conversation::getUnassigned();
        View::renderWithLayout('inbox/index', 'main', [
            'title' => 'Não Atribuídas',
            'activePage' => 'inbox',
            'conversations' => $conversations,
            'counts' => Conversation::countByStatus(),
            'unread' => Conversation::getUnreadCount(Auth::id()),
            'departments' => Department::all(),
            'activeTab' => 'unassigned',
            'inboxes' => Inbox::getUserInboxes(Auth::id()),
        ]);
    }

    /**
     * A aba "Chatbot" foi removida: todas as conversas (inclusive com fluxo
     * ativo) aparecem na caixa de entrada. Mantemos a rota apenas para
     * redirecionar visitas antigas para a caixa principal.
     */
    public function chatbot(Request $request): void
    {
        View::redirect('/inbox');
    }

    public function createPersonal(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        if (!$name) {
            $name = 'Caixa de ' . (Auth::user()['name'] ?? 'usuario');
        }
        $id = Inbox::create([
            'name' => $name,
            'type' => 'personal',
            'is_active' => 1,
        ]);
        Inbox::setUsers($id, [Auth::id()]);

        Session::setFlash('success', 'Caixa pessoal criada com sucesso.');
        View::redirect('/inbox?inbox=' . $id);
    }

    public function show(Request $request, int $id): void
    {
        $conversation = Conversation::find($id);
        if (!$conversation) {
            Session::setFlash('error', 'Conversa não encontrada.');
            View::redirect('/inbox');
        }

        if (!$this->canUserSeeConversation($id, Auth::id())) {
            Session::setFlash('error', 'Voce nao tem acesso a esta conversa.');
            View::redirect('/inbox');
        }

        $messages = Conversation::getMessages($id);
        $events = Conversation::getEvents($id);
        $departments = Department::all();

        Conversation::markMessagesAsRead($id, Auth::id());
        \App\Models\Notification::markConversationNotificationsRead($id, Auth::id());
        $this->markGroupMentionRead($conversation);

        $unread = Conversation::getUnreadCount(Auth::id());

        $counts = Conversation::countByStatus(Auth::id());

        $contact = Contact::find($conversation['contact_id']);

        View::renderWithLayout('inbox/show', 'main', [
            'title' => 'Conversa',
            'activePage' => 'inbox',
            'conversation' => $conversation,
            'messages' => $messages,
            'events' => $events,
            'departments' => $departments,
            'contact' => $contact,
            'unread' => $unread,
            'counts' => $counts,
            'csat' => Conversation::getCsat($conversation['id']),
            'allTags' => Tag::all(),
        ]);
    }

    public function downloadPdf(Request $request, int $id): void
    {
        $data = $this->conversationData($id);
        if (!$data) {
            Session::setFlash('error', 'Conversa não encontrada.');
            View::redirect('/inbox');
        }

        $html = View::renderBuffer('inbox/pdf', $data);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html);
        $dompdf->render();

        while (ob_get_level()) {
            ob_end_clean();
        }

        $protocol = format_protocol((string) ($data['conversation']['protocol'] ?? ''));
        $filename = $protocol !== '' ? "conversa-{$protocol}.pdf" : "conversa-{$id}.pdf";
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }

    public function sendMessage(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $content = $request->post('content');
        $hasText = !empty(trim((string) ($content ?? '')));
        $uploaded = null;
        $createdIds = [];
        $replyTo = $request->post('reply_to') ? (int) $request->post('reply_to') : null;

        $isInternal = $request->post('type') === 'internal';

        $file = $request->file('file');
        $hasFile = !empty($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK;

        // Valida ANTES de mover para disco: evita arquivo órfão se a mensagem for vazia.
        if (!$hasText && !$hasFile) {
            if ($request->isAjax()) {
                View::json(['ok' => false, 'error' => 'Digite uma mensagem ou anexe um arquivo.']);
                return;
            }
            Session::setFlash('error', 'Digite uma mensagem ou anexe um arquivo.');
            View::back();
        }

        if ($hasFile) {
            $uploaded = save_uploaded_file('file');
            if ($uploaded === null) {
                $msg = 'Arquivo inválido. Verifique tipo (imagem/áudio/vídeo/doc) e tamanho máximo.';
                if ($request->isAjax()) {
                    View::json(['ok' => false, 'error' => $msg]);
                    return;
                }
                Session::setFlash('error', $msg);
                View::back();
            }
            // Não convertemos a imagem aqui. A Uazapi é capaz de aceitar PNG e
            // JPEG nativamente via /send/media (com o campo mimetype). WebP é
            // tentado como image primeiro; se a Uazapi rejeitar, o provider
            // faz fallback de conversão para JPEG automaticamente.
        }

        $text = $hasText ? trim((string) $content) : '';
        $delivery = [];

        if ($uploaded) {
            // Quando há arquivo E texto, o texto vira a legenda (caption) da mídia
            // e tudo vai em UMA ÚNICA mensagem — é o comportamento padrão do WhatsApp.
            // Notas internas: nunca recebem anexo, então a regra de caption não se aplica.
            $messageType = $isInternal ? 'internal_note' : $uploaded['type'];
            $caption = (!$isInternal && $text !== '') ? $this->applyWhatsAppSignature($id, $text) : null;
            $meta = [
                'url'  => $uploaded['url'],
                'name' => $uploaded['name'],
                'size' => $uploaded['size'],
                'mime' => $uploaded['mime'],
                // 'path' é o caminho relativo no disco (ex.: messages/abc.webm).
                // Necessário para o provedor Uazapi/WAHA ler o arquivo local e enviar
                // em base64, já que o servidor deles não alcança a URL pública do app
                // (ngrok, IP dinâmico, domínios internos, etc).
                'path' => $uploaded['path'] ?? null,
            ];
            if ($caption !== null && $caption !== '') {
                $meta['caption'] = $caption;
            }
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $msgId = $this->conversationService->sendMessage(
                $id,
                $metaJson,
                $messageType,
                Auth::id(),
                null,
                $replyTo
            );
            $createdIds[] = $msgId;
            if ($messageType !== 'internal_note') {
                $delivery[$msgId] = $this->dispatchWhatsApp($id, $msgId, $messageType, $metaJson);
            }
            // Texto já foi consumido como caption
            $text = '';
        }

        if ($text !== '') {
            $type = $isInternal ? 'internal_note' : 'text';
            if ($type === 'text') {
                $text = $this->applyWhatsAppSignature($id, $text);
            }
            $msgId = $this->conversationService->sendMessage($id, $text, $type, Auth::id(), null, $replyTo);
            $createdIds[] = $msgId;
            if ($type !== 'internal_note') {
                $delivery[$msgId] = $this->dispatchWhatsApp($id, $msgId, $type, $text);
            }
        }

        $failed = array_filter($delivery, fn($d) => empty($d['delivered']));
        if ($request->isAjax()) {
            $messages = [];
            foreach ($createdIds as $mid) {
                $m = Conversation::getMessage($mid);
                if ($m) {
                    $m['user_avatar'] = Auth::user()['avatar'] ?? null;
                    $m['delivered'] = !isset($delivery[$mid]) || !empty($delivery[$mid]['delivered']);
                    $messages[] = $m;
                }
            }
            View::json([
                'ok' => true,
                'messages' => $messages,
                'delivery_failed' => !empty($failed),
                'delivery_error' => !empty($failed) ? (string) reset($failed)['error'] : null,
            ]);
            return;
        }

        if (!empty($failed)) {
            Session::setFlash('error', (string) reset($failed)['error']);
        }
        View::redirect("/inbox?conv={$id}");
    }

    /**
     * Insere a assinatura no topo da mensagem quando o contato veio do WhatsApp
     * e a conversa tem a assinatura ativada. Por padrão usa "*Nome do usuário*";
     * se o usuário cadastrou uma assinatura personalizada no perfil, usa-a.
     */
    private function applyWhatsAppSignature(int $conversationId, string $text): string
    {
        $conv = Conversation::find($conversationId);
        if (($conv['channel_type'] ?? '') !== 'whatsapp') {
            return $text;
        }
        if (isset($conv['signature_enabled']) && (int) $conv['signature_enabled'] === 0) {
            return $text;
        }

        $user = Auth::user();
        $custom = trim((string) ($user['signature'] ?? ''));
        $signature = $custom !== '' ? $custom : '*' . trim($user['name']) . '*';

        return $signature . "\n" . $text;
    }

    public function updateSettings(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $field = $request->post('field');
        if ($field !== 'signature_enabled') {
            View::json(['ok' => false, 'error' => 'Campo inválido'], 400);
            return;
        }

        $value = (bool) $request->post('value', 0) ? 1 : 0;
        Conversation::update($id, ['signature_enabled' => $value]);

        View::json(['ok' => true, 'value' => $value]);
    }

    public function updateSubject(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $subject = $request->post('subject', '');
        Conversation::update($id, ['subject' => $subject]);
        View::json(['ok' => true, 'subject' => $subject]);
    }

    public function updateUnit(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $unit = $request->post('unit', '');
        Conversation::update($id, ['unit' => $unit]);
        View::json(['ok' => true, 'unit' => $unit]);
    }

    public function assign(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $userId = $request->post('user_id');
        if ($userId) {
            $this->conversationService->assign($id, (int) $userId);
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function transfer(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $departmentId = $request->post('department_id');
        $userId = $request->post('user_id');

        $this->conversationService->transfer(
            $id,
            $departmentId ? (int) $departmentId : null,
            $userId ? (int) $userId : null
        );

        Session::setFlash('success', 'Atendimento transferido.');
        View::redirect("/inbox?conv={$id}");
    }

    public function changeStatus(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $status = $request->post('status');
        if ($status && in_array($status, ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'])) {
            $reason = trim((string) $request->post('reason'));
            $description = trim((string) $request->post('description'));
            try {
                $this->conversationService->changeStatus($id, $status, $reason ?: null, $description ?: null);
            } catch (\Throwable $e) {
                if ($request->isAjax() || $request->wantsJson()) {
                    View::json(['error' => $e->getMessage()], 422);
                    return;
                }
                Session::setFlash('error', $e->getMessage());
                View::redirect("/inbox?conv={$id}");
                return;
            }
        }
        if ($request->isAjax() || $request->wantsJson()) {
            View::json(['ok' => true, 'status' => $status]);
            return;
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function addInternalNote(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $content = trim($request->post('content') ?? '');
        if ($content !== '') {
            Database::getInstance()->insert('internal_notes', [
                'conversation_id' => $id,
                'content' => $content,
                'user_id' => Auth::id(),
            ]);
            Conversation::addEvent($id, 'note_added', 'Nota interna adicionada', Auth::id());
        }
        if ($request->isAjax() || $request->wantsJson()) {
            $note = Database::getInstance()->fetch(
                "SELECT n.*, u.name AS user_name FROM internal_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.conversation_id = ? ORDER BY n.id DESC LIMIT 1",
                [$id]
            );
            View::json(['success' => $content !== '', 'note' => $note ?: null]);
            return;
        }
        View::redirect("/inbox/{$id}");
    }

    public function newConversation(Request $request): void
    {
        $contacts = Contact::all(['limit' => 200]);
        $departments = Department::all();

        View::renderWithLayout('inbox/new', 'main', [
            'title' => 'Novo Atendimento',
            'activePage' => 'inbox',
            'contacts' => $contacts,
            'departments' => $departments,
            'channels' => \App\Models\Channel::getConnected(),
        ]);
    }

    public function createConversation(Request $request): void
    {
        $contactId = $request->post('contact_id');
        $departmentId = $request->post('department_id');
        $channelId = $request->post('channel_id');
        $subject = $request->post('subject');

        if (!$contactId || !$channelId) {
            Session::setFlash('error', 'Selecione um contato e um canal.');
            View::back();
        }

        $contact = Contact::find((int) $contactId);
        if (!$contact) {
            Session::setFlash('error', 'Contato não encontrado.');
            View::back();
        }

        $conversationId = Conversation::create([
            'contact_id' => (int) $contactId,
            'department_id' => $departmentId ? (int) $departmentId : null,
            'channel_id' => (int) $channelId,
            'inbox_id' => Inbox::resolveInboxForChannel((int) $channelId),
            'assigned_user_id' => Auth::id(),
            'subject' => $subject,
            'status' => 'open',
        ]);

        Conversation::addEvent($conversationId, 'created', 'Atendimento criado manualmente', Auth::id());

        $messageText = $request->post('message');
        if (!empty(trim($messageText ?? ''))) {
            $msgId = $this->conversationService->sendMessage($conversationId, $messageText, 'text', Auth::id());
            $this->dispatchWhatsApp($conversationId, $msgId, 'text', $messageText);
        }

        Session::setFlash('success', 'Atendimento criado com sucesso.');
        View::redirect("/inbox?conv={$conversationId}");
    }

    public function apiConversations(Request $request): void
    {
        $inboxes = Inbox::getUserInboxes(Auth::id());
        $ids = array_column($inboxes, 'id');

        // Filter by specific inbox if provided
        $inboxId = $request->input('inbox');
        if ($inboxId && in_array((int) $inboxId, $ids)) {
            $ids = [(int) $inboxId];
        }

        $filters = [];
        $statusFilter = $request->input('fstatus');
        if ($statusFilter && $statusFilter !== 'all') {
            $filters['status'] = $statusFilter;
        }
        $conversations = Conversation::getConversationsForInboxes($ids, Auth::id(), $filters);
        View::json($conversations);
    }

    /**
     * Fragmento HTML apenas da lista de conversas (usado para atualização
     * periódica sem recarregar a página inteira).
     */
    public function conversationsListFragment(Request $request): void
    {
        $search = trim((string) $request->input('search'));
        $inboxId = $request->input('inbox');
        $statusFilter = $request->input('fstatus');

        $inboxes = Inbox::getUserInboxes(Auth::id());
        $ids = array_column($inboxes, 'id');
        if ($inboxId && in_array((int) $inboxId, $ids)) {
            $ids = [(int) $inboxId];
        }
        $filters = [];
        if ($search) {
            $filters['search'] = $search;
        }
        if ($statusFilter && $statusFilter !== 'all') {
            $filters['status'] = $statusFilter;
        }
        $conversations = Conversation::getConversationsForInboxes($ids, Auth::id(), $filters);

        View::render('inbox/_conv_list', [
            'conversations' => $conversations,
            'activeInbox' => $inboxId ? (int) $inboxId : null,
            'fstatus' => $statusFilter,
            'activeConvId' => $request->input('conv') ? (int) $request->input('conv') : null,
        ]);
    }

    public function apiCreateConversation(Request $request): void
    {
        $contactId = (int) $request->post('contact_id');
        $departmentId = $request->post('department_id') ? (int) $request->post('department_id') : null;
        $channelId = (int) $request->post('channel_id');
        $subject = $request->post('subject');

        if (!$contactId || !$channelId) {
            View::json(['error' => 'Selecione um contato e um canal.'], 422);
            return;
        }

        $contact = Contact::find($contactId);
        if (!$contact) {
            View::json(['error' => 'Contato não encontrado.'], 404);
            return;
        }

        $conversationId = Conversation::create([
            'contact_id' => $contactId,
            'department_id' => $departmentId,
            'channel_id' => $channelId,
            'inbox_id' => Inbox::resolveInboxForChannel($channelId),
            'assigned_user_id' => Auth::id(),
            'subject' => $subject,
            'status' => 'open',
        ]);

        Conversation::addEvent($conversationId, 'created', 'Atendimento criado manualmente', Auth::id());

        $messageText = $request->post('message');
        if (!empty(trim($messageText ?? ''))) {
            $msgId = $this->conversationService->sendMessage($conversationId, $messageText, 'text', Auth::id());
            $this->dispatchWhatsApp($conversationId, $msgId, 'text', $messageText);
        } else {
            // Conversa manual sem mensagem inicial: ainda avisa a caixa
            // (antes era silenciosa e só aparecia no próximo inbound).
            try {
                \App\Services\NotificationService::notifyNewMessage($conversationId, 0, $subject ?: 'Nova conversa manual', Auth::id());
            } catch (\Throwable $e) {
                error_log('apiCreateConversation notify error: ' . $e->getMessage());
            }
        }

        View::json(['id' => $conversationId]);
    }

    /**
     * Entrega a mensagem outbound ao provedor de WhatsApp (se aplicável).
     * Retorna ['delivered' => bool, 'error' => ?string]. Falhas nunca
     * estouram: ficam registradas na mensagem (delivery_status=failed)
     * e o painel oferece "tentar de novo".
     *
     * @return array{delivered: bool, error: ?string}
     */
    private function dispatchWhatsApp(int $conversationId, int $messageId, string $type, string $content): array
    {
        $conv = Conversation::find($conversationId);
        if (($conv['channel_type'] ?? '') !== 'whatsapp') {
            return ['delivered' => true, 'error' => null];
        }
        try {
            $service = new \App\Services\WhatsAppService();
            $providerId = $service->sendOutbound($conversationId, $messageId, $type, $content);
        } catch (\Throwable $e) {
            error_log('WhatsApp outbound error: ' . $e->getMessage());
            Database::getInstance()->update(
                'messages',
                ['delivery_status' => 'failed'],
                'id = ?',
                [$messageId]
            );
            return ['delivered' => false, 'error' => 'Falha ao enviar: ' . $e->getMessage()];
        }
        if (!$providerId) {
            Database::getInstance()->update(
                'messages',
                ['delivery_status' => 'failed'],
                'id = ? AND (delivery_status IS NULL OR delivery_status <> ?)',
                [$messageId, 'sent']
            );
            $conn = \App\Models\WhatsAppConnection::findByChannel((int) ($conv['channel_id'] ?? 0));
            $detail = ($conn && ($conn['status'] ?? '') !== 'connected')
                ? 'Conexão WhatsApp desconectada. Reconecte e use "Tentar de novo".'
                : 'Provedor não confirmou o envio. Use "Tentar de novo".';
            return ['delivered' => false, 'error' => $detail];
        }
        return ['delivered' => true, 'error' => null];
    }

    /**
     * Reenvia uma mensagem outbound que falhou (sem channel_message_id).
     * POST /inbox/{id}/messages/{mid}/retry
     */
    public function retryMessage(Request $request, int $id, int $mid): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $msg = Conversation::getMessage($mid);
        if (!$msg || (int) $msg['conversation_id'] !== $id) {
            View::json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }
        if (($msg['direction'] ?? '') !== 'outbound'
            || ($msg['type'] ?? '') === 'internal_note'
            || !empty($msg['channel_message_id'])
        ) {
            View::json(['success' => false, 'error' => 'Nada a reenviar.'], 422);
            return;
        }
        Database::getInstance()->update(
            'messages',
            ['delivery_status' => 'pending'],
            'id = ?',
            [$mid]
        );
        $res = $this->dispatchWhatsApp($id, $mid, $msg['type'], $msg['content']);
        View::json([
            'success' => $res['delivered'],
            'delivered' => $res['delivered'],
            'error' => $res['error'],
            'message' => Conversation::getMessage($mid),
        ]);
    }

    public function apiMessages(Request $request, int $id): void
    {
        if (!$this->canUserSeeConversation($id, Auth::id())) {
            View::json(['error' => 'Sem acesso a esta conversa.'], 403);
            return;
        }
        $before = $request->input('before');
        $opts = [];
        if ($before) {
            $opts['before'] = (int) $before;
            $opts['limit'] = 50;
        }
        $messages = Conversation::getMessages($id, $opts);
        if (!$before) {
            Conversation::markMessagesAsRead($id, Auth::id());
            \App\Models\Notification::markConversationNotificationsRead($id, Auth::id());
            $this->markGroupMentionRead(Conversation::find($id));
        }
        View::json($messages);
    }

    public function apiMacros(Request $request): void
    {
        $deptId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        View::json(Macro::getForContext($deptId, Auth::id()));
    }

    /**
     * Resolve variáveis do texto para a conversa (pré-visualização no composer).
     * POST /api/template/render {content, conversation_id} → {success, rendered}
     */
    public function apiTemplateRender(Request $request): void
    {
        $content = (string) $request->input('content', '');
        $convId = (int) $request->input('conversation_id', 0);
        if ($convId <= 0 || !$this->canUserSeeConversation($convId, Auth::id())) {
            View::json(['success' => false, 'error' => 'Sem acesso a esta conversa.'], 403);
            return;
        }
        View::json(['success' => true, 'rendered' => \App\Services\TemplateService::render($content, $convId)]);
    }

    public function apiConversation(Request $request, int $id): void
    {
        if (!$this->canUserSeeConversation($id, Auth::id())) {
            View::json(['error' => 'Sem acesso a esta conversa.'], 403);
            return;
        }
        $conv = Conversation::find($id);
        if ($conv) {
            $conv['csat'] = Conversation::getCsat($id);
        }
        View::json($conv ?: new \stdClass());
    }

    public function apiCanned(Request $request): void
    {
        $deptId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        View::json(CannedResponse::getForContext($deptId, Auth::id()));
    }

    public function changePriority(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $priority = $request->post('priority');
        if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            Conversation::update($id, ['priority' => $priority]);
            Conversation::addEvent($id, 'priority_changed', "Prioridade alterada para: {$priority}", Auth::id());
        }
        if ($request->isAjax() || $request->wantsJson()) {
            View::json(['ok' => true, 'priority' => $priority]);
            return;
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function addTag(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $tagId = (int) $request->post('tag_id');
        $tagName = trim((string) $request->post('tag_name'));
        if ($tagName !== '' && (mb_strlen($tagName) > 50 || strpbrk($tagName, '<>&"\'') !== false)) {
            $tagName = '';
        }
        $color = '#6c757d';
        if (!$tagId && $tagName) {
            $existing = Database::getInstance()->fetch("SELECT id, color FROM tags WHERE name = ?", [$tagName]);
            if ($existing) {
                $tagId = (int) $existing['id'];
                $color = $existing['color'] ?? '#6c757d';
            } else {
                $tagId = Tag::create(['name' => $tagName, 'color' => $color]);
            }
        }
        if ($tagId) {
            Conversation::addTag($id, $tagId);
        }
        if ($request->isAjax()) {
            View::json(['success' => true, 'tag_id' => $tagId, 'color' => $color]);
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function removeTag(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $tagId = (int) $request->post('tag_id');
        if ($tagId) {
            Conversation::removeTag($id, $tagId);
        }
        if ($request->isAjax()) {
            View::json(['success' => true]);
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function sendReaction(Request $request, int $id, int $mid): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $msg = Conversation::getMessage($mid);
        if (!$msg || (int) $msg['conversation_id'] !== $id) {
            View::json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }
        $reaction = trim((string) $request->post('reaction'));
        if ($reaction === '') {
            View::json(['success' => false, 'error' => 'Reação vazia']);
            return;
        }
        $ok = Conversation::updateMessageReaction($mid, $reaction);
        if ($ok) {
            try {
                (new \App\Services\WhatsAppService())->sendReaction($id, $mid, $reaction);
            } catch (\Throwable $e) {
                error_log('sendReaction: ' . $e->getMessage());
            }
        }
        View::json(['success' => $ok, 'reactions' => $ok ? (Conversation::getMessage($mid)['reactions'] ?? null) : null]);
    }

    public function editMessage(Request $request, int $id, int $mid): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $existing = Conversation::getMessage($mid);
        if (!$existing || (int) $existing['conversation_id'] !== $id) {
            View::json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }
        $content = trim((string) $request->post('content'));
        $ok = false;
        if ($content !== '') {
            $ok = Conversation::updateMessage($mid, Auth::id(), $content);
        }
        if ($ok) {
            try {
                (new \App\Services\WhatsAppService())->editMessage($id, $mid, $content);
            } catch (\Throwable $e) {
                error_log('editMessage whatsapp: ' . $e->getMessage());
            }
        }
        if ($this->isAjax($request)) {
            View::json(['success' => $ok, 'content' => $ok ? $content : null]);
            return;
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function deleteMessage(Request $request, int $id, int $mid): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $existing = Conversation::getMessage($mid);
        if (!$existing || (int) $existing['conversation_id'] !== $id) {
            View::json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }
        $ok = Conversation::deleteMessage($mid, Auth::id());
        if ($ok) {
            try {
                (new \App\Services\WhatsAppService())->deleteMessage($id, $mid);
            } catch (\Throwable $e) {
                error_log('deleteMessage whatsapp: ' . $e->getMessage());
            }
        }
        if ($this->isAjax($request)) {
            View::json(['success' => $ok, 'deleted' => $ok]);
            return;
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function markRead(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        Conversation::markMessagesAsRead($id, Auth::id());
        View::json(['success' => true]);
    }

    public function snooze(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $until = $request->post('until');
        if ($until === '' || $until === null) {
            $until = null;
        }
        Conversation::snooze($id, $until);
        if ($this->isAjax($request)) {
            View::json(['success' => true]);
            return;
        }
        Session::setFlash('success', $until ? 'Atendimento agendado.' : 'Agendamento cancelado.');
        View::redirect('/inbox');
    }

    public function merge(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $targetId = (int) $request->post('target_id');
        if ($targetId && $targetId !== $id && $this->denyUnlessCanAccess($request, $targetId)) return;
        if ($targetId && $targetId !== $id) {
            Conversation::merge($id, $targetId);
            Session::setFlash('success', 'Conversas mescladas.');
            View::redirect("/inbox?conv={$targetId}");
        }
        Session::setFlash('error', 'Selecione uma conversa de destino válida.');
        View::redirect("/inbox?conv={$id}");
    }

    public function bulk(Request $request): void
    {
        $ids = $request->post('ids') ?: [];
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        $action = $request->post('action');
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_filter(array_map('intval', $ids));
        $ids = array_values(array_filter($ids, fn($cid) => $this->canUserSeeConversation($cid, Auth::id())));
        $skippedGroups = 0;
        foreach ($ids as $cid) {
            if ($action === 'close') {
                try { $this->conversationService->changeStatus($cid, 'closed'); }
                catch (\Throwable $e) { $skippedGroups++; }
            } elseif ($action === 'resolve') {
                try { $this->conversationService->changeStatus($cid, 'resolved'); }
                catch (\Throwable $e) { $skippedGroups++; }
            } elseif ($action === 'assign') {
                $uid = (int) $request->post('user_id');
                if ($uid) {
                    $this->conversationService->assign($cid, $uid);
                }
            } elseif ($action === 'snooze') {
                $until = $request->post('until');
                Conversation::snooze($cid, $until ?: null);
            }
        }
        if ($this->isAjax($request)) {
            View::json(['success' => true, 'count' => count($ids), 'skipped_groups' => $skippedGroups]);
            return;
        }
        $msg = 'Ação aplicada em ' . count($ids) . ' conversa(s).';
        if ($skippedGroups > 0) {
            $msg .= ' Grupos não podem ser encerrados (' . $skippedGroups . ' ignorado(s)).';
        }
        Session::setFlash('success', $msg);
        View::redirect('/inbox');
    }

    public function applyMacro(Request $request, int $id): void
    {
        if ($this->denyUnlessCanAccess($request, $id)) return;
        $macroId = (int) $request->post('macro_id');
        $macro = Macro::find($macroId);
        $ok = false;
        $sent = 0;
        if ($macro) {
            // Multi-mensagens: itens ordenados (texto e/ou mídia). Legado: content único.
            $items = $macro['items'] ?? [];
            if (empty($items) && !empty(trim((string) ($macro['content'] ?? '')))) {
                $items = [['type' => 'text', 'content' => $macro['content']]];
            }
            foreach ($items as $it) {
                $type = strtolower(trim((string) ($it['type'] ?? 'text')));
                if (!in_array($type, ['text', 'image', 'video', 'audio', 'file'], true)) {
                    $type = 'text';
                }
                try {
                    if ($type === 'text') {
                        $text = trim((string) ($it['content'] ?? ''));
                        if ($text === '') {
                            continue;
                        }
                        $text = $this->applyWhatsAppSignature($id, $text);
                        $msgId = $this->conversationService->sendMessage($id, $text, 'text', Auth::id());
                        $this->dispatchWhatsApp($id, $msgId, 'text', $text);
                        $sent++;
                    } else {
                        $mediaUrl = trim((string) ($it['media_url'] ?? ''));
                        if ($mediaUrl === '') {
                            continue;
                        }
                        $caption = trim((string) ($it['content'] ?? ''));
                        if ($caption !== '') {
                            $caption = $this->applyWhatsAppSignature($id, $caption);
                        }
                        $meta = [
                            'url' => $mediaUrl,
                            'name' => $it['media_name'] ?? basename(parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl),
                            'size' => (int) ($it['media_size'] ?? 0),
                            'mime' => $it['media_mime'] ?? '',
                            'path' => $it['media_path'] ?? null,
                        ];
                        if ($caption !== '') {
                            $meta['caption'] = $caption;
                        }
                        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $msgId = $this->conversationService->sendMessage($id, $metaJson, $type, Auth::id());
                        $this->dispatchWhatsApp($id, $msgId, $type, $metaJson);
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    error_log('applyMacro item error: ' . $e->getMessage());
                }
            }
            $actions = $macro['actions'] ? json_decode($macro['actions'], true) : null;
            if (is_array($actions)) {
                if (!empty($actions['status'])) {
                    try { $this->conversationService->changeStatus($id, $actions['status']); }
                    catch (\Throwable $e) { /* grupo: ignora status final */ }
                }
                if (!empty($actions['tag_id'])) {
                    Conversation::addTag($id, (int) $actions['tag_id']);
                }
                if (!empty($actions['assign_me'])) {
                    $this->conversationService->assign($id, Auth::id());
                }
                if (!empty($actions['transfer_inbox_id'])) {
                    $this->conversationService->transfer($id, null, null, (int) $actions['transfer_inbox_id']);
                }
            }
            $ok = true;
        }
        if ($this->isAjax($request)) {
            View::json(['success' => $ok, 'sent' => $sent]);
            return;
        }
        View::redirect("/inbox?conv={$id}");
    }

    private function isAjax(Request $request): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || $request->input('_ajax') === '1';
    }

    public function macros(Request $request): void
    {
        View::renderWithLayout('macros/index', 'main', [
            'title' => 'Macros',
            'activePage' => 'macros',
            'macros' => Macro::all(),
            'departments' => Department::all(),
            'tags' => Tag::all(),
            'inboxes' => Inbox::getUserInboxes(Auth::id()),
            'variables' => \App\Services\TemplateService::availableVariables(),
            'editMacro' => null,
        ]);
    }

    public function editMacro(Request $request, int $id): void
    {
        $macro = Macro::find($id);
        if (!$macro) {
            Session::setFlash('error', 'Macro não encontrada.');
            View::redirect('/macros');
        }
        View::renderWithLayout('macros/index', 'main', [
            'title' => 'Editar Macro',
            'activePage' => 'macros',
            'macros' => Macro::all(),
            'departments' => Department::all(),
            'tags' => Tag::all(),
            'inboxes' => Inbox::getUserInboxes(Auth::id()),
            'variables' => \App\Services\TemplateService::availableVariables(),
            'editMacro' => $macro,
        ]);
    }

    /**
     * Normaliza os itens da macro vindos do formulário (multi-mensagens).
     * Aceita tanto arrays paralelos (item_type[], item_content[], ...) quanto
     * JSON em 'items_json' (montado pelo JS). Mantém compat com 'content' legado.
     */
    private function parseMacroItems(Request $request): array
    {
        $json = trim((string) $request->post('items_json', ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $this->sanitizeMacroItems($decoded);
            }
        }
        $types = (array) ($request->post('item_type') ?? []);
        $contents = (array) ($request->post('item_content') ?? []);
        $urls = (array) ($request->post('item_media_url') ?? []);
        $names = (array) ($request->post('item_media_name') ?? []);
        $mimes = (array) ($request->post('item_media_mime') ?? []);
        $sizes = (array) ($request->post('item_media_size') ?? []);
        $paths = (array) ($request->post('item_media_path') ?? []);
        $n = max(count($types), count($contents), count($urls));
        $items = [];
        for ($i = 0; $i < $n; $i++) {
            $items[] = [
                'type' => $types[$i] ?? 'text',
                'content' => $contents[$i] ?? '',
                'media_url' => $urls[$i] ?? '',
                'media_name' => $names[$i] ?? '',
                'media_mime' => $mimes[$i] ?? '',
                'media_size' => $sizes[$i] ?? 0,
                'media_path' => $paths[$i] ?? '',
            ];
        }
        // Legado: textarea única 'content' sem itens
        if (empty(array_filter($items, fn($it) => trim((string) ($it['content'] ?? '')) !== '' || trim((string) ($it['media_url'] ?? '')) !== ''))) {
            $legacy = trim((string) $request->post('content', ''));
            if ($legacy !== '') {
                return [['type' => 'text', 'content' => $legacy]];
            }
        }
        return $this->sanitizeMacroItems($items);
    }

    private function sanitizeMacroItems(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $type = strtolower(trim((string) ($it['type'] ?? 'text')));
            if (!in_array($type, ['text', 'image', 'video', 'audio', 'file'], true)) {
                $type = 'text';
            }
            $content = trim((string) ($it['content'] ?? ''));
            $mediaUrl = trim((string) ($it['media_url'] ?? ''));
            if ($content === '' && $mediaUrl === '') {
                continue;
            }
            $out[] = [
                'type' => $type,
                'content' => $content,
                'media_url' => $mediaUrl,
                'media_name' => trim((string) ($it['media_name'] ?? '')),
                'media_mime' => trim((string) ($it['media_mime'] ?? '')),
                'media_size' => (int) ($it['media_size'] ?? 0),
                'media_path' => trim((string) ($it['media_path'] ?? '')),
            ];
            if (count($out) >= 10) {
                break; // limite de segurança: 10 mensagens por macro
            }
        }
        return $out;
    }

    private function macroActionsFromRequest(Request $request): ?string
    {
        $actions = [];
        if ($request->post('action_status')) {
            $actions['status'] = $request->post('action_status');
        }
        if ($request->post('action_tag_id')) {
            $actions['tag_id'] = (int) $request->post('action_tag_id');
        }
        if ($request->post('action_assign_me')) {
            $actions['assign_me'] = true;
        }
        if ($request->post('action_transfer_inbox_id')) {
            $actions['transfer_inbox_id'] = (int) $request->post('action_transfer_inbox_id');
        }
        return $actions ? json_encode($actions) : null;
    }

    public function storeMacro(Request $request): void
    {
        $title = trim((string) $request->post('title'));
        $departmentId = $request->post('department_id') ? (int) $request->post('department_id') : null;

        if ($title === '') {
            Session::setFlash('error', 'Informe um título para a macro.');
            View::redirect('/macros');
        }

        $items = $this->parseMacroItems($request);
        $firstText = '';
        foreach ($items as $it) {
            if (($it['type'] ?? 'text') === 'text' && !empty($it['content'])) {
                $firstText = $it['content'];
                break;
            }
        }

        $macroId = Macro::create([
            'title' => $title,
            'content' => $firstText !== '' ? $firstText : ($items[0]['content'] ?? ''),
            'department_id' => $departmentId,
            'user_id' => Auth::id(),
            'actions' => $this->macroActionsFromRequest($request),
        ]);
        Macro::replaceItems($macroId, $items);

        Session::setFlash('success', 'Macro criada com ' . count($items) . ' mensagem(ns).');
        View::redirect('/macros');
    }

    public function updateMacro(Request $request, int $id): void
    {
        $macro = Macro::find($id);
        if (!$macro) {
            Session::setFlash('error', 'Macro não encontrada.');
            View::redirect('/macros');
        }
        $title = trim((string) $request->post('title'));
        if ($title === '') {
            Session::setFlash('error', 'Informe um título para a macro.');
            View::redirect('/macros/' . $id . '/edit');
        }
        $departmentId = $request->post('department_id') ? (int) $request->post('department_id') : null;
        $items = $this->parseMacroItems($request);
        $firstText = '';
        foreach ($items as $it) {
            if (($it['type'] ?? 'text') === 'text' && !empty($it['content'])) {
                $firstText = $it['content'];
                break;
            }
        }
        Macro::update($id, [
            'title' => $title,
            'content' => $firstText !== '' ? $firstText : ($items[0]['content'] ?? ''),
            'department_id' => $departmentId,
            'actions' => $this->macroActionsFromRequest($request),
        ]);
        Macro::replaceItems($id, $items);
        Session::setFlash('success', 'Macro atualizada.');
        View::redirect('/macros');
    }

    public function deleteMacro(Request $request, int $id): void
    {
        Macro::delete($id);
        Session::setFlash('success', 'Macro removida.');
        View::redirect('/macros');
    }

    /**
     * Upload de mídia p/ itens da macro (foto, vídeo, arquivo, áudio).
     * POST /api/macros/upload — retorna {url,name,size,type,mime,path}.
     */
    public function uploadMacroMedia(Request $request): void
    {
        $uploaded = save_uploaded_file('file', null, 'macros');
        if (!$uploaded) {
            View::json(['error' => 'Arquivo inválido ou tipo não permitido.'], 422);
            return;
        }
        View::json([
            'url' => $uploaded['url'],
            'name' => $uploaded['name'],
            'size' => $uploaded['size'],
            'type' => $uploaded['type'],
            'mime' => $uploaded['mime'] ?? '',
            'path' => $uploaded['path'] ?? null,
        ]);
    }
}
