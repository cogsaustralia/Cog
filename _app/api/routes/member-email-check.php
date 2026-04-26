<?php
declare(strict_types=1);

// Accepts GET or POST — form uses POST with JSON body
$body  = jsonBody();
$email = strtolower(sanitize($body['email'] ?? sanitize($_GET['email'] ?? '')));

if (!validateEmail($email)) {
    apiError('A valid email address is required.', 422);
}

$db = getDB();

// Rate limit — this endpoint reveals whether an email is registered (which
// is its purpose during signup), so it's an enumeration vector. Tight cap:
// a legitimate user typing their own email at signup needs maybe 2-3 checks;
// 10 per hour per IP is generous for them and well below useful botnet rate.
// recordAuthFailure() called for every request — the response itself is the
// enumeration leak regardless of "found" or "not found" outcome.
enforceRateLimit($db, 'check-email', 10, 3600, 3600);
recordAuthFailure($db, 'check-email');

// Check snft_memberships first, then bnft_memberships
$snft = $db->prepare('SELECT id, member_number, full_name FROM snft_memberships WHERE email = ? LIMIT 1');
$snft->execute([$email]);
$row = $snft->fetch();

if (!$row) {
    $bnft = $db->prepare('SELECT id, abn AS member_number, legal_name AS full_name FROM bnft_memberships WHERE email = ? LIMIT 1');
    $bnft->execute([$email]);
    $row = $bnft->fetch();
}

if ($row) {
    apiSuccess([
        'exists'        => true,
        'ok'            => true,
        'member_found'  => true,
        'member_number' => (string)($row['member_number'] ?? ''),
        'name'          => (string)($row['full_name'] ?? ''),
    ]);
} else {
    apiSuccess([
        'exists'       => false,
        'ok'           => true,
        'member_found' => false,
    ]);
}
