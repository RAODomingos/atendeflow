<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\Notification;

class UnreadSummaryController
{
    public function index(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        $unreadMessages = Conversation::getUnreadCount($userId);
        $unreadNotifications = Notification::getUnreadCount($userId);

        View::json([
            'unread_messages' => $unreadMessages,
            'unread_notifications' => $unreadNotifications,
            'total' => $unreadMessages + $unreadNotifications,
        ]);
    }
}
