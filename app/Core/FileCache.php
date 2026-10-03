<?php

namespace App\Core;

/**
 * Cache persistente em arquivos (storage/cache/data), com TTL e flock.
 * Para dados compartilháveis entre requisições (ex.: dashboard, 30s).
 */
class FileCache
{
    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.cache';
    }

    public static function get(string $key): mixed
    {
        $f = self::file($key);
        if (!is_file($f)) {
            return null;
        }
        $raw = @file_get_contents($f);
        if ($raw === false) {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['expires'], $entry['data'])) {
            return null;
        }
        if ($entry['expires'] < time()) {
            @unlink($f);
            return null;
        }
        return $entry['data'];
    }

    public static function set(string $key, mixed $data, int $ttlSeconds): void
    {
        $fp = @fopen(self::file($key), 'c+');
        if (!$fp) {
            return;
        }
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, json_encode(['expires' => time() + $ttlSeconds, 'data' => $data]));
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    public static function remember(string $key, callable $callback, int $ttlSeconds): mixed
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        self::set($key, $value, $ttlSeconds);
        return $value;
    }

    public static function forget(string $key): void
    {
        @unlink(self::file($key));
    }
}
