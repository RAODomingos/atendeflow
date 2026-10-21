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
$userId = null;
$deptId = 2;

try {
    $userId = $db->insert('users', [
        'name' => 'TESTE SLA MEDIVEL',
        'email' => 'teste-sla-medivel@atendeflow.local',
        'password' => password_hash('Teste@12345', PASSWORD_BCRYPT),
        'role' => 'agent',
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
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
        'department_id' => $deptId,
        'assigned_user_id' => $userId,
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

    // ── medição contra o banco ───────────────────────────────────
    $hasMeasurement = class_exists('App\Services\SlaMeasurement')
        && method_exists('App\Services\SlaMeasurement', 'forConversation');
    check('sla: a medicao contra o banco existe', $hasMeasurement);

    if ($hasMeasurement) {
        $meas = 'App\Services\SlaMeasurement';

        // O bloco do model deixou uma linha com cycle_started_at; a medição
        // a usaria para descartar as mensagens — limpa antes de medir.
        $db->execute("DELETE FROM conversation_slas WHERE conversation_id = ?", [$convId]);

        // Departamento 2 com horário comercial seg-sex 08:00-18:00.
        $bhBackup = $db->fetchAll("SELECT * FROM business_hours WHERE department_id = ?", [$deptId]);
        $deptBackup = $db->fetch("SELECT business_hours_enabled FROM departments WHERE id = ?", [$deptId]);
        $db->execute("UPDATE departments SET business_hours_enabled = 1 WHERE id = ?", [$deptId]);
        $db->delete('business_hours', 'department_id = ?', [$deptId]);
        foreach (range(0, 6) as $d) {
            $db->insert('business_hours', [
                'department_id' => $deptId,
                'day_of_week' => $d,
                'open_time' => '08:00:00',
                'close_time' => '18:00:00',
                'is_open' => ($d >= 1 && $d <= 5) ? 1 : 0,
            ]);
        }

        // Limite folgado, para a conversa de exemplo não violar.
        $limitBackup = $db->fetch("SELECT value FROM settings WHERE `key` = 'sla_response_minutes'");
        \App\Models\Setting::set('sla_response_minutes', '60');

        $db->insert('messages', [
            'conversation_id' => $convId,
            'type' => 'text',
            'content' => 'ola, preciso de ajuda',
            'direction' => 'inbound',
            'is_read' => 0,
            'created_at' => '2026-10-19 09:00:00',
            'updated_at' => '2026-10-19 09:00:00',
        ]);
        $db->insert('messages', [
            'conversation_id' => $convId,
            'type' => 'text',
            'content' => 'ola, ja te respondo',
            'direction' => 'outbound',
            'user_id' => $userId,
            'is_read' => 1,
            'created_at' => '2026-10-19 09:20:00',
            'updated_at' => '2026-10-19 09:20:00',
        ]);

        $m = $meas::forConversation($convId, '2026-10-19 09:20:00');
        check('sla: a medicao devolve o resultado', is_array($m) && $m !== [], json_encode($m));
        check('sla: a primeira resposta mede 20 minutos', (int) ($m['response_seconds'] ?? -1) === 1200, json_encode($m));
        check('sla: a conversa dentro do limite nao viola', ($m['response_breached'] ?? null) === false, json_encode($m));

        $meas::saveMeasurement($convId, $m);
        $row = \App\Models\ConversationSla::find($convId);
        check('sla: a medicao foi gravada', $row !== null);
        check('sla: a linha guarda a primeira resposta', (int) ($row['response_seconds'] ?? -1) === 1200);
        check('sla: a linha guarda o limite em vigor', (int) ($row['response_threshold'] ?? -1) === 3600, 'threshold=' . ($row['response_threshold'] ?? 'null'));

        // ── linha do tempo de status ──────────────────────────────
        $svc = new \App\Services\ConversationService();
        $svc->changeStatus($convId, 'waiting_internal');
        $timeline = $meas::loadTimeline($convId);
        $statuses = array_column($timeline, 'status');
        check('sla: a linha do tempo le a mudanca de status', in_array('waiting_internal', $statuses, true) && end($statuses) === 'waiting_internal', json_encode($timeline));
        check('sla: a linha do tempo conhece o status inicial', ($statuses[0] ?? null) === 'open', json_encode($timeline));

        $ev = $db->fetch(
            "SELECT metadata FROM conversation_events WHERE conversation_id = ? AND event_type = 'status_changed' ORDER BY id DESC LIMIT 1",
            [$convId]
        );
        $meta = json_decode((string) ($ev['metadata'] ?? ''), true);
        check('sla: a mudanca de status grava from e to', ($meta['from'] ?? null) === 'open' && ($meta['to'] ?? null) === 'waiting_internal', json_encode($meta));

        // ── reabertura reinicia o ciclo ───────────────────────────
        $csatBackup = $db->fetch("SELECT value FROM settings WHERE `key` = 'csat_enabled'");
        \App\Models\Setting::set('csat_enabled', '0');
        $svc->changeStatus($convId, 'closed');
        $svc->changeStatus($convId, 'open');
        $row = \App\Models\ConversationSla::find($convId);
        check('sla: a reabertura zera a primeira resposta', $row !== null && $row['response_seconds'] === null);
        check('sla: a reabertura zera a resolucao', $row !== null && (int) $row['resolution_seconds'] === 0);
        check('sla: a reabertura marca o novo ciclo', $row !== null && str_contains((string) $row['metadata'], 'cycle_started_at'), (string) ($row['metadata'] ?? ''));

        // ── snapshot do limite (Review Focus #4) ──────────────────
        \App\Models\Setting::set('sla_response_minutes', '15');
        $m2 = $meas::forConversation($convId, '2026-10-19 09:20:00');
        check('sla: a mudanca de config muda o limite da medicao', (int) ($m2['response_threshold'] ?? -1) === 900, 'threshold=' . ($m2['response_threshold'] ?? 'null'));
        $row = \App\Models\ConversationSla::find($convId);
        check('sla: a linha ja gravada guarda o limite antigo', (int) ($row['response_threshold'] ?? -1) === 3600, 'threshold=' . ($row['response_threshold'] ?? 'null'));

        // restaura tudo
        if ($csatBackup) {
            \App\Models\Setting::set('csat_enabled', $csatBackup['value']);
        } else {
            $db->execute("DELETE FROM settings WHERE `key` = 'csat_enabled'");
        }
        if ($limitBackup) {
            \App\Models\Setting::set('sla_response_minutes', $limitBackup['value']);
        } else {
            $db->execute("DELETE FROM settings WHERE `key` = 'sla_response_minutes'");
        }
        $db->delete('business_hours', 'department_id = ?', [$deptId]);
        foreach ($bhBackup as $r) {
            $db->insert('business_hours', [
                'department_id' => $r['department_id'],
                'day_of_week' => $r['day_of_week'],
                'open_time' => $r['open_time'],
                'close_time' => $r['close_time'],
                'is_open' => $r['is_open'],
            ]);
        }
        $db->execute("UPDATE departments SET business_hours_enabled = ? WHERE id = ?", [$deptBackup['business_hours_enabled'], $deptId]);
    }

    // ── config: global e override por caixa ───────────────────────
    $slaSvc = 'App\Services\SlaService';
    $cfgBackup = $db->fetchAll("SELECT `key`, value FROM settings WHERE `key` IN ('sla_response_minutes','sla_resolution_minutes','sla_business_hours')");
    $inboxCfgBackup = $db->fetch(
        "SELECT sla_response_minutes, sla_resolution_minutes, sla_business_hours FROM inboxes WHERE id = ?",
        [$inboxId]
    );

    \App\Models\Setting::set('sla_response_minutes', '30');
    \App\Models\Setting::set('sla_resolution_minutes', '120');
    $slaSvc::forgetConfig();

    $global = $slaSvc::configFor(null)['config'] ?? [];
    check('sla: a config global tem o limite de primeira resposta', (int) ($global['response_minutes'] ?? 0) === 30, json_encode($global));
    check('sla: a config global tem o limite de resolucao', (int) ($global['resolution_minutes'] ?? 0) === 120);
    check('sla: a config global tem os toggles de escalonamento', array_key_exists('escalate_managers', $global) && array_key_exists('escalate_autoassign', $global) && array_key_exists('escalate_whatsapp', $global), json_encode(array_keys($global)));

    $db->execute("UPDATE inboxes SET sla_response_minutes = 60, sla_resolution_minutes = 240 WHERE id = ?", [$inboxId]);
    $slaSvc::forgetConfig();
    $box = $slaSvc::configFor($inboxId)['config'] ?? [];
    check('sla: a caixa sobrescreve o limite global', (int) ($box['response_minutes'] ?? 0) === 60, json_encode($box));
    check('sla: a caixa sobrescreve o limite de resolucao', (int) ($box['resolution_minutes'] ?? 0) === 240);

    $db->execute("UPDATE inboxes SET sla_response_minutes = NULL, sla_resolution_minutes = NULL WHERE id = ?", [$inboxId]);
    $slaSvc::forgetConfig();
    $box = $slaSvc::configFor($inboxId)['config'] ?? [];
    check('sla: caixa sem override herda o global', (int) ($box['response_minutes'] ?? 0) === 30 && (int) ($box['resolution_minutes'] ?? 0) === 120, json_encode($box));

    // restaura
    $db->execute(
        "UPDATE inboxes SET sla_response_minutes = ?, sla_resolution_minutes = ?, sla_business_hours = ? WHERE id = ?",
        [
            $inboxCfgBackup['sla_response_minutes'],
            $inboxCfgBackup['sla_resolution_minutes'],
            $inboxCfgBackup['sla_business_hours'],
            $inboxId,
        ]
    );
    foreach ($cfgBackup as $r) {
        \App\Models\Setting::set((string) $r['key'], (string) $r['value']);
    }
    $slaSvc::forgetConfig();
} catch (Throwable $e) {
    $fail++;
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
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
