<?php
require __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\ConversationService;
use App\Services\WhatsAppService;

Database::connect();
$db = Database::getInstance();
$ok = true;
function check(bool $cond, string $msg): void {
    global $ok;
    echo ($cond ? "ok" : "FAIL") . ": $msg\n";
    if (!$cond) $ok = false;
}

$conn = $db->fetch("SELECT * FROM whatsapp_connections ORDER BY id LIMIT 1");
if (!$conn) { echo "SKIP: sem conexão\n"; exit(1); }
$_GET['secret'] = (string) ($conn['webhook_secret'] ?? '');
$instance = (string) ($conn['instance_name'] ?? '');
$channelId = (int) ($conn['channel_id'] ?? 0);

// 1. Inbound: webhook de contato vira messages.type='contact'
$mid = 'CTC-' . time();
$svc = new WhatsAppService();
$svc->handleWebhook([
    'EventType' => 'messages',
    'instanceName' => $instance,
    'data' => [
        'key' => ['remoteJid' => '5511999998888@s.whatsapp.net', 'id' => $mid, 'fromMe' => false],
        'pushName' => 'João',
        'messageType' => 'ContactMessage',
        'message' => [
            'sender' => '5511999998888@c.us',
            'contact' => ['name' => 'Maria Silva', 'phone' => '5511988887777'],
        ],
    ],
]);
$row = $db->fetch("SELECT * FROM messages WHERE channel_message_id = ? LIMIT 1", [$mid]);
check($row && $row['type'] === 'contact', 'inbound contato salvo como type=contact');
$card = $row ? (json_decode((string) $row['content'], true) ?: []) : [];
check(($card['phone'] ?? '') === '5511988887777', 'cartao tem telefone');
$convId = $row ? (int) $row['conversation_id'] : 0;

// 2. Outbound: ConversationService aceita type=contact na mesma conversa
$msgId = 0;
if ($convId > 0) {
    $msgId = (new ConversationService())->sendMessage(
        $convId,
        json_encode(['name' => 'Maria Silva', 'phone' => '5511988887777'], JSON_UNESCAPED_UNICODE),
        'contact',
        1
    );
    $out = Conversation::getMessage($msgId);
    check($out && $out['type'] === 'contact', 'outbound contato criado como type=contact');
}

// 3. Roteamento: sendOutbound não quebra p/ contact (sem rede → falha graciosa)
if ($msgId > 0) {
    try {
        $res = $svc->sendOutbound($convId, $msgId, 'contact', json_encode(['name' => 'Maria Silva', 'phone' => '5511988887777']));
        $st = $db->fetch("SELECT delivery_status FROM messages WHERE id = ?", [$msgId]);
        check(in_array($st['delivery_status'] ?? null, ['failed', 'sent'], true), 'roteamento contact sem crash (fallback gracioso)');
    } catch (\Throwable $e) {
        check(false, 'sendOutbound contact sem throw (' . substr($e->getMessage(), 0, 80) . ')');
    }
}

// 4. UI: inbox tem botão/modal/cartão de contato
$inboxSrc = file_get_contents(__DIR__ . '/../../app/Views/inbox/show.php');
check(str_contains($inboxSrc, 'contact_id'), 'inbox UI envia contact_id');
check(str_contains($inboxSrc, "type==='contact'") || str_contains($inboxSrc, '"contact"') || str_contains($inboxSrc, "'contact'"), 'inbox UI renderiza cartao contact');

// Limpeza
if ($row) {
    $db->delete('messages', 'conversation_id = ?', [$convId]);
    $db->delete('notifications', 'conversation_id = ?', [$convId]);
    $db->delete('conversation_events', 'conversation_id = ?', [$convId]);
    $db->delete('conversations', 'id = ?', [$convId]);
    $ct = $db->fetch("SELECT * FROM contacts WHERE phone = ? LIMIT 1", ['5511999998888']);
    if ($ct && !$db->fetch("SELECT id FROM conversations WHERE contact_id = ? LIMIT 1", [(int) $ct['id']])) {
        Contact::delete((int) $ct['id']);
    }
    echo "cleanup ok\n";
}

echo $ok ? "PASS: contact flow OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
