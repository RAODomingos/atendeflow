<?php
require __DIR__ . '/../bootstrap.php';

use App\Controllers\WhatsAppGroupController;
use App\Services\WhatsAppService;

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

echo $ok ? "PASS: group send members OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
