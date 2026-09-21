# SNDRA Park — Security Architecture

**System**: SNDRA Park parking reservation and booth management system
**Stack**: PHP 8.2 (no framework), MySQL/MariaDB, vanilla JavaScript frontend, Apache/XAMPP
**Document version**: September 17, 2026 (rev. 2 — CSRF coverage completed, see §19)
**Related documents**: `SECURITY_AUDIT_REPORT.md` and `SECURITY_AUDIT_QUICK_REFERENCE.md` describe the March 2026 audit that produced most of the controls below. This document describes the system as it stands today.

---

## 1. Scope and purpose

This document describes the security controls implemented in SNDRA Park: what protects each entry point, where the trust boundaries are, and what is deliberately left out. It is written for developers maintaining the codebase, reviewers assessing it, and whoever deploys it.

It covers the application layer only. Host hardening (OS patching, MySQL account privileges, TLS termination, firewall rules) is out of scope and is summarised as deployment requirements in §16.

---

## 2. Trust boundaries and identity realms

The system has **three separate identity realms**. They do not share credentials, session keys, or account tables. This separation is deliberate: a compromise in one realm does not grant access to another.

| Realm | Who | Credential | Account table | Session key |
|---|---|---|---|---|
| **Member** | Drivers who book slots | Email + password, or Google sign-in | `users` | `$_SESSION['user_id']` |
| **Staff** | Admins and supervisors | Email + password (+ optional email 2FA) | `staff_accounts` | `$_SESSION['sndra_admin']` |
| **Booth teller** | Gate operators | 4-digit PIN | `booth_teller_accounts` | `$_SESSION['sndra_admin']` with `role = 'booth'` |

Booth tellers and admins share one session key but are distinguished by the `role` field and, for tellers, by `accountType = 'booth_teller_pin'`. `BoothAuthMiddleware::authenticateBySession()` re-reads the account row from the database on every request and refuses the session if `is_active` has since been cleared — a revoked teller loses access immediately rather than at session expiry.

### Entry points

| Entry point | Path | Guard |
|---|---|---|
| Member REST API | `/backend/api/v1/*` | `AuthMiddleware` → `RBACMiddleware` → `RateLimiter` |
| Member page endpoints | `/backend/user/*`, `/backend/parking/*` | Session `user_id` check per file |
| Admin dashboard API | `/backend/admin/*` | `admin_require_auth()` in `admin/common.php` |
| Booth API | `/backend/parking-booth/*` | `booth_bootstrap_endpoint()` → `BoothAuthMiddleware` |
| Password reset | `/forgot-password.php`, `/verify-reset-otp.php`, `/reset-password.php` | OTP state machine in `otp-common.php` |
| Google OAuth | `/backend/auth/google_*.php` | One-time `state` token |

---

## 3. Authentication

### 3.1 Member login — `backend/controllers/AuthController.php`

1. Password length capped at `PasswordPolicy::MAX_LENGTH` (128) before hashing, so an oversized input cannot be used as a CPU exhaustion vector against bcrypt.
2. `RateLimiter::check('login', $ip, $email)` — 5 attempts per 15 minutes, keyed on IP **and** email together.
3. Account lookup; if the row has a `google_id` and an empty `password_hash`, the user is told to sign in with Google rather than being given a generic failure.
4. `LoginThrottle::lockState()` — refuses login while `login_locked_until` is in the future.
5. `password_verify()` against a `password_hash(PASSWORD_DEFAULT)` (bcrypt) hash.
6. On failure, `LoginThrottle::registerFailure()` increments `failed_login_attempts` and locks the account for 15 minutes at the 5th consecutive failure. The failure streak resets once the lockout window has elapsed since the last failure.
7. On success, `LoginThrottle::clearFailures()` and `session_regenerate_id(true)`.

The failed-attempt counter lives on the user row (`failed_login_attempts`, `last_failed_login_at`, `login_locked_until`) and is **deliberately separate** from the reservation-violation lockout (`account_status`, `account_locked_until`, §14) so the two mechanisms cannot interfere with one another.

Both timestamps written by `LoginThrottle` are generated in PHP rather than by MySQL `NOW()`, so the attempt window and the lockout are always compared against the same clock even when the database server runs in a different timezone.

### 3.2 Admin login — `backend/admin/login.php`

The admin form is the highest-value target in the system and carries four layers, cheapest first:

1. **Honeypot block list** (`backend/admin/security-trap.php`). An IP already caught driving the form is refused for one hour before the credentials are even read. The refusal reuses the rate-limiter's wording so a scanner cannot tell it has been singled out.
2. **Honeypot field** (`admin_notes_hp`). The field is in the DOM but off-screen and out of the tab order — no sequence of keystrokes or clicks puts text in it. A scripted client that fills every input trips it. Because a human cannot trip it by accident, the response is a hard block plus an audit record, not a warning. The field name is deliberately dull so password managers have no reason to autofill it.
3. **Rate limit**, keyed on `IP|email`. Keying on both means one attacker cannot lock every administrator out by hammering their addresses, and one person's typos cannot block colleagues behind a shared office IP. **Only failures are recorded** — counting successes would lock out an admin who legitimately signed in several times in a quarter hour.
4. **Password verification** via `admin_staff_password_verify()`, which accepts both `password_hash()` values and legacy 64-character SHA-256 hex hashes (compared with `hash_equals()`) so pre-migration accounts still work. New hashes are always `password_hash(PASSWORD_DEFAULT)`.

