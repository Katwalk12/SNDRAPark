# SNDRA Park Backend Security Audit Report

**Date**: March 31, 2026  
**Scope**: Complete backend architecture review (Controllers, Models, Middleware, Admin endpoints, API routes, Database queries)  
**Status**: CRITICAL SECURITY ISSUES IDENTIFIED

---

## EXECUTIVE SUMMARY

The SNDRA Park backend has **CRITICAL SECURITY GAPS** that must be urgently addressed:

1. **ALL admin endpoints are completely unprotected** - no session authentication
2. **ALL parking booth endpoints are completely public** - no access control
3. **Weak admin password hashing** - using SHA256 instead of password_hash()
4. **No CSRF protection** - all state-changing operations vulnerable
5. **Information disclosure in errors** - SQL details exposed to users
6. **No RBAC enforcement** - roles defined but not enforced
7. **Session management is weak** - no timeout, no regeneration on privilege operations

**ESTIMATED RISK LEVEL**: 🔴 **CRITICAL** - System is currently vulnerable to unauthorized access, data manipulation, and privilege escalation.

---

## 1. CURRENT VALIDATION LAYER ANALYSIS

### Overview
The validation exists but is **inconsistent and incomplete** across controllers.

### AuthController Tests ✓
**What's Validated:**
- Required fields: email, password ✓
- Email format: `filter_var($email, FILTER_VALIDATE_EMAIL)` ✓
- Password strength: `(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}` ✓
- Birth date format: `\d{4}-\d{2}-\d{2}` ✓
- Age check: 18+ years old ✓
- Email duplicate check: ✓

**Issues:**
- No rate limiting on login attempts ✗
- No account lockout after N failures ✗
- Password confirm field not checked during registration ✗

### UserController Tests ⚠️
**What's Validated:**
- Email format ✓
- Email uniqueness (except own) ✓
- Birth date format ✓
- Password strength (if provided) ✓

**Issues:**
- NO current password verification for profile update ✗
- NO length validation on full_name ✗
- Can bypass password requirement entirely ✗
- Email can be changed without verification ✗

### ParkingController Tests ❌
**What's Validated:**
- None - only calls model method ✗

**Issues:**
- NO input validation at all ✗
- Model just executes query ✗

### ReservationController Tests ⚠️
**What's Validated:**
- Required fields check: user_id, parking_slot_id, reservation_date ✓
- Slot existence check ✓
- Slot status check ✓

**Issues:**
- NO validation that user_id matches session ✗
- NO validation on reservation_date format ✗
- NO check that reservation_date is in future ✗
- NO validation on time fields (reserved_time_in/out) ✗
- NO rate limiting on reservations per user ✗

### Admin Endpoints Tests ❌
**Files Reviewed:**
- `add_floor.php` - NO validation ✗
- `get_floors.php` - NO validation ✗
- `save_settings.php` - NO validation ✗
- `delete_floor.php` - Minimal validation ⚠️
- `manage_booth_staff.php` - Minimal validation ⚠️
- `get_users.php` - Minimal validation ⚠️

**Issues:**
- Email validation missing in staff creation ✗
- NO password strength requirements for admin/booth staff ✗
- NO validation of role values ✗
- NO validation of boolean flags ✗
- NO length limits on text fields ✗

### Parking Booth Endpoints Tests ❌
**Files Reviewed:**
- `payment.php` - Minimal barcode validation ⚠️
- `scan.php` - Calls payment.php, same issues ✗
- `pay.php` - Calls payment.php, same issues ✗

**Issues:**
- NO reservation ID validation ✗
- NO payment amount validation ✗
- NO timestamp format validation ✗
- NO user permission check ✗

---

## 2. SESSION MANAGEMENT ANALYSIS

### Current Implementation ⚠️

**Session Lifetime**: 
- PHP default: 24 minutes (`session.gc_maxlifetime`)
- NO explicit timeout configured ✗
- Subject to garbage collection randomness ✗
- In `otp-common.php`: OTP expires after 5 minutes, but session doesn't

