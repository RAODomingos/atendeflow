<?php
require __DIR__ . '/../bootstrap.php';
use App\Models\Conversation;
use App\Models\Contact;
use App\Services\ConversationService;
use App\Core\Database;

Database::connect();
$db = \App\Core\Database::getInstance();

$contactId = Contact::create(['name' => 'CloseReasonTest', 'phone' => '+5511966660001']);
$channel = $db->fetch("SELECT id FROM channels LIMIT 1");
$convId = $db->insert('conversations', [
    'contact_id' => $contactId,
    'channel_id' => $channel['id'],
    'status' => 'open',
    'subject' => 'cr',
    'public_id' => bin2hex(random_bytes(18)),
]);

$svc = new ConversationService();
$svc->changeStatus($convId, 'resolved', 'Resolvido', 'Tudo certo');

$conv = Conversation::find($convId);
$ok = true;
if (($conv['close_reason'] ?? '') !== 'Resolvido') { echo "FAIL reason\n"; $ok = false; }
if (($conv['close_description'] ?? '') !== 'Tudo certo') { echo "FAIL desc\n"; $ok = false; }

$note = $db->fetch("SELECT * FROM messages WHERE conversation_id = ? AND type = 'internal_note' ORDER BY id DESC LIMIT 1", [$convId]);
if (!$note || strpos($note['content'], 'Tudo certo') === false) { echo "FAIL internal note\n"; $ok = false; }

$svc->changeStatus($convId, 'open');
if (Conversation::find($convId)['status'] !== 'open') { echo "FAIL reopen\n"; $ok = false; }

$db->delete('conversations', 'id = ?', [$convId]);
Contact::delete($contactId);

echo $ok ? "PASS: changeStatus motivo/descricao OK\n" : "FAIL\n";
