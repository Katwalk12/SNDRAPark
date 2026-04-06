<?php

require_once __DIR__ . '/../../routes/api.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/ParkingController.php';
require_once __DIR__ . '/../../controllers/ReservationController.php';
require_once __DIR__ . '/../../controllers/UserController.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/ErrorMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../../middleware/RateLimiter.php';
require_once __DIR__ . '/../../middleware/RBACMiddleware.php';
require_once __DIR__ . '/../../middleware/ValidationMiddleware.php';
require_once __DIR__ . '/../../utils/RequestHelper.php';
require_once __DIR__ . '/../../utils/ResponseHelper.php';

ErrorMiddleware::setupGlobalErrorHandling();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Initialize CSRF protection
CsrfMiddleware::initialize();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function parseApiRoute()
{
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

    if ($basePath !== '' && strpos($requestPath, $basePath) === 0) {
        $requestPath = substr($requestPath, strlen($basePath));
    }

    $segments = array_values(array_filter(explode('/', trim($requestPath, '/')), 'strlen'));
    $authActions = ['login', 'register', 'session', 'logout'];

    if (empty($segments)) {
        $queryAction = RequestHelper::query('action', 'default');

        if (in_array($queryAction, $authActions, true)) {
            return ['auth', $queryAction];
        }

        return [null, $queryAction];
    }

    $resource = $segments[0];
    $action = $segments[1] ?? RequestHelper::query('action', 'default');

    if (in_array($resource, $authActions, true)) {
        return ['auth', $resource];
    }

    return [$resource, $action];
}

function invokeControllerHandler($controller, $handler)
{
    $reflection = new ReflectionMethod($controller, $handler);

    if ($reflection->getNumberOfParameters() > 0) {
        $controller->{$handler}(RequestHelper::data());
        return;
    }

    $controller->{$handler}();
}

try {
    [$resource, $action] = parseApiRoute();

    if (!$resource) {
        ResponseHelper::error('Route not found.', 404);
    }

    $handler = resolveApiAction($resource, $_SERVER['REQUEST_METHOD'], $action);

    if (!$handler) {
        $allowedMethods = resolveApiAllowedMethods($resource, $action);

        if (!empty($allowedMethods)) {
            header('Allow: ' . implode(', ', $allowedMethods));
            ResponseHelper::error('Method not allowed.', 405);
        }

        ResponseHelper::error('Route not found.', 404);
    }

    switch ($resource) {
        case 'auth':
            if (in_array($action, ['login', 'register'], true)) {
                $requestData = RequestHelper::data();
                $email = is_array($requestData) ? (string) ($requestData['email'] ?? '') : '';
                RateLimiter::enforce('login', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $email);
            } else {
                RateLimiter::enforce('api_call', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            }

            $controller = new AuthController();
            break;
        case 'users':
            AuthMiddleware::authorizeRequest();
            RateLimiter::enforce('api_call', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            // RBAC check for user operations
            if ($action === 'update') {
                RBACMiddleware::authorize('users.update');
            } else {
                RBACMiddleware::authorize('users.read');
            }

            $controller = new UserController();
            break;
        case 'reservations':
            AuthMiddleware::authorizeRequest();
            RateLimiter::enforce('api_call', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            // RBAC check for reservation operations
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                RBACMiddleware::authorize('reservations.create');
            } else {
                RBACMiddleware::authorize('reservations.read');
            }

            $controller = new ReservationController();
            break;
        case 'parking':
            RateLimiter::enforce('api_call', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $controller = new ParkingController();
            break;
        default:
            ResponseHelper::error('Route not found.', 404);
    }

    invokeControllerHandler($controller, $handler);
} catch (Throwable $exception) {
    ErrorMiddleware::handle($exception);
}
