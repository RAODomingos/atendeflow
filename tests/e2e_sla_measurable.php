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

// Settings tocados pelo teste: o backup permite restaurar exatamente
// (inclusive apagando as chaves que nao existiam antes).
$settingsTouched = [
    'sla_response_minutes', 'sla_resolution_minutes', 'sla_business_hours',
    'csat_enabled',
    'sla_escalate_managers', 'sla_escalate_tag', 'sla_escalate_autoassign', 'sla_escalate_whatsapp',
];
$settingsBackup = $db->fetchAll(
    "SELECT `key`, value FROM settings WHERE `key` IN ('" . implode("','", $settingsTouched) . "')"
);
$settingsBackupMap = [];
foreach ($settingsBackup as $r) {
    $settingsBackupMap[(string) $r['key']] = (string) $r['value'];
}

// O worker mede todas as conversas ativas do banco: para o teste nao
// deixar rastro, a tabela conversation_slas e os eventos de SLA sao
// restaurados no finally.
$slasBackup = $db->fetchAll("SELECT * FROM conversation_slas");
$slaEventsBackup = $db->fetchAll("SELECT * FROM conversation_events WHERE event_type IN ('sla_breached','sla_escalated')");

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
    // A caixa precisa estar vinculada ao departamento 2 (e a ela os
    // atendentes do departamento sao candidatos a autoatribuicao).
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
        \App\Models\Setting::set('csat_enabled', '0');        $svc->changeStatus($convId, 'closed');
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

        // (horario do departamento e settings sao restaurados no finally)
    }

    // O bloco do worker mede em tempo de relogio: restaura aqui o
    // horario original do departamento (a janela comercial fecharia as
    // 18:00 e cortaria a espera em poucos segundos).
    if (isset($bhBackup)) {
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
        $db->execute(
            "UPDATE departments SET business_hours_enabled = ? WHERE id = ?",
            [$deptBackup['business_hours_enabled'], $deptId]
        );
    }

    // ── config: global e override por caixa ───────────────────────
    $slaSvc = 'App\Services\SlaService';
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

    // ── worker: mede, detecta a violação e é idempotente ─────────
    $root = dirname(__DIR__);
    $workerOut = [];
    $workerCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/workers/sla_worker.php') . ' 2>&1', $workerOut, $workerCode);
    check('sla: o worker roda sem erro', $workerCode === 0, implode(' | ', array_slice($workerOut, -3)));

    $db->execute("DELETE FROM conversation_slas WHERE conversation_id = ?", [$convId]);
    $db->execute("DELETE FROM conversation_events WHERE conversation_id = ? AND event_type IN ('sla_breached','sla_escalated')", [$convId]);

    // Conversa ativa com cliente esperando ha mais que o limite: viola.
    $violConvId = $db->insert('conversations', [
        'public_id' => bin2hex(random_bytes(16)),
        'contact_id' => $contactId,
        'channel_id' => $channelId,
        'inbox_id' => $inboxId,
        'department_id' => $deptId,
        'assigned_user_id' => $userId,
        'status' => 'open',
        'source' => 'webchat',
        'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours')),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $db->insert('messages', [
        'conversation_id' => $violConvId,
        'type' => 'text',
        'content' => 'alguem me atende?',
        'direction' => 'inbound',
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours')),
        'updated_at' => date('Y-m-d H:i:s', strtotime('-3 hours')),
    ]);

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/workers/sla_worker.php') . ' 2>&1', $workerOut, $workerCode);
    check('sla: o worker roda sem erro na 2a vez', $workerCode === 0, implode(' | ', array_slice($workerOut, -3)));

    $row = \App\Models\ConversationSla::find($violConvId);
    check('sla: o worker mediu a conversa ativa', $row !== null);
    check('sla: o worker mediu a espera em aberto', (int) ($row['response_seconds'] ?? -1) >= 10800, 'response_seconds=' . ($row['response_seconds'] ?? 'null'));
    check('sla: o worker detectou a violacao', (int) ($row['response_breached'] ?? 0) === 1);

    $events = $db->fetchAll(
        "SELECT id FROM conversation_events WHERE conversation_id = ? AND event_type = 'sla_breached'",
        [$violConvId]
    );
    check('sla: o worker registrou o evento de violacao', count($events) === 1, 'eventos=' . count($events));

    // Idempotencia: rodar de novo nao duplica o evento nem a linha.
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/workers/sla_worker.php') . ' 2>&1', $workerOut, $workerCode);
    $events = $db->fetchAll(
        "SELECT id FROM conversation_events WHERE conversation_id = ? AND event_type = 'sla_breached'",
        [$violConvId]
    );
    check('sla: o worker e idempotente (nao duplica o evento)', count($events) === 1, 'eventos=' . count($events));
    $rowsCount = $db->fetch("SELECT COUNT(*) c FROM conversation_slas WHERE conversation_id = ?", [$violConvId]);
    check('sla: o worker e idempotente (nao duplica a linha)', (int) $rowsCount['c'] === 1, 'linhas=' . $rowsCount['c']);

    // ── leituras: chip, KPI e relatório ──────────────────────────
    check('sla: o dashboard sabe quantas conversas estao em sla critico', \App\Models\ConversationSla::criticalNow() >= 1);
    $compliance = \App\Models\ConversationSla::compliance(30);
    check('sla: o compliance mede a primeira resposta', ($compliance['response']['measured'] ?? 0) >= 1, json_encode($compliance));
    check('sla: o compliance da resolucao so conta conversas encerradas', array_key_exists('measured', $compliance['resolution'] ?? []));
    $slaMap = \App\Models\ConversationSla::forConversations([$violConvId]);
    check('sla: o lote de linhas de sla funciona', isset($slaMap[$violConvId]), json_encode(array_keys($slaMap)));
    $summary = \App\Models\ConversationSla::summaryForConversation($violConvId);
    check('sla: o resumo de uma conversa funciona', $summary !== null && (int) $summary['response_breached'] === 1);

    $db->execute("DELETE FROM conversation_slas WHERE conversation_id = ?", [$violConvId]);
    $db->execute("DELETE FROM conversation_events WHERE conversation_id = ? AND event_type IN ('sla_breached','sla_escalated')", [$violConvId]);
    $db->execute("DELETE FROM messages WHERE conversation_id = ?", [$violConvId]);
    $db->execute("DELETE FROM conversations WHERE id = ?", [$violConvId]);

    // restaura os overrides da caixa (as settings vao no finally)
    $db->execute(
        "UPDATE inboxes SET sla_response_minutes = ?, sla_resolution_minutes = ?, sla_business_hours = ? WHERE id = ?",
        [
            $inboxCfgBackup['sla_response_minutes'],
            $inboxCfgBackup['sla_resolution_minutes'],
            $inboxCfgBackup['sla_business_hours'],
            $inboxId,
        ]
    );
    $slaSvc::forgetConfig();
    // ── escalonamento ────────────────────────────────────────────
    $hasEscalation = class_exists('App\Services\SlaEscalation')
        && method_exists('App\Services\SlaEscalation', 'escalate');
    check('sla: o escalonamento existe', $hasEscalation);

    if ($hasEscalation) {
        // O usuario de teste vira gerente do departamento 2.
        $db->insert('department_users', [
            'department_id' => $deptId,
            'user_id' => $userId,
            'is_manager' => 1,
        ]);
        // Um segundo agente, sem carga: deve ser o escolhido na
        // autoatribuicao (menos carregado).
        $otherUserId = $db->insert('users', [
            'name' => 'TESTE SLA AGENTE',
            'email' => 'teste-sla-agente@atendeflow.local',
            'password' => password_hash('Teste@12345', PASSWORD_BCRYPT),
            'role' => 'agent',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->insert('department_users', [
            'department_id' => $deptId,
            'user_id' => $otherUserId,
            'is_manager' => 0,
        ]);

        // Uma conversa aberta atribuida ao gerente, para ele ter carga 1.
        $busyConvId = $db->insert('conversations', [
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

        // Conversa sem responsavel: candidata a autoatribuicao.
        $escConvId = $db->insert('conversations', [
            'public_id' => bin2hex(random_bytes(16)),
            'contact_id' => $contactId,
            'channel_id' => $channelId,
            'inbox_id' => $inboxId,
            'department_id' => $deptId,
            'assigned_user_id' => null,
            'status' => 'open',
            'source' => 'webchat',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        \App\Models\Setting::set('sla_escalate_autoassign', '1');

        $measurement = [
            'response_seconds' => 3600,
            'response_at' => null,
            'response_breached' => true,
            'response_threshold' => 900,
            'resolution_seconds' => 3600,
            'resolution_breached' => false,
            'resolution_threshold' => 86400,
            'period_started_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ];
        $conv = \App\Models\Conversation::find($escConvId);
        $result = \App\Services\SlaEscalation::escalate($escConvId, $measurement, $conv);

        check('sla: o escalonamento aplicou a tag', ($result['tag'] ?? false) === true, json_encode($result));
        check('sla: o escalonamento notificou o gerente', (int) ($result['managers'] ?? 0) >= 1, json_encode($result));
        check('sla: o escalonamento autoatribuiu', !empty($result['autoassign']), json_encode($result));
        check('sla: a autoatribuicao escolheu o menos carregado', (int) ($result['autoassign'] ?? 0) === (int) $otherUserId, 'escolhido=' . ($result['autoassign'] ?? 'null') . ' esperado=' . $otherUserId);

        $tag = \App\Models\Tag::findByName('SLA violado');
        check('sla: a tag de sistema foi criada', $tag !== null);
        $tagged = $db->fetch(
            "SELECT COUNT(*) c FROM conversation_tags WHERE conversation_id = ? AND tag_id = ?",
            [$escConvId, (int) ($tag['id'] ?? 0)]
        );
        check('sla: a tag foi aplicada na conversa', (int) $tagged['c'] === 1, 'linhas=' . $tagged['c']);

        $notifs = $db->fetch(
            "SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND notification_type = 'system' AND conversation_id = ?",
            [$userId, $escConvId]
        );
        check('sla: o gerente recebeu a notificacao', (int) $notifs['c'] === 1, 'linhas=' . $notifs['c']);

        $events = $db->fetch(
            "SELECT COUNT(*) c FROM conversation_events WHERE conversation_id = ? AND event_type = 'sla_escalated'",
            [$escConvId]
        );
        check('sla: o escalonamento registrou o evento', (int) $events['c'] === 1, 'eventos=' . $events['c']);

        // Conservatism: conversa COM responsavel ativo nao e reatribuida.
        $convBusy = \App\Models\Conversation::find($busyConvId);
        $resultBusy = \App\Services\SlaEscalation::escalate($busyConvId, $measurement, $convBusy);
        check('sla: o escalonamento nao rouba a conversa de quem atende', ($resultBusy['autoassign'] ?? null) === null, json_encode($resultBusy));

        // Toggles desligados: nenhuma acao acontece.
        \App\Models\Setting::set('sla_escalate_autoassign', '0');
        \App\Models\Setting::set('sla_escalate_tag', '0');
        \App\Models\Setting::set('sla_escalate_managers', '0');
        $quietConvId = $db->insert('conversations', [
            'public_id' => bin2hex(random_bytes(16)),
            'contact_id' => $contactId,
            'channel_id' => $channelId,
            'inbox_id' => $inboxId,
            'department_id' => $deptId,
            'status' => 'open',
            'source' => 'webchat',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $resultQuiet = \App\Services\SlaEscalation::escalate($quietConvId, $measurement, \App\Models\Conversation::find($quietConvId));
        check('sla: com os toggles desligados nada acontece', ($resultQuiet['tag'] ?? true) === false && (int) ($resultQuiet['managers'] ?? -1) === 0 && ($resultQuiet['autoassign'] ?? null) === null, json_encode($resultQuiet));

        // limpeza
        foreach ([$escConvId, $busyConvId, $quietConvId] as $cid) {
            $db->execute("DELETE FROM notifications WHERE conversation_id = ?", [$cid]);
            $db->execute("DELETE FROM conversation_events WHERE conversation_id = ?", [$cid]);
            $db->execute("DELETE FROM conversation_tags WHERE conversation_id = ?", [$cid]);
            $db->execute("DELETE FROM conversations WHERE id = ?", [$cid]);
        }
        $db->execute("DELETE FROM conversation_tags WHERE tag_id = ?", [(int) ($tag['id'] ?? 0)]);
        $db->execute("DELETE FROM tags WHERE name = 'SLA violado'");
        $db->execute("DELETE FROM department_users WHERE user_id IN (?, ?)", [$userId, $otherUserId]);
        $db->execute("DELETE FROM users WHERE id = ?", [$otherUserId]);
    }
} catch (Throwable $e) {
    $fail++;
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    // restaura o horario do departamento
    if (isset($bhBackup)) {
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
        $db->execute(
            "UPDATE departments SET business_hours_enabled = ? WHERE id = ?",
            [$deptBackup['business_hours_enabled'], $deptId]
        );
    }

    // restaura as settings tocadas (e apaga as que nao existiam)
    foreach ($settingsTouched as $k) {
        if (array_key_exists($k, $settingsBackupMap)) {
            \App\Models\Setting::set($k, $settingsBackupMap[$k]);
        } else {
            $db->execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        }
    }
    \App\Services\SlaService::forgetConfig();

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
        // Todas as conversas do contato de teste (inclusive as criadas
        // pelo bloco de escalonamento, que pode falhar no meio).
        $extra = $db->fetchAll("SELECT id FROM conversations WHERE contact_id = ?", [$contactId]);
        foreach ($extra as $r) {
            $db->execute("DELETE FROM notifications WHERE conversation_id = ?", [$r['id']]);
            $db->execute("DELETE FROM conversation_events WHERE conversation_id = ?", [$r['id']]);
            $db->execute("DELETE FROM conversation_tags WHERE conversation_id = ?", [$r['id']]);
            $db->execute("DELETE FROM conversation_slas WHERE conversation_id = ?", [$r['id']]);
            $db->execute("DELETE FROM messages WHERE conversation_id = ?", [$r['id']]);
            $db->execute("DELETE FROM conversations WHERE id = ?", [$r['id']]);
        }
        $db->execute("DELETE FROM contacts WHERE id = ?", [$contactId]);
    }
    if ($userId) {
        $db->execute("DELETE FROM users WHERE id = ?", [$userId]);
    }
    echo 'cleanup ok' . PHP_EOL;
}

exit($fail > 0 ? 1 : 0);
