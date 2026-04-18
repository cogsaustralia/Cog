<?php
/**
 * doc-download.php — Log document downloads by authenticated members
 * POST /_app/api/doc-download
 * Body: { "filename": "some-file.pdf" }
 * Requires active session (member must be logged in).
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

try {
    $principal = requireAuth('snft');
    $db = getDB();
    $body = json_decode((string)file_get_contents('php://input'), true);
    $filename = trim((string)($body['filename'] ?? ''));

    if ($filename === '' || strlen($filename) > 255) {
        apiError('Invalid filename.');
    }

    // Sanitise — only allow expected characters
    $filename = preg_replace('/[^a-zA-Z0-9._\-() ]/', '', $filename);

    $stmt = $db->prepare(
        'INSERT INTO wallet_events (subject_type, subject_ref, event_type, description, created_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $stmt->execute([
        'snft_member',
        (string)($principal['subject_ref'] ?? $principal['member_number'] ?? ''),
        'document_download',
        $filename
    ]);

    apiSuccess(['logged' => true, 'filename' => $filename]);
} catch (Throwable $e) {
    // Don't block the download if logging fails
    http_response_code(200);
    echo json_encode(['success' => true, 'logged' => false, 'note' => 'Log skipped']);
}
