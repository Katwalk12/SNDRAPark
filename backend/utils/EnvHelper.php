<?php

class EnvHelper
{
    private static $loaded = false;
    private static $values = [];

    public static function load($path = null)
    {
        if (self::$loaded) {
            return self::$values;
        }

        $envPath = $path ?: dirname(__DIR__, 2) . '/.env';

        if (file_exists($envPath)) {
            $values = parse_ini_file($envPath, false, INI_SCANNER_RAW);

            if (is_array($values)) {
                self::$values = $values;

                foreach (self::$values as $key => $value) {
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                    }
                    if (getenv($key) === false) {
                        putenv($key . '=' . $value);
                    }
                }
            }
        }

        self::$loaded = true;

        return self::$values;
    }

    public static function get($key, $default = null)
    {
        self::load();
        return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
    }
}
