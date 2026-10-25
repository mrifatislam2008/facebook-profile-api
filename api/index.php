
<?php
declare(strict_types=1);

/**
 * Facebook Profile Picture API
 * Vercel PHP Runtime
 *
 * Endpoints:
 * /api/?url=FACEBOOK_URL&mode=json
 * /api/?url=FACEBOOK_URL&mode=image
 */

header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');

const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
const PROVIDER_URL = 'https://fvdownloader.net/req';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Send JSON and terminate.
 */
function respondJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Log diagnostics without logging cookies, tokens,
 * complete provider responses, or user profile URLs.
 */
function diagnostic(string $event, array $details = []): void
{
    $safe = [
        'event' => $event,
        'details' => $details,
    ];

    error_log(
        '[FacebookProfileAPI] ' .
        json_encode(
            $safe,
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        )
    );
}

/**
 * Make a short, sanitized response preview for logs.
 */
function safePreview(string $body): string
{
    $body = strip_tags($body);
    $body = preg_replace('/https?:\/\/\S+/i', '[URL]', $body) ?? '';
    $body = preg_replace('/[\x00-\x1F\x7F]/', ' ', $body) ?? '';
    $body = preg_replace('/\s+/', ' ', $body) ?? '';

    return substr($body, 0, 240);
}

/**
 * Validate Facebook profile URLs.
 */
function validFacebookUrl(string $url): bool
{
    if ($url === '' || strlen($url) > 2048) {
        return false;
    }

    $p = parse_url($url);

    if (
        !is_array($p) ||
        empty($p['scheme']) ||
        empty($p['host'])
    ) {
        return false;
    }

    if (!in_array(
        strtolower($p['scheme']),
        ['http', 'https'],
        true
    )) {
        return false;
    }

    if (isset($p['user']) || isset($p['pass'])) {
        return false;
    }

    if (
        isset($p['port']) &&
        !in_array((int)$p['port'], [80, 443], true)
    ) {
        return false;
    }

    return in_array(
        strtolower($p['host']),
        [
            'facebook.com',
            'www.facebook.com',
            'm.facebook.com',
            'web.facebook.com',
        ],
        true
    );
}

/**
 * Only permit public HTTPS image URLs.
 */
function isPublicHttpsUrl(string $url): bool
{
    $p = parse_url($url);

    if (
        !is_array($p) ||
        strtolower($p['scheme'] ?? '') !== 'https' ||
        empty($p['host'])
    ) {
        return false;
    }

    if (isset($p['user']) || isset($p['pass'])) {
        return false;
    }

    if (
        isset($p['port']) &&
        (int)$p['port'] !== 443
    ) {
        return false;
    }

    $host = strtolower(rtrim($p['host'], '.'));

    if (
        $host === 'localhost' ||
        str_ends_with($host, '.localhost') ||
        str_ends_with($host, '.local')
    ) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE |
            FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    $ips = @gethostbynamel($host);

    if (!is_array($ips) || !$ips) {
        return false;
    }

    foreach ($ips as $ip) {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE |
            FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }
    }

    return true;
}

/**
 * Perform an HTTP request using cURL.
 */
function requestUrl(
    string $url,
    array $extraOptions = []
): array {
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException(
            'Unable to initialize cURL'
        );
    }

    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT =>
            'Mozilla/5.0 (Linux; Android 13) ' .
            'AppleWebKit/537.36 (KHTML, like Gecko) ' .
            'Chrome/130.0 Mobile Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_MAXREDIRS => 0,
    ];

    curl_setopt_array($ch, $extraOptions + $defaults);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo(
        $ch,
        CURLINFO_CONTENT_TYPE
    );

    unset($ch);

    if ($body === false) {
        throw new RuntimeException(
            'cURL error ' . $curlErrno . ': ' . $curlError
        );
    }

    return [
        'body' => $body,
        'status' => $status,
        'content_type' => $contentType,
    ];
}

/**
 * Handle request method.
 */
$method = strtoupper(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');

    respondJson(405, [
        'success' => false,
        'error' => 'Method not allowed. Use GET.',
    ]);
}

/**
 * Read and validate parameters.
 */
$url = trim((string)($_GET['url'] ?? ''));
$mode = strtolower(trim((string)($_GET['mode'] ?? 'json')));

if (!in_array($mode, ['json', 'image'], true)) {
    respondJson(400, [
        'success' => false,
        'error' => 'Invalid mode. Use json or image.',
    ]);
}

if ($url === '') {
    respondJson(400, [
        'success' => false,
        'error' => 'Missing required parameter: url',
        'usage' => [
            'json' => '/api/?url=FACEBOOK_URL&mode=json',
            'image' => '/api/?url=FACEBOOK_URL&mode=image',
        ],
    ]);
}

