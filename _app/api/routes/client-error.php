<?php
/**
 * client-error.php — Browser JS error beacon.
 *
 *   POST /_app/api/index.php/client-error
 *   Body: {
 *     "message":  string   error message (required)
 *     "source":   string   script URL
 *     "line":     int      line number
 *     "col":      int      column number
 *     "stack":    string   stack trace (from Error.stack)
 *     "page":     string   window.location.pathname (truncated)
 *     "ua_hint":  string   first 80 chars of navigator.userAgent
 *   }
 *
 * Writes to app_error_log with http_status=0 to distinguish from server errors.
 * No authentication required. Rate-limiting is enforced client-side (3 per page load).
 * Fails silently — never returns an error to the browser.
 * IP and UA are hashed before storage. No raw PII stored.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// Always return 204 No Content — browser beacons do not need a response body
http_response_code(204);
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    exit;
}

try {
    $raw  = (string)file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        exit;
    }

    $message = substr(trim((string)($body['message'] ?? '')), 0, 4000);
    if ($message === '') {
        exit;
    }

    $source  = substr(trim((string)($body['source']  ?? '')), 0, 255)  ?: null;
    $line    = isset($body['line'])  ? (int)$body['line']  : null;
    $col     = isset($body['col'])   ? (int)$body['col']   : null;
    $stack   = substr(trim((string)($body['stack']   ?? '')), 0, 4000) ?: null;
    $page    = substr(trim((string)($body['page']    ?? '')), 0, 120)  ?: null;

    // Build a readable error message including location context
    $fullMessage = $message;
    if ($source !== null || $line !== null) {
        $loc = array_filter([
            $source,
            $line  !== null ? 'L' . $line  : null,
            $col   !== null ? 'C' . $col   : null,
        ]);
        if ($loc) {
            $fullMessage .= ' [' . implode(':', $loc) . ']';
        }
    }
    if ($stack !== null) {
        $fullMessage .= "\n" . $stack;
    }
    $fullMessage = substr($fullMessage, 0, 4000);

    // Route label: "client-error" + page path for grouping in errors.php
    $route = 'client-error' . ($page !== null ? ':' . $page : '');
    $route = substr($route, 0, 120);

    // Hash IP and UA — same privacy posture as server-side errors
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $rawUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ipHash = $rawIp !== '' ? hash('sha256', $rawIp) : null;
    $uaHash = $rawUa !== '' ? hash('sha256', $rawUa) : null;

    $db = getDB();
    $db->prepare(
        "INSERT INTO app_error_log
           (route, http_status, error_message, area_key,
            member_id, request_method, ip_hash, ua_hash, created_at)
         VALUES (?, 0, ?, ?, NULL, 'GET', ?, ?, NOW())"
    )->execute([
        $route,
        $fullMessage,
        'client-js',
        $ipHash,
        $uaHash,
    ]);

} catch (Throwable $e) {
    // Silent fail — never expose errors to the browser
    error_log('[client-error beacon] ' . $e->getMessage());
}

exit;
