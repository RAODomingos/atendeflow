<?php

/**
 * Provider do whatsmeow (bridge Go) — contrato HTTP e parsing de webhook.
 *
 * Roda sozinho:  php tests/whatsmeow_provider.php
 * Ou pelo runner: php tests/run.php (faz require deste arquivo)
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Services\WhatsApp\IncomingMessage;
use App\Services\WhatsApp\WhatsAppHttpClient;
use App\Services\WhatsApp\WhatsmeowProvider;

$standalone = !function_exists('check');

if ($standalone) {
    $passes = 0;
    $failures = 0;
    function check(string $name, bool $cond): void
    {
        global $passes, $failures;
        if ($cond) {
            $passes++;
            echo "ok - {$name}\n";
        } else {
            $failures++;
            echo "FAIL - {$name}\n";
        }
    }
}

/**
 * Cliente HTTP falso: registra as chamadas e devolve respostas prontas.
 */
class FakeBridgeClient extends WhatsAppHttpClient
{
    /** @var array<int, array{method:string,path:string,body:mixed,headers:array}> */
    public array $calls = [];

    /** @var array<string, array{status:int,body:mixed,raw:string}> */
    public array $responses = [];

    public function __construct()
    {
        parent::__construct('http://bridge.test');
    }

    public function request(string $method, string $path, array|object|null $body = null, array $headers = []): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];
        $key = $method . ' ' . $path;
        if (isset($this->responses[$key])) {
            return $this->responses[$key];
        }
        return ['status' => 200, 'body' => [], 'raw' => '{}'];
    }

    public function lastCall(): ?array
    {
        return $this->calls[count($this->calls) - 1] ?? null;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}

function bridge(string $method, string $path, array $body = [], int $status = 200): array
{
    return ['status' => $status, 'body' => $body, 'raw' => json_encode($body)];
}

$connection = ['instance_name' => 'canal-vendas', 'webhook_secret' => 'segredo'];

function providerWith(FakeBridgeClient $client): WhatsmeowProvider
{
    return new WhatsmeowProvider($client, ['base_url' => 'http://bridge.test', 'bridge_token' => 'x']);
}

// 1) Ciclo de vida da sessão.
$client = new FakeBridgeClient();
$client->responses['POST /sessions'] = bridge('POST', '/sessions', ['id' => 'canal-vendas', 'status' => 'disconnected']);
$provider = providerWith($client);
$init = $provider->initConnection(['instance_name' => 'canal-vendas']);
check('sessao: initConnection cria a sessao na bridge', $init['provider_id'] === 'canal-vendas');
check(
    'sessao: initConnection usa o instance_name',
    ($client->lastCall()['body']['name'] ?? null) === 'canal-vendas'
);

$client->responses['GET /sessions/canal-vendas/qr'] = bridge('GET', '/sessions/canal-vendas/qr', [
    'qr_code' => 'data:image/png;base64,AAA',
    'status' => 'waiting_qr',
]);
$connect = $provider->connect($connection);
check('sessao: connect devolve o QR', $connect['qr_code'] === 'data:image/png;base64,AAA');

$client->responses['GET /sessions/canal-vendas/status'] = bridge('GET', '/sessions/canal-vendas/status', [
    'status' => 'connected',
    'phone_number' => '5511999998888',
]);
$status = $provider->getStatus($connection);
check(
    'sessao: getStatus devolve estado e numero',
    $status['status'] === 'connected' && $status['phone_number'] === '5511999998888'
);

$provider->disconnect($connection);
check(
    'sessao: disconnect chama o endpoint de desconexao',
    ($client->lastCall()['method'] ?? '') === 'POST'
        && ($client->lastCall()['path'] ?? '') === '/sessions/canal-vendas/disconnect'
);
$provider->deleteConnection($connection);
check(
    'sessao: deleteConnection apaga a sessao',
    ($client->lastCall()['method'] ?? '') === 'DELETE'
        && ($client->lastCall()['path'] ?? '') === '/sessions/canal-vendas'
);

