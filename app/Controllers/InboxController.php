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
                'contacts' => Contact::all(),
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
            'contacts' => Contact::all(),
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
        if ($conversation['inbox_id'] && !Inbox::canAccess((int) $conversation['inbox_id'], Auth::id())) {
            return null;
        }
        $conversation['csat'] = Conversation::getCsat($id);
        $allMessages = Conversation::getMessages($id);
        $hasOlder = count($allMessages) > 30;
        $messages = $hasOlder ? array_slice($allMessages, -30) : $allMessages;
        $firstMid = $hasOlder ? (int) $messages[0]['id'] : 0;

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
        ];
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
        View::render('inbox/panel', $detail);
    }

    public function mine(Request $request): void
    {
        $owned = Inbox::getOwnedPersonalInboxes(Auth::id());
        $ids = array_column($owned, 'id');
        $conversations = Conversation::getConversationsForInboxes($ids, Auth::id());

        View::renderWithLayout('inbox/index', 'main', [
            'title' => 'Minha Caixa',
            'activePage' => 'inbox',
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

        if ($conversation['inbox_id'] && !Inbox::canAccess((int) $conversation['inbox_id'], Auth::id())) {
            Session::setFlash('error', 'Voce nao tem acesso a esta conversa.');
            View::redirect('/inbox');
        }

        $messages = Conversation::getMessages($id);
        $events = Conversation::getEvents($id);
        $departments = Department::all();

        Conversation::markMessagesAsRead($id, Auth::id());

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

    public function sendMessage(Request $request, int $id): void
    {
        $content = $request->post('content');
        $hasText = !empty(trim($content ?? ''));
        $uploaded = null;
        $createdIds = [];
        $replyTo = $request->post('reply_to') ? (int) $request->post('reply_to') : null;

        $isInternal = $request->post('type') === 'internal';

        $file = $request->file('file');
        if (!empty($file['tmp_name'])) {
            $uploaded = save_uploaded_file('file');
        }

        if (!$hasText && !$uploaded) {
            if ($request->isAjax()) {
                View::json(['ok' => false, 'error' => 'Digite uma mensagem ou anexe um arquivo.']);
                return;
            }
            Session::setFlash('error', 'Digite uma mensagem ou anexe um arquivo.');
            View::back();
        }

        if ($uploaded) {
            $meta = [
                'url'  => $uploaded['url'],
                'name' => $uploaded['name'],
                'size' => $uploaded['size'],
                'mime' => $uploaded['mime'],
            ];
            $messageType = $isInternal ? 'internal_note' : $uploaded['type'];
            $msgId = $this->conversationService->sendMessage(
                $id,
                json_encode($meta),
                $messageType,
                Auth::id(),
                null,
                $replyTo
            );
            $createdIds[] = $msgId;
            if ($messageType !== 'internal_note') {
                $this->dispatchWhatsApp($id, $msgId, $messageType, json_encode($meta));
            }
        }

        if ($hasText) {
            $text = trim($content);
            $type = $isInternal ? 'internal_note' : 'text';
            if ($type === 'text') {
                $text = $this->applyWhatsAppSignature($id, $text);
            }
            $msgId = $this->conversationService->sendMessage($id, $text, $type, Auth::id(), null, $replyTo);
            $createdIds[] = $msgId;
            if ($type !== 'internal_note') {
                $this->dispatchWhatsApp($id, $msgId, $type, $text);
            }
        }

        if ($request->isAjax()) {
            $messages = [];
            foreach ($createdIds as $mid) {
                $m = Conversation::getMessage($mid);
                if ($m) {
                    $m['user_avatar'] = Auth::user()['avatar'] ?? null;
                    $messages[] = $m;
                }
            }
            View::json(['ok' => true, 'messages' => $messages]);
            return;
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
        $field = $request->post('field');
        if ($field !== 'signature_enabled') {
            View::json(['ok' => false, 'error' => 'Campo inválido'], 400);
            return;
        }

        $value = (bool) $request->post('value', 0) ? 1 : 0;
        Conversation::update($id, ['signature_enabled' => $value]);

        View::json(['ok' => true, 'value' => $value]);
    }

    public function assign(Request $request, int $id): void
    {
        $userId = $request->post('user_id');
        if ($userId) {
            $this->conversationService->assign($id, (int) $userId);
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function transfer(Request $request, int $id): void
    {
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
        $status = $request->post('status');
        if ($status && in_array($status, ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'])) {
            $reason = trim((string) $request->post('reason'));
            $description = trim((string) $request->post('description'));
            $this->conversationService->changeStatus($id, $status, $reason ?: null, $description ?: null);
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function addInternalNote(Request $request, int $id): void
    {
        $content = $request->post('content');
        if (!empty(trim($content ?? ''))) {
            Database::getInstance()->insert('internal_notes', [
                'conversation_id' => $id,
                'content' => $content,
                'user_id' => Auth::id(),
            ]);
            Conversation::addEvent($id, 'note_added', 'Nota interna adicionada', Auth::id());
        }
        View::redirect("/inbox/{$id}");
    }

    public function newConversation(Request $request): void
    {
        $contacts = Contact::all();
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
        }

        View::json(['id' => $conversationId]);
    }

    /**
     * Entrega a mensagem outbound ao provedor de WhatsApp (se aplicável).
     */
    private function dispatchWhatsApp(int $conversationId, int $messageId, string $type, string $content): void
    {
        try {
            $service = new \App\Services\WhatsAppService();
            $service->sendOutbound($conversationId, $messageId, $type, $content);
        } catch (\Throwable $e) {
            error_log('WhatsApp outbound error: ' . $e->getMessage());
        }
    }

    public function apiMessages(Request $request, int $id): void
    {
        $before = $request->input('before');
        $opts = [];
        if ($before) {
            $opts['before'] = (int) $before;
            $opts['limit'] = 50;
        }
        $messages = Conversation::getMessages($id, $opts);
        if (!$before) {
            Conversation::markMessagesAsRead($id, Auth::id());
        }
        View::json($messages);
    }

    public function apiMacros(Request $request): void
    {
        $deptId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        View::json(Macro::getForContext($deptId, Auth::id()));
    }

    public function apiConversation(Request $request, int $id): void
    {
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
        $priority = $request->post('priority');
        if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            Conversation::update($id, ['priority' => $priority]);
            Conversation::addEvent($id, 'priority_changed', "Prioridade alterada para: {$priority}", Auth::id());
        }
        View::redirect("/inbox?conv={$id}");
    }

    public function addTag(Request $request, int $id): void
    {
        $tagId = (int) $request->post('tag_id');
        $tagName = trim((string) $request->post('tag_name'));
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
        View::json(['success' => $ok]);
    }

    public function editMessage(Request $request, int $id, int $mid): void
    {
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
        Conversation::markMessagesAsRead($id, Auth::id());
        View::json(['success' => true]);
    }

    public function snooze(Request $request, int $id): void
    {
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

    public function csat(Request $request, int $id): void
    {
        $rating = (int) $request->post('rating');
        $comment = trim((string) $request->post('comment'));
        if ($rating >= 1 && $rating <= 5) {
            Conversation::addCsat($id, $rating, $comment);
        }
        if ($this->isAjax($request)) {
            View::json(['success' => true]);
            return;
        }
        Session::setFlash('success', 'Avaliação registrada.');
        View::redirect("/inbox?conv={$id}");
    }

    public function merge(Request $request, int $id): void
    {
        $targetId = (int) $request->post('target_id');
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
        foreach ($ids as $cid) {
            if ($action === 'close') {
                $this->conversationService->changeStatus($cid, 'closed');
            } elseif ($action === 'resolve') {
                $this->conversationService->changeStatus($cid, 'resolved');
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
            View::json(['success' => true, 'count' => count($ids)]);
            return;
        }
        Session::setFlash('success', 'Ação aplicada em ' . count($ids) . ' conversa(s).');
        View::redirect('/inbox');
    }

    public function applyMacro(Request $request, int $id): void
    {
        $macroId = (int) $request->post('macro_id');
        $macro = Macro::find($macroId);
        $ok = false;
        if ($macro) {
            if (!empty($macro['content'])) {
                $this->conversationService->sendMessage($id, $macro['content'], 'text', Auth::id());
            }
            $actions = $macro['actions'] ? json_decode($macro['actions'], true) : null;
            if (is_array($actions)) {
                if (!empty($actions['status'])) {
                    $this->conversationService->changeStatus($id, $actions['status']);
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
            View::json(['success' => $ok]);
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
        View::renderWithLayout('inbox/macros', 'main', [
            'title' => 'Macros',
            'macros' => Macro::all(),
            'departments' => Department::all(),
            'tags' => Tag::all(),
            'inboxes' => Inbox::getUserInboxes(Auth::id()),
        ]);
    }

    public function storeMacro(Request $request): void
    {
        $title = trim((string) $request->post('title'));
        $content = trim((string) $request->post('content'));
        $departmentId = $request->post('department_id') ? (int) $request->post('department_id') : null;
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

        if ($title === '') {
            Session::setFlash('error', 'Informe um título para a macro.');
            View::redirect('/macros');
        }

        Macro::create([
            'title' => $title,
            'content' => $content,
            'department_id' => $departmentId,
            'user_id' => Auth::id(),
            'actions' => $actions ? json_encode($actions) : null,
        ]);

        Session::setFlash('success', 'Macro criada.');
        View::redirect('/macros');
    }
}