Only `admin` and `supervisor` roles with `is_active = 1` may sign in here.

**Legacy hash retirement.** Unsalted SHA-256 is close to plaintext for any password a person would actually choose, so a leaked `staff_accounts` table gave up every pre-migration admin credential in it. The compatibility branch has to stay — dropping it would lock out every account created before the migration — but it only needs to survive until each holder signs in once. `admin_staff_password_upgrade_hash()` runs immediately after a successful verification, which is the only moment the plaintext exists alongside the account id, and rewrites the row with `password_hash()`. It is best effort by design: a failed `UPDATE` must not fail the login, because the old hash still verifies and the upgrade is simply retried next time.

### 3.3 Admin two-factor authentication — `backend/admin/verify-2fa.php`

When `admin_2fa_enabled` is set in system settings, a successful password check does not create a session. Instead:

- A 6-digit code is generated with `random_int()` and emailed.
- Only `password_hash($code)` is stored in the session, alongside a 300-second expiry and an attempt counter — the session cannot be read for the answer.
- Verification fails closed on expiry and after 5 attempts.
- **Availability fallback**: if the mail cannot be sent, the pending challenge is discarded and the login proceeds. This is a conscious trade — an SMTP outage should not lock the only administrator out of the system. Sites that prefer the opposite behaviour should change `admin_start_two_factor_challenge()`'s caller to fail closed.

`admin_establish_session()` is shared by `login.php` and `verify-2fa.php` so the two paths cannot drift; a second factor that skipped session regeneration or the audit entry would be worse than no second factor.

### 3.4 Booth teller PIN — `backend/parking-booth/login.php`

PINs are 4 digits (`BOOTH_PIN_LENGTH`, mirrored in `frontend/js/booth-login.js`). Because the keyspace is only 10,000, the PIN is protected by rate limiting rather than by its own entropy:

- `RateLimiter::enforce('login', $ip, 'booth-pin')`.
- A per-session failed-attempt counter with its own lockout window.
- PINs are stored as `password_hash()` values and checked with `password_verify()` against active teller rows.
- `session_regenerate_id(true)` on success.

A 4-digit PIN is appropriate only because the booth terminal is physically supervised. It is not a remote-access credential and the booth endpoints should not be exposed to the public internet (§16).

### 3.5 Google sign-in — `backend/auth/google_oauth_common.php`

A one-time `state` token (`bin2hex(random_bytes(16))`) is issued before redirect, validated with `hash_equals()`, and consumed on use — this prevents login CSRF and callback replay. Sessions created through OAuth use `SessionManager::prepareSessionStorage()` so they land on the same session storage path as the rest of the app.

### 3.6 Password reset — `otp-common.php`

A three-step state machine: request → verify → reset.

- OTP is 6 digits, valid for `OTP_EXPIRY_SECONDS` (300).
- Only the **hash** of the OTP is stored (`users.reset_otp_hash`), with `reset_otp_expires_at`.
- `OTP_MAX_VERIFY_ATTEMPTS` (5) per session.
- Requesting a new code invalidates the previous one.
- `RateLimiter::check('otp_request', $ip, $email)` — 3 per 5 minutes.
- After verification, the reset window is `OTP_RESET_SESSION_TTL` (300 seconds); the reset page will not act outside it.
- The new password is compared against the current hash so a reset cannot reuse the same password, and `failed_login_attempts` is cleared on completion.

### 3.7 Password policy — `backend/utils/PasswordPolicy.php`

One class governs registration, password change, and reset, so the rules cannot diverge between them. Defaults, overridable from the `security_settings` table:

| Rule | Default |
|---|---|
| Minimum length | 8 (floor is enforced in code — settings cannot lower it below `MIN_LENGTH`) |
| Maximum length | 128 |
| Uppercase / lowercase / number / special | all required |
| Block personal info | on — rejects passwords containing the user's name, email local part, or birth date |
| Maximum age | 90 days (advisory; surfaced as an expiry warning, not a hard block) |
| Expiry warning | 14 days |

Hashing is `password_hash(PASSWORD_DEFAULT)` (bcrypt) everywhere. No password is ever logged, echoed, or stored in reversible form.

### 3.8 Sign-up bot defence — `backend/utils/HumanCheck.php`

Self-contained, with no third-party CAPTCHA: no API keys to hold, works offline on the XAMPP host, and no visitor IP is handed to an outside service. Three layers:

1. A bait field (`extra_notes_hp`) no person can fill.
2. A minimum fill time (3 seconds), issued **server-side** so it cannot be back-dated by the client the way a hidden timestamp can.
3. A generated arithmetic question whose answer never leaves the server. Numbers are spelled out as words so the sum cannot be lifted with a digit regex and evaluated.

The answer is held as a hash with a 15-minute TTL, a 3-attempt limit, and single-use semantics, so a solved challenge cannot be replayed for a second account.

---

## 4. Session management

