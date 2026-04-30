<?php
/**
 * track.php — Anonymous visit + funnel event logger (pre-auth).
 *
 *   GET  /_app/api/index.php?route=track/visit&p=intro&ref=fb&utm_campaign=fairsay&utm_content=D-15
 *        Returns 1x1 transparent GIF. Use as <img> beacon in cold-traffic pages.
 *
 *   POST /_app/api/index.php?route=track/event
 *        Body: { "event": "join_started", "path": "join", "metadata": "..." }
 *        Returns JSON {success: true|false}.
 *
 * No authentication required — caller is not yet a member.
 * IP is hashed (SHA-256) before storage. Never raw.
 * UA is truncated to 120 chars.
 * Fails silently — never blocks page rendering or form submission.
 *
 * Mirrors the privacy posture of routes/jvpa-click.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// ─── Parse sub-action from $id (set by the API router as the path tail) ────
$action = is_string($id ?? null) ? trim((string)$id, '/') : '';

// ─── Helper: hash IP, truncate UA, detect mobile ────────────────────────────
function track_request_meta(): array {
    $rawIp  = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua     = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return [
        'ip_hash'  => $rawIp !== '' ? hash('sha256', $rawIp) : null,
        'ua'      => substr($ua, 0, 120) ?: null,
        'is_mobile' => preg_match('/Mobile|iPhone|Android|iPad|iPod/i', $ua) ? 1 : 0,
    ];
}

// ─── Helper: extract host from referrer ──────────────────────────────────────
function track_referrer_host(): ?string {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') return null;
    $host = parse_url($ref, PHP_URL_HOST);
    return $host ? substr($host, 0, 120) : null;
}

// ─── Helper: get-or-set anonymous session cookie ────────────────────────────
function track_session_token(): string {
    $cookieName = 'cogs_st';
    $existing = $_COOKIE[$cookieName] ?? '';
    if (is_string($existing) && preg_match('/^[a-f0-9]{32,64}$/i', $existing)) {
        return $existing;
    }
    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $token = substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 32);
    }
    setcookie(
        $cookieName,
        $token,
        [
            'expires'  => time() + (60 * 60 * 24 * 90),
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]
    );
    return $token;
}

// ─── Helper: 1x1 transparent GIF response ───────────────────────────────────
function track_pixel_response(): void {
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

// ─── Approved event vocabulary (drop unknowns silently) ─────────────────────
$ALLOWED_EVENTS = [
    'intro_card_seen',
    'intro_completed',
    'intro_skipped',
    'questions_clicked',
    'join_started',
    'join_field_focus',
    'join_invite_validated',
    'join_invite_failed',
    'join_submitted',
    'thankyou_seen',
    'stripe_clicked',
    'payid_clicked',
    'voice_started',
    'voice_submitted',
    'vault_setup_completed',
    'payment_received',
];

$ALLOWED_PATHS = [
    'index', 'intro', 'join', 'thank-you', 'thank-you-business',
    'welcome', 'skeptic', 'tell-me-more', 'vision',
    'landholders', 'gold-cogs', 'businesses', 'community', 'faq',
];

// ─── ACTION: visit (GET, returns pixel) ─────────────────────────────────────
if ($action === 'visit') {
    $token = track_session_token();

    $rawPath = (string)($_GET['p'] ?? '');
    $path    = in_array($rawPath, $ALLOWED_PATHS, true) ? $rawPath : 'other';

    $rawRef  = strtolower(trim((string)($_GET['ref'] ?? '')));
    $allowedRefs = ['fb','yt','ig','tw','li','email','sms','direct','qr','other'];
    $refSource = in_array($rawRef, $allowedRefs, true) ? $rawRef : null;

    $utmCampaign = substr(trim((string)($_GET['utm_campaign'] ?? '')), 0, 64) ?: null;
    $utmContent  = substr(trim((string)($_GET['utm_content']  ?? '')), 0, 64) ?: null;

    $partnerCode = strtoupper(trim((string)($_GET['partner_code'] ?? $_GET['code'] ?? '')));
    if ($partnerCode !== '' && !preg_match('/^COGS-[A-Z0-9]{4,10}$/', $partnerCode)) {
        $partnerCode = null;
    }
    $partnerCode = $partnerCode === '' ? null : $partnerCode;

    $meta = track_request_meta();
    $referrerHost = track_referrer_host();

    try {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO page_visits
               (session_token, path, ref_source, utm_campaign, utm_content,
                referrer_host, partner_code, ip_hash, user_agent_snippet, is_mobile)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $token, $path, $refSource, $utmCampaign, $utmContent,
            $referrerHost, $partnerCode, $meta['ip_hash'], $meta['ua'], $meta['is_mobile'],
        ]);
    } catch (Throwable $e) {
        error_log('[track/visit] ' . $e->getMessage());
    }

    track_pixel_response();
}

// ─── ACTION: event (POST or GET, returns JSON or pixel) ─────────────────────
if ($action === 'event') {
    header('Content-Type: application/json');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $token = track_session_token();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $body  = json_decode((string)file_get_contents('php://input'), true) ?? [];
        $event = (string)($body['event']    ?? '');
        $path  = (string)($body['path']     ?? '');
        $meta  = (string)($body['metadata'] ?? '');
    } else {
        $event = (string)($_GET['e']  ?? '');
        $path  = (string)($_GET['p']  ?? '');
        $meta  = (string)($_GET['m']  ?? '');
    }

    if (!in_array($event, $ALLOWED_EVENTS, true)) {
        echo json_encode(['success' => true, 'logged' => false, 'note' => 'Unknown event']);
        exit;
    }

    $path = in_array($path, $ALLOWED_PATHS, true) ? $path : null;
    $meta = $meta !== '' ? substr($meta, 0, 255) : null;

    try {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO funnel_events (session_token, event, path, metadata)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$token, $event, $path, $meta]);
        echo json_encode(['success' => true, 'logged' => true]);
        exit;
    } catch (Throwable $e) {
        error_log('[track/event] ' . $e->getMessage());
        echo json_encode(['success' => true, 'logged' => false]);
        exit;
    }
}

header('Content-Type: application/json');
http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Unknown track action']);
exit;