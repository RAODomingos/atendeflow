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

        // Mesmo cache de 30s do dashboard (auto-refresh a cada 20s).
        $data = \App\Core\FileCache::remember(
            'dashboard-stats:' . $userId,
            function () use ($userId) {
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

        // Separação normal x grupos (espelha o DashboardController)
        $hasGroups = Conversation::hasGroupColumn();
        $normalOpen = $globalOpen;
        $groupOpen = 0;
        $groupNew = 0;
        $myGroupOpen = 0;
        $todayNormalConvs = $todayConversations;
        $todayGroupConvs = 0;
        $todayNormalMsgs = $todayMessages;
        $todayGroupMsgs = 0;
        $groupMentionsUnread = 0;
        $totalGroups = 0;
        if ($hasGroups) {
            try {
                $split = Conversation::countByStatusSplit();
                $mySplit = Conversation::countByStatusSplit($userId);
                foreach (['new', 'open', 'waiting_customer', 'waiting_internal'] as $s) {
                    $groupOpen += $split['groups'][$s] ?? 0;
                    $myGroupOpen += $mySplit['groups'][$s] ?? 0;
                }
                $groupNew = $split['groups']['new'] ?? 0;
                $normalOpen = $globalOpen - $groupOpen;
                $row = $db->fetch(
                    "SELECT SUM(group_id IS NULL) as n, SUM(group_id IS NOT NULL) as g FROM conversations WHERE created_at >= ?",
                    [$todayStart]
                );
                $todayNormalConvs = (int) ($row['n'] ?? 0);
                $todayGroupConvs = (int) ($row['g'] ?? 0);
                $row = $db->fetch(
                    "SELECT SUM(c.group_id IS NULL) as n, SUM(c.group_id IS NOT NULL) as g FROM messages m
                     JOIN conversations c ON c.id = m.conversation_id WHERE m.created_at >= ?",
                    [$todayStart]
                );
                $todayNormalMsgs = (int) ($row['n'] ?? 0);
                $todayGroupMsgs = (int) ($row['g'] ?? 0);
                $groupMentionsUnread = \App\Models\WhatsAppGroup::unreadMentionsTotal();
                try {
                    $totalGroups = (int) ($db->fetch("SELECT COUNT(*) as c FROM whatsapp_groups")['c'] ?? 0);
                } catch (\Throwable $e) {
                    $totalGroups = 0;
                }
            } catch (\Throwable $e) {
                $hasGroups = false;
            }
        }

        return [
            'globalOpen' => $globalOpen,
            'normalOpen' => $normalOpen,
            'groupOpen' => $groupOpen,
            'groupNew' => $groupNew,
            'myGroupOpen' => $myGroupOpen,
            'groupMentionsUnread' => $groupMentionsUnread,
            'hasGroups' => $hasGroups,
            'todayNormalConvs' => $todayNormalConvs,
            'todayGroupConvs' => $todayGroupConvs,
            'todayNormalMsgs' => $todayNormalMsgs,
            'todayGroupMsgs' => $todayGroupMsgs,
            'totalGroups' => $totalGroups,
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
        ];
            },
            30
        );

        View::json($data);
    }
}