### Member sessions — `backend/utils/SessionManager.php`

Cookie flags set before `session_start()`:

```
session.cookie_httponly  = 1
session.cookie_secure    = 1 when HTTPS, else 0
session.cookie_samesite  = Strict
session.use_strict_mode  = 1     (rejects attacker-supplied session IDs)
session.use_only_cookies = 1     (no session ID in URLs)
```

`cookie_secure` is conditional rather than always on: forcing it under plain-HTTP XAMPP would make the browser drop the cookie and nobody could sign in at all. **In production behind TLS this evaluates to 1 automatically** — but see §16.

Per-request validation in `validateSession()`:

- Rejects an inactive session or a missing `user_id`.
- **Idle timeout** — default 1800 seconds, read from `security_settings.session_timeout_minutes`. Expiry destroys the session and writes an audit entry.
- **IP pinning** — a session whose client IP changes is destroyed and logged. This blocks straightforward session-cookie theft at the cost of breaking sessions for users on mobile networks that rotate addresses.
- **Periodic ID rotation** — `session_regenerate_id(true)` every 30 minutes, plus unconditionally at login.

`createSession()` clears all prior session data before populating it, and writes `session_expires_at` to the user row; `destroySession()` clears it, expires the cookie, and destroys the session.

`prepareSessionStorage()` falls back to `storage/sessions/` when the configured `session.save_path` is missing or unwritable — without it, an OAuth callback and the API could end up on different storage paths and the cookie would point at a session file the rest of the app could not read.

### Admin sessions — `backend/admin/common.php`

Same flags, set in `admin_harden_session_cookie()` before `session_start()` (after the session is open PHP has already emitted `Set-Cookie` and `ini_set` is ignored). `cookie_lifetime = 0` makes it a browser-session cookie. `gc_maxlifetime` is pinned to `ADMIN_SESSION_TIMEOUT` (1800) explicitly rather than left to the php.ini default of 1440, which silently made the documented 30 minutes into 24.

Idle timeout is enforced per request in `admin_require_auth()` against `$_SESSION['_admin_last_activity']`. A session whose structure is malformed (missing `id`, `role`, or `email`) is destroyed rather than trusted.

---

## 5. Authorization

### 5.1 Role-based access control — `backend/middleware/RBACMiddleware.php`

A static permission matrix maps three roles to explicit permission strings:

- **`user`** — own profile, own vehicles, own reservations, notifications, feedback.
- **`admin`** — the above plus `admin.*` (dashboard, users, slots, floors, staff, settings, payments, logs, feedback) and `audit.read`.
- **`booth_staff`** — `booth.scan`, `booth.pay`, `booth.monitor`, `booth.realtime`, `booth.recent`, plus reservation and payment updates.

`authorize()` reads the role **from the database**, not from the session, so a role revoked mid-session takes effect on the next request. Every denial is written to the audit log with the user, role, and attempted permission.

`canAccessResource()` adds ownership checks on top of the permission check: a non-admin may only read their own user record, and a reservation is fetched and its `user_id` compared before access is granted.

### 5.2 Admin endpoint gate — `admin_require_auth()`

Every admin endpoint calls this at the top of the file. It performs, in order: rate limit → session presence → session structure → optional RBAC permission → role check → idle timeout → activity timestamp refresh.

The rate limit runs **before** the session check so an unauthenticated flood is refused there rather than reaching the database.

**Supervisor read-only rule**: a `supervisor` satisfies an `admin` requirement on `GET` and is refused on anything else. Because every mutating admin endpoint is a `POST`, the HTTP method is the whole test — and the rule therefore holds for endpoints written later without anyone having to remember it.

Coverage: every admin endpoint calls `admin_require_auth()`. The files that do not are `login.php` (the entry point itself), `verify-2fa.php` (guarded by the pending-2FA session state), and `common.php` / `feedback_common.php` / `security-trap.php` / `audit-log.php`, which are includes, not endpoints.

### 5.3 Booth endpoint gate — `booth_bootstrap_endpoint()`

Every booth endpoint calls `booth_bootstrap_endpoint($methods, $permission)`, which installs error handling, sends headers, answers preflight, and authenticates through `BoothAuthMiddleware`. `scan.php`, `pay.php`, and `walkin.php` are thin aliases that set an action and delegate to `payment.php`, so they inherit its `process_payment` gate.

`BoothAuthMiddleware::authenticate()` tries, in order: an existing booth session, an `X-Booth-API-Key` header (for system-to-system calls, 64 hex characters from `random_bytes(32)`), then an `X-Booth-Token` header (8-hour tokens, also `random_bytes(32)`). All three re-check `is_active` against the database. Failures are audit-logged and return 401/403.

### 5.4 Ownership on member endpoints

Member endpoints under `backend/user/` derive the user ID from `$_SESSION['user_id']` and ignore any client-supplied id. This was the fix for the audit's highest-severity finding, where `ReservationController` read `user_id` from the query string and from the POST body — allowing any authenticated user to read or create reservations for anyone else.

