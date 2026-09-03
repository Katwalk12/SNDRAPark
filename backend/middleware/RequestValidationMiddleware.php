<?php

require_once __DIR__ . '/ValidationMiddleware.php';
require_once __DIR__ . '/CsrfMiddleware.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/AuditLogger.php';

class RequestValidationMiddleware
{
    /**
     * Validate incoming request comprehensively
     */
    public static function validateRequest(): void
    {
        self::validateRequestMethod();
        self::validateContentType();
        self::validateContentLength();
        self::validateHeaders();
        self::validateRateLimit();
        self::validateCSRF();
        self::sanitizeInput();
    }

    /**
     * Validate HTTP request method
     */
    private static function validateRequestMethod(): void
    {
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];

        if (!in_array($_SERVER['REQUEST_METHOD'], $allowedMethods)) {
            AuditLogger::logSecurityEvent('invalid_method', 'request_validation', 'warning',
                "Invalid HTTP method: {$_SERVER['REQUEST_METHOD']}");
            throw new RuntimeException('Method not allowed.', 405);
        }
    }

    /**
     * Validate Content-Type header
     */
    private static function validateContentType(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // For methods that typically send body data
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

            // Allow JSON, form data, and multipart
            $allowedTypes = [
                'application/json',
                'application/x-www-form-urlencoded',
                'multipart/form-data'
            ];

            $isAllowed = false;
            foreach ($allowedTypes as $type) {
                if (stripos($contentType, $type) === 0) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed && !empty($contentType)) {
                AuditLogger::logSecurityEvent('invalid_content_type', 'request_validation', 'warning',
                    "Invalid Content-Type: {$contentType}");
                throw new RuntimeException('Unsupported content type.', 415);
            }
        }
    }

    /**
     * Validate Content-Length to prevent oversized requests
     */
    private static function validateContentLength(): void
    {
        $maxContentLength = 10 * 1024 * 1024; // 10MB default

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $contentLength = (int) $_SERVER['CONTENT_LENGTH'];

            if ($contentLength > $maxContentLength) {
                AuditLogger::logSecurityEvent('oversized_request', 'request_validation', 'warning',
                    "Request too large: {$contentLength} bytes");
                throw new RuntimeException('Request entity too large.', 413);
            }

            // Additional check for potential buffer overflow attempts
            if ($contentLength < 0) {
                AuditLogger::logSecurityEvent('invalid_content_length', 'request_validation', 'warning',
                    "Invalid Content-Length: {$contentLength}");
                throw new RuntimeException('Bad request.', 400);
            }
        }
    }

    /**
     * Validate request headers for security
     */
    private static function validateHeaders(): void
    {
        // Check for suspicious headers that might indicate attacks
        $suspiciousHeaders = [
            'X-Forwarded-Host',
            'X-Forwarded-Scheme',
            'X-Forwarded-Proto',
            'X-Forwarded-Port'
        ];

        foreach ($suspiciousHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $value = $_SERVER[$header];
                // Basic validation - these headers should not contain suspicious content
                if (preg_match('/[<>\'"]/', $value)) {
                    AuditLogger::logSecurityEvent('suspicious_header', 'request_validation', 'warning',
                        "Suspicious header {$header}: {$value}");
                    throw new RuntimeException('Bad request.', 400);
                }
            }
        }

        // Validate User-Agent (basic check)
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (strlen($userAgent) > 500) {
            AuditLogger::logSecurityEvent('oversized_user_agent', 'request_validation', 'warning',
                "User-Agent too long: " . strlen($userAgent) . " characters");
            throw new RuntimeException('Bad request.', 400);
        }
    }

    /**
     * Apply rate limiting to the request
     */
    private static function validateRateLimit(): void
    {
        if (self::isAuthRoute()) {
            return;
        }

        $identifier = self::getRequestIdentifier();
        $actionType = self::getActionTypeFromRequest();
        $allowed = RateLimiter::check($actionType, $identifier);
        $remainingAttempts = RateLimiter::getRemainingAttempts($actionType, $identifier);
        $resetSeconds = RateLimiter::getResetTime($actionType, $identifier);
        $resetAt = time() + $resetSeconds;

        // Add rate limit headers to response
        header('X-RateLimit-Remaining: ' . $remainingAttempts);
        header('X-RateLimit-Reset: ' . $resetAt);

        if (!$allowed) {
            AuditLogger::log('rate_limit_exceeded', 'request_validation', 'warning',
                "Rate limit exceeded for {$identifier} on {$actionType}");

            header('Retry-After: ' . max(1, $resetSeconds));
            throw new RuntimeException('Too many requests.', 429);
        }
    }

    /**
     * Validate CSRF token for state-changing requests
     */
    private static function validateCSRF(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // CSRF validation required for state-changing methods
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            if (self::isCsrfExemptRoute()) {
                return;
            }

            try {
                CsrfMiddleware::validate();
            } catch (Exception $e) {
                AuditLogger::logSecurityEvent('csrf_validation_failed', 'request_validation', 'warning',
                    'CSRF token validation failed: ' . $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Sanitize all input data
     */
    private static function sanitizeInput(): void
    {
        // Sanitize GET parameters
        if (!empty($_GET)) {
            $_GET = ValidationMiddleware::sanitizeInput($_GET);
        }

        // Sanitize POST parameters
        if (!empty($_POST)) {
            $_POST = ValidationMiddleware::sanitizeInput($_POST);
        }

        // Sanitize request body for JSON requests
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
            $input = file_get_contents('php://input');
            if (!empty($input)) {
                $jsonData = json_decode($input, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                    $GLOBALS['sanitized_json_data'] = ValidationMiddleware::sanitizeInput($jsonData);
                }
            }
        }
    }

    /**
     * Get a unique identifier for rate limiting (IP + session)
     */
    private static function getRequestIdentifier(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // If user is logged in, include user ID for per-user limits
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) {
            return "user_{$userId}";
        }

        return $ip;
    }

    /**
     * Determine action type from request for rate limiting
     */
    private static function getActionTypeFromRequest(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // Authentication endpoints
        if (preg_match('#/auth/(login|register|forgot-password)#', $uri)) {
            return 'login';
        }

        // Admin endpoints
        if (preg_match('#/admin/#', $uri)) {
            return 'admin_action';
        }

        // API endpoints
        if (preg_match('#/api/#', $uri)) {
            return 'api_call';
        }

        // Default to API call
        return 'api_call';
    }

    private static function isAuthRoute(): bool
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        return (bool) preg_match('#/backend/api/v1/(login|register|session|logout)$#i', $uri);
    }

    private static function isCsrfExemptRoute(): bool
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

        // Exempt the classic API login/register endpoints. The router may use auth.php?action=login
        // so explicitly check for auth.php with action query as well as the neat route paths.
        $isAuthPhp = false;
        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $action = strtoupper((string) ($_GET['action'] ?? ''));

        if (strcasecmp($scriptName, 'auth.php') === 0 && in_array(strtolower($action), ['login', 'register'], true)) {
            $isAuthPhp = true;
        }

        return $isAuthPhp || (bool) preg_match(
            '#/backend/(api/v1/(login|register)|admin/login\.php|parking-booth/login\.php)$#i',
            $uri
        );
    }

    /**
     * Validate API request structure
     */
    public static function validateApiRequest(array $requiredFields = []): array
    {
        // Get request data based on content type
        $data = self::getRequestData();

        // Validate required fields
        if (!empty($requiredFields)) {
            ValidationMiddleware::validateRequired($data, $requiredFields);
        }

        return $data;
    }

    /**
     * Get request data from appropriate source
     */
    public static function getRequestData(): array
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // For GET requests, use query parameters
        if ($method === 'GET') {
            return $_GET;
        }

        // For JSON requests, use sanitized JSON data
        if (isset($GLOBALS['sanitized_json_data'])) {
            return $GLOBALS['sanitized_json_data'];
        }

        // For form data, use POST parameters
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            // Check if it's JSON
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') === 0) {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                return $data ?: [];
            }

            // Otherwise use POST data
            return $_POST;
        }

        return [];
    }

    /**
     * Validate file upload security
     */
    public static function validateFileUpload(string $fieldName, array $allowedTypes = [], int $maxSize = 5242880): array
    {
        if (!isset($_FILES[$fieldName])) {
            throw new RuntimeException('No file uploaded.', 400);
        }

        $file = $_FILES[$fieldName];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File too large (server limit).',
                UPLOAD_ERR_FORM_SIZE => 'File too large (form limit).',
                UPLOAD_ERR_PARTIAL => 'File upload incomplete.',
                UPLOAD_ERR_NO_FILE => 'No file uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error.',
                UPLOAD_ERR_CANT_WRITE => 'Cannot write file.',
                UPLOAD_ERR_EXTENSION => 'File upload blocked by extension.'
            ];

            $message = $errorMessages[$file['error']] ?? 'File upload failed.';
            throw new RuntimeException($message, 400);
        }

        // Check file size
        if ($file['size'] > $maxSize) {
            throw new RuntimeException('File too large.', 400);
        }

        // Check file type
        if (!empty($allowedTypes)) {
            $fileType = mime_content_type($file['tmp_name']);
            if (!in_array($fileType, $allowedTypes)) {
                AuditLogger::logSecurityEvent('invalid_file_type', 'file_upload', 'warning',
                    "Invalid file type: {$fileType}");
                throw new RuntimeException('Invalid file type.', 400);
            }
        }

        // Check file name for security
        $fileName = basename($file['name']);
        if (preg_match('/[<>\'"]/', $fileName) || strlen($fileName) > 255) {
            throw new RuntimeException('Invalid file name.', 400);
        }

        return $file;
    }

    /**
     * Validate and get pagination parameters
     */
    public static function validatePagination(int $defaultLimit = 50, int $maxLimit = 100): array
    {
        $page = isset($_GET['page']) ? ValidationMiddleware::validateInteger($_GET['page'], 'page', 1) : 1;
        $limit = isset($_GET['limit']) ? ValidationMiddleware::validateInteger($_GET['limit'], 'limit', 1, $maxLimit) : $defaultLimit;

        return [
            'page' => $page,
            'limit' => $limit,
            'offset' => ($page - 1) * $limit
        ];
    }
}
