<?php
// ============================================================
// config/cache.php - Simple File-based Caching System
// ============================================================

class Cache {
    private static $cacheDir;
    private static $defaultTtl = 3600; // 1 hour
    
    public static function initialize($cacheDir = null) {
        self::$cacheDir = $cacheDir ?? __DIR__ . '/../cache/';
        
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
        
        // Create .htaccess to protect cache directory
        $htaccess = self::$cacheDir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }
    
    public static function get($key, $default = null) {
        self::initialize();
        
        $file = self::getFilePath($key);
        
        if (!file_exists($file)) {
            return $default;
        }
        
        $data = unserialize(file_get_contents($file));
        
        // Check if expired
        if (time() > $data['expires']) {
            self::delete($key);
            return $default;
        }
        
        return $data['value'];
    }
    
    public static function set($key, $value, $ttl = null) {
        self::initialize();
        
        $ttl = $ttl ?? self::$defaultTtl;
        $file = self::getFilePath($key);
        
        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];
        
        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }
    
    public static function delete($key) {
        self::initialize();
        
        $file = self::getFilePath($key);
        
        if (file_exists($file)) {
            return unlink($file);
        }
        
        return true;
    }
    
    public static function clear($pattern = null) {
        self::initialize();
        
        if ($pattern) {
            $files = glob(self::$cacheDir . $pattern . '*.cache');
        } else {
            $files = glob(self::$cacheDir . '*.cache');
        }
        
        $deleted = 0;
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    public static function remember($key, $callback, $ttl = null) {
        $value = self::get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = call_user_func($callback);
        self::set($key, $value, $ttl);
        
        return $value;
    }
    
    public static function has($key) {
        return self::get($key) !== null;
    }
    
    public static function increment($key, $step = 1) {
        $value = self::get($key, 0);
        $value += $step;
        self::set($key, $value);
        return $value;
    }
    
    public static function decrement($key, $step = 1) {
        return self::increment($key, -$step);
    }
    
    public static function getStats() {
        self::initialize();
        
        $files = glob(self::$cacheDir . '*.cache');
        $totalSize = 0;
        $count = count($files);
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        return [
            'files' => $count,
            'size' => $totalSize,
            'size_formatted' => self::formatBytes($totalSize)
        ];
    }
    
    public static function cleanup($maxAge = null) {
        self::initialize();
        
        $maxAge = $maxAge ?? self::$defaultTtl;
        $files = glob(self::$cacheDir . '*.cache');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (time() - filemtime($file) > $maxAge) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
    
    private static function getFilePath($key) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return self::$cacheDir . $safeKey . '.cache';
    }
    
    private static function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Database Query Cache
class QueryCache {
    private static $enabled = true;
    private static $ttl = 300; // 5 minutes
    
    public static function enable($enabled = true) {
        self::$enabled = $enabled;
    }
    
    public static function setTtl($ttl) {
        self::$ttl = $ttl;
    }
    
    public static function get($sql, $params = []) {
        if (!self::$enabled) {
            return null;
        }
        
        $key = 'query_' . md5($sql . serialize($params));
        return Cache::get($key);
    }
    
    public static function set($sql, $params = [], $result) {
        if (!self::$enabled) {
            return false;
        }
        
        $key = 'query_' . md5($sql . serialize($params));
        return Cache::set($key, $result, self::$ttl);
    }
    
    public static function clear() {
        return Cache::clear('query_');
    }
    
    public static function remember($sql, $params = [], $callback, $ttl = null) {
        if (!self::$enabled) {
            return call_user_func($callback);
        }
        
        $result = self::get($sql, $params);
        
        if ($result !== null) {
            return $result;
        }
        
        $result = call_user_func($callback);
        self::set($sql, $params, $result, $ttl);
        
        return $result;
    }
}

// Page Cache
class PageCache {
    private static $enabled = false;
    private static $ttl = 600; // 10 minutes
    
    public static function enable($enabled = true) {
        self::$enabled = $enabled;
    }
    
    public static function start($key = null, $ttl = null) {
        if (!self::$enabled || !empty($_POST)) {
            return false;
        }
        
        $key = $key ?? 'page_' . md5($_SERVER['REQUEST_URI']);
        $content = Cache::get($key);
        
        if ($content !== null) {
            echo $content;
            exit;
        }
        
        ob_start();
        return $key;
    }
    
    public static function end($key, $ttl = null) {
        if (!self::$enabled) {
            return;
        }
        
        $content = ob_get_contents();
        ob_end_flush();
        
        $ttl = $ttl ?? self::$ttl;
        Cache::set($key, $content, $ttl);
    }
    
    public static function clear($pattern = null) {
        return Cache::clear($pattern ? 'page_' . $pattern : 'page_');
    }
}

// Auto-initialize
Cache::initialize();
