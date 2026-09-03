<?php

require_once __DIR__ . '/../utils/EnvHelper.php';

date_default_timezone_set('Asia/Manila');

return [
    'app_name' => 'SNDRAPark',
    'app_url' => EnvHelper::get('APP_URL', 'http://localhost/sndraPark'),
    'base_path' => dirname(__DIR__, 2)
];
