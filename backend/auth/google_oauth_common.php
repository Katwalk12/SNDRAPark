<?php

declare(strict_types=1);

// =========================================================================
// BULLETPROOF ENVHELPER LOADER (Sinisiguro nitong laging kilala si EnvHelper)
// =========================================================================
$envHelperPath = dirname(__DIR__) . '/utils/EnvHelper.php';
if (file_exists($envHelperPath)) {
    require_once $envHelperPath;
} else {
    // Subukan ang fallback kung sakaling iba ang structure ng deployment path
    require_once __DIR__ . '/../utils/EnvHelper.php';
}

require_once dirname(__DIR__) . '/utils/SessionManager.php';
// =========================================================================

const GOOGLE_AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
const GOOGLE_USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';
const GOOGLE_STATE_SESSION_KEY = 'google_oauth_state';
const GOOGLE_STATE_TTL_SECONDS = 600;

/**
 * Single-point configuration ng session na sumusunod sa default engine settings
 * ng iyong auth.php upang maging pareho ang tracking cookie sa dashboard.
 *
 * Mahalaga ang SessionManager::prepareSessionStorage(): iyon ang pumipili ng
 * session save path na ginagamit ng API, kaya kung hindi ito tatawagin dito ay
 * hindi mababasa ng dashboard ang session na ginawa ng Google callback.
 */
function google_oauth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    SessionManager::prepareSessionStorage();

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    // Lax (hindi Strict) dahil ang callback ay isang cross-site redirect galing
    // sa accounts.google.com - hindi ipapadala ng browser ang Strict cookie doon.
    ini_set('session.cookie_samesite', 'Lax');

    session_start();
}

/**
 * Gumawa ng one-time state token laban sa login CSRF / replay ng callback URL.
 */
function google_oauth_issue_state(): string
{
    $state = bin2hex(random_bytes(16));

    $_SESSION[GOOGLE_STATE_SESSION_KEY] = [
        'value' => $state,
        'created_at' => time(),
    ];

    return $state;
}

/**
 * I-validate at agad ding ubusin ang state token.
 */
function google_oauth_consume_state(string $state): bool
{
    $stored = $_SESSION[GOOGLE_STATE_SESSION_KEY] ?? null;
    unset($_SESSION[GOOGLE_STATE_SESSION_KEY]);

    if (!is_array($stored) || !isset($stored['value'], $stored['created_at'])) {
        return false;
    }

    if ((time() - (int) $stored['created_at']) > GOOGLE_STATE_TTL_SECONDS) {
        return false;
    }

    return $state !== '' && hash_equals((string) $stored['value'], $state);
}

