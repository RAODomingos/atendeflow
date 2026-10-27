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
 *     evento `sla_breached` + o escalonamento
 *  3. Finaliza as conversas encerradas que já estavam sendo medidas
 *     (congela o valor final da resolução)
 *  4. Faz o backfill das conversas encerradas antigas, em lote
 *
 * Backfill NÃO escala: o histórico serve para o relatório de compliance,
 * não para notificar gerentes nem mandar WhatsApp para o plantão. Só
 * conversas encerradas há pouco tempo (que ainda estavam sendo medidas
 * ao vivo) escalam normalmente.
 *
 * Idempotente: rodar duas vezes não muda nada.
 * Exclusivo: flock impede que dois crons sobrepostos processem a mesma
 * conversa (o escalonamento duplicava notificação, etiqueta e WhatsApp).
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Core\Database;
use App\Models\Conversation;
use App\Models\ConversationSla;
use App\Services\SlaEscalation;
use App\Services\SlaMeasurement;

$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

Database::connect();

/** Quantas conversas processar por categoria, por execução. */
$limit = 200;

/**
 * Conversas encerradas há menos que isso ainda são "quentes": foram
 * encerradas enquanto o worker as media ao vivo, então uma violação
 * nelas é real e precisa escalar. Acima da janela é backfill.
 */
$escalateGraceMinutes = 15;

// ── Exclusividade: só uma execução por vez ────────────────────────────
$lockDir = dirname(__DIR__) . '/storage/cache';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockHandle = @fopen($lockDir . '/sla_worker.lock', 'c');
if (!$lockHandle) {
    echo '[' . date('Y-m-d H:i:s') . "] ERRO: nao foi possivel abrir o lock do worker.\n";
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] SLA: outra execucao esta em andamento; saindo.\n";
    exit(0);
}

echo '[' . date('Y-m-d H:i:s') . "] Iniciando worker de SLA...\n";

