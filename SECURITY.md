# Security Implementation Guide

## Overview
This document outlines the security improvements implemented in the InglesDeUna application to protect against common vulnerabilities and provide better session management.

---

## 1. Password Hashing & Storage

### Implementation
- **Location**: `config/security.php` - Security class
- **Method**: bcrypt with cost parameter of 12
- **Database**: Passwords stored in `admin_users` table with `password_hash` column

### Code Examples

#### Hash a Password
```php
require_once __DIR__ . '/config/security.php';

$hashed = Security::hashPassword('plaintext_password');
```

#### Verify a Password
```php
$isValid = Security::verifyPassword($submitted_password, $stored_hash);
```

### Migration Notes
- Old admin credentials from `admin/data/users.json` are deprecated
- Admin users are now stored in the `admin_users` PostgreSQL table
- Passwords are automatically hashed during database initialization

---

## 2. CSRF Protection

### Implementation
- **Token Generation**: `Security::generateCSRFToken()` - Creates and stores token in session
- **Token Verification**: `Security::verifyCSRFToken($token)` - Uses `hash_equals()` for timing-attack resistance
- **Protection**: Uses `hash_equals()` to prevent timing attacks

### How to Use

#### In Forms
```html
<form method="post">
    <input type="hidden" name="_csrf_token" 
           value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
    <!-- form fields -->
</form>
```

#### In PHP (Processing Form)
```php
require_once __DIR__ . '/config/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    
    if (!Security::verifyCSRFToken($token)) {
        die('Invalid CSRF token');
    }
    
    // Process form safely
}
```

---

## 3. Session Security

### Features
- **Session Timeout**: 1 hour (3600 seconds) - configurable in `Security::SESSION_TIMEOUT`
- **HttpOnly Cookies**: Prevents JavaScript access
- **SameSite Attribute**: Set to 'Lax' to prevent CSRF attacks
- **Session Regeneration**: ID regenerated on login
- **Secure Initialization**: `Security::initializeSession()` sets up secure session

### Code Examples

#### Initialize Secure Session
```php
require_once __DIR__ . '/config/security.php';

session_start();
Security::initializeSession();
```

#### Destroy Session Securely
```php
Security::destroySession();
// Redirects to login
```

#### Check Session Timeout
Session timeout is checked automatically in `Security::initializeSession()`. If session expires, user is automatically logged out.

---

## 4. Input Validation & Sanitization

### Classes & Methods

#### Security Class Methods
- `Security::sanitize(string, type)` - Basic sanitization
- `Security::isValidEmail(email)` - Email validation
- `Security::isValidURL(url)` - URL validation
- `Security::isValidLength(string, min, max)` - Length validation
- `Security::escapeHTML(text)` - XSS prevention

#### InputValidator Class
Full-featured input validation for forms

### Code Examples

#### Simple String Sanitization
```php
require_once __DIR__ . '/config/security.php';

$email = Security::sanitize($_POST['email'], 'email');
$url = Security::sanitize($_POST['url'], 'url');
$text = Security::sanitize($_POST['text'], 'string');
```

#### Form Validation (Batch)
```php
require_once __DIR__ . '/config/input_validator.php';

$schema = [
    'email' => [
        'type' => 'email',
        'required' => true,
    ],
    'age' => [
        'type' => 'integer',
        'required' => true,
        'min' => 0,
        'max' => 150,
    ],
    'website' => [
        'type' => 'url',
        'required' => false,
    ],
    'role' => [
        'type' => 'enum',
        'required' => true,
        'values' => ['admin', 'user', 'guest'],
    ],
];

$result = InputValidator::validateFormData($_POST, $schema);

if (!$result['is_valid']) {
    // Handle errors
    foreach ($result['errors'] as $field => $error) {
        echo "Error in $field: $error\n";
    }
} else {
    // Use validated data
    $data = $result['valid'];
    // Process $data safely
}
```

