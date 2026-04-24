<?php
declare(strict_types=1);
ignore_user_abort(true); // Continue executing after HTTP response is sent — required for inline email queue processing
set_time_limit(60);      // Ensure sufficient time for SMTP send after join completes

// JVPA Acceptance Service — Option A: acceptance recorded at registration
require_once __DIR__ . '/../services/JvpaAcceptanceService.php';

requireMethod('POST');
$db   = getDB();
$body = jsonBody();

// Capture IP and user agent for acceptance record hash
$acceptedIp        = (string)($_SERVER['REMOTE_ADDR']    ?? '');
$acceptedUserAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

$firstName = sanitize($body['first_name'] ?? '');
$lastName = sanitize($body['last_name'] ?? '');
$fullName = sanitize($body['full_name'] ?? trim($firstName . ' ' . $lastName));
$email = strtolower(sanitize($body['email'] ?? ''));
$mobile = sanitize($body['mobile'] ?? '');
// Normalise to consistent 04xx format (strips +61, spaces, non-digits)
if (function_exists('normalizePhone')) {
    $mobile = normalizePhone($mobile);
} else {
    $digits = preg_replace('/\D/', '', $mobile);
    if (substr($digits, 0, 2) === '61' && strlen($digits) === 11) {
        $mobile = '0' . substr($digits, 2);
    } else {
        $mobile = $digits;
    }
}
$dob = sanitize($body['dob'] ?? $body['date_of_birth'] ?? '');
$street = sanitize($body['street'] ?? '');
$suburb = sanitize($body['suburb'] ?? '');
$state = sanitize($body['state'] ?? '');
$postcode = sanitize($body['postcode'] ?? '');
$referralCode = strtoupper(substr(sanitize($body['referral_code'] ?? ''), 0, 16));

// Optional Partner invitation pathway fields. These are compatibility-friendly:
// existing joins continue to work unless invite mode is explicitly set to required.
$inviteCodeRaw = strtoupper(trim((string)($body['invite_code'] ?? $body['invite_code_used'] ?? '')));
$inviteCodeRaw = substr($inviteCodeRaw, 0, 60);
$inviteEntryType = 'personal';

$additionalInfo = trim((string)($body['additional_info'] ?? ''));
$noticeAccepted = !empty($body['reservation_notice_accepted']);
$noticeVersion = sanitize($body['reservation_notice_version'] ?? '');
$noticeAcceptedAtRaw = sanitize($body['reservation_notice_accepted_at'] ?? '');

$tokenInputs = [
    'PERSONAL_SNFT' => 1,
    'KIDS_SNFT' => normalizeTokenCount($body['kids_tokens'] ?? 0, 0, 100000),
    'LANDHOLDER_COG' => normalizeTokenCount($body['landholder_tokens'] ?? 0, 0, 100000),
    'ASX_INVESTMENT_COG' => normalizeTokenCount($body['investment_tokens'] ?? 0, 0, 100000),
    'PAY_IT_FORWARD_COG' => normalizeTokenCount($body['pay_it_forward_tokens'] ?? 0, 0, 100000),
    'DONATION_COG' => normalizeTokenCount($body['donation_tokens'] ?? 0, 0, 100000),
    'RWA_COG' => normalizeTokenCount($body['rwa_tokens'] ?? 0, 0, 100000),
    'LR_COG' => normalizeTokenCount($body['lr_tokens'] ?? 0, 0, 100000),
];

if ($fullName === '') apiError('Full name is required.');
if (!validateEmail($email)) apiError('A valid email is required.');
if ($mobile === '') apiError('Mobile is required.');
if ($dob === '') apiError('Date of birth is required.');
if ($street === '') apiError('Street address is required.');
if ($suburb === '') apiError('Suburb is required.');
if (!validatePostcode($postcode)) apiError('A valid 4 digit postcode is required.');
if (!$noticeAccepted) apiError('You must accept the beta reservation notice before continuing.');
if ($noticeVersion === '') apiError('Reservation notice version is required.');

