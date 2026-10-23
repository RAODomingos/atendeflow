<?php
/**
 * Teste end-to-end da reabertura de conversa no SLA.
 *
 *   php tests/e2e_sla_reopen.php
 *
 * Regressão do bug em que a conversa reaberta nunca mais era medida:
 * o reset() mantinha a linha com `period_started_at` vazio e nenhuma
 * das consultas do worker a selecionava — o ciclo novo ficava sem SLA
 * para sempre (e ainda contava como "cumprido" no compliance).
 *
 * Cria dados temporarios, verifica e limpa tudo no fim.
 */
$loader = require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';

$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

\App\Core\Database::connect();
$db = \App\Core\Database::getInstance();

$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $fail;
    if ($cond) {
        echo "ok - $name" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "FALHOU - $name" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
    }
}

$convId = null;
$contactId = null;
$userId = null;
$deptId = 2;

// O worker mede todas as conversas ativas do banco: para nao deixar
// rastro, a tabela conversation_slas e os eventos de SLA sao restaurados.
$slasBackup = $db->fetchAll("SELECT * FROM conversation_slas");
$slaEventsBackup = $db->fetchAll("SELECT * FROM conversation_events WHERE event_type IN ('sla_breached','sla_escalated')");

try {
    $userId = $db->insert('users', [
        'name' => 'TESTE SLA REABERTURA',
        'email' => 'teste-sla-reabertura@atendeflow.local',
        'password' => password_hash('Teste@12345', PASSWORD_BCRYPT),
        'role' => 'agent',
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $contactId = $db->insert('contacts', [
        'name' => 'TESTE SLA REABERTURA',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $channelId = (int) $db->fetch("SELECT id FROM channels WHERE type = 'webchat' ORDER BY id LIMIT 1")['id'];
    $inboxId = (int) $db->fetch(
        "SELECT i.id FROM inboxes i
         JOIN inbox_departments idp ON idp.inbox_id = i.id
         WHERE idp.department_id = ? AND i.type = 'department'
         ORDER BY i.id LIMIT 1",
        [$deptId]
    )['id'];

    $convId = $db->insert('conversations', [
        'public_id' => bin2hex(random_bytes(16)),
        'contact_id' => $contactId,
        'channel_id' => $channelId,
        'inbox_id' => $inboxId,
        'department_id' => $deptId,
        'assigned_user_id' => $userId,
        'status' => 'open',
        'source' => 'webchat',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Mensagem do cliente: e a ancora do relogio de primeira resposta.
    $db->insert('messages', [
        'conversation_id' => $convId,
        'direction' => 'inbound',
        'type' => 'text',
        'content' => 'preciso de ajuda',
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
        'updated_at' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
    ]);

    $root = dirname(__DIR__);
    $workerOut = [];
    $workerCode = 0;
    $runWorker = function () use ($root, &$workerOut, &$workerCode) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/workers/sla_worker.php') . ' 2>&1', $workerOut, $workerCode);
    };

    // ── 1) conversa ativa e medida ────────────────────────────────
    $runWorker();
    check('sla: o worker roda sem erro', $workerCode === 0, implode(' | ', array_slice($workerOut, -2)));

    $row = \App\Models\ConversationSla::find($convId);
    check('sla: a conversa ativa foi medida', $row !== null);
    check('sla: a conversa ativa tem periodo em aberto', $row !== null && !empty($row['period_started_at']), 'periodo=' . ($row['period_started_at'] ?? 'null'));

    // ── 2) encerra e reaberta (o reopen zera o ciclo) ─────────────
    $service = new \App\Services\ConversationService();
    $service->changeStatus($convId, 'closed');
    $service->changeStatus($convId, 'open');

    $row = \App\Models\ConversationSla::find($convId);
    check('sla: a reabertura limpou o periodo em aberto', $row !== null && $row['period_started_at'] === null, 'periodo=' . ($row['period_started_at'] ?? 'null'));
    check('sla: a reabertura guardou o inicio do novo ciclo', $row !== null && str_contains((string) $row['metadata'], 'cycle_started_at'));

    // ── 3) o cliente volta a falar: o ciclo novo comeca a medir ──
    // Sem mensagem nova do cliente nao ha o que medir (o relogio da
    // primeira resposta comeca na mensagem seguinte). O que nao pode
    // acontecer e a conversa ficar de fora das consultas do worker.
    $db->insert('messages', [
        'conversation_id' => $convId,
        'direction' => 'inbound',
        'type' => 'text',
        'content' => 'voltei, ainda preciso de ajuda',
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $runWorker();
    check('sla: o worker roda sem erro apos a reabertura', $workerCode === 0, implode(' | ', array_slice($workerOut, -2)));

    $row = \App\Models\ConversationSla::find($convId);
    check('sla: a conversa reaberta voltou a ser medida', $row !== null && !empty($row['period_started_at']), 'periodo=' . ($row['period_started_at'] ?? 'null'));
    check('sla: a conversa reaberta tem primeira resposta medida', $row !== null && $row['response_seconds'] !== null, 'response_seconds=' . ($row['response_seconds'] ?? 'null'));

    $rowsCount = $db->fetch("SELECT COUNT(*) c FROM conversation_slas WHERE conversation_id = ?", [$convId]);
    check('sla: a conversa reaberta continua com uma unica linha', (int) $rowsCount['c'] === 1, 'linhas=' . $rowsCount['c']);

    // A linha pendente de medicao nao pode ficar na fila para sempre.
    $pending = \App\Models\ConversationSla::activeWithPendingCycle(500);
    check('sla: a conversa reaberta saiu da fila de ciclos pendentes', !in_array((int) $convId, array_map('intval', $pending), true));
} catch (Throwable $e) {
    $fail++;
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    // restaura a tabela conversation_slas e os eventos de SLA
    $db->execute("DELETE FROM conversation_slas");
    foreach ($slasBackup as $r) {
        unset($r['id']);
        $db->insert('conversation_slas', $r);
    }
    $db->execute("DELETE FROM conversation_events WHERE event_type IN ('sla_breached','sla_escalated')");
    foreach ($slaEventsBackup as $r) {
        unset($r['id']);
        $db->insert('conversation_events', $r);
    }

    if ($convId) {
        $db->execute("DELETE FROM conversation_slas WHERE conversation_id = ?", [$convId]);
        $db->execute("DELETE FROM conversation_events WHERE conversation_id = ?", [$convId]);
        $db->execute("DELETE FROM messages WHERE conversation_id = ?", [$convId]);
        $db->execute("DELETE FROM conversations WHERE id = ?", [$convId]);
    }
    if ($contactId) {
        $db->execute("DELETE FROM contacts WHERE id = ?", [$contactId]);
    }
    if ($userId) {
        $db->execute("DELETE FROM users WHERE id = ?", [$userId]);
    }
    echo 'cleanup ok' . PHP_EOL;
}

exit($fail > 0 ? 1 : 0);
