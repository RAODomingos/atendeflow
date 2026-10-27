<?php

/**
 * Contrato compartilhado dos provedores de WhatsApp.
 *
 * Cobre as duas garantias que os fluxos do AtendeFlow dependem:
 *   1. menu/lista interativo existe em TODOS os providers (com degradação
 *      para texto numerado, que o FlowEngineService já responde por número);
 *   2. o par LID <-> telefone é normalizado igual em todo provider, para o
 *      cliente ser sempre identificado pelo número.
 *
 * Roda sozinho:  php tests/wa_contract.php
 * Ou pelo runner: php tests/run.php (faz require deste arquivo)
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Services\WhatsApp\UazapiProvider;
use App\Services\WhatsApp\WahaProvider;
use App\Services\WhatsApp\WhatsAppIds;
use App\Services\WhatsApp\WhatsAppInteractive;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppProviderInterface;

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

// 1) O contrato exige menu e lista para todo provider novo.
$iface = new ReflectionClass(WhatsAppProviderInterface::class);
check(
    'contrato: a interface exige sendButton',
    $iface->hasMethod('sendButton')
);
check(
    'contrato: a interface exige sendList',
    $iface->hasMethod('sendList')
);

// 2) Os providers que já existem atendem ao contrato.
foreach ([UazapiProvider::class, WahaProvider::class] as $providerClass) {
    check(
        "contrato: {$providerClass} implementa a interface",
        in_array(WhatsAppProviderInterface::class, class_implements($providerClass), true)
    );
    check(
        "contrato: {$providerClass} implementa sendButton",
        method_exists($providerClass, 'sendButton')
    );
    check(
        "contrato: {$providerClass} implementa sendList",
        method_exists($providerClass, 'sendList')
    );
}

// 2.1) A lista de provedores oferecidos mantem o UAZAPI e esconde o legado.
check(
    'provider: o uazapi continua na lista de provedores',
    in_array('uazapi', WhatsAppManager::availableProviders(), true)
);
check(
    'provider: o waha continua fora da lista (legado)',
    !in_array('waha', WhatsAppManager::availableProviders(), true)
);

// 3) Menu em texto numerado: é o que segura o fluxo quando o motor
//    não manda botão nativo (whatsapp-web.js, por exemplo).
check(
    'menu: texto numerado mantem a ordem das opcoes',
    WhatsAppInteractive::renderButtonsAsText(
        'Escolha uma opcao',
        [['id' => 'a', 'label' => 'Sim'], ['id' => 'b', 'label' => 'Nao']]
    ) === "Escolha uma opcao\n\n1 - Sim\n2 - Nao"
);
check(
    'menu: lista usa o titulo e numera as opcoes',
    WhatsAppInteractive::renderListAsText(
        'O que voce precisa?',
        'Servicos',
        [['id' => 's1', 'label' => 'Suporte'], ['id' => 's2', 'label' => 'Vendas']]
    ) === "O que voce precisa?\n\nServicos:\n1 - Suporte\n2 - Vendas"
);
check(
    'menu: sem opcoes devolve o texto original',
    WhatsAppInteractive::renderButtonsAsText('Sem opcoes', []) === 'Sem opcoes'
);
check(
    'menu: opcoes em string simples tambem viram texto numerado',
    WhatsAppInteractive::renderButtonsAsText('Escolha', ['Alpha', 'Beta'])
        === "Escolha\n\n1 - Alpha\n2 - Beta"
);

// 4) Numero do cliente: o par LID <-> telefone precisa sair identico
//    em qualquer provider (zapo entrega os dois JIDs em campos separados).
$pair = WhatsAppIds::splitLidAndPhone('225962742546599:93@lid', '5511999998888@s.whatsapp.net');
check(
    'lid: separa telefone e lid quando o lid vem primeiro',
    $pair['phone'] === '5511999998888' && $pair['lid'] === '225962742546599'
);
$pair = WhatsAppIds::splitLidAndPhone('5511999998888@c.us', '225962742546599@lid');
check(
    'lid: telefone tem prioridade mesmo vindo no campo alt',
    $pair['phone'] === '5511999998888' && $pair['lid'] === '225962742546599'
);
$pair = WhatsAppIds::splitLidAndPhone('225962742546599@lid', null);
check(
    'lid: sem telefone conhecido nao inventa numero',
    $pair['phone'] === null && $pair['lid'] === '225962742546599'
);
check(
    'lid: id longo sem marcador @lid e tratado como lid',
    WhatsAppIds::isLid('225962742546599') && !WhatsAppIds::isLid('5511999998888')
);

// 5) Participantes: mesmo shape em todo provider.
$mapped = WhatsAppIds::mapParticipants([
    ['phone' => '5511999998888', 'name' => 'Ana'],
    ['sender_lid' => '999999999999993@lid', 'sender_pn' => '5522992036639@s.whatsapp.net', 'name' => 'Bia'],
]);
check(
    'participantes: quem so tem telefone e preservado',
    isset($mapped[0]) && $mapped[0]['phone'] === '5511999998888' && $mapped[0]['lid'] === null
);
check(
    'participantes: quem tem lid e telefone mantem os dois',
    isset($mapped[1])
        && $mapped[1]['phone'] === '5522992036639'
        && $mapped[1]['lid'] === '999999999999993'
        && $mapped[1]['name'] === 'Bia'
);
check(
    'participantes: o helper do UAZAPI usa a mesma normalizacao',
    UazapiProvider::mapParticipantList([
        ['phone' => '5511999998888', 'name' => 'Ana'],
    ]) === WhatsAppIds::mapParticipants([
        ['phone' => '5511999998888', 'name' => 'Ana'],
    ])
);

if ($standalone) {
    echo "\n$passes passed, $failures failed\n";
    exit($failures > 0 ? 1 : 0);
}
