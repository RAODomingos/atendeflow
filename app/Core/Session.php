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
            $cookieLifetime = (int) env('SESSION_LIFETIME', 120) * 60;
            // O GC do PHP não pode ser mais curto que nosso timeout de
            // inatividade (em produção o php.ini costuma ter 1440s): senão o
            // arquivo da sessão é apagado antes do nosso last_activity vencer.
            // Só aumenta, nunca encurta (save_path pode ser compartilhado).
            if ($cookieLifetime > 0) {
                $curGc = (int) ini_get('session.gc_maxlifetime');
                if ($curGc <= 0 || $curGc < $cookieLifetime) {
                    @ini_set('session.gc_maxlifetime', (string) $cookieLifetime);
                }
            }
            session_set_cookie_params([
                'lifetime' => $cookieLifetime,
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

            // Cookie deslizante: o PHP só emite o cookie de sessão na criação
            // dela — NÃO renova a validade a cada request. Sem esta reemissão,
            // o navegador apaga o cookie exatamente `lifetime` após o LOGIN,
            // mesmo com uso contínuo ("desloga sozinho usando o sistema").
            if ($lifetime > 0 && !headers_sent()
                && session_status() === PHP_SESSION_ACTIVE
                && isset($_COOKIE[session_name()]) && $_SESSION !== []
            ) {
                setcookie(session_name(), session_id(), [
                    'expires' => time() + $lifetime,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => $isHttps,
                ]);
            }
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
