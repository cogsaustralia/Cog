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
 * No authentication required. Rate-limiting: 10 writes per IP per 60 seconds (server-side,
 * via app_error_log count) + 3 per page load (client-side). Silent exit on breach.
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

    // Server-side rate limit: 10 writes per IP per 60 seconds.
    // Uses app_error_log as the counter — no new table required.
    // Silent exit on breach — never reveal throttling to an attacker.
    // Legitimate browsers send at most 3 per page load; 10/60s is generous.
    $db = getDB();
    if ($ipHash !== null) {
        try {
            $rl = $db->prepare(
                "SELECT COUNT(*) FROM app_error_log
                  WHERE ip_hash = ?
                    AND http_status = 0
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)"
            );
            $rl->execute([$ipHash]);
            if ((int)($rl->fetchColumn() ?: 0) >= 10) {
                exit; // silent — same 204 already sent above
            }
        } catch (Throwable $rlEx) {
            // Rate limit check failed — fail open (allow write) rather than
            // block legitimate errors. Log silently.
            error_log('[client-error rate-limit] ' . $rlEx->getMessage());
        }
    }
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

    // First-seen alert: email admin if this error class has not fired in the last 60 minutes.
    // Debounced by route + message snippet to avoid flooding on repeat errors.
    try {
        $snippet = substr($fullMessage, 0, 120);
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) FROM app_error_log
              WHERE route = ? AND LEFT(error_message,120) = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
                AND created_at < NOW()"
        );
        $stmt2->execute([$route, $snippet]);
        $priorCount = (int)($stmt2->fetchColumn() ?: 0);

        if ($priorCount === 0 && function_exists('smtpSendEmail') && mailerEnabled()) {
            $adminTo = defined('MAIL_ADMIN_EMAIL') ? MAIL_ADMIN_EMAIL : 'admin@cogsaustralia.org';
            $subject = '[COGS Alert] New JS error: ' . substr($route, 0, 60);
            $html = '<p><strong>New client-side JS error detected on cogsaustralia.org</strong></p>'
                . '<p><strong>Route/Page:</strong> ' . htmlspecialchars($route) . '</p>'
                . '<p><strong>Error:</strong> ' . htmlspecialchars(substr($fullMessage, 0, 500)) . '</p>'
                . '<p><strong>Time:</strong> ' . date('Y-m-d H:i:s T') . '</p>'
                . '<p>View and acknowledge at <a href="https://cogsaustralia.org/admin/errors.php">admin/errors.php</a></p>';
            $text = "New client-side JS error detected on cogsaustralia.org\n"
                . "Route/Page: {$route}\n"
                . "Error: " . substr($fullMessage, 0, 500) . "\n"
                . "Time: " . date('Y-m-d H:i:s T') . "\n"
                . "View: https://cogsaustralia.org/admin/errors.php";
            smtpSendEmail($adminTo, $subject, $html, $text);
        }
    } catch (Throwable $alertEx) {
        error_log('[client-error alert] ' . $alertEx->getMessage());
    }

} catch (Throwable $e) {
    // Silent fail — never expose errors to the browser
    error_log('[client-error beacon] ' . $e->getMessage());
}

exit;
