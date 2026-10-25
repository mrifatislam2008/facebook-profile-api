<?php
// Facebook Profile Picture API for Vercel

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');

function sendJsonError(int $status, string $message): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function makeRequest(string $url, array $options = []): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'body' => false,
            'error' => 'curl_init failed',
            'status' => 0,
            'content_type' => ''
        ];
    }

    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT =>
            'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome/131.0.0.0 Mobile Safari/537.36'
    ];

    curl_setopt_array($ch, $options + $defaults);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    unset($ch);

    return [
        'body' => $body,
        'error' => $error,
        'status' => $status,
        'content_type' => $contentType
    ];
}

function isAllowedFacebookUrl(string $url): bool
{
    if (strlen($url) > 2048 ||
        filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($url);

    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower($parts['host'] ?? '');

    $allowedHosts = [
        'facebook.com',
        'www.facebook.com',
        'm.facebook.com',
        'web.facebook.com'
    ];

    return in_array($scheme, ['http', 'https'], true)
        && in_array($host, $allowedHosts, true)
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function isPublicHttpsUrl(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($url);

    if (!is_array($parts) ||
        strtolower($parts['scheme'] ?? '') !== 'https' ||
        empty($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass'])) {
        return false;
    }

    $host = strtolower($parts['host']);

    if ($host === 'localhost' ||
        str_ends_with($host, '.localhost') ||
        str_ends_with($host, '.local')) {
        return false;
    }

    // Reject literal private/reserved IP addresses.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    // Resolve hostname and reject private/reserved IPv4 addresses.
    $ip = gethostbyname($host);

    if ($ip === $host) {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

// -------------------------
// 1. Validate input
// -------------------------

$url = trim((string) ($_GET['url'] ?? ''));

if ($url === '') {
    sendJsonError(400, 'Missing required parameter: url');
}

if (!isAllowedFacebookUrl($url)) {
    sendJsonError(400, 'Please provide a valid Facebook profile URL');
}

// -------------------------
// 2. Request image URL from provider
// -------------------------

$provider = makeRequest('https://fvdownloader.net/req', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'query' => $url,
        'downloader' => 'profile'
    ]),
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_ENCODING => 'gzip, deflate, br',
    CURLOPT_HTTPHEADER => [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'Origin: https://fvdownloader.net',
        'Referer: https://fvdownloader.net/facebook-profile-picture-viewer',
        'X-Requested-With: XMLHttpRequest'
    ]
]);

if ($provider['error'] !== '') {
    error_log(
        'Facebook API provider connection error: ' .
        $provider['error']
    );

    sendJsonError(
        502,
        'Could not connect to the profile picture provider. Check Vercel Runtime Logs.'
    );
}

if ($provider['status'] < 200 || $provider['status'] >= 300) {
    error_log(
        'Facebook API provider returned HTTP ' .
        $provider['status']
    );

    sendJsonError(502, 'Profile picture provider returned an HTTP error');
}

$data = json_decode((string) $provider['body'], true);

if (!is_array($data)) {
    error_log('Facebook API provider returned invalid JSON');
    sendJsonError(502, 'Provider returned an invalid response');
}

$imageUrl = trim((string) ($data['downloadUrl'] ?? ''));

if ($imageUrl === '') {
    error_log('Facebook API provider response did not contain downloadUrl');
    sendJsonError(404, 'Profile picture URL was not found');
}

$imageUrl = str_replace('&dl=1', '', $imageUrl);

if (!isPublicHttpsUrl($imageUrl)) {
    error_log('Facebook API provider returned an invalid image URL');
    sendJsonError(502, 'Provider returned an invalid image URL');
}

// -------------------------
// 3. Download image
// -------------------------

$image = makeRequest($imageUrl, [
    CURLOPT_HTTPHEADER => [
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8'
    ],
    CURLOPT_TIMEOUT => 20,
    CURLOPT_ENCODING => 'gzip, deflate, br'
]);

if ($image['error'] !== '') {
    error_log('Facebook API image download error: ' . $image['error']);
    sendJsonError(502, 'Failed to download the profile picture');
}

if ($image['status'] !== 200 || !is_string($image['body'])) {
    error_log(
        'Facebook API image server returned HTTP ' .
        $image['status']
    );

    sendJsonError(502, 'Image server returned an HTTP error');
}

$bytes = $image['body'];

if ($bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
    sendJsonError(502, 'Image is empty or exceeds the 10 MB limit');
}

// Verify the actual image data.
$imageInfo = @getimagesizefromstring($bytes);

if ($imageInfo === false || empty($imageInfo['mime'])) {
    sendJsonError(502, 'The provider did not return valid image data');
}

$mime = strtolower($imageInfo['mime']);

$allowedMimes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/avif'
];

if (!in_array($mime, $allowedMimes, true)) {
    sendJsonError(502, 'Unsupported image format');
}

// -------------------------
// 4. Return image
// -------------------------

http_response_code(200);
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: public, max-age=3600');

echo $bytes;
