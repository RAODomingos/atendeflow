<?php
require __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppLidMap;
use App\Services\WhatsApp\IncomingMessage;
use App\Services\WhatsApp\UazapiProvider;
use App\Services\WhatsAppService;

Database::connect();
$db = Database::getInstance();
$ok = true;
function check(bool $cond, string $msg): void {
    global $ok;
    echo ($cond ? "ok" : "FAIL") . ": $msg\n";
    if (!$cond) $ok = false;
}

$conn = $db->fetch("SELECT * FROM whatsapp_connections WHERE id = 3");
if (!$conn) { echo "SKIP: sem conexão id=3\n"; exit(1); }
$connPhone = preg_replace('/\D/', '', $conn['phone_number']); // 5521998647673

// 1. Replay fiel do payload real (Uazapi, menção via @lid + sender com :93)
$uaz = new UazapiProvider();
$real = $uaz->parseWebhook([
    'EventType' => 'messages',
    'instanceName' => 'atendeflow_d0b2dd23',
    'data' => ['key' => ['remoteJid' => '120363431093004793@g.us', 'id' => 'REAL1', 'fromMe' => false]],
    'message' => [
        'chatid' => '120363431093004793@g.us',
        'isGroup' => true,
        'groupName' => 'Teste',
        'sender' => '225962742546599:93@lid',
        'sender_lid' => '225962742546599@lid',
        'sender_pn' => '5522992036639@s.whatsapp.net',
        'senderName' => 'Rômulo',
        'messageType' => 'ExtendedTextMessage',
        'content' => ['text' => '@163926738235460 preciso de ajuda', 'contextInfo' => ['mentionedJID' => ['163926738235460@lid']]],
        'text' => '@163926738235460 preciso de ajuda',
    ],
    'chat' => ['name' => 'Teste', 'wa_chatlid' => '', 'phone' => ''],
]);
check($real instanceof IncomingMessage && ($real->extra['is_group'] ?? false), 'replay: grupo parseado');
check($real && $real->from === '225962742546599', 'replay: participante sem sufixo :93 (got ' . ($real ? $real->from : '?') . ')');
check($real && in_array('163926738235460', $real->extra['mentioned'] ?? [], true), 'replay: mentionedJID (case) extraído');

// 2. Aprendizado LID->fone via payload 1:1/self
$svc = new WhatsAppService();
$ref = new ReflectionMethod($svc, 'learnLidMappings');
$ref->setAccessible(true);
$ref->invoke($svc, ['message' => ['sender_lid' => '163926738235460@lid', 'sender_pn' => '5521998647673@s.whatsapp.net'], 'chat' => ['wa_chatlid' => '225962742546599@lid', 'phone' => '+55 22 99203-6639']]);
check(WhatsAppLidMap::resolve('163926738235460') === '5521998647673', 'mapa: LID do dono -> fone');
check(WhatsAppLidMap::resolve('225962742546599') === '5522992036639', 'mapa: LID participante -> fone');

// 3. Menção LID agora casa com o número da conexão
check(WhatsAppService::connectionMentioned($connPhone, ['163926738235460'], 'oi'), 'menção LID resolve e alerta');
check(WhatsAppService::connectionMentioned($connPhone, [], '@163926738235460 ajuda') === true, 'menção textual LID alerta');

// 4. Fluxo completo com LIDs
$inMsg = new IncomingMessage('atendeflow_d0b2dd23', 'REPLAY-' . time(), '225962742546599', 'text',
    '@163926738235460 preciso de ajuda', null, null, null, time(), false, 'Rômulo', null,
    ['is_group' => true, 'group_jid' => '120363431093004793@g.us', 'group_name' => 'Teste',
     'mentioned' => ['163926738235460'], 'participant_phone' => '225962742546599']);
$svc->handleGroupMessage($conn, $inMsg);
$group = WhatsAppGroup::findByConnectionJid(3, '120363431093004793@g.us');
check(!!$group, 'replay: grupo registrado');
$mentions = $group ? WhatsAppGroup::mentions((int) $group['id'], 5) : [];
$found = false;
foreach ($mentions as $m) { if (($m['provider_message_id'] ?? '') === $inMsg->messageId) $found = true; }
check($found, 'replay: menção LID gerou alerta');
$notifs = $group ? $db->fetchAll(
    "SELECT * FROM notifications WHERE notification_type = 'group_mention'
      AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.group_id')) = ?
      AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.provider_message_id')) = ?",
    [(string) $group['id'], $inMsg->messageId]
) : [];
check(count($notifs) >= 1, 'replay: notificação no sino (' . count($notifs) . ')');

// Limpeza (mantém o grupo real e a conversa, remove só menção/notif/msg do replay + LIDs de teste)
if ($group) {
    $db->delete('notifications', "notification_type = 'group_mention' AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '\$.provider_message_id')) = ?", [$inMsg->messageId]);
    $db->delete('whatsapp_group_mentions', 'provider_message_id = ?', [$inMsg->messageId]);
    $convs = $db->fetchAll("SELECT id FROM conversations WHERE group_id = ?", [(int) $group['id']]);
    foreach ($convs as $c) {
        $db->delete('messages', 'conversation_id = ? AND channel_message_id = ?', [(int) $c['id'], $inMsg->messageId]);
    }
}
$db->delete('whatsapp_lid_map', 'lid_digits = ?', ['225962742546599']);
echo "cleanup ok (mantido mapa do dono)\n";

echo $ok ? "PASS: replay LID OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
