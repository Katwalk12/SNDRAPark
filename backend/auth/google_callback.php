<?php

declare(strict_types=1);

require_once __DIR__ . '/google_oauth_common.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/common/reservation-security.php';
require_once dirname(__DIR__) . '/utils/SessionManager.php';
require_once dirname(__DIR__) . '/utils/LoginThrottle.php';
require_once dirname(__DIR__) . '/middleware/AuditLogger.php';

// 1. Simulan ang session gamit ang nag-iisang standardized function check mula sa common helper
google_oauth_start_session();

$config = google_oauth_config();
google_oauth_require_config($config);

// 2. Kung nag-cancel o may ibinalik na error ang Google, ipakita agad iyon.
//    Isang babala: ibinabalik ni Google ang `access_denied` sa DALAWANG magkaibang
//    sitwasyon - (a) tunay na pag-cancel ng user, at (b) naka-"Testing" pa ang
//    OAuth consent screen at wala sa tester list ang account. Walang paraan dito
//    para paghiwalayin ang dalawa, kaya ang error_description ni Google ang
//    ipinapasa pabalik - iyon lang ang makakapagsabi kung alin ang nangyari.
$googleError = trim((string) ($_GET['error'] ?? ''));
if ($googleError !== '') {
    $googleDetail = trim((string) ($_GET['error_description'] ?? ''));
    $googleSubtype = trim((string) ($_GET['error_subtype'] ?? ''));

    google_oauth_fail(
        $googleError === 'access_denied'
            ? 'Google did not complete the sign in.'
            : 'Google returned an error during sign in.',
        $googleError,
        'Google redirected back with error=' . $googleError
            . ($googleSubtype !== '' ? ' subtype=' . $googleSubtype : '')
            . ($googleDetail !== '' ? ' description=' . $googleDetail : ''),
        $googleDetail
    );
}

// 3. I-validate ang state token bago pa man gamitin ang code (anti login-CSRF)
if (!google_oauth_consume_state(trim((string) ($_GET['state'] ?? '')))) {
    google_oauth_fail(
        'Your Google sign in session has expired. Please try again.',
        'invalid_state'
    );
}

// 4. Tiyakin na may bumalik na 'code' mula kay Google
$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    google_oauth_fail('No authorization code provided by Google.', 'missing_code');
}

// 5. I-exchange ang Code para maging Access Token
$tokenPayload = [
    'code'          => $code,
    'client_id'     => $config['client_id'],
    'client_secret' => $config['client_secret'],
    'redirect_uri'  => $config['redirect_uri'],
    'grant_type'    => 'authorization_code',
];

$tokenData = google_oauth_http_post_json(GOOGLE_TOKEN_ENDPOINT, $tokenPayload);
$accessToken = $tokenData['access_token'] ?? '';

if ($accessToken === '') {
    google_oauth_fail('Failed to obtain access token from Google.', 'token_exchange_failed');
}

// 6. Kunin ang User Info / Profile mula sa Google
$userInfo = google_oauth_http_get_json(GOOGLE_USERINFO_ENDPOINT, $accessToken);

$googleId  = trim((string) ($userInfo['sub'] ?? ''));
$email     = strtolower(trim((string) ($userInfo['email'] ?? '')));
$firstName = trim((string) ($userInfo['given_name'] ?? ''));
$lastName  = trim((string) ($userInfo['family_name'] ?? ''));
$emailVerified = filter_var($userInfo['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($googleId === '' || $email === '') {
    google_oauth_fail('Google profile info is missing unique identification data.', 'invalid_profile');
}

// Kung hindi beripikado ang email, puwedeng ma-hijack ang isang existing account.
if (!$emailVerified) {
    google_oauth_fail(
        'Your Google email address is not verified. Please verify it with Google and try again.',
        'email_not_verified'
    );
}

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

try {
    $connection = Database::connection();
    $userModel = new User();

    // 7. Hanapin muna ang account gamit ang google_id, saka ang email
    $user = $userModel->findByGoogleId($googleId);
    $isNewAccount = false;

    if (!$user) {
        $user = $userModel->findByEmail($email);

        if ($user) {
            // Dating email account: i-link ang Google sa kanya minsanan lang.
            $userModel->linkGoogleId((int) $user['id'], $googleId);

            AuditLogger::log('google_account_linked', 'auth', 'success',
                'Google account linked to existing user', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip_address' => $ipAddress
                ]);
        } else {
            // Bagong account: just-in-time registration
            $newUserId = (int) $userModel->createFromGoogle([
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => $email,
                'google_id'  => $googleId,
            ]);

            if ($newUserId <= 0) {
                google_oauth_fail('Unable to create your account. Please try again.', 'registration_failed');
            }

            $user = $userModel->findById($newUserId);
            $isNewAccount = true;

            AuditLogger::log('google_registration_success', 'auth', 'success',
                'User registered through Google', [
                    'user_id' => $newUserId,
                    'email' => $email,
                    'ip_address' => $ipAddress
                ]);
        }
    }

    if (!$user) {
        google_oauth_fail('Unable to load your account. Please try again.', 'account_lookup_failed');
    }

    $userId = (int) $user['id'];

    // 8. Pareho ng manual login: hindi puwedeng lusutan ng Google ang mga lock
    reservation_security_expire_due_reservations($connection, $userId);
    $user = reservation_security_assert_user_can_login($connection, $userId);
    $user = $userModel->findById($userId) ?: $user;

    // 9. Gamitin ang parehong session engine ng manual login para basahin ito ng dashboard
    SessionManager::createSession($user);

    // Malinis na login: burahin ang anumang failed attempt streak
    LoginThrottle::clearFailures($userId);

    AuditLogger::log('login_success', 'auth', 'success',
        'User logged in with Google', [
            'user_id' => $userId,
            'email' => $email,
            'ip_address' => $ipAddress,
            'user_role' => $user['role'] ?? 'user',
            'provider' => 'google',
            'new_account' => $isNewAccount
        ]);

    $successUrl = google_oauth_success_redirect_for_role($config, (string) ($user['role'] ?? 'user'));

    if ($isNewAccount) {
        // Walang sasakyan pa ang bagong Google account - sabihin ito sa dashboard.
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'welcome=google';
    }

    session_write_close();

    google_oauth_redirect($successUrl);
} catch (RuntimeException $exception) {
    // Naka-lock / disabled na account: ipakita ang mismong dahilan sa login page
    AuditLogger::log('google_login_blocked', 'auth', 'warning',
        'Google login blocked', [
            'email' => $email,
            'ip_address' => $ipAddress,
            'reason' => $exception->getMessage()
        ]);

    google_oauth_fail($exception->getMessage(), 'account_blocked');
} catch (Throwable $exception) {
    error_log('Google callback failed: ' . $exception->getMessage());

    google_oauth_fail('Google sign in failed. Please try again or use your email and password.', 'callback_error');
}
