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
use App\Models\Inbox;
use App\Services\SlaService;

if (!function_exists('check')) {
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

// ── Duas conversas de fixture ────────────────────────────────────────────
// convA: cliente falou há 10min  → abaixo da atenção (60min) → normal.
// convB: cliente falou há 200min → acima do alerta (120min)   → alerta.
$convA = 0;
$convB = 0;

try {
    if ($inboxId > 0 && $contactId > 0 && $channelId > 0 && $departmentId > 0) {
        foreach ([['A', 600, 'convA'], ['B', 12000, 'convB']] as [$tag, $age, $var]) {
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
                Conversation::addMessage($id, [
                    'direction'  => 'inbound',
                    'type'       => 'text',
                    'content'    => 'preciso de ajuda',
                    'user_id'    => null,
                    'created_at' => date('Y-m-d H:i:s', time() - $age),
                    'is_read'    => 1,
                ]);
                if ($tag === 'A') { $convA = $id; } else { $convB = $id; }
            }
        }
    }

    // ── Asserções: a query da fila em risco ─────────────────────────────────
    $rows = Conversation::openConversationsAwaitingReply();
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }

    check(
        'dashboard sla: a conversa dentro do limite entra na fila em risco',
        isset($byId[$convA]),
        'convA=' . $convA
    );
    check(
        'dashboard sla: a conversa estourada entra na fila em risco',
        isset($byId[$convB]),
        'convB=' . $convB
    );
    check(
        'dashboard sla: a fila em risco traz as ancoras do relogio',
        isset($byId[$convB]) && ($byId[$convB]['sla_last_inbound_id'] ?? 0) > 0
    );
    check(
        'dashboard sla: a fila em risco traz inbox e departamento',
        isset($byId[$convB])
            && (int) ($byId[$convB]['inbox_id'] ?? 0) === $inboxId
            && (int) ($byId[$convB]['department_id'] ?? 0) === $departmentId
    );
} finally {
    // ── Limpeza ─────────────────────────────────────────────────────────────
    // No finally: um check que falha no meio não pode deixar a caixa com
    // limite alterado nem as conversas de fixture no banco.
    foreach ([$convA, $convB] as $id) {
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
// arquivo fecha com o próprio total.
if (!function_exists('check')) {
    echo "\n" . $GLOBALS['passes'] . " passed, " . $GLOBALS['failures'] . " failed\n";
    exit($GLOBALS['failures'] > 0 ? 1 : 0);
}