**Session Data Storage:**
- Location: Filesystem (PHP default) - `/tmp` on Linux, susceptible to local attacks
- Format: Serialized PHP objects
- Encryption: NONE - plain text on disk ✗

**Session Variables Used:**
```
User: $_SESSION['user_id'], $_SESSION['user_email'], $_SESSION['user_name']
Admin: $_SESSION['sndra_admin'] = [id, role, fullName, email]
Password Reset: $_SESSION['password_reset_*'], $_SESSION['otp_*']
```

**Session Regeneration:**
- ✓ `AuthController::login()` - calls `session_regenerate_id(true)`
- ✗ `AuthController::register()` - calls `session_regenerate_id(true)` ✓
- ✗ Admin login - NO regeneration in `backend/admin/login.php` ✗
- ✗ No regeneration after role changes ✗
- ✗ No regeneration on privilege escalation ✗

**Session Destruction:**
```php
// AuthController logout:
$_SESSION = [];
session_destroy();  // Not called!

// Admin logout (logout.php):
$_SESSION = [];
session_destroy();  // IS called ✓
```

**Critical Issues:**
1. NO timeout enforcement to kick out idle users ✗
2. Session can fixation attacks on admin ✗
3. Session files readable by other users on shared hosting ✗
4. No HTTPS requirement (assumes HTTPS but not enforced) ✗
5. No HttpOnly flag enforcement on cookies ✗
6. No SameSite attribute on cookies ✗

---

## 3. AUTHENTICATION FLOW ANALYSIS

### User Authentication Flow (AuthController)

```
User → register/login → validateRequired → 
  email uniqueness → password_verify() → 
  createSessionForUser() → session_regenerate_id() → 
  ResponseHelper::success()
```

**Weaknesses:**
- No rate limiting on login/register ✗
- No captcha on repeated failures ✗
- OTP implementation separate (forgot-password.php) ✗
- No fingerprinting/device tracking ✗
- logout() doesn't call session_destroy() ✗

### Admin Authentication Flow (backend/admin/login.php)

```
Admin → email/password →  Prepare statement →
  Check role === 'admin' amd is_active === 1 →
  hash_equals() for sha256 hash (BAD!) →
  Set $_SESSION['sndra_admin'] →
  Update last_login_at
```

**Critical Weaknesses:**
1. ❌ **SHA256 password hashing** - uses `admin_staff_password_hash()` which is `hash('sha256', $password)`
   - Should use: `password_hash($password, PASSWORD_DEFAULT)`
   - No salt, easily crackable
   - hash_equals() provides timing attack protection but useless if hash is weak

2. ✗ NO rate limiting on failed attempts
3. ✗ NO account lockout after N failures  
4. ✗ NO session regeneration after login
5. ✗ NO 2FA/MFA
6. ✗ NO IP whitelist for admin
7. ✗ last_login_at updated but never checked/enforced

### Where Auth is NOT Enforced ✗

**CRITICAL: Admin endpoints have NO protection:**
- `backend/admin/add_floor.php` - OPEN ✗
- `backend/admin/get_floors.php` - OPEN ✗
- `backend/admin/save_settings.php` - OPEN ✗
- `backend/admin/delete_floor.php` - OPEN ✗
- `backend/admin/delete_slot.php` - OPEN ✗
- `backend/admin/manage_slots.php` - OPEN ✗
- `backend/admin/manage_booth_staff.php` - OPEN ✗
- `backend/admin/manage_notifications.php` - OPEN ✗
- `backend/admin/get_reservations.php` - OPEN ✗
- `backend/admin/get_users.php` - OPEN ✗
- `backend/admin/get_payment_report.php` - OPEN ✗
- `backend/admin/get_feedback.php` - OPEN ✗
- `backend/admin/get_logs.php` - OPEN ✗

**Only protected:** login.php, logout.php

**CRITICAL: Parking booth endpoints have NO protection:**
- `backend/parking-booth/payment.php` - OPEN ✗
- `backend/parking-booth/scan.php` - OPEN ✗
- `backend/parking-booth/pay.php` - OPEN ✗
- All other booth endpoints OPEN ✗

