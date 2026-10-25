<?php
declare(strict_types=1);

/** Facebook Profile Picture API for Vercel PHP runtime. */
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respondJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function validFacebookUrl(string $url): bool
{
    if ($url === '' || strlen($url) > 2048) return false;
    $p = parse_url($url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return false;
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) return false;
    return in_array(strtolower($p['host']), [
        'facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com'
    ], true);
}

function requestUrl(string $url, array $extraOptions = []): array
{
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Unable to initialize cURL');
    curl_setopt_array($ch, $extraOptions + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    unset($ch); // PHP 8.5 handles curl handle cleanup automatically.
    if ($body === false) throw new RuntimeException('Upstream request failed: ' . $error);
    return ['body' => $body, 'status' => $status, 'content_type' => $type];
}

function isPublicHttpsUrl(string $url): bool
{
    $p = parse_url($url);
    if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    if (isset($p['user']) || isset($p['pass']) || isset($p['port']) && (int)$p['port'] !== 443) return false;
    $host = strtolower(rtrim($p['host'], '.'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    $ips = @gethostbynamel($host);
    if (!is_array($ips) || !$ips) return false;
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    }
    return true;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');
    respondJson(405, ['success' => false, 'error' => 'Method not allowed. Use GET.']);
}

$url = trim((string)($_GET['url'] ?? ''));
$mode = strtolower(trim((string)($_GET['mode'] ?? 'json')));
if (!in_array($mode, ['json', 'image'], true)) {
    respondJson(400, ['success' => false, 'error' => 'Invalid mode. Use json or image.']);
}
if ($url === '') {
    respondJson(400, [
        'success' => false,
        'error' => 'Missing required parameter: url',
        'usage' => [
            'json' => '/api/?url=https%3A%2F%2Fwww.facebook.com%2Fexample&mode=json',
            'image' => '/api/?url=https%3A%2F%2Fwww.facebook.com%2Fexample&mode=image'
        ]
    ]);
}
if (!validFacebookUrl($url)) respondJson(400, ['success' => false, 'error' => 'Invalid Facebook URL']);

try {
    $upstream = requestUrl('https://fvdownloader.net/req', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['query' => $url, 'downloader' => 'profile']),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: https://fvdownloader.net',
            'Referer: https://fvdownloader.net/facebook-profile-picture-viewer'
        ]
    ]);
    if ($upstream['status'] < 200 || $upstream['status'] >= 300) {
        respondJson(502, ['success' => false, 'error' => 'Upstream provider returned an HTTP error']);
    }
    $data = json_decode($upstream['body'], true);
    if (!is_array($data) || !empty($data['error']) || !isset($data['downloadUrl']) || !is_string($data['downloadUrl'])) {
        respondJson(404, ['success' => false, 'error' => 'Profile picture not found']);
    }
    $imageUrl = trim($data['downloadUrl']);
    if (!isPublicHttpsUrl($imageUrl)) {
        respondJson(502, ['success' => false, 'error' => 'Provider returned an invalid or unsafe image URL']);
    }

    if ($mode === 'json') {
        respondJson(200, [
            'success' => true,
            'profile_url' => $url,
            'image_url' => $imageUrl,
            'mode' => 'json',
            'note' => 'The upstream image URL may expire or change.'
        ]);
    }

    $image = requestUrl($imageUrl, [
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8']
    ]);
    if ($image['status'] < 200 || $image['status'] >= 300) {
        respondJson(502, ['success' => false, 'error' => 'Could not retrieve the image']);
    }
    $contentType = strtolower(trim(explode(';', $image['content_type'])[0]));
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
    if (!in_array($contentType, $allowed, true)) {
        respondJson(502, ['success' => false, 'error' => 'Provider did not return a supported image']);
    }
    $bytes = $image['body'];
    if ($bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
        respondJson(502, ['success' => false, 'error' => 'Image is empty or exceeds the 10 MB limit']);
    }
    $info = @getimagesizefromstring($bytes);
    if ($info === false || ($info['mime'] ?? '') !== $contentType) {
        respondJson(502, ['success' => false, 'error' => 'Invalid image data received']);
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: public, max-age=300');
    header('Content-Disposition: inline; filename="profile-picture"');
    echo $bytes;
    exit;
} catch (Throwable $e) {
    error_log('Facebook Profile API error: ' . $e->getMessage());
    respondJson(502, ['success' => false, 'error' => 'Unable to process request. Try again later.']);
}
