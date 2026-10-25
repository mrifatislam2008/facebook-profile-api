
<?php
// Facebook Profile Picture API
// Compatible with Vercel PHP runtime.
// Do not add declare(strict_types=1).

header('X-Content-Type-Options: nosniff');

function fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requestUrl(string $url, array $options = []): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    unset($ch);

    return [
        'body' => $body,
        'error' => $error,
        'status' => $status,
        'content_type' => $contentType,
    ];
}

function isPublicHttpsUrl(string $url): bool
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

    if ($host === 'localhost' || str_ends_with($host, '.local')) {
        return false;
    }

    $ip = gethostbyname($host);

    if ($ip === $host || filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false) {
        return false;
    }

    return true;
}

// 1. Read and validate Facebook URL
$url = trim((string) ($_GET['url'] ?? ''));

if ($url === '') {
    fail(400, 'Missing required parameter: url');
}

if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
    fail(400, 'Invalid URL');
}

$parts = parse_url($url);
$host = strtolower($parts['host'] ?? '');

$allowedHosts = [
    'facebook.com',
    'www.facebook.com',
    'm.facebook.com',
    'web.facebook.com',
];

if (
    !in_array($host, $allowedHosts, true) ||
    !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
) {
    fail(400, 'Please provide a valid Facebook URL');
}

// 2. Ask the upstream provider for the picture URL
$provider = requestUrl('https://fvdownloader.net/req', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'query' => $url,
        'downloader' => 'profile',
    ]),
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_ENCODING => 'gzip, deflate, br',
    CURLOPT_HTTPHEADER => [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'Origin: https://fvdownloader.net',
        'Referer: https://fvdownloader.net/facebook-profile-picture-viewer',
        'X-Requested-With: XMLHttpRequest',
        'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/131.0.0.0 Mobile Safari/537.36',
    ],
]);

if ($provider['error'] !== '') {
    fail(502, 'Could not connect to the profile picture provider');
}

if ($provider['status'] < 200 || $provider['status'] >= 300) {
    fail(502, 'Upstream provider returned HTTP ' . $provider['status']);
}

$data = json_decode((string) $provider['body'], true);

if (!is_array($data) || !empty($data['error'])) {
    fail(502, 'Provider returned an invalid response');
}

$imageUrl = trim((string) ($data['downloadUrl'] ?? ''));

if ($imageUrl === '') {
    fail(404, 'Profile picture URL was not found');
}

$imageUrl = str_replace('&dl=1', '', $imageUrl);

if (!isPublicHttpsUrl($imageUrl)) {
    fail(502, 'Provider returned an invalid image URL');
}

// 3. Download the image
$image = requestUrl($imageUrl, [
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PROTOCOLS_STR => 'https',
    CURLOPT_HTTPHEADER => [
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    ],
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome/131.0.0.0 Mobile Safari/537.36',
]);

if ($image['error'] !== '') {
    fail(502, 'Failed to download the profile picture');
}

if ($image['status'] !== 200 || !is_string($image['body'])) {
    fail(502, 'Image server returned HTTP ' . $image['status']);
}

$bytes = $image['body'];

if ($bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
    fail(502, 'Image is empty or exceeds the 10 MB limit');
}

$mime = strtolower(trim(explode(';', $image['content_type'])[0]));

$allowedMimes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/avif',
];

if (!in_array($mime, $allowedMimes, true)) {
    fail(502, 'Provider did not return a supported image');
}

// 4. Return image bytes
http_response_code(200);
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: public, max-age=3600');
echo $bytes;
