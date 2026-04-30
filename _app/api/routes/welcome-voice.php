<?php
declare(strict_types=1);
// Deliberately unauthenticated — cold visitors have no session.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body  = json_decode((string)file_get_contents('php://input'), true) ?: [];
$text  = trim((string)($body['text_content']   ?? ''));
$token = trim((string)($body['session_token']  ?? ''));
$ref   = trim((string)($body['ref_source']     ?? ''));

if ($text === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Text content is required.']);
    exit;
}
if (mb_strlen($text, 'UTF-8') > 280) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Text must be 280 characters or fewer.']);
    exit;
}
if ($token === '' || strlen($token) < 20 || strlen($token) > 64) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Session token required.']);
    exit;
}

// Sanitise ref — alphanumeric/hyphen/underscore only, max 20 chars
$ref = substr(preg_replace('/[^a-z0-9_-]/i', '', $ref), 0, 20);

// IP hash — never store raw IP
$salt   = defined('APP_SALT') ? APP_SALT : (string)(getenv('APP_SALT') ?: 'cogs_2026');
$ipRaw  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
// Use first IP only (X-Forwarded-For can be a comma-list)
$ipRaw  = trim(explode(',', $ipRaw)[0]);
$ipHash = hash('sha256', $salt . $ipRaw);

try {
    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO pending_voice_submissions
           (session_token, ip_hash, text_content, ref_source)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           text_content = VALUES(text_content),
           ref_source   = COALESCE(VALUES(ref_source), ref_source)'
    );
    $stmt->execute([$token, $ipHash, $text, $ref !== '' ? $ref : null]);

    $pendingId = (int)$db->lastInsertId();
    if ($pendingId === 0) {
        // ON DUPLICATE KEY — fetch existing id
        $s = $db->prepare(
            'SELECT id FROM pending_voice_submissions WHERE session_token = ? LIMIT 1'
        );
        $s->execute([$token]);
        $pendingId = (int)($s->fetchColumn() ?: 0);
    }

    echo json_encode(['success' => true, 'data' => ['pending_id' => $pendingId]]);
} catch (Throwable $e) {
    error_log('[welcome-voice] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save submission.']);
}