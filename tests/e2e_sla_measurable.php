<?php
/**
 * Teste end-to-end da medição de SLA contra o MySQL real.
 *
 *   php tests/e2e_sla_measurable.php
 *
 * Cria dados temporários, verifica e limpa tudo no fim.
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

try {
    $contactId = $db->insert('contacts', [
        'name' => 'TESTE SLA MEDIVEL',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $channelId = (int) $db->fetch("SELECT id FROM channels WHERE type = 'webchat' ORDER BY id LIMIT 1")['id'];
    $inboxId = (int) $db->fetch("SELECT id FROM inboxes WHERE type = 'department' ORDER BY id LIMIT 1")['id'];
    $convId = $db->insert('conversations', [
        'public_id' => bin2hex(random_bytes(16)),
        'contact_id' => $contactId,
        'channel_id' => $channelId,
        'inbox_id' => $inboxId,
        'status' => 'open',
        'source' => 'webchat',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // ── model ConversationSla ──────────────────────────────────────
    $hasModel = class_exists('App\Models\ConversationSla');

    check('sla: o model ConversationSla existe', $hasModel);

    if ($hasModel) {
        $sla = 'App\Models\ConversationSla';

        check('sla: a linha nasce inexistente', $sla::find($convId) === null);

        $id = $sla::save($convId, [
            'response_seconds' => 1200,
            'response_at' => '2026-10-19 09:20:00',
            'response_breached' => 0,
            'response_threshold' => 900,
            'resolution_seconds' => 1200,
            'resolution_breached' => 0,
            'resolution_threshold' => 86400,
            'period_started_at' => '2026-10-19 09:00:00',
            'metadata' => ['origem' => 'teste'],
        ]);
        check('sla: o save cria a linha', $id > 0, 'id=' . $id);

        $row = $sla::find($convId);
        check('sla: a linha gravou os dois SLAs', (int) $row['response_seconds'] === 1200 && (int) $row['resolution_seconds'] === 1200);
        check('sla: a linha gravou os limites (snapshot)', (int) $row['response_threshold'] === 900 && (int) $row['resolution_threshold'] === 86400);
        check('sla: a linha guardou o periodo em aberto', $row['period_started_at'] === '2026-10-19 09:00:00');
        check('sla: o metadata virou JSON', str_contains((string) $row['metadata'], 'origem'));

        // save() de novo = update, nao insert duplicado
        $id2 = $sla::save($convId, ['response_seconds' => 600]);
        $rows = $db->fetch("SELECT COUNT(*) c FROM conversation_slas WHERE conversation_id = ?", [$convId]);
        check('sla: o save faz upsert (nao duplica)', (int) $rows['c'] === 1, 'linhas=' . $rows['c']);
        check('sla: o update aplicou o valor novo', (int) $sla::find($convId)['response_seconds'] === 600);
        check('sla: o upsert devolve o mesmo id', $id2 === $id, "$id2 vs $id");

        // reset() zera os acumulados e marca o novo ciclo
        $sla::reset($convId, '2026-10-19 09:30:00');
        $row = $sla::find($convId);
        check('sla: o reset zera a primeira resposta', $row['response_seconds'] === null && (int) $row['response_breached'] === 0);
        check('sla: o reset zera a resolucao', (int) $row['resolution_seconds'] === 0 && (int) $row['resolution_breached'] === 0);
        check('sla: o reset limpa o periodo em aberto', $row['period_started_at'] === null);
        check('sla: o reset marca o inicio do novo ciclo', str_contains((string) $row['metadata'], '2026-10-19 09:30:00'));
    }
} catch (Throwable $e) {
    $fail++;
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    if ($convId) {
        $db->execute("DELETE FROM conversation_slas WHERE conversation_id = ?", [$convId]);
        $db->execute("DELETE FROM conversations WHERE id = ?", [$convId]);
    }
    if ($contactId) {
        $db->execute("DELETE FROM contacts WHERE id = ?", [$contactId]);
    }
    echo 'cleanup ok' . PHP_EOL;
}

exit($fail > 0 ? 1 : 0);
