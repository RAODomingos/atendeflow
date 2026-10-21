<?php

namespace App\Services;

use App\Models\Inbox;
use App\Models\Setting;

/**
 * Centraliza as configurações de SLA da lista de conversas:
 * tempos de espera, cores de fundo e perfis de som para os
 * estados "normal" (verde), "atenção" (amarelo) e "alerta" (vermelho).
 *
 * Cada caixa de entrada pode ter configuração própria; quando
 * algum valor fica NULL na tabela `inboxes`, o sistema usa a
 * configuração global salva em `settings` (chaves `sla_*`).
 */
class SlaService
{
    public const LEVEL_NONE = 'none';
    public const LEVEL_NORMAL = 'normal';
    public const LEVEL_ATTENTION = 'attention';
    public const LEVEL_ALERT = 'alert';

    /** Status em que a conversa ainda está em aberto. */
    public const ACTIVE_STATUSES = ['new', 'open', 'waiting_customer', 'waiting_internal'];

    /** Motivos possíveis para o relógio estar congelado. */
    public const PAUSED_CUSTOMER = 'customer';        // atendente falou por último
    public const PAUSED_INTERNAL = 'internal';        // aguardando outro setor/atendente
    public const PAUSED_CLOSED = 'closed';            // conversa encerrada
    public const PAUSED_OUT_OF_HOURS = 'out_of_hours'; // fora do horário de atendimento

    /** Chave em `settings` que liga/desliga o relógio por horário comercial. */
    public const SETTING_BUSINESS_HOURS = 'sla_business_hours';

    public const SOUND_PROFILES = ['default', 'soft', 'sharp', 'silent'];

    /**
     * Cache de requisição da config por caixa.
     *
     * A listagem de conversas chama levelFor() uma vez por item; sem o
     * cache, cada chamada fazia um Inbox::find() (N+1 na tela da inbox).
     */
    private static array $configCache = [];

    public const DEFAULT_CONFIG = [
        'enabled' => '1',
        'business_hours' => '1',
        'attention_minutes' => '5',
        'alert_minutes' => '10',
        'color_normal' => '#dcfce7',
        'color_attention' => '#fef3c7',
        'color_alert' => '#fee2e2',
        'color_normal_text' => '#166534',
        'color_attention_text' => '#92400e',
        'color_alert_text' => '#991b1b',
        'sound_attention' => 'default',
        'sound_alert' => 'default',
    ];

    public const INBOX_SLA_FIELDS = [
        'sla_enabled', 'sla_attention_minutes', 'sla_alert_minutes',
        'sla_color_normal', 'sla_color_attention', 'sla_color_alert',
        'sla_color_normal_text', 'sla_color_attention_text', 'sla_color_alert_text',
        'sla_sound_attention', 'sla_sound_alert',
    ];

    /**
     * Lê config global (chaves sla_*) já com defaults.
     */
    public static function globalConfig(): array
    {
        $cfg = self::DEFAULT_CONFIG;
        foreach (self::DEFAULT_CONFIG as $k => $v) {
            $stored = Setting::get('sla_' . $k, $v);
            $cfg[$k] = $stored !== null && $stored !== '' ? (string) $stored : $v;
        }
        return $cfg;
    }

    /**
     * Lê a config de SLA efetiva para uma caixa.
     *  - Sem $inboxId: retorna a config global.
     *  - Com $inboxId: mescla overrides da caixa (campos não-nulos)
     *    sobre a config global.
     *
     * @return array{config: array, overrides: array, is_custom: bool}
     */
    public static function configFor(?int $inboxId = null): array
    {
        $key = $inboxId === null ? 'global' : (string) $inboxId;
        if (isset(self::$configCache[$key])) {
            return self::$configCache[$key];
        }

        $global = self::globalConfig();
        if ($inboxId === null) {
            return self::$configCache[$key] = ['config' => $global, 'overrides' => [], 'is_custom' => false];
        }

        $row = Inbox::find($inboxId);
        $overrides = [];
        if ($row) {
            foreach (self::INBOX_SLA_FIELDS as $col) {
                $short = substr($col, 4); // remove prefixo sla_
                if (!array_key_exists($short, $global)) continue;
                if (array_key_exists($col, $row) && $row[$col] !== null && $row[$col] !== '') {
                    $overrides[$short] = (string) $row[$col];
                }
            }
        }

        $merged = array_merge($global, $overrides);
        return self::$configCache[$key] = [
            'config' => $merged,
            'overrides' => $overrides,
            'is_custom' => !empty($overrides),
        ];
    }

