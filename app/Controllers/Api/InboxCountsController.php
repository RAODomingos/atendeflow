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
        $catInboxes = [];
        foreach (Inbox::getUserInboxes($userId) as $ib) {
            if (($ib['type'] ?? '') !== 'personal') {
                $catInboxes[] = $ib;
            }
        }
        $personalInboxes = Inbox::getOwnedPersonalInboxes($userId);
        $allIds = array_merge(array_column($catInboxes, 'id'), array_column($personalInboxes, 'id'));

        if (empty($allIds)) {
            return [[], 0];
        }

        $counts = Conversation::openCountsByInbox($allIds);
        $total = 0;
        foreach ($catInboxes as $ib) {
            $total += (int) ($counts[$ib['id']] ?? 0);
        }

        return [$counts, $total];
    }
}
