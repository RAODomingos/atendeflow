<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Notification;

class NotificationsController
{
    /**
     * GET /api/v2/notifications
     */
    public function index(Request $request): void
    {
        $unreadOnly = (bool) $request->input('unread_only', false);
        $limit = (int) $request->input('limit', 50);
        $notifications = Notification::getByUser(Auth::id(), $unreadOnly, $limit);
        $unreadCount = Notification::getUnreadCount(Auth::id());

        View::json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * POST /api/v2/notifications/{id}/read
     */
    public function markRead(Request $request, int $id): void
    {
        Notification::markAsRead($id, Auth::id());
        View::json(['ok' => true]);
    }

    /**
     * POST /api/v2/notifications/read-all
     */
    public function markAllRead(Request $request): void
    {
        Notification::markAllAsRead(Auth::id());
        View::json(['ok' => true]);
    }

    /**
     * GET /api/v2/notifications/unread-count
     */
    public function unreadCount(Request $request): void
    {
        View::json([
            'count' => Notification::getUnreadCount(Auth::id()),
        ]);
    }

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
