<?php
// Facebook Profile Picture API
// File: api/index.php
// Save as UTF-8 without BOM. Do not add anything before <?php.

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Cache-Control: no-store');

function sendError(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function requestUrl(string $url, array $options = []): array
{
    $ch = curl_init($url);

    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'Accept-Encoding: identity',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36'
        ],
    ];

    curl_setopt_array($ch, $options + $defaults);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    curl_close($ch);

    return [
        'body' => $body,
        'error' => $error,
        'status' => $status,
        'content_type' => $contentType
    ];
}

function isValidHttpsUrl(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($url);

    if (
        !is_array($parts) ||
        strtolower($parts['scheme'] ?? '') !== 'https' ||
        empty($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass'])
    ) {
        return false;
    }

    $host = strtolower($parts['host']);

    if (
        $host === 'localhost' ||
        str_ends_with($host, '.localhost') ||
        str_ends_with($host, '.local')
    ) {
        return false;
    }

    $ip = filter_var($host, FILTER_VALIDATE_IP)
        ? $host
        : gethostbyname($host);

    if (
        filter_var($ip, FILTER_VALIDATE_IP) === false ||
        filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false
    ) {
        return false;
    }

    return true;
}

// Only GET requests are supported.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    sendError(405, 'Only GET requests are allowed');
}

// Read and validate the Facebook profile URL.
$profileUrl = trim((string) ($_GET['url'] ?? ''));

if ($profileUrl === '' || !filter_var($profileUrl, FILTER_VALIDATE_URL)) {
    sendError(400, 'Please provide a valid Facebook profile URL');
}

$profileParts = parse_url($profileUrl);
$profileHost = strtolower($profileParts['host'] ?? '');

$allowedHosts = [
    'facebook.com',
    'www.facebook.com',
    'm.facebook.com',
    'web.facebook.com'
];

if (
    strtolower($profileParts['scheme'] ?? '') !== 'https' ||
    !in_array($profileHost, $allowedHosts, true) ||
    isset($profileParts['user']) ||
    isset($profileParts['pass'])
) {
    sendError(400, 'The URL must be a valid HTTPS Facebook profile URL');
}

// Request the profile picture download information.
$providerUrl = 'https://fvdownloader.net/req';

$provider = requestUrl($providerUrl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'query' => $profileUrl,
        'downloader' => 'profile'
    ]),
    CURLOPT_HTTPHEADER => [
        'Accept: application/json, text/plain, */*',
        'Accept-Encoding: identity',
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://fvdownloader.net',
        'Referer: https://fvdownloader.net/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36'
    ],
]);

if ($provider['error'] !== '') {
    error_log('Facebook API provider cURL error: ' . $provider['error']);
    sendError(502, 'Could not connect to the profile picture provider');
}

if (
    $provider['status'] < 200 ||
    $provider['status'] >= 300 ||
    !is_string($provider['body'])
) {
    error_log('Facebook API provider HTTP status: ' . $provider['status']);
    sendError(502, 'The profile picture provider returned an HTTP error');
}

$data = json_decode($provider['body'], true);

if (!is_array($data)) {
    error_log('Facebook API provider returned invalid JSON');
    sendError(502, 'The provider returned an unexpected response');
}

$downloadUrl = $data['downloadUrl'] ?? null;

if (
    !is_string($downloadUrl) ||
    !isValidHttpsUrl($downloadUrl)
) {
    error_log('Facebook API provider returned an invalid download URL');
    sendError(502, 'The provider did not return a valid picture URL');
}

// Download the image bytes.
$image = requestUrl($downloadUrl, [
    CURLOPT_HTTPHEADER => [
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        'Accept-Encoding: identity',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36'
    ],
]);

if ($image['error'] !== '') {
    error_log('Facebook API image download cURL error: ' . $image['error']);
    sendError(502, 'Could not download the profile picture');
}

if (
    $image['status'] < 200 ||
    $image['status'] >= 300 ||
    !is_string($image['body']) ||
    $image['body'] === ''
) {
    error_log('Facebook API image download HTTP status: ' . $image['status']);
    sendError(502, 'The profile picture could not be retrieved');
}

// Verify that the response contains image data.
$imageInfo = @getimagesizefromstring($image['body']);

if ($imageInfo === false || empty($imageInfo['mime'])) {
    error_log('Facebook API provider response was not a valid image');
    sendError(502, 'The provider did not return valid image data');
}

$allowedImageTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif'
];

if (!in_array($imageInfo['mime'], $allowedImageTypes, true)) {
    sendError(502, 'The provider returned an unsupported image format');
}

// Return the actual image, not JSON.
http_response_code(200);
header('Content-Type: ' . $imageInfo['mime']);
header('Content-Length: ' . strlen($image['body']));
header('X-Content-Type-Options: nosniff');

echo $image['body'];
