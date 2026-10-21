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
        'response_seconds',
        'response_at',
        'response_breached',
        'response_threshold',
        'resolution_seconds',
        'resolution_breached',
        'resolution_threshold',
        'period_started_at',
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
}
