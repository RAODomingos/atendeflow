<?php

namespace App\Models;

use App\Core\Database;

/**
 * Regra de reabertura de atendimento.
 *
 * Decide o que acontece quando o mesmo cliente escreve de novo no
 * WhatsApp depois de o atendimento anterior ter sido encerrado:
 *
 *  - unique: todo atendimento é único (comportamento padrão);
 *  - always: a nova mensagem reabre o atendimento anterior;
 *  - window: reabre o anterior só se a última mensagem dele foi há
 *    menos de X minutos.
 *
 * A regra vigente é a da CAIXA do atendimento anterior (é ela que
 * volta para a fila e que tem SLA). Sem override na caixa, vale o
 * padrão do sistema.
 */
class AtendimentoPolicy
{
    public const MODE_UNIQUE = 'unique';
    public const MODE_ALWAYS = 'always';
    public const MODE_WINDOW = 'window';

    public const MODES = [self::MODE_UNIQUE, self::MODE_ALWAYS, self::MODE_WINDOW];

    public const DEFAULT_WINDOW_MINUTES = 120;

    public const SETTING_MODE = 'atendimento_reabertura_modo';
    public const SETTING_WINDOW = 'atendimento_reabertura_janela_minutos';

    /**
     * Modo desconhecido (ou ausente) cai no padrão seguro: único.
     */
    public static function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_UNIQUE;
    }

    /**
     * Janela em minutos. Zero ou negativo não faz sentido: volta ao
     * padrão do sistema.
     */
    public static function normalizeWindowMinutes($minutes): int
    {
        $n = (int) $minutes;
        return $n > 0 ? $n : self::DEFAULT_WINDOW_MINUTES;
    }

    /**
     * Decisão pura: a mesma entrada devolve sempre a mesma saída.
     *
     * $referenceAt é o instante de referência do atendimento anterior
     * (a última mensagem). Sem referência, o modo janela não reabre.
     */
    public static function decide(string $mode, int $windowMinutes, ?string $referenceAt, ?int $now = null): bool
    {
        $mode = self::normalizeMode($mode);

        if ($mode === self::MODE_UNIQUE) {
            return false;
        }

        if ($mode === self::MODE_ALWAYS) {
            return true;
        }

        // Modo janela: reabre se a última mensagem do atendimento
        // anterior está dentro da janela.
        $referenceAt = trim((string) $referenceAt);
        if ($referenceAt === '') {
            return false;
        }

        $reference = strtotime($referenceAt);
        if ($reference === false) {
            return false;
        }

        $now ??= time();
        $window = self::normalizeWindowMinutes($windowMinutes) * 60;

        return ($now - $reference) <= $window;
    }

    /**
     * Padrão do sistema (settings).
     *
     * @return array{mode:string, window_minutes:int}
     */
    public static function globalConfig(): array
    {
        return [
            'mode' => self::normalizeMode(Setting::get(self::SETTING_MODE, self::MODE_UNIQUE)),
            'window_minutes' => self::normalizeWindowMinutes(
                Setting::get(self::SETTING_WINDOW, self::DEFAULT_WINDOW_MINUTES)
            ),
        ];
    }

    /**
     * Regra vigente para uma caixa: override da caixa > padrão global.
     *
     * @return array{mode:string, window_minutes:int, source:string}
     *         source = 'inbox' (override da caixa) | 'global' (padrão)
     */
    public static function forInbox(?int $inboxId): array
    {
        $global = self::globalConfig();

        if ($inboxId === null || !self::hasColumns()) {
            return $global + ['source' => 'global'];
        }

        $row = Inbox::find($inboxId);
        if (!$row) {
            return $global + ['source' => 'global'];
        }

        $mode = null;
        if (array_key_exists('atendimento_modo', $row) && $row['atendimento_modo'] !== null && $row['atendimento_modo'] !== '') {
            $mode = self::normalizeMode((string) $row['atendimento_modo']);
        }

        if ($mode === null) {
            // Caixa sem override: herda o padrão do sistema.
            return $global + ['source' => 'global'];
        }

        $window = $global['window_minutes'];
        if (array_key_exists('atendimento_janela_minutos', $row)
            && $row['atendimento_janela_minutos'] !== null
            && $row['atendimento_janela_minutos'] !== '') {
            $window = self::normalizeWindowMinutes((int) $row['atendimento_janela_minutos']);
        }

        return [
            'mode' => $mode,
            'window_minutes' => $window,
            'source' => 'inbox',
        ];
    }

    /**
     * O atendimento anterior do mesmo contato no mesmo canal.
     *
     * Só conversa encerrada de verdade (resolved/closed) e nunca
     * conversa de grupo: grupo é permanente e fica sempre em aberto.
     *
     * @return array|null linha de `conversations`
     */
    public static function previousConversation(int $channelId, int $contactId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM conversations
              WHERE contact_id = ? AND channel_id = ?
                AND group_id IS NULL
                AND status IN ('resolved', 'closed')
              ORDER BY COALESCE(last_message_at, created_at) DESC
              LIMIT 1",
            [$contactId, $channelId]
        );
    }

    /**
     * A conversa anterior deve ser reaberta?
     *
     * @param array|null $previous linha de `conversations`
     * @param array      $policy   saída de forInbox()
     */
    public static function shouldReopen(?array $previous, array $policy): bool
    {
        if (!$previous) {
            return false;
        }

        // A janela é contada da ÚLTIMA MENSAGEM do atendimento anterior.
        $reference = $previous['last_message_at'] ?: ($previous['created_at'] ?? null);

        return self::decide(
            (string) ($policy['mode'] ?? self::MODE_UNIQUE),
            (int) ($policy['window_minutes'] ?? self::DEFAULT_WINDOW_MINUTES),
            $reference
        );
    }

    /**
     * A base tem as colunas de reabertura em `inboxes`?
     *
     * Instalação que ainda não rodou a migração fica no modo único —
     * o comportamento de hoje — em vez de quebrar em runtime.
     */
    private static ?bool $columnsCache = null;

    public static function hasColumns(): bool
    {
        if (self::$columnsCache !== null) {
            return self::$columnsCache;
        }

        try {
            $row = Database::getInstance()->fetch("SHOW COLUMNS FROM inboxes LIKE 'atendimento_modo'");
            self::$columnsCache = !empty($row);
        } catch (\Throwable $e) {
            self::$columnsCache = false;
        }

        return self::$columnsCache;
    }
}
