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
        // Conversa de grupo de WhatsApp: permanente, sem objeto de SLA.
        if (!empty($conv['group_id'])) {
            return [];
        }

        $appTz = (string) ($opts['app_tz'] ?? date_default_timezone_get());
        $responseThreshold = (int) ($opts['response_threshold'] ?? 0);
        $resolutionThreshold = (int) ($opts['resolution_threshold'] ?? 0);

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

        // ── SLA de primeira resposta ───────────────────────────────
        $responseSeconds = null;
        $responseAt = null;
        $responseBreached = false;

        if ($firstReply !== null && $firstInbound !== null) {
            $responseSeconds = self::elapsed($schedule, $scheduleTz, $firstInbound, $firstReply);
            $responseAt = $firstReply->format('Y-m-d H:i:s');
            $responseBreached = $responseSeconds >= $responseThreshold;
        } elseif ($isFinal) {
            // Encerrada sem resposta humana: violação consumada.
            $responseBreached = true;
        }

        // ── SLA de resolução ──────────────────────────────────────
        $resolutionSeconds = 0;
        $fallbackStatus = (string) ($conv['status'] ?? '');
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
