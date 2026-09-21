<?php

class ResponseHelper
{
    public static function json($payload, $status = 200)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($message, $data = [], $status = 200)
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => self::withCsrfToken($data)
        ], $status);
    }

    /**
     * Attach the session's CSRF token to a successful response.
     *
     * The router now refuses state-changing API calls without a token, so the
     * client has to be able to get one. This is the same trick the admin
     * dashboard has always used -- every successful response carries a fresh
     * token, so the page is armed for its next POST without a separate fetch.
     *
     * Only added to a list-shaped payload; a handler returning a scalar or a
     * string is left alone rather than being quietly reshaped into an array.
     * Safe to expose: CORS keeps a hostile origin from reading the body, which
     * is what makes the double-submit pattern work at all.
     */
    private static function withCsrfToken($data)
    {
        if (!is_array($data) || !class_exists('CsrfGuard')) {
            return $data;
        }

        // A list (vehicles, reservations) must stay a JSON array -- adding a
        // string key would turn it into an object and break every caller that
        // iterates it.
        if ($data !== [] && array_keys($data) === range(0, count($data) - 1)) {
            return $data;
        }

        $data['csrfToken'] = CsrfGuard::issueToken();

        return $data;
    }

    public static function error($message, $status = 500, $errors = [])
    {
        self::json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $status);
    }
}
