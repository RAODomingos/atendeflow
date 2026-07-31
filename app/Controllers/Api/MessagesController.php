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
        $row = \App\Core\Database::getInstance()->fetch(
            "SELECT id, conversation_id, direction FROM messages WHERE id = ?",
            [$id]
        );
        if (!$row) {
            View::json(['ok' => false, 'error' => 'Mensagem não encontrada'], 404);
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
        \App\Core\Database::getInstance()->update(
            'conversations',
            ['last_typing_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
        View::json(['ok' => true, 'ts' => date('c')]);
    }
}
