<?php

namespace App\Core;

class CacheHelper
{
    private static array $store = [];
    private static array $timers = [];

    /**
     * Get a value from the in-memory (request-level) cache.
     */
    public static function get(string $key): mixed
    {
        $entry = self::$store[$key] ?? null;
        if ($entry === null) return null;
        if ($entry['ttl'] !== null && microtime(true) > $entry['ttl']) {
            unset(self::$store[$key]);
            return null;
        }
        return $entry['data'];
    }

    /**
     * Set a value in the in-memory cache with optional TTL in seconds.
     */
    public static function set(string $key, mixed $data, ?int $ttlSeconds = null): void
    {
        self::$store[$key] = [
            'data' => $data,
            'ttl'  => $ttlSeconds !== null ? microtime(true) + $ttlSeconds : null,
        ];
    }

    /**
     * Remember — returns cached value or calls $callback to generate it.
     */
    public static function remember(string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        $cached = self::get($key);
        if ($cached !== null) return $cached;
        $value = $callback();
        self::set($key, $value, $ttlSeconds);
        return $value;
    }

    /**
     * Delete a key from cache.
     */
    public static function forget(string $key): void
    {
        unset(self::$store[$key]);
    }

    /**
     * Clear entire cache.
     */
    public static function flush(): void
    {
        self::$store = [];
    }

    /**
     * Set HTTP cache headers for public caching (browser / CDN).
     * $maxAge in seconds.
     */
    public static function setHttpCache(int $maxAge = 300): void
    {
        if (!headers_sent()) {
            header("Cache-Control: public, max-age={$maxAge}, must-revalidate");
            header("Expires: " . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
            header("Pragma: cache");
        }
    }

    /**
     * Set HTTP cache headers for private caching (per-user).
     */
    public static function setHttpPrivateCache(int $maxAge = 60): void
    {
        if (!headers_sent()) {
            header("Cache-Control: private, max-age={$maxAge}, must-revalidate");
        }
    }

    /**
     * Disable caching for sensitive/ dynamic pages.
     */
    public static function disableHttpCache(): void
    {
        if (!headers_sent()) {
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Pragma: no-cache");
            header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        }
    }

    /**
     * Generate an ETag from content and return 304 Not Modified if it matches.
     * Returns true if 304 was sent.
     */
    public static function etag(string $content): bool
    {
        $etag = '"' . md5($content) . '"';
        if (!headers_sent()) {
            header("ETag: {$etag}");
        }
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            return true;
        }
        return false;
    }

    /**
     * Generate a Last-Modified header and return 304 if not modified.
     */
    public static function lastModified(int $timestamp): bool
    {
        $gm = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
        if (!headers_sent()) {
            header("Last-Modified: {$gm}");
        }
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($since !== false && $since >= $timestamp) {
                http_response_code(304);
                return true;
            }
        }
        return false;
    }
}