if (!validFacebookUrl($url)) {
    respondJson(400, [
        'success' => false,
        'error' => 'Invalid Facebook URL',
    ]);
}

try {
    /*
     * Step 1: Ask the upstream provider for the image URL.
     */
    $upstream = requestUrl(PROVIDER_URL, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'query' => $url,
            'downloader' => 'profile',
        ]),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: https://fvdownloader.net',
            'Referer: https://fvdownloader.net/facebook-profile-picture-viewer',
        ],
    ]);

    if (
        $upstream['status'] < 200 ||
        $upstream['status'] >= 300
    ) {
        diagnostic('provider_http_error', [
            'status' => $upstream['status'],
            'content_type' => $upstream['content_type'],
            'body_preview' => safePreview($upstream['body']),
        ]);

        respondJson(502, [
            'success' => false,
            'error' => 'Upstream provider returned an HTTP error',
            'upstream_status' => $upstream['status'],
            'hint' => 'Check Vercel Logs for provider_http_error.',
        ]);
    }

    /*
     * Step 2: Parse provider response.
     */
    $data = json_decode($upstream['body'], true);

    if (!is_array($data)) {
        diagnostic('provider_invalid_json', [
            'status' => $upstream['status'],
            'content_type' => $upstream['content_type'],
            'body_preview' => safePreview($upstream['body']),
        ]);

        respondJson(502, [
            'success' => false,
            'error' => 'Provider returned an unexpected response',
            'hint' => 'Check Vercel Logs for provider_invalid_json.',
        ]);
    }

    if (!empty($data['error'])) {
        diagnostic('provider_reported_error', [
            'status' => $upstream['status'],
            'provider_error_type' => gettype($data['error']),
        ]);

        respondJson(502, [
            'success' => false,
            'error' => 'Provider could not process this profile',
        ]);
    }

    $imageUrl = $data['downloadUrl'] ?? null;

    if (!is_string($imageUrl) || trim($imageUrl) === '') {
        diagnostic('provider_missing_image_url', [
            'status' => $upstream['status'],
            'response_keys' => array_slice(
                array_map('strval', array_keys($data)),
                0,
                20
            ),
            'content_type' => $upstream['content_type'],
            'body_preview' => safePreview($upstream['body']),
        ]);

        respondJson(502, [
            'success' => false,
            'error' => 'Image URL was not found in provider response',
            'hint' => 'Provider response format may have changed.',
        ]);
    }

    $imageUrl = trim($imageUrl);

    if (!isPublicHttpsUrl($imageUrl)) {
        diagnostic('provider_unsafe_image_url');

        respondJson(502, [
            'success' => false,
            'error' => 'Provider returned an invalid or unsafe image URL',
        ]);
    }

    /*
     * Step 3: JSON mode.
     */
    if ($mode === 'json') {
        respondJson(200, [
            'success' => true,
            'profile_url' => $url,
            'image_url' => $imageUrl,
            'mode' => 'json',
            'note' => 'The image URL may expire or change.',
        ]);
    }

    /*
     * Step 4: Download image bytes.
     */
    $image = requestUrl($imageUrl, [
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8',
        ],
    ]);

    if (
        $image['status'] < 200 ||
        $image['status'] >= 300
    ) {
        diagnostic('image_download_http_error', [
            'status' => $image['status'],
            'content_type' => $image['content_type'],
        ]);

        respondJson(502, [
            'success' => false,
            'error' => 'Could not retrieve the image',
            'upstream_status' => $image['status'],
        ]);
    }

    $bytes = $image['body'];

    if (
        $bytes === '' ||
        strlen($bytes) > MAX_IMAGE_BYTES
    ) {
        respondJson(502, [
            'success' => false,
            'error' => 'Image is empty or exceeds the 10 MB limit',
        ]);
    }

    /*
     * Validate actual image bytes, not just response headers.
     */
    $info = @getimagesizefromstring($bytes);

    $allowed = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ];

    if (
        $info === false ||
        !isset($info['mime']) ||
        !in_array($info['mime'], $allowed, true)
    ) {
        diagnostic('invalid_image_data', [
            'http_status' => $image['status'],
            'content_type' => $image['content_type'],
        ]);

        respondJson(502, [
            'success' => false,
            'error' => 'Provider did not return valid image data',
        ]);
    }

    $contentType = $info['mime'];

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: public, max-age=300');
    header('Content-Disposition: inline; filename="profile-picture"');

    echo $bytes;
    exit;

} catch (Throwable $e) {
    diagnostic('request_exception', [
        'type' => get_class($e),
        'message' => substr($e->getMessage(), 0, 300),
    ]);

    respondJson(502, [
        'success' => false,
        'error' => 'Unable to process request. Try again later.',
        'hint' => 'Check Vercel Runtime Logs for request_exception.',
    ]);
}
