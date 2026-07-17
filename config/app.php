<?php

return [
    'name' => env('APP_NAME', 'AtendeFlow'),
    'env' => env('APP_ENV', 'production'),
    'url' => env('APP_URL', ''),
    'debug' => env('APP_DEBUG', false),
    'session_lifetime' => (int) env('SESSION_LIFETIME', 120),
    'upload_max_size' => (int) env('UPLOAD_MAX_SIZE', 10485760),
    'upload_path' => env('UPLOAD_PATH', 'storage/uploads'),
    'timezone' => 'America/Sao_Paulo',
    'locale' => 'pt_BR',
];
