# SNDRA Park Security Audit - Quick Reference & Files to Change

## 🔴 CRITICAL VULNERABILITIES (Fix FIRST)

### 1. ALL Admin Endpoints Unprotected
Files needing auth check:
- `backend/admin/add_floor.php`
- `backend/admin/delete_floor.php`
- `backend/admin/delete_slot.php`
- `backend/admin/get_dashboard_summary.php`
- `backend/admin/get_feedback.php`
- `backend/admin/get_floors.php`
- `backend/admin/get_live_reservations.php`
- `backend/admin/get_logs.php`
- `backend/admin/get_payments.php`
- `backend/admin/get_reservations.php`
- `backend/admin/get_users.php`
- `backend/admin/manage_booth_staff.php`
- `backend/admin/manage_notifications.php`
- `backend/admin/manage_slots.php`
- `backend/admin/save_settings.php`

**FIX**: Add to top of each file after `require_once __DIR__ . '/common.php';`
```php
// Add auth check
if (empty($_SESSION['sndra_admin'])) {
    admin_error('Unauthorized access.', 401);
}
```

### 2. ALL Booth Endpoints Unprotected & CORS Open
Files: `backend/parking-booth/*`

**FIX**: 
- Change CORS in `backend/parking-booth/common.php` line 21: Remove `*`, set to specific origin
- Add booth staff auth check for paid operations

### 3. Query Parameter User ID Vulnerability
File: `backend/controllers/ReservationController.php` line 23

**Current (VULNERABLE):**
```php
$userId = (int) RequestHelper::query('user_id', 0);
```

**Fix:**
```php
$userId = (int) ($_SESSION['user_id'] ?? 0);
```

### 4. Create Reservation for Any User Vulnerability
File: `backend/controllers/ReservationController.php` line 33

**Current (VULNERABLE):**
```php
$reservationId = $this->reservationModel->create(
    (int) $data['user_id'],  // Can pass different user_id!
```

**Fix:**
```php
// Verify user_id matches session
if ((int) $data['user_id'] !== $userId) {
    ResponseHelper::error('Unauthorized user.', 403);
}
$reservationId = $this->reservationModel->create($userId, ...);
```

### 5. Weak Admin Password Hashing
File: `backend/admin/common.php` function `admin_staff_password_hash()`

**Current (WEAK):**
```php
function admin_staff_password_hash(string $password): string {
    return hash('sha256', $password);  // ← NO salt, easy to crack
}
```

**Fix:**
```php
function admin_staff_password_hash(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);  // ← Proper hashing
}
```

**Then update login.php line 15** to use `password_verify()`:
```php
// From:
if (!hash_equals((string) $staff['password_hash'], admin_staff_password_hash($password))) {
    admin_error('Invalid admin account credentials.', 401);
}

// To:
if (!password_verify($password, (string) $staff['password_hash'])) {
    admin_error('Invalid admin account credentials.', 401);
}
```

### 6. No CSRF Protection
**Create** `backend/middleware/CsrfMiddleware.php`
```php
<?php
class CsrfMiddleware {
    public static function generateToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function validateToken($token) {
        if (empty($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            throw new RuntimeException('CSRF token validation failed.', 403);
        }
    }
}
?>
```

**Add** to ALL POST/PUT/DELETE endpoints in admin and user APIs

### 7. No Session Timeout Configuration
**Create** `backend/config/session.php`:
```php
<?php
ini_set('session.gc_maxlifetime', 1800);  // 30 minutes
ini_set('session.cookie_lifetime', 0);     // Dies with browser
ini_set('session.cookie_httponly', 1);     // No JS access
ini_set('session.cookie_secure', 1);       // HTTPS only (set to 0 in dev)
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.name', 'SNDRA_SESS');
?>
```

**Add** to each entry point that calls `session_start()`:
```php
require_once __DIR__ . '/backend/config/session.php';
session_start();
```

### 8. Expose SQL Errors
Files: Multiple admin endpoints that pass `['details' => $exception->getMessage()]`

**Current (LEAKS INFO):**
```php
admin_error('Failed to delete floor.', 500, [
    'details' => $exception->getMessage()  // ← Exposes SQL errors
]);
```

**Fix:**
```php
// Only in development/debug mode
$details = (getenv('APP_DEBUG') === 'true') ? ['details' => $exception->getMessage()] : [];
admin_error('An error occurred.', 500, $details);
```

---

## 🟠 HIGH PRIORITY FIXES

### 1. Add Rate Limiting
**Create** `backend/utils/RateLimiter.php`
```php
<?php
class RateLimiter {
    public static function checkLimit($key, $maxAttempts = 3, $timeWindow = 300) {
        $key = "ratelimit_" . $key;
        $sessionKey = $key . "_attempts";
        $sessionTime = $key . "_time";
        
        if (empty($_SESSION[$sessionTime])) {
            $_SESSION[$sessionTime] = time();
            $_SESSION[$sessionKey] = 0;
        }
        
        if (time() - $_SESSION[$sessionTime] > $timeWindow) {
            $_SESSION[$sessionTime] = time();
            $_SESSION[$sessionKey] = 0;
        }
        
        $_SESSION[$sessionKey]++;
        
        if ($_SESSION[$sessionKey] > $maxAttempts) {
            throw new RuntimeException('Too many attempts. Please try again later.', 429);
        }
    }
}
?>
```

