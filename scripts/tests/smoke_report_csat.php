<?php
require __DIR__ . '/../bootstrap.php';
use App\Models\Conversation;
use App\Models\Contact;
use App\Core\Database;

Database::connect();
$db = Database::getInstance();

// Garante uma conversa + CSAT para o teste
$contactId = Contact::create(['name' => 'Report Test', 'phone' => '+5511977770011']);
$channel = $db->fetch("SELECT id FROM channels LIMIT 1");
$convId = $db->insert('conversations', [
    'contact_id' => $contactId,
    'channel_id' => $channel['id'],
    'status' => 'resolved',
    'subject' => 'report test',
    'public_id' => bin2hex(random_bytes(18)),
]);
$db->insert('conversation_csats', ['conversation_id' => $convId, 'rating' => 4, 'comment' => 'Bom atendimento']);

$where = 'WHERE cc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
$params = [30];
$total = (int)($db->fetch("SELECT COUNT(*) AS c FROM conversation_csats cc $where", $params)['c'] ?? 0);
$avg = $db->fetch("SELECT AVG(rating) AS a FROM conversation_csats cc $where", $params)['a'] ?? null;
$dist = $db->fetchAll("SELECT rating, COUNT(*) AS c FROM conversation_csats cc $where GROUP BY rating ORDER BY rating DESC", $params);
$recent = $db->fetchAll("SELECT cc.*, c.name AS contact_name, ch.type AS channel_type, d.name AS department_name
    FROM conversation_csats cc JOIN conversations conv ON conv.id = cc.conversation_id
    LEFT JOIN contacts c ON c.id = conv.contact_id LEFT JOIN channels ch ON ch.id = conv.channel_id
    LEFT JOIN departments d ON d.id = conv.department_id $where ORDER BY cc.created_at DESC LIMIT 25", $params);

$ok = true;
if ($total < 1) { echo "FAIL: total\n"; $ok = false; }
if ($avg === null) { echo "FAIL: avg\n"; $ok = false; }
if (count($recent) < 1) { echo "FAIL: recent\n"; $ok = false; }
echo "total=$total avg=".(round((float)$avg,2))." dist=".count($dist)." recent=".count($recent)."\n";

// clean up
$db->delete('conversation_csats', 'conversation_id = ?', [$convId]);
$db->delete('conversations', 'id = ?', [$convId]);
Contact::delete($contactId);

echo $ok ? "PASS: relatorio CSAT OK\n" : "SOME FAILURES\n";
