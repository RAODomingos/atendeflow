<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;

class ReportsController
{
    /**
     * GET /reports - Overview / landing page
     */
    public function index(Request $request): void
    {
        $db = Database::getInstance();
        $period = $request->get('period', '30');
        $days = in_array($period, ['7', '30', '90', 'all']) ? ($period === 'all' ? null : (int) $period) : 30;

        $whereDate = '';
        $params = [];
        if ($days !== null) {
            $whereDate = 'WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        $totalConversations = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations c {$whereDate}", $params
        )['c'] ?? 0);

        $resolvedCount = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations c {$whereDate} AND c.status = 'resolved'" . ($days ? '' : ' WHERE c.status = \'resolved\''),
            $days ? $params : []
        )['c'] ?? 0);
        if ($days === null) {
            $resolvedCount = (int) ($db->fetch(
                "SELECT COUNT(*) as c FROM conversations WHERE status = 'resolved'"
            )['c'] ?? 0);
        }

        $totalMessages = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM messages m JOIN conversations c ON c.id = m.conversation_id {$whereDate}",
            $params
        )['c'] ?? 0);

        $avgRating = $db->fetch(
            "SELECT AVG(cc.rating) as a FROM conversation_csats cc JOIN conversations c ON c.id = cc.conversation_id {$whereDate}",
            $params
        );
        $avgCsat = $avgRating && $avgRating['a'] !== null ? round((float) $avgRating['a'], 2) : null;

        $csatCount = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversation_csats cc JOIN conversations c ON c.id = cc.conversation_id {$whereDate}",
            $params
        )['c'] ?? 0);

        // Conversas por canal
        $channelData = $db->fetchAll(
            "SELECT ch.type, COUNT(c.id) as total
             FROM conversations c
             JOIN channels ch ON ch.id = c.channel_id
             {$whereDate}
             GROUP BY ch.type ORDER BY total DESC",
            $days ? $params : []
        );

        // Status breakdown
        $statusData = $db->fetchAll(
            "SELECT status, COUNT(*) as total FROM conversations c {$whereDate} GROUP BY status ORDER BY total DESC",
            $params
        );

        View::renderWithLayout('reports/index', 'main', [
            'title' => 'Relatórios',
            'activePage' => 'reports',
            'period' => $period,
            'totalConversations' => $totalConversations,
            'resolvedCount' => $resolvedCount,
            'totalMessages' => $totalMessages,
            'avgCsat' => $avgCsat,
            'csatCount' => $csatCount,
            'channelData' => $channelData,
            'statusData' => $statusData,
        ]);
    }

    /**
     * GET /reports/conversations - Relatório detalhado de conversas
     */
    public function conversations(Request $request): void
    {
        $db = Database::getInstance();
        $period = $request->get('period', '30');
        $days = in_array($period, ['7', '30', '90', 'all']) ? ($period === 'all' ? null : (int) $period) : 30;

        $whereDate = '';
        $whereDateC = '';
        $params = [];
        if ($days !== null) {
            $whereDate = 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $whereDateC = 'WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        // Tendência diária
        $trendRaw = $db->fetchAll(
            "SELECT DATE(created_at) as date, COUNT(*) as total
             FROM conversations {$whereDate}
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $params
        );
        $trendLabels = [];
        $trendValues = [];
        foreach ($trendRaw as $r) {
            $trendLabels[] = date('d/m', strtotime($r['date']));
            $trendValues[] = (int) $r['total'];
        }

        // Breakdown por status
        $statusData = $db->fetchAll(
            "SELECT status, COUNT(*) as total FROM conversations {$whereDate} GROUP BY status",
            $params
        );

        // Breakdown por canal
        $channelData = $db->fetchAll(
            "SELECT ch.type, COUNT(c.id) as total
             FROM conversations c
             JOIN channels ch ON ch.id = c.channel_id
             {$whereDateC}
             GROUP BY ch.type",
            $params
        );

        // Breakdown por departamento
        $deptData = $db->fetchAll(
            "SELECT d.name, d.color, COUNT(c.id) as total
             FROM conversations c
             JOIN departments d ON d.id = c.department_id
             {$whereDateC}
             GROUP BY d.id, d.name, d.color
             ORDER BY total DESC",
            $params
        );

        // Top contatos
        $topContacts = $db->fetchAll(
            "SELECT ct.name, ct.email, ct.phone, COUNT(c.id) as convos
             FROM conversations c
             JOIN contacts ct ON ct.id = c.contact_id
             {$whereDateC}
             GROUP BY ct.id, ct.name, ct.email, ct.phone
             ORDER BY convos DESC
             LIMIT 10",
            $params
        );

        View::renderWithLayout('reports/conversations', 'main', [
            'title' => 'Relatório de Conversas',
            'activePage' => 'reports_conversations',
            'period' => $period,
            'trendLabels' => $trendLabels,
            'trendValues' => $trendValues,
            'statusData' => $statusData,
            'channelData' => $channelData,
            'deptData' => $deptData,
            'topContacts' => $topContacts,
        ]);
    }

    /**
     * GET /reports/agents - Performance dos atendentes
     */
    public function agents(Request $request): void
    {
        $db = Database::getInstance();

        $agents = $db->fetchAll(
            "SELECT u.id, u.name, u.email, u.role,
                    COUNT(DISTINCT c.id) as active_convos,
                    COALESCE((SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'outbound'), 0) as total_messages,
                    COALESCE((SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'outbound' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) as msgs_7d,
                    COALESCE((SELECT COUNT(*) FROM conversations WHERE assigned_user_id = u.id AND status IN ('resolved', 'closed')), 0) as total_resolved,
                    COALESCE((SELECT COUNT(*) FROM conversations WHERE assigned_user_id = u.id AND status IN ('resolved', 'closed') AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)), 0) as resolved_30d,
                    (SELECT ROUND(AVG(cc.rating), 2) FROM conversation_csats cc JOIN conversations cx ON cx.id = cc.conversation_id WHERE cx.assigned_user_id = u.id) as avg_csat
             FROM users u
             LEFT JOIN conversations c ON c.assigned_user_id = u.id AND c.status NOT IN ('closed', 'spam')
             WHERE u.is_active = 1
             GROUP BY u.id, u.name, u.email, u.role
             ORDER BY active_convos DESC"
        );

        // Tempo médio de resposta por agente (separado para evitar subquery duplamente aninhada)
        $avgResponseRaw = $db->fetchAll(
            "SELECT resp.user_id, ROUND(AVG(TIMESTAMPDIFF(MINUTE, resp.in_time, resp.out_time)), 0) as avg_min
             FROM (
                 SELECT m1.conversation_id, m1.user_id,
                        MAX(CASE WHEN m2.direction = 'inbound' THEN m2.created_at END) as in_time,
                        MIN(CASE WHEN m1.direction = 'outbound' THEN m1.created_at END) as out_time
                 FROM messages m1
                 JOIN messages m2 ON m2.conversation_id = m1.conversation_id
                 WHERE m1.direction = 'outbound' AND m1.user_id IS NOT NULL
                   AND m1.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY m1.conversation_id, m1.user_id
                 HAVING in_time IS NOT NULL AND out_time IS NOT NULL AND out_time > in_time
             ) resp
             GROUP BY resp.user_id"
        );
        $avgResponseMap = [];
        foreach ($avgResponseRaw as $r) {
            $avgResponseMap[(int) $r['user_id']] = $r['avg_min'];
        }
        foreach ($agents as &$a) {
            $a['avg_response_min'] = $avgResponseMap[(int) $a['id']] ?? null;
        }
        unset($a);

        View::renderWithLayout('reports/agents', 'main', [
            'title' => 'Performance dos Atendentes',
            'activePage' => 'reports_agents',
            'agents' => $agents,
        ]);
    }

    /**
     * GET /reports/csat - Relatório CSAT (existente)
     */
    public function csat(Request $request): void
    {
        $period = $request->get('period', '30');
        $valid = ['7' => 7, '30' => 30, '90' => 90, 'all' => null];
        $days = $valid[$period] ?? 30;

        $where = '';
        $params = [];
        if ($days !== null) {
            $where = 'WHERE cc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        $db = Database::getInstance();

        $total = (int) ($db->fetch("SELECT COUNT(*) AS c FROM conversation_csats cc {$where}", $params)['c'] ?? 0);
        $avg = $db->fetch("SELECT AVG(rating) AS a FROM conversation_csats cc {$where}", $params)['a'] ?? null;
        $average = $avg !== null ? round((float) $avg, 2) : null;

        $distRows = $db->fetchAll(
            "SELECT rating, COUNT(*) AS c FROM conversation_csats cc {$where} GROUP BY rating ORDER BY rating DESC",
            $params
        );
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($distRows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['c'];
        }

        $recent = $db->fetchAll(
            "SELECT cc.*, conv.id AS conversation_id, conv.public_id, c.name AS contact_name,
                    ch.type AS channel_type, d.name AS department_name
             FROM conversation_csats cc
             JOIN conversations conv ON conv.id = cc.conversation_id
             LEFT JOIN contacts c ON c.id = conv.contact_id
             LEFT JOIN channels ch ON ch.id = conv.channel_id
             LEFT JOIN departments d ON d.id = conv.department_id
             {$where}
             ORDER BY cc.created_at DESC
             LIMIT 25",
            $params
        );

        View::renderWithLayout('reports/csat', 'main', [
            'title' => 'Relatório de CSAT',
            'activePage' => 'reports_csat',
            'period' => $period,
            'total' => $total,
            'average' => $average,
            'distribution' => $distribution,
            'recent' => $recent,
        ]);
    }
}
