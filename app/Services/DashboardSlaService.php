<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Conversation;
use App\Models\ConversationSla;

/**
 * Monta o payload de SLA do dashboard.
 *
 * Uma unidade, uma responsabilidade: juntar o SLA de medição (histórico,
 * em `conversation_slas`) com o SLA de relógio (fila viva, `SlaService`)
 * e devolver o array que a view consome.
 *
 * Fica fora do `SlaService` (que é o motor) e do controller (que já
 * carrega 15+ queries): a view não monta dado, e o controller não
 * conhece regra de SLA.
 *
 * Contrato de degradação: `visible` diz se a faixa de SLA aparece;
 * `reason` diz por quê quando não aparece — 'disabled' (SLA desligado
 * na configuração) ou 'unavailable' (a tabela de medição não pôde ser
 * lida). São causas diferentes e o usuário precisa de mensagens
 * diferentes.
 */
class DashboardSlaService
{
    /** SLA desligado na configuração. */
    public const REASON_DISABLED = 'disabled';

    /** Tabela de medição ausente ou query quebrada. */
    public const REASON_UNAVAILABLE = 'unavailable';

    /** Períodos aceitos no seletor. */
    public const PERIODS = [7, 30, 90];

    /**
     * Payload completo da faixa "SLA agora" + dados da faixa de detalhe.
     *
     * @param int  $period  7, 30 ou 90 dias
     * @param bool $isAdmin inclui a quebra por atendente
     */
    public static function payload(int $period, bool $isAdmin): array
    {
        $period = self::normalizePeriod($period);

        if (!self::isEnabled()) {
            $payload = self::emptyPayload($period);
            $payload['reason'] = self::REASON_DISABLED;
            return $payload;
        }

        try {
            $compliance = ConversationSla::compliance($period);
            $risk = self::liveRisk();

            $payload = [
                'visible' => true,
                'reason' => null,
                'period' => $period,
                'risk' => $risk,
                'breachedOpen' => ConversationSla::criticalNow(),
                'response' => $compliance['response'],
                'resolution' => $compliance['resolution'],
                'violated' => ConversationSla::violatedCount($period),
                'measured' => (int) $compliance['response']['measured'],
                'byDepartment' => self::withActiveCounts(
                    ConversationSla::departmentBreakdown($period),
                    self::activeCounts('department')
                ),
                'byInbox' => self::withActiveCounts(
                    ConversationSla::inboxBreakdown($period),
                    self::activeCounts('inbox')
                ),
                'byAgent' => [],
                'violations' => ConversationSla::violatedConversations($period, 8),
            ];

            // A quebra por atendente é comparação de desempenho entre
            // colegas: só o admin vê. A query nem é executada para o
            // atendente comum.
            if ($isAdmin) {
                $payload['byAgent'] = ConversationSla::agentBreakdown($period);
            }

            return $payload;
        } catch (\Throwable $e) {
            // Tabela de medição ausente (migração não aplicada) ou query
            // quebrada: o dashboard continua de pé, sem o bloco de SLA.
            $payload = self::emptyPayload($period);
            $payload['reason'] = self::REASON_UNAVAILABLE;
            return $payload;
        }
    }

    /**
     * Distribuição das conversas abertas pelo nível do relógio do SLA.
     *
     * Usa exatamente a mesma matemática da inbox: `applyClock()` (conta
     * só segundos úteis, com a grade do departamento) e `levelFor()`
     * (limites por caixa). Se este número divergir da cor da fila, o
     * dashboard mente.
     *
     * @return array{now: int, alert: int, attention: int, normal: int, total: int}
     */
    public static function liveRisk(): array
    {
        $buckets = ['now' => 0, 'alert' => 0, 'attention' => 0, 'normal' => 0, 'total' => 0];

        $rows = Conversation::openConversationsAwaitingReply();
        if (!$rows) {
            return $buckets;
        }

        $rows = SlaService::applyClock($rows);

        foreach ($rows as $row) {
            $level = SlaService::levelFor(
                (int) ($row['waiting_seconds'] ?? 0),
                $row['status'] ?? null,
                null,
                !empty($row['inbox_id']) ? (int) $row['inbox_id'] : null
            );
            $buckets['total']++;
            if ($level === SlaService::LEVEL_ALERT) {
                $buckets['alert']++;
            } elseif ($level === SlaService::LEVEL_ATTENTION) {
                $buckets['attention']++;
            } elseif ($level === SlaService::LEVEL_NORMAL) {
                $buckets['normal']++;
            }
        }

        $buckets['now'] = $buckets['alert'] + $buckets['attention'];
        return $buckets;
    }