function google_oauth_origin(): string
{
    $scheme = 'http';

    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

function google_oauth_current_dir_url(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return google_oauth_origin() . $scriptDir;
}

/**
 * Root URL ng project, hinahango sa aktwal na script path (hal. http://localhost/sndraPark).
 */
function google_oauth_project_base_url(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = (string) preg_replace('#/backend/auth/[^/]*$#', '', $scriptName);

    return google_oauth_origin() . rtrim($basePath, '/');
}

/**
 * Buoin ang absolute URL mula sa isang env value na maaaring path o buong URL na.
 */
function google_oauth_resolve_url(string $value, string $fallback): string
{
    $value = trim($value);

    if ($value === '') {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    return google_oauth_origin() . '/' . ltrim($value, '/');
}

/**
 * Buoin ang isang URL na panloob lang sa app (ang success/failure landing).
 *
 * Iba ito sa redirect_uri: wala itong kinalaman sa nakarehistro sa Google, kaya
 * ang aktwal na base path ng kasalukuyang request ang sinusunod - hindi ang
 * naka-hardcode na host at capitalization sa .env. Dalawang bagay ang naiiwasan
 * nito: ang biglaang pagpalit ng URL casing sa gitna ng login, at ang 404 sa
 * isang case-sensitive na host (Linux) kapag mali ang case ng nakasulat na path.
 *
 * Ang relative value (may leading slash man o wala) ay laging sinusukat mula sa
 * project base. Ang buong http(s) URL ay ginagalang bilang escape hatch kung
 * talagang ibang host ang patutunguhan.
 */
function google_oauth_resolve_app_url(string $value, string $relativeFallback): string
{
    $value = trim($value);

    if ($value === '') {
        $value = $relativeFallback;
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    return google_oauth_project_base_url() . '/' . ltrim($value, '/');
}

function google_oauth_config(): array
{
    $clientId     = (string) EnvHelper::get('GOOGLE_CLIENT_ID', '');
    $clientSecret = (string) EnvHelper::get('GOOGLE_CLIENT_SECRET', '');

    $baseUrl = google_oauth_project_base_url();

    // Tinatanggap ang parehong pangalan ng key na ginamit sa .env sa paglipas ng panahon.
    $successValue = (string) (EnvHelper::get('GOOGLE_LOGIN_SUCCESS_REDIRECT', null)
        ?? EnvHelper::get('GOOGLE_SUCCESS_REDIRECT', ''));
    $failureValue = (string) (EnvHelper::get('GOOGLE_LOGIN_FAILURE_REDIRECT', null)
        ?? EnvHelper::get('GOOGLE_FAILURE_REDIRECT', ''));

    $successUrl = google_oauth_resolve_app_url($successValue, 'frontend/pages/user-dashboard.html');
    $failureUrl = google_oauth_resolve_app_url($failureValue, 'frontend/pages/login.html');

    // Ang redirect_uri ay kailangang EKSAKTONG katumbas ng nakarehistro sa Google
    // Console. Kaya inuuna ang .env value kaysa sa hinuhulaan mula sa URL na
    // tinipa ng user (kung saan iba ang capitalization ay sasabog ang
    // redirect_uri_mismatch).
    $redirectUrl = google_oauth_resolve_url(
        (string) EnvHelper::get('GOOGLE_REDIRECT_URI', ''),
        google_oauth_current_dir_url() . '/google_callback.php'
    );

    return [
        'client_id'        => $clientId,
        'client_secret'    => $clientSecret,
        'redirect_uri'     => $redirectUrl,
        'success_redirect' => $successUrl,
        'failure_redirect' => $failureUrl,
        'base_url'         => $baseUrl,
    ];
}

/**
 * Ihatid ang user sa dashboard na naaayon sa role niya.
 */
function google_oauth_success_redirect_for_role(array $config, string $role): string
{
    $baseUrl = $config['base_url'] ?? google_oauth_project_base_url();

    if ($role === 'admin') {
        return $baseUrl . '/frontend/pages/admin-dashboard.html';
    }

    if ($role === 'booth') {
        return $baseUrl . '/frontend/pages/parking-booth.html';
    }

    return $config['success_redirect'];
}

function google_oauth_require_config(array $config): void
{
    if ($config['client_id'] === '' || $config['client_secret'] === '') {
        google_oauth_fail(
            'Google sign in is not configured on this server.',
            'missing_env_credentials',
            'GOOGLE_CLIENT_ID and/or GOOGLE_CLIENT_SECRET are empty in .env.'
        );
    }
}

function google_oauth_redirect(string $url): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }

    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Ihatid pabalik sa login page na may dalang dahilan.
 *
 * Tatlong magkaibang bagay ang mensahe dito, kaya hiwalay ang bawat isa:
 *   $message      - ligtas na kopya para sa user (napupunta sa URL).
 *   $logDetail    - panloob na detalye (cURL error, API error). Sa error_log
 *                   lang ito napupunta; hindi ito dapat makita ng user dahil
 *                   naglalantad ito ng configuration at endpoint na detalye.
 *   $googleDetail - ang sariling paliwanag ni Google sa user (error_description).
 *                   Ligtas ipakita dahil kay Google mismo galing.
 */
function google_oauth_fail(string $message, string $errorCode, string $logDetail = '', string $googleDetail = ''): never
{
    if ($logDetail !== '') {
        error_log('[google-oauth] ' . $errorCode . ': ' . $logDetail);
    }

    $config = google_oauth_config();

    $params = [
        'error' => 'google_oauth_error',
        'code'  => $errorCode,
        'msg'   => $message,
    ];

    if ($googleDetail !== '') {
        $params['detail'] = $googleDetail;
    }

    google_oauth_redirect($config['failure_redirect'] . '?' . http_build_query($params));
}

function google_oauth_http_get_json(string $url, string $accessToken): array
{
    return google_oauth_http_json($url, [
        'method' => 'GET',
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept'        => 'application/json',
        ],
    ]);
}

function google_oauth_http_post_json(string $url, array $payload): array
{
    return google_oauth_http_json($url, [
        'method' => 'POST',
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ],
        'body' => http_build_query($payload),
    ]);
}

/**
 * Hanapin ang CA bundle. Kadalasan nakatakda na ito sa php.ini ng XAMPP; kung
 * hindi, susubukan ang mga karaniwang lokasyon bago sumuko.
 */
function google_oauth_ca_bundle(): string
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $candidates = [
        (string) EnvHelper::get('CURL_CA_BUNDLE', ''),
        (string) ini_get('curl.cainfo'),
        (string) ini_get('openssl.cafile'),
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        'C:/xampp/php/extras/ssl/cacert.pem',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_readable($candidate)) {
            $resolved = $candidate;
            return $resolved;
        }
    }

    // Walang bundle: hahayaan ang cURL na gamitin ang system default nito.
    $resolved = '';

    return $resolved;
}

function google_oauth_http_json(string $url, array $options): array
{
    if (!function_exists('curl_init')) {
        google_oauth_fail(
            'Google sign in is not available on this server.',
            'curl_missing',
            'PHP cURL extension is required for Google OAuth HTTP requests.'
        );
    }

    $curl = curl_init($url);
    
    $headers = [];
    foreach (($options['headers'] ?? []) as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $options['method'] ?? 'GET',
        // Ang token exchange ay may dalang client_secret - kailangang tunay na
        // beripikado ang koneksyon papuntang Google.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $caBundle = google_oauth_ca_bundle();

    if ($caBundle !== '') {
        curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
    }

    if (array_key_exists('body', $options)) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, (string) $options['body']);
    }

    $rawResponse = curl_exec($curl);
    $curlError   = curl_error($curl);
    $statusCode  = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($rawResponse === false) {
        google_oauth_fail(
            'Could not reach Google. Please try again.',
            'oauth_http_failed',
            $url . ' - ' . $curlError
        );
    }

    $decoded = json_decode((string) $rawResponse, true);

    if (!is_array($decoded)) {
        google_oauth_fail(
            'Google returned an unexpected response. Please try again.',
            'oauth_invalid_json',
            $url . ' returned HTTP ' . $statusCode . ' with a non-JSON body'
        );
    }

    if ($statusCode >= 400) {
        $errDescription = (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'Unknown API Error');
        google_oauth_fail(
            'Google could not complete the sign in. Please try again.',
            'oauth_api_error',
            $url . ' returned HTTP ' . $statusCode . ': ' . $errDescription,
            $errDescription
        );
    }

    return $decoded;
}