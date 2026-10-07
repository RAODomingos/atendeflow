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
            // Auto-detecção: funciona com QUALQUER host que aponte para o
            // projeto (atendeflow.test, localhost, ngrok, ...), na raiz ou
            // sob subpasta (/atendeflow). Atrás de proxy/túnel (ngrok), o
            // protocolo/host reais vêm dos headers X-Forwarded-*.
            $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
            if (empty($proto)) {
                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            }
            $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            // Host pode vir com porta do túnel — preserva como veio.
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            // /index.php (raiz) -> ''; /atendeflow/index.php -> '/atendeflow';
            // /foo/public/index.php (Apache c/ rewrite) -> '/foo' (o /public
            // é interno e nunca aparece na URL pública).
            $dir = str_replace('\\', '/', dirname($scriptName));
            $parent = str_replace('\\', '/', dirname($dir));
            if (str_ends_with($dir, '/public')) {
                // /public/index.php -> raiz; /foo/public/index.php -> '/foo'
                // (o /public é interno do rewrite e nunca aparece na URL).
                $base = ($parent === '/' || $parent === '.' || $parent === '') ? '' : $parent;
            } else {
                $base = ($dir === '/' || $dir === '.' || $dir === '') ? '' : $dir;
            }
            $basePath = rtrim($proto . '://' . $host . $base, '/');
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
