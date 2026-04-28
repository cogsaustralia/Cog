<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/VoiceSubmissionService.php';

// Dispatch pattern mirrors vault.php
$_vsId    = trim((string)($id ?? ''), '/');
$_parts   = explode('/', $_vsId, 2);
$action   = $_parts[0] ?? '';
$actionId = (int)($action ?: 0);

$db  = getDB();
$svc = new VoiceSubmissionService($db);

// ── POST / (create) ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
    $session = requireAuth('snft');
    $stmt    = $db->prepare(
        'SELECT p.id AS partner_id, m.first_name, m.state_code, m.email
         FROM partners p
         JOIN members m ON m.id = p.member_id
         WHERE p.member_id = ?
         ORDER BY p.id ASC LIMIT 1'
    );
    $stmt->execute([(int)$session['principal_id']]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partner) {
        apiError('Partner record not found.', 403);
    }
    try {
        $result = $svc->create(
            (int)$partner['partner_id'],
            (string)($partner['email'] ?? ''),
            (string)($partner['first_name'] ?? ''),
            (string)($partner['state_code'] ?? ''),
            $_POST,
            $_FILES,
            $_SERVER
        );
        apiSuccess($result, 201);
    } catch (InvalidArgumentException $e) {
        apiError($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        error_log('voice-submissions create error: ' . $e->getMessage());
        apiError('An error occurred while saving your submission.', 500);
    }
}

// ── GET /me (list member's own submissions) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'me') {
    $session = requireAuth('snft');
    $stmt    = $db->prepare(
        'SELECT p.id AS partner_id FROM partners p
         WHERE p.member_id = ?
         ORDER BY p.id ASC LIMIT 1'
    );
    $stmt->execute([(int)$session['principal_id']]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partner) {
        apiError('Partner record not found.', 403);
    }
    apiSuccess($svc->listForMember((int)$partner['partner_id']));
}

// ── POST /{id}/withdraw ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $actionId > 0 && isset($_parts[1]) && $_parts[1] === 'withdraw') {
    $session = requireAuth('snft');
    $stmt    = $db->prepare(
        'SELECT p.id AS partner_id FROM partners p
         WHERE p.member_id = ?
         ORDER BY p.id ASC LIMIT 1'
    );
    $stmt->execute([(int)$session['principal_id']]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partner) {
        apiError('Partner record not found.', 403);
    }
    $body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $reason = trim((string)($body['withdrawn_reason'] ?? ''));
    try {
        apiSuccess($svc->withdraw((int)$partner['partner_id'], $actionId, $reason));
    } catch (InvalidArgumentException $e) {
        apiError($e->getMessage(), 404);
    } catch (RuntimeException $e) {
        apiError($e->getMessage(), 403);
    }
}

// ── GET /{id}/file (stream file — member owner or admin) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $actionId > 0 && isset($_parts[1]) && $_parts[1] === 'file') {
    $partnerId = 0;
    $isAdmin   = false;
    $principal = null;
    try {
        $principal = requireAuth('snft');
    } catch (Throwable $e) {}
    if ($principal) {
        $stmt = $db->prepare(
            'SELECT p.id AS partner_id FROM partners p
             WHERE p.member_id = ? ORDER BY p.id ASC LIMIT 1'
        );
        $stmt->execute([(int)$principal['principal_id']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        $partnerId = (int)($p['partner_id'] ?? 0);
    } else {
        // Fall back to admin session for admin preview
        try {
            requireAdminRole();
            $isAdmin = true;
        } catch (Throwable $e) {
            apiError('Authentication required.', 401);
        }
    }
    $svc->streamFile($partnerId, $isAdmin, $actionId);
}

apiError('Unknown voice-submissions action.', 404);
