<?php
require __DIR__ . '/../bootstrap.php';

use App\Services\WhatsApp\UazapiProvider;
use App\Services\WhatsApp\WahaProvider;
use App\Services\WhatsApp\WhatsAppProviderInterface;

$ok = true;
function check(bool $cond, string $msg): void {
    global $ok;
    echo ($cond ? "ok" : "FAIL") . ": $msg\n";
    if (!$cond) $ok = false;
}

// 1. Contrato: interface declara fetchGroupParticipants
check(method_exists(WhatsAppProviderInterface::class, 'fetchGroupParticipants'), 'interface tem fetchGroupParticipants');

// 2. WAHA implementa fetch
$waha = new WahaProvider();
check(method_exists($waha, 'fetchGroupParticipants'), 'waha tem fetchGroupParticipants');

// 3. sendGroupText aceita mentions (4o param opcional)
$refW = new ReflectionMethod($waha, 'sendGroupText');
check($refW->getNumberOfParameters() >= 4, 'waha sendGroupText aceita mentions');
$uaz = new UazapiProvider();
$refU = new ReflectionMethod($uaz, 'sendGroupText');
check($refU->getNumberOfParameters() >= 4, 'uazapi sendGroupText aceita mentions');

// 4. fetch retorna shape esperado (sem rede: mock via conexão inexistente deve retornar [] sem throw)
try {
    $res = $waha->fetchGroupParticipants(['instance_name' => '__none__', 'instance_token' => null], '120363012345678@g.us');
    check(is_array($res), 'fetch retorna array (vazio sem throw)');
} catch (\Throwable $e) {
    check(false, 'fetch nao deve dar throw sem rede (' . substr($e->getMessage(), 0, 80) . ')');
}

// 5. Parser Uazapi preserva participante só-telefone (sem LID)
$parsed = \App\Services\WhatsApp\UazapiProvider::mapParticipantList([
    ['phone' => '5511999998888', 'name' => 'Ana'],
    ['sender_lid' => '999999999999993@lid', 'sender_pn' => '5522992036639@s.whatsapp.net'],
    ['id' => '120363012345678@g.us', 'name' => 'Grupo'],
]);
$phones = array_column($parsed, 'phone');
check(in_array('5511999998888', $phones, true), 'participante só-telefone preservado');
check(in_array('5522992036639', $phones, true), 'par lid->phone preservado');

echo $ok ? "PASS: group members contrato OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
