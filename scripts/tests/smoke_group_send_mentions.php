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

echo $ok ? "PASS: group send members OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
