<?php

namespace App\Models;

use App\Core\Database;

/**
 * Linha de medição do SLA de uma conversa.
 *
 * Uma linha por conversa, criada sob demanda pelo worker. Guarda o
 * resultado dos dois SLAs (primeira resposta e resolução), os limites
 * que estavam em vigor no momento da medição (snapshot — mudar a config
 * depois não reescreve o histórico) e o período em aberto (bola com a
 * gente, aguardando resposta).
 */
class ConversationSla
{
    /** Colunas graváveis por save(). */
    private const COLUMNS = [
        'sla_kind',
        'response_seconds',
        'response_at',
        'response_breached',
        'response_threshold',
        'resolution_seconds',
        'resolution_breached',
        'resolution_threshold',
        'period_started_at',
        'pending_message_id',
        'pending_alerted_message_id',
        'pending_seconds',
        'pending_breached',
        'pending_threshold',
        'metadata',
    ];

    public static function find(int $conversationId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM conversation_slas WHERE conversation_id = ?",
            [$conversationId]
        );
    }

    /**
     * Upsert da linha de SLA.
     *
     * @param array $data Colunas de COLUMNS. `metadata` como array vira JSON.
     * @return int id da linha (0 se não havia nada para gravar).
     */
    public static function save(int $conversationId, array $data): int
    {
        $db = Database::getInstance();

        $payload = [];
        foreach (self::COLUMNS as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $payload[$col] = ($col === 'metadata' && is_array($data[$col]))
                ? json_encode($data[$col], JSON_UNESCAPED_UNICODE)
                : $data[$col];
        }

        if (empty($payload)) {
            return 0;
        }

        $existing = self::find($conversationId);
        if ($existing) {
            $db->update('conversation_slas', $payload, 'conversation_id = ?', [$conversationId]);
            return (int) $existing['id'];
        }

        $payload['conversation_id'] = $conversationId;
        $payload['created_at'] = date('Y-m-d H:i:s');
        $payload['updated_at'] = date('Y-m-d H:i:s');
        return $db->insert('conversation_slas', $payload);
    }

    /**
     * Reinicia o ciclo de SLA da conversa.
     *
     * Usado na reabertura (de resolved/closed para um status ativo): o
     * atendimento recomeça, então os acumulados zeram e `metadata`
     * guarda o início do novo ciclo — a medição passa a ignorar as
     * mensagens anteriores a ele.
     */
    public static function reset(int $conversationId, ?string $cycleStartedAt = null): void
    {
        $db = Database::getInstance();

        $payload = [
            'response_seconds' => null,
            'response_at' => null,
            'response_breached' => 0,
            'resolution_seconds' => 0,
            'resolution_breached' => 0,
            'period_started_at' => null,
            'metadata' => json_encode(
                $cycleStartedAt ? ['cycle_started_at' => $cycleStartedAt] : [],
                JSON_UNESCAPED_UNICODE
            ),
        ];

        $existing = self::find($conversationId);
        if ($existing) {
            $db->update('conversation_slas', $payload, 'conversation_id = ?', [$conversationId]);
            return;
        }

        $payload['conversation_id'] = $conversationId;
        $payload['created_at'] = date('Y-m-d H:i:s');
        $payload['updated_at'] = date('Y-m-d H:i:s');
        $db->insert('conversation_slas', $payload);
    }

    // ── consultas de lote (worker) ────────────────────────────────

    /**
     * Conversas ativas sem linha de SLA: precisam ser medidas.
     *
     * @return array<int> ids de conversa
     */
    public static function activeWithoutRow(int $limit = 200): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT c.id FROM conversations c
             LEFT JOIN conversation_slas s ON s.conversation_id = c.id
             WHERE s.id IS NULL
               AND c.status IN ('new','open','waiting_customer','waiting_internal')
               AND c.group_id IS NULL
             ORDER BY c.created_at ASC
             LIMIT " . (int) $limit
        );
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Reserva (de forma atomica) o escalonamento de uma violacao.
     *
     * A transicao 0 -> 1 acontece num UPDATE condicional: com dois
     * workers sobrepostos, so quem altera a linha escala. Sem isso, o
     * segundo worker via a leitura antiga (`response_breached = 0`) e
     * disparava uma segunda notificacao, uma segunda tag e um segundo
     * WhatsApp para o mesmo cliente.
     *
     * @return bool true se ESTE processo foi o dono da violacao
     */
    public static function claimResponseBreach(int $conversationId): bool
    {
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');

        $affected = $db->execute(
            "UPDATE conversation_slas SET response_breached = 1, updated_at = ?
             WHERE conversation_id = ? AND response_breached = 0",
            [$now, $conversationId]
        );
        if ($affected > 0) {
            return true;
        }

        // Sem linha ainda: cria ja marcada (a medicao completa vem
        // logo depois, no saveMeasurement).
        $row = $db->fetch("SELECT id FROM conversation_slas WHERE conversation_id = ?", [$conversationId]);
        if ($row) {
            return false; // ja existia e ja estava marcada
        }

        try {
            $db->insert('conversation_slas', [
                'conversation_id' => $conversationId,
                'response_breached' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return true;
        } catch (\Throwable $e) {
            // Corrida com outro worker: ele criou a linha primeiro.
            return false;
        }
    }

    /**
     * Conversas de GRUPO que já receberam menção: são medidas pelo SLA da
     * menção (da marcação até a 1ª resposta).
     *
     * @return array<int, int> ids de conversa
     */
    public static function groupsWithMentions(int $limit = 50): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT c.id, MAX(m.id) AS last_mention
             FROM conversations c
             JOIN messages m ON m.conversation_id = c.id AND m.is_mention = 1
             WHERE c.group_id IS NOT NULL
               AND c.status IN ('new','open','waiting_customer','waiting_internal')
             GROUP BY c.id
             ORDER BY last_mention DESC
             LIMIT " . (int) $limit
        );
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Reserva (de forma atomica) o alerta de "cliente sem resposta".
     *
     * Diferente da violacao de 1a resposta — que dispara uma vez por
     * conversa — este alerta e por MENSAGEM: cada mensagem do cliente que
     * fica sem resposta tem o seu proprio alerta. A reserva acontece num
     * UPDATE condicional: com dois workers sobrepostos, so quem altera a
     * linha escala, entao a mesma mensagem nunca gera duas notificacoes.
     *
     * No grupo o mesmo mecanismo serve para a MENÇÃO: cada marcação tem o
     * seu próprio alerta.
     *
     * @param int $conversationId
     * @param int $pendingMessageId id da mensagem do cliente sem resposta
     * @return bool true se ESTE processo foi o dono do alerta
     */
    public static function claimPendingBreach(int $conversationId, int $pendingMessageId): bool
    {
        if ($pendingMessageId <= 0) {
            return false;
        }

        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');

        $affected = $db->execute(
            "UPDATE conversation_slas
                SET pending_alerted_message_id = ?, pending_breached = 1, updated_at = ?
              WHERE conversation_id = ?
                AND (pending_alerted_message_id IS NULL OR pending_alerted_message_id <> ?)",
            [$pendingMessageId, $now, $conversationId, $pendingMessageId]
        );
        if ($affected > 0) {
            return true;
        }

        // Sem linha ainda: cria ja marcada (a medicao completa vem
        // logo depois, no saveMeasurement).
        $row = $db->fetch("SELECT id FROM conversation_slas WHERE conversation_id = ?", [$conversationId]);
        if ($row) {
            return false; // ja existia e ja estava marcada
        }

        try {
            $db->insert('conversation_slas', [
                'conversation_id' => $conversationId,
                'pending_alerted_message_id' => $pendingMessageId,
                'pending_breached' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return true;
        } catch (\Throwable $e) {
            // Corrida com outro worker: ele criou a linha primeiro.
            return false;
        }
    }

    /**
     * Conversas ativas cujo ciclo foi reiniciado (reabertura).
     *
     * A linha existe, mas o ciclo novo ainda nao foi medido
     * (`period_started_at` vazio). Sem esta consulta a conversa
     * reaberta nunca mais seria medida: `activeWithoutRow` exige linha
     * inexistente e `openPeriods` exige `period_started_at` preenchido.
     *
     * @return array<int> ids de conversa
     */
    public static function activeWithPendingCycle(int $limit = 200): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT s.conversation_id FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             WHERE s.period_started_at IS NULL
               AND c.status IN ('new','open','waiting_customer','waiting_internal')
               AND c.group_id IS NULL
             ORDER BY c.updated_at ASC
             LIMIT " . (int) $limit
        );
        return array_map(fn($r) => (int) $r['conversation_id'], $rows);
    }

    /**
     * Conversas encerradas sem linha de SLA: precisam ser finalizadas
     * (o valor final é gravado uma vez e não muda mais).
     *
     * @return array<int, array{id:int, closed_at:string}> linhas das conversas
     */
    public static function closedWithoutRow(int $limit = 200): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT c.id, c.closed_at FROM conversations c
             LEFT JOIN conversation_slas s ON s.conversation_id = c.id
             WHERE s.id IS NULL
               AND c.closed_at IS NOT NULL
               AND c.group_id IS NULL
             ORDER BY c.closed_at ASC
             LIMIT " . (int) $limit
        );
        return array_map(
            fn($r) => ['id' => (int) $r['id'], 'closed_at' => (string) $r['closed_at']],
            $rows
        );
    }

    /**
     * Conversas encerradas que JÁ tinham linha de SLA medida enquanto
     * estavam ativas.
     *
     * A linha é congelada no último valor medido antes do encerramento
     * (`updated_at < closed_at`), então o valor final da resolução nunca
     * era gravado. Esta consulta devolve cada conversa uma única vez: depois
     * de finalizada, `updated_at` passa a ser maior que `closed_at` e ela
     * some da lista.
     *
     * @return array<int> ids de conversa
     */
    public static function closedWithStaleRow(int $limit = 200): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT s.conversation_id FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             WHERE c.closed_at IS NOT NULL
               AND s.updated_at < c.closed_at
               AND c.group_id IS NULL
             ORDER BY c.closed_at ASC
             LIMIT " . (int) $limit
        );
        return array_map(fn($r) => (int) $r['conversation_id'], $rows);
    }

    /**
     * Conversas ativas cujo período em aberto começou no passado: o
     * relógio delas pode ter mudado desde a última medição.
     *
     * @return array<int> ids de conversa
     */
    public static function openPeriods(int $limit = 200): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT s.conversation_id FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             WHERE s.period_started_at IS NOT NULL
               AND s.period_started_at < ?
               AND c.status IN ('new','open','waiting_customer','waiting_internal')
               AND c.group_id IS NULL
             ORDER BY s.period_started_at ASC
             LIMIT " . (int) $limit,
            [date('Y-m-d H:i:s')]
        );
        return array_map(fn($r) => (int) $r['conversation_id'], $rows);
    }

    // ── consultas de leitura (chip, dashboard, relatório) ─────────

    /**
     * Linha de SLA de uma conversa (null se ainda não foi medida).
     */
    public static function summaryForConversation(int $conversationId): ?array
    {
        return self::find($conversationId);
    }

    /**
     * Linhas de SLA de várias conversas, em UMA query.
     *
     * @param array<int> $conversationIds
     * @return array<int, array> indexado por conversation_id
     */
    public static function forConversations(array $conversationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $conversationIds))));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::getInstance()->fetchAll(
            "SELECT * FROM conversation_slas WHERE conversation_id IN ({$ph})",
            $ids
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['conversation_id']] = $r;
        }
        return $map;
    }

    /**
     * Conversas ativas que já furaram o SLA de 1ª resposta.
     */
    public static function criticalNow(): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) c FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             WHERE s.response_breached = 1
               AND c.status IN ('new','open','waiting_customer','waiting_internal')"
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Compliance dos dois SLAs no período.
     *
     * A 1ª resposta é medida em todas as conversas com linha de SLA
     * (inclusive as que ainda estão abertas e esperando). A resolução só
     * conta as conversas encerradas — uma conversa aberta ainda não tem
     * tempo de resolução.
     *
     * @return array{
     *   response: array{measured: int, breached: int, percent: ?float},
     *   resolution: array{measured: int, breached: int, percent: ?float}
     * }
     */
    public static function compliance(int $days, ?int $departmentId = null, ?int $inboxId = null): array
    {
        $params = [max(1, $days)];
        $where = "c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        if ($departmentId) {
            $where .= " AND c.department_id = ?";
            $params[] = $departmentId;
        }
        if ($inboxId) {
            $where .= " AND c.inbox_id = ?";
            $params[] = $inboxId;
        }

        $row = Database::getInstance()->fetch(
            "SELECT COUNT(CASE WHEN s.response_seconds IS NOT NULL THEN 1 END) as measured,
                    SUM(CASE WHEN s.response_seconds IS NOT NULL AND s.response_breached = 1 THEN 1 ELSE 0 END) as breached,
                    SUM(CASE WHEN c.closed_at IS NOT NULL AND s.resolution_seconds > 0 THEN 1 ELSE 0 END) as measured_res,
                    SUM(CASE WHEN c.closed_at IS NOT NULL AND s.resolution_seconds > 0 AND s.resolution_breached = 1 THEN 1 ELSE 0 END) as breached_res
             FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             WHERE {$where} AND s.sla_kind = 'standard'",
            $params
        ) ?: [];

        $measured = (int) ($row['measured'] ?? 0);
        $breached = (int) ($row['breached'] ?? 0);
        $measuredRes = (int) ($row['measured_res'] ?? 0);
        $breachedRes = (int) ($row['breached_res'] ?? 0);

        return [
            'response' => [
                'measured' => $measured,
                'breached' => $breached,
                'percent' => $measured > 0 ? round((1 - $breached / $measured) * 100, 1) : null,
            ],
            'resolution' => [
                'measured' => $measuredRes,
                'breached' => $breachedRes,
                'percent' => $measuredRes > 0 ? round((1 - $breachedRes / $measuredRes) * 100, 1) : null,
            ],
        ];
    }

    /**
     * Quebra do compliance de 1ª resposta por uma dimensão.
     *
     * @param string $dimension agent|department|inbox
     * @return array<int, array{label: string, total: int, breached: int, percent: ?float}>
     */
    private static function breakdown(string $dimension, int $days): array
    {
        $map = [
            'agent' => ['LEFT JOIN users u ON u.id = c.assigned_user_id', "COALESCE(u.name, 'Sem responsável')"],
            'department' => ['LEFT JOIN departments d ON d.id = c.department_id', "COALESCE(d.name, 'Sem departamento')"],
            'inbox' => ['LEFT JOIN inboxes i ON i.id = c.inbox_id', "COALESCE(i.name, 'Sem caixa')"],
        ];
        if (!isset($map[$dimension])) {
            return [];
        }
        [$join, $label] = $map[$dimension];

        $rows = Database::getInstance()->fetchAll(
            "SELECT {$label} as label,
                    COUNT(CASE WHEN s.response_seconds IS NOT NULL THEN 1 END) as total,
                    SUM(CASE WHEN s.response_seconds IS NOT NULL AND s.response_breached = 1 THEN 1 ELSE 0 END) as breached
             FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             {$join}
             WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND s.sla_kind = 'standard'
             GROUP BY label
             ORDER BY total DESC",
            [max(1, $days)]
        );

        $out = [];
        foreach ($rows as $r) {
            $total = (int) $r['total'];
            $breached = (int) $r['breached'];
            $out[] = [
                'label' => (string) $r['label'],
                'total' => $total,
                'breached' => $breached,
                'percent' => $total > 0 ? round((1 - $breached / $total) * 100, 1) : null,
            ];
        }
        return $out;
    }

    /** @return array<int, array> quebra por atendente */
    public static function agentBreakdown(int $days): array
    {
        return self::breakdown('agent', $days);
    }

    /** @return array<int, array> quebra por departamento */
    public static function departmentBreakdown(int $days): array
    {
        return self::breakdown('department', $days);
    }

    /** @return array<int, array> quebra por caixa de entrada */
    public static function inboxBreakdown(int $days): array
    {
        return self::breakdown('inbox', $days);
    }

    /**
     * Conversas com qualquer SLA furado no período.
     *
     * Mesma população de violatedConversations(), mas em contagem: a
     * ficha do dashboard precisa do total, a lista devolve linhas.
     */
    public static function violatedCount(int $days): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) c FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND (s.response_breached = 1 OR s.resolution_breached = 1)
               AND s.sla_kind = 'standard'",
            [max(1, $days)]
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Conversas que furaram algum dos SLAs no período.
     *
     * @return array<int, array>
     */
    public static function violatedConversations(int $days, int $limit = 200): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT c.id, c.protocol, c.status, c.created_at,
                    ct.name as contact_name,
                    COALESCE(u.name, '') as assigned_user_name,
                    COALESCE(d.name, '') as department_name,
                    COALESCE(i.name, '') as inbox_name,
                    s.response_seconds, s.response_threshold, s.response_breached,
                    s.resolution_seconds, s.resolution_threshold, s.resolution_breached
             FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             LEFT JOIN users u ON u.id = c.assigned_user_id
             LEFT JOIN departments d ON d.id = c.department_id
             LEFT JOIN inboxes i ON i.id = c.inbox_id
             WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND (s.response_breached = 1 OR s.resolution_breached = 1)
               AND s.sla_kind = 'standard'
             ORDER BY c.created_at DESC
             LIMIT " . (int) $limit,
            [max(1, $days)]
        );
    }
}
