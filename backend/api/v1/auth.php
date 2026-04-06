<?php

require_once __DIR__ . '/../../routes/api.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../middleware/ErrorMiddleware.php';
require_once __DIR__ . '/../../utils/RequestHelper.php';
require_once __DIR__ . '/../../utils/ResponseHelper.php';

ErrorMiddleware::setupGlobalErrorHandling();

header('Access-Control-Allow-Origin: *');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $action = RequestHelper::query('action', 'login');
    $handler = resolveApiAction('auth', $_SERVER['REQUEST_METHOD'], $action);

    if (!$handler) {
        ResponseHelper::error('Route not found.', 404);
    }

    $controller = new AuthController();
    $controller->{$handler}(RequestHelper::data());
} catch (Throwable $exception) {
    ErrorMiddleware::handle($exception);
}