    /**
     * Compat: retorna o array de config (mesma forma que o código
     * antigo esperava).
     */
    public static function config(?int $inboxId = null): array
    {
        return self::configFor($inboxId)['config'];
    }

    public static function set(string $key, string $value): void
    {
        if (!array_key_exists($key, self::DEFAULT_CONFIG)) {
            return;
        }
        Setting::set('sla_' . $key, $value);
        self::$configCache = [];
    }

    public static function setAll(array $values): void
    {
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULT_CONFIG)) {
                self::set($k, (string) $v);
            }
        }
    }

    /**
     * Descarta o cache de config por caixa.
     *
     * Chamar depois de alterar a SLA de uma caixa (Inbox::update), para
     * que a mesma requisição não leia a config antiga.
     */
    public static function forgetConfig(): void
    {
        self::$configCache = [];
    }

    public static function thresholds(?int $inboxId = null): array
    {
        $cfg = self::config($inboxId);
        $attention = max(1, (int) $cfg['attention_minutes']);
        $alert = max($attention + 1, (int) $cfg['alert_minutes']);
        return [
            'attention_seconds' => $attention * 60,
            'alert_seconds' => $alert * 60,
        ];
    }

    /**
     * Calcula o nível de SLA com base no status e tempo de espera
     * (em segundos). Retorna um dos LEVEL_* (incluindo LEVEL_NONE).
     *
     * Regras (todas baseadas nos thresholds configurados para a caixa
     * ou, na falta deles, nos globais em /settings):
     *   - "new" (sem atendente atribuído) → NORMAL abaixo do limite de
     *     atenção (verde), ATTENTION ao atingir atenção (amarelo),
     *     ALERT ao atingir alerta (vermelho).
     *   - "open" / "waiting_*" (atendente já assumiu) → NONE abaixo do
     *     limite de atenção (fundo padrão, sem cor), ATTENTION ao
     *     atingir atenção, ALERT ao atingir alerta.
     *   - outros status (closed/resolved/spam) → NONE.
     */
    public static function levelFor(int $waitingSeconds, ?string $status = null, ?string $lastDirection = null, ?int $inboxId = null): string
    {
        $cfg = self::config($inboxId);
        if (($cfg['enabled'] ?? '1') !== '1') {
            return self::LEVEL_NONE;
        }

        if ($status !== null) {
            if (!in_array($status, self::ACTIVE_STATUSES, true)) {
                return self::LEVEL_NONE;
            }
        }

        $t = self::thresholds($inboxId);
        if ($waitingSeconds >= $t['alert_seconds']) {
            return self::LEVEL_ALERT;
        }
        if ($waitingSeconds >= $t['attention_seconds']) {
            return self::LEVEL_ATTENTION;
        }

        return ($status === 'new') ? self::LEVEL_NORMAL : self::LEVEL_NONE;
    }

    /**
     * Aplica o relógio do SLA às linhas de conversas da listagem.
     *
     * Reescreve `waiting_seconds` em segundos úteis (dentro do horário
     * comercial do departamento) e acrescenta os campos que a view e o
     * tick do navegador consomem:
     *   sla_frozen        1 quando o relógio não está correndo
     *   sla_paused_reason motivo da pausa (customer/internal/closed/out_of_hours)
     *   sla_next_boundary próxima virada do horário de atendimento
     *   sla_sync_at       instante do cálculo (âncora do tick)
     */
    public static function applyClock(array $rows, ?string $now = null): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $useBusinessHours = Setting::get(self::SETTING_BUSINESS_HOURS, '1') === '1';
        $appTz = date_default_timezone_get();
        $nowTs = $now ?: date('Y-m-d H:i:s');
        $nowEpoch = self::parseIn($nowTs, $appTz)?->getTimestamp() ?: time();

        // Grade por departamento, resolvida uma vez por requisição.
        $departments = [];

        foreach ($rows as $i => $row) {
            $deptId = !empty($row['department_id']) ? (int) $row['department_id'] : null;
            $schedule = null;
            $scheduleTz = $appTz;

            if ($useBusinessHours && $deptId) {
                if (!array_key_exists($deptId, $departments)) {
                    $departments[$deptId] = BusinessHoursService::slaConfig($deptId);
                }
                $deptConfig = $departments[$deptId];
                if ($deptConfig) {
                    $schedule = $deptConfig['schedule'];
                    $scheduleTz = $deptConfig['timezone'];
                }
            }

            $state = self::clockState([
                'status' => $row['status'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'last_inbound_at' => $row['sla_last_inbound_at'] ?? null,
                'last_inbound_id' => $row['sla_last_inbound_id'] ?? null,
                'last_public_id' => $row['sla_last_public_id'] ?? null,
            ], $schedule, $scheduleTz, $nowTs, $appTz);

            $rows[$i]['waiting_seconds'] = $state['waiting_seconds'];
            $rows[$i]['sla_frozen'] = $state['frozen'] ? 1 : 0;
            $rows[$i]['sla_paused_reason'] = $state['paused_reason'];
            $rows[$i]['sla_next_boundary'] = $state['next_boundary'];
            $rows[$i]['sla_boundary_at'] = $state['boundary_at'];
            $rows[$i]['sla_sync_at'] = $nowEpoch;
        }

        return $rows;
    }

    /**
     * Estado do relógio do SLA para uma conversa.
     *
     * Puro: recebe a linha da conversa, a grade do departamento e o
     * instante de referência. Devolve quantos segundos úteis a
     * conversation está esperando, se o relógio está correndo e por
     * que está congelado.
     *
     * Regras:
     *  - Status fora de ACTIVE_STATUSES (encerrada) => relógio zerado.
     *  - `waiting_internal` => relógio zerado (a bola está com outro
     *    setor/atendente).
     *  - Atendente falou depois do cliente => relógio zerado (a bola
     *    está com o cliente).
     *  - Caso contrário, conta desde a última mensagem do cliente (ou
     *    desde a criação, se o cliente nunca escreveu) apenas o tempo
     *    dentro das janelas abertas do departamento.
     *
     * @param array|null $schedule Grade do departamento; null = sem
     *        grade => conta tempo de relógio puro.
     * @param string $now         Instante de referência (fuso da app).
     * @param string $appTz       Fuso em que os timestamps do banco estão.
     */
    public static function clockState(array $row, ?array $schedule, string $scheduleTz, string $now, string $appTz = 'America/Sao_Paulo'): array
    {
        $status = (string) ($row['status'] ?? '');
        $nowLocal = self::parseIn($now, $appTz) ?? new \DateTimeImmutable('now', self::tz($appTz));

        if (!in_array($status, self::ACTIVE_STATUSES, true)) {
            return self::frozen(self::PAUSED_CLOSED);
        }

        if ($status === 'waiting_internal') {
            return self::frozen(self::PAUSED_INTERNAL);
        }

        $inboundId = (int) ($row['last_inbound_id'] ?? 0);
        $publicId = (int) ($row['last_public_id'] ?? 0);

        if ($inboundId > 0 && $publicId > $inboundId) {
            // O atendente falou depois do cliente: a bola está com o cliente.
            return self::frozen(self::PAUSED_CUSTOMER);
        }

        $anchor = $inboundId > 0
            ? self::parseIn($row['last_inbound_at'] ?? null, $appTz)
            : self::parseIn($row['created_at'] ?? null, $appTz);

        if ($anchor === null) {
            return self::frozen(self::PAUSED_CLOSED);
        }

        $elapsed = $schedule === null
            ? max(0, $nowLocal->getTimestamp() - $anchor->getTimestamp())
            : SlaClock::elapsedInSchedule($schedule, $scheduleTz, $anchor, $nowLocal);

        $isOpenNow = $schedule === null
            ? true
            : SlaClock::isOpenAt($schedule, $scheduleTz, $nowLocal);

        $boundary = null;
        $boundaryAt = 0;
        if ($schedule !== null) {
            $b = SlaClock::nextBoundary($schedule, $scheduleTz, $nowLocal);
            if ($b !== null) {
                $boundary = $b->setTimezone(self::tz($appTz))->format('Y-m-d H:i:s');
                $boundaryAt = $b->getTimestamp();
            }
        }

        return [
            'waiting_seconds' => max(0, $elapsed),
            'frozen' => !$isOpenNow,
            'paused_reason' => $isOpenNow ? null : self::PAUSED_OUT_OF_HOURS,
            'next_boundary' => $boundary,
            'boundary_at' => $boundaryAt,
        ];
    }

    /**
     * Relógio congelado, sem próxima virada (o departamento não importa:
     * o motivo é o status ou a bola com o cliente).
     */
    private static function frozen(string $reason): array
    {
        return [
            'waiting_seconds' => 0,
            'frozen' => true,
            'paused_reason' => $reason,
            'next_boundary' => null,
            'boundary_at' => 0,
        ];
    }

    private static function parseIn(?string $value, string $tz): ?\DateTimeImmutable
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
