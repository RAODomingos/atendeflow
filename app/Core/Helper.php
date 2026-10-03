<?php

if (!function_exists('env')) {
function env(string $key, mixed $default = null): mixed
{
    static $env = null;

    if ($env === null) {
        $envFile = __DIR__ . '/../../env/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                $env[$k] = $v;
            }
        }
    }

    return $env[$key] ?? $default;
}
}

// Helpers fatiados por tema (mesmas funções globais de antes).
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/view.php';
require_once __DIR__ . '/helpers/text.php';
require_once __DIR__ . '/helpers/uploads.php';
require_once __DIR__ . '/helpers/ui.php';
