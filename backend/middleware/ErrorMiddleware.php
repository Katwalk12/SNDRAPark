<?php

require_once __DIR__ . '/../utils/ResponseHelper.php';
require_once __DIR__ . '/AuditLogger.php';

class ErrorMiddleware
{
    // Error messages that are safe to display to users
    private const SAFE_MESSAGES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable'
    ];

    /**
     * Handle exceptions with security considerations
     */
    public static function handle($exception)
    {
        $code = (int) $exception->getCode();

        // Ensure valid HTTP status code
        if ($code < 400 || $code > 599) {
            $code = 500;
        }

        // Get safe error message
        $safeMessage = self::getSafeMessage($code, $exception);

        // Log detailed error information for debugging (not exposed to client)
        self::logError($exception, $code);

        // Return safe response
        ResponseHelper::error($safeMessage, $code);
    }

    /**
     * Handle PHP errors and convert to exceptions
     */
    public static function handleError($errno, $errstr, $errfile, $errline)
    {
        // Only handle errors that should be converted to exceptions
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $exception = new ErrorException($errstr, 0, $errno, $errfile, $errline);
        self::handle($exception);

        // Don't execute PHP's internal error handler
        return true;
    }

    /**
     * Handle fatal errors
     */
    public static function handleFatalError()
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );
            self::handle($exception);
        }
    }

    /**
     * Get safe error message that doesn't leak sensitive information
     */
    private static function getSafeMessage(int $code, Throwable $exception): string
    {
        // For validation errors (422), keep the original message as it's user-safe
        if ($code === 422) {
            return $exception->getMessage();
        }

        // For authentication/authorization errors, keep specific messages
        if (in_array($code, [401, 403])) {
            return $exception->getMessage();
        }

        // For all other errors, use generic safe messages
        return self::SAFE_MESSAGES[$code] ?? 'An error occurred';
    }

    /**
     * Log detailed error information securely
     */
    private static function logError(Throwable $exception, int $code): void
    {
        $errorDetails = [
            'message' => $exception->getMessage(),
            'code' => $code,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => self::sanitizeStackTrace($exception->getTraceAsString()),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => self::getClientIP(),
            'session_id' => session_id(),
            'user_id' => $_SESSION['user_id'] ?? null
        ];

        // Determine log level based on error code
        $status = 'error';
        if ($code >= 400 && $code < 500) {
            $status = 'warning'; // Client errors
        }

        // Log to audit system
        AuditLogger::logSecurityEvent('application_error', 'error_handling', $status,
            json_encode($errorDetails));

        // Also log to PHP error log with sanitized information
        $safeLogMessage = sprintf(
            "[%s] Error %d in %s:%d - %s",
            date('Y-m-d H:i:s'),
            $code,
            basename($exception->getFile()),
            $exception->getLine(),
            self::sanitizeErrorMessage($exception->getMessage())
        );

        error_log($safeLogMessage);
    }

    /**
     * Sanitize stack trace to remove sensitive information
     */
    private static function sanitizeStackTrace(string $trace): string
    {
        $lines = explode("\n", $trace);
        $sanitized = [];

        foreach ($lines as $line) {
            // Remove full paths, keep only file names
            $line = preg_replace('/\/[^\/\s]+\//', '/.../', $line);
            // Remove sensitive parameters from function calls
            $line = preg_replace('/->([^(]+)\([^)]*password[^)]*\)/i', '->$1(***)', $line);
            $line = preg_replace('/->([^(]+)\([^)]*token[^)]*\)/i', '->$1(***)', $line);
            $line = preg_replace('/->([^(]+)\([^)]*key[^)]*\)/i', '->$1(***)', $line);

            $sanitized[] = $line;
        }

        return implode("\n", $sanitized);
    }

    /**
     * Sanitize error message to remove sensitive data
     */
    private static function sanitizeErrorMessage(string $message): string
    {
        // Remove database connection details
        $message = preg_replace('/mysqli.*@\w+/', 'mysqli@***', $message);
        $message = preg_replace('/SQLSTATE\[\w+\]\s*\[\d+\]/', 'SQLSTATE[***]', $message);

        // Remove file paths
        $message = preg_replace('/\/[^\/\s]+\//', '/.../', $message);

        // Remove potential sensitive data patterns
        $message = preg_replace('/password[^=]*=[\'"]?[^\'"\s]+/i', 'password=***', $message);
        $message = preg_replace('/token[^=]*=[\'"]?[^\'"\s]+/i', 'token=***', $message);
        $message = preg_replace('/key[^=]*=[\'"]?[^\'"\s]+/i', 'key=***', $message);

        return $message;
    }

    /**
     * Get client IP address
     */
    private static function getClientIP(): string
    {
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Set up global error handling
     */
    public static function setupGlobalErrorHandling(): void
    {
        // Set error reporting level
        error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

        // Set custom error handler
        set_error_handler([self::class, 'handleError']);

        // Set exception handler
        set_exception_handler([self::class, 'handle']);

        // Set fatal error handler
        register_shutdown_function([self::class, 'handleFatalError']);

        // Disable error display in production
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
    }
}
