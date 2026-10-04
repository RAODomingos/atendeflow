<?php

namespace App\Core;

/**
 * Rate limiting persistente em arquivos (storage/cache/rate_limit).
 * Sem dependência de banco ou extensões: usa flock para concorrência.
 */
class RateLimiter
{
    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache/rate_limit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.json';
    }

    private static function read(string $key): array
    {
        $f = self::file($key);
        if (!is_file($f)) {
            return ['count' => 0, 'first' => time(), 'blocked_until' => 0];
        }
        $data = json_decode((string) @file_get_contents($f), true);
        if (!is_array($data)) {
            return ['count' => 0, 'first' => time(), 'blocked_until' => 0];
        }
        return $data + ['count' => 0, 'first' => time(), 'blocked_until' => 0];
    }

    private static function write(string $key, array $data): void
    {
        $f = self::file($key);
        $fp = @fopen($f, 'c+');
        if (!$fp) {
            return;
        }
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, json_encode($data));
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Registra uma falha. Retorna segundos restantes de bloqueio (>0) se
     * o limite foi atingido, ou 0 caso contrário.
     */
    public static function hit(string $key, int $maxAttempts = 5, int $windowSeconds = 600, int $blockSeconds = 900): int
    {
        $data = self::read($key);
        $now = time();
        if ($now - (int) $data['first'] > $windowSeconds) {
            $data = ['count' => 0, 'first' => $now, 'blocked_until' => 0];
        }
        $data['count'] = (int) $data['count'] + 1;
        if ($data['count'] >= $maxAttempts) {
            $data['blocked_until'] = $now + $blockSeconds;
            self::write($key, $data);
            return $blockSeconds;
        }
        self::write($key, $data);
        return 0;
    }

    /** Segundos restantes de bloqueio (0 = liberado). */
    public static function blocked(string $key): int
    {
        $data = self::read($key);
        $left = (int) $data['blocked_until'] - time();
        return $left > 0 ? $left : 0;
    }

    public static function clear(string $key): void
    {
        @unlink(self::file($key));
    }

    public static function clientKey(string $prefix): string
    {
        // REMOTE_ADDR apenas: X-Forwarded-For é forjável pelo cliente e
        // burlava o rate-limit do login. Atrás de proxy, configure o
        // proxy para sobrescrever REMOTE_ADDR.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ip = trim(explode(',', (string) $ip)[0]);
        return $prefix . ':' . $ip;
    }
}