// 2) Envio de texto e mídia.
$client = new FakeBridgeClient();
$client->responses['POST /sessions/canal-vendas/send'] = bridge('POST', '/sessions/canal-vendas/send', [
    'message_id' => 'MSG-1',
]);
$provider = providerWith($client);
$sent = $provider->send($connection, '+55 (11) 99999-8888', 'text', 'olá', ['quoted_id' => 'Q1']);
check('envio: texto devolve o id da mensagem', $sent['provider_message_id'] === 'MSG-1');
check(
    'envio: telefone é normalizado para digitos',
    ($client->lastCall()['body']['to'] ?? null) === '5511999998888'
);
check(
    'envio: citacao nativa vai em options.quoted_id',
    ($client->lastCall()['body']['options']['quoted_id'] ?? null) === 'Q1'
);

$tmp = tempnam(sys_get_temp_dir(), 'wa');
file_put_contents($tmp, 'conteudo-do-arquivo');
$client->responses = [];
$sent = $provider->send($connection, '5511999998888', 'image', json_encode(['path' => $tmp, 'name' => 'foto.png']), ['caption' => 'olá']);
$media = $client->lastCall()['body']['media'] ?? [];
check(
    'envio: media local vai em base64 (a bridge nao alcanca a URL do app)',
    ($media['encoding'] ?? null) === 'base64'
        && base64_decode((string) ($media['data'] ?? '')) === 'conteudo-do-arquivo'
);
check('envio: legenda vai em options.caption', ($client->lastCall()['body']['options']['caption'] ?? null) === 'olá');
@unlink($tmp);

// 3) Grupo com menções.
$client = new FakeBridgeClient();
$client->responses['POST /sessions/canal-vendas/groups/120363%40g.us/text'] = bridge('POST', '', ['message_id' => 'G1']);
$provider = providerWith($client);
$group = $provider->sendGroupText($connection, '120363', 'oi', ['5511888887777', 'all', '9999@s.whatsapp.net']);
check(
    'grupo: mencoes sao normalizadas e "all" preservado',
    ($group['provider_message_id'] ?? null) === 'G1'
        && ($client->lastCall()['body']['mentions'] ?? []) === ['5511888887777@s.whatsapp.net', 'all', '9999@s.whatsapp.net']
);

// 4) Menu e lista: nativo quando a bridge entrega, texto numerado quando não.
$client = new FakeBridgeClient();
$client->responses['POST /sessions/canal-vendas/interactive/buttons'] = bridge('POST', '', [
    'message_id' => 'B1',
    'native' => true,
]);
$provider = providerWith($client);
$buttons = $provider->sendButton($connection, '5511999998888', 'Escolha', [
    ['id' => 'a', 'label' => 'Sim'],
    ['id' => 'b', 'label' => 'Nao'],
]);
check('menu: nativo devolve o id da mensagem', ($buttons['provider_message_id'] ?? null) === 'B1');

$client = new FakeBridgeClient();
$client->responses['POST /sessions/canal-vendas/interactive/buttons'] = bridge('POST', '', ['native' => false]);
$client->responses['POST /sessions/canal-vendas/send'] = bridge('POST', '', ['message_id' => 'B2']);
$provider = providerWith($client);
$buttons = $provider->sendButton($connection, '5511999998888', 'Escolha', [
    ['id' => 'a', 'label' => 'Sim'],
    ['id' => 'b', 'label' => 'Nao'],
]);
$fallbackText = (string) ($client->lastCall()['body']['text'] ?? '');
check('menu: sem interativo nativo cai para texto numerado', $fallbackText === "Escolha\n\n1 - Sim\n2 - Nao");
check('menu: o fallback devolve o id da mensagem enviada', ($buttons['provider_message_id'] ?? null) !== null);

$client = new FakeBridgeClient();
$client->responses['POST /sessions/canal-vendas/interactive/list'] = bridge('POST', '', ['native' => false]);
$provider = providerWith($client);
$provider->sendList($connection, '5511999998888', 'O que voce precisa', 'Servicos', [
    ['id' => 's1', 'label' => 'Suporte'],
]);
$listText = (string) ($client->lastCall()['body']['text'] ?? '');
check('lista: sem interativo nativo cai para texto numerado com titulo', $listText === "O que voce precisa\n\nServicos:\n1 - Suporte");

