<?php
/**
 * address-verify.php — Admin API route for address verification
 * COGs of Australia Foundation
 *
 * Deploy to: _app/api/routes/address-verify.php
 *
 * Routes:
 *   POST /address-verify/member/{id}    — Verify a single member's address
 *   POST /address-verify/batch          — Re-check all members against zones
 *   GET  /address-verify/status/{id}    — Get verification status for a member
 *   GET  /address-verify/zones          — List active affected zones
 */

declare(strict_types=1);

// Action can come from URL path segment OR from POST body 'action' field.
// URL path:  POST /address-verify/member          → $id = 'member'
// POST body: POST /address-verify  + {"action":"member","member_id":1}
$_body_action  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_raw = file_get_contents('php://input');
    $_parsed = json_decode($_raw ?: '', true);
    $_body_action = (string)(is_array($_parsed) ? ($_parsed['action'] ?? '') : '');
}
$_segments = $id ? explode('/', trim((string)$id, '/')) : [];
$action    = $_segments[0] ?? $_body_action;
if (!$action || !in_array($action, ['member','business','batch','status','zones'], true)) {
    $action = $_body_action ?: 'status';
}

match ($action) {
    'member'   => handleVerifyMember(),
    'business' => handleVerifyBusiness(),
    'batch'    => handleBatchRecheck(),
    'status'   => handleGetStatus(),
    'zones'    => handleListZones(),
    default   => apiError('Unknown address-verify route', 404),
};

// ─────────────────────────────────────────────────────────────────────────
// Verify a single member's address
// ─────────────────────────────────────────────────────────────────────────

function handleVerifyMember(): void
{
    requireMethod('POST');
    $admin = requireAdminRole();
    $db    = getDB();
    $body  = jsonBody();

    $memberId = (int)($body['member_id'] ?? 0);
    if ($memberId < 1) {
        apiError('member_id is required.');
    }

    // Load member
    $stmt = $db->prepare(
        'SELECT id, member_number, street_address, suburb, state_code, postcode
         FROM members WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        apiError('Member not found.', 404);
    }

    if (!$member['street_address'] || !$member['suburb'] || !$member['postcode']) {
        apiError('Member has no address on file. Address columns must be populated first.');
    }

    // Run the agent
    require_once __DIR__ . '/../services/GnafAddressAgent.php';
    $agent = new GnafAddressAgent($db);

    try {
        $result = $agent->verify(
            (int)$member['id'],
            (string)$member['member_number'],
            (string)$member['street_address'],
            (string)$member['suburb'],
            (string)($member['state_code'] ?? ''),
            (string)$member['postcode']
        );

        apiSuccess([
            'member_id'       => $memberId,
            'member_number'   => $member['member_number'],
            'status'          => $result['status'],
            'gnaf_pid'        => $result['gnaf_pid'],
            'gnaf_address'    => $result['gnaf_address'],
            'confidence'      => $result['gnaf_confidence'],
            'latitude'        => $result['latitude'],
            'longitude'       => $result['longitude'],
            'zone_code'       => $result['zone_code'],
            'in_affected_zone'=> $result['in_affected_zone'],
            'evidence_hash'   => $result['evidence_hash'],
        ]);
    } catch (\Throwable $e) {
        apiError('Address verification failed: ' . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Verify a business address (bnft_memberships)
// ─────────────────────────────────────────────────────────────────────────

function handleVerifyBusiness(): void
{
    requireMethod('POST');
    $admin = requireAdminRole();
    $db    = getDB();
    $body  = jsonBody();

    $bizId = (int)($body['business_id'] ?? 0);
    if ($bizId < 1) {
        apiError('business_id is required.');
    }

    $stmt = $db->prepare(
        'SELECT id, abn, street_address, suburb, state_code, postcode
         FROM bnft_memberships WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$bizId]);
    $biz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$biz) {
        apiError('Business not found.', 404);
    }

    if (!$biz['street_address'] || !$biz['suburb'] || !$biz['postcode']) {
        apiError('Business has no address on file.');
    }

    require_once __DIR__ . '/../services/GnafAddressAgent.php';
    $agent = new GnafAddressAgent($db);

    try {
        // Use a synthetic member_number (ABN) for the agent
        $result = $agent->verify(
            0,
            (string)$biz['abn'],
            (string)$biz['street_address'],
            (string)$biz['suburb'],
            (string)($biz['state_code'] ?? ''),
            (string)$biz['postcode']
        );

        // Update bnft_memberships with the GNAF PID
        if (!empty($result['gnaf_pid'])) {
            $db->prepare('UPDATE bnft_memberships SET gnaf_pid = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$result['gnaf_pid'], $bizId]);
        }

        apiSuccess([
            'business_id'     => $bizId,
            'abn'             => $biz['abn'],
            'status'          => $result['status'],
            'gnaf_pid'        => $result['gnaf_pid'],
            'gnaf_address'    => $result['gnaf_address'],
            'confidence'      => $result['gnaf_confidence'],
            'latitude'        => $result['latitude'],
            'longitude'       => $result['longitude'],
        ]);
    } catch (\Throwable $e) {
        apiError('Address verification failed: ' . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Batch re-check all members against current zone boundaries
// ─────────────────────────────────────────────────────────────────────────

function handleBatchRecheck(): void
{
    requireMethod('POST');
    $admin = requireAdminRole();
    $db    = getDB();

    require_once __DIR__ . '/../services/GnafAddressAgent.php';
    $agent = new GnafAddressAgent($db);

    try {
        $results = $agent->batchRecheck();
        apiSuccess([
            'checked'  => count($results),
            'results'  => $results,
        ]);
    } catch (\Throwable $e) {
        apiError('Batch recheck failed: ' . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Get verification status for a member
// ─────────────────────────────────────────────────────────────────────────

function handleGetStatus(): void
{
    requireMethod('GET');
    $admin = requireAdminRole();
    $db    = getDB();
    $body  = $_GET;

    $memberId = (int)($body['member_id'] ?? 0);
    if ($memberId < 1) {
        apiError('member_id query parameter is required.');
    }

    // Latest verification
    $stmt = $db->prepare(
        'SELECT id, gnaf_pid, gnaf_address, gnaf_confidence, gnaf_match_type,
                latitude, longitude, parcel_pid, zone_id, zone_code,
                in_affected_zone, status, verified_by, evidence_hash,
                created_at, verified_at
         FROM address_verifications
         WHERE member_id = ?
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$memberId]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);

    // Member's current address state
    $mStmt = $db->prepare(
        'SELECT id, member_number, street_address, suburb, state_code, postcode,
                gnaf_pid, zone_id, address_lat, address_lng,
                address_evidence_hash, address_verified_at, kyc_status
         FROM members WHERE id = ? LIMIT 1'
    );
    $mStmt->execute([$memberId]);
    $member = $mStmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        apiError('Member not found.', 404);
    }

    apiSuccess([
        'member'            => $member,
        'latest_verification' => $verification ?: null,
        'has_been_verified'   => (bool)$verification,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────
// List active affected zones
// ─────────────────────────────────────────────────────────────────────────

function handleListZones(): void
{
    requireMethod('GET');
    $admin = requireAdminRole();
    $db    = getDB();

    $stmt = $db->query(
        "SELECT id, zone_code, zone_name, zone_type, version, status,
                effective_date, review_date, expires_at,
                fnac_consulted, fnac_endorsed, board_approved,
                ST_AsText(geometry) AS boundary_wkt,
                created_at
         FROM affected_zones
         ORDER BY status = 'active' DESC, zone_code ASC"
    );

    apiSuccess([
        'zones' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}
