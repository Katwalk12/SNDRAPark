<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/CsrfMiddleware.php';

/**
 * One CSRF gate for the endpoint families that are not the admin dashboard.
 *
 * The admin endpoints have had CSRF since the March audit, wired through
 * admin_require_csrf() in backend/admin/common.php. The other three families
 * -- the member REST API under backend/api/v1, the member page endpoints under
 * backend/user and backend/parking, and the booth endpoints under
 * backend/parking-booth -- authenticated the caller but never checked that the
 * request was one the caller meant to make. A logged-in driver visiting a
 * hostile page could be made to cancel a reservation; a booth teller could be
 * made to mark a stay paid.
 *
 * SameSite=Strict on the session cookie blocks the straightforward version of
 * that in current browsers, which is why this was a gap rather than a hole.
 * It is not a token: it does nothing for a same-site subdomain, it is ignored
 * by older clients, and it is a browser-side control protecting a server-side
 * decision. This class is the token.
 *
 * Kept separate from CsrfMiddleware because the middleware throws and each
 * family renders errors its own way -- booth_error(), ResponseHelper::error()
 * and admin_error() all produce different JSON. Callers catch and translate.
 */
class CsrfGuard
{
    /** Methods that change state and therefore need a token. */
    private const UNSAFE_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * Endpoints reached by someone who is not signed in.
     *
     * CSRF is an attack on an authenticated session: the forged request is
     * dangerous because the victim's cookie makes it legitimate. Where there
     * is no session to ride, a token adds ceremony and no protection --
     * forging a login just signs the victim into an account the attacker
     * already controls.
     *
     * That covers the sign-in forms, the Google OAuth hand-off (guarded by its
     * own one-time state token), the token endpoint itself, the locked-account
     * appeal form (deliberately unauthenticated -- the whole point is that the
     * person cannot log in), and the password-reset flow, where the OTP is the
     * real gate and it is emailed to the account holder rather than being
     * guessable by whoever forged the request.
     *
     * Each one carries its own defences instead: rate limiting on all of them,
     * plus the honeypot on the admin form, the human check on sign-up, and the
     * hashed single-use OTP with a 5-attempt ceiling on the reset flow.
     *
     * Matched against the request path, anchored at the end, so a query string
     * or a path prefix cannot be used to slip past the list.
     */
    private const EXEMPT_PATH_PATTERN =
        '#/(backend/(api/v1/(login|register)|admin/login\.php|parking-booth/login\.php|auth/(google_auth|google_callback|csrf-token)\.php|user/submit-appeal\.php)|(send|verify|check)-reset-otp\.php|forgot-password\.php|reset-password\.php)$#i';

    public static function isUnsafeRequest(): bool
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        return in_array($method, self::UNSAFE_METHODS, true);
    }

    /**
     * True when this request is on the exemption list above.
     */
    public static function isExemptRequest(): bool
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';

        if ($path !== '' && preg_match(self::EXEMPT_PATH_PATTERN, $path) === 1) {
            return true;
        }

        // The router rewrites /backend/api/v1/login to index.php, so by the
        // time this runs the path may no longer name the action. The query
        // form (index.php?action=login) never did.
        $action = strtolower(trim((string) ($_GET['action'] ?? '')));

        return in_array($action, ['login', 'register'], true)
            && stripos($path, '/backend/api/v1') !== false;
    }

    /**
     * The token for this session, creating one if there is none.
     *
     * Safe to hand back in any same-origin response: CORS keeps a cross-origin
     * page from reading the body, which is the whole reason the double-submit
     * pattern works.
     */
    public static function issueToken(): string
    {
        return CsrfMiddleware::getToken();
    }

    /**
     * Refuse the request unless it carries a valid token.
     *
     * Safe methods and exempt paths return immediately. Everything else must
     * present the token in the X-CSRF-Token header, the _csrf_token form
     * field, or a _csrf_token key in a JSON body.
     *
     * @throws RuntimeException 403 when the token is missing or wrong.
     */
    public static function requireValidToken(): void
    {
        if (!self::isUnsafeRequest() || self::isExemptRequest()) {
            return;
        }

        try {
            CsrfMiddleware::validate();
        } catch (RuntimeException $exception) {
            // The middleware raises 419, which Apache rewrites to 500 because
            // it has no reason phrase for it -- the same trap admin_validate_csrf()
            // documents. 403 keeps a refused request distinguishable from a
            // server fault, so the client knows to re-fetch a token and retry.
            throw new RuntimeException(
                'Security validation failed. Refresh the page and try again.',
                403
            );
        }
    }
}
