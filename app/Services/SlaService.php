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

    public const SOUND_PROFILES = ['default', 'soft', 'sharp', 'silent'];

    public const DEFAULT_CONFIG = [
        'enabled' => '1',
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
        $global = self::globalConfig();
        if ($inboxId === null) {
            return ['config' => $global, 'overrides' => [], 'is_custom' => false];
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
        return [
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
    }

    public static function setAll(array $values): void
    {
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULT_CONFIG)) {
                self::set($k, (string) $v);
            }
        }
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
            $active = ['new', 'open', 'waiting_customer', 'waiting_internal'];
            if (!in_array($status, $active, true)) {
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
}
