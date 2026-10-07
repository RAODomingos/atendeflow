<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Notification;

class MessagesController
{
    public function readAll(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }
        Conversation::markAllMessagesAsRead($userId);
        Notification::markAllAsRead($userId);
        View::json(['ok' => true]);
    }

    /**
     * POST /api/messages/{id}/read
     * Marca uma mensagem específica como lida (usado pelo cliente/WhatsApp
     * para confirmar leitura e gerar o "✓✓" no agente).
     */
    public function markRead(Request $request, int $id): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }
        $row = \App\Core\Database::getInstance()->fetch(
            "SELECT m.id, m.conversation_id, m.direction, c.assigned_user_id, c.inbox_id FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.id = ?",
            [$id]
        );
        if (!$row) {
            View::json(['ok' => false, 'error' => 'Mensagem não encontrada'], 404);
            return;
        }
        // Ownership: só quem tem acesso à conversa pode confirmar leitura.
        $canSee = Auth::isAdmin()
            || ((int) ($row['assigned_user_id'] ?? 0) === (int) $userId)
            || (!empty($row['inbox_id']) && \App\Models\Inbox::canAccess((int) $row['inbox_id'], (int) $userId));
        if (!$canSee) {
            View::json(['ok' => false, 'error' => 'Sem acesso'], 403);
            return;
        }
        if ($row['direction'] !== 'outbound') {
            View::json(['ok' => false, 'error' => 'Mensagem não é outbound'], 400);
            return;
        }
        \App\Core\Database::getInstance()->update(
            'messages',
            ['read_at' => date('Y-m-d H:i:s')],
            'id = ? AND read_at IS NULL',
            [$id]
        );
        View::json(['ok' => true]);
    }

    /**
     * POST /api/conversations/{id}/typing
     * Heartbeat do cliente (webchat) indicando que está digitando.
     * Apenas atualiza um flag na conversa (last_typing_at) usado para
     * exibir o indicador "está digitando..." no painel do atendente.
     */
    public function typing(Request $request, int $id): void
    {
        $conv = Conversation::find($id);
        if (!$conv) {
            View::json(['ok' => false], 404);
            return;
        }
        $userId = Auth::id();
        if (!Auth::isAdmin()
            && (int) ($conv['assigned_user_id'] ?? 0) !== (int) $userId
            && (empty($conv['inbox_id']) || !\App\Models\Inbox::canAccess((int) $conv['inbox_id'], (int) $userId))) {
            View::json(['ok' => false, 'error' => 'Sem acesso'], 403);
            return;
        }
        \App\Core\Database::getInstance()->update(
            'conversations',
            ['last_typing_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
        View::json(['ok' => true, 'ts' => date('c')]);
    }
}
