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
     * 
     * @param string $password The plaintext password to hash
     * @return string The hashed password
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify a plaintext password against a hash
     * 
     * @param string $password The plaintext password to verify
     * @param string $hash The password hash to check against
     * @return bool True if password matches hash, false otherwise
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if a password needs rehashing (for upgrading hashes)
     * 
     * @param string $hash The password hash
     * @return bool True if password should be rehashed
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Generate a CSRF token for session
     * 
     * @return string The generated CSRF token
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
     * 
     * @return string|null The CSRF token or null if not set
     */
    public static function getCSRFToken(): ?string
    {
        return $_SESSION[self::CSRF_TOKEN_NAME] ?? null;
    }

    /**
     * Verify that a submitted CSRF token matches the session token
     * 
     * @param string $token The submitted token to verify
     * @return bool True if token is valid and matches session token
     */
    public static function verifyCSRFToken(string $token): bool
    {
        $sessionToken = self::getCSRFToken();
        
        if ($sessionToken === null) {
            return false;
        }

        // Use hash_equals to prevent timing attacks
        return hash_equals($sessionToken, $token);
    }

    /**
     * Initialize secure session settings
     * Should be called at the start of each session
     */
    public static function initializeSession(): void
    {
        // Set session timeout
        if (!isset($_SESSION['_session_start_time'])) {
            $_SESSION['_session_start_time'] = time();
        }

        // Check if session has expired
        if (time() - $_SESSION['_session_start_time'] > self::SESSION_TIMEOUT) {
            session_destroy();
            session_start();
            $_SESSION['_session_start_time'] = time();
        }

        // Set secure session options
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        
        // Set SameSite attribute (PHP 7.3+)
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
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
     * Sanitize user input - remove/escape potentially dangerous content
     * 
     * @param string $input The input to sanitize
     * @param string $type The type of sanitization (string|email|url|integer)
     * @return mixed The sanitized value
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
                // Remove null bytes and trim whitespace
                return trim(str_replace("\x00", '', $input));
        }
    }

    /**
     * Validate email format
     * 
     * @param string $email The email to validate
     * @return bool True if email is valid
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate URL format
     * 
     * @param string $url The URL to validate
     * @return bool True if URL is valid
     */
    public static function isValidURL(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check string length constraints
     * 
     * @param string $input The string to check
     * @param int $minLength Minimum allowed length
     * @param int $maxLength Maximum allowed length
     * @return bool True if length is valid
     */
    public static function isValidLength(string $input, int $minLength = 0, int $maxLength = PHP_INT_MAX): bool
    {
        $length = strlen($input);
        return $length >= $minLength && $length <= $maxLength;
    }

    /**
     * Escape HTML to prevent XSS attacks
     * 
     * @param string $text The text to escape
     * @return string HTML-escaped text safe for output
     */
    public static function escapeHTML(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Log security event for audit trail
     * 
     * @param string $event The event type (login, logout, failed_login, etc.)
     * @param string $details Additional details about the event
     * @param string|null $userId The user ID if applicable
     */
    public static function logSecurityEvent(string $event, string $details = '', ?string $userId = null): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
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

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
