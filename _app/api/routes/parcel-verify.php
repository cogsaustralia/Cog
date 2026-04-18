<?php
/**
 * parcel-verify.php — Admin route for Landholder COG$ parcel verification
 *
 * Routes:
 *   POST /parcel-verify/member   — Verify a parcel claim for a member
 *   POST /parcel-verify/batch    — Re-check all landholder verifications
 *   GET  /parcel-verify/status   — Get verification status for a member
 *
 * Deploy to: _app/api/routes/parcel-verify.php
 * Register in: _app/api/index.php (case 'parcel-verify')
 */

declare(strict_types=1);

$_segments = $id ? explode('/', trim((string)$id, '/')) : [];
$_ba = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_raw = file_get_contents('php://input');
    $_p   = json_decode($_raw ?: '', true);
    $_ba  = (string)(is_array($_p) ? ($_p['action'] ?? '') : '');
}
$action = $_segments[0] ?? $_ba;
if (!$action || !in_array($action, ['member','batch','status'], true)) {
    $action = $_ba ?: 'status';
}

match ($action) {
    'member' => handleParcelVerifyMember(),
    'batch'  => handleParcelBatchRecheck(),
    'status' => handleParcelGetStatus(),
    default  => apiError('Unknown parcel-verify route', 404),
};

function handleParcelVerifyMember(): void
{
    requireMethod('POST');
    requireAdminRole();
    $db   = getDB();
    $body = jsonBody();

    $memberId      = (int)($body['member_id'] ?? 0);
    $holderType    = (string)($body['holder_type']     ?? 'freehold');
    $lot           = (string)($body['lot']             ?? '');
    $plan          = (string)($body['plan']            ?? '');
    $jurisdiction  = strtoupper((string)($body['jurisdiction'] ?? 'NSW'));
    $titleRef      = (string)($body['title_reference'] ?? '');
    $parcelPid     = (string)($body['parcel_pid']      ?? '');

    if ($memberId < 1) apiError('member_id is required.');
    if ($lot === '' && $plan === '' && $parcelPid === '') {
        apiError('Provide at least lot/plan or a parcel_pid.');
    }

    $mStmt = $db->prepare('SELECT id, member_number, full_name FROM members WHERE id = ? LIMIT 1');
    $mStmt->execute([$memberId]);
    $member = $mStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) apiError('Member not found.', 404);

    require_once __DIR__ . '/../services/ParcelLandholderAgent.php';
    $agent  = new ParcelLandholderAgent($db);
    $result = $agent->verify(
        (int)$member['id'],
        (string)$member['member_number'],
        $holderType, $lot, $plan, $jurisdiction, $titleRef, $parcelPid
    );

    // Log admin action
    try {
        $db->prepare(
            "INSERT INTO wallet_events (subject_type, subject_ref, event_type, description, created_at)
             VALUES ('snft_member', ?, 'parcel_verification_triggered',
                     CONCAT('Admin verified parcel claim. Status: ', ?, '. Tokens: ', ?, '. Zone: ', ?),
                     UTC_TIMESTAMP())"
        )->execute([
            $member['member_number'],
            $result['status'] ?? 'unknown',
            $result['tokens_calculated'] ?? 0,
            $result['tenement_zone_code'] ?? 'none',
        ]);
    } catch (Throwable $e) {}

    apiSuccess(array_merge($result, [
        'member_id'     => $memberId,
        'member_number' => $member['member_number'],
        'full_name'     => $member['full_name'],
    ]));
}

function handleParcelBatchRecheck(): void
{
    requireMethod('POST');
    requireAdminRole();
    $db = getDB();
    require_once __DIR__ . '/../services/ParcelLandholderAgent.php';
    $agent   = new ParcelLandholderAgent($db);
    $results = $agent->batchRecheck();
    apiSuccess($results);
}

function handleParcelGetStatus(): void
{
    requireMethod('GET');
    requireAdminRole();
    $db       = getDB();
    $memberId = (int)($_GET['member_id'] ?? 0);
    if ($memberId < 1) apiError('member_id is required.');

    $stmt = $db->prepare(
        'SELECT * FROM landholder_verifications
         WHERE member_id = ? ORDER BY created_at DESC LIMIT 5'
    );
    $stmt->execute([$memberId]);
    $verifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mStmt = $db->prepare(
        'SELECT id, member_number, full_name,
                landholder_hectares, landholder_tokens_calculated,
                landholder_holder_type, landholder_zero_cost,
                landholder_fnac_required, landholder_verified_at
         FROM members WHERE id = ? LIMIT 1'
    );
    $mStmt->execute([$memberId]);
    $member = $mStmt->fetch(PDO::FETCH_ASSOC);

    apiSuccess([
        'member'        => $member,
        'verifications' => $verifications,
    ]);
}
