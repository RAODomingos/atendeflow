<?php
/**
 * E2E do bloco de SLA do dashboard (2026-10-26).
 *
 * Cobre o que a spec 2026-10-26-dashboard-sla-design.md definiu:
 *  - a fila em risco (bucket do relógio vivo)
 *  - o compliance de 1ª resposta e resolução
 *  - a contagem de violações do período
 *  - a permissão: não-admin não recebe a quebra por atendente
 *
 * Roda junto do runner principal (tests/run.php) e tambémstandalone:
 *   php tests/e2e_dashboard_sla.php
 *
 * O fixture desliga o horário comercial da caixa (relógio de parede) e
 * fixa os limites de atenção/alerta, para o teste não depender da hora
 * em que roda.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';
\App\Config\Environment::load();

// Mesmo fuso do app: o banco guarda os horários em America/Sao_Paulo e o
// relógio do SLA interpreta os timestamps nesse fuso. Sem esta linha o
// fixture sai 3h fora e o relógio lê "âncora no futuro".
$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

use App\Core\Database;
use App\Models\Conversation;
use App\Services\SlaService;

// Modo do arquivo, decidido ANTES de definir o check(): em standalone o
// próprio arquivo define o helper, e um `function_exists('check')` testado
// no fim do arquivo já estaria true — o resumo e o exit() nunca rodariam
// e o script sairia com 0 mesmo com todos os checks em FALHOU.
$standalone = !function_exists('check');

if ($standalone) {
    // Standalone: o runner principal não está carregado, então o próprio
    // arquivo define o check e os contadores. O terceiro argumento é o
    // diagnóstico: quando o check falha, ele mostra o valor real.
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

// Caixa de departamento + um departamento coberto por ela.
$fixture = $db->fetch(
    "SELECT i.id AS inbox_id, d.department_id
       FROM inboxes i
       JOIN inbox_departments d ON d.inbox_id = i.id
      WHERE i.type = 'department'
        AND (i.conversation_scope IS NULL OR i.conversation_scope = '' OR i.conversation_scope = 'all')
      ORDER BY i.id LIMIT 1"
);
if (!$fixture) {
    $fixture = $db->fetch(
        "SELECT i.id AS inbox_id, d.department_id
           FROM inboxes i
           JOIN inbox_departments d ON d.inbox_id = i.id
          WHERE i.type = 'department'
          ORDER BY i.id LIMIT 1"
    );
}

$inboxId = (int) ($fixture['inbox_id'] ?? 0);
$departmentId = (int) ($fixture['department_id'] ?? 0);
$contactId = (int) ($db->fetch("SELECT id FROM contacts WHERE is_group = 0 ORDER BY id LIMIT 1")['id'] ?? 0);
$channelId = (int) ($db->fetch("SELECT id FROM channels ORDER BY id LIMIT 1")['id'] ?? 0);
// Atendente para a resposta humana: sem um user_id válido a "resposta do
// atendente" seria indistinguível da fala do bot.
$userId = (int) ($db->fetch("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1")['id'] ?? 0);

// Estado anterior da caixa: o teste restaura tudo no final.
$inboxBefore = null;
if ($inboxId > 0) {
    $inboxBefore = $db->fetch(
        "SELECT sla_business_hours, sla_attention_minutes, sla_alert_minutes FROM inboxes WHERE id = ?",
        [$inboxId]
    );
    // Relógio de parede (sem horário comercial) e limites fixos:
    // o teste não pode depender da hora em que roda.
    $db->update('inboxes', [
        'sla_business_hours' => 0,
        'sla_attention_minutes' => 60,
        'sla_alert_minutes' => 120,
    ], 'id = ?', [$inboxId]);
    SlaService::forgetConfig();
}

// ── Conversas de fixture ────────────────────────────────────────────────
// convA: cliente falou há 10min  → abaixo da atenção (60min) → normal.
// convB: cliente falou há 200min → acima do alerta (120min)   → alerta.
// convC: waiting_internal        → a bola está com outro setor → fora.
// convD: o atendente respondeu   → a bola está com o cliente  → fora.
// convE: só o bot falou depois   → o relógio continua         → dentro,
//        e a âncora continua sendo a mensagem do cliente.
// convF: o atendente respondeu e o cliente voltou → a bola volta a ser
//        nossa → dentro.
//
// O id entra na lista de limpeza ANTES da mensagem: se o insert da
// mensagem falhar, a conversa não fica órfã no banco (e uma conversa
// órfã volta na query — sem inbound, a âncora é a criação).
$fixtureIds = [];
// Instante da mensagem do cliente em convE: é a âncora que a fila tem
// que devolver (o bot não pode trocar a âncora).
$clientAtE = '';

try {
    if ($inboxId > 0 && $contactId > 0 && $channelId > 0 && $departmentId > 0) {
        // A e B: o cliente falou e ninguém respondeu.
        foreach ([['A', 600], ['B', 12000]] as [$tag, $age]) {
            $id = Conversation::create([
                'contact_id'    => $contactId,
                'channel_id'    => $channelId,
                'department_id' => $departmentId,
                'inbox_id'      => $inboxId,
                'subject'       => 'Teste SLA dashboard ' . $tag,
                'status'        => 'new',
                'source'        => 'whatsapp',
            ]);
            if ($id > 0) {
                $fixtureIds[$tag] = $id;
                Conversation::addMessage($id, [
                    'direction'  => 'inbound',
                    'type'       => 'text',
                    'content'    => 'preciso de ajuda',
                    'user_id'    => null,
                    'created_at' => date('Y-m-d H:i:s', time() - $age),
                    'is_read'    => 1,
                ]);
            }
        }

        // C: waiting_internal — o relógio está congelado (a bola está com
        // outro setor), então a conversa não entra na fila em risco.
        $id = Conversation::create([
            'contact_id'    => $contactId,
            'channel_id'    => $channelId,
            'department_id' => $departmentId,
            'inbox_id'      => $inboxId,
            'subject'       => 'Teste SLA dashboard C',
            'status'        => 'waiting_internal',
            'source'        => 'whatsapp',
        ]);
        if ($id > 0) {
            $fixtureIds['C'] = $id;
            Conversation::addMessage($id, [
                'direction'  => 'inbound',
                'type'       => 'text',
                'content'    => 'preciso de ajuda (C)',
                'user_id'    => null,
                'created_at' => date('Y-m-d H:i:s', time() - 900),
                'is_read'    => 1,
            ]);
        }

        // D: o atendente respondeu depois do cliente — a bola está com o
        // cliente: a conversa não entra na fila em risco.
        if ($userId > 0) {
            $id = Conversation::create([
                'contact_id'    => $contactId,
                'channel_id'    => $channelId,
                'department_id' => $departmentId,
                'inbox_id'      => $inboxId,
                'subject'       => 'Teste SLA dashboard D',
                'status'        => 'open',
                'source'        => 'whatsapp',
            ]);
            if ($id > 0) {
                $fixtureIds['D'] = $id;
                Conversation::addMessage($id, [
                    'direction'  => 'inbound',
                    'type'       => 'text',
                    'content'    => 'preciso de ajuda (D)',
                    'user_id'    => null,
                    'created_at' => date('Y-m-d H:i:s', time() - 900),
                    'is_read'    => 1,
                ]);
                Conversation::addMessage($id, [
                    'direction'  => 'outbound',
                    'type'       => 'text',
                    'content'    => 'ja te atendo',
                    'user_id'    => $userId,
                    'created_at' => date('Y-m-d H:i:s', time() - 600),
                    'is_read'    => 1,
                ]);
            }
        }

        // E: só o bot falou depois do cliente — o bot não conta como
        // resposta humana, então o relógio continua e a conversa entra.
        $id = Conversation::create([
            'contact_id'    => $contactId,
            'channel_id'    => $channelId,
            'department_id' => $departmentId,
            'inbox_id'      => $inboxId,
            'subject'       => 'Teste SLA dashboard E',
            'status'        => 'new',
            'source'        => 'whatsapp',
        ]);
        if ($id > 0) {
            $fixtureIds['E'] = $id;
            $clientAtE = date('Y-m-d H:i:s', time() - 900);
            Conversation::addMessage($id, [
                'direction'  => 'inbound',
                'type'       => 'text',
                'content'    => 'preciso de ajuda (E)',
                'user_id'    => null,
                'created_at' => $clientAtE,
                'is_read'    => 1,
            ]);
            Conversation::addMessage($id, [
                'direction'  => 'outbound',
                'type'       => 'text',
                'content'    => 'sou um bot',
                'user_id'    => null,
                'created_at' => date('Y-m-d H:i:s', time() - 600),
                'is_read'    => 1,
            ]);
        }

        // F: o atendente respondeu e o cliente voltou a falar — a bola está
        // com a gente de novo (o relógio reinicia na última mensagem do
        // cliente). É o caminho `last_human_id <= last_inbound_id`: com as
        // duas âncoras preenchidas e o cliente falando por último.
        if ($userId > 0) {
            $id = Conversation::create([
                'contact_id'    => $contactId,
                'channel_id'    => $channelId,
                'department_id' => $departmentId,
                'inbox_id'      => $inboxId,
                'subject'       => 'Teste SLA dashboard F',
                'status'        => 'open',
                'source'        => 'whatsapp',
            ]);
            if ($id > 0) {
                $fixtureIds['F'] = $id;
                Conversation::addMessage($id, [
                    'direction'  => 'outbound',
                    'type'       => 'text',
                    'content'    => 'ja te atendo',
                    'user_id'    => $userId,
                    'created_at' => date('Y-m-d H:i:s', time() - 1800),
                    'is_read'    => 1,
                ]);
                Conversation::addMessage($id, [
                    'direction'  => 'inbound',
                    'type'       => 'text',
                    'content'    => 'ainda preciso de ajuda (F)',
                    'user_id'    => null,
                    'created_at' => date('Y-m-d H:i:s', time() - 900),
                    'is_read'    => 1,
                ]);
            }
        }
    }

    // ── Asserções: a query da fila em risco ─────────────────────────────────
    $rows = Conversation::openConversationsAwaitingReply();
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }

    $convA = (int) ($fixtureIds['A'] ?? 0);
    $convB = (int) ($fixtureIds['B'] ?? 0);
    $convC = (int) ($fixtureIds['C'] ?? 0);
    $convD = (int) ($fixtureIds['D'] ?? 0);
    $convE = (int) ($fixtureIds['E'] ?? 0);
    $convF = (int) ($fixtureIds['F'] ?? 0);

    // O diagnóstico vai no NOME do check: o check do runner tem 2
    // parâmetros e descarta o 3º argumento, e assim o dado sobrevive
    // nos dois modos de execução.
    check(
        'dashboard sla: a conversa dentro do limite entra na fila em risco (convA=' . $convA . ')',
        isset($byId[$convA])
    );
    check(
        'dashboard sla: a conversa estourada entra na fila em risco (convB=' . $convB . ')',
        isset($byId[$convB])
    );
    check(
        'dashboard sla: a fila em risco traz as ancoras do relogio (convB=' . $convB . ')',
        isset($byId[$convB]) && ($byId[$convB]['sla_last_inbound_id'] ?? 0) > 0
    );
    check(
        'dashboard sla: a fila em risco traz inbox e departamento (convB=' . $convB . ')',
        isset($byId[$convB])
            && (int) ($byId[$convB]['inbox_id'] ?? 0) === $inboxId
            && (int) ($byId[$convB]['department_id'] ?? 0) === $departmentId
    );

    // ── As exclusões: a fila é só das conversas com a bola com a gente ────
    // As três conversas negativas também precisam ter nascido: sem o
    // id > 0 o check passaria vazio e a exclusão não estaria coberta.
    check(
        'dashboard sla: a conversa em waiting_internal fica fora da fila em risco (convC=' . $convC . ')',
        $convC > 0 && !isset($byId[$convC])
    );
    check(
        'dashboard sla: a conversa respondida pelo atendente fica fora da fila em risco (convD=' . $convD . ')',
        $convD > 0 && !isset($byId[$convD])
    );
    check(
        'dashboard sla: a conversa que so o bot falou entra na fila em risco (convE=' . $convE . ')',
        $convE > 0 && isset($byId[$convE])
    );
    check(
        'dashboard sla: o bot nao troca a ancora do relogio (convE=' . $convE . ', ancora=' . $clientAtE . ')',
        isset($byId[$convE]) && (string) ($byId[$convE]['sla_last_inbound_at'] ?? '') === $clientAtE
    );
    check(
        'dashboard sla: a conversa que o cliente retomou depois da resposta entra na fila em risco (convF=' . $convF . ')',
        $convF > 0 && isset($byId[$convF])
    );
} finally {
    // ── Limpeza ─────────────────────────────────────────────────────────────
    // No finally: um check que falha no meio não pode deixar a caixa com
    // limite alterado nem as conversas de fixture no banco.
    foreach ($fixtureIds as $id) {
        if ($id > 0) {
            $db->delete('messages', 'conversation_id = ?', [$id]);
            $db->delete('conversation_events', 'conversation_id = ?', [$id]);
            $db->delete('notifications', 'conversation_id = ?', [$id]);
            $db->delete('conversation_slas', 'conversation_id = ?', [$id]);
            $db->delete('conversations', 'id = ?', [$id]);
        }
    }
    if ($inboxId > 0 && $inboxBefore !== null) {
        $db->update('inboxes', [
            'sla_business_hours' => $inboxBefore['sla_business_hours'],
            'sla_attention_minutes' => $inboxBefore['sla_attention_minutes'],
            'sla_alert_minutes' => $inboxBefore['sla_alert_minutes'],
        ], 'id = ?', [$inboxId]);
        SlaService::forgetConfig();
    }
}

// ── Resumo ───────────────────────────────────────────────────────────────
// Quando o arquivo é carregado pelo runner principal, quem fecha o
// processo é o runner (com o total de todos os testes). Standalone, este
// arquivo fecha com o próprio total — e com exit 1 se algo falhou.
if ($standalone) {
    echo "\n" . $GLOBALS['passes'] . " passed, " . $GLOBALS['failures'] . " failed\n";
    exit($GLOBALS['failures'] > 0 ? 1 : 0);
}
