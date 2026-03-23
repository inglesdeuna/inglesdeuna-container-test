<?php
/**
 * Security Utilities Class
 * Handles password hashing, CSRF protection, session security, and input validation
 */

class Security
{
    // CSRF Token configuration
    const CSRF_TOKEN_NAME = '_csrf_token';
    const CSRF_TOKEN_LENGTH = 32;
    const SESSION_TIMEOUT = 3600; // 1 hour in seconds

    /**
     * Hash a password using bcrypt (PASSWORD_BCRYPT)
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify a plaintext password against a hash
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if a password needs rehashing
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Generate a CSRF token for session
     */
    public static function generateCSRFToken(): string
    {
        if (!isset($_SESSION[self::CSRF_TOKEN_NAME])) {
            $_SESSION[self::CSRF_TOKEN_NAME] = bin2hex(random_bytes(self::CSRF_TOKEN_LENGTH));
        }
        return $_SESSION[self::CSRF_TOKEN_NAME];
    }

    /**
     * Get the current CSRF token from session
     */
    public static function getCSRFToken(): ?string
    {
        return $_SESSION[self::CSRF_TOKEN_NAME] ?? null;
    }

    /**
     * Verify that a submitted CSRF token matches the session token
     */
    public static function verifyCSRFToken(string $token): bool
    {
        $sessionToken = self::getCSRFToken();
        
        if ($sessionToken === null) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Initialize secure session settings
     * Note: Call this AFTER session_start()
     */
    public static function initializeSession(): void
    {
        // Set session timeout check
        if (!isset($_SESSION['_session_start_time'])) {
            $_SESSION['_session_start_time'] = time();
        }

        // Check if session has expired
        if (time() - $_SESSION['_session_start_time'] > self::SESSION_TIMEOUT) {
            session_destroy();
            session_start();
            $_SESSION['_session_start_time'] = time();
        }
    }

    /**
     * Destroy session securely
     */
    public static function destroySession(): void
    {
        $_SESSION = [];
        
        if (ini_get('session.use_cookies') === '1') {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Sanitize user input
     */
    public static function sanitize(string $input, string $type = 'string')
    {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            
            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);
            
            case 'integer':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT);
            
            case 'string':
            default:
                return trim(str_replace("\x00", '', $input));
        }
    }

    /**
     * Validate email format
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate URL format
     */
    public static function isValidURL(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check string length constraints
     */
    public static function isValidLength(string $input, int $minLength = 0, int $maxLength = PHP_INT_MAX): bool
    {
        $length = strlen($input);
        return $length >= $minLength && $length <= $maxLength;
    }

    /**
     * Escape HTML to prevent XSS attacks
     */
    public static function escapeHTML(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Log security event for audit trail
     */
    public static function logSecurityEvent(string $event, string $details = '', ?string $userId = null): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/security.log';
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $logEntry = sprintf(
            "[%s] Event: %s | User: %s | IP: %s | Details: %s | UA: %s\n",
            $timestamp,
            $event,
            $userId ?? 'none',
            $ip,
            $details,
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        );

        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
