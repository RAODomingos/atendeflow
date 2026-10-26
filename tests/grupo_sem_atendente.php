<?php
/**
 * Testes da conversa de grupo do WhatsApp (2026-10-24).
 *
 * Conversa de grupo não é atendimento individual:
 *  - não exige atendente (ninguém é auto-atribuído);
 *  - fica sempre "em aberto" (nunca "aguardando cliente"/"novo"/"fechada");
 *  - o aviso de que há trabalho é a MENÇÃO no grupo.
 *
 * Roda junto do runner principal (tests/run.php).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';
\App\Config\Environment::load();

use App\Core\Database;
use App\Models\Conversation;
use App\Services\ConversationService;
use App\Services\NotificationService;
use App\Services\SlaEscalation;

$db = Database::getInstance();
$service = new ConversationService();

// ── Fixtures: grupo e conversa de grupo isolados ────────────────────────
$connectionId = (int) ($db->fetch("SELECT connection_id FROM whatsapp_groups LIMIT 1")['connection_id'] ?? 0);
if ($connectionId <= 0) {
    $connectionId = (int) ($db->fetch("SELECT id FROM whatsapp_connections LIMIT 1")['id'] ?? 0);
}
$inboxId = (int) ($db->fetch("SELECT id FROM inboxes WHERE type = 'department' LIMIT 1")['id'] ?? 0);
$contactId = (int) ($db->fetch("SELECT id FROM contacts LIMIT 1")['id'] ?? 0);
$channelId = (int) ($db->fetch("SELECT id FROM channels LIMIT 1")['id'] ?? 0);

$groupId = 0;
$conversationId = 0;

if ($connectionId > 0 && $contactId > 0 && $channelId > 0) {
    $groupId = $db->insert('whatsapp_groups', [
        'connection_id'   => $connectionId,
        'group_jid'       => 'teste-unitario-' . bin2hex(random_bytes(4)) . '@g.us',
        'name'            => 'Teste unitario (grupo)',
        'topic_window_hours' => 0,
        'mention_alert'   => 1,
        'inbox_id'        => $inboxId ?: null,
        'is_active'       => 1,
    ]);

    $conversationId = Conversation::create([
        'contact_id'    => $contactId,
        'group_id'      => $groupId,
        'channel_id'    => $channelId,
        'department_id' => null,
        'inbox_id'      => $inboxId ?: null,
        'subject'       => 'Teste unitario (grupo)',
        'status'        => 'open',
        'source'        => 'whatsapp_group',
    ]);
}

$cleanup = static function () use ($db, &$groupId, &$conversationId): void {
    if ($conversationId > 0) {
        $db->delete('messages', 'conversation_id = ?', [$conversationId]);
        $db->delete('conversation_events', 'conversation_id = ?', [$conversationId]);
        $db->delete('notifications', 'conversation_id = ?', [$conversationId]);
        $db->delete('conversations', 'id = ?', [$conversationId]);
        $conversationId = 0;
    }
    if ($groupId > 0) {
        $db->delete('whatsapp_group_mentions', 'group_id = ?', [$groupId]);
        $db->delete('whatsapp_groups', 'id = ?', [$groupId]);
        $groupId = 0;
    }
};

// ── 1) Responder no grupo não auto-atribui nem joga para "aguardando" ──
if ($conversationId > 0) {
    $service->sendMessage($conversationId, 'Resposta no grupo', 'text', 1);
    $conv = Conversation::find($conversationId);
    check(
        'grupo: responder nao exige atendente (sem autoatribuicao)',
        empty($conv['assigned_user_id'])
    );
    check(
        'grupo: responder mantem a conversa em aberto',
        ($conv['status'] ?? '') === 'open'
    );

    // ── 2) Mensagem do cliente no grupo tambem mantem em aberto ──
    $service->receiveMessage($conversationId, 'Mensagem no grupo');
    $conv = Conversation::find($conversationId);
    check(
        'grupo: mensagem do cliente mantem a conversa em aberto',
        ($conv['status'] ?? '') === 'open'
    );
    check(
        'grupo: mensagem do cliente nao inventa responsavel',
        empty($conv['assigned_user_id'])
    );

    // ── 3) Status: so "em aberto" ──
    $blocked = false;
    try {
        $service->changeStatus($conversationId, 'closed');
    } catch (\Throwable $e) {
        $blocked = true;
    }
    check('grupo: nao da para fechar a conversa de grupo', $blocked);

    $blocked = false;
    try {
        $service->changeStatus($conversationId, 'waiting_customer');
    } catch (\Throwable $e) {
        $blocked = true;
    }
    check('grupo: nao da para mandar a conversa de grupo para aguardando', $blocked);

    $service->changeStatus($conversationId, 'open');
    $conv = Conversation::find($conversationId);
    check('grupo: da para manter em aberto', ($conv['status'] ?? '') === 'open');

    // ── 4) Transferencia nao joga a conversa de grupo para "novo" ──
    $service->transfer($conversationId, null, null, $inboxId ?: null);
    $conv = Conversation::find($conversationId);
    check(
        'grupo: transferencia mantem a conversa em aberto',
        ($conv['status'] ?? '') === 'open'
    );

    // ── 5) O aviso do grupo e a MENCAO, nao cada mensagem ──
    $notified = NotificationService::notifyNewMessage($conversationId, 0, 'Mensagem no grupo');
    check('grupo: mensagem comum do grupo nao gera notificacao', count($notified) === 0);

    // ── 6) SLA de grupo nao escala para um atendente ──
    $conv = Conversation::find($conversationId);
    $result = SlaEscalation::escalate(
        $conversationId,
        ['pending_seconds' => 600, 'response_seconds' => 600],
        $conv,
        SlaEscalation::KIND_GROUP_MENTION
    );
    check(
        'grupo: SLA de mencao nao atribui atendente automaticamente',
        ($result['autoassign'] ?? null) === null
    );
    $conv = Conversation::find($conversationId);
    check(
        'grupo: SLA de mencao nao cria responsavel',
        empty($conv['assigned_user_id'])
    );
} else {
    check('grupo: fixtures criadas (grupo e conversa)', false);
}

$cleanup();

// ── 7) O estado gravado também respeita a regra ─────────────────────────
$offenders = $db->fetchAll(
    "SELECT id FROM conversations WHERE group_id IS NOT NULL AND status <> 'open'"
);
check(
    'grupo: toda conversa de grupo esta em aberto' . (count($offenders) ? ' (ids: ' . implode(',', array_column($offenders, 'id')) . ')' : ''),
    count($offenders) === 0
);

$assigned = $db->fetchAll(
    "SELECT id FROM conversations WHERE group_id IS NOT NULL AND assigned_user_id IS NOT NULL"
);
check(
    'grupo: nenhuma conversa de grupo tem atendente obrigatorio' . (count($assigned) ? ' (ids: ' . implode(',', array_column($assigned, 'id')) . ')' : ''),
    count($assigned) === 0
);

// ── 8) O caminho do código ─────────────────────────────────────────────
$svcSrc = file_get_contents(__DIR__ . '/../app/Services/ConversationService.php');
$waSrc = file_get_contents(__DIR__ . '/../app/Services/WhatsAppService.php');
$notifSrc = file_get_contents(__DIR__ . '/../app/Services/NotificationService.php');
$slaSrc = file_get_contents(__DIR__ . '/../app/Services/SlaEscalation.php');
$panelSrc = file_get_contents(__DIR__ . '/../app/Views/inbox/panel.php');
$migSrc = file_get_contents(__DIR__ . '/../database/migrations/2026_10_24_grupo_sem_atendente.sql');

check(
    'grupo: a conversa nasce em aberto (sem fila "novo")',
    str_contains($waSrc, "'status' => 'open'")
    && str_contains($waSrc, "'source' => 'whatsapp_group'")
);
check(
    'grupo: a notificacao de mencao continua no lugar',
    str_contains($notifSrc, 'notifyGroupMention')
    && str_contains($waSrc, 'notifyGroupMention')
);
check(
    'grupo: o painel mostra a conversa de grupo sempre em aberto',
    str_contains($panelSrc, '$isGroupConv')
    && str_contains($panelSrc, "'Em aberto'")
);
check(
    'grupo: o SLA nao escala conversa de grupo para atendente',
    str_contains($slaSrc, "group_id")
    && str_contains($slaSrc, 'Conversa de grupo não tem atendente')
);
check(
    'grupo: a migration normaliza o estado das conversas de grupo',
    str_contains($migSrc, 'group_id IS NOT NULL')
    && str_contains($migSrc, "status = 'open'")
    && str_contains($migSrc, 'assigned_user_id = NULL')
);
check(
    'grupo: o status travado em aberto esta no servico',
    str_contains($svcSrc, 'Conversas de grupo ficam sempre em aberto')
);