    /**
     * Média medida de 1ª resposta, em minutos.
     *
     * Vem de `conversation_slas` — a mesma base das porcentagens de
     * compliance. A média bruta de `messages` que existia no dashboard
     * contava resposta de bot e podia dar negativo (o `MIN(outbound)`
     * podia ser anterior à última inbound).
     */
    public static function avgMeasuredResponse(int $period): ?float
    {
        try {
            $row = Database::getInstance()->fetch(
                "SELECT AVG(response_seconds) as avg_seconds
                 FROM conversation_slas s
                 JOIN conversations c ON c.id = s.conversation_id
                 WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND s.sla_kind = 'standard'
                   AND s.response_seconds IS NOT NULL",
                [max(1, $period)]
            );
        } catch (\Throwable $e) {
            return null;
        }

        $avg = $row['avg_seconds'] ?? null;
        return $avg === null ? null : round((float) $avg / 60, 1);
    }

    /**
     * O relógio do SLA está ligado?
     *
     * A configuração global decide; a caixa pode desligar, mas não
     * liga — se o global está off, não há relógio.
     */
    public static function isEnabled(): bool
    {
        return (string) (SlaService::config()['enabled'] ?? '1') === '1';
    }

    /** Período válido, ou o default de 30 dias. */
    public static function normalizePeriod(int $period): int
    {
        return in_array($period, self::PERIODS, true) ? $period : 30;
    }

    /**
     * Conversas ativas por dimensão.
     *
     * A chave é o rótulo, para casar com `ConversationSla::breakdown()`:
     * as duas consultas precisam falar do mesmo departamento/caixa com
     * o mesmo nome.
     */
    private static function activeCounts(string $dimension): array
    {
        $map = [
            'department' => ['LEFT JOIN departments d ON d.id = c.department_id', "COALESCE(d.name, 'Sem departamento')"],
            'inbox' => ['LEFT JOIN inboxes i ON i.id = c.inbox_id', "COALESCE(i.name, 'Sem caixa')"],
        ];
        if (!isset($map[$dimension])) {
            return [];
        }
        [$join, $label] = $map[$dimension];

        $rows = Database::getInstance()->fetchAll(
            "SELECT {$label} as label, COUNT(c.id) as active
             FROM conversations c
             {$join}
             WHERE c.status NOT IN ('closed','spam','resolved')
             GROUP BY label"
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['label']] = (int) $r['active'];
        }
        return $out;
    }

    /** Junta o breakdown de compliance com as conversas ativas. */
    private static function withActiveCounts(array $rows, array $active): array
    {
        foreach ($rows as &$r) {
            $r['active'] = $active[$r['label']] ?? 0;
        }
        unset($r);
        return $rows;
    }

    /** Payload vazio, com o contrato de degradação já preenchido. */
    private static function emptyPayload(int $period): array
    {
        return [
            'visible' => false,
            'reason' => null,
            'period' => $period,
            'risk' => ['now' => 0, 'alert' => 0, 'attention' => 0, 'normal' => 0, 'total' => 0],
            'breachedOpen' => 0,
            'response' => ['percent' => null, 'breached' => 0, 'measured' => 0],
            'resolution' => ['percent' => null, 'breached' => 0, 'measured' => 0],
            'violated' => 0,
            'measured' => 0,
            'byDepartment' => [],
            'byInbox' => [],
            'byAgent' => [],
            'violations' => [],
        ];
    }
}
