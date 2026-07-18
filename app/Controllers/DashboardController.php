<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Inbox;

class DashboardController
{
    public function index(Request $request): void
    {
        $userId = Auth::id();
        $isAdmin = Auth::isAdmin();
        $db = Database::getInstance();

        // --- Global stats (all inboxes the user can access) ---
        $inboxes = Inbox::getUserInboxes($userId);
        $inboxIds = array_column($inboxes, 'id');

        $globalCounts = Conversation::countByStatus();
        $globalOpen = 0;
        foreach (['new', 'open', 'waiting_customer', 'waiting_internal'] as $s) {
            $globalOpen += $globalCounts[$s] ?? 0;
        }

        // --- Personal stats (only the current user) ---
        $myCounts = Conversation::countByStatus($userId);
        $myOpen = 0;
        foreach (['new', 'open', 'waiting_customer', 'waiting_internal'] as $s) {
            $myOpen += $myCounts[$s] ?? 0;
        }
        $unread = Conversation::getUnreadCount($userId);
        $onlineUsers = User::getOnlineCount();

        // --- Today metrics (global) ---
        $todayStart = date('Y-m-d 00:00:00');
        $todayConversations = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations WHERE created_at >= ?", [$todayStart]
        )['c'] ?? 0);
        $todayMessages = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM messages WHERE created_at >= ?", [$todayStart]
        )['c'] ?? 0);
        $todayResolved = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations WHERE status = 'resolved' AND closed_at >= ?", [$todayStart]
        )['c'] ?? 0);

        // --- Trend (last 7 days) ---
        $trendData = $db->fetchAll(
            "SELECT DATE(created_at) as date, COUNT(*) as total
             FROM conversations
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $trendLabels = [];
        $trendValues = [];
        $trendMap = [];
        foreach ($trendData as $row) {
            $trendMap[$row['date']] = (int) $row['total'];
        }
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trendLabels[] = date('d/m', strtotime($d));
            $trendValues[] = $trendMap[$d] ?? 0;
        }

        // --- Avg response time (30 days) ---
        $avgResponse = $db->fetch(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, inbound_time, outbound_time)) as avg_minutes
             FROM (
                 SELECT m.conversation_id,
                        MAX(CASE WHEN m.direction = 'inbound' THEN m.created_at END) as inbound_time,
                        MIN(CASE WHEN m.direction = 'outbound' AND m.type = 'text' THEN m.created_at END) as outbound_time
                 FROM messages m
                 WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY m.conversation_id
                 HAVING inbound_time IS NOT NULL AND outbound_time IS NOT NULL
             ) sub"
        );
        $avgResponseTime = $avgResponse && $avgResponse['avg_minutes'] !== null
            ? round((float) $avgResponse['avg_minutes']) : null;

        // --- Conversations by department (active only) ---
        $deptData = $db->fetchAll(
            "SELECT d.name, d.color, COUNT(c.id) as total
             FROM departments d
             LEFT JOIN conversations c ON c.department_id = d.id AND c.status NOT IN ('closed', 'spam', 'resolved')
             GROUP BY d.id, d.name, d.color
             ORDER BY total DESC"
        );

        // --- Agent performance (top 5) ---
        $agentData = $db->fetchAll(
            "SELECT u.id, u.name,
                    COUNT(c.id) as active_convos,
                    (SELECT COUNT(*) FROM messages m WHERE m.user_id = u.id AND m.direction = 'outbound' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as msgs_7d,
                    (SELECT COUNT(*) FROM conversations c2 WHERE c2.assigned_user_id = u.id AND c2.status IN ('resolved', 'closed') AND c2.closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as resolved_30d
             FROM users u
             LEFT JOIN conversations c ON c.assigned_user_id = u.id AND c.status NOT IN ('closed', 'spam', 'resolved')
             WHERE u.is_active = 1
             GROUP BY u.id, u.name
             ORDER BY active_convos DESC
             LIMIT 5"
        );

        // --- Inbox breakdown (open count per inbox) ---
        $openByInbox = Conversation::openCountsByInbox($inboxIds);

        // --- Recent conversations (global, unassigned + assigned) ---
        $recentConversations = Conversation::getInboxConversations(null, null, 'open');
        $recentConversations = array_slice($recentConversations, 0, 10);

        // --- Admin-only data ---
        if ($isAdmin) {
            $totalUsers = count(User::all());
            $totalDepartments = (int) ($db->fetch("SELECT COUNT(*) as t FROM departments")['t'] ?? 0);
        } else {
            $totalUsers = 0;
            $totalDepartments = 0;
            $agentData = [];
        }

        View::renderWithLayout('dashboard/index', 'main', [
            'title' => 'Dashboard',
            'activePage' => 'dashboard',
            'inboxes' => $inboxes,
            'openByInbox' => $openByInbox,
            'globalCounts' => $globalCounts,
            'globalOpen' => $globalOpen,
            'myCounts' => $myCounts,
            'myOpen' => $myOpen,
            'unread' => $unread,
            'onlineUsers' => $onlineUsers,
            'totalUsers' => $totalUsers,
            'totalDepartments' => $totalDepartments,
            'todayConversations' => $todayConversations,
            'todayMessages' => $todayMessages,
            'todayResolved' => $todayResolved,
            'avgResponseTime' => $avgResponseTime,
            'trendLabels' => $trendLabels,
            'trendValues' => $trendValues,
            'deptData' => $deptData,
            'agentData' => $agentData,
            'recentConversations' => $recentConversations,
            'isAdmin' => $isAdmin,
        ]);
    }
}
