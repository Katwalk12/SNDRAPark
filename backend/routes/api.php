<?php

function getApiRoutes()
{
    return [
        'auth' => [
            'GET' => [
                'session' => 'session',
                'logout' => 'logout'
            ],
            'POST' => [
                'login' => 'login',
                'register' => 'register'
            ]
        ],
        'users' => [
            'GET' => [
                'default' => 'getProfile',
                'profile' => 'getProfile',
                'vehicles' => 'getVehicles'
            ],
            'POST' => [
                'update' => 'updateProfile',
                'vehicles' => 'addVehicle'
            ],
            'PUT' => [
                'vehicles' => 'updateVehicle'
            ],
            'DELETE' => [
                'vehicles' => 'removeVehicle'
            ]
        ],
        'parking' => [
            'GET' => [
                'default' => 'getAvailableSlots'
            ]
        ],
        'reservations' => [
            'GET' => [
                'default' => 'getReservations'
            ],
            'POST' => [
                'default' => 'createReservation'
            ]
        ]
    ];
}

function resolveApiAction($resource, $method, $action = 'default')
{
    $routes = getApiRoutes();
    $action = $action ?: 'default';

    if (isset($routes[$resource][$method][$action])) {
        return $routes[$resource][$method][$action];
    }

    if (isset($routes[$resource][$method]['default'])) {
        return $routes[$resource][$method]['default'];
    }

    return null;
}

function resolveApiAllowedMethods($resource, $action = 'default')
{
    $routes = getApiRoutes();
    $action = $action ?: 'default';
    $allowedMethods = [];

    if (!isset($routes[$resource])) {
        return $allowedMethods;
    }

    foreach ($routes[$resource] as $method => $methodRoutes) {
        if (isset($methodRoutes[$action])) {
            $allowedMethods[] = $method;
            continue;
        }

        if ($action === 'default' && isset($methodRoutes['default'])) {
            $allowedMethods[] = $method;
        }
    }

    return $allowedMethods;
}
