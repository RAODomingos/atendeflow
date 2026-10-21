<?php
/**
 * Teste end-to-end do relógio do SLA contra o MySQL real.
 *
 *   php tests/e2e_sla.php
 *
 * Cria dados temporários (conversa, contato e grade de horário de um
 * departamento), verifica os invariantes do relógio e limpa tudo no fim.
 * Exige o banco de dados configurado (env/.env) e roda em seconds.
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

$DEPT = 2;    // Suporte
$INBOX = 3;   // Caixa Suporte
$CHANNEL = 2; // ChatWeb Suporte (departamento 2)

$bhBackup = $db->fetchAll("SELECT * FROM business_hours WHERE department_id = ?", [$DEPT]);
$deptBackup = $db->fetch("SELECT business_hours_enabled FROM departments WHERE id = ?", [$DEPT]);
$settingBackup = $db->fetch("SELECT value FROM settings WHERE `key` = 'sla_business_hours'");

$convId = null;
$contactId = null;

function rowFor(array $rows, int $id): ?array
{
    foreach ($rows as $r) {
        if ((int) $r['id'] === $id) {
            return $r;
        }
    }
    return null;
}

try {
    // ── cenário: seg-sex 08:00-18:00 ─────────────────────────────
    $db->execute("UPDATE departments SET business_hours_enabled = 1 WHERE id = ?", [$DEPT]);
    $db->delete('business_hours', 'department_id = ?', [$DEPT]);
    foreach (range(0, 6) as $d) {
        $db->insert('business_hours', [
            'department_id' => $DEPT,
            'day_of_week' => $d,
            'open_time' => '08:00:00',
            'close_time' => '18:00:00',
            'is_open' => ($d >= 1 && $d <= 5) ? 1 : 0,
        ]);
    }
    \App\Models\Setting::set('sla_business_hours', '1');

    $contactId = $db->insert('contacts', [
        'name' => 'TESTE SLA E2E',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $convId = $db->insert('conversations', [
        'public_id' => bin2hex(random_bytes(16)),
        'contact_id' => $contactId,
        'department_id' => $DEPT,
        'channel_id' => $CHANNEL,
        'inbox_id' => $INBOX,
        'status' => 'open',
        'subject' => 'TESTE SLA E2E',
        'source' => 'webchat',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Mensagem do cliente há 3 dias (sempre no passado).
    $anchor = date('Y-m-d H:i:s', strtotime('-3 days'));
    $db->insert('messages', [
        'conversation_id' => $convId,
        'type' => 'text',
        'content' => 'ola, preciso de ajuda',
        'direction' => 'inbound',
        'is_read' => 0,
        'created_at' => $anchor,
        'updated_at' => $anchor,
    ]);

    $row = rowFor(\App\Models\Conversation::getConversationsForInbox($INBOX), $convId);
    check('a conversa de teste aparece na listagem', $row !== null);
    if ($row === null) {
        throw new RuntimeException('conversa nao encontrada na listagem');
    }

    $wallClock = time() - strtotime($anchor);
    $util = (int) $row['waiting_seconds'];
    echo "   [info] ancora=$anchor | uteis=$util | relogio=$wallClock"
        . " | frozen=" . $row['sla_frozen'] . " | motivo=" . var_export($row['sla_paused_reason'], true)
        . " | boundary=" . var_export($row['sla_next_boundary'], true) . PHP_EOL;

    check('a ancora da espera foi exposta na listagem', $row['sla_last_inbound_at'] === $anchor, (string) $row['sla_last_inbound_at']);
    check('o relogio conta segundos uteis (nunca mais que o relogio)', $util >= 0 && $util <= $wallClock, "uteis=$util relogio=$wallClock");
    check('o relogio util e menor que o relogio de parede', $util < $wallClock, "uteis=$util relogio=$wallClock");
    check('o instante do sync foi gravado', (int) $row['sla_sync_at'] > 0);
    check('o congelamento e um booleano de DOM', in_array($row['sla_frozen'], [0, 1], true));

    // O congelamento bate com o horario real do departamento.
    $openNow = (int) date('w') >= 1 && (int) date('w') <= 5
        && date('H:i') >= '08:00' && date('H:i') <= '18:00';
    check('o congelamento bate com o horario do departamento', (int) $row['sla_frozen'] === ($openNow ? 0 : 1), "aberto agora=" . var_export($openNow, true));
    if (!$openNow) {
        check('fora do horario o motivo e out_of_hours', $row['sla_paused_reason'] === 'out_of_hours');
        check('fora do horario a proxima virada e a proxima abertura', (bool) preg_match('/^\d{4}-\d{2}-\d{2} 08:00:00$/', (string) $row['sla_next_boundary']), (string) $row['sla_next_boundary']);
    } else {
        check('dentro do horario nao ha motivo de pausa', $row['sla_paused_reason'] === null);
        check('dentro do horario a proxima virada e o fechamento', (bool) preg_match('/^\d{4}-\d{2}-\d{2} 18:00:00$/', (string) $row['sla_next_boundary']), (string) $row['sla_next_boundary']);
    }

    // ── cenario 2: atendente responde -> bola com o cliente ───────
    $db->insert('messages', [
        'conversation_id' => $convId,
        'type' => 'text',
        'content' => 'ola, ja te respondo',
        'direction' => 'outbound',
        'user_id' => 1,
        'is_read' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $row = rowFor(\App\Models\Conversation::getConversationsForInbox($INBOX), $convId);
    check('bola com o cliente zera o relogio', (int) $row['waiting_seconds'] === 0, 'motivo=' . var_export($row['sla_paused_reason'], true));
    check('bola com o cliente marca o motivo customer', $row['sla_paused_reason'] === 'customer');
    check('bola com o cliente congela', (int) $row['sla_frozen'] === 1);

    // ── cenario 3: waiting_internal congela ───────────────────────
    $db->update('conversations', ['status' => 'waiting_internal'], 'id = ?', [$convId]);
    $row = rowFor(\App\Models\Conversation::getConversationsForInbox($INBOX), $convId);
    check('waiting_internal congela o relogio', (int) $row['waiting_seconds'] === 0);
    check('waiting_internal marca o motivo internal', $row['sla_paused_reason'] === 'internal');

    // ── cenario 4: status final zera ──────────────────────────────
    $db->update('conversations', ['status' => 'resolved'], 'id = ?', [$convId]);
    $row = rowFor(\App\Models\Conversation::getConversationsForInbox($INBOX), $convId);
    check('status final zera o relogio', (int) $row['waiting_seconds'] === 0);
    check('status final marca o motivo closed', $row['sla_paused_reason'] === 'closed');

    // ── cenario 5: toggle global desliga o horario comercial ──────
    \App\Models\Setting::set('sla_business_hours', '0');
    $db->update('conversations', ['status' => 'open'], 'id = ?', [$convId]);
    // Remove a resposta do atendente: o cliente precisa ter falado por
    // ultimo, senao a bola esta com o cliente e o relogio fica zerado.
    $db->execute("DELETE FROM messages WHERE conversation_id = ? AND direction = 'outbound'", [$convId]);
    $row = rowFor(\App\Models\Conversation::getConversationsForInbox($INBOX), $convId);
    $wallNow = time() - strtotime($anchor);
    check('com o toggle desligado volta a contar tempo de relogio', (int) $row['waiting_seconds'] === $wallNow, "util=" . $row['waiting_seconds'] . " relogio=" . $wallNow);
    check('com o toggle desligado o relogio nunca congela', (int) $row['sla_frozen'] === 0);

    \App\Models\Setting::set('sla_business_hours', '1');
} catch (Throwable $e) {
    $fail++;
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    if ($convId) {
        $db->execute("DELETE FROM messages WHERE conversation_id = ?", [$convId]);
        $db->execute("DELETE FROM conversations WHERE id = ?", [$convId]);
    }
    if ($contactId) {
        $db->execute("DELETE FROM contacts WHERE id = ?", [$contactId]);
    }
    $db->delete('business_hours', 'department_id = ?', [$DEPT]);
    foreach ($bhBackup as $r) {
        $db->insert('business_hours', [
            'department_id' => $r['department_id'],
            'day_of_week' => $r['day_of_week'],
            'open_time' => $r['open_time'],
            'close_time' => $r['close_time'],
            'is_open' => $r['is_open'],
        ]);
    }
    $db->execute("UPDATE departments SET business_hours_enabled = ? WHERE id = ?", [$deptBackup['business_hours_enabled'], $DEPT]);
    if ($settingBackup) {
        \App\Models\Setting::set('sla_business_hours', $settingBackup['value']);
    } else {
        $db->execute("DELETE FROM settings WHERE `key` = 'sla_business_hours'");
    }
    echo 'cleanup ok' . PHP_EOL;
}

exit($fail > 0 ? 1 : 0);