---

## 4. API ENDPOINTS SECURITY ANALYSIS

### Route Definition (backend/routes/api.php)

```php
'auth' => [
  'GET' => ['session' => 'session', 'logout' => 'logout'],
  'POST' => ['login' => 'login', 'register' => 'register']
],
'users' => [
  'GET' => ['default' => 'getProfile'],
  'POST' => ['update' => 'updateProfile']
],
'parking' => [
  'GET' => ['default' => 'getAvailableSlots']  // OPEN ✗
],
'reservations' => [
  'GET' => ['default' => 'getReservations'],
  'POST' => ['default' => 'createReservation']
]
```

**Issues:**
1. Routes file is just a routing map - NOT used for auth ✗
2. No security definition in routes ✗
3. No middleware chain definition ✗

### User API Endpoints

| Endpoint | Method | Auth | Validation | Issues |
|----------|--------|------|-----------|--------|
| `auth/login` | POST | None | Email, password strength | ✗ No rate limiting |
| `auth/register` | POST | None | Email, password, age, birth date | ✗ No rate limiting, no CAPTCHA |
| `auth/session` | GET | `$_SESSION['user_id']` | None | ✓ Session checked |
| `auth/logout` | GET | `$_SESSION['user_id']` | None | ✗ GET for logout? Should be POST |
| `users/getProfile` | GET | `$_SESSION['user_id']` | None | ✓ Session checked |
| `users/updateProfile` | POST | `$_SESSION['user_id']` | Email, password | ✗ No current password verification |
| `parking/getAvailableSlots` | GET | **NONE** ✗ | None | ✗ Completely open |
| `reservations/getReservations` | GET | ⚠️ `user_id` query param | None | **CRITICAL ✗** Gets user_id from query param, not session! |
| `reservations/createReservation` | POST | None ✗ | user_id, parking_slot_id, reservation_date | **CRITICAL ✗** Anyone can create reservation for any user |

### Critical Findings

**1. ReservationController::getReservations() - CRITICAL VULNERABILITY ✗**

```php
public function getReservations() {
    $userId = (int) RequestHelper::query('user_id', 0);  // ← Gets from query param!
    if ($userId <= 0) {
        ResponseHelper::error('A valid user_id is required.', 400);
    }
    $reservations = $this->reservationModel->findByUserId($userId);
}
```

**Risk**: Any user can query any user's reservations by passing different user_id!
- Should use: `$userId = (int) ($_SESSION['user_id'] ?? 0);`

**2. ReservationController::createReservation() - CRITICAL ✗**

```php
public function createReservation($data) {
    ValidationMiddleware::validateRequired($data, ['user_id', '...']);
    // Can pass ANY user_id in the request!
    // No verification that user_id matches session
    $reservationId = $this->reservationModel->create(
        (int) $data['user_id'],  // ← Takes from request!
        // ...
    );
}
```

**Risk**: Anyone can create reservations for any user!

**3. No middleware enforcement ✗**

The `AuthMiddleware::authorizeRequest()` exists but is NEVER CALLED:
- Not in controllers
- Not in routing layer
- Not in entry point

---

## 5. DATABASE QUERY SECURITY ANALYSIS

### Models Using Prepared Statements (Good ✓)

**User Model:**
```php
$statement = $connection->prepare('SELECT ... WHERE id = ? LIMIT 1');
$statement->bind_param('i', $id);  ✓ Parameterized
```

**Reservation Model:**
```php
$statement = $connection->prepare('INSERT INTO reservations (...) VALUES (?, ?, ?)');
$statement->bind_param('iis', $userId, $parkingSlotId, $reservationDate);  ✓
```

**ParkingSlot Model:**
```php
$statement = $connection->prepare('SELECT ... WHERE id = ? LIMIT 1');
$statement->bind_param('i', $id);  ✓
```

### Models Using Real Escape String (Bad ⚠️)

