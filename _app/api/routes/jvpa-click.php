<?php
/**
 * jvpa-click.php — Record anonymous JVPA PDF click from join page (pre-auth)
 *
 * POST /_app/api/jvpa-click
 * Body: {
 *   "session_token": "<uuid>",      // browser-generated anonymous token
 *   "referrer_code": "<string>",    // partner/invite code if present (optional)
 *   "page_context":  "join"         // optional, defaults to "join"
 * }
 *
 * No authentication required — caller is not yet a member.
 * IP is hashed (SHA-256) before storage — never stored raw.
 * Fails silently (200 + logged:false) so it never blocks the PDF open.
 */
declare(strict_types=1);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';

    $body = json_decode((string)file_get_contents('php://input'), true) ?? [];

    $sessionToken = substr(trim((string)($body['session_token'] ?? '')), 0, 64);
    $referrerCode = substr(trim((string)($body['referrer_code'] ?? '')), 0, 64) ?: null;
    $pageContext  = substr(trim((string)($body['page_context']  ?? 'join')), 0, 64);

    // Require a session token — without one the row is meaningless
    if ($sessionToken === '') {
        echo json_encode(['success' => true, 'logged' => false, 'note' => 'No session token']);
        exit;
    }

    // Hash the IP — store no raw PII
    $rawIp   = $_SERVER['REMOTE_ADDR'] ?? '';
    $ipHash  = $rawIp !== '' ? hash('sha256', $rawIp) : null;

    // Truncate UA to avoid storing a fingerprinting blob
    $ua      = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120) ?: null;

    $db = getDB();

    // Guard: table may not exist yet if migration not run
    $tableExists = (bool)$db->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'jvpa_pdf_clicks'"
    )->fetchColumn();

    if (!$tableExists) {
        echo json_encode(['success' => true, 'logged' => false, 'note' => 'Table not ready']);
        exit;
    }

    $stmt = $db->prepare(
        'INSERT INTO jvpa_pdf_clicks
           (session_token, page_context, referrer_code, ip_hash, user_agent_snippet, clicked_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $stmt->execute([$sessionToken, $pageContext, $referrerCode, $ipHash, $ua]);

    echo json_encode(['success' => true, 'logged' => true]);

} catch (Throwable $e) {
    // Never block the download
    http_response_code(200);
    echo json_encode(['success' => true, 'logged' => false, 'note' => 'Log skipped']);
}
