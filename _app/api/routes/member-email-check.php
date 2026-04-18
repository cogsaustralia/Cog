<?php
declare(strict_types=1);

// Accepts GET or POST — form uses POST with JSON body
$body  = jsonBody();
$email = strtolower(sanitize($body['email'] ?? sanitize($_GET['email'] ?? '')));

if (!validateEmail($email)) {
    apiError('A valid email address is required.', 422);
}

$db = getDB();

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