`submit_reservation.php` carried a residual version of the same defect until September 17, 2026: when the session was empty it fell back to a `userId` in the request body, so an unauthenticated caller could book a slot in any user's name. That mattered more than it looks — an unclaimed booking expires into a no-show strike, so the fallback let a stranger walk someone else's account toward the four-strike lock in §14. The fallback is gone; the endpoint now returns 401 when there is no session.

---

## 6. CSRF protection

Two pieces: `CsrfMiddleware` mints and checks the tokens, and `CsrfGuard` decides which requests need one.

### The token — `backend/middleware/CsrfMiddleware.php`

- 256-bit tokens from `random_bytes(32)`, one-hour lifetime, stored in the session.
- Checked only for `POST`, `PUT`, `DELETE`, `PATCH`.
- Accepted from the `_csrf_token` POST field, the legacy `csrf_token` field, the `X-CSRF-Token` header, or a `_csrf_token` key in a JSON body.
- Compared with `hash_equals()` — constant time, no early-exit timing signal.
- `refresh()` runs on every privilege change — admin login, admin 2FA completion, booth PIN login, and member login (in both `SessionManager::createSession()` and `AuthController::createSessionForUser()`) — so a token issued to an anonymous visitor is never valid for the session that replaces it.

### The gate — `backend/utils/CsrfGuard.php`

One class decides whether a given request needs a token, so the rule cannot drift between endpoint families. It exists because the four families render errors differently — `booth_error()`, `ResponseHelper::error()` and `admin_error()` all produce different JSON — so each caller catches and translates rather than sharing one response path.

A rejected token is reported as **403**, not the middleware's native 419: Apache has no reason phrase for 419 and rewrites it to 500, which would make a refused request indistinguishable from a server fault.

### Coverage

Every state-changing endpoint is covered:

| Family | Where it is enforced |
|---|---|
| Admin (`/backend/admin/*`) | `admin_require_csrf()` per endpoint |
| Member REST API (`/backend/api/v1/*`) | `CsrfGuard::requireValidToken()` in the router |
| Member pages (`/backend/user/*`, `/backend/parking/*`) | `parking_require_csrf()` in `parking_bootstrap_endpoint()` |
| Booth (`/backend/parking-booth/*`) | `booth_require_csrf()` in `booth_bootstrap_endpoint()`, after authentication |
| Notifications (`/backend/notifications/*`) | `CsrfGuard::requireValidToken()` in `notifications/common.php` |
| Feedback (`/backend/feedback/submit.php`) | `admin_require_csrf()` |

Enforcing at the router and at the bootstrap rather than per endpoint is deliberate: it covers endpoints written later that forget to ask. The booth check runs *after* authentication so a caller with no session still gets the more useful 401.

### Exemptions

`CsrfGuard::EXEMPT_PATH_PATTERN` names the endpoints reached by someone who is not signed in. CSRF is an attack on an authenticated session — the forged request is dangerous precisely because the victim's cookie makes it legitimate. Where there is no session to ride, a token adds ceremony and no protection; forging a login just signs the victim into an account the attacker already controls.

Exempt: the three sign-in forms, the Google OAuth hand-off (guarded by its own one-time `state` token), the token endpoint, the locked-account appeal form (deliberately unauthenticated — the whole point is that the person cannot log in), and the password-reset flow, where the OTP is the real gate and it is emailed to the account holder rather than being guessable by whoever forged the request.

Each carries its own defences instead: rate limiting on all of them, plus the honeypot on the admin form, the human check on sign-up, and the hashed single-use OTP with a 5-attempt ceiling on the reset flow.

The pattern is anchored at the end of the path, so appending an exempt-looking segment (`/payment.php/login.php`) does not exempt a guarded endpoint, and `?action=login` only exempts inside the `api/v1` router where the rewrite genuinely strips the action from the path. Both are covered by tests.

### Getting the token to the client

The token has to reach same-origin JavaScript for any of this to work. Three routes, in order of preference:

1. **Every successful response carries it.** `admin_success()` always did; `booth_success()` and `ResponseHelper::success()` now do too. A page that has made any successful call is already armed for its next POST. A list-shaped payload is left untouched — adding a string key would turn a JSON array into an object under every caller that iterates it.
2. **`backend/auth/csrf-token.php`** for a page that has not made a call yet. Unauthenticated on purpose: the token is bound to the session cookie and worthless without it, and requiring a login would break the one case it exists for — arming the sign-up and reset pages, which run before there is a session.
3. **`assets/js/csrf.js`** wraps `fetch()` once per page rather than threading a token through roughly thirty scattered call sites. Every same-origin response is read for a token; every same-origin state-changing request gets the latest one attached. Scope is narrow by design: cross-origin requests never see the token, safe methods pass through untouched, and an `X-CSRF-Token` the caller set itself is never overwritten — so the admin dashboard, which handles its own token, is unaffected. On a 403 it refreshes once and retries, because a token can go stale for honest reasons (it expires after an hour, and it is deliberately rotated on sign-in) and the user should not have to reload the page to recover.

Exposing the token in a response body is safe for the same reason the double-submit pattern works at all: CORS (§10) only names allow-listed origins, so a hostile page cannot read the response it would need.

---

## 7. Rate limiting — `backend/middleware/RateLimiter.php`

Database-backed fixed-window counters in `rate_limit_attempts`, with periodic cleanup of expired rows.

