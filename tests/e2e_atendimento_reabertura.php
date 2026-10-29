<?php
/**
 * E2E da regra de reabertura de atendimento (2026-10-29).
 *
 * Cobre o que a spec 2026-10-29-atendimento-reabertura-design.md definiu:
 *  - modo unique: a mensagem nova abre um atendimento novo;
 *  - modo always: a mensagem nova reabre o atendimento anterior;
 *  - modo window: reabre dentro da janela, cria novo fora dela;
 *  - spam, grupo e outro canal nunca são reabertos;
 *  - a caixa sobrepõe o padrão global; sem override, herda o global.
 *
 * Roda junto do runner principal (tests/run.php) e também standalone:
 *   php tests/e2e_atendimento_reabertura.php
 *
 * Cria dados temporários, verifica e limpa tudo no fim.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';
\App\Config\Environment::load();

// Mesmo fuso do app: o banco guarda os horários em America/Sao_Paulo e o
// relógio do SLA interpreta os timestamps nesse fuso. Sem esta linha o
// fixture sai 3h fora e a janela de reabertura lê errado.
$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

use App\Core\Database;
use App\Models\AtendimentoPolicy;
use App\Models\Conversation;
use App\Models\ConversationSla;
use App\Services\WhatsAppService;

// Modo do arquivo, decidido ANTES de definir o check(): em standalone o
// próprio arquivo define o helper, e um `function_exists('check')` testado
// no fim do arquivo já estaria true — o resumo e o exit() nunca rodariam
// e o script sairia com 0 mesmo com todos os checks em FALHOU.
$standalone = !function_exists('check');

if ($standalone) {
    $GLOBALS['failures'] = 0;
    $GLOBALS['passes'] = 0;
    function check(string $name, bool $cond, string $detail = ''): void
    {
        $suffix = $detail !== '' ? " ($detail)" : '';
        if ($cond) {
            $GLOBALS['passes']++;
            echo "ok - $name$suffix\n";
        } else {
            $GLOBALS['failures']++;
            echo "FALHOU - $name$suffix\n";
        }
    }
}

$db = Database::getInstance();

if (!AtendimentoPolicy::hasColumns()) {
    check('reabertura: as colunas da regra existem na base', false, 'roda a migration antes do e2e');
    if ($standalone) {
        exit(1);
    }
    return;
}

$inboxId = null;
$channelId = null;
$otherChannelId = null;
$createdConvIds = [];
$createdContactIds = [];

// Backup do estado global: o e2e mexe no padrão do sistema e precisa
// devolver tudo como estava.
$globalBackup = [
    'mode' => \App\Models\Setting::get(AtendimentoPolicy::SETTING_MODE),
    'window' => \App\Models\Setting::get(AtendimentoPolicy::SETTING_WINDOW),
];

try {
    // ── Fixture ──────────────────────────────────────────────────
    $channel = $db->fetch("SELECT id FROM channels WHERE type = 'whatsapp' ORDER BY id LIMIT 1");
    if (!$channel) {
        $channel = $db->fetch("SELECT id FROM channels ORDER BY id LIMIT 1");
    }
    $channelId = (int) $channel['id'];

    $other = $db->fetch("SELECT id FROM channels WHERE id <> ? ORDER BY id LIMIT 1", [$channelId]);
    $otherChannelId = $other ? (int) $other['id'] : null;

    $inboxId = (int) $db->insert('inboxes', [
        'name' => 'TESTE REABERTURA',
        'type' => 'department',
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    /**
     * Cada cenário usa um contato novo: a conversa ABERTA de um cenário
     * seria reutilizada pelo próximo (findOrCreateConversation devolve
     * a conversa aberta antes de olhar a política) e o teste passaria
     * a exercitar o caminho errado.
     *
     * A conversa anterior SEMPRE leva a caixa do teste (inbox_id): é a
     * caixa do atendimento anterior que define a regra vigente — sem
     * inbox, a política cairia no padrão global e o override da caixa
     * nunca seria exercitado.
     *
     * @return array{0:int, 1:int} [conversationId, contactId]
     */
    $makePrevious = function (string $status, ?string $lastMessageAt, ?int $channel = null) use ($db, $channelId, $inboxId, &$createdConvIds, &$createdContactIds) {
        $contactId = (int) $db->insert('contacts', [
            'name' => 'TESTE REABERTURA',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $createdContactIds[] = $contactId;

        $id = (int) $db->insert('conversations', [
            'public_id' => bin2hex(random_bytes(16)),
            'contact_id' => $contactId,
            'channel_id' => $channel ?? $channelId,
            'inbox_id' => $inboxId,
            'department_id' => null,
            'assigned_user_id' => null,
            'subject' => 'atendimento anterior',
            'status' => $status,
            'source' => 'whatsapp',
            'last_message_at' => $lastMessageAt,
            'closed_at' => $lastMessageAt,
            'created_at' => $lastMessageAt ?? date('Y-m-d H:i:s'),
        ]);
        $createdConvIds[] = $id;

        return [$id, $contactId];
    };

    $service = new WhatsAppService();

    // ── 1) modo unique: atendimento novo ────────────────────────
    \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'unique');
    \App\Models\Setting::set(AtendimentoPolicy::SETTING_WINDOW, '120');
    $db->update('inboxes', ['atendimento_modo' => null, 'atendimento_janela_minutos' => null], 'id = ?', [$inboxId]);

    [$prev1, $contact1] = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contact1);
    check('reabertura: unique abre um atendimento novo', (int) $result['id'] !== $prev1, 'id=' . $result['id']);
    $createdConvIds[] = (int) $result['id'];
    $stillClosed = Conversation::find($prev1);
    check('reabertura: unique deixa o anterior encerrado', ($stillClosed['status'] ?? '') === 'closed');

    // ── 2) modo always: reabre o anterior ────────────────────────
    $db->update('inboxes', ['atendimento_modo' => 'always'], 'id = ?', [$inboxId]);
    [$prev2, $contact2] = $makePrevious('resolved', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contact2);
    check('reabertura: always reabre o atendimento anterior', (int) $result['id'] === $prev2, 'id=' . $result['id']);
    $reopened = Conversation::find($prev2);
    check('reabertura: a conversa reaberta volta para a fila', ($reopened['status'] ?? '') === 'new', 'status=' . ($reopened['status'] ?? ''));
    check('reabertura: a conversa reaberta limpa o fechamento', empty($reopened['closed_at']));
    $event = $db->fetch(
        "SELECT COUNT(*) AS c FROM conversation_events WHERE conversation_id = ? AND event_type = 'reopened'",
        [$prev2]
    );
    check('reabertura: a reabertura deixa rastro no historico', (int) $event['c'] === 1, 'eventos=' . $event['c']);
    $sla = ConversationSla::find($prev2);
    $metadata = json_decode((string) ($sla['metadata'] ?? '{}'), true);
    check(
        'reabertura: a reabertura reinicia o ciclo do sla',
        $sla !== null && !empty($metadata['cycle_started_at']),
        'metadata=' . json_encode($metadata)
    );

    // ── 3) modo window: dentro da janela reabre ──────────────────
    $db->update('inboxes', ['atendimento_modo' => 'window', 'atendimento_janela_minutos' => 120], 'id = ?', [$inboxId]);
    [$prev3, $contact3] = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contact3);
    check('reabertura: window reabre dentro da janela', (int) $result['id'] === $prev3, 'id=' . $result['id']);

    // ── 4) modo window: fora da janela abre novo ─────────────────
    [$prev4, $contact4] = $makePrevious('closed', date('Y-m-d H:i:s', time() - 3 * 3600));
    $result = $service->findOrCreateConversation($channelId, $contact4);
    check('reabertura: window abre novo fora da janela', (int) $result['id'] !== $prev4, 'id=' . $result['id']);
    $createdConvIds[] = (int) $result['id'];

    // ── 5) spam nunca é reaberto ─────────────────────────────────
    [$prev5, $contact5] = $makePrevious('spam', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contact5);
    check('reabertura: spam nunca e reaberto', (int) $result['id'] !== $prev5, 'id=' . $result['id']);
    $createdConvIds[] = (int) $result['id'];

    // ── 6) a caixa sobrepoe o padrao global ──────────────────────
    \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'unique');
    $db->update('inboxes', ['atendimento_modo' => 'always'], 'id = ?', [$inboxId]);
    [$prev6, $contact6] = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contact6);
    check('reabertura: a caixa sobrepoe o padrao global', (int) $result['id'] === $prev6, 'id=' . $result['id']);

    // ── 7) sem override, herda o padrao global ───────────────────
    $db->update('inboxes', ['atendimento_modo' => null, 'atendimento_janela_minutos' => null], 'id = ?', [$inboxId]);
    \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'always');
    [$prev7, $contact7] = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contact7);
    check('reabertura: sem override herda o padrao global', (int) $result['id'] === $prev7, 'id=' . $result['id']);

    // ── 8) conversa de outro canal nao e candidata ───────────────
    if ($otherChannelId) {
        // Global em 'unique': a mensagem no canal A nao pode tocar no
        // atendimento do canal B (que fica no canal B).
        \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'unique');
        [$prev8, $contact8] = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60), $otherChannelId);
        $result = $service->findOrCreateConversation($channelId, $contact8);
        check('reabertura: atendimento de outro canal nao e reaberto', (int) $result['id'] !== $prev8, 'id=' . $result['id']);
        $untouched = Conversation::find($prev8);
        check('reabertura: o atendimento do outro canal continua encerrado', ($untouched['status'] ?? '') === 'closed');
        $createdConvIds[] = (int) $result['id'];
    }

    // ── 9) grupo nunca e candidato ───────────────────────────────
    // conversations.group_id tem FK para whatsapp_groups: usa um grupo
    // que ja existe (a linha do grupo NAO e mexida).
    $groupRow = $db->fetch("SELECT id FROM whatsapp_groups ORDER BY id LIMIT 1");
    if ($groupRow) {
        // Global em 'always': se a conversa de grupo fosse candidata,
        // ela seria reaberta. Com o filtro group_id IS NULL ela nao e.
        \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'always');
        $groupContact = (int) $db->insert('contacts', [
            'name' => 'TESTE REABERTURA GRUPO',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $createdContactIds[] = $groupContact;

        $groupConv = (int) $db->insert('conversations', [
            'public_id' => bin2hex(random_bytes(16)),
            'contact_id' => $groupContact,
            'channel_id' => $channelId,
            'inbox_id' => $inboxId,
            'group_id' => (int) $groupRow['id'],
            'status' => 'closed',
            'source' => 'whatsapp',
            'last_message_at' => date('Y-m-d H:i:s', time() - 60),
            'closed_at' => date('Y-m-d H:i:s', time() - 60),
            'created_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        $createdConvIds[] = $groupConv;
        $candidate = AtendimentoPolicy::previousConversation($channelId, $groupContact);
        check(
            'reabertura: conversa de grupo nao e candidata',
            $candidate === null || (int) $candidate['id'] !== $groupConv,
            'candidato=' . ($candidate['id'] ?? 'null')
        );
    }
} finally {
    // ── Limpeza ──────────────────────────────────────────────────
    foreach (array_unique($createdConvIds) as $cid) {
        $db->delete('conversation_events', 'conversation_id = ?', [$cid]);
        $db->delete('messages', 'conversation_id = ?', [$cid]);
        $db->delete('conversation_slas', 'conversation_id = ?', [$cid]);
        $db->delete('conversation_tags', 'conversation_id = ?', [$cid]);
        $db->delete('conversations', 'id = ?', [$cid]);
    }
    foreach (array_unique($createdContactIds) as $cid) {
        $db->delete('contacts', 'id = ?', [$cid]);
    }
    if ($inboxId) {
        $db->delete('inboxes', 'id = ?', [$inboxId]);
    }
    if ($globalBackup['mode'] === null) {
        $db->execute("DELETE FROM settings WHERE `key` = ?", [AtendimentoPolicy::SETTING_MODE]);
    } else {
        \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, (string) $globalBackup['mode']);
    }
    if ($globalBackup['window'] === null) {
        $db->execute("DELETE FROM settings WHERE `key` = ?", [AtendimentoPolicy::SETTING_WINDOW]);
    } else {
        \App\Models\Setting::set(AtendimentoPolicy::SETTING_WINDOW, (string) $globalBackup['window']);
    }
}

if ($standalone) {
    echo "\n{$GLOBALS['passes']} passed, {$GLOBALS['failures']} failed\n";
    exit($GLOBALS['failures'] > 0 ? 1 : 0);
}
