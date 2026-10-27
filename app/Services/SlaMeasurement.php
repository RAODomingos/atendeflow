<?php

namespace App\Services;

/**
 * Motor de medição dos dois SLAs de uma conversa.
 *
 * Função pura: recebe a conversa, o histórico de mensagens, a linha do
 * tempo de status e a grade de horário do departamento, e devolve o
 * resultado da medição. Sem banco, sem estado — o mesmo histórico sempre
 * produz o mesmo número.
 *
 * O histórico é a única fonte da verdade: não há acumulador em memória
 * nem gancho que possa ser perdido. Por isso a medição pode ser rodada
 * quantas vezes quiser (o worker recalcula as conversas ativas a cada
 * minuto) sem risco de divergência.
 *
 * Os dois SLAs:
 *  - **primeira resposta**: da 1ª mensagem do cliente até a 1ª resposta
 *    HUMANA (resposta de bot não conta — `user_id` preenchido).
 *  - **resolução**: soma dos períodos em que a bola esteve com a gente,
 *    em segundos úteis, descontando as paradas em `waiting_internal`.
 */
class SlaMeasurement
{
    /** Status em que a conversa não está mais em atendimento. */
    public const FINAL_STATUSES = ['resolved', 'closed', 'spam'];

    /** Status que congela o relógio: a bola está com outro setor. */
    public const PAUSE_STATUS = 'waiting_internal';

    /**
     * A mensagem é uma resposta humana ao cliente?
     *
     * Bot (flow) responde com `user_id` nulo; nota interna e mensagem de
     * sistema não são resposta ao cliente.
     */
    public static function isHumanReply(array $message): bool
    {
        if (($message['direction'] ?? '') !== 'outbound') {
            return false;
        }
        if (!array_key_exists('user_id', $message) || $message['user_id'] === null) {
            return false;
        }
        return !in_array((string) ($message['type'] ?? ''), ['internal_note', 'system'], true);
    }