| Action | Limit |
|---|---|
| `login` | 5 per 15 minutes |
| `otp_request` | 3 per 5 minutes |
| `admin_action` | 100 per minute |
| `api_call` | 1000 per minute |
| `reservation_create` | 10 per 5 minutes |

Identifiers prefer the authenticated user or admin ID and fall back to client IP. A secondary identifier can be appended (`IP|email`, `IP|booth-pin`) to scope the window more tightly.

**Fails open**: if the database is unreachable, `check()` returns `true` and the request proceeds. This favours availability over enforcement — an outage should not take down the whole application. Note the consequence: a database-level denial of service also disables rate limiting.

Where enforced, responses carry `Retry-After`, and `RequestValidationMiddleware` additionally emits `X-RateLimit-Remaining` and `X-RateLimit-Reset`.

---

## 8. Input validation and injection defence

### SQL injection

All user-controlled values reach the database through `mysqli` prepared statements with `bind_param()` — 165 prepared statements across the backend. The remaining direct `query()` calls fall into three safe categories:

1. **Schema DDL** in `config/database.php` and `config/db.php`, where identifiers are passed through `escapeIdentifier()` / `booth_escape_identifier()` and never derived from request input.
2. **Static reporting SQL** in `admin/get_dashboard_summary.php` and `common/parking-log-feed.php`, built from constants with no interpolated request data.
3. **Integer-cast interpolation**, for example `... WHERE id = " . (int) $reservationId` in `reservation-notifier.php`, where the cast makes injection impossible.

`mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` makes driver errors throw rather than silently return false, so a failed statement cannot be mistaken for an empty result.

### Request validation

`RequestValidationMiddleware::validateRequest()` runs, for the API routes: request size and content-type checks → rate limiting → CSRF → recursive input sanitisation of `$_GET`, `$_POST`, and JSON bodies via `ValidationMiddleware::sanitizeInput()`. `validateApiRequest([...])` additionally asserts required fields are present.

`ValidationMiddleware` provides the typed validators used by the controllers: email (`FILTER_VALIDATE_EMAIL`), dates (format plus range), age (18+), plate numbers, and length bounds.

Booth barcode input is normalised defensively in `booth_normalize_barcode()` — scanner prefixes stripped, control characters and zero-width characters removed, whitespace collapsed, en/em dashes folded — and then reduced to `[A-Z0-9]` for lookup.

---

## 9. Output encoding and XSS

The frontend is rendered by vanilla JavaScript. Values that originate from user or database content are escaped with a local `escapeHtml()` helper (defined in `admin-dashboard.js`, `parking-booth.js`, and `assets/js/notifications.js`) or assigned through `textContent` before being placed in the DOM. Across the frontend there are 71 `innerHTML` assignments against 389 escaped or `textContent` writes.

`X-Content-Type-Options: nosniff` is sent on admin and booth responses so a JSON body holding member records or payment totals cannot be coerced into being rendered as HTML.

There is no Content-Security-Policy header; see gap 3 in §15.

---

## 10. HTTP security headers and CORS

### Headers

Admin responses (`admin_send_security_headers()`):

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Cache-Control: no-store, no-cache, must-revalidate
Pragma: no-cache
```

`no-store` matters specifically because the dashboard is used on shared machines — without it the browser keeps member records and payment totals in the disk cache.

Booth responses (`booth_send_common_headers()`) send `nosniff`, `X-Frame-Options: DENY`, and `X-XSS-Protection: 1; mode=block`.

### CORS — `backend/utils/CorsHelper.php`

One class decides which origins may call the API. The allow list is derived from `APP_URL`, plus `http://localhost` and `http://127.0.0.1` for local development, plus anything in `CORS_EXTRA_ORIGINS`.

The endpoints previously answered `Access-Control-Allow-Origin: *` **while also** sending `Access-Control-Allow-Credentials: true`. Browsers reject that pair outright, so the wildcard bought nothing and every genuine cross-origin credentialed request failed. `resolveOrigin()` now echoes back the caller's origin only when it is on the allow list, and `Vary: Origin` keeps intermediary caches from crossing responses between origins.

### Apache — `.htaccess`

```apache
Options -Indexes
<FilesMatch "^\.|\.(env|ini|log|bak|sql|sqlite|db|key|pem|crt|old|orig|save)$|~$">
  Require all denied
</FilesMatch>
```

Directory listing is off, and dotfiles, credential files, and editor/backup leftovers are refused. This closes a real exposure: Apache has no PHP handler for `.env`, so before this rule `GET /.env` returned 200 with the database, SMTP, and Google OAuth secrets in the clear.

---

## 11. Secrets management

Configuration lives in `.env`, loaded by `EnvHelper` and never committed — `.gitignore` excludes `.env` and `.env.*` while keeping `.env.example` as the documented template. `.env.example` ships with every secret field blank.

Secrets held there: database credentials, SMTP credentials (`MAIL_PASSWORD` is documented as a Google App Password, not the account password), and the Google OAuth client ID and secret.

Defence in depth: the `.htaccess` rule in §10 means that even if `.env` were served from a web-accessible path, Apache refuses it.

