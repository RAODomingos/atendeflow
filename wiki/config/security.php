<?php
// ============================================================
// config/security.php - Security utilities and helpers
// ============================================================

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // Token expires after 1 hour
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate CSRF input field
 */
function getCSRFInput() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Sanitize input data
 */
function sanitizeInput($input, $type = 'string') {
    switch ($type) {
        case 'int':
            return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'html':
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        case 'slug':
            return preg_replace('/[^a-z0-9-]/', '', strtolower($input));
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Validate required fields
 */
function validateRequired($data, $required) {
    $errors = [];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $errors[] = "O campo {$field} é obrigatório.";
        }
    }
    return $errors;
}

/**
 * Rate limiting for login attempts
 */
function checkRateLimit($key, $maxAttempts = 5, $timeWindow = 300) {
    $cacheKey = "rate_limit_{$key}";
    
    if (!isset($_SESSION[$cacheKey])) {
        $_SESSION[$cacheKey] = ['attempts' => 0, 'first_attempt' => time()];
    }
    
    $rateData = $_SESSION[$cacheKey];
    
    // Reset if time window passed
    if (time() - $rateData['first_attempt'] > $timeWindow) {
        $_SESSION[$cacheKey] = ['attempts' => 0, 'first_attempt' => time()];
        return true;
    }
    
    if ($rateData['attempts'] >= $maxAttempts) {
        return false;
    }
    
    $_SESSION[$cacheKey]['attempts']++;
    return true;
}

/**
 * XSS Protection for content
 */
function xssProtect($content) {
    return htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * SQL Injection Protection (additional layer)
 */
function sanitizeSQL($value) {
    return addslashes($value);
}
?>
