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
                'base_url' => env('WAHA_BASE_URL', 'http://localhost:3000'),
                'api_key' => env('WAHA_API_KEY', ''),
            ],
            'uazapi' => [
                // URL base da API Uazapi (ex.: https://free.uazapi.com)
                'base_url' => env('UAZAPI_BASE_URL', 'https://free.uazapi.com'),
                // Token de administrador para criar instâncias
                'admin_token' => env('UAZAPI_ADMIN_TOKEN', ''),
            ],
        ],
    ],
    'webchat' => [
        'session_lifetime' => 1440,
        'polling_interval' => 3000,
    ],
];
