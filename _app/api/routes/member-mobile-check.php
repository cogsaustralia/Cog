<?php
declare(strict_types=1);

// Verify a personal S-NFT membership by mobile number.
// Used by the B-NFT business join form to confirm the Responsible Person
// holds a valid personal membership before allowing registration.
// Returns SNFT records only — BNFT-only contacts are not accepted.

$body   = jsonBody();
$raw    = sanitize($body['mobile'] ?? sanitize($_GET['mobile'] ?? ''));
$mobile = normalizePhone($raw);

if ($mobile === '' || strlen(preg_replace('/\D/', '', $mobile)) < 9) {
    apiError('A valid Australian mobile number is required.', 422);
}

// Build both formats for the lookup (DB may store 04xx or +614xx)
$mobileAlt = $mobile;
if (substr($mobile, 0, 1) === '0') {
    $mobileAlt = '+61' . substr($mobile, 1);
} elseif (substr($mobile, 0, 3) === '+61') {
    $mobileAlt = '0' . substr($mobile, 3);
}

$db   = getDB();
$stmt = $db->prepare(
    'SELECT id, member_number, full_name FROM snft_memberships WHERE mobile = ? OR mobile = ? LIMIT 1'
);
$stmt->execute([$mobile, $mobileAlt]);
$row = $stmt->fetch();

if ($row) {
    apiSuccess([
        'ok'            => true,
        'member_found'  => true,
        'member_number' => (string)($row['member_number'] ?? ''),
        'name'          => (string)($row['full_name']    ?? ''),
    ]);
} else {
    apiSuccess([
        'ok'           => true,
        'member_found' => false,
    ]);
}
