<?php
declare(strict_types=1);
ignore_user_abort(true); // Continue executing after HTTP response is sent — required for inline email queue processing
set_time_limit(60);      // Ensure sufficient time for SMTP send after join completes

requireMethod('POST');
$db   = getDB();
$body = jsonBody();

// ── Input ──────────────────────────────────────────────────────────────────────
$personalName   = sanitize($body['personal_name'] ?? '');
$email          = strtolower(sanitize($body['email'] ?? ''));
$mobile         = sanitize($body['mobile'] ?? '');
$goodsServices  = trim((string)($body['goods_services'] ?? ''));
$acceptancePct  = max(1, min(100, (int)($body['acceptance_percent'] ?? 20)));
$existingMember = sanitize($body['existing_member'] ?? 'no');
$memberVerified = !empty($body['existing_member_verified']);
$sourcePage     = sanitize($body['source_page'] ?? 'business-interest');

// ── Validation ─────────────────────────────────────────────────────────────────
if ($personalName === '')   apiError('Name is required.');
if (!validateEmail($email)) apiError('A valid email address is required.');
if ($goodsServices === '')  apiError('Please describe your goods and services.');

// ── Duplicate guard ────────────────────────────────────────────────────────────
$dup = $db->prepare('SELECT id FROM business_interest_submissions WHERE email = ? LIMIT 1');
$dup->execute([$email]);
if ($dup->fetch()) {
    apiError('An interest submission already exists for this email. We will be in touch.');
}

// ── Insert ─────────────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    INSERT INTO business_interest_submissions
        (personal_name, email, mobile, goods_services, acceptance_percent,
         existing_member, existing_member_verified, source_page, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
");
$stmt->execute([
    $personalName,
    $email,
    $mobile,
    $goodsServices,
    $acceptancePct,
    $existingMember,
    $memberVerified ? 1 : 0,
    $sourcePage,
]);
$submissionId = (int)$db->lastInsertId();
$reference    = 'BIZ-' . str_pad((string)$submissionId, 5, '0', STR_PAD_LEFT);

// ── Confirmation email to submitter ────────────────────────────────────────────
$payloadUser = [
    'personal_name'      => $personalName,
    'email'              => $email,
    'goods_services'     => $goodsServices,
    'acceptance_percent' => $acceptancePct,
    'existing_member'    => $existingMember,
    'reference'          => $reference,
];
queueEmail($db, 'business_interest', $submissionId, $email,
    'business_interest_confirmation',
    'Your business interest has been received — COG$ of Australia Foundation',
    $payloadUser);

// ── Admin alert ────────────────────────────────────────────────────────────────
$adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : (string)env('ADMIN_EMAIL', '');
if ($adminEmail !== '') {
    queueEmail($db, 'business_interest', $submissionId, $adminEmail,
        'business_interest_admin',
        'New business interest — ' . $personalName . ' (' . $email . ')',
        array_merge($payloadUser, [
            'mobile'       => $mobile,
            'source_page'  => $sourcePage,
            'submitted_at' => nowUtc(),
        ]));
}

processEmailQueue($db, 4);

apiSuccess([
    'ok'        => true,
    'name'      => $personalName,
    'email'     => $email,
    'percent'   => $acceptancePct,
    'reference' => $reference,
], 201);