// 5) Ações em mensagens.
$client = new FakeBridgeClient();
$provider = providerWith($client);
check('mensagem: editar chama o endpoint de edicao', $provider->editMessage($connection, 'M1', 'novo texto') === true);
check(
    'mensagem: editar envia o texto',
    ($client->lastCall()['path'] ?? '') === '/sessions/canal-vendas/messages/M1/edit'
        && ($client->lastCall()['body']['text'] ?? null) === 'novo texto'
);
check('mensagem: apagar p/ todos', $provider->deleteMessage($connection, 'M1') === true);
check(
    'mensagem: apagar usa o endpoint de revogacao',
    ($client->lastCall()['path'] ?? '') === '/sessions/canal-vendas/messages/M1/delete'
);
check('mensagem: reagir', $provider->sendReaction($connection, 'M1', '👍') === true);
check(
    'mensagem: reagir envia o emoji',
    ($client->lastCall()['path'] ?? '') === '/sessions/canal-vendas/messages/M1/react'
        && ($client->lastCall()['body']['reaction'] ?? null) === '👍'
);

// 6) Contatos e grupos.
$client = new FakeBridgeClient();
$client->responses['GET /sessions/canal-vendas/resolve/225962742546599%40lid'] = bridge('GET', '', [
    'phone' => '5511999998888',
    'lid' => '225962742546599',
]);
$provider = providerWith($client);
check(
    'lid: resolvePhone devolve o telefone real',
    $provider->resolvePhone($connection, '225962742546599@lid') === '5511999998888'
);

$client = new FakeBridgeClient();
$client->responses['GET /sessions/canal-vendas/groups/120363%40g.us/participants'] = bridge('GET', '', [
    ['phone' => '5511999998888', 'lid' => null, 'name' => 'Ana', 'is_admin' => true],
    ['phone' => '5522992036639', 'lid' => '999999999999993', 'name' => 'Bia', 'is_admin' => false],
]);
$provider = providerWith($client);
$participants = $provider->fetchGroupParticipants($connection, '120363');
check(
    'grupo: participantes no shape padrao (phone/lid/name/is_admin)',
    count($participants) === 2
        && $participants[0]['phone'] === '5511999998888'
        && $participants[0]['is_admin'] === true
        && $participants[1]['lid'] === '999999999999993'
);

$client = new FakeBridgeClient();
$client->responses['GET /sessions/canal-vendas/contacts/5511999998888/avatar'] = bridge('GET', '', [
    'url' => 'http://bridge.test/avatar/1',
]);
$provider = providerWith($client);
check(
    'contato: getProfilePicture devolve a url',
    $provider->getProfilePicture($connection, '5511999998888') === 'http://bridge.test/avatar/1'
);

$client = new FakeBridgeClient();
$client->responses['GET /sessions/canal-vendas/media/M1'] = bridge('GET', '', [
    'url' => 'http://bridge.test/media/M1',
    'mime' => 'image/jpeg',
    'name' => 'foto.jpg',
]);
$provider = providerWith($client);
$media = $provider->downloadMedia($connection, 'M1');
check(
    'midia: downloadMedia devolve url, mime e nome',
    ($media['fileURL'] ?? null) === 'http://bridge.test/media/M1'
        && ($media['mime'] ?? null) === 'image/jpeg'
        && ($media['name'] ?? null) === 'foto.jpg'
);

// 7) Webhook: o envelope da bridge vira IncomingMessage.
$client = new FakeBridgeClient();
$provider = providerWith($client);

$incoming = $provider->parseWebhook([
    'provider' => 'whatsmeow',
    'event' => 'message',
    'session' => 'canal-vendas',
    'message' => [
        'id' => 'W1',
        'chat_id' => '5511999998888@s.whatsapp.net',
        'from' => '5511999998888@s.whatsapp.net',
        'from_phone' => '5511999998888',
        'from_me' => false,
        'is_group' => false,
        'type' => 'text',
        'body' => 'ola',
        'timestamp' => 1761290000,
        'sender_name' => 'Maria',
    ],
]);
check(
    'webhook: texto 1:1 vira mensagem com o telefone do cliente',
    $incoming instanceof IncomingMessage
        && $incoming->from === '5511999998888'
        && $incoming->content === 'ola'
        && $incoming->providerId === 'canal-vendas'
);