`APP_DEBUG` gates all diagnostic output. When it is off, `display_errors` and `display_startup_errors` are forced to `0` and `log_errors` to `1` — a PHP warning rendered into the middle of a JSON body both breaks the parse and prints the server's directory layout.

---

## 12. Error handling and information disclosure

`admin_register_exception_handler()` is the backstop for anything thrown above an endpoint's own `try` block — notably `admin_require_auth()`, which can throw a 429. Without a handler PHP turned that into a fatal and the dashboard received an HTML stack trace with absolute paths where it expected JSON.

Because the handler cannot know where an exception came from, it **never reuses the exception message**. The HTTP status picks the wording from a fixed table (400/401/403/404/405/409/422/429), defaulting to "An unexpected error occurred." The real message goes to the error log on every path.

`admin_safe_error_message()` applies the finer rule used inside endpoints: a 4xx message the endpoint authored itself ("Floor still has active reservations") is shown to the user, but any 5xx message is replaced with a generic fallback, because a 5xx message is whatever the database driver threw.

`admin_debug_details()` returns the exception text in the response `details` bag **only when `APP_DEBUG` is on**. On a query failure that text is the raw mysqli message — table names, column names, and the fragment of SQL that broke — which is a map of the schema handed to anyone who can provoke a 500.

`ErrorMiddleware` does the same job for the API and booth routes, with one difference: on localhost it appends the file basename and line to unexpected errors to make development tractable. This must not be relied on in production, where `REMOTE_ADDR` is not `127.0.0.1`.

**A refusal is not a fault.** `backend/parking-booth/login.php` used to flatten every throwable to a 500 with the exception text in the `details` bag. `RateLimiter::enforce()` throws 429 to cap PIN guessing, so a rate-limited teller saw "Failed to log in to the parking booth", the console showed a server error, and the limiter's own wording was handed to the client. It now carries the status through, picks a safe message per status, sends `Retry-After` on a 429, and routes the driver text through `booth_debug_details()` so it only travels when `APP_DEBUG` is on. This is the same class of defect as the 419→500 rewrite described in §6: a refusal the endpoint meant to make should not be reported as a server fault.

---

## 13. Audit logging

`backend/middleware/AuditLogger.php` writes to the `audit_log` table. Each entry records the user or admin ID, action, resource type and ID, old and new values, IP address, user agent, session ID, status, and a JSON context blob (truncated at the 65,535-byte TEXT limit rather than failing the insert).

Event types are a fixed vocabulary; an unrecognised type is recorded as `UNKNOWN_EVENT` rather than being written verbatim. Levels run `DEBUG` through `CRITICAL`.

Logged categories:

- **Authentication** — member and admin login success/failure, logout, rate-limited sign-ins, honeypot trips.
- **Authorization** — every RBAC denial, with user, role, and attempted permission.
- **Sessions** — creation, expiry, ID regeneration, IP-change violations.
- **User management** — registration, profile updates, password changes, role changes (old and new role both recorded), account lock/unlock, deletion.
- **Reservations and payments** — creation, cancellation, expiry, check-in, payment processed/failed/refunded.
- **Admin actions** — slot and floor changes, settings updates, notifications sent, API key generation (the key itself is masked as `***`).
- **Security events** — rate limit exceeded, CSRF violation, suspicious activity, brute-force detection.

Admins read the log through `backend/admin/get_logs.php` and `audit-log.php`, gated by the `admin.logs.read` / `audit.read` permissions.

---

## 14. Application-level abuse controls

### No-show violation policy — `backend/common/reservation-security.php`

A reservation whose barcode is never scanned expires, releases the slot back to Available, and costs the driver one strike. Three strikes are warnings; the **fourth locks the account with no automatic release date**, so it stays locked until an administrator approves an appeal (`backend/user/submit-appeal.php`).

The grace period runs from the **reserved arrival time**, not from `created_at`. The earlier behaviour penalised a driver who booked a 6pm slot in the morning at 09:30 — for a time that had not come yet.

Grace minutes, warning allowance, and the warning window all come from the settings table, so an operator can loosen them without a deploy; the constants in the file remain the fallback for an un-migrated database.

This lockout is independent of the failed-login lockout (§3.1) and uses different columns, so neither can release or trigger the other.

---

## 15. Known gaps and residual risk

Stated plainly so they can be weighed rather than discovered. Three gaps from rev. 1 have been closed; see §19.

