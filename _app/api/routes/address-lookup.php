<?php
/**
 * address-lookup.php — Public Address Autocomplete Proxy
 * POST /_app/api/address-lookup
 *
 * Proxies address searches to Geoscape Predictive API (PSMA/G-NAF)
 * without exposing API keys to the browser.
 *
 * Returns: array of address suggestions with G-NAF PIDs.
 * Rate limited: 20 requests per IP per minute.
 * No authentication required (public join form).
 *
 * Requires in .env:
 *   GEOSCAPE_CONSUMER_KEY
 *   GEOSCAPE_BASE_URL  (default: https://api.psma.com.au)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// ── Rate limiting ───────────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateDir = sys_get_temp_dir() . '/cogs_addr_rate';
if (!is_dir($rateDir)) @mkdir($rateDir, 0755, true);
$rateFile = $rateDir . '/' . md5($ip) . '.json';
$rateData = file_exists($rateFile) ? json_decode((string)file_get_contents($rateFile), true) : [];
$now = time();
$rateData = array_filter($rateData ?: [], fn($t) => $t > $now - 60);
if (count($rateData) >= 20) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait a moment.']);
    exit;
}
$rateData[] = $now;
file_put_contents($rateFile, json_encode($rateData));

// ── Parse input ─────────────────────────────────────────────────────────
$body = json_decode((string)file_get_contents('php://input'), true);
$query = trim((string)($body['query'] ?? ''));

if ($query === '' || mb_strlen($query) < 3 || mb_strlen($query) > 200) {
    http_response_code(400);
    echo json_encode(['error' => 'Query must be 3-200 characters.', 'suggestions' => []]);
    exit;
}

// ── API credentials ─────────────────────────────────────────────────────
$consumerKey = (string)env('GEOSCAPE_CONSUMER_KEY', '');
$baseUrl = rtrim((string)env('GEOSCAPE_BASE_URL', 'https://api.psma.com.au'), '/');

if ($consumerKey === '') {
    // Fallback: return empty suggestions gracefully (manual entry will be used)
    echo json_encode(['suggestions' => [], 'fallback' => true]);
    exit;
}

// ── Call Geoscape Predictive API ────────────────────────────────────────
$url = $baseUrl . '/v1/predictive/address?' . http_build_query([
    'query'      => $query,
    'maxResults' => 6,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Authorization: ' . $consumerKey,
    ],
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '' || $httpCode !== 200) {
    error_log('[address-lookup] Geoscape error ' . $httpCode . ': ' . substr((string)$response, 0, 300));
    // Return empty — front-end will show manual entry
    echo json_encode(['suggestions' => [], 'fallback' => true]);
    exit;
}

$data = json_decode((string)$response, true);
$suggestions = [];

foreach (($data['suggest'] ?? []) as $item) {
    $suggestions[] = [
        'id'      => (string)($item['id'] ?? ''),
        'address' => (string)($item['address'] ?? ''),
    ];
}

echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
