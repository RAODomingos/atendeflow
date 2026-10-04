<?php

namespace App\Core;

class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (!self::$started && session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params([
                'lifetime' => (int) env('SESSION_LIFETIME', 120) * 60,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $isHttps,
            ]);
            session_name('ATENDEFLOW_SESSION');
            session_start();
            self::$started = true;

            // Timeout idle server-side: sem atividade por SESSION_LIFETIME,
            // derruba a sessão (roubo de sessão perde valor rápido).
            $lifetime = (int) env('SESSION_LIFETIME', 120) * 60;
            $last = $_SESSION['last_activity'] ?? null;
            if (is_int($last) && $lifetime > 0 && (time() - $last) > $lifetime) {
                self::destroy();
                session_name('ATENDEFLOW_SESSION');
                session_start();
                self::$started = true;
            }
            $_SESSION['last_activity'] = time();
        }
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        // Apaga o cookie de sessão no navegador (espelha flags da criação).
        if (!headers_sent() && isset($_COOKIE[session_name()])) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie(session_name(), '', [
                'expires' => 1, 'path' => '/', 'httponly' => true,
                'samesite' => 'Lax', 'secure' => $isHttps,
            ]);
        }
        self::$started = false;
    }

    public static function setFlash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }
}