try {
    $measured = 0;
    $violations = 0;
    $finalized = 0;
    $backfilled = 0;
    $errors = 0;

    /**
     * Mede uma conversa e detecta a transição de violação.
     *
     * @param string $mode 'live' (medida ao vivo), 'finalize' (congela o
     *                     valor de uma conversa que estava sendo medida) ou
     *                     'backfill' (histórico: grava o valor, mas não
     *                     gera evento nem escalonamento).
     */
    $process = function (int $conversationId, string $mode = 'live') use (&$measured, &$violations, &$errors) {
        try {
            $measurement = SlaMeasurement::forConversation($conversationId);
            if ($measurement === null) {
                return; // conversa inexistente
            }

            $previous = ConversationSla::find($conversationId);
            $wasBreached = $previous
                ? (int) ($previous['response_breached'] ?? 0) === 1
                : false;

            // Backfill e historico: grava o valor para o compliance, mas
            // nao polui a linha do tempo nem dispara notificacao/WhatsApp.
            if ($mode === 'backfill') {
                SlaMeasurement::saveMeasurement($conversationId, $measurement);
                $measured++;
                return;
            }

            if ($measurement['response_breached'] && !$wasBreached) {
                // Reserva atomica da violacao: com dois workers
                // sobrepostos, so um escalona (tag, gerentes e WhatsApp
                // sao disparados uma vez so).
                $claimed = ConversationSla::claimResponseBreach($conversationId);
                SlaMeasurement::saveMeasurement($conversationId, $measurement);
                $measured++;

                if ($claimed) {
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

                    // Escalonamento (tag, gerentes, autoatribuição, WhatsApp).
                    $conv = Conversation::find($conversationId);
                    if ($conv) {
                        SlaEscalation::escalate($conversationId, $measurement, $conv);
                    }
                }
            } else {
                SlaMeasurement::saveMeasurement($conversationId, $measurement);
                $measured++;
            }

            // Alerta de "cliente sem resposta": dispara uma vez por
            // MENSAGEM do cliente que fica sem resposta — inclusive em
            // conversas que ja foram atendidas antes. A escalonamento de
            // 1a resposta so acontece uma vez por conversa, entao sozinha
            // ela deixava passar o caso mais comum: o cliente escreve de
            // novo e ninguem responde.
            //
            // Conversas que NUNCA tiveram resposta humana sao deixadas de
            // fora: elas ja sao cobertas pela violacao de 1a resposta acima
            // (e nao podem gerar dois alertas para o mesmo atraso).
            $pendingId = (int) ($measurement['pending_message_id'] ?? 0);
            $hadHumanReply = !empty($measurement['response_at']);
            if (
                $pendingId > 0
                && $hadHumanReply
                && !empty($measurement['pending_breached'])
            ) {
                $claimed = ConversationSla::claimPendingBreach($conversationId, $pendingId);
                if ($claimed) {
                    Conversation::addEvent(
                        $conversationId,
                        'sla_breached',
                        'Cliente sem resposta',
                        null,
                        [
                            'pending_seconds' => $measurement['pending_seconds'],
                            'threshold' => $measurement['pending_threshold'],
                            'message_id' => $pendingId,
                        ]
                    );
                    $violations++;

                    $conv = Conversation::find($conversationId);
                    if ($conv) {
                        SlaEscalation::escalate(
                            $conversationId,
                            $measurement,
                            $conv,
                            SlaEscalation::KIND_PENDING
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // Uma conversa quebrada nao pode abortar a rodada inteira:
            // as demais ficariam sem medicao naquele minuto.
            $errors++;
            echo '[' . date('Y-m-d H:i:s') . "] ERRO na conversa #{$conversationId}: " . $e->getMessage() . "\n";
        }
    };

    // 1) conversas ativas com período em aberto: o relógio pode ter mudado
    foreach (ConversationSla::openPeriods($limit) as $conversationId) {
        $process($conversationId, 'live');
    }

    // 2) conversas ativas sem linha: precisam da primeira medição
    foreach (ConversationSla::activeWithoutRow($limit) as $conversationId) {
        $process($conversationId, 'live');
    }

    // 2b) conversas ativas com ciclo reiniciado (reabertura): a linha
    //     existe, mas o ciclo novo ainda nao foi medido. Sem esta etapa
    //     a conversa reaberta ficava sem SLA para sempre.
    foreach (ConversationSla::activeWithPendingCycle($limit) as $conversationId) {
        $process($conversationId, 'live');
    }

    // 3) conversas encerradas que já estavam sendo medidas: congela o valor
    //    final (a resolução só fecha com o encerramento).
    foreach (ConversationSla::closedWithStaleRow($limit) as $conversationId) {
        $process($conversationId, 'finalize');
        $finalized++;
    }

    // 4) backfill das conversas encerradas sem linha: histórico puro.
    //    Encerradas há pouco tempo ainda escalam (foram medidas ao vivo).
    $graceLimit = date('Y-m-d H:i:s', time() - ($escalateGraceMinutes * 60));
    foreach (ConversationSla::closedWithoutRow($limit) as $row) {
        $mode = ($row['closed_at'] >= $graceLimit) ? 'finalize' : 'backfill';
        $process($row['id'], $mode);
        if ($mode === 'backfill') {
            $backfilled++;
        } else {
            $finalized++;
        }
    }

    // 5) conversas de GRUPO: SLA da MENÇÃO (da marcação até a 1ª resposta).
    //    O grupo não tem "1ª resposta" nem "resolução": o que se mede é o
    //    tempo que a menção ficou sem resposta. Cada menção tem o seu
    //    próprio alerta (reserva por pending_alerted_message_id).
    foreach (ConversationSla::groupsWithMentions(50) as $conversationId) {
        try {
            $measurement = SlaMeasurement::forConversation($conversationId);
            if ($measurement === null || empty($measurement['pending_message_id'])) {
                continue;
            }
            $mentionId = (int) $measurement['pending_message_id'];
            $claimed = false;
            if (!empty($measurement['pending_breached'])) {
                $claimed = ConversationSla::claimPendingBreach($conversationId, $mentionId);
            }
            SlaMeasurement::saveMeasurement($conversationId, $measurement);
            $measured++;

            if ($claimed) {
                Conversation::addEvent(
                    $conversationId,
                    'sla_breached',
                    'Menção em grupo sem resposta',
                    null,
                    [
                        'pending_seconds' => $measurement['pending_seconds'],
                        'threshold' => $measurement['pending_threshold'],
                        'message_id' => $mentionId,
                    ]
                );
                $violations++;

                $conv = Conversation::find($conversationId);
                if ($conv) {
                    SlaEscalation::escalate(
                        $conversationId,
                        $measurement,
                        $conv,
                        SlaEscalation::KIND_GROUP_MENTION
                    );
                }
            }
        } catch (\Throwable $e) {
            $errors++;
            echo '[' . date('Y-m-d H:i:s') . "] ERRO na conversa de grupo #{$conversationId}: " . $e->getMessage() . "\n";
        }
    }

    echo '[' . date('Y-m-d H:i:s') . "] SLA: {$measured} medidas, {$violations} violacoes detectadas, "
        . "{$finalized} finalizadas, {$backfilled} backfill\n";
    if ($errors > 0) {
        echo '[' . date('Y-m-d H:i:s') . "] {$errors} conversa(s) com erro nesta rodada\n";
    }
    echo '[' . date('Y-m-d H:i:s') . "] Worker concluído com sucesso.\n";
} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . "] ERRO: " . $e->getMessage() . "\n";
    echo '[' . date('Y-m-d H:i:s') . "] Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
