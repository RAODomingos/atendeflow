<?php
require __DIR__ . '/../bootstrap.php';

use App\Controllers\WhatsAppGroupController;
use App\Core\Database;
use App\Models\WhatsAppLidMap;
use App\Services\WhatsAppService;

Database::connect();
$db = Database::getInstance();

$ok = true;
function check(bool $cond, string $msg): void {
    global $ok;
    echo ($cond ? "ok" : "FAIL") . ": $msg\n";
    if (!$cond) $ok = false;
}

// 1. Service aceita mentions (3o param opcional)
$ref = new ReflectionMethod(WhatsAppService::class, 'sendGroupMessage');
check($ref->getNumberOfParameters() >= 3, 'sendGroupMessage aceita mentions');

// 2. Controller tem endpoint members
check(method_exists(WhatsAppGroupController::class, 'members'), 'controller tem members()');

// 3. Rota registrada
$routesSrc = file_get_contents(__DIR__ . '/../../routes/web.php');
check(str_contains($routesSrc, "whatsapp/groups/{id}/members"), 'rota members registrada');

// 4. send() lê mentions do request
$ctrlSrc = file_get_contents(__DIR__ . '/../../app/Controllers/WhatsAppGroupController.php');
check(str_contains($ctrlSrc, "input('mentions')"), 'send() lê mentions do request');

// 5. Membros: LID mapeado resolve p/ telefone; sem mapa NÃO funde LID em phone
WhatsAppLidMap::learn('999999999999991', '5511988887777');
$mapped = WhatsAppGroupController::mapMembersForDisplay([
    ['phone' => '', 'lid' => '999999999999991', 'name' => 'Zé', 'is_admin' => false],
    ['phone' => '', 'lid' => '999999999999992', 'name' => null, 'is_admin' => true],
    ['phone' => '5511999998888', 'lid' => null, 'name' => 'Ana', 'is_admin' => false],
]);
check(($mapped[0]['phone'] ?? '') === '5511988887777', 'lid mapeado resolve telefone');
check(($mapped[1]['phone'] ?? 'X') === '' && ($mapped[1]['lid'] ?? '') === '999999999999992', 'lid sem mapa nao vira phone (lid preservado)');
check(($mapped[2]['phone'] ?? '') === '5511999998888', 'telefone direto preservado');
$db->delete('whatsapp_lid_map', 'lid_digits = ?', ['999999999999991']);

// 6. Anti duplo-submit: submit do form de envio desabilita o botão
$groupShowSrc = file_get_contents(__DIR__ . '/../../app/Views/whatsapp/group_show.php');
check(str_contains($groupShowSrc, "querySelector('button[type=submit]')") && str_contains($groupShowSrc, 'btn.disabled = true'), 'form do grupo evita duplo submit');

// 7. Roteamento inbox→grupo: seam testável no service
check(method_exists(\App\Services\WhatsAppService::class, 'sendGroupConversationMessage'), 'service tem sendGroupConversationMessage');

// 8. Endpoint de membros para a conversa do inbox
check(method_exists(WhatsAppGroupController::class, 'members'), 'controller base tem members()');
check(method_exists(\App\Controllers\InboxController::class, 'groupMembers'), 'inbox tem groupMembers()');
$routesSrc = file_get_contents(__DIR__ . '/../../routes/web.php');
check(str_contains($routesSrc, "inbox/{id}/group-members"), 'rota inbox group-members registrada');

// 9. Autocomplete de @ no composer do painel (conversa de grupo)
$panelSrc = file_get_contents(__DIR__ . '/../../app/Views/inbox/panel.php');
check(str_contains($panelSrc, 'CONV_GROUP_ID'), 'painel expoe grupo da conversa');
check(str_contains($panelSrc, 'group-members') || str_contains($panelSrc, 'mentionSuggest'), 'painel tem autocomplete de mencao');

// 10. Roteamento e2e offline: conversa de grupo -> sendGroupText (falha graciosa sem rede)
$conn = $db->fetch("SELECT * FROM whatsapp_connections ORDER BY id LIMIT 1");
if ($conn) {
    $gid = $db->insert('whatsapp_groups', [
        'connection_id' => (int) $conn['id'],
        'group_jid' => '120363099988877@g.us',
        'name' => 'Roteamento Teste',
    ]);
    $ctId = \App\Models\Contact::create(['name' => 'Roteamento Teste', 'phone' => '120363099988877@g.us']);
    $convId = \App\Models\Conversation::create([
        'contact_id' => $ctId,
        'channel_id' => (int) $conn['channel_id'],
        'inbox_id' => null,
        'group_id' => (int) $gid,
        'subject' => 'Roteamento Teste',
        'status' => 'new',
        'source' => 'whatsapp_group',
    ]);
    $routeMsgId = 0;
    try {
        $routeMsgId = (new \App\Services\ConversationService())->sendMessage($convId, 'oi @5511999998888', 'text', 1);
        $res = (new \App\Services\WhatsAppService())->sendGroupConversationMessage($convId, $routeMsgId, 'oi @5511999998888', ['5511999998888']);
        $st = $db->fetch("SELECT delivery_status FROM messages WHERE id = ?", [$routeMsgId]);
        check($res === null && ($st['delivery_status'] ?? '') === 'failed', 'roteamento grupo sem rede: falha graciosa p/ retry');
    } catch (\Throwable $e) {
        check(false, 'roteamento grupo sem throw (' . substr($e->getMessage(), 0, 80) . ')');
    }
    // Limpeza
    $db->delete('messages', 'conversation_id = ?', [$convId]);
    $db->delete('conversation_events', 'conversation_id = ?', [$convId]);
    $db->delete('conversations', 'id = ?', [$convId]);
    $db->delete('contacts', 'id = ?', [$ctId]);
    $db->delete('whatsapp_groups', 'id = ?', [(int) $gid]);
}

echo $ok ? "PASS: group send members OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