**Add** to `AuthController::login()`:
```php
RateLimiter::checkLimit('login_' . $email, 5, 900);  // 5 attempts in 15 min
```

### 2. Add Session Regeneration on Admin Login
File: `backend/admin/login.php` after setting session

**Add after line 36** where session is set:
```php
session_regenerate_id(true);
```

### 3. Enforce RBAC in Controllers
```php
// In each controller method that requires admin:
if (empty($_SESSION['sndra_admin'])) {
    ResponseHelper::error('Admin access required.', 403);
}

// Or for booth staff:
if (empty($_SESSION['booth_staff'])) {
    ResponseHelper::error('Booth staff access required.', 403);
}
```

### 4. Add Audit Logging
**Create** `backend/utils/AuditLogger.php`
```php
<?php
class AuditLogger {
    public static function log($action, $userId, $details, $connection = null) {
        // If no connection provided, use standard one
        if (!$connection) {
            $connection = Database::connection();
        }
        
        $stmt = $connection->prepare("
            INSERT INTO audit_logs (action, user_id, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $detailsJson = json_encode($details);
        $stmt->bind_param('siss', $action, $userId, $detailsJson, $ip);
        $stmt->execute();
    }
}
?>
```

### 5. Require Current Password on Profile Update
File: `backend/controllers/UserController.php` in `updateProfile()`

**Add validation:**
```php
if ($password !== '' && isset($data['currentPassword'])) {
    $currentPassword = (string) $data['currentPassword'];
    if (!password_verify($currentPassword, $user['password_hash'])) {
        ResponseHelper::error('Current password is incorrect.', 422);
    }
}
```

---

## 🟡 MEDIUM PRIORITY FIXES

### 1. Add Input Length Validation
```php
// Example for all text fields:
if (strlen($fullName) > 150) {
    ResponseHelper::error('Name is too long (max 150 characters).', 422);
}
```

### 2. Add XSS Protection
Add to all response outputs (in a centralized sanitizer):
```php
function sanitize_output($data) {
    if (is_array($data)) {
        return array_map('sanitize_output', $data);
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}
```

### 3. Add Content Security Policy Headers
**Create** `backend/config/headers.php`:
```php
<?php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline';");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
?>
```

### 4. Implement JWT for API (Optional but Recommended)
- Install: `composer require firebase/php-jwt`
- Create JWT tokens on login instead of sessions
- Better for API security and scalability

---

## DATABASE MIGRATION NEEDED

### Create Audit Log Table
```sql
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(100) NOT NULL,
    user_id INT,
    details JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

CREATE TABLE staff_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(100),
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,  -- Must be updated for new hashing
    role ENUM('admin', 'booth') DEFAULT 'booth',
    is_active TINYINT DEFAULT 1,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## LOGIN ATTEMPT TRACKING (Optional)

```sql
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100),
    ip_address VARCHAR(45),
    success TINYINT,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_ip (email, ip_address),
    INDEX idx_attempted_at (attempted_at)
);
```

---

## FILES TO CREATE (NEW)

1. `backend/middleware/AdminAuthMiddleware.php` - Admin session check
2. `backend/middleware/CsrfMiddleware.php` - CSRF token handling
3. `backend/middleware/RbacMiddleware.php` - Role-based access
4. `backend/utils/RateLimiter.php` - Rate limiting
5. `backend/utils/AuditLogger.php` - Audit logging
6. `backend/config/session.php` - Session configuration
7. `backend/config/headers.php` - Security headers

## FILES TO MODIFY (CRITICAL)

1. `backend/admin/common.php` - Add auth helper & fix password hashing
2. `backend/admin/login.php` - Add session regeneration & fix password verify
3. `backend/admin/*.php` (all 13) - Add auth check
4. `backend/parking-booth/common.php` - Fix CORS
5. `backend/controllers/ReservationController.php` - Fix user_id vulnerabilities  
6. Backend entry points - Include session config

---

## TESTING CHECKLIST

- [ ] Can access admin endpoints without login? Should fail
- [ ] Can access booth endpoints without auth? Should fail
- [ ] Can create reservation for other user? Should fail
- [ ] Can view other user's reservations? Should fail
- [ ] Admin passwords hash to sha256? Should use password_hash()
- [ ] Session timeout after 30 minutes? Should logout
- [ ] CSRF token validated on POST? Should fail without token
- [ ] Rate limiting on login? Should lock after 5 attempts
- [ ] SQL errors exposed in responses? Should be hidden
- [ ] Error logs contain sensitive data? Should not

---

## PRIORITY ORDER FOR IMPLEMENTATION

1. **Week 1**: Fix critical vulnerabilities (admin auth, password hashing, query param)
2. **Week 2**: Add CSRF, rate limiting, session timeout
3. **Week 3**: Add RBAC, audit logging
4. **Week 4**: Add input validation, error handling, XSS protection

---

**This summary should be kept with the main audit report for reference during implementation.**
