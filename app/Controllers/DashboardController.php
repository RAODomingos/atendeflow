<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\User;

class DashboardController
{
    public function index(Request $request): void
    {
        $userId = Auth::id();
        $isAdmin = Auth::isAdmin();

        $myConversations = Conversation::getInboxConversations($userId, null, 'open');
        $counts = Conversation::countByStatus($userId);
        $unread = Conversation::getUnreadCount($userId);
        $onlineUsers = User::getOnlineCount();

        $totalOpen = 0;
        foreach (['new', 'open', 'waiting_customer', 'waiting_internal'] as $s) {
            $totalOpen += $counts[$s] ?? 0;
        }

        $db = Database::getInstance();

        // Métricas do dia
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

        // Tendência últimos 7 dias (conversas por dia)
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

        // Tempo médio de resposta (minutos) - última mensagem do agente - última mensagem do cliente
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

        // Conversas por departamento
        $deptData = $db->fetchAll(
            "SELECT d.name, d.color, COUNT(c.id) as total
             FROM departments d
             LEFT JOIN conversations c ON c.department_id = d.id AND c.status NOT IN ('closed', 'spam', 'resolved')
             GROUP BY d.id, d.name, d.color
             ORDER BY total DESC"
        );

        // Performance dos atendentes (top 5)
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

        if ($isAdmin) {
            $totalUsers = count(User::all());
            $totalDepartments = $db->fetch("SELECT COUNT(*) as t FROM departments")['t'];
            $allCounts = Conversation::countByStatus();
            $totalAll = 0;
            foreach (['new', 'open', 'waiting_customer', 'waiting_internal'] as $s) {
                $totalAll += $allCounts[$s] ?? 0;
            }
        } else {
            $totalUsers = 0;
            $totalDepartments = 0;
            $totalAll = 0;
            $allCounts = [];
            $agentData = [];
        }

        $recentConversations = Conversation::getInboxConversations(null, null, 'open');
        $recentConversations = array_slice($recentConversations, 0, 10);

        View::renderWithLayout('dashboard/index', 'main', [
            'title' => 'Dashboard',
            'activePage' => 'dashboard',
            'myConversations' => $myConversations,
            'counts' => $counts,
            'unread' => $unread,
            'onlineUsers' => $onlineUsers,
            'totalOpen' => $totalOpen,
            'totalUsers' => $totalUsers,
            'totalDepartments' => $totalDepartments,
            'totalAll' => $totalAll,
            'allCounts' => $allCounts,
            'recentConversations' => $recentConversations,
            'isAdmin' => $isAdmin,
            'todayConversations' => $todayConversations,
            'todayMessages' => $todayMessages,
            'todayResolved' => $todayResolved,
            'avgResponseTime' => $avgResponseTime,
            'trendLabels' => $trendLabels,
            'trendValues' => $trendValues,
            'deptData' => $deptData,
            'agentData' => $agentData,
        ]);
    }
}
