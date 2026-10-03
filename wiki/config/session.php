<?php
// ============================================================
// config/session.php - Centralized Session Management
// ============================================================

class SessionManager {
    private static $initialized = false;
    private static $timeout = 30 * 60; // 30 minutes
    private static $regenerateInterval = 10 * 60; // 10 minutes
    
    public static function initialize() {
        if (self::$initialized) {
            return;
        }
        
        // Secure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
        ini_set('session.cookie_samesite', 'Strict');
        
        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Regenerate session ID periodically
        if (!isset($_SESSION['last_regeneration']) || 
            (time() - $_SESSION['last_regeneration']) > self::$regenerateInterval) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
        
        // Check session timeout
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity']) > self::$timeout) {
            self::destroy();
            throw new SessionTimeoutException('Session expired');
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        self::$initialized = true;
    }
    
    public static function destroy() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        
        // Clear session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
            unset($_COOKIE[session_name()]);
        }
    }
    
    public static function set($key, $value) {
        self::initialize();
        $_SESSION[$key] = $value;
    }
    
    public static function get($key, $default = null) {
        self::initialize();
        return $_SESSION[$key] ?? $default;
    }
    
    public static function has($key) {
        self::initialize();
        return isset($_SESSION[$key]);
    }
    
    public static function remove($key) {
        self::initialize();
        unset($_SESSION[$key]);
    }
    
    public static function flash($key, $value) {
        self::set('_flash_' . $key, $value);
    }
    
    public static function getFlash($key, $default = null) {
        $flashKey = '_flash_' . $key;
        $value = self::get($flashKey, $default);
        if ($value !== $default) {
            self::remove($flashKey);
        }
        return $value;
    }
    
    public static function isValid() {
        try {
            self::initialize();
            return self::has('admin_id');
        } catch (SessionTimeoutException $e) {
            return false;
        }
    }
    
    public static function getTimeout() {
        return self::$timeout;
    }
    
    public static function setTimeout($seconds) {
        self::$timeout = $seconds;
    }
}

class SessionTimeoutException extends Exception {}

// Auto-initialize when included
SessionManager::initialize();