function trust_cols(PDO $db, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table`");
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $cols[$row['Field']] = $row;
        return $cache[$table] = $cols;
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}
function trust_has_col(PDO $db, string $table, string $col): bool {
    return isset(trust_cols($db, $table)[$col]);
}
function trust_table_exists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function trust_get_setting(PDO $db, string $key, ?string $default = null): ?string {
    if (!trust_table_exists($db, 'admin_settings')) return $default;
    try {
        $stmt = $db->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string)$val;
    } catch (Throwable $e) {
        return $default;
    }
}
function trust_invite_mode(PDO $db): string {
    $raw = strtolower(trim((string)(trust_get_setting($db, 'partner_invitation_mode', trust_get_setting($db, 'invite_program_mode', 'required')) ?? 'required')));
    return match ($raw) {
        'required', 'on_required', 'enforced' => 'required',
        'disabled', 'off', 'inactive' => 'disabled',
        default => 'required',
    };
}
function trust_validate_partner_invite(PDO $db, string $publicCode, string $entryType = 'personal'): array {
    $result = [
        'ok' => false,
        'status' => 'missing',
        'invite_code_id' => null,
        'inviter_partner_id' => null,
        'invite_code_used' => null,
        'verified_at' => null,
        'notes' => null,
    ];
    $publicCode = strtoupper(trim($publicCode));
    if ($publicCode === '') return $result;
    $result['invite_code_used'] = $publicCode;
    if (!trust_table_exists($db, 'partner_invite_codes')) {
        $result['status'] = 'unavailable';
        return $result;
    }
    try {
        $stmt = $db->prepare('SELECT id, inviter_partner_id, public_code, status, allowed_entry_type, max_uses, use_count, expires_at FROM partner_invite_codes WHERE public_code = ? LIMIT 1');
        $stmt->execute([$publicCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $result['status'] = 'invalid';
            return $result;
        }
        if (($row['status'] ?? '') !== 'active') {
            $result['status'] = (($row['status'] ?? '') === 'revoked') ? 'revoked' : 'expired';
            return $result;
        }
        $allowed = strtolower((string)($row['allowed_entry_type'] ?? 'both'));
        if ($allowed !== 'both' && $allowed !== strtolower($entryType)) {
            $result['status'] = 'wrong_entry_type';
            return $result;
        }
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) !== false && strtotime((string)$row['expires_at']) < time()) {
            $result['status'] = 'expired';
            return $result;
        }
        $maxUses = isset($row['max_uses']) ? (int)$row['max_uses'] : 0;
        $useCount = isset($row['use_count']) ? (int)$row['use_count'] : 0;
        if ($maxUses > 0 && $useCount >= $maxUses) {
            $result['status'] = 'expired';
            return $result;
        }
        $result['ok'] = true;
        $result['status'] = 'valid';
        $result['invite_code_id'] = (int)$row['id'];
        $result['inviter_partner_id'] = (int)$row['inviter_partner_id'];
        $result['verified_at'] = gmdate('Y-m-d H:i:s');
        return $result;
    } catch (Throwable $e) {
        $result['status'] = 'error';
        $result['notes'] = $e->getMessage();
        return $result;
    }
}
function trust_log_partner_invitation_acceptance(PDO $db, array $inviteState, string $email, string $mobile, string $entryType = 'personal'): void {
    if (empty($inviteState['ok']) || !trust_table_exists($db, 'partner_invitations')) return;
    $cols = trust_cols($db, 'partner_invitations');
    $data = [];
    foreach ([
        'invite_code_id' => $inviteState['invite_code_id'] ?? null,
        'inviter_partner_id' => $inviteState['inviter_partner_id'] ?? null,
        'invitee_email_nullable' => $email !== '' ? $email : null,
        'invitee_mobile_nullable' => $mobile !== '' ? $mobile : null,
        'accepted_at' => date('Y-m-d H:i:s'),
        'entry_type' => $entryType,
        'created_at' => date('Y-m-d H:i:s'),
    ] as $col => $val) {
        if (isset($cols[$col])) $data[$col] = $val;
    }
    if ($data) {
        $names = array_keys($data);
        $marks = implode(',', array_fill(0, count($names), '?'));
        $sql = 'INSERT INTO partner_invitations (' . implode(',', array_map(fn($n) => "`$n`", $names)) . ') VALUES (' . $marks . ')';
        $db->prepare($sql)->execute(array_values($data));
    }
    try {
        $db->prepare('UPDATE partner_invite_codes SET use_count = use_count + 1, updated_at = NOW() WHERE id = ?')->execute([(int)$inviteState['invite_code_id']]);
    } catch (Throwable $e) {
        error_log('[snft-reserve] invite use_count increment failed: ' . $e->getMessage());
    }
}
function trust_generate_personal_member_number(PDO $db): string {
    // Use the member_number_sequence auto-increment table for race-safe generation.
    // AUTO_INCREMENT is atomic — concurrent inserts can never produce the same ID.
    try {
        if (trust_table_exists($db, 'member_number_sequence')) {
            $db->prepare('INSERT INTO member_number_sequence (created_at) VALUES (NOW())')->execute();
            $seqId  = (int)$db->lastInsertId();
            $prefix = defined('SNFT_MEMBER_PREFIX')
                ? str_pad(substr(preg_replace('/\D+/', '', (string)SNFT_MEMBER_PREFIX) ?: '608200', 0, 6), 6, '0', STR_PAD_RIGHT)
                : '608200';
            $base   = $prefix . str_pad((string)$seqId, 9, '0', STR_PAD_LEFT);
            return $base . (function_exists('luhnCheckDigit') ? luhnCheckDigit($base) : '0');
        }
    } catch (Throwable $e) {}

    // Fallback: SELECT MAX — less safe under concurrent load but prevents a hard failure
    // if the sequence table is unavailable. Uses MAX(sequence_no) which at least reflects
    // the highest known value rather than scanning P-prefix strings.
    $next = 100001;
    try {
        if (trust_table_exists($db, 'snft_memberships')) {
            $stmt = $db->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 AS next_seq FROM snft_memberships');
            $stmt->execute();
            $max = (int)($stmt->fetchColumn() ?? 0);
            if ($max >= $next) $next = $max;
        }
    } catch (Throwable $e) {}
    return 'P' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
}
function trust_upsert_reservation_line(PDO $db, int $memberId, int $tokenClassId, int $requestedUnits, bool $paymentRequired, bool $approvalRequired): void {
    $now = date('Y-m-d H:i:s');
    $approvalStatus = $approvalRequired ? 'pending' : 'not_required';
    $paymentStatus = $paymentRequired ? 'pending' : 'not_required';

    $existing = $db->prepare('SELECT id FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ? LIMIT 1');
    $existing->execute([$memberId, $tokenClassId]);
    $lineId = $existing->fetchColumn();

    if ($lineId) {
        $stmt = $db->prepare('UPDATE member_reservation_lines SET requested_units = ?, approval_status = ?, payment_status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$requestedUnits, $approvalStatus, $paymentStatus, $now, $lineId]);
        return;
    }

    $stmt = $db->prepare('INSERT INTO member_reservation_lines (member_id, token_class_id, requested_units, approved_units, paid_units, approval_status, payment_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$memberId, $tokenClassId, $requestedUnits, 0, 0, $approvalStatus, $paymentStatus, $now, $now]);
}
function trust_create_approval_request(PDO $db, int $memberId, int $tokenClassId, int $requestedUnits, int $valueCents, string $requestType, string $notes): void {
    if (!trust_table_exists($db, 'approval_requests') || $requestedUnits <= 0) return;
    $cols = trust_cols($db, 'approval_requests');
    $data = [];
    foreach ([
        'member_id' => $memberId,
        'token_class_id' => $tokenClassId,
        'request_type' => $requestType,
        'requested_units' => $requestedUnits,
        'requested_value_cents' => $valueCents,
        'request_status' => 'pending',
        'status' => 'pending',
        'notes' => $notes,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ] as $col => $val) {
        if (isset($cols[$col])) $data[$col] = $val;
    }
    if (!$data) return;
    $names = array_keys($data);
    $marks = implode(',', array_fill(0, count($names), '?'));
    $sql = 'INSERT INTO approval_requests (' . implode(',', array_map(fn($n) => "`$n`", $names)) . ') VALUES (' . $marks . ')';
    $db->prepare($sql)->execute(array_values($data));
}
function trust_log_activity(PDO $db, int $memberId, ?int $tokenClassId, string $actionType, array $payload = []): void {
    if (!trust_table_exists($db, 'wallet_activity')) return;
    $cols = trust_cols($db, 'wallet_activity');
    $data = [];
    foreach ([
        'member_id' => $memberId,
        'token_class_id' => $tokenClassId,
        'action_type' => $actionType,
        'actor_type' => 'system',
        'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'created_at' => date('Y-m-d H:i:s'),
    ] as $col => $val) {
        if (isset($cols[$col])) $data[$col] = $val;
    }
    if (!$data) return;
    $names = array_keys($data);
    $marks = implode(',', array_fill(0, count($names), '?'));
    $sql = 'INSERT INTO wallet_activity (' . implode(',', array_map(fn($n) => "`$n`", $names)) . ') VALUES (' . $marks . ')';
    $db->prepare($sql)->execute(array_values($data));
}

if (!trust_table_exists($db, 'members')) apiError('Members table is missing.');
if (!trust_table_exists($db, 'member_reservation_lines')) apiError('Reservation lines table is missing.');
if (!trust_table_exists($db, 'token_classes')) apiError('Token classes table is missing.');

$existsMembers = trust_table_exists($db, 'members');
if ($existsMembers) {
    $exists = $db->prepare('SELECT id FROM members WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        apiError('A personal reservation already exists for this email.');
    }
    // Check mobile duplicate — mobile is the primary vault login key
    // Check both 04xx and +614xx formats since DB may store either
    if ($mobile !== '') {
        $mobileAlt = $mobile;
        if (substr($mobile, 0, 1) === '0') {
            $mobileAlt = '+61' . substr($mobile, 1);
        } elseif (substr($mobile, 0, 3) === '+61') {
            $mobileAlt = '0' . substr($mobile, 3);
        }
        $mobCheck = $db->prepare("SELECT id FROM members WHERE phone = ? OR phone = ? LIMIT 1");
        $mobCheck->execute([$mobile, $mobileAlt]);
        if ($mobCheck->fetch()) {
            apiError('This mobile number is already registered to a member. If this is you, please sign in to your vault instead.');
        }
    }
}
if (trust_table_exists($db, 'snft_memberships')) {
    $exists = $db->prepare('SELECT id FROM snft_memberships WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        apiError('A personal reservation already exists for this email.');
    }
    // Check mobile duplicate in snft_memberships (both formats)
    if ($mobile !== '') {
        $mobileAlt = $mobile;
        if (substr($mobile, 0, 1) === '0') {
            $mobileAlt = '+61' . substr($mobile, 1);
        } elseif (substr($mobile, 0, 3) === '+61') {
            $mobileAlt = '0' . substr($mobile, 3);
        }
        $mobCheck2 = $db->prepare('SELECT id FROM snft_memberships WHERE mobile = ? OR mobile = ? LIMIT 1');
        $mobCheck2->execute([$mobile, $mobileAlt]);
        if ($mobCheck2->fetch()) {
            apiError('This mobile number is already registered to a member. If this is you, please sign in to your vault instead.');
        }
    }
}

// ── Partner Invitation Pathway ───────────────────────────────────────────────
$inviteMode  = trust_invite_mode($db);
$inviteState = trust_validate_partner_invite($db, $inviteCodeRaw, $inviteEntryType);

if ($inviteMode === 'required' && empty($inviteState['ok'])) {
    apiError('A valid Member invitation code is required to join the membership. Please return to the Member who introduced you or contact administration.');
}
if ($inviteCodeRaw !== '' && empty($inviteState['ok'])) {
    apiError('The Member invitation code could not be verified. Please check the code and try again.');
}

$stateCode = stateCode($state);
$generatedMember = function_exists('generateSnftMemberNumber') ? generateSnftMemberNumber($db, $stateCode) : ['member_number' => trust_generate_personal_member_number($db), 'sequence_no' => null, 'state_code' => $stateCode];
$memberNumber = (string)$generatedMember['member_number'];
$acceptedAt = $noticeAcceptedAtRaw !== '' ? gmdate('Y-m-d H:i:s', strtotime($noticeAcceptedAtRaw) ?: time()) : nowUtc();
$now = date('Y-m-d H:i:s');

$meta = [
    'mobile' => $mobile,
    'date_of_birth' => $dob,
    'street_address' => $street,
    'suburb' => $suburb,
    'state' => $stateCode,
    'postcode' => $postcode,
    'invite_mode' => $inviteMode,
    'invite_code_used' => $inviteState['invite_code_used'],
    'invite_validation_status' => $inviteState['status'],
    'invited_by_partner_id' => $inviteState['inviter_partner_id'],
    'invite_verified_at' => $inviteState['verified_at'],
    'referral_code' => $referralCode !== '' ? $referralCode : null,
    'additional_info' => $additionalInfo,
    'reservation_notice_version' => $noticeVersion,
    'reservation_notice_accepted_at' => $acceptedAt,
];

$classRows = [];
$stmt = $db->query("SELECT id, class_code, display_name, unit_price_cents, payment_required, approval_required FROM token_classes WHERE is_active = 1");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $classRows[(string)$row['class_code']] = $row;
}

$requiredCodes = ['PERSONAL_SNFT','KIDS_SNFT','LANDHOLDER_COG','ASX_INVESTMENT_COG','PAY_IT_FORWARD_COG','DONATION_COG','RWA_COG','LR_COG'];
$missingCodes = array_values(array_filter($requiredCodes, fn($code) => !isset($classRows[$code])));
if ($missingCodes) {
    apiError('Missing token classes: ' . implode(', ', $missingCodes), 500);
}

$reservationValue = 0.00;
foreach ($tokenInputs as $classCode => $units) {
    $reservationValue += ((int)($classRows[$classCode]['unit_price_cents'] ?? 0) / 100) * (int)$units;
}

$stewardshipModule = evaluateStewardshipModule((array)($body['stewardship_module'] ?? []), false);
$meta['stewardship_module_completed_at'] = $stewardshipModule['completed_at'];
$meta['stewardship_attestation_hash'] = $stewardshipModule['attestation_hash'];

$db->beginTransaction();
try {
    $memberCols = trust_cols($db, 'members');
    $memberData = [];
    foreach ([
        'member_number' => $memberNumber,
        'member_type' => 'personal',
        'first_name' => $firstName,
        'last_name' => $lastName,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $mobile,
        'mobile' => $mobile,
        'date_of_birth' => $dob !== '' ? $dob : null,
        'street_address' => $street !== '' ? $street : null,
        'suburb' => $suburb !== '' ? $suburb : null,
        'state_code' => $stateCode,
        'postcode' => $postcode,
        'wallet_status' => 'invited',
        'signup_payment_status' => 'pending',
        'stewardship_status' => 'active',
        'is_active' => 1,
        'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'created_at' => $now,
        'updated_at' => $now,
    ] as $col => $val) {
        if (isset($memberCols[$col])) $memberData[$col] = $val;
    }

    $names = array_keys($memberData);
    $marks = implode(',', array_fill(0, count($names), '?'));
    $sql = 'INSERT INTO members (' . implode(',', array_map(fn($n) => "`$n`", $names)) . ') VALUES (' . $marks . ')';
    $db->prepare($sql)->execute(array_values($memberData));
    $memberId = (int)$db->lastInsertId();

    if (trust_table_exists($db, 'snft_memberships')) {
        $snftCols = trust_cols($db, 'snft_memberships');
        $snftData = [];
        $snftBreakdown = [
            'reserved_tokens' => 1,
            'investment_tokens' => (int)$tokenInputs['ASX_INVESTMENT_COG'],
            'donation_tokens' => (int)$tokenInputs['DONATION_COG'],
            'pay_it_forward_tokens' => (int)$tokenInputs['PAY_IT_FORWARD_COG'],
            'kids_tokens' => (int)$tokenInputs['KIDS_SNFT'],
            'landholder_tokens' => (int)$tokenInputs['LANDHOLDER_COG'],
            'landholder_hectares' => 0,
            'tokens_total' => 1 + (int)$tokenInputs['KIDS_SNFT'] + (int)$tokenInputs['LANDHOLDER_COG'] + (int)$tokenInputs['ASX_INVESTMENT_COG'] + (int)$tokenInputs['PAY_IT_FORWARD_COG'] + (int)$tokenInputs['DONATION_COG'],
        ];
        foreach ([
            'sequence_no' => $generatedMember['sequence_no'] ?? null,
            'member_number' => $memberNumber,
            'state_code' => $stateCode,
            'community_tokens' => 1000,
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'full_name' => $fullName,
            'email' => $email,
            'mobile' => $mobile,
            'phone' => $mobile,
            'date_of_birth' => $dob !== '' ? $dob : null,
            'street_address' => $street !== '' ? $street : null,
            'suburb' => $suburb !== '' ? $suburb : null,
            'postcode' => $postcode,
            'referral_code' => $referralCode !== '' ? $referralCode : null,
    'invite_code_used' => $inviteState['invite_code_used'],
    'invite_validation_status' => $inviteState['status'],
    'invited_by_partner_id' => $inviteState['inviter_partner_id'],
    'invite_verified_at' => $inviteState['verified_at'],
            'additional_info' => $additionalInfo !== '' ? $additionalInfo : null,
            'reservation_notice_accepted' => 1,
            'reservation_notice_version' => $noticeVersion,
            'reservation_notice_accepted_at' => $acceptedAt,
            'reserved_tokens' => $snftBreakdown['reserved_tokens'],
            'investment_tokens' => $snftBreakdown['investment_tokens'],
            'donation_tokens' => $snftBreakdown['donation_tokens'],
            'pay_it_forward_tokens' => $snftBreakdown['pay_it_forward_tokens'],
            'kids_tokens' => $snftBreakdown['kids_tokens'],
            'landholder_hectares' => $snftBreakdown['landholder_hectares'],
            'landholder_tokens' => $snftBreakdown['landholder_tokens'],
            'tokens_total' => $snftBreakdown['tokens_total'],
            'reservation_value' => $reservationValue,
            'approved_tokens_total' => 0,
            'approved_reservation_value' => 0,
            'intent_status' => 'proposed',
            'entitlement_status' => 'inactive',
            'wallet_status' => 'pending_setup',
            'signup_payment_status' => 'pending',
            'stewardship_status' => 'active',
            'attestation_hash' => $stewardshipModule['attestation_hash'],
            'created_at' => $now,
            'updated_at' => $now,
        ] as $col => $val) {
            if (isset($snftCols[$col])) $snftData[$col] = $val;
        }
        if ($snftData) {
            $names = array_keys($snftData);
            $marks = implode(',', array_fill(0, count($names), '?'));
            $sql = 'INSERT INTO snft_memberships (' . implode(',', array_map(fn($n) => "`$n`", $names)) . ') VALUES (' . $marks . ')';
            $db->prepare($sql)->execute(array_values($snftData));
        }
    }

    if (!empty($inviteState['ok'])) {
        trust_log_partner_invitation_acceptance($db, $inviteState, $email, $mobile, 'personal');
    }

    foreach ($tokenInputs as $classCode => $units) {
        $class = $classRows[$classCode];
        $units = max(0, (int)$units);

        trust_upsert_reservation_line(
            $db,
            $memberId,
            (int)$class['id'],
            $units,
            (bool)($class['payment_required'] ?? 0),
            (bool)($class['approval_required'] ?? 0)
        );

        if ($units > 0 && ((int)($class['payment_required'] ?? 0) === 1 || (int)($class['approval_required'] ?? 0) === 1)) {
            $valueCents = (int)($class['unit_price_cents'] ?? 0) * $units;
            $requestType = ((int)($class['payment_required'] ?? 0) === 1) ? 'signup_payment' : 'manual_approval';
            trust_create_approval_request($db, $memberId, (int)$class['id'], $units, $valueCents, $requestType, 'Created from public SNFT reservation form');
        }
    }

    trust_log_activity($db, $memberId, null, 'initial_reservation', [
        'pathway' => 'snft',
        'member_number' => $memberNumber,
        'token_inputs' => $tokenInputs,
    ]);

    $snftLegacyId = 0;
    if (trust_table_exists($db, 'snft_memberships')) {
        try {
            $snftIdStmt = $db->prepare('SELECT id FROM snft_memberships WHERE member_number = ? LIMIT 1');
            $snftIdStmt->execute([$memberNumber]);
            $snftLegacyId = (int)($snftIdStmt->fetchColumn() ?: 0);
        } catch (Throwable $seedLookupEx) {
            $snftLegacyId = 0;
        }
    }
    if ($snftLegacyId > 0) {
        api_seed_personal_community_cog($db, $memberId, $snftLegacyId, $memberNumber, 1000, 'join_seed');
        recordWalletEvent($db, 'snft_member', $memberNumber, 'community_cog_seeded', 'Opening Community COG$ allocation recorded: 1,000 CC.');
        trust_log_activity($db, $memberId, null, 'community_cog_seeded', [
            'member_number' => $memberNumber,
            'units' => 1000,
            'source_action' => 'join_seed',
        ]);
    }

    // ── Create partners row (required for partner_entry_records FK) ──────────
    $partnerId = null;
    if (trust_table_exists($db, 'partners')) {
        $partnerCols = trust_cols($db, 'partners');
        $partnerData = [];
        foreach ([
            'member_id'              => $memberId,
            'partner_number'         => $memberNumber,
            'partner_kind'           => 'personal',
            'public_label'           => 'Partner',
            'internal_label'         => 'Member',
            'status'                 => 'pending',
            'wallet_status_snapshot' => 'invited',
            'entry_status'           => 'pending',
            'governance_status'      => 'pending',
            'stewardship_status'     => 'active',
            'created_at'             => $now,
            'updated_at'             => $now,
        ] as $col => $val) {
            if (isset($partnerCols[$col])) $partnerData[$col] = $val;
        }
        if ($partnerData) {
            $names = array_keys($partnerData);
            $marks = implode(',', array_fill(0, count($names), '?'));
            $db->prepare(
                'INSERT INTO partners ('
                . implode(',', array_map(fn($n) => "`{$n}`", $names))
                . ') VALUES (' . $marks . ')'
            )->execute(array_values($partnerData));
            $partnerId = (int)$db->lastInsertId();
        }
    }

    // ── Create partner_wallet_access row ─────────────────────────────────────
    if ($partnerId && trust_table_exists($db, 'partner_wallet_access')) {
        $pwaCols = trust_cols($db, 'partner_wallet_access');
        $pwaData = [];
        foreach ([
            'partner_id'    => $partnerId,
            'wallet_type'   => 'personal',
            'access_status' => 'invited',
            'created_at'    => $now,
            'updated_at'    => $now,
        ] as $col => $val) {
            if (isset($pwaCols[$col])) $pwaData[$col] = $val;
        }
        if ($pwaData) {
            $names = array_keys($pwaData);
            $marks = implode(',', array_fill(0, count($names), '?'));
            $db->prepare(
                'INSERT INTO partner_wallet_access ('
                . implode(',', array_map(fn($n) => "`{$n}`", $names))
                . ') VALUES (' . $marks . ')'
            )->execute(array_values($pwaData));
        }
    }

    // ── Record JVPA acceptance (Option A — before Stripe payment) ────────────
    $acceptanceHash = null;
    if ($partnerId) {
        try {
            $snftSeqNo = (int)($generatedMember['sequence_no'] ?? 0);
            $acceptanceHash = JvpaAcceptanceService::record(
                $db,
                $partnerId,
                $memberNumber,
                $memberId,
                $snftSeqNo,
                $acceptedIp,
                $acceptedUserAgent,
                'personal'
            );
        } catch (Throwable $jvpaEx) {
            // Non-fatal for registration — log prominently but do not roll back.
            // Admin must be alerted: if jvpa_versions is unseeded, registrations
            // will succeed but lack a proper acceptance record.
            error_log('[snft-reserve] JVPA acceptance record failed: ' . $jvpaEx->getMessage()
                . ' | member=' . $memberNumber . ' | partner_id=' . $partnerId);
        }
    }

    recordStewardshipAttestation($db, 'snft_member', $memberId, $memberNumber, $stewardshipModule);
    trust_log_activity($db, $memberId, null, 'stewardship_module_passed', [
        'pathway' => 'snft',
        'member_number' => $memberNumber,
        'score' => (int)$stewardshipModule['score'],
        'total_questions' => (int)$stewardshipModule['total_questions'],
        'completed_at' => $stewardshipModule['completed_at'],
        'attestation_hash' => $stewardshipModule['attestation_hash'],
    ]);

    $joiningFeeDueNow = round(
        ((int)($classRows['PERSONAL_SNFT']['unit_price_cents'] ?? 0)) / 100
        + ((int)$tokenInputs['KIDS_SNFT']) * (((int)($classRows['KIDS_SNFT']['unit_price_cents'] ?? 0)) / 100),
        2
    );

    enqueueReservationEmails($db, 'snft_member', $memberId, [
        'first_name' => $firstName,
    'last_name' => $lastName,
    'full_name' => $fullName,
        'email' => $email,
        'mobile' => $mobile,
        'date_of_birth' => $dob,
        'street_address' => $street,
        'suburb' => $suburb,
        'state' => $stateCode,
        'postcode' => $postcode,
        'referral_code' => $referralCode !== '' ? $referralCode : '',
        'additional_info' => $additionalInfo,
        'reservation_notice_version' => $noticeVersion,
        'reservation_notice_accepted_at' => $acceptedAt,
        'member_number' => $memberNumber,
        'support_code' => substr(generateWalletSupportCode('snft', $memberNumber, $email), 0, 4),
        'wallet_path' => 'wallets/member.html',
        'reservation_value' => $reservationValue ?? 0,
        'joining_fee_due_now' => '$' . number_format($joiningFeeDueNow, 2),
        'kids_tokens' => (int)$tokenInputs['KIDS_SNFT'],
        'investment_tokens' => (int)$tokenInputs['ASX_INVESTMENT_COG'],
        'pay_it_forward_tokens' => (int)$tokenInputs['PAY_IT_FORWARD_COG'],
        'donation_tokens' => (int)$tokenInputs['DONATION_COG'],
        'trace_line' => 'Trace: snft_member#' . $memberId . ' | ' . $memberNumber . ' | ' . $acceptedAt,
    ]);

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[snft-reserve] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    apiError('Registration could not be completed. Please try again or contact members@cogsaustralia.org if the problem continues.', 500);
}

processCrmQueue($db, 1);
processEmailQueue($db, 4);

$tokensTotal = array_sum($tokenInputs);
$joiningFeeDueNow = round((((int)($classRows['PERSONAL_SNFT']['unit_price_cents'] ?? 0)) / 100) + ((((int)$tokenInputs['KIDS_SNFT']) * ((int)($classRows['KIDS_SNFT']['unit_price_cents'] ?? 0))) / 100), 2);

apiSuccess([
    'member_number' => $memberNumber,
    'support_code' => substr(generateWalletSupportCode('snft', $memberNumber, $email), 0, 4),
    'wallet_path' => 'wallets/member.html',
    'wallet_status' => 'pending_setup',
    'wallet_mode' => 'setup',
    'first_name' => $firstName,
    'last_name' => $lastName,
    'full_name' => $fullName,
    'email' => $email,
    'mobile' => $mobile,
    'street' => $street,
    'suburb' => $suburb,
    'state' => $stateCode,
    'postcode' => $postcode,
    'joining_fee_due_now' => '$' . number_format($joiningFeeDueNow, 2),
    'reservation_value' => '$' . number_format($reservationValue, 2),
    'approved_reservation_value' => '$' . number_format(0, 2),
    'additional_reserved_value' => '$' . number_format($reservationValue, 2),
    'stewardship_status' => 'active',
    'stewardship_module_score' => (int)$stewardshipModule['score'],
    'stewardship_module_total_questions' => (int)$stewardshipModule['total_questions'],
    'stewardship_module_completed_at' => $stewardshipModule['completed_at'],
    'stewardship_attestation_hash' => $stewardshipModule['attestation_hash'],
    'tokens_total' => $tokensTotal,
    'reserved_tokens' => 1,
    'kids_tokens' => (int)$tokenInputs['KIDS_SNFT'],
    'landholder_tokens' => (int)$tokenInputs['LANDHOLDER_COG'],
    'investment_tokens' => (int)$tokenInputs['ASX_INVESTMENT_COG'],
    'pay_it_forward_tokens' => (int)$tokenInputs['PAY_IT_FORWARD_COG'],
    'donation_tokens' => (int)$tokenInputs['DONATION_COG'],
    'rwa_tokens' => (int)$tokenInputs['RWA_COG'],
    'lr_tokens' => (int)$tokenInputs['LR_COG'],
    'community_tokens' => 1000,
], 201);