    /**
     * Mede os dois SLAs de uma conversa.
     *
     * @param array $conv Linha de `conversations` (id, status, department_id,
     *        group_id, created_at, closed_at).
     * @param array $messages Lista de ['id','direction','type','user_id','created_at']
     *        em ordem de id.
     * @param array $timeline Lista de ['at' => 'Y-m-d H:i:s', 'status' => string].
     * @param array|null $schedule Grade do departamento (formato do SlaClock);
     *        null = sem grade, conta tempo de relógio puro.
     * @param string $scheduleTz Fuso da grade.
     * @param string $now Instante de referência, no fuso da aplicação.
     * @param array $opts response_threshold, resolution_threshold,
     *        cycle_started_at (reabertura) e app_tz.
     */
    public static function measure(
        array $conv,
        array $messages,
        array $timeline,
        ?array $schedule,
        string $scheduleTz,
        string $now,
        array $opts = []
    ): array {
        // Conversa de grupo de WhatsApp: o objeto de SLA é a MENÇÃO
        // (da marcação até a 1ª resposta), não a 1ª mensagem do cliente.
        if (!empty($conv['group_id'])) {
            return self::measureGroup($conv, $messages, $timeline, $schedule, $scheduleTz, $now, $opts);
        }

        $appTz = (string) ($opts['app_tz'] ?? date_default_timezone_get());
        $responseThreshold = (int) ($opts['response_threshold'] ?? 0);
        $resolutionThreshold = (int) ($opts['resolution_threshold'] ?? 0);
        // Limite do alerta de "cliente sem resposta". Sem valor próprio,
        // herda o limite de 1ª resposta (mesma semântica: tempo que o
        // cliente fica esperando por uma resposta).
        $pendingThreshold = (int) ($opts['pending_threshold'] ?? 0);
        if ($pendingThreshold <= 0) {
            $pendingThreshold = $responseThreshold;
        }

        $nowAt = self::parse($now, $appTz) ?? new \DateTimeImmutable('now', self::tz($appTz));
        $closedAt = self::parse($conv['closed_at'] ?? null, $appTz);
        $isFinal = $closedAt !== null
            || in_array((string) ($conv['status'] ?? ''), self::FINAL_STATUSES, true);

        // Reabertura: as mensagens do ciclo anterior não contam.
        $cycleStart = self::parse($opts['cycle_started_at'] ?? null, $appTz);
        if ($cycleStart !== null) {
            $messages = array_values(array_filter($messages, function ($m) use ($cycleStart, $appTz) {
                $at = self::parse($m['created_at'] ?? null, $appTz);
                return $at === null || $at >= $cycleStart;
            }));
        }

        $firstInbound = null;
        $firstReply = null;
        $waitStart = null;   // 1ª mensagem do cliente desde a última resposta
        $waitStartId = 0;    // id dessa mensagem (âncora do ciclo pendente)
        $periods = [];       // [inicio, fim] com a bola com a gente
        $periodStartedAt = null;

        foreach ($messages as $m) {
            $at = self::parse($m['created_at'] ?? null, $appTz);
            if ($at === null) {
                continue;
            }

            if (($m['direction'] ?? '') === 'inbound') {
                if ($firstInbound === null) {
                    $firstInbound = $at;
                }
                if ($waitStart === null) {
                    // A PRIMEIRA mensagem do cliente desde a última resposta
                    // ancora a espera: se ele insiste, a espera não recomeça
                    // (senão o intervalo entre a 1ª e a última se perde).
                    $waitStart = $at;
                    $waitStartId = (int) ($m['id'] ?? 0);
                }
                continue;
            }

            if (!self::isHumanReply($m)) {
                continue;
            }

            if ($firstReply === null) {
                $firstReply = $at;
            }
            if ($waitStart !== null) {
                $periods[] = [$waitStart, $at];
                $waitStart = null;
            }
        }

        // Período ainda em aberto: a bola está com a gente e ninguém respondeu.
        if ($waitStart !== null) {
            $end = $closedAt ?? $nowAt;
            if ($end > $waitStart) {
                $periods[] = [$waitStart, $end];
            }
            $periodStartedAt = $waitStart->format('Y-m-d H:i:s');
        }

        // ── Ciclo de resposta pendente ────────────────────────────
        // A mensagem do cliente que está sem resposta AGORA (a 1ª desde a
        // última resposta nossa). É a âncora do alerta de "cliente sem
        // resposta": o alerta é por MENSAGEM, não por conversa — assim
        // uma conversa já atendida que o cliente reativa volta a ter
        // alerta quando ninguém responde de novo.
        $pendingMessageId = $waitStartId;
        $pendingSeconds = null;
        $pendingBreached = false;

        // Status vigente antes da primeira mudança registrada: o da conversa.
        $fallbackStatus = (string) ($conv['status'] ?? '');

        if ($waitStart !== null) {
            $pendingSeconds = self::countWithPause(
                $schedule,
                $scheduleTz,
                $appTz,
                $waitStart,
                $closedAt ?? $nowAt,
                $timeline,
                $fallbackStatus
            );
            $pendingBreached = !$isFinal && $pendingSeconds >= $pendingThreshold;
        }

        // ── SLA de primeira resposta ───────────────────────────────
        $responseSeconds = null;
        $responseAt = null;
        $responseBreached = false;

        if ($firstInbound === null) {
            // O cliente nunca falou (conversa criada manualmente):
            // não há objeto de SLA, e não há o que violar.
            $responseSeconds = null;
            $responseBreached = false;
        } elseif ($firstReply !== null) {
            // Resposta humana já saiu: o tempo é o da 1ª resposta.
            $responseSeconds = self::countWithPause(
                $schedule,
                $scheduleTz,
                $appTz,
                $firstInbound,
                $firstReply,
                $timeline,
                $fallbackStatus
            );
            $responseAt = $firstReply->format('Y-m-d H:i:s');
            $responseBreached = $responseSeconds >= $responseThreshold;
        } elseif (!$isFinal) {
            // Ainda esperando a 1ª resposta: a espera CONTINUA correndo
            // (é o que permite detectar a violação em tempo real).
            $responseSeconds = self::countWithPause(
                $schedule,
                $scheduleTz,
                $appTz,
                $firstInbound,
                $nowAt,
                $timeline,
                $fallbackStatus
            );
            $responseAt = null;
            $responseBreached = $responseSeconds >= $responseThreshold;
        } else {
            // Encerrada sem resposta humana: violação consumada. O tempo
            // de espera é conhecido (do cliente até o encerramento) e
            // precisa ser guardado: com response_seconds nulo a conversa
            // ficava fora da medição do compliance e o relatório a contava
            // como cumprida.
            $endAt = $closedAt ?? $nowAt;
            $responseSeconds = self::countWithPause(
                $schedule,
                $scheduleTz,
                $appTz,
                $firstInbound,
                $endAt,
                $timeline,
                $fallbackStatus
            );
            $responseAt = null;
            $responseBreached = true;
        }

        // ── SLA de resolução ──────────────────────────────────────
        $resolutionSeconds = 0;
        foreach ($periods as [$ini, $fim]) {
            $resolutionSeconds += self::countWithPause(
                $schedule,
                $scheduleTz,
                $appTz,
                $ini,
                $fim,
                $timeline,
                $fallbackStatus
            );
        }
        $resolutionBreached = $resolutionSeconds >= $resolutionThreshold;

        return [
            'response_seconds' => $responseSeconds,
            'response_at' => $responseAt,
            'response_breached' => $responseBreached,
            'response_threshold' => $responseThreshold,
            'resolution_seconds' => $resolutionSeconds,
            'resolution_breached' => $resolutionBreached,
            'resolution_threshold' => $resolutionThreshold,
            'period_started_at' => $periodStartedAt,
            'pending_message_id' => $pendingMessageId,
            'pending_seconds' => $pendingSeconds,
            'pending_breached' => $pendingBreached,
            'pending_threshold' => $pendingThreshold,
        ];
    }

