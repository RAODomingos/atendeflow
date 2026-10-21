<?php

namespace App\Services;

/**
 * Matemática do relógio de SLA ciente de horário comercial.
 *
 * Puro e sem dependência de banco: recebe a grade semanal de um
 * departamento e devolve quantos segundos dentro das janelas abertas
 * existem entre dois instantes. É o núcleo testável da correção do
 * relógio — a camada com banco vive em SlaService::applyClock().
 *
 * A grade segue o formato de `business_hours`:
 *   [0..6 => ['is_open' => 0|1, 'open' => 'HH:MM:SS', 'close' => 'HH:MM:SS']]
 * com 0 = domingo ... 6 = sábado.
 *
 * Mesma premissa de BusinessHoursService::isOpen(): dia sem regra na
 * grade conta como aberto o dia inteiro.
 */
class SlaClock
{
    /** Quantos dias à frente procurar a próxima abertura/fechamento. */
    private const HORIZON_DAYS = 15;

    /**
     * Segundos dentro das janelas abertas entre dois instantes.
     *
     * @param array  $schedule Grade semanal (ver docblock da classe).
     * @param string $timezone Fuso em que a grade está escrita.
     */
    public static function elapsedInSchedule(array $schedule, string $timezone, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $tz = self::tz($timezone);
        $start = \DateTimeImmutable::createFromInterface($from)->setTimezone($tz);
        $end = \DateTimeImmutable::createFromInterface($to)->setTimezone($tz);

        if ($end <= $start) {
            return 0;
        }

        $total = 0;
        $day = $start->setTime(0, 0, 0);
        $lastDay = $end->setTime(0, 0, 0);

        while ($day <= $lastDay) {
            foreach (self::windowsForDay($schedule, $day) as [$ws, $we]) {
                $segStart = $ws > $start ? $ws : $start;
                $segEnd = $we < $end ? $we : $end;
                if ($segEnd > $segStart) {
                    $total += $segEnd->getTimestamp() - $segStart->getTimestamp();
                }
            }
            $day = $day->modify('+1 day');
        }

        return $total;
    }

    /**
     * O atendimento está aberto no instante informado?
     */
    public static function isOpenAt(array $schedule, string $timezone, \DateTimeInterface $at): bool
    {
        $tz = self::tz($timezone);
        $local = \DateTimeImmutable::createFromInterface($at)->setTimezone($tz);
        $day = $local->setTime(0, 0, 0);

        foreach (self::windowsForDay($schedule, $day) as [$ws, $we]) {
            if ($local >= $ws && $local <= $we) {
                return true;
            }
        }

        return false;
    }

    /**
     * Próximo instante em que o estado (aberto/fechado) muda.
     *
     * Serve para o tick do navegador: enquanto o relógio não cruza esse
     * ponto, o valor servido continua válido.
     */
    public static function nextBoundary(array $schedule, string $timezone, \DateTimeInterface $at): ?\DateTimeImmutable
    {
        $tz = self::tz($timezone);
        $local = \DateTimeImmutable::createFromInterface($at)->setTimezone($tz);

        $windows = [];
        $day = $local->setTime(0, 0, 0);
        for ($i = 0; $i < self::HORIZON_DAYS; $i++) {
            foreach (self::windowsForDay($schedule, $day) as $w) {
                $windows[] = $w;
            }
            $day = $day->modify('+1 day');
        }

        if (self::isOpenAt($schedule, $timezone, $local)) {
            // Fim da janela aberta atual. Se a janela seguinte começa
            // colada nela, o atendimento continua aberto e a virada só
            // acontece na quebra entre as duas.
            $prevEnd = null;
            foreach ($windows as [$ws, $we]) {
                if ($we <= $local) {
                    continue;
                }
                if ($prevEnd !== null && $ws > $prevEnd) {
                    return $prevEnd;
                }
                $prevEnd = $we;
            }
            return $prevEnd;
        }

        // Fechado: a próxima abertura é a virada.
        foreach ($windows as [$ws, $we]) {
            if ($ws > $local) {
                return $ws;
            }
        }

        return null;
    }

    /**
     * Janelas abertas de um dia (lista de [início, fim]).
     *
     * Dia sem regra na grade => aberto o dia inteiro.
     */
    private static function windowsForDay(array $schedule, \DateTimeImmutable $day): array
    {
        $dow = (int) $day->format('w');

        if (!array_key_exists($dow, $schedule)) {
            return [[$day, $day->modify('+1 day')]];
        }

        $rule = $schedule[$dow];
        if (!is_array($rule) || empty($rule['is_open'])) {
            return [];
        }

        $open = self::hhmmss($rule['open'] ?? '00:00:00', '00:00:00');
        $close = self::hhmmss($rule['close'] ?? '23:59:59', '23:59:59');

        $ws = $day->setTime((int) substr($open, 0, 2), (int) substr($open, 3, 2), (int) substr($open, 6, 2));
        $we = $day->setTime((int) substr($close, 0, 2), (int) substr($close, 3, 2), (int) substr($close, 6, 2));

        if ($we <= $ws) {
            // Turno noturno não é suportado (mesma limitação de
            // BusinessHoursService::isOpen): a janela é ignorada.
            return [];
        }

        return [[$ws, $we]];
    }

    private static function hhmmss($value, string $fallback): string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return $fallback;
        }
        if (strlen($v) === 5) {
            $v .= ':00';
        }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $v)) {
            return $fallback;
        }
        return $v;
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
