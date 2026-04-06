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
            'data' => $data
        ], $status);
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
