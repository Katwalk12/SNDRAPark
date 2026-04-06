<?php

require_once __DIR__ . '/../../routes/api.php';
require_once __DIR__ . '/../../controllers/ParkingController.php';
require_once __DIR__ . '/../../middleware/ErrorMiddleware.php';
require_once __DIR__ . '/../../utils/ResponseHelper.php';

ErrorMiddleware::setupGlobalErrorHandling();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $handler = resolveApiAction('parking', $_SERVER['REQUEST_METHOD'], 'default');

    if (!$handler) {
        ResponseHelper::error('Route not found.', 404);
    }

    $controller = new ParkingController();
    $controller->{$handler}();
} catch (Throwable $exception) {
    ErrorMiddleware::handle($exception);
}
