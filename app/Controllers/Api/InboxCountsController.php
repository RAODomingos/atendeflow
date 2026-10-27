<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\Inbox;

class InboxCountsController
{
    /**
     * GET /api/inbox-counts
     * Retorna a quantidade de conversas em aberto por caixa do usuário,
     * no mesmo critério do menu lateral (new/open/waiting_*).
     * Usado para atualizar os badges em tempo real mesmo sem a caixa aberta.
     */
    public function index(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        [$counts, $total] = self::countsForUser($userId);

        View::json([
            'counts' => $counts,
            'total' => $total,
        ]);
    }

    /**
     * @return array{0: array<int,int>, 1: int}
     */
    public static function countsForUser(int $userId): array
    {
        $inboxes = Inbox::getUserInboxes($userId);
        $allIds = array_map('intval', array_column($inboxes, 'id'));

        $counts = Conversation::openCountsByInbox($allIds);

        // O total da caixa de entrada é a contagem do escopo, e NÃO a soma
        // dos badges: as caixas se sobrepõem (uma conversa pode estar em
        // duas), então somar contaria a mesma conversa duas vezes.
        $total = Conversation::openCountForSets(Conversation::scopeSetsForUser($userId));

        return [$counts, $total];
    }
}
