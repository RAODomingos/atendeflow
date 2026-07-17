<?php
require __DIR__ . '/../bootstrap.php';

use App\Models\Conversation;
use App\Models\Contact;
use App\Services\ConversationService;
use App\Core\Database;

Database::connect();
$db = \App\Core\Database::getInstance();

$contactId = Contact::create(['name' => 'CSAT Num Test', 'phone' => '+5511988880009']);
$channel = $db->fetch("SELECT id, type FROM channels LIMIT 1");
$convId = $db->insert('conversations', [
    'contact_id' => $contactId,
    'channel_id' => $channel['id'],
    'status' => 'resolved',
    'subject' => 'csat num',
    'public_id' => bin2hex(random_bytes(18)),
    'csat_requested' => 1,
]);

$svc = new ConversationService();
$svc->receiveMessage($convId, '5', 'text');

$ok = true;
$csat = Conversation::getCsat($convId);
if (!$csat || (int)$csat['rating'] !== 5) { echo "FAIL: rating nao capturado (got ".var_export($csat,true).")\n"; $ok = false; }

$conv = Conversation::find($convId);
if ($conv['status'] !== 'resolved') { echo "FAIL: conversa reaberta (status=".$conv['status'].")\n"; $ok = false; }
if (!empty($conv['after_hours_notified'])) { echo "FAIL: ausencia disparada indevidamente\n"; $ok = false; }

// Mensagem normal apos avaliado deve reabrir
$svc->receiveMessage($convId, 'Obrigado!', 'text');
$conv2 = Conversation::find($convId);
if ($conv2['status'] === 'resolved') { echo "FAIL: mensagem pos-avaliacao nao reabriu\n"; $ok = false; }

// Limpeza
$db->delete('conversation_csats', 'conversation_id = ?', [$convId]);
$db->delete('conversations', 'id = ?', [$convId]);
Contact::delete($contactId);

echo $ok ? "PASS: CSAT numerico OK\n" : "SOME FAILURES\n";
