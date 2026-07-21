<?php

return [
    'whatsapp' => [
        // Provedor ativo por padrão. Troque para "evolution", "twilio", etc.
        // ao registrar um novo provedor em App\Services\WhatsApp\WhatsAppManager.
        'provider' => env('WHATSAPP_PROVIDER', 'waha'),

        // Caminho do webhook público que recebe as mensagens dos provedores.
        'webhook_path' => '/webhooks/whatsapp',

        // Intervalo (s) para tentar reconectar instâncias desconectadas.
        'reconnect_interval' => 30,

        // Configuração por provedor. Cada chave aqui corresponde a um provedor
        // registrado no WhatsAppManager.
        'providers' => [
            'waha' => [
                // URL base da API WAHA (sem barra final). Ex.: http://localhost:3000
                'base_url' => env('WAHA_BASE_URL', 'http://localhost:3000'),
                // API Key do WAHA (necessária para todas as requisições).
                'api_key' => env('WAHA_API_KEY', ''),
            ],
        ],
    ],
    'webchat' => [
        'session_lifetime' => 1440,
        'polling_interval' => 3000,
    ],
];