**backend/parking/common.php:**
```php
$table = $connection->real_escape_string($tableName);  ⚠️ Not for dynamic table names!
$column = $connection->real_escape_string($columnName);
$result = $connection->query("SELECT 1 FROM information_schema.COLUMNS WHERE ... AND TABLE_NAME = '{$table}'");
```

**Issues:**
- Real escape string is NOT sufficient for identifiers ⚠️
- Should use backticks or prepared statement parameters (though identifiers can't be parameterized)

### Dynamic SQL Building (Risky ⚠️)

**backend/admin/get_reservations.php:**
```php
$sql = "WHERE (r.barcode_value LIKE ? OR ...)";
$types = 'sssss';
$params = [$search, $search, $search, $search, $search];

if ($statusFilter !== '') {
    $sql .= " AND CASE WHEN ... END = ? ";  // ← Dynamic SQL
    $types .= 's';
    $params[] = $statusFilter;
}

$statement = $connection->prepare($sql);
$statement->bind_param($types, ...$params);  ✓ Still parameterized, but dangerous pattern
```

**Risk**: Dynamic SQL strings are error-prone, though this one is parameterized

### SQL Injection Risk Summary

**Clean (Prepared):** ✓
- User model queries
- Reservation model queries
- ParkingSlot model queries
- Most admin queries

**Risky (Real Escape String, Dynamic SQL):** ⚠️
- `parking/common.php` database schema queries
- Dynamic filter building (though parameterized)

**VERDICT**: SQL injection risk is LOW due to prepared statements, but code is not optimally secure

---

## 6. ERROR HANDLING & INFORMATION DISCLOSURE

### Error Response Structure

```php
ResponseHelper::error($message, $status = 500, $errors = []);
// Returns:
{
  "success": false,
  "message": "...",
  "errors": { ... }
}

// admin_error() in common.php:
admin_error('Failed to delete floor.', 500, [
  'details' => $exception->getMessage()  // ← Raw exception message!
]);
```

### Information Disclosed ✗

1. **SQL Error Details:**
   - In `backend/admin/delete_floor.php`: `['details' => $exception->getMessage()]`
   - Exposes table names, column names, query structure
   - Example: "Table 'sndrapark_db.parking_floors' doesn't exist"

2. **File Paths:**
   - Stack traces would show `/xampp/htdocs/sndraPark/backend/...`
   - Confirms server path structure

3. **Database Structure:**
   - Prep statements sometimes fail with helpful error messages
   - Table names and column names leaked

4. **Exception Types:**
   - Error messages can reveal internally-used libraries
   - Example: "RuntimeException" vs "InvalidArgumentException" reveals architecture

### Error Handling Issues ✗

1. NO try-catch in several endpoints ✗
2. Errors logged to error_log, not secure audit log ✗
3. No error rate tracking ✗
4. No alerting on unusual error patterns ✗
5. Stack traces not sanitized ✗
6. Database connection errors expose DB structure ✗

### Logging Status

**Current Implementation:**
- `admin_log()` - writes to error_log with "[admin]" prefix
- `booth_log()` - writes to error_log with "[parking-booth]" prefix
- No persistent audit trail ✗
- No log rotation ✗
- No log encryption ✗
- No tamper detection ✗

**Missing:**
- Login attempts (success/failure) ✗
- Admin actions (create, update, delete) ✗
- Permission violations ✗
- Data access patterns ✗

---

## 7. ROLE-BASED ACCESS CONTROL (RBAC) ANALYSIS

### Roles Defined

```
1. User (implicit - has $_SESSION['user_id'])
2. Admin (explicit - has $_SESSION['sndra_admin'] with role='admin')
3. Booth Staff (implicit - has auth in parking-booth)
```

### Where Roles Are Checked

**ADMIN role:**
- `backend/admin/login.php` line 36: `if ($staff['role'] !== 'admin' || (int) $staff['is_active'] !== 1)`

**BOOTH role:**
- `backend/admin/manage_booth_staff.php`: Creates staff with role 'admin' or 'booth'
- NOT actually enforced beyond creation ✗

**USER role:**
- `backend/controllers/AuthController.php`: Creates users during registration
- NOT enforced beyond existence ✗

### RBAC Issues ✗

1. **NO role enforcement in controllers** - Roles persist in DB but unused ✗
2. **NO permission matrix** - No ACL defined ✗
3. **NO middleware for role checks** - AuthMiddleware doesn't check roles ✗
4. **Admin-only endpoints accessible to ALL** - No role check ✗
5. **Booth staff operations undefined** - Can they approve payments? Modify reservations? Unknown ✗
6. **User endpoints don't check role** - Any authenticated user bypasses user-only restrictions ✗

### Missing Permissions

**Admin-only operations (should require role='admin'):**
- Create/update/delete floors ✗
- Create/update/delete parking slots ✗
- Manage staff accounts ✗
- Send notifications ✗
- View user list and reports ✗
- Modify system settings ✗
- View logs and feedback ✗

**Booth staff operations (should require role='booth'):**
- Scan barcodes (currently anyone can) ✗
- Record time in/out (currently anyone can) ✗
- Mark payments (currently anyone can) ✗
- View reservations for kiosk (should be restricted) ✗

**User operations (should require role='user'):**
- View own reservations ✓
- Create reservations (but anyone can create for others!) ✗
- Update own profile ✓
- View available slots ✓

---

## 8. ADMIN & BOOTH ENDPOINTS SECURITY ANALYSIS

### Admin Endpoints Security Matrix

| Endpoint | Auth Check | Role Check | Input Val | SQL Inj | CSRF | Rate Limit |
|----------|-----------|-----------|-----------|---------|------|-----------|
| `add_floor.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO |
| `delete_floor.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO |
| `delete_slot.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO |
| `get_dashboard_summary.php` | ❌ NO | ❌ NO | None | ✓ Safe | ❌ NO | ❌ NO |
| `get_users.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO |
| `get_reservations.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO |
| `get_live_reservations.php` | ❌ NO | ❌ NO | None | ⚠️ Dynamic | ❌ NO | ❌ NO |
| `get_logs.php` | ❌ NO | ❌ NO | None | ⚠️ Dynamic | ❌ NO | ❌ NO |
| `get_payments.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ⚠️ Dynamic | ❌ NO | ❌ NO |
| `manage_booth_staff.php` | ❌ NO | ❌ NO | ⚠️ Weak | ✓ Safe | ❌ NO | ❌ NO |
| `manage_slots.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO |
| `manage_notifications.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO |
| `save_settings.php` | ❌ NO | ❌ NO | ⚠️ Weak | ✓ Safe | ❌ NO | ❌ NO |
| `login.php` | N/A | ✓ YES | ✓ Good | ✓ Safe | ✓ YES | ⚠️ Weak |
| `logout.php` | ✓ SESSION* | N/A | None | N/A | N/A | N/A |

*logout.php doesn't explicitly check session but clears it - weak

### Booth Endpoints Security Matrix

| Endpoint | Auth Check | Role Check | Input Val | SQL Inj | CSRF | Rate Limit | CORS |
|----------|-----------|-----------|-----------|---------|------|-----------|------|
| `payment.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO | 🔴 OPEN |
| `scan.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO | 🔴 OPEN |
| `pay.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO | 🔴 OPEN |
| `realtime-monitor.php` | ❌ NO | ❌ NO | None | ✓ Safe | ❌ NO | ❌ NO | 🔴 OPEN |
| `recent.php` | ❌ NO | ❌ NO | None | ✓ Safe | ❌ NO | ❌ NO | 🔴 OPEN |
| `monitor-helpers.php` | ❌ NO | ❌ NO | None | ✓ Safe | ❌ NO | ❌ NO | 🔴 OPEN |
| `slot-monitor.php` | ❌ NO | ❌ NO | ⚠️ Minimal | ✓ Safe | ❌ NO | ❌ NO | 🔴 OPEN |

**CRITICAL**: All booth endpoints have `Access-Control-Allow-Origin: *` - CORS completely open!

---

## 9. SECURITY GAPS SUMMARY TABLE

### Critical Priority (Must Fix Immediately) 🔴

| Gap | Location | Impact | Complexity | Files Affected |
|-----|----------|--------|-----------|-----------------|
| NO admin session auth | All admin/* | Anyone can modify system | Low | 13 files |
| NO booth auth | All parking-booth/* | Anyone can process payments | Low | 8+ files |
| Weak admin password hashing | admin/common.php | Admin accounts easily crackable | Low | 1 file |
| Query-based user_id in reservations | ReservationController | User can view other users' data | Low | 1 controller |
| create_reservation allows any user_id | ReservationController | User can create reservation for others | Low | 1 controller |
| CORS fully open | parking-booth/common.php | Anyone from any domain can exploit | Low | 1 file |
| No CSRF tokens | Global | State-changing ops vulnerable | Medium | 20+ files |
| Error messages expose SQL | Global | Information disclosure | Low | Multiple |
| SHA256 for admin passwords | admin/common.php | Weak crypto | Low | 1 file |
| No session timeout | config/database.php | Sessions persist indefinitely | Low | 1 file |

### High Priority (Fix Soon) 🟠

| Gap | Location | Impact | Complexity |
|-----|----------|--------|-----------|
| NO rate limiting | Global | Brute force attacks possible | Medium |
| NO role enforcement | Controllers | No actual RBAC | Medium |
| Weak input validation | Controllers | XSS, injection attacks | Medium |
| No audit logging | Global | No accountability | Medium |
| Password reset XSS vulnerable | otp-common.php | Session hijacking | Low |
| Session fixation on admin | admin/login.php | Session hijacking | Low |
| No password confirmation needed | UserController | Account takeover | Low |
| No current password on update | UserController | Account takeover | Low |

### Medium Priority (Fix Later) 🟡

| Gap | Location | Impact | Complexity |
|-----|----------|--------|-----------|
| No XSS prevention | Controllers/Models | JavaScript injection | High |
| No request signing | API endpoints | Replay attacks | Medium |
| Filesystem session storage | PHP config | Shared host vulnerability | Low |
| No HTTPS enforcement | Global | Man-in-middle attacks | Low |
| No IP whitelisting | Admin | Unauthorized access | Medium |
| No device fingerprinting | Auth flow | Account compromise | High |

---

## 10. FILE-BY-FILE RECOMMENDATIONS

### CRITICAL - IMMEDIATE FIX REQUIRED

#### backend/admin/common.php
- Add `admin_require_auth()` helper function
- Export to check `$_SESSION['sndra_admin']` in every admin/* endpoint
- Change `admin_staff_password_hash()` to use `password_hash(PASSWORD_DEFAULT)`
- Add session validation helper

**Changes Needed:**
- Add function to verify admin session
- Add CSRF token generation/validation
- Modify password hashing to use password_hash()

#### backend/admin/ - ALL ENDPOINTS
- Add session check at top of each file
- Add CSRF token validation for POST requests
- Examples:
  - `add_floor.php` - Add auth check
  - `delete_floor.php` - Add auth check
  - `save_settings.php` - Add auth check  
  - `manage_booth_staff.php` - Add auth check
  - `manage_slots.php` - Add auth check
  - etc.

#### backend/parking-booth/common.php
- Remove `Access-Control-Allow-Origin: *`
- Add booth staff authentication check
- Add CSRF token support
- Restrict CORS to specific origins

#### backend/controllers/ReservationController.php
- Line in `getReservations()`: Change to use `$_SESSION['user_id']` not query param
- Line in `createReservation()`: Verify `$data['user_id']` matches session

#### backend/admin/login.php
- Add `session_regenerate_id(true)` after setting session
- Change `admin_staff_password_hash()` usage to `password_verify()`
- Add rate limiting on login attempts
- Add account lockout after N failures

---

### HIGH PRIORITY - FIX SOON

#### backend/controllers/AuthController.php
- Add rate limiting on login/register
- Add CAPTCHA after N failures
- Remove logout() method or implement properly (should call session_destroy())
- Add `session_regenerate_id(true)` after login

#### backend/controllers/UserController.php
- Add current password verification for profile updates
- Prevent email change without verification
- Add password strength confirmation

#### backend/middleware/AuthMiddleware.php
- Rename to require explicit auth checks or auto-invoke
- Add role checking capability
- Add CSRF token validation

#### backend/models/ParkingSlot.php
- Add row locking for concurrent reservation prevention

#### All password reset/OTP files
- Add CSRF token to forms
- Rate limit OTP requests
- Validate OTP format strictly

---

## 11. PRIORITIZED IMPLEMENTATION ROADMAP

### Phase 1: CRITICAL (Week 1-2)
1. ✅ Add auth middleware to ALL admin endpoints
2. ✅ Add CSRF token support globally
3. ✅ Fix weak admin password hashing
4. ✅ Fix query param vulnerability in ReservationController
5. ✅ Fix booking for other users vulnerability
6. ✅ Restrict CORS origins
7. ✅ Add session timeout configuration

### Phase 2: HIGH (Week 3-4)
1. ✅ Implement rate limiting (login, OTP, reservations)
2. ✅ Enforce RBAC in controllers
3. ✅ Improve input validation across all endpoints
4. ✅ Implement audit logging
5. ✅ Add password confirmation for sensitive operations
6. ✅ Add session invalidation on role changes

### Phase 3: MEDIUM (Week 5-6)
1. ✅ Add XSS protection globally
2. ✅ Implement request signing for API
3. ✅ Add IP whitelisting for admin
4. ✅ Implement device fingerprinting (optional)
5. ✅ Migrate to JWT tokens (optional but recommended)
6. ✅ Add database connection encryption

---

## 12. IMPLEMENTATION COMPLEXITY ESTIMATES

### CRITICAL Issues (Est. 40-60 hours)

| Task | Complexity | Hours | Priority |
|------|-----------|-------|----------|
| Add admin auth middleware | Low | 8 | P0 |
| Fix weak password hashing | Low | 4 | P0 |
| Add CSRF protection | Medium | 16 | P0 |
| Fix ReservationController auth | Low | 4 | P0 |
| Add session timeout | Low | 2 | P0 |
| Fix CORS settings | Low | 2 | P0 |

### HIGH Issues (Est. 30-50 hours)

| Task | Complexity | Hours | Priority |
|------|-----------|-------|----------|
| Add rate limiting | Medium | 12 | P1 |
| Implement RBAC enforcement | Medium | 16 | P1 |
| Improve input validation | Low | 10 | P1 |
| Add audit logging | Medium | 8 | P1 |
| Add password verification | Low | 4 | P1 |

### MEDIUM Issues (Est. 20-40 hours)

| Task | Complexity | Hours | Priority |
|------|-----------|-------|----------|
| Add XSS protection | Medium | 12 | P2 |
| Implement request signing | High | 16 | P2 |
| Add IP whitelisting | Low | 4 | P2 |
| Device fingerprinting | High | 8 | P2 |

**TOTAL ESTIMATED EFFORT: 90-150 hours (2-4 weeks)**

---

## 13. RECOMMENDED ARCHITECTURE IMPROVEMENTS

### 1. Create Centralized Auth Middleware
```
backend/middleware/AdminAuthMiddleware.php
- Check $_SESSION['sndra_admin']
- Verify role
- Verify session timeout
- Auto-invoke in all admin/* endpoints
```

### 2. Implement CSRF Token Middleware
```
backend/middleware/CsrfMiddleware.php
- Generate token for GET requests
- Validate token for POST/PUT/DELETE
- Store in session
- Validate on each state-changing request
```

### 3. Create Rate Limiter Helper
```
backend/utils/RateLimiter.php
- Track attempts per IP/user_id/action
- Enforce limits (3 failed logins = 5 min lockout)
- Clear old attempts periodically
```

### 4. Create RBAC Helper
```
backend/middleware/RbacMiddleware.php
- Check user role against allowed roles
- Enforce permissions per endpoint
- Provide helper functions for permission checks
```

### 5. Improve Error Handling
```
backend/middleware/ErrorMiddleware.php
- Log errors securely without exposure
- Return generic error messages to client
- Log detailed errors internally
- Track error patterns for security alerts
```

### 6. Add Audit Logging
```
backend/utils/AuditLogger.php
- Log all admin actions
- Log all payment operations
- Log all auth operations
- Store in database with retention policy
```

---

## 14. TESTING RECOMMENDATIONS

### Security Testing Checklist

- [ ] Try accessing admin endpoints without login (should 401)
- [ ] Try accessing booth endpoints without auth (currently succeeds - BAD)
- [ ] Try creating reservation for user_id != session user_id (currently succeeds - BAD)
- [ ] Try viewing another user's reservations via query param (currently succeeds - BAD)
- [ ] Try using CSRF tokens from different session (should fail after fix)
- [ ] Try password reset OTP brute force (no limit - BAD)
- [ ] Try login brute force (no limit - BAD)
- [ ] Check session persistence after logout (should clear)
- [ ] Check password hash in database (currently SHA256 - BAD)
- [ ] Check admin password reset (probably missing)

### Tools to Use
- OWASP ZAP for automated scanning
- Burp Suite Community for manual testing
- SQLmap for SQL injection testing (should all fail)
- CSRF testing by creating cross-domain POST requests

---

## 15. COMPLIANCE & STANDARDS

### Current Status ❌

- OWASP Top 10: **Fails 8/10**
  - ✗ A1: Broken Access Control (admin/booth unprotected)
  - ✗ A2: Cryptographic Failures (SHA256 hashing, no HTTPS enforcement)
  - ✗ A3: Injection (partial - SQL safe but other injection vectors)
  - ✗ A4: Insecure Design (no RBAC, no rate limiting)
  - ✗ A5: Security Misconfiguration (CORS open, errors exposed)
  - ✗ A6: Vulnerable Components (old PHP version? check)
  - ✗ A7: Authentication Failures (weak passwords, no MFA)
  - ⚠️ A8: Data Integrity Failures (partial - some transaction issues)
  - ✓ A9: Logging & Monitoring (partially - logs to error_log)
  - ❌ A10: SSRF (not assessed, likely low risk)

- **GDPR Readiness:** ❌ No consent tracking, no encryption, no audit trail
- **PCI DSS (if taking payments):** ❌ Major failures - no HTTPS enforced, no encryption, weak auth
- **SQL Injection Protection:** ✓ Good (prepared statements used)
- **XSS Protection:** ❌ No output encoding, no CSP headers
- **Session Security:** ⚠️ Partial (regeneration on user login only)

---

## CONCLUSION

The SNDRA Park backend has **CRITICAL SECURITY VULNERABILITIES** that must be addressed immediately:

1. **Admin system completely bypassed** - all endpoints open to public
2. **Booth booth endpoints open** - anyone can process payments, record times
3. **User data exposure** - users can view/create reservations for others
4. **Weak authentication** - SHA256 admin passwords, no CSRF protection
5. **Poor design** - no RBAC, no rate limiting, no audit trail

**Estimated timeline to fix:**
- Critical issues: 2 weeks
- Critical + High: 4 weeks  
- All issues: 6-8 weeks

**Risk if not fixed:** System is currently vulnerable to complete unauthorized access, data theft, and financial manipulation.

---

## ACTION ITEMS

### Immediate (Next 48 Hours)
1. [ ] Add admin session check to all admin/* endpoints
2. [ ] Add booth authentication or restrict endpoints
3. [ ] Fix ReservationController vulnerability (query param)
4. [ ] Disable CORS for public access

### This Week
1. [ ] Implement CSRF tokens
2. [ ] Fix admin password hashing
3. [ ] Add rate limiting to login/OTP
4. [ ] Add session timeout

### Next 2 Weeks
1. [ ] Implement RBAC enforcement
2. [ ] Improve input validation
3. [ ] Add audit logging
4. [ ] Add better error handling

---

**Report Prepared**: March 31, 2026  
**Next Review**: After critical issues fixed (estimated mid-April 2026)
