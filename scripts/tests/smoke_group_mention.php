<?php
require __DIR__ . '/../bootstrap.php';

use App\Models\WhatsAppGroup;
use App\Services\NotificationService;
use App\Services\WhatsApp\IncomingMessage;
use App\Services\WhatsApp\UazapiProvider;
use App\Services\WhatsApp\WahaProvider;
use App\Services\WhatsAppService;
use App\Core\Database;

Database::connect();
$db = Database::getInstance();
$ok = true;
function check(bool $cond, string $msg): void {
    global $ok;
    echo ($cond ? "ok" : "FAIL") . ": $msg\n";
    if (!$cond) $ok = false;
}

// 1. Matching de dígitos
check(WhatsAppService::samePhoneDigits('5522997035962', '5522997035962'), 'dígitos iguais');
check(WhatsAppService::samePhoneDigits('5522997035962', '22997035962'), 'sufixo sem DDI');
check(WhatsAppService::samePhoneDigits('5522997035962', '5522997035961') === false, 'número diferente não casa');
check(WhatsAppService::connectionMentioned('5522997035962', ['5522997035962'], 'olá'), 'menção explícita');
check(WhatsAppService::connectionMentioned('5522997035962', [], 'fala @5522997035962 blz?'), 'menção textual @numero');
check(WhatsAppService::connectionMentioned('5522997035962', [], 'sem menção') === false, 'sem menção não alerta');

// 2. Parse Uazapi de grupo
$uaz = new UazapiProvider();
$uazMsg = $uaz->parseWebhook([
    'EventType' => 'messages',
    'instanceName' => 'test-inst',
    'data' => [
        'key' => ['remoteJid' => '120363012345678@g.us', 'id' => 'MSG123', 'fromMe' => false],
        'pushName' => 'João',
        'messageType' => 'Conversation',
        'message' => [
            'sender' => '5511999998888@c.us',
            'content' => 'oi @5522997035962 ajuda aqui',
            'mentionedJid' => ['5522997035962@s.whatsapp.net'],
        ],
    ],
    'chat' => ['name' => 'Grupo Teste'],
]);
check($uazMsg instanceof IncomingMessage && ($uazMsg->extra['is_group'] ?? false), 'uazapi: grupo parseado');
check($uazMsg && $uazMsg->from === '5511999998888', 'uazapi: remetente é o participante');
check($uazMsg && ($uazMsg->extra['group_jid'] ?? '') === '120363012345678@g.us', 'uazapi: group_jid');
check($uazMsg && in_array('5522997035962', $uazMsg->extra['mentioned'] ?? [], true), 'uazapi: mencionado extraído');

// 3. Parse WAHA de grupo
$waha = new WahaProvider();
$wahaMsg = $waha->parseWebhook([
    'event' => 'message',
    'session' => 'test-session',
    'payload' => [
        'id' => 'WMSG1',
        'from' => '120363012345678@g.us',
        'participant' => '5511999998888@c.us',
        'body' => 'chamando @5522997035962',
        '_data' => ['notifyName' => 'Maria', 'mentionedJidList' => ['5522997035962@c.us']],
    ],
]);
check($wahaMsg instanceof IncomingMessage && ($wahaMsg->extra['is_group'] ?? false), 'waha: grupo parseado');
check($wahaMsg && $wahaMsg->from === '5511999998888', 'waha: remetente é o participante');

// 4. Fluxo completo: upsert + menção + notificação (primeira conexão real)
$conn = $db->fetch("SELECT * FROM whatsapp_connections ORDER BY id LIMIT 1");
if (!$conn) { echo "SKIP: sem conexão\n"; exit($ok ? 0 : 1); }
$connId = (int) $conn['id'];
$connPhone = preg_replace('/\D/', '', (string) ($conn['phone_number'] ?? ''));
$svc = new WhatsAppService();
$inMsg = new IncomingMessage('test-inst', 'SMOKE-' . time(), '5511999998888', 'text',
    'preciso de ajuda @' . $connPhone, null, null, null, time(), false, 'Smoke User', null,
    ['is_group' => true, 'group_jid' => '120363099988877@g.us', 'group_name' => 'Smoke Group',
     'mentioned' => [$connPhone], 'participant_phone' => '5511999998888']);
$svc->handleGroupMessage($conn, $inMsg);
$group = WhatsAppGroup::findByConnectionJid($connId, '120363099988877@g.us');
check(!!$group, 'grupo criado via webhook');
check(($group['name'] ?? '') === 'Smoke Group', 'nome do grupo salvo');
$mentions = $group ? WhatsAppGroup::mentions((int) $group['id'], 5) : [];
check(count($mentions) === 1, 'menção registrada');
$notifs = $db->fetchAll(
    "SELECT * FROM notifications WHERE notification_type = 'group_mention'
      AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.group_id')) = ?",
    [(string) $group['id']]
);
check(count($notifs) >= 1, 'notificação group_mention criada (' . count($notifs) . ' destinatários)');

