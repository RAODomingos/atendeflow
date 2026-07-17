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
}
