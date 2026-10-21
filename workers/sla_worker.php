<?php
/**
 * Worker de medição do SLA.
 *
 * Executar via cron (a cada minuto):
 *   * * * * * cd /var/www/atendeflow && php workers/sla_worker.php >> storage/logs/sla_worker.log 2>&1
 *
 * Por execução:
 *  1. Mede as conversas ativas (sem linha de SLA ou com período em aberto)
 *  2. Detecta a transição 0 -> 1 de `response_breached` e dispara o
 *     evento `sla_breached` (o escalonamento entra aqui na Task 6)
 *  3. Finaliza as conversas encerradas: o valor final é gravado uma vez
 *     e não muda mais (é o que dá o compliance histórico)
 *  4. Faz o backfill das conversas encerradas antigas, em lote
 *
 * Idempotente: rodar duas vezes não muda nada.
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Core\Database;
use App\Models\Conversation;
use App\Models\ConversationSla;
use App\Services\SlaMeasurement;

$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

Database::connect();

/** Quantas conversas processar por categoria, por execução. */
$limit = 200;

echo '[' . date('Y-m-d H:i:s') . "] Iniciando worker de SLA...\n";

try {
    $measured = 0;
    $violations = 0;
    $finalized = 0;
    $backfilled = 0;

    /**
     * Mede uma conversa e detecta a transição de violação.
     */
    $process = function (int $conversationId) use (&$measured, &$violations) {
        $measurement = SlaMeasurement::forConversation($conversationId);
        if ($measurement === null) {
            return; // grupo de WhatsApp ou conversa inexistente
        }

        $previous = ConversationSla::find($conversationId);
        $wasBreached = $previous
            ? (int) ($previous['response_breached'] ?? 0) === 1
            : false;

        SlaMeasurement::saveMeasurement($conversationId, $measurement);
        $measured++;

        if ($measurement['response_breached'] && !$wasBreached) {
            Conversation::addEvent(
                $conversationId,
                'sla_breached',
                'SLA de primeira resposta violado',
                null,
                [
                    'response_seconds' => $measurement['response_seconds'],
                    'threshold' => $measurement['response_threshold'],
                ]
            );
            $violations++;
        }
    };

    // 1) conversas ativas com período em aberto: o relógio pode ter mudado
    foreach (ConversationSla::openPeriods($limit) as $conversationId) {
        $process($conversationId);
    }

    // 2) conversas ativas sem linha: precisam da primeira medição
    foreach (ConversationSla::activeWithoutRow($limit) as $conversationId) {
        $process($conversationId);
    }

    // 3) conversas encerradas: o valor final é gravado e congelado
    foreach (ConversationSla::closedWithoutRow($limit) as $conversationId) {
        $process($conversationId);
        $finalized++;
    }

    echo '[' . date('Y-m-d H:i:s') . "] SLA: {$measured} medidas, {$violations} violacoes detectadas, {$finalized} finalizadas\n";
    echo '[' . date('Y-m-d H:i:s') . "] Worker concluído com sucesso.\n";
} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . "] ERRO: " . $e->getMessage() . "\n";
    echo '[' . date('Y-m-d H:i:s') . "] Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
