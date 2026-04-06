<?php

declare(strict_types=1);

class RequestHelper
{
    public static function data(): array
    {
        if (isset($GLOBALS['sanitized_json_data']) && is_array($GLOBALS['sanitized_json_data'])) {
            return $GLOBALS['sanitized_json_data'];
        }

        static $cachedData = null;
        if (is_array($cachedData)) {
            return $cachedData;
        }

        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $rawInput = file_get_contents('php://input') ?: '';

        if (stripos($contentType, 'application/json') !== false && $rawInput !== '') {
            $decoded = json_decode($rawInput, true);

            if (is_array($decoded)) {
                $cachedData = $decoded;
                return $cachedData;
            }
        }

        if (!empty($_POST)) {
            $cachedData = $_POST;
            return $cachedData;
        }

        if ($rawInput !== '') {
            parse_str($rawInput, $parsedBody);
            if (is_array($parsedBody)) {
                $cachedData = $parsedBody;
                return $cachedData;
            }
        }

        $cachedData = [];
        return $cachedData;
    }

    public static function query(string $key, $default = null)
    {
        return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
    }
}