### Supported Validation Types
- `string` - Text input with min/max length and pattern matching
- `email` - Email format validation
- `integer` - Integer with min/max range
- `boolean` - Boolean values
- `url` - URL format validation
- `enum` - Limited set of allowed values
- `array` - Arrays with item validation

---

## 5. Security Event Logging

### Implementation
- **Location**: `logs/security.log` file (created automatically)
- **Method**: `Security::logSecurityEvent(event, details, userId)`
- **Events**: login, logout, failed_login, etc.

### Code Example
```php
Security::logSecurityEvent('admin_login', 'Successful login', $user_id);
Security::logSecurityEvent('failed_login', 'Invalid credentials', $email);
Security::logSecurityEvent('admin_logout', 'User logged out', $user_id);
```

### Log Format
```
[2024-03-23 14:30:45] Event: admin_login | User: admin_1 | IP: 192.168.1.100 | Details: Successful login | UA: Mozilla/5.0...
```

---

## 6. Updated Files

### New Files Created
1. **`config/security.php`** - Core security utilities
2. **`config/input_validator.php`** - Input validation framework
3. **`logs/`** - Directory for security event logs (auto-created)

### Modified Files
1. **`config/init_db.php`** - Added `admin_users` table and seeding
2. **`admin/login.php`** - Updated to use database and security functions
3. **`admin/logout.php`** - Enhanced with security logging
4. **`admin/dashboard.php`** - Added session initialization and secure logout
5. **`.gitignore`** - Added entries for sensitive files

---

## 7. Migration from Old Admin System

### Before
- Credentials stored in `admin/data/users.json`
- Plaintext password comparison
- No CSRF protection
- Basic session handling

### After
- Credentials stored in `admin_users` PostgreSQL table
- Bcrypt password hashing
- CSRF token protection on all forms
- Secure session with timeout and regeneration
- Security event logging

### Migration Steps
1. Database initialization automatically creates `admin_users` table
2. Default admin user seeded with email: `admin@lets.com`, password: `1234` (hashed)
3. Old JSON file can be kept for reference but is no longer used
4. Update any other login systems to use new Security class

---

## 8. Best Practices Checklist

- [x] Use `Security::hashPassword()` for all new passwords
- [x] Use `Security::verifyPassword()` for authentication
- [x] Always validate user input with `InputValidator::validateFormData()`
- [x] Include CSRF tokens in all forms (`Security::generateCSRFToken()`)
- [x] Verify CSRF tokens before processing forms (`Security::verifyCSRFToken()`)
- [x] Call `Security::initializeSession()` at start of protected pages
- [x] Use `Security::escapeHTML()` when outputting user data
- [x] Log security events for audit trail
- [x] Never store passwords in plaintext
- [x] Review security logs regularly (`logs/security.log`)

---

## 9. Configuration

### Session Timeout
Edit `config/security.php` to adjust:
```php
const SESSION_TIMEOUT = 3600; // 1 hour in seconds
```

### Password Hashing Cost
Edit `config/security.php` bcrypt cost (higher = slower but more secure):
```php
'cost' => 12 // Recommended range: 10-14
```

### Log Location
Security logs are stored in: `logs/security.log`

---

## 10. Troubleshooting

### Issue: "Unable to write to logs directory"
**Solution**: Ensure the `lessons/lessons/logs/` directory exists and is writable by the web server.

### Issue: "CSRF token validation failed"
**Solution**: 
1. Make sure `session_start()` is called before `Security::initializeSession()`
2. Verify the CSRF token field is included in the form
3. Check that the form method is POST

### Issue: Password verification fails
**Solution**:
1. Verify password is actually hashed with `password_hash()` (not plaintext)
2. Use `Security::verifyPassword()` for verification, not simple `===` comparison
3. Check that the database column stores the full hash

---

## 11. Additional Resources

- PHP Password Hashing: https://www.php.net/manual/en/function.password-hash.php
- OWASP CSRF Prevention: https://owasp.org/www-community/attacks/csrf
- OWASP Input Validation: https://owasp.org/www-community/attacks/xss/
- Session Security: https://www.php.net/manual/en/session.security.php
