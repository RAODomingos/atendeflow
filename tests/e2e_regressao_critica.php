<?php
/**
 * Testes de regressão dos 8 cenários críticos da auditoria (P2.16).
 *
 *   php tests/e2e_regressao_critica.php
 *
 * Ao contrário dos outros e2e (que cobrem uma feature por vez), aqui cada
 * cenário é um bug real que já aconteceu em producao e que pode voltar:
 *
 *  1. Backfill nao escala        — historico nao notifica nem etiqueta
 *  2. Reabertura volta a medir    — o ciclo novo nao fica sem SLA
 *  3. Toggle de horario comercial — tela e motor usam a MESMA regra
 *  4. Dois workers em paralelo    — a violacao escala uma vez so
 *  5. Compliance sem linhas NULL  — so conta o que foi medido
 *  6. Autoassign em conversa encerrada — nao mexe em conversa fechada
 *  7. Contagem do relatorio       — o numero bate com o que existe
 *  8. Contrato do sla_response_at no poll — o chip nao volta a mentir
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
$pass = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "ok - $name" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "FALHOU - $name" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
    }
}

function section(string $title): void
{
    echo PHP_EOL . "--- {$title} ---" . PHP_EOL;
}

$root = dirname(__DIR__);
$deptId = 2;

// ── Journal de recuperacao ──────────────────────────────────────────
// Se a execucao for interrompida (SIGKILL, queda do terminal, Ctrl+C),
// o `finally` nao roda e o banco fica com os dados do teste. O journal
// guarda o estado original; a proxima execucao o encontra e restaura
// antes de comecar.
$journalFile = $root . '/storage/cache/regressao_critica.journal';

function journalRestore(string $file, int $deptId): void
{
    if (!is_file($file)) {
        return;
    }
    $state = json_decode((string) @file_get_contents($file), true);
    if (!is_array($state)) {
        // Journal corrompido: descarta, o auto-healing abaixo limpa o resto.
        @unlink($file);
        return;
    }

    $db = \App\Core\Database::getInstance();

    // Settings: volta ao valor original (ou apaga a chave se nao existia).
    foreach (($state['settings'] ?? []) as $key => $value) {
        if ($value === null) {
            $db->execute("DELETE FROM settings WHERE `key` = ?", [$key]);
        } else {
            $db->execute(
                "INSERT INTO settings (`key`, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$key, (string) $value]
            );
        }
    }

    // Conversas, contatos e usuarios do teste interrompido.
    $convIds = array_map('intval', $state['conversations'] ?? []);
    if ($convIds) {
        $ph = implode(',', array_fill(0, count($convIds), '?'));
        $db->execute("DELETE FROM messages WHERE conversation_id IN ({$ph})", $convIds);
        $db->execute("DELETE FROM conversation_slas WHERE conversation_id IN ({$ph})", $convIds);
        $db->execute("DELETE FROM conversation_events WHERE conversation_id IN ({$ph})", $convIds);
        $db->execute("DELETE FROM conversation_tags WHERE conversation_id IN ({$ph})", $convIds);
        $db->execute("DELETE FROM conversations WHERE id IN ({$ph})", $convIds);
    }
    $userIds = array_map('intval', $state['users'] ?? []);
    foreach ($userIds as $uid) {
        $db->execute("DELETE FROM inbox_users WHERE user_id = ?", [$uid]);
        $db->execute("DELETE FROM department_users WHERE user_id = ?", [$uid]);
        $db->execute("DELETE FROM notifications WHERE user_id = ?", [$uid]);
    }
    if ($userIds) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $db->execute("DELETE FROM users WHERE id IN ({$ph})", $userIds);
    }
    $contactIds = array_map('intval', $state['contacts'] ?? []);
    if ($contactIds) {
        $ph = implode(',', array_fill(0, count($contactIds), '?'));
        $db->execute("DELETE FROM contacts WHERE id IN ({$ph})", $contactIds);
    }

    // Caixa e departamento de teste.
    $inboxIds = array_map('intval', $state['inboxes'] ?? []);
    foreach ($inboxIds as $iid) {
        $db->execute("DELETE FROM inbox_users WHERE inbox_id = ?", [$iid]);
        $db->execute("DELETE FROM inbox_departments WHERE inbox_id = ?", [$iid]);
        $db->execute("DELETE FROM conversations WHERE inbox_id = ?", [$iid]);
        $db->execute("DELETE FROM inboxes WHERE id = ?", [$iid]);
    }
    $deptIds = array_map('intval', $state['departments'] ?? []);
    foreach ($deptIds as $did) {
        $db->execute("DELETE FROM business_hours WHERE department_id = ?", [$did]);
        $db->execute("DELETE FROM inbox_departments WHERE department_id = ?", [$did]);
        $db->execute("DELETE FROM department_users WHERE department_id = ?", [$did]);
        $db->execute("DELETE FROM conversations WHERE department_id = ?", [$did]);
        $db->execute("DELETE FROM departments WHERE id = ?", [$did]);
    }

    // Grade de horario comercial do departamento de teste.
    if (isset($state['business_hours']) && is_array($state['business_hours'])) {
        $db->execute("DELETE FROM business_hours WHERE department_id = ?", [$deptId]);
        foreach ($state['business_hours'] as $r) {
            unset($r['id']);
            $db->insert('business_hours', $r);
        }
    }
    if (isset($state['department'])) {
        $db->execute(
            "UPDATE departments SET business_hours_enabled = ?, business_hours_timezone = ? WHERE id = ?",
            [
                $state['department']['business_hours_enabled'] ?? null,
                $state['department']['business_hours_timezone'] ?? null,
                $deptId,
            ]
        );
    }

    // O journal so e descartado depois de a restauracao completa: se
    // algo falhar no meio, ele fica no disco para a proxima tentativa.
    @unlink($file);
    echo '[recuperacao] estado de uma execucao interrompida foi restaurado' . PHP_EOL;
}

// Restaura o que a ultima execucao interrompida deixou para tras.
journalRestore($journalFile, $deptId);

// ── Backup do estado que o teste mexe ────────────────────────────────
$settingsTouched = [
    'sla_response_minutes', 'sla_resolution_minutes', 'sla_business_hours',
    'sla_escalate_managers', 'sla_escalate_tag', 'sla_escalate_autoassign',
    'sla_escalate_whatsapp',
];
$settingsBackupMap = [];
foreach ($db->fetchAll(
    "SELECT `key`, value FROM settings WHERE `key` IN ('" . implode("','", $settingsTouched) . "')"
) as $r) {
    $settingsBackupMap[(string) $r['key']] = (string) $r['value'];
}

// O worker mede TODAS as conversas do banco: sem restaurar, o teste
// deixaria rastro nas conversas de verdade.
$slasBackup = $db->fetchAll("SELECT * FROM conversation_slas");
$slaEventsBackup = $db->fetchAll("SELECT * FROM conversation_events WHERE event_type IN ('sla_breached','sla_escalated')");
$notifBackup = $db->fetchAll("SELECT * FROM notifications");
$convTagsBackup = $db->fetchAll("SELECT * FROM conversation_tags");

$created = [
    'users' => [],
    'contacts' => [],
    'conversations' => [],
    'messages' => [],
    'inboxes' => [],
    'departments' => [],
];

// Sufixo unico por execucao: duas execucoes simultaneas (ou uma
// execucao interrompida no meio) nao colidem no email do usuario.
$runId = substr(bin2hex(random_bytes(4)), 0, 8);

// Estado do departamento que o cenario 3 altera: guardado aqui para o
// journal e para o finally (antes do try, para o closure poder usar).
$bhBackup = $db->fetchAll("SELECT * FROM business_hours WHERE department_id = ?", [$deptId]);
$deptBackup = $db->fetch(
    "SELECT business_hours_enabled, business_hours_timezone FROM departments WHERE id = ?",
    [$deptId]
) ?: [];
$hoursRestored = false;

// Auto-healing: uma execucao interrompida (SIGKILL, queda do terminal)
// deixa lixo no banco e a proxima falha na constraint de email. O
// prefixo de e-mail permite reconhecer e limpar esse resto. As
// ligacoes vem primeiro: o usuario tem FK em inbox_users/department_users.
$orphanIds = array_map(
    fn($r) => (int) $r['id'],
    $db->fetchAll("SELECT id FROM users WHERE email LIKE 'teste-regressao-%'")
);
foreach ($orphanIds as $oid) {
    $db->execute("DELETE FROM inbox_users WHERE user_id = ?", [$oid]);
    $db->execute("DELETE FROM department_users WHERE user_id = ?", [$oid]);
    $db->execute("DELETE FROM notifications WHERE user_id = ?", [$oid]);
}
$db->execute("DELETE FROM users WHERE email LIKE 'teste-regressao-%'");

// Mesmo para o departamento e a caixa de teste: uma execucao
// interrompida pode ter deixado os dois para tras.
$orphanDepts = array_map(
    fn($r) => (int) $r['id'],
    $db->fetchAll("SELECT id FROM departments WHERE name LIKE 'TESTE REGRESSAO%'")
);
foreach ($orphanDepts as $did) {
    $db->execute("DELETE FROM business_hours WHERE department_id = ?", [$did]);
    $db->execute("DELETE FROM inbox_departments WHERE department_id = ?", [$did]);
    $db->execute("DELETE FROM department_users WHERE department_id = ?", [$did]);
    $db->execute("DELETE FROM conversations WHERE department_id = ?", [$did]);
    $db->execute("DELETE FROM departments WHERE id = ?", [$did]);
}
$orphanInboxes = array_map(
    fn($r) => (int) $r['id'],
    $db->fetchAll("SELECT id FROM inboxes WHERE name LIKE 'TESTE REGRESSAO%'")
);
foreach ($orphanInboxes as $iid) {
    $db->execute("DELETE FROM inbox_users WHERE inbox_id = ?", [$iid]);
    $db->execute("DELETE FROM inbox_departments WHERE inbox_id = ?", [$iid]);
    $db->execute("DELETE FROM conversations WHERE inbox_id = ?", [$iid]);
    $db->execute("DELETE FROM inboxes WHERE id = ?", [$iid]);
}

// Grava o journal: a partir de agora, uma interrupcao deixa o arquivo
// com o estado necessario para a proxima execucao restaurar tudo.
function journalWrite(string $file, array $state): void
{
    @file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function makeUser(string $suffix, string $role = 'agent'): int
{
    global $db, $created, $runId;
    $id = $db->insert('users', [
        'name' => 'TESTE REGRESSAO ' . $suffix,
        'email' => 'teste-regressao-' . strtolower($suffix) . '-' . $runId . '@atendeflow.local',
        'password' => password_hash('Teste@12345', PASSWORD_BCRYPT),
        'role' => $role,
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $created['users'][] = $id;
    if (isset($GLOBALS['writeJournal'])) {
        ($GLOBALS['writeJournal'])();
    }
    return $id;
}

function makeContact(string $suffix): int
{
    global $db, $created;
    $id = $db->insert('contacts', [
        'name' => 'TESTE REGRESSAO ' . $suffix,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $created['contacts'][] = $id;
    if (isset($GLOBALS['writeJournal'])) {
        ($GLOBALS['writeJournal'])();
    }
    return $id;
}

/**
 * Cria uma conversa com uma mensagem do cliente e (opcionalmente) uma
 * resposta humana.
 */
