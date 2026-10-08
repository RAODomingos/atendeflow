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

// 1. Contrato: interface declara sendContact
check(method_exists(WhatsAppProviderInterface::class, 'sendContact'), 'interface tem sendContact');

// 2. Providers implementam
$waha = new WahaProvider();
$uaz = new UazapiProvider();
check(method_exists($waha, 'sendContact'), 'waha tem sendContact');
check(method_exists($uaz, 'sendContact'), 'uazapi tem sendContact');

// 3. Parse inbound de contato (Uazapi)
$in = $uaz->parseWebhook([
    'EventType' => 'messages',
    'instanceName' => 'test-inst',
    'data' => [
        'key' => ['remoteJid' => '5511999998888@s.whatsapp.net', 'id' => 'CT1', 'fromMe' => false],
        'pushName' => 'João',
        'messageType' => 'ContactMessage',
        'message' => [
            'sender' => '5511999998888@c.us',
            'contact' => ['name' => 'Maria', 'phone' => '5511988887777'],
        ],
    ],
]);
check($in && $in->type === 'contact', 'uazapi parse inbound contact');

// 4. Parse inbound de contato (WAHA)
$inW = $waha->parseWebhook([
    'event' => 'message',
    'session' => 'test-session',
    'payload' => [
        'id' => 'WCT1',
        'from' => '5511999998888@c.us',
        'body' => '',
        'type' => 'contact',
        '_data' => ['notifyName' => 'João', 'contactVcard' => "BEGIN:VCARD\nFN:Maria\nTEL:5511988887777\nEND:VCARD"],
    ],
]);
check($inW && $inW->type === 'contact', 'waha parse inbound contact');

// 5. Validação compartilhada: telefone <8 dígitos não é compartilhável
check(\App\Services\WhatsApp\IncomingMessage::isShareableContact(['name' => 'X', 'phone' => '123']) === false, 'contato telefone curto rejeitado');
check(\App\Services\WhatsApp\IncomingMessage::isShareableContact(['name' => 'Maria', 'phone' => '5511988887777']) === true, 'contato válido aceito');

// 6. vCard gigante: ainda vira contact com telefone extraído
$big = "BEGIN:VCARD\nFN:Big\nTEL:5511988887777\nNOTE:" . str_repeat('x', 3000) . "\nEND:VCARD";
$inBig = $uaz->parseWebhook([
    'EventType' => 'messages',
    'instanceName' => 'test-inst',
    'data' => [
        'key' => ['remoteJid' => '5511999998888@s.whatsapp.net', 'id' => 'CTBIG', 'fromMe' => false],
        'pushName' => 'João',
        'messageType' => 'ContactMessage',
        'message' => ['sender' => '5511999998888@c.us', 'contact' => ['vcard' => $big]],
    ],
]);
check($inBig && $inBig->type === 'contact', 'vcard gigante vira contact');

// 7. Contato sem nome/telefone mas com tipo contato: fallback p/ remetente
$inEmpty = $waha->parseWebhook([
    'event' => 'message',
    'session' => 'test-session',
    'payload' => [
        'id' => 'WCTE', 'from' => '5511999998888@c.us', 'body' => '',
        'type' => 'contact',
        '_data' => ['notifyName' => 'João'],
    ],
]);
check($inEmpty && $inEmpty->type === 'contact', 'contato vazio vira contact com fallback');

// 8. Fresh install: schema.sql já inclui 'contact' no ENUM de messages
$schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
check(preg_match("/CREATE TABLE messages \(.*?`type` ENUM\([^)]*'contact'[^)]*\)/s", $schema) === 1
    || preg_match("/CREATE TABLE messages \(.*?type ENUM\([^)]*'contact'[^)]*\)/s", $schema) === 1, 'schema.sql inclui contact');

echo $ok ? "PASS: contact contrato OK\n" : "SOME FAILURES\n";
exit($ok ? 0 : 1);
