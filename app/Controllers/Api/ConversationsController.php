<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Tag;
use App\Models\CannedResponse;
use App\Models\Macro;
use App\Services\ConversationService;

class ConversationsController
{
    private ConversationService $conversationService;

    public function __construct()
    {
        $this->conversationService = new ConversationService();
    }

    /**
     * GET /api/v2/conversations
     */
    public function index(Request $request): void
    {
        $filters = [];
        $statusFilter = $request->input('status', 'open');
        $inboxId = $request->input('inboxId');
        $search = trim((string) $request->input('search'));

        if ($statusFilter && $statusFilter !== 'all') {
            $filters['status'] = $statusFilter;
        }
        if ($search) {
            $filters['search'] = $search;
        }

        if ($inboxId) {
            $conversations = Conversation::getConversationsForInbox((int) $inboxId, Auth::id(), $filters);
        } elseif ($request->input('mine')) {
            $owned = Inbox::getOwnedPersonalInboxes(Auth::id());
            $ids = array_column($owned, 'id');
            $conversations = Conversation::getConversationsForInboxes($ids, Auth::id(), $filters);
        } elseif ($request->input('unassigned')) {
            $conversations = Conversation::getUnassigned();
        } else {
            $inboxes = Inbox::getUserInboxes(Auth::id());
            $ids = array_column($inboxes, 'id');
            $conversations = Conversation::getConversationsForInboxes($ids, Auth::id(), $filters);
        }

        $counts = Conversation::countByStatus(Auth::id());
        $unread = Conversation::getUnreadCount(Auth::id());
        $departments = Department::all();
        $allInboxes = Inbox::getUserInboxes(Auth::id());

        View::json([
            'conversations' => $conversations,
            'counts' => $counts,
            'unread' => $unread,
            'departments' => $departments,
            'inboxes' => $allInboxes,
        ]);
    }

    /**
     * GET /api/v2/conversations/{id}
     */
    public function show(Request $request, int $id): void
    {
        $conversation = Conversation::find($id);
        if (!$conversation) {
            View::json(['error' => 'Conversa não encontrada'], 404);
            return;
        }

        $conversation['csat'] = Conversation::getCsat($id);
        $messages = Conversation::getMessages($id);
        $events = Conversation::getEvents($id);
        $contact = Contact::find($conversation['contact_id']);
        $otherConversations = Conversation::getByContact($conversation['contact_id'], $id);
        $allTags = Tag::all();
        $departments = Department::all();

        View::json([
            'conversation' => $conversation,
            'messages' => $messages,
            'events' => $events,
            'contact' => $contact,
            'otherConversations' => $otherConversations,
            'allTags' => $allTags,
            'departments' => $departments,
            'csat' => Conversation::getCsat($id),
        ]);
    }

    /**
     * GET /api/v2/conversations/{id}/messages
     */
    public function messages(Request $request, int $id): void
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

    /**
     * POST /api/v2/conversations/{id}/messages
     */
    public function sendMessage(Request $request, int $id): void
    {
        $content = trim((string) $request->post('content'));
        $type = $request->post('type', 'text');
        $isInternal = $type === 'internal_note';

        if (empty($content)) {
            View::json(['error' => 'Digite uma mensagem'], 400);
            return;
        }

        $msgId = $this->conversationService->sendMessage($id, $content, $isInternal ? 'internal_note' : 'text', Auth::id());

        if (!$isInternal) {
            $this->dispatchWhatsApp($id, $msgId, 'text', $content);
        }

        $message = Conversation::getMessage($msgId);
        View::json($message);
    }

    /**
     * PUT /api/v2/conversations/{id}/status
     */
    public function changeStatus(Request $request, int $id): void
    {
        $status = $request->post('status');
        if ($status && in_array($status, ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'])) {
            $this->conversationService->changeStatus($id, $status);
            View::json(['ok' => true, 'status' => $status]);
        } else {
            View::json(['error' => 'Status inválido'], 400);
        }
    }

    /**
     * PUT /api/v2/conversations/{id}/assign
     */
    public function assign(Request $request, int $id): void
    {
        $userId = $request->post('user_id');
        $departmentId = $request->post('department_id');

        if ($userId) {
            $this->conversationService->assign($id, (int) $userId);
        } elseif ($departmentId) {
            $this->conversationService->transfer($id, (int) $departmentId, null);
        }

        View::json(['ok' => true]);
    }

    /**
     * PUT /api/v2/conversations/{id}/priority
     */
    public function changePriority(Request $request, int $id): void
    {
        $priority = $request->post('priority');
        if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            Conversation::update($id, ['priority' => $priority]);
            Conversation::addEvent($id, 'priority_changed', "Prioridade alterada para: {$priority}", Auth::id());
            View::json(['ok' => true, 'priority' => $priority]);
        } else {
            View::json(['error' => 'Prioridade inválida'], 400);
        }
    }

    private function dispatchWhatsApp(int $conversationId, int $messageId, string $type, string $content): void
    {
        try {
            $service = new \App\Services\WhatsAppService();
            $service->sendOutbound($conversationId, $messageId, $type, $content);
        } catch (\Throwable $e) {
            error_log('WhatsApp outbound error: ' . $e->getMessage());
        }
    }
}
