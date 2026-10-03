<?php
// ============================================================
// config/api_auth.php - API Authentication System
// ============================================================

require_once __DIR__ . '/db.php';

class APIAuth {
    private static $apiKey = 'GUILD_API_2024_SECURE_KEY_CHANGE_ME';
    private static $rateLimits = [
        'frontend' => ['window' => 60, 'max' => 200], // 200 requests/min for frontend
        'api' => ['window' => 60, 'max' => 100]      // 100 requests/min for external API
    ];
    
    public static function authenticate() {
        $headers = getallheaders();
        $apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? $_GET['api_key'] ?? null;
        
        // Allow same-origin requests without API key
        $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        
        if (self::isSameOrigin($origin, $serverName) || self::isSameOrigin($_SERVER['HTTP_REFERER'], $serverName)) {
            // Same-origin request - no API key required
            if (!self::checkRateLimit('frontend')) {
                self::sendError('Rate limit exceeded', 429);
            }
            return true;
        }
        
        if (!$apiKey || $apiKey !== self::$apiKey) {
            self::sendError('Unauthorized - Invalid API key', 401);
        }
        
        if (!self::checkRateLimit('api')) {
            self::sendError('Rate limit exceeded', 429);
        }
        
        return true;
    }
    
    private static function isSameOrigin($url, $serverName) {
        if (!$url) return false;
        
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        
        // Check for localhost or same domain
        return $host === $serverName || 
               $host === 'localhost' || 
               $host === '127.0.0.1' ||
               strpos($host, $serverName) === 0;
    }
    
    private static function checkRateLimit($type = 'api') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "rate_limit_{$type}_{$ip}";
        
        $limits = self::$rateLimits[$type] ?? self::$rateLimits['api'];
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'start' => time()];
        }
        
        $data = $_SESSION[$key];
        
        // Reset window if expired
        if (time() - $data['start'] > $limits['window']) {
            $_SESSION[$key] = ['count' => 1, 'start' => time()];
            return true;
        }
        
        // Check limit
        if ($data['count'] >= $limits['max']) {
            return false;
        }
        
        $_SESSION[$key]['count']++;
        return true;
    }
    
    public static function sendError($message, $code = 400) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message, 'code' => $code]);
        exit;
    }
    
    public static function logRequest($endpoint, $method, $status = 200) {
        try {
            $db = getDB();
            $stmt = $db->prepare('INSERT INTO api_logs (endpoint, method, ip_address, user_agent, status_code, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $endpoint,
                $method,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $status
            ]);
        } catch (Exception $e) {
            error_log("API logging failed: " . $e->getMessage());
        }
    }
}

// Iniciar sessão para rate limiting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
