<?php

class AuthMiddleware
{
    public static function authorizeRequest()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            throw new RuntimeException('Unauthorized request.', 401);
        }

        return true;
    }
}
