<?php

// Parte de app/Core/Helper.php — funções de view.
// Carregado automaticamente pelo Helper.php (não incluir diretamente).

function session(string|array|null $key = null, mixed $default = null): mixed
{
    \App\Core\Session::start();
    if ($key === null) {
        return new class {
            public function has(string $k): bool { return \App\Core\Session::has($k); }
            public function get(string $k, mixed $d = null): mixed { return \App\Core\Session::get($k, $d); }
            public function set(string $k, mixed $v): void { \App\Core\Session::set($k, $v); }
            public function flash(string $k, mixed $v): void { \App\Core\Session::setFlash($k, $v); }
            public function getFlash(string $k, mixed $d = null): mixed { return \App\Core\Session::getFlash($k, $d); }
        };
    }
    if (is_array($key)) {
        foreach ($key as $k => $v) { \App\Core\Session::set($k, $v); }
        return null;
    }
    return \App\Core\Session::get($key, $default);
}

function csrf_token(): string
{
    $token = \App\Core\Session::get('csrf_token');
    if (!$token) {
        $token = bin2hex(random_bytes(32));
        \App\Core\Session::set('csrf_token', $token);
    }
    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    return \App\Core\Session::getFlash('old_' . $key, $default);
}

function error(string $key, mixed $default = null): mixed
{
    $errors = \App\Core\Session::getFlash('errors', []);
    return $errors[$key][0] ?? $default;
}

function has_error(string $key): bool
{
    $errors = \App\Core\Session::getFlash('errors', []);
    return isset($errors[$key]);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
