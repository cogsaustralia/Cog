<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);
ob_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integrations/crm.php';
require_once __DIR__ . '/integrations/mailer.php';
// NOTE: auth.php is NOT required here. It was previously required at startup which caused
// its match($action) block to execute on every request before routing, always calling
// handleLogin() and returning "Member number is required." for all non-member requests.
// Auth handling goes entirely through routes/auth.php when route=auth.

$corsOrigin = getCorsOrigin();
if ($corsOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $corsOrigin);
    header('Vary: Origin');
    // Credentials (cookies) may only be sent with a specific, non-wildcard origin.
    // Browsers hard-block credentialed requests when origin is '*'.
    if ($corsOrigin !== '*') {
        header('Access-Control-Allow-Credentials: true');
    }
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/');
$parts = array_values(array_filter(explode('/', $path), static fn($v) => $v !== ''));
$route = '';
$id = null;

foreach (['api', '_app'] as $anchor) {
    $idx = array_search($anchor, $parts, true);
    if ($idx !== false) {
        $routeParts = array_slice($parts, $idx + 1);
        if ($anchor === '_app' && (($routeParts[0] ?? '') === 'api')) {
            array_shift($routeParts);
        }
        if (($routeParts[0] ?? '') === 'index.php') {
            array_shift($routeParts);
        }
        $route = $routeParts[0] ?? '';
        $id = count($routeParts) > 1 ? implode('/', array_slice($routeParts, 1)) : null;
        break;
    }
}

if ($route === '') {
    $queryRoute = trim((string)($_GET['route'] ?? ''), '/');
    if ($queryRoute !== '') {
        $queryParts = array_values(array_filter(explode('/', $queryRoute), static fn($v) => $v !== ''));
        $route = $queryParts[0] ?? '';
        $id = count($queryParts) > 1 ? implode('/', array_slice($queryParts, 1)) : null;
    }
}

try {
    switch ($route) {
        case 'invitations':
            require __DIR__ . '/routes/invitations.php';
            break;
        case 'snft-reserve':
            require __DIR__ . '/routes/snft-reserve.php';
            break;
        case 'bnft-reserve':
            require __DIR__ . '/routes/bnft-reserve.php';
            break;
        case 'vault-interest':
            require __DIR__ . '/routes/vault-interest.php';
            break;
        case 'auth':
            require __DIR__ . '/routes/auth.php';
            break;
        case 'vault':
            require __DIR__ . '/routes/vault.php';
            break;
        case 'kyc':
            require __DIR__ . '/routes/kyc.php';
            break;
        case 'community':
            require __DIR__ . '/routes/community.php';
            break;
        case 'admin':
            require __DIR__ . '/routes/admin.php';
            break;
        case 'news':
            require __DIR__ . '/routes/news.php';
            break;
        case 'vote':
            require __DIR__ . '/routes/vote.php';
            break;
        case 'ask':
            require __DIR__ . '/routes/ask.php';
            break;
        case 'community-stats':
            require __DIR__ . '/routes/community-stats.php';
            break;
        case 'doc-download':
            require __DIR__ . '/routes/doc-download.php';
            break;
        case 'health':
        case 'status':
            apiSuccess([
                'ok' => true,
                'app' => 'COG$ Current Phase Beta',
                'php' => PHP_VERSION,
                'route' => $route,
            ]);
            break;
        case 'business-interest':
            require __DIR__ . '/routes/business-interest.php';
            break;
        case 'member-email-check':
            require __DIR__ . '/routes/member-email-check.php';
            break;
        case 'member-mobile-check':
            require __DIR__ . '/routes/member-mobile-check.php';
            break;
        case 'address-verify':
            require __DIR__ . '/routes/address-verify.php';
            break;
        case 'address-lookup':
            require __DIR__ . '/routes/address-lookup.php';
            break;
        case 'parcel-verify':
            require __DIR__ . '/routes/parcel-verify.php';
            break;
        default:
            apiError('Route not found', 404);
            break;
    }
} catch (Throwable $e) {
    ob_end_clean();
    apiError('Server error: ' . $e->getMessage(), 500);
}
