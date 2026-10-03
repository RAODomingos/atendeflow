<?php
// ============================================================
// config/logger.php - Comprehensive Activity Logging System
// ============================================================

class Logger {
    private static $db;
    private static $levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    
    public static function initialize() {
        global $db;
        self::$db = $db;
    }
    
    public static function log($level, $message, $context = [], $userId = null) {
        if (!isset(self::$levels[$level])) {
            $level = 'INFO';
        }
        
        $userId = $userId ?? ($_SESSION['admin_id'] ?? null);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $endpoint = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        
        try {
            $stmt = self::$db->prepare('
                INSERT INTO activity_logs (
                    level, message, context, user_id, ip_address, 
                    user_agent, endpoint, method, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $stmt->execute([
                $level,
                $message,
                json_encode($context),
                $userId,
                $ip,
                $userAgent,
                $endpoint,
                $method
            ]);
        } catch (Exception $e) {
            error_log("Logger failed: " . $e->getMessage());
        }
    }
    
    public static function debug($message, $context = [], $userId = null) {
        self::log('DEBUG', $message, $context, $userId);
    }
    
    public static function info($message, $context = [], $userId = null) {
        self::log('INFO', $message, $context, $userId);
    }
    
    public static function warning($message, $context = [], $userId = null) {
        self::log('WARNING', $message, $context, $userId);
    }
    
    public static function error($message, $context = [], $userId = null) {
        self::log('ERROR', $message, $context, $userId);
    }
    
    public static function critical($message, $context = [], $userId = null) {
        self::log('CRITICAL', $message, $context, $userId);
    }
    
    // Specific logging methods for common actions
    public static function logLogin($username, $success, $reason = '') {
        self::info('Login attempt', [
            'username' => $username,
            'success' => $success,
            'reason' => $reason
        ]);
    }
    
    public static function logLogout($userId) {
        self::info('User logout', ['user_id' => $userId]);
    }
    
    public static function logCrud($action, $entity, $entityId, $data = []) {
        self::info("{$action} {$entity}", array_merge([
            'entity_id' => $entityId,
            'action' => $action,
            'entity' => $entity
        ], $data));
    }
    
    public static function logApiRequest($endpoint, $method, $status, $responseTime = null) {
        self::info('API request', [
            'endpoint' => $endpoint,
            'method' => $method,
            'status' => $status,
            'response_time_ms' => $responseTime
        ]);
    }
    
    public static function logSecurity($event, $details = []) {
        self::warning('Security event', array_merge([
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ], $details));
    }
    
    public static function logUpload($filename, $size, $success, $error = null) {
        self::info('File upload', [
            'filename' => $filename,
            'size' => $size,
            'success' => $success,
            'error' => $error
        ]);
    }
    
    public static function getLogs($level = null, $limit = 100, $userId = null) {
        try {
            $sql = 'SELECT * FROM activity_logs WHERE 1=1';
            $params = [];
            
            if ($level) {
                $sql .= ' AND level = ?';
                $params[] = $level;
            }
            
            if ($userId) {
                $sql .= ' AND user_id = ?';
                $params[] = $userId;
            }
            
            $sql .= ' ORDER BY created_at DESC LIMIT ?';
            $params[] = $limit;
            
            $stmt = self::$db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            self::error('Failed to retrieve logs', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    public static function cleanup($days = 30) {
        try {
            $stmt = self::$db->prepare('
                DELETE FROM activity_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ');
            $deleted = $stmt->execute([$days]);
            
            self::info('Log cleanup', ['days' => $days, 'deleted_rows' => $deleted]);
            return $deleted;
        } catch (Exception $e) {
            self::error('Log cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}

// Auto-initialize when included
if (isset($db)) {
    Logger::initialize();
}
