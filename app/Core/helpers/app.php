<?php

// Parte de app/Core/Helper.php — funções de app.
// Carregado automaticamente pelo Helper.php (não incluir diretamente).

function base_url(string $path = '/'): string
{
    static $basePath = null;

    if ($basePath === null) {
        $appUrl = env('APP_URL');
        if (!empty($appUrl)) {
            $basePath = rtrim($appUrl, '/');
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = rtrim($protocol . '://' . $host . str_replace('\\', '/', dirname(dirname($scriptName))), '/');
        }
    }

    return $basePath . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $url = base_url($path);
    // Cache-busting: ?v=<mtime> invalida o cache agressivo do .htaccess
    // a cada deploy/edição, sem renomear arquivos.
    $local = dirname(__DIR__, 3) . '/public/' . ltrim($path, '/');
    if (is_file($local)) {
        $url .= '?v=' . filemtime($local);
    }
    return $url;
}

function url(string $path = '/'): string
{
    return base_url(ltrim($path, '/'));
}

function route(string $name): string
{
    $routes = [
        'login' => '/login',
        'logout' => '/logout',
        'dashboard' => '/',
        'inbox' => '/inbox',
        'inbox.mine' => '/inbox/mine',
        'inbox.unassigned' => '/inbox/unassigned',
        'inbox.new' => '/inbox/new',
        'contacts' => '/contacts',
        'contacts.create' => '/contacts/create',
        'departments' => '/departments',
        'flows' => '/flows',
        'flows.create' => '/flows/create',
        'users' => '/users',
        'users.create' => '/users/create',
        'profile' => '/profile',
    ];
    return base_url($routes[$name] ?? '/');
}
