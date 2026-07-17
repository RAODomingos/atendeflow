<?php
require __DIR__ . '/../bootstrap.php';

use App\Models\Contact;
use App\Core\Database;

Database::connect();
$db = Database::getInstance();

// Cria contatos de teste
$src = Contact::create([
    'name' => 'Fonte Teste Merge',
    'email' => 'fonte@example.com',
    'phone' => '+5511999990001',
    'company' => 'Empresa Fonte',
]);
$tgt = Contact::create([
    'name' => 'Destino Teste Merge',
    'phone' => '+5511999990002',
]);

// Telefones/emails extras na fonte
Contact::addPhone($src, '+5511999990003', 'trabalho', false);
Contact::addEmail($src, 'extra@fonte.com', 'pessoal', false);

// Conversa na fonte
$channel = $db->fetch("SELECT id FROM channels LIMIT 1");
$convId = $db->insert('conversations', [
    'contact_id' => $src,
    'channel_id' => $channel ? $channel['id'] : null,
    'status' => 'open',
    'subject' => 'conv fonte',
    'public_id' => bin2hex(random_bytes(18)),
]);

// Tags na fonte (pega uma tag existente ou cria)
$tag = $db->fetch("SELECT id FROM tags LIMIT 1");
if (!$tag) { $tagId = $db->insert('tags', ['name' => 'merge_test', 'color' => '#ff0000']); } else { $tagId = $tag['id']; }
$db->insert('contact_tags', ['contact_id' => $src, 'tag_id' => $tagId]);

// Mescla fonte -> destino
Contact::merge($src, $tgt);

$ok = true;
// conversa realocada
$c = $db->fetch("SELECT contact_id FROM conversations WHERE id = ?", [$convId]);
if ($c['contact_id'] != $tgt) { echo "FAIL: conversa nao realocada\n"; $ok = false; }

// telefone extra foi para destino
if (!$db->fetch("SELECT 1 FROM contact_phones WHERE contact_id = ? AND phone = ?", [$tgt, '+5511999990003'])) { echo "FAIL: telefone extra nao mesclado\n"; $ok = false; }

// email extra foi para destino
if (!$db->fetch("SELECT 1 FROM contact_emails WHERE contact_id = ? AND email = ?", [$tgt, 'extra@fonte.com'])) { echo "FAIL: email extra nao mesclado\n"; $ok = false; }

// tag mesclada
if (!$db->fetch("SELECT 1 FROM contact_tags WHERE contact_id = ? AND tag_id = ?", [$tgt, $tagId])) { echo "FAIL: tag nao mesclada\n"; $ok = false; }

// company preenchido no destino (ausente antes)
$dst = Contact::find($tgt);
if (($dst['company'] ?? '') !== 'Empresa Fonte') { echo "FAIL: campo company nao preenchido\n"; $ok = false; }

// fonte removida
if (Contact::find($src)) { echo "FAIL: fonte nao removida\n"; $ok = false; }

// clean up
$db->delete('conversations', 'id = ?', [$convId]);
$db->delete('contact_tags', 'contact_id = ?', [$tgt]);
Contact::delete($tgt);

echo $ok ? "PASS: Contact::merge OK\n" : "SOME FAILURES\n";
