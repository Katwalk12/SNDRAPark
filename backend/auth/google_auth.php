<?php

declare(strict_types=1);

require_once __DIR__ . '/google_oauth_common.php';

// Simulan ang session gamit ang nag-iisang standardized function check
google_oauth_start_session();

$config = google_oauth_config();
google_oauth_require_config($config);

$authUrl = GOOGLE_AUTH_ENDPOINT . '?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $config['client_id'],
    'redirect_uri'  => $config['redirect_uri'],
    'scope'         => 'openid profile email',
    'prompt'        => 'select_account',
    'state'         => google_oauth_issue_state(),
]);

google_oauth_redirect($authUrl);