$incoming = $provider->parseWebhook([
    'provider' => 'whatsmeow',
    'event' => 'message',
    'session' => 'canal-vendas',
    'message' => [
        'id' => 'W2',
        'chat_id' => '120363@g.us',
        'is_group' => true,
        'from_phone' => '5511888887777',
        'participant_phone' => '5511888887777',
        'participant' => '5511888887777@s.whatsapp.net',
        'type' => 'text',
        'body' => 'oi grupo',
    ],
]);
check(
    'webhook: mensagem de grupo carrega o participante e o extra is_group',
    $incoming instanceof IncomingMessage
        && $incoming->from === '5511888887777'
        && ($incoming->extra['is_group'] ?? false) === true
        && ($incoming->extra['group_jid'] ?? '') === '120363@g.us'
);

$incoming = $provider->parseWebhook([
    'provider' => 'whatsmeow',
    'event' => 'message',
    'session' => 'canal-vendas',
    'message' => [
        'id' => 'W3',
        'from_phone' => '5511999998888',
        'type' => 'reaction',
        'reaction' => ['emoji' => '👍', 'parent_message_id' => 'P1'],
    ],
]);
check(
    'webhook: reacao vira o mesmo JSON do UAZAPI',
    $incoming instanceof IncomingMessage
        && str_contains((string) $incoming->content, '"reaction":"👍"')
        && str_contains((string) $incoming->content, '"parent_message_id":"P1"')
);

$incoming = $provider->parseWebhook([
    'provider' => 'whatsmeow',
    'event' => 'message',
    'session' => 'canal-vendas',
    'message' => [
        'id' => 'W4',
        'from_phone' => '5511999998888',
        'type' => 'image',
        'media' => ['url' => 'http://bridge.test/media/W4', 'mime' => 'image/jpeg', 'filename' => 'foto.jpg'],
        'caption' => 'olá',
    ],
]);
check(
    'webhook: midia vira IncomingMessage::media',
    $incoming instanceof IncomingMessage
        && $incoming->type === 'image'
        && ($incoming->mediaUrl ?? '') === 'http://bridge.test/media/W4'
        && ($incoming->mediaMime ?? '') === 'image/jpeg'
);

$incoming = $provider->parseWebhook([
    'provider' => 'whatsmeow',
    'event' => 'message',
    'session' => 'canal-vendas',
    'message' => [
        'id' => 'W5',
        'from_phone' => '5511999998888',
        'type' => 'contact',
        'contact' => ['name' => 'Ana', 'phone' => '5511999998888', 'organization' => 'Acme'],
    ],
]);
check(
    'webhook: cartao de contato vira IncomingMessage::contact',
    $incoming instanceof IncomingMessage && $incoming->type === 'contact'
);

$incoming = $provider->parseWebhook([
    'provider' => 'whatsmeow',
    'event' => 'message',
    'session' => 'canal-vendas',
    'message' => [
        'id' => 'W6',
        'from_phone' => '5511999998888',
        'from_me' => true,
        'type' => 'text',
        'body' => 'eu',
    ],
]);
check('webhook: mensagem enviada pelo proprio numero e ignorada', $incoming === null);

$incoming = $provider->parseWebhook([
    'provider' => 'whatsmeow',
    'event' => 'message',
    'session' => 'canal-vendas',
    'message' => [
        'id' => 'W7',
        'from_lid' => '225962742546599',
        'from' => '225962742546599@lid',
        'type' => 'text',
        'body' => 'oi',
    ],
]);
check(
    'webhook: so com LID, o extra original_from marca o @lid para o service resolver',
    $incoming instanceof IncomingMessage
        && $incoming->from === '225962742546599'
        && ($incoming->extra['original_from'] ?? '') === '225962742546599@lid'
);

// 8) Presença (extras usados pelo WhatsAppService).
$client = new FakeBridgeClient();
$provider = providerWith($client);
$provider->sendSeen($connection, '5511999998888@s.whatsapp.net');
check(
    'presenca: marcar visto',
    ($client->lastCall()['path'] ?? '') === '/sessions/canal-vendas/messages/seen'
);
$provider->startTyping($connection, '5511999998888@s.whatsapp.net');
check(
    'presenca: digitando',
    ($client->lastCall()['path'] ?? '') === '/sessions/canal-vendas/typing'
        && ($client->lastCall()['body']['state'] ?? null) === 'composing'
);
$provider->stopTyping($connection, '5511999998888@s.whatsapp.net');
check(
    'presenca: parar de digitar',
    ($client->lastCall()['body']['state'] ?? null) === 'paused'
);

if ($standalone) {
    echo "\n$passes passed, $failures failed\n";
    exit($failures > 0 ? 1 : 0);
}
