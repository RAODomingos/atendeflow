<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;

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

        $whereResolved = $days !== null ? "{$whereDate} AND c.status = 'resolved'" : "WHERE c.status = 'resolved'";
        $resolvedCount = (int) ($db->fetch(
            "SELECT COUNT(*) as c FROM conversations c {$whereResolved}", $params
        )['c'] ?? 0);

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

        $summary = Conversation::csatSummary($days);

        View::renderWithLayout('reports/csat', 'main', [
            'title' => 'Relatório de CSAT',
            'activePage' => 'reports_csat',
            'period' => $period,
            'total' => $summary['total'],
            'average' => $summary['average'],
            'distribution' => Conversation::csatDistribution($days),
            'recent' => Conversation::csatRecent($days),
        ]);
    }

    /**
     * GET /reports/timeline - Listagem de conversas agrupadas por dia/semana/mês/ano.
     * Inclui filtros, comparação entre períodos e heatmap de horários.
     */
    public function timeline(Request $request): void
    {
        $db = Database::getInstance();
        $timeline = $this->resolveTimelineParams($request);

        $whereC = $timeline['where'];
        $paramsC = $timeline['params'];

        // Paginação real: evita renderizar 2000 cards de uma vez.
        $page = max(1, (int) $request->get('page', 1));
        $perPage = (int) $request->get('per_page', 100);
        if ($perPage < 20) $perPage = 20;
        if ($perPage > 200) $perPage = 200;

        $totalRow = $db->fetch(
            "SELECT COUNT(*) as t FROM conversations c {$whereC}",
            $paramsC
        );
        $totalAll = (int) ($totalRow['t'] ?? 0);
        $pages = max(1, (int) ceil($totalAll / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        // Página atual (leve, só o necessário p/ listagem).
        $rows = $db->fetchAll(
            "SELECT c.id, c.contact_id, c.department_id, c.assigned_user_id, c.channel_id,
                    c.status, c.priority, c.subject, c.created_at, c.last_message_at,
                    c.closed_at, c.close_reason, c.message_count_cache,
                    ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone,
                    d.name AS department_name, d.color AS department_color,
                    u.name AS assigned_user_name,
                    ch.name AS channel_name, ch.type AS channel_type
             FROM conversations c
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             LEFT JOIN departments d ON d.id = c.department_id
             LEFT JOIN users u ON u.id = c.assigned_user_id
             LEFT JOIN channels ch ON ch.id = c.channel_id
             {$whereC}
             ORDER BY c.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $paramsC
        );

        $groups = $this->groupByTimeline($rows, $timeline['granularity']);
        $stats = $this->computeTimelineStats($rows);
        $stats['total'] = $totalAll;
        $stats['pages'] = $pages;
        $stats['page'] = $page;
        $stats['per_page'] = $perPage;
        $comparison = $this->computeComparison($db, $timeline);
        $heatmap = $this->computeHeatmap($rows);
        $peakHour = $this->findPeakHour($heatmap);

        // Opções para selects
        $channels = $db->fetchAll("SELECT id, name, type FROM channels WHERE is_active = 1 ORDER BY name");
        $departments = $db->fetchAll("SELECT id, name, color FROM departments ORDER BY name");

        View::renderWithLayout('reports/timeline', 'main', [
            'title' => 'Linha do Tempo de Conversas',
            'activePage' => 'reports_timeline',
            'timeline' => $timeline,
            'groups' => $groups,
            'stats' => $stats,
            'comparison' => $comparison,
            'heatmap' => $heatmap,
            'peakHour' => $peakHour,
            'channels' => $channels,
            'departments' => $departments,
        ]);
    }

    /**
     * GET /reports/timeline/pdf - Exporta conversas do timeline (filtradas ou selecionadas) em PDF.
     */
    public function timelinePdf(Request $request): void
    {
        $db = Database::getInstance();
        $timeline = $this->resolveTimelineParams($request);

        $ids = $this->extractIds($request);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $whereC = "WHERE c.id IN ($placeholders)";
        } else {
            $whereC = $timeline['where'];
            $params = $timeline['params'];
        }

        $rows = $db->fetchAll(
            "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                    ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone,
                    d.name AS department_name, d.color AS department_color,
                    u.name AS assigned_user_name
             FROM conversations c
             LEFT JOIN channels ch ON ch.id = c.channel_id
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             LEFT JOIN departments d ON d.id = c.department_id
             LEFT JOIN users u ON u.id = c.assigned_user_id
             {$whereC}
             ORDER BY c.created_at DESC
             LIMIT 500",
            $params
        );

        if (empty($rows)) {
            \App\Core\Session::setFlash('error', 'Nenhuma conversa encontrada para os filtros selecionados.');
            \App\Core\View::redirect('/reports/timeline');
        }
        if (count($rows) > 100 && empty($ids)) {
            // PDF com 500 conversas estoura memória/DOMPDF: pagina na fonte.
            \App\Core\Session::setFlash('error', 'Filtro muito amplo para PDF (máx. 100). Refine o período ou selecione conversas específicas.');
            \App\Core\View::redirect('/reports/timeline');
        }

        $allTags = $db->fetchAll("SELECT * FROM tags ORDER BY name");
        // Tags em lote (1 query, não N).
        $tagsByConv = [];
        $rowIds = array_map(fn($c) => (int) $c['id'], $rows);
        if ($rowIds) {
            $ph = implode(',', array_fill(0, count($rowIds), '?'));
            foreach ($db->fetchAll(
                "SELECT ct.conversation_id as cid, t.* FROM tags t
                 INNER JOIN conversation_tags ct ON ct.tag_id = t.id
                 WHERE ct.conversation_id IN ($ph)",
                $rowIds
            ) as $t) {
                $tagsByConv[(int) $t['cid']][] = $t;
            }
        }
        $conversationsData = [];
        foreach ($rows as $conv) {
            $cid = (int) $conv['id'];
            $conv['tags'] = $tagsByConv[$cid] ?? [];
            $conversationsData[] = [
                'conversation' => $conv,
                'messages'     => \App\Models\Conversation::getMessages($cid),
                'events'       => \App\Models\Conversation::getEvents($cid),
                'csat'         => \App\Models\Conversation::getCsat($cid),
            ];
        }

        $html = \App\Core\View::renderBuffer('reports/timeline_pdf', [
            'timeline' => $timeline,
            'conversationsData' => $conversationsData,
            'allTags' => $allTags,
            'isSelection' => !empty($ids),
            'selectedCount' => count($ids),
        ]);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html);
        $dompdf->render();

        while (ob_get_level()) { ob_end_clean(); }

        $filename = 'relatorio-conversas-' . date('Y-m-d') . '.pdf';
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }

    /**
     * GET /reports/timeline/csv - Exporta conversas do timeline (filtradas ou selecionadas) em CSV.
     */
    public function timelineCsv(Request $request): void
    {
        $db = Database::getInstance();
        $timeline = $this->resolveTimelineParams($request);

        $ids = $this->extractIds($request);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $whereC = "WHERE c.id IN ($placeholders)";
        } else {
            $whereC = $timeline['where'];
            $params = $timeline['params'];
        }

        $rows = $db->fetchAll(
            "SELECT c.id, c.created_at, c.last_message_at, c.closed_at, c.status, c.priority,
                    c.subject, c.message_count_cache,
                    ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone,
                    d.name AS department_name, d.color AS department_color,
                    u.name AS assigned_user_name,
                    ch.name AS channel_name, ch.type AS channel_type
             FROM conversations c
             LEFT JOIN channels ch ON ch.id = c.channel_id
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             LEFT JOIN departments d ON d.id = c.department_id
             LEFT JOIN users u ON u.id = c.assigned_user_id
             {$whereC}
             ORDER BY c.created_at DESC
             LIMIT 5000",
            $params
        );

        if (empty($rows)) {
            \App\Core\Session::setFlash('error', 'Nenhuma conversa encontrada para os filtros selecionados.');
            \App\Core\View::redirect('/reports/timeline');
        }

        $statusLabels = [
            'new' => 'Novo', 'open' => 'Aberto',
            'waiting_customer' => 'Aguardando cliente', 'waiting_internal' => 'Aguardando interno',
            'resolved' => 'Resolvido', 'closed' => 'Fechado', 'spam' => 'Spam',
        ];
        $priorityLabels = ['low' => 'Baixa', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente'];

        $delim = function ($v) { return "\"".str_replace(["\r", "\n", '"'], [' ', ' ', '""'], (string) $v)."\""; };
        $col = function ($v) use ($delim) {
            // Fórmulas potencialmente perigosas (p. ex. =SUM(...)) são prefixadas para evitar injeção de fórmulas.
            $s = (string) $v;
            if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) {
                return $delim("'\t" . $s);
            }
            return $delim($s);
        };

        // BOM UTF-8 para compatibilidade com Excel
        $out = "\xEF\xBB\xBF";
        $out .= implode(',', array_map($col, [
            '#', 'Data criação', 'Status', 'Prioridade', 'Contato', 'E-mail', 'Telefone',
            'Departamento', 'Atendente', 'Canal', 'Assunto', 'Mensagens', 'Data último msg', 'Data fechamento',
        ])) . "\r\n";

        foreach ($rows as $r) {
            $out .= implode(',', array_map($col, [
                $r['id'],
                $r['created_at'],
                $statusLabels[$r['status']] ?? $r['status'],
                $priorityLabels[$r['priority']] ?? $r['priority'],
                $r['contact_name'],
                $r['contact_email'],
                $r['contact_phone'],
                $r['department_name'],
                $r['assigned_user_name'],
                $r['channel_name'],
                $r['subject'],
                $r['message_count_cache'],
                $r['last_message_at'],
                $r['closed_at'],
            ])) . "\r\n";
        }

        while (ob_get_level()) { ob_end_clean(); }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="conversas-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: max-age=0');
        echo $out;
        exit;
    }

    /**
     * Resolve e valida os parâmetros de filtro/granularidade do timeline.
     * Retorna {granularity, from, to, where, params, whereSql, paramsC, label}.
     */
    private function resolveTimelineParams(Request $request): array
    {
        $granularity = $request->get('granularity', 'day');
        if (!in_array($granularity, ['day', 'week', 'month', 'year'], true)) {
            $granularity = 'day';
        }

        // Defaults de período por granularidade
        $defaults = [
            'day'   => 14,
            'week'  => 84,   // 12 semanas
            'month' => 365,  // 12 meses
            'year'  => 1825, // 5 anos
        ];
        $defaultDays = $defaults[$granularity];

        $from = trim((string) $request->get('from', ''));
        $to   = trim((string) $request->get('to', ''));

        if (!$from || !$to) {
            $toDate = date('Y-m-d');
            $fromDate = date('Y-m-d', strtotime("-{$defaultDays} days"));
            $from = $from ?: $fromDate;
            $to   = $to   ?: $toDate;
        }
        // Validação simples
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime("-{$defaultDays} days"));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

        $whereParts = ['DATE(c.created_at) >= ?', 'DATE(c.created_at) <= ?'];
        $params = [$from, $to];

        $status = trim((string) $request->get('status', ''));
        if ($status !== '') {
            $whereParts[] = 'c.status = ?';
            $params[] = $status;
        }
        $channelId = (int) $request->get('channel_id', 0);
        if ($channelId > 0) {
            $whereParts[] = 'c.channel_id = ?';
            $params[] = $channelId;
        }
        $departmentId = (int) $request->get('department_id', 0);
        if ($departmentId > 0) {
            $whereParts[] = 'c.department_id = ?';
            $params[] = $departmentId;
        }
        $contactId = (int) $request->get('contact_id', 0);
        if ($contactId > 0) {
            $whereParts[] = 'c.contact_id = ?';
            $params[] = $contactId;
        }

        $where = 'WHERE ' . implode(' AND ', $whereParts);

        return [
            'granularity' => $granularity,
            'from' => $from,
            'to' => $to,
            'where' => $where,
            'params' => $params,
            'status' => $status,
            'channel_id' => $channelId,
            'department_id' => $departmentId,
            'contact_id' => $contactId,
        ];
    }

    /**
     * Extrai e valida lista de IDs (?ids=1,2,3).
     * @return int[]
     */
    private function extractIds(Request $request): array
    {
        $raw = trim((string) $request->get('ids', ''));
        if ($raw === '') return [];
        $ids = array_values(array_filter(array_map('intval', explode(',', $raw)), fn($v) => $v > 0));
        return $ids;
    }

    /**
     * Agrupa as conversas por período de acordo com a granularidade.
     * @return array<int, array{key:string,label:string,from:string,to:string,total:int,open:int,closed:int,conversations:array}>
     */
    private function groupByTimeline(array $rows, string $granularity): array
    {
        $buckets = [];
        $monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                       'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        $weekdayNames = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

        foreach ($rows as $r) {
            $created = strtotime($r['created_at']);
            $key = '';
            $label = '';
            $from = '';
            $to = '';
            switch ($granularity) {
                case 'day':
                    $key = date('Y-m-d', $created);
                    $label = date('d/m/Y', $created) . ' (' . $weekdayNames[date('w', $created)] . ')';
                    $from = $key;
                    $to = $key;
                    break;
                case 'week':
                    // Semana ISO começando na segunda
                    $dow = (int) date('N', $created); // 1=Seg, 7=Dom
                    $monday = strtotime('-' . ($dow - 1) . ' days', $created);
                    $key = date('Y-m-d', $monday);
                    $label = 'Semana de ' . date('d/m', $monday) . ' a ' . date('d/m', strtotime('+6 days', $monday));
                    $from = date('Y-m-d', $monday);
                    $to = date('Y-m-d', strtotime('+6 days', $monday));
                    break;
                case 'month':
                    $key = date('Y-m', $created);
                    $label = $monthNames[(int) date('n', $created)] . ' / ' . date('Y', $created);
                    $from = date('Y-m-01', $created);
                    $to = date('Y-m-t', $created);
                    break;
                case 'year':
                    $key = date('Y', $created);
                    $label = $key;
                    $from = $key . '-01-01';
                    $to = $key . '-12-31';
                    break;
            }

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'from' => $from,
                    'to' => $to,
                    'total' => 0,
                    'open' => 0,
                    'closed' => 0,
                    'spam' => 0,
                    'resolved' => 0,
                    'avg_response_min' => null,
                    'csat_avg' => null,
                    'top_agent' => null,
                    'top_channel' => null,
                    'conversations' => [],
                ];
            }
            $buckets[$key]['total']++;
            $buckets[$key]['conversations'][] = $r;
            $status = $r['status'] ?? '';
            if (in_array($status, ['new', 'open', 'waiting_customer', 'waiting_internal'], true)) {
                $buckets[$key]['open']++;
            } elseif (in_array($status, ['resolved', 'closed'], true)) {
                $buckets[$key]['closed']++;
                if ($status === 'resolved') $buckets[$key]['resolved']++;
            } elseif ($status === 'spam') {
                $buckets[$key]['spam']++;
            }
        }

        // Ordena do mais recente para o mais antigo
        krsort($buckets);

        // Pós-processa cada bucket: top agent, top channel (PHP), CSAT e
        // tempo médio em lote (2 queries p/ todos os ids, não 2 por bucket).
        $db = Database::getInstance();
        $allIds = [];
        foreach ($buckets as $b) {
            foreach ($b['conversations'] as $c) {
                $allIds[] = (int) $c['id'];
            }
        }
        $allIds = array_values(array_unique($allIds));
        $csatByConv = [];
        $respByConv = [];
        if (!empty($allIds)) {
            $ph = implode(',', array_fill(0, count($allIds), '?'));
            foreach ($db->fetchAll(
                "SELECT conversation_id, AVG(rating) as a FROM conversation_csats WHERE conversation_id IN ($ph) GROUP BY conversation_id",
                $allIds
            ) as $r) {
                $csatByConv[(int) $r['conversation_id']] = (float) $r['a'];
            }
            // Primeira resposta outbound por conversa + último inbound anterior.
            $outRows = $db->fetchAll(
                "SELECT m1.conversation_id as cid, MIN(m1.created_at) as out_t
                 FROM messages m1
                 WHERE m1.direction = 'outbound' AND m1.conversation_id IN ($ph)
                 GROUP BY m1.conversation_id",
                $allIds
            );
            $inMap = [];
            if ($outRows) {
                $outIds = array_column($outRows, 'cid');
                $ph2 = implode(',', array_fill(0, count($outIds), '?'));
                // Último inbound por conversa (aproximação em lote; refinado abaixo pelo out_t).
                foreach ($db->fetchAll(
                    "SELECT conversation_id as cid, MAX(created_at) as in_t FROM messages
                     WHERE direction = 'inbound' AND conversation_id IN ($ph2) GROUP BY conversation_id",
                    $outIds
                ) as $r) {
                    $inMap[(int) $r['cid']] = $r['in_t'];
                }
            }
            $outByConv = [];
            foreach ($outRows as $r) {
                $outByConv[(int) $r['cid']] = $r['out_t'];
            }
            foreach ($outByConv as $cid => $outT) {
                $inT = $inMap[$cid] ?? null;
                if ($inT !== null && $outT > $inT) {
                    $diff = (strtotime($outT) - strtotime($inT)) / 60;
                    if ($diff >= 0 && $diff < 10080) {
                        $respByConv[$cid] = $diff;
                    }
                }
            }
        }
        foreach ($buckets as &$b) {
            // Top agent
            $agents = [];
            $channels = [];
            foreach ($b['conversations'] as $c) {
                $a = $c['assigned_user_name'] ?? 'Não atribuído';
                $agents[$a] = ($agents[$a] ?? 0) + 1;
                $ch = $c['channel_name'] ?? $c['channel_type'] ?? '-';
                $channels[$ch] = ($channels[$ch] ?? 0) + 1;
            }
            arsort($agents);
            arsort($channels);
            $b['top_agent'] = $agents ? array_key_first($agents) . ' (' . current($agents) . ')' : '—';
            $b['top_channel'] = $channels ? array_key_first($channels) . ' (' . current($channels) . ')' : '—';

            // CSAT médio e tempo médio a partir dos mapas em lote (sem query por bucket).
            $ids = array_column($b['conversations'], 'id');
            if (!empty($ids)) {
                $sum = 0; $n = 0;
                foreach ($ids as $cid) {
                    if (isset($csatByConv[(int) $cid])) { $sum += $csatByConv[(int) $cid]; $n++; }
                }
                $b['csat_avg'] = $n > 0 ? round($sum / $n, 2) : null;

                $rsum = 0; $rn = 0;
                foreach ($ids as $cid) {
                    if (isset($respByConv[(int) $cid])) { $rsum += $respByConv[(int) $cid]; $rn++; }
                }
                $b['avg_response_min'] = $rn > 0 ? (int) round($rsum / $rn) : null;
            }
        }
        unset($b);

        return array_values($buckets);
    }

    private function computeTimelineStats(array $rows): array
    {
        $total = count($rows);
        $open = $closed = $spam = $resolved = 0;
        $byChannel = [];
        $byStatus = [];
        $byDepartment = [];
        $byAgent = [];
        $messagesTotal = 0;
        foreach ($rows as $r) {
            $messagesTotal += (int) ($r['message_count_cache'] ?? 0);
            $status = $r['status'] ?? '';
            if (in_array($status, ['new', 'open', 'waiting_customer', 'waiting_internal'], true)) $open++;
            elseif (in_array($status, ['resolved', 'closed'], true)) { $closed++; if ($status === 'resolved') $resolved++; }
            elseif ($status === 'spam') $spam++;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $ch = $r['channel_name'] ?? $r['channel_type'] ?? '-';
            $byChannel[$ch] = ($byChannel[$ch] ?? 0) + 1;
            $dp = $r['department_name'] ?? 'Sem departamento';
            $byDepartment[$dp] = ($byDepartment[$dp] ?? 0) + 1;
            $ag = $r['assigned_user_name'] ?? 'Não atribuído';
            $byAgent[$ag] = ($byAgent[$ag] ?? 0) + 1;
        }
        arsort($byChannel); arsort($byStatus); arsort($byDepartment); arsort($byAgent);
        return [
            'total' => $total,
            'open' => $open,
            'closed' => $closed,
            'spam' => $spam,
            'resolved' => $resolved,
            'messages_total' => $messagesTotal,
            'avg_per_day' => $total > 0 ? round($messagesTotal / max(1, $total), 1) : 0,
            'by_channel' => array_slice($byChannel, 0, 6, true),
            'by_status' => $byStatus,
            'by_department' => array_slice($byDepartment, 0, 6, true),
            'by_agent' => array_slice($byAgent, 0, 5, true),
        ];
    }

    /**
     * Compara o período atual com o período anterior de mesma duração.
     * Retorna array com deltas por métrica.
     */
    private function computeComparison(Database $db, array $timeline): array
    {
        $from = strtotime($timeline['from']);
        $to   = strtotime($timeline['to']);
        if (!$from || !$to || $to <= $from) return [];
        $days = (int) floor(($to - $from) / 86400) + 1;
        $prevTo = date('Y-m-d', strtotime('-1 day', $from));
        $prevFrom = date('Y-m-d', strtotime("-{$days} days", strtotime($prevTo)));

        $current = $this->countConversations($db, $timeline['from'], $timeline['to']);
        $previous = $this->countConversations($db, $prevFrom, $prevTo);

        $delta = function ($c, $p) {
            if (!$p) return $c > 0 ? 'new' : 0;
            $diff = round(($c - $p) / max(1, $p) * 100);
            return $diff;
        };
        return [
            'current_from' => $timeline['from'],
            'current_to' => $timeline['to'],
            'previous_from' => $prevFrom,
            'previous_to' => $prevTo,
            'current' => $current,
            'previous' => $previous,
            'delta_total' => $delta($current['total'], $previous['total']),
            'delta_open' => $delta($current['open'], $previous['open']),
            'delta_closed' => $delta($current['closed'], $previous['closed']),
        ];
    }

    private function countConversations(Database $db, string $from, string $to): array
    {
        $row = $db->fetch(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('new','open','waiting_customer','waiting_internal') THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status IN ('resolved','closed') THEN 1 ELSE 0 END) as closed
             FROM conversations
             WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?",
            [$from, $to]
        );
        return [
            'total' => (int) ($row['total'] ?? 0),
            'open' => (int) ($row['open'] ?? 0),
            'closed' => (int) ($row['closed'] ?? 0),
        ];
    }

    /**
     * Heatmap de conversas por dia da semana × hora do dia.
     * Retorna [dow][hour] = count, max value, labels.
     */
    private function computeHeatmap(array $rows): array
    {
        $grid = [];
        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $grid[$d][$h] = 0;
            }
        }
        foreach ($rows as $r) {
            $created = strtotime($r['created_at']);
            $dow = (int) date('w', $created);
            $hour = (int) date('G', $created);
            $grid[$dow][$hour]++;
        }
        $max = 0;
        foreach ($grid as $d => $hours) {
            foreach ($hours as $h => $c) {
                if ($c > $max) $max = $c;
            }
        }
        return ['grid' => $grid, 'max' => $max];
    }

    private function findPeakHour(array $heatmap): ?array
    {
        if (empty($heatmap['grid']) || empty($heatmap['max'])) return null;
        $best = null;
        foreach ($heatmap['grid'] as $d => $hours) {
            foreach ($hours as $h => $c) {
                if ($best === null || $c > $best['count']) {
                    $best = ['dow' => $d, 'hour' => $h, 'count' => $c];
                }
            }
        }
        return $best;
    }
}