    /**
     * Segundos úteis de um período, descontando as paradas em
     * `waiting_internal`.
     *
     * A linha do tempo de status divide o período em segmentos; cada
     * segmento com status `waiting_internal` não conta.
     */
    private static function countWithPause(
        ?array $schedule,
        string $scheduleTz,
        string $appTz,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $timeline,
        string $fallbackStatus
    ): int {
        if ($to <= $from) {
            return 0;
        }

        $points = [];
        foreach ($timeline as $t) {
            $at = self::parse($t['at'] ?? null, $appTz);
            if ($at !== null && $at > $from && $at < $to) {
                $points[] = $at;
            }
        }
        usort($points, fn($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        $bounds = array_merge([$from], $points, [$to]);
        $total = 0;
        $count = count($bounds);
        for ($i = 0; $i < $count - 1; $i++) {
            $segStart = $bounds[$i];
            $segEnd = $bounds[$i + 1];
            if (self::statusAt($timeline, $segStart, $appTz, $fallbackStatus) === self::PAUSE_STATUS) {
                continue;
            }
            $total += self::elapsed($schedule, $scheduleTz, $segStart, $segEnd);
        }

        return $total;
    }

    /**
     * Status vigente em um instante: o da última entrada da linha do
     * tempo que começa antes dele (ou o status da conversa, se a linha do
     * tempo não cobre o instante).
     */
    private static function statusAt(array $timeline, \DateTimeImmutable $at, string $appTz, string $fallback): string
    {
        $status = $fallback;
        foreach ($timeline as $t) {
            $tat = self::parse($t['at'] ?? null, $appTz);
            if ($tat === null || $tat > $at) {
                continue;
            }
            $status = (string) ($t['status'] ?? $status);
        }
        return $status;
    }

    /**
     * SLA de grupo: o que importa é a MENÇÃO.
     *
     * O tráfego do grupo é contínuo e ninguém "tem a bola" — medir a 1ª
     * resposta do grupo não faria sentido. O relógio que importa começa
     * quando alguém marca o número da conexão (ou @todos) e para na 1ª
     * resposta humana depois da marcação.
     *
     * Uma menção nova depois da resposta reinicia o relógio (cada menção
     * tem a sua): o alerta é por menção, não por conversa.
     *
     * @return array [] quando o grupo nunca recebeu menção (sem objeto de SLA)
     */
    private static function measureGroup(
        array $conv,
        array $messages,
        array $timeline,
        ?array $schedule,
        string $scheduleTz,
        string $now,
        array $opts
    ): array {
        $appTz = (string) ($opts['app_tz'] ?? date_default_timezone_get());
        $threshold = (int) ($opts['mention_threshold'] ?? 0);
        $nowAt = self::parse($now, $appTz) ?? new \DateTimeImmutable('now', self::tz($appTz));
        $isFinal = !empty($conv['closed_at'])
            || in_array((string) ($conv['status'] ?? ''), self::FINAL_STATUSES, true);

        // Última menção recebida (mensagem marcada no webhook).
        $mentionAt = null;
        $mentionId = 0;
        foreach ($messages as $m) {
            if (($m['direction'] ?? '') !== 'inbound' || empty($m['is_mention'])) {
                continue;
            }
            $at = self::parse($m['created_at'] ?? null, $appTz);
            if ($at === null) {
                continue;
            }
            $mentionAt = $at;
            $mentionId = (int) ($m['id'] ?? 0);
        }

        if ($mentionAt === null) {
            return []; // grupo sem menção: nada a medir
        }

        // 1ª resposta humana depois da última menção.
        $replyAt = null;
        foreach ($messages as $m) {
            if (!self::isHumanReply($m)) {
                continue;
            }
            $at = self::parse($m['created_at'] ?? null, $appTz);
            if ($at === null || $at <= $mentionAt) {
                continue;
            }
            $replyAt = $at;
            break;
        }

        $closedAt = self::parse($conv['closed_at'] ?? null, $appTz);
        $end = $replyAt ?? ($isFinal ? ($closedAt ?? $mentionAt) : null);
        $seconds = self::countWithPause(
            $schedule,
            $scheduleTz,
            $appTz,
            $mentionAt,
            $end ?? $nowAt,
            $timeline,
            (string) ($conv['status'] ?? '')
        );

        return [
            'sla_kind' => 'group_mention',
            // A 1ª resposta "clássica" não existe em grupo: o dado vive no
            // ciclo da menção (pending_*), o que também mantém o relatório
            // de compliance sem misturar grupo com atendimento 1:1.
            'response_seconds' => null,
            'response_at' => null,
            'response_breached' => false,
            'response_threshold' => null,
            'resolution_seconds' => 0,
            'resolution_breached' => false,
            'resolution_threshold' => null,
            'period_started_at' => null,
            'pending_message_id' => $mentionId,
            'pending_seconds' => $seconds,
            'pending_breached' => $threshold > 0 && $seconds >= $threshold,
            'pending_threshold' => $threshold,
            'mention_answered' => $replyAt !== null,
        ];
    }

    /**
     * Mede os dois SLAs de uma conversa a partir do banco.
     *
     * Não grava nada: quem decide o que fazer com o resultado é o worker
     * (que chama saveMeasurement).
     *
     * @return array|null null quando a conversa não existe
     */
    public static function forConversation(int $conversationId, ?string $now = null): ?array
    {
        $conv = \App\Models\Conversation::find($conversationId);
        if (!$conv) {
            return null;
        }

        $db = \App\Core\Database::getInstance();

        $messages = $db->fetchAll(
            "SELECT id, direction, type, user_id, created_at, is_mention
             FROM messages WHERE conversation_id = ? ORDER BY id",
            [$conversationId]
        );
        $timeline = self::loadTimeline($conversationId);

        // Grade do relogio: mesma regra do chip na tela (toggle global +
        // override da caixa + grade do departamento). Sem isso, com o
        // horario comercial desligado, a tela mostrava relogio de
        // parede e o motor media segundos uteis.
        $deptId = !empty($conv['department_id']) ? (int) $conv['department_id'] : null;
        $inboxId = !empty($conv['inbox_id']) ? (int) $conv['inbox_id'] : null;
        $hours = \App\Services\SlaService::businessHoursFor($inboxId, $deptId, date_default_timezone_get());
        $schedule = $hours['schedule'];
        $scheduleTz = $hours['timezone'];

        // Limites: config da caixa (herda o global) ou o global direto.
        $cfg = SlaService::configFor($inboxId)['config'] ?? [];
        $responseMinutes = (int) ($cfg['response_minutes']
            ?? \App\Models\Setting::get('sla_response_minutes', '15'));
        $resolutionMinutes = (int) ($cfg['resolution_minutes']
            ?? \App\Models\Setting::get('sla_resolution_minutes', '1440'));
        // Limite do alerta de "cliente sem resposta". Vazio = herda o
        // limite de 1ª resposta.
        $pendingMinutes = (int) ($cfg['pending_response_minutes']
            ?? \App\Models\Setting::get('sla_pending_response_minutes', '0'));
        if ($pendingMinutes <= 0) {
            $pendingMinutes = $responseMinutes;
        }
        // Limite do SLA da MENÇÃO em grupo. Vazio = herda o limite de 1ª
        // resposta (mesma régua: tempo que a bola fica com a gente).
        $mentionMinutes = (int) ($cfg['group_mention_minutes']
            ?? \App\Models\Setting::get('sla_group_mention_minutes', '0'));
        if ($mentionMinutes <= 0) {
            $mentionMinutes = $responseMinutes;
        }

        // Ciclo reiniciado por reabertura: as mensagens anteriores ficam de fora.
        $cycleStartedAt = null;
        $row = \App\Models\ConversationSla::find($conversationId);
        if ($row && !empty($row['metadata'])) {
            $meta = json_decode((string) $row['metadata'], true);
            if (is_array($meta) && !empty($meta['cycle_started_at'])) {
                $cycleStartedAt = (string) $meta['cycle_started_at'];
            }
        }

        $result = self::measure(
            $conv,
            $messages,
            $timeline,
            $schedule,
            $scheduleTz,
            $now ?: date('Y-m-d H:i:s'),
            [
                'response_threshold' => $responseMinutes * 60,
                'resolution_threshold' => $resolutionMinutes * 60,
                'pending_threshold' => $pendingMinutes * 60,
                'mention_threshold' => $mentionMinutes * 60,
                'cycle_started_at' => $cycleStartedAt,
                'app_tz' => date_default_timezone_get(),
            ]
        );

        return $result === [] ? null : $result;
    }

    /**
     * Linha do tempo de status da conversa.
     *
     * Lista de ['at' => 'Y-m-d H:i:s', 'status' => string] em ordem
     * cronológica. O destino do status vem do `metadata.to` do evento; em
     * eventos antigos (sem metadata) cai no parse da descrição.
     *
     * Quando a primeira mudança conhece o status anterior (`metadata.from`),
     * ele entra como estado inicial — sem isso, o período anterior à
     * primeira mudança seria classificado pelo status ATUAL da conversa e a
     * pausa de `waiting_internal` cairia no lugar errado.
     */
    public static function loadTimeline(int $conversationId): array
    {
        $rows = \App\Core\Database::getInstance()->fetchAll(
            "SELECT created_at, description, metadata
             FROM conversation_events
             WHERE conversation_id = ? AND event_type = 'status_changed'
             ORDER BY created_at ASC, id ASC",
            [$conversationId]
        );

        $timeline = [];
        $initialStatus = null;

        foreach ($rows as $row) {
            $meta = !empty($row['metadata']) ? json_decode((string) $row['metadata'], true) : null;
            $meta = is_array($meta) ? $meta : [];

            $status = !empty($meta['to']) ? (string) $meta['to'] : null;
            if ($status === null && !empty($row['description'])) {
                if (preg_match('/Status alterado para: (\w+)/', (string) $row['description'], $m)) {
                    $status = $m[1];
                }
            }
            if ($status === null) {
                continue;
            }

            if ($initialStatus === null && !empty($meta['from'])) {
                $initialStatus = (string) $meta['from'];
            }
            $timeline[] = ['at' => (string) $row['created_at'], 'status' => $status];
        }

        if ($initialStatus !== null) {
            array_unshift($timeline, ['at' => '1970-01-01 00:00:00', 'status' => $initialStatus]);
        }

        return $timeline;
    }

    /**
     * Grava o resultado da medição.
     *
     * O `metadata` preserva o `cycle_started_at` já existente (a
     * reabertura o define) — só é substituído quando informado.
     */
    public static function saveMeasurement(int $conversationId, array $measurement, ?string $cycleStartedAt = null): void
    {
        $meta = [];
        $existing = \App\Models\ConversationSla::find($conversationId);
        if ($existing && !empty($existing['metadata'])) {
            $decoded = json_decode((string) $existing['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        if ($cycleStartedAt !== null) {
            $meta['cycle_started_at'] = $cycleStartedAt;
        }

        \App\Models\ConversationSla::save($conversationId, [
            'sla_kind' => $measurement['sla_kind'] ?? 'standard',
            'response_seconds' => $measurement['response_seconds'] ?? null,
            'response_at' => $measurement['response_at'] ?? null,
            'response_breached' => !empty($measurement['response_breached']) ? 1 : 0,
            'response_threshold' => $measurement['response_threshold'] ?? null,
            'resolution_seconds' => (int) ($measurement['resolution_seconds'] ?? 0),
            'resolution_breached' => !empty($measurement['resolution_breached']) ? 1 : 0,
            'resolution_threshold' => $measurement['resolution_threshold'] ?? null,
            'period_started_at' => $measurement['period_started_at'] ?? null,
            'pending_message_id' => $measurement['pending_message_id'] ?? null,
            'pending_seconds' => $measurement['pending_seconds'] ?? null,
            'pending_breached' => !empty($measurement['pending_breached']) ? 1 : 0,
            'pending_threshold' => $measurement['pending_threshold'] ?? null,
            'metadata' => $meta,
        ]);
    }

    /**
     * Segundos entre dois instantes: dentro da grade do departamento, ou
     * tempo de relógio puro quando não há grade.
     */
    private static function elapsed(?array $schedule, string $scheduleTz, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }
        if ($schedule === null) {
            return $to->getTimestamp() - $from->getTimestamp();
        }
        return SlaClock::elapsedInSchedule($schedule, $scheduleTz, $from, $to);
    }

    private static function parse(?string $value, string $tz): ?\DateTimeImmutable
    {
        $v = trim((string) $value);
        if ($v === '' || str_starts_with($v, '0000-00-00')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($v, self::tz($tz));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function tz(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone ?: 'America/Sao_Paulo');
        } catch (\Throwable $e) {
            return new \DateTimeZone('America/Sao_Paulo');
        }
    }
}