// 5. Dedup: mesmo webhook não duplica
$svc->handleGroupMessage($conn, $inMsg);
check(count(WhatsAppGroup::mentions((int) $group['id'], 10)) === 1, 'webhook duplicado não duplica menção');

// 6. Sem menção: só aprende o grupo
$inMsg2 = new IncomingMessage('test-inst', 'SMOKE2-' . time(), '5511999998888', 'text',
    'bom dia a todos', null, null, null, time(), false, 'Smoke User', null,
    ['is_group' => true, 'group_jid' => '120363099988877@g.us', 'mentioned' => [], 'participant_phone' => '5511999998888']);
$svc->handleGroupMessage($conn, $inMsg2);
check(count(WhatsAppGroup::mentions((int) $group['id'], 10)) === 1, 'mensagem sem menção não alerta');

// 7. Mensagem própria: sem alerta
$inMsg3 = new IncomingMessage('test-inst', 'SMOKE3-' . time(), $connPhone, 'text',
    '@5511999998888 ok', null, null, null, time(), false, 'Eu', null,
    ['is_group' => true, 'group_jid' => '120363099988877@g.us', 'mentioned' => ['5511999998888'], 'participant_phone' => $connPhone]);
$svc->handleGroupMessage($conn, $inMsg3);
check(count(WhatsAppGroup::mentions((int) $group['id'], 10)) === 1, 'mensagem própria não alerta');

check((bool) $db->fetch("SELECT id FROM conversations WHERE group_id = ? LIMIT 1", [(int) $group['id']]), 'conversa do grupo criada na caixa');
$convRow = $db->fetch("SELECT * FROM conversations WHERE group_id = ? ORDER BY id DESC LIMIT 1", [(int) $group['id']]);
check($convRow && !empty($convRow['inbox_id']), 'conversa do grupo tem caixa definida');
check($notifs && !empty($notifs[0]['conversation_id']), 'sino aponta para a conversa na caixa');

// 8. Só menção conta como não-lida (msg comum não notifica/não conta)
$unreadRow = $db->fetch(
    "SELECT COUNT(*) c FROM messages WHERE conversation_id = ? AND direction = 'inbound' AND is_read = 0",
    [(int) $convRow['id']]
);
check((int) ($unreadRow['c'] ?? 0) === 1, 'só menção fica não-lida (comum é histórico lido)');
$commonMsg = $db->fetch(
    "SELECT is_read FROM messages WHERE conversation_id = ? AND channel_message_id = ? LIMIT 1",
    [(int) $convRow['id'], $inMsg2->messageId]
);
check($commonMsg && (int) $commonMsg['is_read'] === 1, 'mensagem sem menção não gera contador');

// 9. Grupo não pode ser encerrado/fechado
$blocked = false;
try {
    (new \App\Services\ConversationService())->changeStatus((int) $convRow['id'], 'closed');
} catch (\Throwable $e) {
    $blocked = true;
}
check($blocked, 'grupo não pode ser fechado/encerrado');

// Limpeza (conversa do grupo + contato sintético + menções/notif)
if ($group) {
    foreach ($db->fetchAll("SELECT id FROM conversations WHERE group_id = ?", [(int) $group['id']]) as $c) {
        $db->delete('messages', 'conversation_id = ?', [(int) $c['id']]);
        $db->delete('conversation_events', 'conversation_id = ?', [(int) $c['id']]);
        $db->delete('notifications', 'conversation_id = ?', [(int) $c['id']]);
        $db->delete('conversations', 'id = ?', [(int) $c['id']]);
    }
    $db->delete('notifications', "notification_type = 'group_mention' AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.group_id')) = ?", [(string) $group['id']]);
    $db->delete('whatsapp_group_mentions', 'group_id = ?', [$group['id']]);
    $db->delete('whatsapp_groups', 'id = ?', [$group['id']]);
    $ct = $db->fetch("SELECT * FROM contacts WHERE phone = ? LIMIT 1", ['120363099988877@g.us']);
    if ($ct && !$db->fetch("SELECT id FROM conversations WHERE contact_id = ? LIMIT 1", [(int) $ct['id']])) {
        $db->delete('contacts', 'id = ?', [(int) $ct['id']]);
    }
    echo "cleanup ok\n";
}

echo $ok ? "PASS: grupos WhatsApp OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