| # | Gap | Risk | Notes |
|---|---|---|---|
| 1 | **No Content-Security-Policy header.** | Medium | The largest remaining gap. Would materially reduce the impact of any XSS that slips past the escaping in §9. Not added blind: the pages load scripts from two CDNs (jsPDF, JsBarcode) and carry inline `<script>` blocks, so a policy written without testing each page would break the app silently. Needs a page-by-page pass. |
| 2 | **Rate limiter fails open** when the database is unreachable. | Medium | Deliberate availability trade-off. A database outage removes brute-force protection at the same time. |
| 3 | **Admin 2FA fails open** when SMTP is unavailable. | Medium | Deliberate — avoids locking the sole administrator out. Sites with a second admin account should change this. |
| 4 | **`SessionManager::getClientIP()` trusts forwarded headers.** It reads `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, and similar before `REMOTE_ADDR`. | Medium | Behind a trusted reverse proxy this is correct. Directly exposed, a client can spoof these headers and defeat the IP-pinning check in §4 and skew rate-limit and audit-log attribution. Only trust these headers when a proxy you control sets them. |
| 5 | **Booth PIN rate limiting counts successes, not just failures.** `RateLimiter::enforce('login', $ip, 'booth-pin')` runs before verification, so every attempt consumes one of 5 per 15 minutes per IP. | Low–Medium (operational) | Found while smoke-testing this revision: repeated booth sign-ins from one terminal lock the booth out, and a booth is one IP by definition. Admin login deliberately records only failures for exactly this reason (§3.2). Combined with the 30-minute session timeout, a shift with several re-logins can hit the ceiling. Move the `enforce` call after verification, or count failures only. |
| 6 | **Legacy SHA-256 admin hashes are still accepted** by `admin_staff_password_verify()`. | Low | Now self-retiring — each account is re-hashed on its next successful sign-in (§3.2). The branch can be deleted once `staff_accounts` holds no 64-character hex hashes; until then a table leaked *before* those sign-ins still exposes the old digests. |
| 7 | **`ErrorMiddleware` is verbose on localhost.** Unexpected errors include the message, file, and line when `REMOTE_ADDR` is `127.0.0.1`/`::1` or `SERVER_NAME` is `localhost`. | Low | Harmless in development; make sure production `SERVER_NAME` is not `localhost`. |
| 8 | **`cookie_secure` is conditional.** It is off on plain HTTP by design so XAMPP development works. | Low (production: High if TLS is absent) | Correct only because production is assumed to be behind TLS. Deploy without TLS and every session cookie travels in the clear. |
| 9 | **IP pinning breaks mobile sessions.** A client whose IP changes mid-session is logged out. | Low | A usability cost accepted for session-theft resistance. |
| 10 | **4-digit booth PIN.** 10,000-value keyspace. | Low, given physical control | Acceptable only for a supervised terminal on a private network. Never expose the booth endpoints publicly. |
| 11 | **`assets/js/csrf.js` cannot replay a streamed request body** on its one 403 retry. | Low | Every current call site passes a string body, so the retry works. A future caller using a `ReadableStream` body would get the original 403 back rather than a transparent retry — correct behaviour, just not transparent. |

---

## 16. Deployment requirements

The application-layer controls above assume the following. None of them are enforced by the code.

1. **Serve over HTTPS.** Without TLS, `session.cookie_secure` evaluates to `0` and every session cookie — member, admin, and booth — is sent in the clear.
2. **Set `APP_DEBUG=false`** in `.env`. This is what suppresses schema details in error responses (§12).
3. **Set `APP_URL`** to the real origin, and do not add hostnames to `CORS_EXTRA_ORIGINS` unless they genuinely need cross-origin access.
4. **Do not deploy with `SERVER_NAME=localhost`** — it enables verbose error messages (gap 8).
5. **Give MySQL a dedicated non-root account** with only the privileges the app needs. `.env.example` ships `DB_USER=root` for XAMPP convenience; that is a development default, not a deployment one.
6. **Keep the booth endpoints off the public internet.** They are protected by a 4-digit PIN behind a supervised terminal.
7. **Terminate at a reverse proxy you control** if you rely on forwarded-IP headers, or the IP-based controls in §4 and §7 can be spoofed (gap 6).
8. **Confirm legacy admin hashes have drained** before deleting the SHA-256 branch from `admin_staff_password_verify()` (gap 6). Each account re-hashes itself on its next sign-in; check with `SELECT COUNT(*) FROM staff_accounts WHERE CHAR_LENGTH(password_hash) = 64 AND password_hash REGEXP '^[0-9a-f]+$'` and force a reset for any account that has not signed in.
9. **Verify `.htaccess` is honoured** — `AllowOverride` must permit it, or the dotfile denial and directory-listing suppression in §10 silently do nothing.
10. **Rotate any credential ever committed or shared**, including the Google App Password and the OAuth client secret.

---

## 17. Control-to-code index

| Control | Implementation |
|---|---|
| Member session lifecycle | `backend/utils/SessionManager.php` |
| Admin session lifecycle | `backend/admin/common.php` |
| Member authentication | `backend/controllers/AuthController.php` |
| Admin authentication | `backend/admin/login.php`, `backend/admin/verify-2fa.php` |
| Admin bot tripwire | `backend/admin/security-trap.php` |
| Booth authentication | `backend/parking-booth/login.php`, `backend/middleware/BoothAuthMiddleware.php` |
| Google OAuth | `backend/auth/google_oauth_common.php` |
| Password reset OTP | `otp-common.php`, `forgot-password.php`, `verify-reset-otp.php`, `reset-password.php` |
| Password policy | `backend/utils/PasswordPolicy.php` |
| Failed-login lockout | `backend/utils/LoginThrottle.php` |
| Sign-up bot defence | `backend/utils/HumanCheck.php` |
| RBAC | `backend/middleware/RBACMiddleware.php` |
| CSRF tokens | `backend/middleware/CsrfMiddleware.php` |
| CSRF enforcement policy | `backend/utils/CsrfGuard.php` |
| CSRF token endpoint | `backend/auth/csrf-token.php` |
| CSRF client plumbing | `assets/js/csrf.js` |
| Rate limiting | `backend/middleware/RateLimiter.php` |
| Request validation and sanitisation | `backend/middleware/RequestValidationMiddleware.php`, `backend/middleware/ValidationMiddleware.php` |
| Error handling | `backend/middleware/ErrorMiddleware.php`, `admin_register_exception_handler()` |
| Audit logging | `backend/middleware/AuditLogger.php` |
| CORS | `backend/utils/CorsHelper.php` |
| Secrets loading | `backend/utils/EnvHelper.php`, `.env`, `.gitignore` |
| Web server hardening | `.htaccess` |
| Abuse policy (no-shows) | `backend/common/reservation-security.php` |
| Security settings storage | `security_settings` table, `backend/config/system-settings.php` |

---

## 18. Verification

`.github/workflows` runs on every push and pull request to `main`:

- `php -l` across every first-party PHP file.
- A check that every PHP file begins exactly with `<?php` — a stray space before the opening tag once took admin login down entirely, because the echoed whitespace made `declare(strict_types=1)` illegal.
- `php tests/run.php` — unit tests covering booth payment calculation, reservation-security policy, CORS origin resolution, password policy, the admin honeypot, the sign-up human check, the CSRF guard, and legacy password-hash handling.
- A JavaScript parse check over `frontend/js/*.js` and `assets/js/*.js`.

The CSRF tests are worth calling out because the guard is the kind of control that fails silently in the wrong direction: a rule that is too loose lets forged requests through, and one that is too tight locks legitimate users out of the app. They assert both halves — that every guarded endpoint is refused without a token, that every exempt endpoint is not asked for one, that safe methods are never challenged, and that the anchoring cannot be defeated by appending an exempt-looking path segment or an `action=login` query outside the API router.

---

## 19. Remediation record — September 17, 2026

What changed in this revision, and how each item was verified.

### Fixed

| Was | Change |
|---|---|
| **CSRF unenforced on the booth, member-page, member-API, notification and feedback endpoints.** The admin dashboard had tokens; nothing else did. `UserController`, `ReservationController` and `ParkingController` never called `RequestValidationMiddleware::validateRequest()`, so `POST /users/update`, the vehicle create/update/delete routes and `POST /reservations` accepted any cross-site request carrying the victim's session cookie. | New `CsrfGuard` enforced at the API router and at both bootstraps, plus `notifications/common.php` and `feedback/submit.php`. New `backend/auth/csrf-token.php` and `assets/js/csrf.js` deliver the token to the client; `booth_success()` and `ResponseHelper::success()` now carry it the way `admin_success()` always did. Token refreshed on every login path. |
| **`submit_reservation.php` trusted a request-supplied user ID** when the session was empty, letting an unauthenticated caller book in anyone's name — and, through the no-show policy, push a stranger's account toward the four-strike lock. | The fallback is removed; the endpoint uses the session id only and returns 401 without one. |
| **Legacy unsalted SHA-256 admin hashes sat indefinitely** in `staff_accounts`. | `admin_staff_password_upgrade_hash()` re-hashes each one at the moment its owner proves they know the password. Best effort — a failed update never fails the login. |
| **Booth login reported a rate-limited refusal as a 500** and leaked the limiter's internal message to the client. | Status carried through, safe message per status, `Retry-After` on 429, driver text gated behind `APP_DEBUG`. |

### Verification

Unit tests: 188 passing, including 37 new assertions across the CSRF guard and legacy hash handling. Full CI suite clean (PHP lint, the `<?php` prefix check, JS parse).

Live smoke test against XAMPP, driving real Chrome:

- Member login → token issued in the response.
- Reservation created through the reservation form with CSRF enforced → succeeded, reservation 110 stored.
- Reservation cancelled through the dashboard → 200, no console errors.
- Booth PIN login → token issued; barcode scan → reservation flipped `Reserved` → `Parked` with `actual_time_in` recorded; payment reached the business layer.
- Admin dashboard loaded and rendered in full, confirming the `booth_success()` change did not disturb `admin_success()`.

The decisive check was the A/B on one endpoint. `POST /backend/user/cancel_reservation.php` with a valid session cookie and **no token** returns:

```
HTTP 403
{"success":false,"message":"Security validation failed. Refresh the page and try again."}
```

The same call **through the page**, same session, returns `200 Reservation cancelled successfully.` The same pattern was confirmed on `/backend/api/v1/users/update`, `/backend/api/v1/reservations` and `/backend/notifications/mark-read.php`; a request carrying a valid token reaches the controller and is answered by the endpoint's own validation rather than by the guard.

Booth rate limiting was confirmed to return `429` with `Retry-After: 721` and no leaked internals, and wrong PINs to return `401`.

### Not changed, and why

A Content-Security-Policy header (gap 1) was left alone deliberately. The pages load scripts from two CDNs and carry inline `<script>` blocks; a policy written without testing each page would break the app with no visible error. It needs a page-by-page pass, which is its own piece of work rather than something to append to this one.
