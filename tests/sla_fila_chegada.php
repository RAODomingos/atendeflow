<?php
/**
 * SLA da fila de chegada — aba "Aguardando" (2026-10-24).
 *
 * Regressão do sintoma: a conversa que chega NOVA na fila (vinda de um
 * fluxo de bot ou transferida de outro setor) ficava com o relógio do SLA
 * CONGELADO e nunca entrava no estado "SLA estourado" (vermelho).
 *
 * Causa: a âncora "já respondemos?" olhava qualquer mensagem nossa
 * posterior à do cliente — inclusive a do BOT/fluxo (que não tem
 * `user_id`). Agora só a resposta HUMANA conta, igual ao motor de medição
 * (SlaMeasurement::isHumanReply).
 *
 * Roda junto do runner principal (tests/run.php).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';
\App\Config\Environment::load();

// Mesmo fuso do app: o banco guarda os horários em America/Sao_Paulo e o
// relógio do SLA interpreta os timestamps nesse fuso.
$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

use App\Core\Database;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Services\SlaService;

$db = Database::getInstance();

// Caixa de departamento + um departamento coberto por ela (e sem grupo:
// a conversa de teste é de mensagem geral).
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

$conversationId = 0;
$inboxBefore = null;

// Horário comercial desligado na caixa: o relógio conta tempo de relógio
// e o teste não depende de em que faixa o teste roda.
if ($inboxId > 0) {
    $inboxBefore = $db->fetch("SELECT sla_business_hours FROM inboxes WHERE id = ?", [$inboxId]);
    $db->update('inboxes', ['sla_business_hours' => 0], 'id = ?', [$inboxId]);
    SlaService::forgetConfig();
}

if ($inboxId > 0 && $contactId > 0 && $channelId > 0 && $departmentId > 0) {
    $conversationId = Conversation::create([
        'contact_id'    => $contactId,
        'channel_id'    => $channelId,
        'department_id' => $departmentId,
        'inbox_id'      => $inboxId,
        'subject'       => 'Teste SLA fila de chegada',
        'status'        => 'new',
        'source'        => 'whatsapp',
    ]);

    // Cliente falou há 3 horas...
    Conversation::addMessage($conversationId, [
        'direction'  => 'inbound',
        'type'       => 'text',
        'content'    => 'oi, preciso de ajuda',
        'user_id'    => null,
        'created_at' => date('Y-m-d H:i:s', time() - 10800),
        'is_read'    => 1,
    ]);
    // ...e o bot respondeu há 2 horas (sem user_id = não é resposta humana).
    Conversation::addMessage($conversationId, [
        'direction'  => 'outbound',
        'type'       => 'text',
        'content'    => 'Vou te transferir para um atendente',
        'user_id'    => null,
        'created_at' => date('Y-m-d H:i:s', time() - 7200),
        'is_read'    => 1,
    ]);
}

$row = null;
if ($conversationId > 0) {
    $rows = Conversation::getConversationsForSets(
        Inbox::membershipSets([$inboxId]),
        null,
        ['status' => 'waiting']
    );
    foreach ($rows as $r) {
        if ((int) ($r['id'] ?? 0) === $conversationId) {
            $row = $r;
            break;
        }
    }
}

check('sla fila: a conversa nova aparece na aba Aguardando', $row !== null);

if ($row !== null) {
    $waiting = (int) ($row['waiting_seconds'] ?? 0);
    check(
        'sla fila: o relogio corre mesmo com o bot falando por ultimo',
        $waiting > 3600,
        'waiting=' . $waiting
    );
    check(
        'sla fila: o relogio nao fica congelado por causa do bot',
        (int) ($row['sla_frozen'] ?? 1) === 0,
        'frozen=' . ($row['sla_frozen'] ?? '-') . ' motivo=' . ($row['sla_paused_reason'] ?? '-')
    );
    check(
        'sla fila: a conversa entra no estado estourado',
        SlaService::levelFor($waiting, $row['status'], $row['last_message_direction'], $inboxId) === SlaService::LEVEL_ALERT,
        'nivel=' . SlaService::levelFor($waiting, $row['status'], $row['last_message_direction'], $inboxId)
    );
} else {
    check('sla fila: o relogio corre mesmo com o bot falando por ultimo', false);
    check('sla fila: o relogio nao fica congelado por causa do bot', false);
    check('sla fila: a conversa entra no estado estourado', false);
}

// ── Nota interna e mensagem de sistema NAO sao resposta humana ──────────
// A ancora do relogio (Conversation::slaAnchorsSql) so conta como resposta
// humana a mensagem outbound de um atendente que NAO e nota interna nem
// mensagem de sistema. Sem essa clausula, uma nota interna escrita pelo
// proprio atendente pararia o relogio do cliente que ainda espera resposta.
if ($conversationId > 0) {
    Conversation::addMessage($conversationId, [
        'direction'  => 'outbound',
        'type'       => 'internal_note',
        'content'    => 'Nota interna: o cliente ainda espera resposta',
        'user_id'    => 1,
        'created_at' => date('Y-m-d H:i:s', time() - 5400),
        'is_read'    => 1,
    ]);

    $rows = Conversation::getConversationsForSets(
        Inbox::membershipSets([$inboxId]),
        null,
        ['status' => 'waiting']
    );
    $row = null;
    foreach ($rows as $r) {
        if ((int) ($r['id'] ?? 0) === $conversationId) {
            $row = $r;
            break;
        }
    }
    check('sla fila: a nota interna nao tira a conversa da aba Aguardando', $row !== null);

    if ($row !== null) {
        $waiting = (int) ($row['waiting_seconds'] ?? 0);
        check(
            'sla fila: nota interna nao conta como resposta humana (o relogio corre)',
            $waiting > 3600,
            'waiting=' . $waiting
        );
        check(
            'sla fila: nota interna nao congela o relogio',
            (int) ($row['sla_frozen'] ?? 1) === 0,
            'frozen=' . ($row['sla_frozen'] ?? '-') . ' motivo=' . ($row['sla_paused_reason'] ?? '-')
        );
        check(
            'sla fila: nota interna nao marca a pausa por cliente',
            ($row['sla_paused_reason'] ?? '') !== 'customer',
            'motivo=' . ($row['sla_paused_reason'] ?? '-')
        );
    } else {
        check('sla fila: nota interna nao conta como resposta humana (o relogio corre)', false);
        check('sla fila: nota interna nao congela o relogio', false);
        check('sla fila: nota interna nao marca a pausa por cliente', false);
    }
}

// ── Mensagem de sistema tambem NAO e resposta humana ────────────────────
// Mesma regra para o outro tipo excluido pela ancora.
if ($conversationId > 0) {
    Conversation::addMessage($conversationId, [
        'direction'  => 'outbound',
        'type'       => 'system',
        'content'    => 'Mensagem de sistema',
        'user_id'    => 1,
        'created_at' => date('Y-m-d H:i:s', time() - 4500),
        'is_read'    => 1,
    ]);

    $rows = Conversation::getConversationsForSets(
        Inbox::membershipSets([$inboxId]),
        null,
        ['status' => 'waiting']
    );
    $row = null;
    foreach ($rows as $r) {
        if ((int) ($r['id'] ?? 0) === $conversationId) {
            $row = $r;
            break;
        }
    }
    check('sla fila: a mensagem de sistema nao tira a conversa da aba Aguardando', $row !== null);

    if ($row !== null) {
        $waiting = (int) ($row['waiting_seconds'] ?? 0);
        check(
            'sla fila: mensagem de sistema nao conta como resposta humana (o relogio corre)',
            $waiting > 3600,
            'waiting=' . $waiting
        );
        check(
            'sla fila: mensagem de sistema nao congela o relogio',
            (int) ($row['sla_frozen'] ?? 1) === 0,
            'frozen=' . ($row['sla_frozen'] ?? '-') . ' motivo=' . ($row['sla_paused_reason'] ?? '-')
        );
        check(
            'sla fila: mensagem de sistema nao marca a pausa por cliente',
            ($row['sla_paused_reason'] ?? '') !== 'customer',
            'motivo=' . ($row['sla_paused_reason'] ?? '-')
        );
    } else {
        check('sla fila: mensagem de sistema nao conta como resposta humana (o relogio corre)', false);
        check('sla fila: mensagem de sistema nao congela o relogio', false);
        check('sla fila: mensagem de sistema nao marca a pausa por cliente', false);
    }
}

// ── Quando um HUMANO responde, a bola volta para o cliente: congela ──────
if ($conversationId > 0) {
    Conversation::addMessage($conversationId, [
        'direction'  => 'outbound',
        'type'       => 'text',
        'content'    => 'Ola, ja te atendemos',
        'user_id'    => 1,
        'created_at' => date('Y-m-d H:i:s', time() - 3600),
        'is_read'    => 1,
    ]);

    $rows = Conversation::getConversationsForSets(
        Inbox::membershipSets([$inboxId]),
        null,
        ['status' => 'waiting']
    );
    $row = null;
    foreach ($rows as $r) {
        if ((int) ($r['id'] ?? 0) === $conversationId) {
            $row = $r;
            break;
        }
    }
    check('sla fila: a conversa continua na aba Aguardando', $row !== null);
    check(
        'sla fila: resposta humana zera o relogio (bola com o cliente)',
        (int) ($row['waiting_seconds'] ?? -1) === 0,
        'waiting=' . ($row['waiting_seconds'] ?? '-')
    );
    check(
        'sla fila: resposta humana marca a pausa por cliente',
        ($row['sla_paused_reason'] ?? '') === 'customer',
        'motivo=' . ($row['sla_paused_reason'] ?? '-')
    );
}

// ── Limpeza ─────────────────────────────────────────────────────────────
if ($conversationId > 0) {
    $db->delete('messages', 'conversation_id = ?', [$conversationId]);
    $db->delete('conversation_events', 'conversation_id = ?', [$conversationId]);
    $db->delete('notifications', 'conversation_id = ?', [$conversationId]);
    $db->delete('conversation_slas', 'conversation_id = ?', [$conversationId]);
    $db->delete('conversations', 'id = ?', [$conversationId]);
}
if ($inboxId > 0 && $inboxBefore !== null) {
    $db->update('inboxes', ['sla_business_hours' => $inboxBefore['sla_business_hours']], 'id = ?', [$inboxId]);
    SlaService::forgetConfig();
}
