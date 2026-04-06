<?php

declare(strict_types=1);

class DateHelper
{
    public static function format(?string $date, string $format = 'Y-m-d H:i:s', string $default = ''): string
    {
        if ($date === null || trim($date) === '') {
            return $default;
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $default;
        }

        return date($format, $timestamp);
    }
}
