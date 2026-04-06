<?php

declare(strict_types=1);

/**
 * CSRF Middleware - Protects against Cross-Site Request Forgery attacks
 * Generates and validates CSRF tokens for state-changing operations (POST, PUT, DELETE, PATCH)
 */
class CsrfMiddleware
{
    private const TOKEN_FIELD_NAME = '_csrf_token';
    private const LEGACY_TOKEN_FIELD_NAME = 'csrf_token';
    private const TOKEN_HEADER_NAME = 'X-CSRF-Token';
    private const SESSION_KEY = '_csrf_token';
    private const TOKEN_LIFETIME = 3600; // 1 hour default

    /**
     * Initialize CSRF protection
     * Must be called at the start of every request
     */
    public static function initialize(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Generate token if not exists or expired
        self::ensureTokenExists();
    }

    /**
     * Generate a new CSRF token and store in session
     */
    public static function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        
        $_SESSION[self::SESSION_KEY] = [
            'token' => $token,
            'created_at' => time()
        ];

        return $token;
    }

    /**
     * Ensure token exists in session, generate if needed
     */
    private static function ensureTokenExists(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            self::generateToken();
            return;
        }

        $tokenData = $_SESSION[self::SESSION_KEY];

        // Check token expiration
        if (!isset($tokenData['created_at']) || 
            (time() - $tokenData['created_at']) > self::TOKEN_LIFETIME) {
            self::generateToken();
        }
    }

    /**
     * Get current CSRF token
     */
    public static function getToken(): string
    {
        self::initialize();
        return $_SESSION[self::SESSION_KEY]['token'] ?? '';
    }

    /**
     * Validate CSRF token from request
     * Checks POST data, JSON body, and headers
     * 
     * @throws RuntimeException if token is invalid or missing
     */
    public static function validate(): bool
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // CSRF check only required for state-changing operations
        if (!in_array($requestMethod, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return true;
        }

        self::initialize();

        $providedToken = self::getProvidedToken();
        $sessionToken = $_SESSION[self::SESSION_KEY]['token'] ?? '';

        if (empty($providedToken) || empty($sessionToken)) {
            throw new RuntimeException('CSRF token missing or invalid.', 419);
        }

        if (!hash_equals($sessionToken, $providedToken)) {
            throw new RuntimeException('CSRF token validation failed.', 419);
        }

        return true;
    }

    /**
     * Get CSRF token from request (from POST data, JSON, or headers)
     */
    private static function getProvidedToken(): string
    {
        // Check POST data
        if (isset($_POST[self::TOKEN_FIELD_NAME])) {
            return (string) $_POST[self::TOKEN_FIELD_NAME];
        }

        if (isset($_POST[self::LEGACY_TOKEN_FIELD_NAME])) {
            return (string) $_POST[self::LEGACY_TOKEN_FIELD_NAME];
        }

        // Check request headers
        $headerToken = self::getHeaderToken();
        if ($headerToken !== '') {
            return $headerToken;
        }

        // Check JSON body
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $body = file_get_contents('php://input');
            $data = json_decode($body, true);
            
            if (is_array($data) && isset($data[self::TOKEN_FIELD_NAME])) {
                return (string) $data[self::TOKEN_FIELD_NAME];
            }

            if (is_array($data) && isset($data[self::LEGACY_TOKEN_FIELD_NAME])) {
                return (string) $data[self::LEGACY_TOKEN_FIELD_NAME];
            }
        }

        return '';
    }

    private static function getHeaderToken(): string
    {
        $serverKeys = [
            'HTTP_X_CSRF_TOKEN',
            'X_CSRF_TOKEN',
            self::TOKEN_HEADER_NAME
        ];

        foreach ($serverKeys as $key) {
            if (!empty($_SERVER[$key])) {
                return (string) $_SERVER[$key];
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, self::TOKEN_HEADER_NAME) === 0 && $value !== '') {
                    return (string) $value;
                }
            }
        }

        return '';
    }

    /**
     * Get token field name for forms
     */
    public static function getFieldName(): string
    {
        return self::TOKEN_FIELD_NAME;
    }

    /**
     * Get token header name for AJAX/API requests
     */
    public static function getHeaderName(): string
    {
        return self::TOKEN_HEADER_NAME;
    }

    /**
     * Generate HTML input field with CSRF token
     */
    public static function getInputField(): string
    {
        $token = self::getToken();
        $fieldName = self::getFieldName();
        return sprintf('<input type="hidden" name="%s" value="%s">', 
            htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Refresh CSRF token (useful after login/privilege changes)
     */
    public static function refresh(): string
    {
        unset($_SESSION[self::SESSION_KEY]);
        return self::generateToken();
    }
}
