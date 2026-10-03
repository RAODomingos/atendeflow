<?php
// ============================================================
// config/rate_limiter.php - Global Rate Limiting System
// ============================================================

class RateLimiter {
    private static $limits = [
        'global' => ['requests' => 1000, 'window' => 3600], // 1000 requests/hour
        'login' => ['requests' => 5, 'window' => 300], // 5 login attempts/5 minutes
        'api' => ['requests' => 100, 'window' => 60], // 100 API requests/minute
        'upload' => ['requests' => 10, 'window' => 300], // 10 uploads/5 minutes
        'admin' => ['requests' => 200, 'window' => 3600] // 200 admin requests/hour
    ];
    
    public static function check($type = 'global', $identifier = null) {
        $identifier = $identifier ?? self::getIdentifier();
        $limit = self::$limits[$type] ?? self::$limits['global'];
        
        $key = "rate_limit_{$type}_{$identifier}";
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 0,
                'start' => time(),
                'blocked_until' => null
            ];
        }
        
        $data = $_SESSION[$key];
        
        // Check if currently blocked
        if ($data['blocked_until'] && time() < $data['blocked_until']) {
            $remaining = $data['blocked_until'] - time();
            throw new RateLimitException("Rate limit exceeded. Try again in {$remaining} seconds.", $remaining);
        }
        
        // Reset window if expired
        if (time() - $data['start'] > $limit['window']) {
            $_SESSION[$key] = [
                'count' => 1,
                'start' => time(),
                'blocked_until' => null
            ];
            return true;
        }
        
        // Check limit
        if ($data['count'] >= $limit['requests']) {
            // Block for the window duration
            $blockTime = $limit['window'];
            $_SESSION[$key]['blocked_until'] = time() + $blockTime;
            throw new RateLimitException("Rate limit exceeded. Try again in {$blockTime} seconds.", $blockTime);
        }
        
        // Increment counter
        $_SESSION[$key]['count']++;
        return true;
    }
    
    public static function getRemaining($type = 'global', $identifier = null) {
        $identifier = $identifier ?? self::getIdentifier();
        $limit = self::$limits[$type] ?? self::$limits['global'];
        $key = "rate_limit_{$type}_{$identifier}";
        
        if (!isset($_SESSION[$key])) {
            return $limit['requests'];
        }
        
        $data = $_SESSION[$key];
        
        if ($data['blocked_until'] && time() < $data['blocked_until']) {
            return 0;
        }
        
        if (time() - $data['start'] > $limit['window']) {
            return $limit['requests'];
        }
        
        return max(0, $limit['requests'] - $data['count']);
    }
    
    public static function getResetTime($type = 'global', $identifier = null) {
        $identifier = $identifier ?? self::getIdentifier();
        $limit = self::$limits[$type] ?? self::$limits['global'];
        $key = "rate_limit_{$type}_{$identifier}";
        
        if (!isset($_SESSION[$key])) {
            return time();
        }
        
        $data = $_SESSION[$key];
        
        if ($data['blocked_until'] && time() < $data['blocked_until']) {
            return $data['blocked_until'];
        }
        
        return $data['start'] + $limit['window'];
    }
    
    public static function reset($type = 'global', $identifier = null) {
        $identifier = $identifier ?? self::getIdentifier();
        $key = "rate_limit_{$type}_{$identifier}";
        unset($_SESSION[$key]);
    }
    
    public static function setLimit($type, $requests, $window) {
        self::$limits[$type] = ['requests' => $requests, 'window' => $window];
    }
    
    private static function getIdentifier() {
        // Use IP + User Agent for better identification
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 50);
        return md5($ip . $ua);
    }
    
    public static function logRateLimitHit($type, $identifier = null) {
        try {
            $db = require_once __DIR__ . '/db.php';
            $pdo = getDB();
            
            $stmt = $pdo->prepare('INSERT INTO rate_limit_logs (type, identifier, ip_address, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute([
                $type,
                $identifier ?? self::getIdentifier(),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (Exception $e) {
            error_log("Rate limit logging failed: " . $e->getMessage());
        }
    }
}

class RateLimitException extends Exception {
    private $retryAfter;
    
    public function __construct($message, $retryAfter = 0) {
        parent::__construct($message);
        $this->retryAfter = $retryAfter;
    }
    
    public function getRetryAfter() {
        return $this->retryAfter;
    }
}
