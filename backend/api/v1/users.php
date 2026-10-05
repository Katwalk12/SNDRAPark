<?php

require_once __DIR__ . '/../../routes/api.php';
require_once __DIR__ . '/../../controllers/UserController.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/ErrorMiddleware.php';
require_once __DIR__ . '/../../utils/RequestHelper.php';
require_once __DIR__ . '/../../utils/ResponseHelper.php';
require_once __DIR__ . '/../../utils/CsrfGuard.php';

ErrorMiddleware::setupGlobalErrorHandling();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    AuthMiddleware::authorizeRequest();

    // This file is its own entry point, so it never passed through the guard in
    // api/v1/index.php -- every state-changing action here (update, vehicles,
    // and now tutorial) was reachable with session cookies alone. The only
    // caller is the driver dashboard, which loads assets/js/csrf.js and so has
    // been sending the token on these requests all along.
    CsrfGuard::requireValidToken();

    $action = RequestHelper::query('action', 'default');
    $handler = resolveApiAction('users', $_SERVER['REQUEST_METHOD'], $action);

    if (!$handler) {
        ResponseHelper::error('Route not found.', 404);
    }

    $controller = new UserController();
    $reflection = new ReflectionMethod($controller, $handler);

    if ($reflection->getNumberOfParameters() > 0) {
        $controller->{$handler}(RequestHelper::data());
    } else {
        $controller->{$handler}();
    }
} catch (Throwable $exception) {
    ErrorMiddleware::handle($exception);
}
