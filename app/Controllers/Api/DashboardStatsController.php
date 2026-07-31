<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\User;

class DashboardStatsController
{
    public function index(Request $request): void
    {
        $userId = Auth::id();
        $db = Database::getInstance();

        $globalCounts = Conversation::countByStatus();
        $myCounts = Conversation::countByStatus($userId);
        $unread = Conversation::getUnreadCount($userId);
        $onlineUsers = User::getOnlineCount();

        $todayStart = date('Y-m-d 00:00:00');
        $yesterdayStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $todayConversations = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations WHERE created_at >= ?", [$todayStart]
        )['c'] ?? 0);
        $todayMessages = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM messages WHERE created_at >= ?", [$todayStart]
        )['c'] ?? 0);
        $todayResolved = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations WHERE status = 'resolved' AND closed_at >= ?", [$todayStart]
        )['c'] ?? 0);

        $prevDayConversations = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations WHERE created_at >= ? AND created_at < ?",
            [$yesterdayStart, $todayStart]
        )['c'] ?? 0);
        $prevDayMessages = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM messages WHERE created_at >= ? AND created_at < ?",
            [$yesterdayStart, $todayStart]
        )['c'] ?? 0);
        $prevDayResolved = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations WHERE status = 'resolved' AND closed_at >= ? AND closed_at < ?",
            [$yesterdayStart, $todayStart]
        )['c'] ?? 0);

        $csatRow = $db->fetch(
            "SELECT ROUND(AVG(rating), 2) as avg_rating, COUNT(*) as total
             FROM conversation_csats
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $csatAvg = $csatRow && $csatRow['avg_rating'] !== null ? (float) $csatRow['avg_rating'] : null;
        $csatCount = (int) ($csatRow['total'] ?? 0);

        $globalOpen = 0;
        $myOpen = 0;
        foreach (['new', 'open', 'waiting_customer', 'waiting_internal'] as $s) {
            $globalOpen += $globalCounts[$s] ?? 0;
            $myOpen += $myCounts[$s] ?? 0;
        }

        View::json([
            'globalOpen' => $globalOpen,
            'myOpen' => $myOpen,
            'unread' => $unread,
            'onlineUsers' => $onlineUsers,
            'todayConversations' => $todayConversations,
            'todayMessages' => $todayMessages,
            'todayResolved' => $todayResolved,
            'prevDayConversations' => $prevDayConversations,
            'prevDayMessages' => $prevDayMessages,
            'prevDayResolved' => $prevDayResolved,
            'csatAvg' => $csatAvg,
            'csatCount' => $csatCount,
            'counts' => $globalCounts,
            'myCounts' => $myCounts,
        ]);
    }
}
