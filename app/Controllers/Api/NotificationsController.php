<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Notification;

class NotificationsController
{
    /**
     * GET /api/notifications/dropdown
     * Lista as notificações estruturadas (menções, atribuições, novas conversas)
     * para popular o dropdown do sino. Combina notifications + unread_conversations.
     */
    public function dropdown(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        $limit = (int) $request->input('limit', 15);
        $limit = max(5, min($limit, 50));

        $notifications = Notification::getForDropdown($userId, $limit);

        View::json([
            'notifications' => $notifications,
            'unread_count' => Notification::getUnreadCount($userId),
        ]);
    }
}
