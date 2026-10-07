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

        // Cache de 30s por usuário/perfil: o dashboard faz 15+ queries por
        // load + auto-refresh. Contadores toleram 30s de defasagem.
        $data = \App\Core\FileCache::remember(
            'dashboard:' . $userId . ':' . ($isAdmin ? 'admin' : 'user'),
            function () use ($userId, $isAdmin) {
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

        // --- Yesterday comparisons (for delta badges) ---
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

        // --- CSAT (last 30 days) ---
        $csatRow = $db->fetch(
            "SELECT ROUND(AVG(rating), 2) as avg_rating, COUNT(*) as total
             FROM conversation_csats
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $csatAvg = $csatRow && $csatRow['avg_rating'] !== null ? (float) $csatRow['avg_rating'] : null;
        $csatCount = (int) ($csatRow['total'] ?? 0);

        // --- Trend (last 7 days): conversations + resolved ---
        $trendData = $db->fetchAll(
            "SELECT DATE(created_at) as date, COUNT(*) as total
             FROM conversations
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        $resolvedTrendData = $db->fetchAll(
            "SELECT DATE(closed_at) as date, COUNT(*) as total
             FROM conversations
             WHERE status = 'resolved' AND closed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY DATE(closed_at)
             ORDER BY date ASC"
        );
        $trendLabels = [];
        $trendValues = [];
        $trendResolvedValues = [];
        $trendMap = [];
        $resolvedTrendMap = [];
        foreach ($trendData as $row) {
            $trendMap[$row['date']] = (int) $row['total'];
        }
        foreach ($resolvedTrendData as $row) {
            $resolvedTrendMap[$row['date']] = (int) $row['total'];
        }
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trendLabels[] = date('d/m', strtotime($d));
            $trendValues[] = $trendMap[$d] ?? 0;
            $trendResolvedValues[] = $resolvedTrendMap[$d] ?? 0;
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

        // --- Atendimento normal x grupos WhatsApp (group_id) ---
        $hasGroups = Conversation::hasGroupColumn();
        $split = ['normal' => [], 'groups' => []];
        $mySplit = ['normal' => [], 'groups' => []];
        $todayNormalConvs = $todayGroupConvs = 0;
        $prevNormalConvs = $prevGroupConvs = 0;
        $todayNormalMsgs = $todayGroupMsgs = 0;
        $todayNormalResolved = $todayGroupResolved = 0;
        $trendNormalValues = $trendGroupValues = [];
        $groupOpen = $groupNew = $myGroupOpen = 0;
        $groupMentionsUnread = 0;
        $totalGroups = 0;
        $groupsTop = [];
        if ($hasGroups) {
            try {
                $split = Conversation::countByStatusSplit();
                $mySplit = Conversation::countByStatusSplit($userId);
                foreach (['new', 'open', 'waiting_customer', 'waiting_internal'] as $s) {
                    $groupOpen += $split['groups'][$s] ?? 0;
                }
                $groupNew = $split['groups']['new'] ?? 0;
                foreach (['new', 'open', 'waiting_customer', 'waiting_internal'] as $s) {
                    $myGroupOpen += $mySplit['groups'][$s] ?? 0;
                }
                $normalOpen = $globalOpen - $groupOpen;

                $convSplit = function (string $from, string $to) use ($db) {
                    $rows = $db->fetchAll(
                        "SELECT (group_id IS NOT NULL) as g, COUNT(*) as c FROM conversations
                         WHERE created_at >= ? AND created_at < ? GROUP BY g",
                        [$from, $to]
                    );
                    $n = $g = 0;
                    foreach ($rows as $r) {
                        if (!empty($r['g'])) $g = (int) $r['c'];
                        else $n = (int) $r['c'];
                    }
                    return [$n, $g];
                };
                [$todayNormalConvs, $todayGroupConvs] = $convSplit($todayStart, date('Y-m-d H:i:s', strtotime('+1 day', strtotime($todayStart))));
                [$prevNormalConvs, $prevGroupConvs] = $convSplit($yesterdayStart, $todayStart);

                $resSplit = function (string $from, string $to) use ($db) {
                    $rows = $db->fetchAll(
                        "SELECT (group_id IS NOT NULL) as g, COUNT(*) as c FROM conversations
                         WHERE status = 'resolved' AND closed_at >= ? AND closed_at < ? GROUP BY g",
                        [$from, $to]
                    );
                    $n = $g = 0;
                    foreach ($rows as $r) {
                        if (!empty($r['g'])) $g = (int) $r['c'];
                        else $n = (int) $r['c'];
                    }
                    return [$n, $g];
                };
                [$todayNormalResolved, $todayGroupResolved] = $resSplit($todayStart, date('Y-m-d H:i:s', strtotime('+1 day', strtotime($todayStart))));

                $msgRows = $db->fetchAll(
                    "SELECT (c.group_id IS NOT NULL) as g, COUNT(*) as c FROM messages m
                     JOIN conversations c ON c.id = m.conversation_id
                     WHERE m.created_at >= ? GROUP BY g",
                    [$todayStart]
                );
                foreach ($msgRows as $r) {
                    if (!empty($r['g'])) $todayGroupMsgs = (int) $r['c'];
                    else $todayNormalMsgs = (int) $r['c'];
                }

                // Tendência 7d separada (novas conversas por dia)
                $trendSplitRows = $db->fetchAll(
                    "SELECT DATE(created_at) as date, (group_id IS NOT NULL) as g, COUNT(*) as total
                     FROM conversations
                     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                     GROUP BY DATE(created_at), g"
                );
                $trendNormalMap = $trendGroupMap = [];
                foreach ($trendSplitRows as $row) {
                    if (!empty($row['g'])) $trendGroupMap[$row['date']] = (int) $row['total'];
                    else $trendNormalMap[$row['date']] = (int) $row['total'];
                }
                for ($i = 6; $i >= 0; $i--) {
                    $d = date('Y-m-d', strtotime("-{$i} days"));
                    $trendNormalValues[] = $trendNormalMap[$d] ?? 0;
                    $trendGroupValues[] = $trendGroupMap[$d] ?? 0;
                }

                // Painel de grupos: menções não lidas + top grupos por atividade
                $groupMentionsUnread = \App\Models\WhatsAppGroup::unreadMentionsTotal();
                $allGroups = \App\Models\WhatsAppGroup::allWithDetails();
                $totalGroups = count($allGroups);
                $groupsTop = array_slice($allGroups, 0, 5);
            } catch (\Throwable $e) {
                $hasGroups = false;
            }
        }
        if (!$hasGroups) {
            $normalOpen = $globalOpen;
            $split = ['normal' => $globalCounts, 'groups' => array_fill_keys(['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'], 0)];
            $mySplit = ['normal' => $myCounts, 'groups' => []];
            $todayNormalConvs = $todayConversations;
            $prevNormalConvs = $prevDayConversations;
            $todayNormalMsgs = $todayMessages;
            $todayNormalResolved = $todayResolved;
            $trendNormalValues = $trendValues;
            $trendGroupValues = array_fill(0, 7, 0);
        }

        // --- Recent conversations (global, unassigned + assigned) ---
        $recentConversations = Conversation::getInboxConversations(null, null, 'open');
        $recentConversations = array_slice($recentConversations, 0, 10);
        if ($hasGroups) {
            try {
                $gids = array_values(array_unique(array_filter(array_map(
                    fn($c) => $c['group_id'] ?? null,
                    $recentConversations
                ))));
                $groupNames = [];
                if ($gids) {
                    $ph = implode(',', array_fill(0, count($gids), '?'));
                    foreach ($db->fetchAll("SELECT id, name FROM whatsapp_groups WHERE id IN ({$ph})", $gids) as $gr) {
                        $groupNames[$gr['id']] = $gr['name'];
                    }
                }
                foreach ($recentConversations as &$rc) {
                    if (!empty($rc['group_id'])) {
                        $rc['group_name'] = $groupNames[$rc['group_id']] ?? 'Grupo';
                    }
                }
                unset($rc);
            } catch (\Throwable $e) {
            }
        }

        // --- Admin-only data ---
        if ($isAdmin) {
            $totalUsers = count(User::all());
            $totalDepartments = (int) ($db->fetch("SELECT COUNT(*) as t FROM departments")['t'] ?? 0);
        } else {
            $totalUsers = 0;
            $totalDepartments = 0;
            $agentData = [];
        }

                return [
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
            'prevDayConversations' => $prevDayConversations,
            'prevDayMessages' => $prevDayMessages,
            'prevDayResolved' => $prevDayResolved,
            'csatAvg' => $csatAvg,
            'csatCount' => $csatCount,
            'avgResponseTime' => $avgResponseTime,
            'trendLabels' => $trendLabels,
            'trendValues' => $trendValues,
            'trendResolvedValues' => $trendResolvedValues,
            'hasGroups' => $hasGroups,
            'split' => $split,
            'mySplit' => $mySplit,
            'normalOpen' => $normalOpen ?? $globalOpen,
            'groupOpen' => $groupOpen,
            'groupNew' => $groupNew,
            'myGroupOpen' => $myGroupOpen,
            'todayNormalConvs' => $todayNormalConvs,
            'todayGroupConvs' => $todayGroupConvs,
            'prevNormalConvs' => $prevNormalConvs,
            'prevGroupConvs' => $prevGroupConvs,
            'todayNormalMsgs' => $todayNormalMsgs,
            'todayGroupMsgs' => $todayGroupMsgs,
            'todayNormalResolved' => $todayNormalResolved,
            'todayGroupResolved' => $todayGroupResolved,
            'trendNormalValues' => $trendNormalValues,
            'trendGroupValues' => $trendGroupValues,
            'groupMentionsUnread' => $groupMentionsUnread,
            'totalGroups' => $totalGroups,
            'groupsTop' => $groupsTop,
            'deptData' => $deptData,
            'agentData' => $agentData,
            'recentConversations' => $recentConversations,
                    'isAdmin' => $isAdmin,
                ];
            },
            30
        );

        View::renderWithLayout('dashboard/index', 'main', $data);
    }
}