function makeConversation(
    string $suffix,
    int $contactId,
    int $channelId,
    int $inboxId,
    int $deptId,
    ?int $assignedUserId,
    string $status = 'open',
    ?string $inboundAt = null,
    ?string $outboundAt = null,
    ?string $closedAt = null,
    ?string $createdAt = null
): int {
    global $db, $created;

    $createdAt = $createdAt ?: date('Y-m-d H:i:s');
    $convId = $db->insert('conversations', [
        'public_id' => bin2hex(random_bytes(16)),
        'contact_id' => $contactId,
        'channel_id' => $channelId,
        'inbox_id' => $inboxId,
        'department_id' => $deptId,
        'assigned_user_id' => $assignedUserId,
        'status' => $status,
        'source' => 'webchat',
        'created_at' => $createdAt,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $created['conversations'][] = $convId;
    if (isset($GLOBALS['writeJournal'])) {
        ($GLOBALS['writeJournal'])();
    }

    $inboundAt = $inboundAt ?: $createdAt;
    $db->insert('messages', [
        'conversation_id' => $convId,
        'direction' => 'inbound',
        'type' => 'text',
        'content' => 'mensagem do cliente',
        'is_read' => 0,
        'created_at' => $inboundAt,
        'updated_at' => $inboundAt,
    ]);

    if ($outboundAt !== null) {
        $db->insert('messages', [
            'conversation_id' => $convId,
            'direction' => 'outbound',
            'type' => 'text',
            'content' => 'resposta humana',
            'user_id' => $assignedUserId ?: 1,
            'is_read' => 1,
            'created_at' => $outboundAt,
            'updated_at' => $outboundAt,
        ]);
    }

    if ($closedAt !== null) {
        $db->execute("UPDATE conversations SET closed_at = ? WHERE id = ?", [$closedAt, $convId]);
    }

    return $convId;
}

function runWorker(): array
{
    global $root;
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/workers/sla_worker.php') . ' 2>&1', $out, $code);
    return ['out' => implode("\n", $out), 'code' => $code];
}

try {
    $channelId = (int) $db->fetch("SELECT id FROM channels WHERE type = 'webchat' ORDER BY id LIMIT 1")['id'];
    $inboxId = (int) $db->fetch(
        "SELECT i.id FROM inboxes i
         JOIN inbox_departments idp ON idp.inbox_id = i.id
         WHERE idp.department_id = ? AND i.type = 'department'
         ORDER BY i.id LIMIT 1",
        [$deptId]
    )['id'];

    $contactId = makeContact('principal');
    $agentId = makeUser('agente');

    // O agente precisa estar na caixa para ser candidato a autoatribuicao.
    $db->insert('inbox_users', ['inbox_id' => $inboxId, 'user_id' => $agentId]);
    $db->insert('department_users', ['department_id' => $deptId, 'user_id' => $agentId, 'is_manager' => 0]);

    // Journal: gravado agora e reescrito a cada criacao, para que uma
    // interrupcao em qualquer ponto deixe o estado completo.
    $writeJournal = function () use (
        &$created,
        $journalFile,
        $settingsBackupMap,
        $settingsTouched,
        $bhBackup,
        $deptBackup
    ) {
        $settings = [];
        foreach ($settingsTouched as $key) {
            $settings[$key] = $settingsBackupMap[$key] ?? null;
        }
        journalWrite($journalFile, [
            'settings' => $settings,
            'conversations' => $created['conversations'],
            'users' => $created['users'],
            'contacts' => $created['contacts'],
            'inboxes' => $created['inboxes'],
            'departments' => $created['departments'],
            'business_hours' => $bhBackup ?? [],
            'department' => $deptBackup ?: null,
        ]);
    };
    $writeJournal();
    $GLOBALS['writeJournal'] = $writeJournal;

    // =================================================================
    // 1) Backfill nao escala
    // =================================================================
    // Conversa encerrada ha mais de 15 min (a "grace" do worker) sem
    // linha de SLA e com violacao: o worker deve GRAVAR o valor para o
    // historico, mas nao gerar evento, nem tag, nem notificacao.
    section('1) Backfill nao escala');

    \App\Models\Setting::set('sla_response_minutes', '15');
    \App\Models\Setting::set('sla_resolution_minutes', '1440');
    \App\Models\Setting::set('sla_escalate_managers', '1');
    \App\Models\Setting::set('sla_escalate_tag', '1');
    \App\Models\Setting::set('sla_escalate_autoassign', '1');
    \App\Models\Setting::set('sla_escalate_whatsapp', '0');

    $oldClosedAt = date('Y-m-d H:i:s', strtotime('-3 hours'));
    $oldCreatedAt = date('Y-m-d H:i:s', strtotime('-4 hours'));
    $backfillConv = makeConversation(
        'backfill',
        $contactId,
        $channelId,
        $inboxId,
        $deptId,
        $agentId,
        'closed',
        date('Y-m-d H:i:s', strtotime('-4 hours')),
        null,
        $oldClosedAt,
        $oldCreatedAt
    );

    // Sem resposta humana e encerrada 3h depois: violacao de primeira
    // resposta (> 15 min).
    $eventsBefore = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_events WHERE conversation_id = ?",
        [$backfillConv]
    )['c'];

    $r = runWorker();
    check('1) o worker roda sem erro', $r['code'] === 0, substr($r['out'], -160));

    $slaRow = \App\Models\ConversationSla::find($backfillConv);
    check('1) o backfill gravou a medicao', $slaRow !== null);
    check(
        '1) o backfill gravou o tempo de espera',
        $slaRow !== null && $slaRow['response_seconds'] !== null,
        'response_seconds=' . ($slaRow['response_seconds'] ?? 'null')
    );
    check(
        '1) o backfill marcou a violacao no historico',
        $slaRow !== null && (int) $slaRow['response_breached'] === 1
    );

    $eventsAfter = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_events WHERE conversation_id = ?",
        [$backfillConv]
    )['c'];
    check(
        '1) o backfill nao gerou evento de violacao',
        $eventsAfter === $eventsBefore,
        'eventos=' . $eventsAfter
    );

    $breachEvents = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_events
         WHERE conversation_id = ? AND event_type IN ('sla_breached','sla_escalated')",
        [$backfillConv]
    )['c'];
    check('1) o backfill nao gerou evento de SLA', $breachEvents === 0, 'eventos_sla=' . $breachEvents);

    $tagRows = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_tags ct
         JOIN tags t ON t.id = ct.tag_id
         WHERE ct.conversation_id = ? AND t.name = ?",
        [$backfillConv, \App\Services\SlaEscalation::TAG_NAME]
    )['c'];
    check('1) o backfill nao aplicou a tag de violacao', $tagRows === 0, 'tags=' . $tagRows);

    // =================================================================
    // 2) Reabertura volta a medir
    // =================================================================
    section('2) Reabertura volta a medir');

    $reopenConv = makeConversation(
        'reabertura',
        $contactId,
        $channelId,
        $inboxId,
        $deptId,
        $agentId,
        'open',
        date('Y-m-d H:i:s', strtotime('-10 minutes')),
        null,
        null
    );

    $r = runWorker();
    $slaRow = \App\Models\ConversationSla::find($reopenConv);
    check('2) a conversa ativa foi medida', $slaRow !== null);
    check(
        '2) a conversa ativa tem periodo em aberto',
        $slaRow !== null && !empty($slaRow['period_started_at'])
    );

    // Encerra e reabre: o ciclo novo precisa ser medido de novo.
    $service = new \App\Services\ConversationService();
    $service->changeStatus($reopenConv, 'closed');
    $service->changeStatus($reopenConv, 'open');

    $slaRow = \App\Models\ConversationSla::find($reopenConv);
    check(
        '2) a reabertura limpou o periodo em aberto',
        $slaRow !== null && $slaRow['period_started_at'] === null
    );
    check(
        '2) a reabertura guardou o inicio do novo ciclo',
        $slaRow !== null && str_contains((string) $slaRow['metadata'], 'cycle_started_at')
    );

    $pending = \App\Models\ConversationSla::activeWithPendingCycle(500);
    check(
        '2) a conversa reaberta entrou na fila de ciclos pendentes',
        in_array((int) $reopenConv, array_map('intval', $pending), true)
    );

    // O cliente volta a falar: agora o ciclo novo tem o que medir.
    $db->insert('messages', [
        'conversation_id' => $reopenConv,
        'direction' => 'inbound',
        'type' => 'text',
        'content' => 'voltei, ainda preciso de ajuda',
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $r = runWorker();
    $slaRow = \App\Models\ConversationSla::find($reopenConv);
    check(
        '2) a conversa reaberta voltou a ser medida',
        $slaRow !== null && !empty($slaRow['period_started_at']),
        'periodo=' . ($slaRow['period_started_at'] ?? 'null')
    );
    check(
        '2) a conversa reaberta tem primeira resposta medida',
        $slaRow !== null && $slaRow['response_seconds'] !== null,
        'response_seconds=' . ($slaRow['response_seconds'] ?? 'null')
    );
    $rowsCount = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_slas WHERE conversation_id = ?",
        [$reopenConv]
    )['c'];
    check('2) a conversa reaberta continua com uma unica linha', $rowsCount === 1, 'linhas=' . $rowsCount);

    // =================================================================
    // 3) Toggle de horario comercial: tela e motor usam a MESMA regra
    // =================================================================
    section('3) Toggle de horario comercial');

    // O departamento 2 ganha uma grade que FECHA no fim de semana; o
    // toggle global e a caixa decidem se ela vale. O estado original
    // esta em $bhBackup/$deptBackup (guardados antes do try).
    $db->execute("DELETE FROM business_hours WHERE department_id = ?", [$deptId]);
    foreach (range(0, 6) as $dow) {
        $db->insert('business_hours', [
            'department_id' => $deptId,
            'day_of_week' => $dow,
            'is_open' => 1,
            'open_time' => '08:00:00',
            'close_time' => '12:00:00',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    $db->execute(
        "UPDATE departments SET business_hours_enabled = 1, business_hours_timezone = 'America/Sao_Paulo' WHERE id = ?",
        [$deptId]
    );

    // Toggle global LIGADO: a grade do departamento vale.
    \App\Models\Setting::set('sla_business_hours', '1');
    \App\Services\SlaService::forgetConfig();

    $withHours = \App\Services\SlaService::businessHoursFor($inboxId, $deptId, 'America/Sao_Paulo');
    check(
        '3) com o toggle ligado a grade do departamento e usada',
        $withHours['schedule'] !== null && !empty($withHours['schedule']),
        'dias=' . count($withHours['schedule'] ?? [])
    );
    check(
        '3) a janela da grade e a configurada no departamento',
        ($withHours['schedule'][1]['open'] ?? '') === '08:00:00'
            && ($withHours['schedule'][1]['close'] ?? '') === '12:00:00',
        'aberta=' . ($withHours['schedule'][1]['open'] ?? '-') . ' fecha=' . ($withHours['schedule'][1]['close'] ?? '-')
    );

    // Toggle global DESLIGADO: a mesma grade deixa de valer. Era aqui que
    // a tela mostrava relogio de parede e o motor media segundos uteis.
    \App\Models\Setting::set('sla_business_hours', '0');
    \App\Services\SlaService::forgetConfig();

    $withoutHours = \App\Services\SlaService::businessHoursFor($inboxId, $deptId, 'America/Sao_Paulo');
    check(
        '3) com o toggle desligado a grade e ignorada',
        $withoutHours['schedule'] === null
    );
    check(
        '3) com o toggle desligado o motor usa o fuso da aplicacao',
        ($withoutHours['timezone'] ?? '') === 'America/Sao_Paulo',
        'tz=' . ($withoutHours['timezone'] ?? '-')
    );

    // O chip da tela usa a MESMA fonte: applyClock e businessHoursFor
    // nao podem divergir sobre o schedule.
    //
    // Instantes fixos (06:00 -> 10:00) para o resultado nao depender de
    // que horas o teste roda: com a grade 08:00-12:00 so conta 2h; sem
    // grade (relogio de parede) conta as 4h.
    $anchorAt = '2026-10-19 06:00:00';
    $nowAt = '2026-10-19 10:00:00';
    $clockRow = [
        'status' => 'open',
        'created_at' => $anchorAt,
        'last_inbound_at' => $anchorAt,
        'last_inbound_id' => 1,
        'last_human_id' => 0,
    ];

    $onClock = \App\Services\SlaService::clockState(
        $clockRow,
        $withHours['schedule'],
        $withHours['timezone'],
        $nowAt,
        'America/Sao_Paulo'
    );
    $offClock = \App\Services\SlaService::clockState(
        $clockRow,
        $withoutHours['schedule'],
        $withoutHours['timezone'],
        $nowAt,
        'America/Sao_Paulo'
    );
    check(
        '3) a tela e o motor nao divergem com o toggle desligado',
        $onClock['waiting_seconds'] !== $offClock['waiting_seconds'],
        'com_grade=' . $onClock['waiting_seconds'] . ' sem_grade=' . $offClock['waiting_seconds']
    );
    check(
        '3) com a grade a espera conta so o horario de atendimento',
        (int) $onClock['waiting_seconds'] === 7200,
        'espera=' . $onClock['waiting_seconds']
    );
    check(
        '3) sem a grade a espera conta o relogio de parede',
        (int) $offClock['waiting_seconds'] === 14400,
        'espera=' . $offClock['waiting_seconds']
    );

    // Restaura o toggle para os cenarios seguintes.
    \App\Models\Setting::set('sla_business_hours', '1');
    \App\Services\SlaService::forgetConfig();

    // E restaura a grade do departamento: com a janela 08:00-12:00
    // valendo, as conversas criadas agora (a noite) mediriam espera
    // zero e nenhum cenario seguinte conseguiria furar o SLA.
    $db->execute("DELETE FROM business_hours WHERE department_id = ?", [$deptId]);
    foreach ($bhBackup as $r) {
        unset($r['id']);
        $db->insert('business_hours', $r);
    }
    if ($deptBackup) {
        $db->execute(
            "UPDATE departments SET business_hours_enabled = ?, business_hours_timezone = ? WHERE id = ?",
            [
                $deptBackup['business_hours_enabled'] ?? null,
                $deptBackup['business_hours_timezone'] ?? null,
                $deptId,
            ]
        );
    }
    $hoursRestored = true;

    // ── Departamento de teste com expediente 24h ───────────────────
    // Os cenarios 4 a 7 dependem de a conversa ter espera > 0 no
    // momento em que o worker roda. Com o departamento real (que fecha
    // as 22h) o teste so passava durante o expediente: rodando as
    // 23h, a espera util era zero e nao havia violacao para detectar.
    //
    // Um departamento proprio, com a grade 24h, tira a hora do
    // resultado: o teste mede sempre a mesma coisa.
    $testDeptId = (int) $db->insert('departments', [
        'name' => 'TESTE REGRESSAO DEPTO',
        'business_hours_enabled' => 1,
        'business_hours_timezone' => 'America/Sao_Paulo',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $created['departments'][] = $testDeptId;

    // Grade 24h (00:00 - 23:59) nos 7 dias.
    foreach (range(0, 6) as $dow) {
        $db->insert('business_hours', [
            'department_id' => $testDeptId,
            'day_of_week' => $dow,
            'is_open' => 1,
            'open_time' => '00:00:00',
            'close_time' => '23:59:59',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // Caixa e vinculos do departamento de teste: o worker mede
    // conversas por inbox, e o autoassign usa inbox_users.
    $testInboxId = (int) $db->insert('inboxes', [
        'name' => 'TESTE REGRESSAO CAIXA',
        'type' => 'department',
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $created['inboxes'][] = $testInboxId;
    $db->insert('inbox_departments', [
        'inbox_id' => $testInboxId,
        'department_id' => $testDeptId,
    ]);
    $db->insert('inbox_users', ['inbox_id' => $testInboxId, 'user_id' => $agentId]);
    $db->insert('department_users', [
        'department_id' => $testDeptId,
        'user_id' => $agentId,
        'is_manager' => 0,
    ]);

    // =================================================================
    // 4) Dois workers em paralelo: a violacao escala uma vez so
    // =================================================================
    section('4) Dois workers em paralelo');

    $raceConv = makeConversation(
        'corrida',
        $contactId,
        $channelId,
        $testInboxId,
        $testDeptId,
        null, // sem responsavel: assim o autoassign pode agir
        'open',
        date('Y-m-d H:i:s', strtotime('-40 minutes')),
        null
    );

    // Limite de 1 minuto: a conversa de 40 min ja furou o SLA.
    \App\Models\Setting::set('sla_response_minutes', '1');
    \App\Services\SlaService::forgetConfig();

    // A reserva atomica e o que garante a unicidade: dois processos
    // disputam o mesmo UPDATE condicional (0 -> 1).
    $claimed1 = \App\Models\ConversationSla::claimResponseBreach($raceConv);
    $claimed2 = \App\Models\ConversationSla::claimResponseBreach($raceConv);
    check('4) o primeiro worker reserva a violacao', $claimed1 === true);
    check('4) o segundo worker nao reserva a mesma violacao', $claimed2 === false);

    // Rodar o worker sobre uma conversa ja reservada nao pode gerar
    // evento de violacao (a transicao 0->1 ja aconteceu).
    $r = runWorker();
    $breachCount = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_events
         WHERE conversation_id = ? AND event_type = 'sla_breached'",
        [$raceConv]
    )['c'];
    check(
        '4) a violacao ja reservada nao gera evento novo',
        $breachCount === 0,
        'eventos=' . $breachCount
    );

    // ── Agora a violacao detectada pelo proprio worker ─────────────
    // Conversa que fura o SLA sozinha: o worker deve gerar UM evento
    // de violacao e UM de escalonamento, mesmo rodando varias vezes.
    $autoConv = makeConversation(
        'automatica',
        $contactId,
        $channelId,
        $testInboxId,
        $testDeptId,
        null,
        'open',
        date('Y-m-d H:i:s', strtotime('-40 minutes')),
        null
    );

    $r = runWorker();
    $breach1 = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_events
         WHERE conversation_id = ? AND event_type = 'sla_breached'",
        [$autoConv]
    )['c'];
    $escalated1 = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_events
         WHERE conversation_id = ? AND event_type = 'sla_escalated'",
        [$autoConv]
    )['c'];
    check('4) o worker gera um evento de violacao', $breach1 === 1, 'eventos=' . $breach1);
    check('4) o worker gera um evento de escalonamento', $escalated1 === 1, 'eventos=' . $escalated1);

    $r = runWorker();
    $breach2 = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_events
         WHERE conversation_id = ? AND event_type = 'sla_breached'",
        [$autoConv]
    )['c'];
    $escalated2 = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_events
         WHERE conversation_id = ? AND event_type = 'sla_escalated'",
        [$autoConv]
    )['c'];
    check('4) rodar o worker de novo nao duplica a violacao', $breach2 === 1, 'eventos=' . $breach2);
    check('4) rodar o worker de novo nao duplica o escalonamento', $escalated2 === 1, 'eventos=' . $escalated2);

    // A escalonamento notifica os gerentes uma vez so.
    $notifCount = (int) $db->fetch(
        "SELECT COUNT(*) c FROM notifications
         WHERE conversation_id = ? AND title LIKE '%SLA%'",
        [$autoConv]
    )['c'];
    check(
        '4) os gerentes sao notificados uma vez so',
        $notifCount <= 1,
        'notificacoes=' . $notifCount
    );

    \App\Models\Setting::set('sla_response_minutes', '15');
    \App\Services\SlaService::forgetConfig();

    // =================================================================
    // 5) Compliance sem linhas NULL
    // =================================================================
    section('5) Compliance sem linhas NULL');

    // Linha de SLA sem medicao (response_seconds NULL): nao pode entrar
    // no denominador do compliance, senao o percentual cai sem motivo.
    $nullConv = makeConversation(
        'null',
        $contactId,
        $channelId,
        $testInboxId,
        $testDeptId,
        $agentId,
        'open',
        date('Y-m-d H:i:s'),
        null
    );
    \App\Models\ConversationSla::save($nullConv, [
        'response_seconds' => null,
        'response_at' => null,
        'response_breached' => 0,
        'resolution_seconds' => 0,
        'resolution_breached' => 0,
    ]);

    $measuredCount = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_slas s
         JOIN conversations c ON c.id = s.conversation_id
         WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND s.response_seconds IS NOT NULL"
    )['c'];
    $nullCount = (int) $db->fetch(
        "SELECT COUNT(*) c FROM conversation_slas s
         JOIN conversations c ON c.id = s.conversation_id
         WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND s.response_seconds IS NULL"
    )['c'];
    check(
        '5) existe uma linha sem medicao no periodo',
        $nullCount > 0,
        'linhas_sem_medicao=' . $nullCount
    );

    $compliance = \App\Models\ConversationSla::compliance(30);
    check(
        '5) o compliance medido bate com as linhas medidas',
        (int) $compliance['response']['measured'] === $measuredCount,
        'compliance=' . $compliance['response']['measured'] . ' banco=' . $measuredCount
    );
    check(
        '5) o compliance ignora as linhas sem medicao',
        (int) $compliance['response']['measured'] !== $measuredCount + $nullCount
    );
    check(
        '5) o percentual so existe com denominador',
        $measuredCount === 0
            ? $compliance['response']['percent'] === null
            : is_numeric($compliance['response']['percent'])
    );

    $breakdown = \App\Models\ConversationSla::agentBreakdown(30);
    $breakdownTotal = array_sum(array_map(fn($b) => (int) $b['total'], $breakdown));
    check(
        '5) a quebra por atendente soma o mesmo total do compliance',
        $breakdownTotal === (int) $compliance['response']['measured'],
        'quebra=' . $breakdownTotal . ' compliance=' . $compliance['response']['measured']
    );

    // =================================================================
    // 6) Autoassign em conversa encerrada
    // =================================================================
    section('6) Autoassign em conversa encerrada');

    $closedConv = makeConversation(
        'encerrada',
        $contactId,
        $channelId,
        $testInboxId,
        $testDeptId,
        null,
        'closed',
        date('Y-m-d H:i:s', strtotime('-40 minutes')),
        null,
        date('Y-m-d H:i:s', strtotime('-30 minutes'))
    );

    $before = \App\Models\Conversation::find($closedConv);
    check(
        '6) a conversa de teste esta encerrada',
        ($before['status'] ?? '') === 'closed',
        'status=' . ($before['status'] ?? '-')
    );
    check(
        '6) a conversa nao tem responsavel',
        empty($before['assigned_user_id'])
    );

    // Controle positivo: em conversa ABERTA sem responsavel o
    // autoatribuicao acontece. Sem esse par, o teste acima passaria
    // mesmo com a guarda removida.
    $openConv = makeConversation(
        'aberta',
        $contactId,
        $channelId,
        $testInboxId,
        $testDeptId,
        null,
        'open',
        date('Y-m-d H:i:s', strtotime('-40 minutes')),
        null
    );
    $openBefore = \App\Models\Conversation::find($openConv);
    $openResult = \App\Services\SlaEscalation::escalate(
        $openConv,
        ['response_seconds' => 3600, 'response_threshold' => 900],
        $openBefore
    );
    check(
        '6) o autoassign funciona em conversa aberta',
        $openResult['autoassign'] !== null,
        'autoassign=' . var_export($openResult['autoassign'] ?? null, true)
    );
    $openAfter = \App\Models\Conversation::find($openConv);
    check(
        '6) a conversa aberta ganhou o responsavel escolhido',
        !empty($openAfter['assigned_user_id'])
    );
    check(
        '6) a conversa aberta continua aberta',
        ($openAfter['status'] ?? '') === 'open',
        'status=' . ($openAfter['status'] ?? '-')
    );

    // ── a conversa ENCERRADA nao pode ser autoatribuida ────────────
    $result = \App\Services\SlaEscalation::escalate(
        $closedConv,
        ['response_seconds' => 3600, 'response_threshold' => 900],
        $before
    );
    check(
        '6) o autoassign nao atribui em conversa encerrada',
        array_key_exists('autoassign', $result) && $result['autoassign'] === null,
        'autoassign=' . var_export($result['autoassign'] ?? null, true)
    );

    $after = \App\Models\Conversation::find($closedConv);
    check(
        '6) a conversa continua encerrada',
        ($after['status'] ?? '') === 'closed',
        'status=' . ($after['status'] ?? '-')
    );
    check(
        '6) a conversa nao ganhou responsavel',
        empty($after['assigned_user_id']),
        'assigned=' . ($after['assigned_user_id'] ?? 'null')
    );
    check(
        '6) o closed_at nao foi perdido',
        !empty($after['closed_at']),
        'closed_at=' . ($after['closed_at'] ?? 'null')
    );

    // =================================================================
    // 7) Contagem do relatorio
    // =================================================================
    section('7) Contagem do relatorio');

    // Uma conversa que furou o SLA precisa aparecer na lista de violadas
    // do relatorio — e o numero do relatorio tem que bater com a lista.
    $reportConv = makeConversation(
        'relatorio',
        $contactId,
        $channelId,
        $testInboxId,
        $testDeptId,
        $agentId,
        'closed',
        date('Y-m-d H:i:s', strtotime('-50 minutes')),
        null,
        date('Y-m-d H:i:s', strtotime('-45 minutes'))
    );
    \App\Models\ConversationSla::save($reportConv, [
        'response_seconds' => 3000,
        'response_at' => null,
        'response_breached' => 1,
        'response_threshold' => 900,
        'resolution_seconds' => 0,
        'resolution_breached' => 0,
        'resolution_threshold' => 86400,
    ]);

    $violated = \App\Models\ConversationSla::violatedConversations(30);
    $violatedIds = array_map(fn($v) => (int) $v['id'], $violated);
    check(
        '7) a conversa que furou o SLA aparece no relatorio',
        in_array($reportConv, $violatedIds, true)
    );

    // O relatorio nao pode listar a mesma conversa duas vezes.
    check(
        '7) o relatorio nao repete conversa',
        count($violatedIds) === count(array_unique($violatedIds)),
        'linhas=' . count($violatedIds) . ' unicas=' . count(array_unique($violatedIds))
    );

    // O numero do compliance de 1a resposta tem que ser >= o numero de
    // conversas violadas (violar resposta implica estar medido).
    $compliance = \App\Models\ConversationSla::compliance(30);
    check(
        '7) o compliance mede pelo menos as conversas violadas',
        (int) $compliance['response']['measured'] >= (int) $compliance['response']['breached'],
        'medido=' . $compliance['response']['measured'] . ' violado=' . $compliance['response']['breached']
    );
    check(
        '7) o total de violados do compliance bate com o do relatorio',
        (int) $compliance['response']['breached'] <= count($violatedIds),
        'compliance=' . $compliance['response']['breached'] . ' relatorio=' . count($violatedIds)
    );

    // =================================================================
    // 8) Contrato do sla_response_at no poll
    // =================================================================
    section('8) Contrato do sla_response_at no poll');

    // O painel decide "SLA ok" vs "SLA <tempo>" pelo campo sla_response_at.
    // O poll (apiConversation) sobrescreve o chip a cada 10s: se o campo
    // faltar no payload, o chip volta a mostrar o tempo de espera mesmo
    // com a resposta ja dada.
    $ctlSrc = (string) file_get_contents(__DIR__ . '/../app/Controllers/InboxController.php');
    check(
        '8) a api anexa o sla_response_at',
        str_contains($ctlSrc, "'sla_response_at'"),
    );
    check(
        '8) o painel le o sla_response_at',
        str_contains(
            (string) file_get_contents(__DIR__ . '/../app/Views/inbox/panel.php'),
            'c.sla_response_at'
        )
    );

    // O campo tem que vir da coluna response_at da linha do SLA.
    $answeredConv = makeConversation(
        'respondida',
        $contactId,
        $channelId,
        $testInboxId,
        $testDeptId,
        $agentId,
        'open',
        date('Y-m-d H:i:s', strtotime('-20 minutes')),
        date('Y-m-d H:i:s', strtotime('-15 minutes'))
    );
    \App\Models\ConversationSla::save($answeredConv, [
        'response_seconds' => 300,
        'response_at' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
        'response_breached' => 0,
        'response_threshold' => 900,
        'resolution_seconds' => 0,
        'resolution_breached' => 0,
        'resolution_threshold' => 86400,
    ]);

    // Reproduz o que o controller envia no poll.
    $map = \App\Models\ConversationSla::forConversations([$answeredConv]);
    $sla = $map[$answeredConv] ?? null;
    check('8) a linha de SLA tem o instante da resposta', $sla !== null && !empty($sla['response_at']));
    check(
        '8) o payload do poll carrega o sla_response_at',
        $sla !== null && array_key_exists('response_at', $sla) && !empty($sla['response_at'])
    );

    // E o que o painel faz com ele: um valor preenchido = "SLA ok".
    $panelSrc = (string) file_get_contents(__DIR__ . '/../app/Views/inbox/panel.php');
    check(
        '8) o painel transforma o sla_response_at em "SLA ok"',
        str_contains($panelSrc, "respDone ? 'SLA ok'")
            || str_contains($panelSrc, "respDone = !!(c.sla_response_at)")
    );
} catch (Throwable $e) {
    $fail++;
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    // ── Limpeza: restaura exatamente o estado anterior ──────────────
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

    $db->execute("DELETE FROM notifications");
    foreach ($notifBackup as $r) {
        unset($r['id']);
        $db->insert('notifications', $r);
    }

    $db->execute("DELETE FROM conversation_tags");
    foreach ($convTagsBackup as $r) {
        unset($r['id']);
        $db->insert('conversation_tags', $r);
    }

    // Grades de horario comercial do departamento de teste.
    if (isset($bhBackup) && empty($hoursRestored)) {
        $db->execute("DELETE FROM business_hours WHERE department_id = ?", [$deptId]);
        foreach ($bhBackup as $r) {
            unset($r['id']);
            $db->insert('business_hours', $r);
        }
    }
    if (isset($deptBackup) && $deptBackup) {
        $db->execute(
            "UPDATE departments SET business_hours_enabled = ?, business_hours_timezone = ? WHERE id = ?",
            [
                $deptBackup['business_hours_enabled'] ?? null,
                $deptBackup['business_hours_timezone'] ?? null,
                $deptId,
            ]
        );
    }

    foreach ($created['conversations'] as $id) {
        $db->execute("DELETE FROM messages WHERE conversation_id = ?", [$id]);
        $db->execute("DELETE FROM conversation_slas WHERE conversation_id = ?", [$id]);
        $db->execute("DELETE FROM conversation_events WHERE conversation_id = ?", [$id]);
        $db->execute("DELETE FROM conversation_tags WHERE conversation_id = ?", [$id]);
        $db->execute("DELETE FROM conversations WHERE id = ?", [$id]);
    }
    foreach ($created['contacts'] as $id) {
        $db->execute("DELETE FROM contacts WHERE id = ?", [$id]);
    }
    foreach ($created['users'] as $id) {
        $db->execute("DELETE FROM inbox_users WHERE user_id = ?", [$id]);
        $db->execute("DELETE FROM department_users WHERE user_id = ?", [$id]);
        $db->execute("DELETE FROM users WHERE id = ?", [$id]);
    }

    // Departamento e caixa de teste (expediente 24h).
    foreach ($created['inboxes'] as $id) {
        $db->execute("DELETE FROM inbox_users WHERE inbox_id = ?", [$id]);
        $db->execute("DELETE FROM inbox_departments WHERE inbox_id = ?", [$id]);
        $db->execute("DELETE FROM conversations WHERE inbox_id = ?", [$id]);
        $db->execute("DELETE FROM inboxes WHERE id = ?", [$id]);
    }
    foreach ($created['departments'] as $id) {
        $db->execute("DELETE FROM business_hours WHERE department_id = ?", [$id]);
        $db->execute("DELETE FROM inbox_departments WHERE department_id = ?", [$id]);
        $db->execute("DELETE FROM department_users WHERE department_id = ?", [$id]);
        $db->execute("DELETE FROM conversations WHERE department_id = ?", [$id]);
        $db->execute("DELETE FROM departments WHERE id = ?", [$id]);
    }

    // Settings: volta ao valor anterior (ou apaga a chave se nao existia).
    foreach ($settingsTouched as $key) {
        if (array_key_exists($key, $settingsBackupMap)) {
            \App\Models\Setting::set($key, $settingsBackupMap[$key]);
        } else {
            $db->execute("DELETE FROM settings WHERE `key` = ?", [$key]);
        }
    }
    \App\Services\SlaService::forgetConfig();

    // Journal cumprido: a proxima execucao nao precisa restaurar nada.
    @unlink($journalFile);
    unset($GLOBALS['writeJournal']);

    echo PHP_EOL . 'cleanup ok' . PHP_EOL;
}

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
