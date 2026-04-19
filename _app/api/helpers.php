<?php
declare(strict_types=1);

function apiSuccess($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(string $message, int $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}


// =============================================================================
// SMS OTP DELIVERY — Twilio
// Sends a 6-digit OTP to a mobile number via Twilio's REST API.
// Requires: TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER in .env
// Falls back gracefully — never throws to caller.
// Returns true if sent, false on any failure.
// =============================================================================

/**
 * Returns true if SMS delivery is configured and enabled.
 */
function smsEnabled(): bool {
    return SMS_PROVIDER === 'twilio'
        && TWILIO_ACCOUNT_SID !== ''
        && TWILIO_AUTH_TOKEN  !== ''
        && TWILIO_FROM_NUMBER !== '';
}

/**
 * Send a plain-text SMS via Twilio REST API.
 * Uses cURL. Throws RuntimeException on failure.
 */
function smsSend(string $to, string $message): void {
    if (!smsEnabled()) {
        throw new RuntimeException('SMS provider not configured.');
    }

    // Normalise to E.164 (+61xxxxxxxxx)
    $digits = preg_replace('/\D/', '', $to);
    if (substr($digits, 0, 1) === '0' && strlen($digits) === 10) {
        $digits = '61' . substr($digits, 1); // 04xx → +61 4xx
    }
    $toE164 = '+' . ltrim($digits, '+');

    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json';
    $body = http_build_query([
        'From' => TWILIO_FROM_NUMBER,
        'To'   => $toE164,
        'Body' => $message,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        throw new RuntimeException('SMS cURL error: ' . $curlErr);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $decoded = json_decode((string)$result, true);
        $detail  = is_array($decoded) ? ($decoded['message'] ?? (string)$result) : (string)$result;
        throw new RuntimeException('SMS API error (' . $httpCode . '): ' . $detail);
    }
}

/**
 * Send a vault sign-in OTP via SMS.
 * Returns true on success, false on any failure.
 */
function smsSendOtp(string $mobile, string $otp, string $memberName = 'Member'): bool {
    try {
        $msg = "COG$ Vault sign-in code: {$otp}\nExpires in 10 minutes. Do not share this code.\n— COG$ of Australia Foundation";
        smsSend($mobile, $msg);
        return true;
    } catch (Throwable $e) {
        error_log('[SMS] OTP send failed to ' . substr($mobile, 0, 4) . 'xxxx: ' . $e->getMessage());
        return false;
    }
}

function requireMethod(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        apiError('Method not allowed', 405);
    }
}

function sanitize($value): string {
    return trim((string)$value);
}

function validateEmail(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePostcode(string $postcode): bool {
    return (bool)preg_match('/^\d{4}$/', $postcode);
}

function stateCode(string $state): string {
    $value = strtoupper(trim($state));
    $allowed = ['NSW','VIC','QLD','WA','SA','TAS','ACT','NT'];
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException('State or territory is required.');
    }
    return $value;
}

function normalizeDigits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function normalizeMemberNumber(string $value): string {
    return substr(normalizeDigits($value), 0, 16);
}

function normalizeAbn(string $value): string {
    return substr(normalizeDigits($value), 0, 11);
}

function normalizePhone(string $value): string {
    $digits = normalizeDigits($value);
    if (substr($digits, 0, 2) === '61' && strlen($digits) === 11) {
        return '0' . substr($digits, 2);
    }
    return $digits;
}

function normalizeIdentityString(string $value): string {
    return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?? '';
}

function luhnCheckDigit(string $numberWithoutCheck): string {
    $sum = 0;
    $double = true;
    for ($i = strlen($numberWithoutCheck) - 1; $i >= 0; $i--) {
        $digit = (int)$numberWithoutCheck[$i];
        if ($double) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
        $double = !$double;
    }
    return (string)((10 - ($sum % 10)) % 10);
}

function generateSnftMemberNumber(PDO $db, string $state): array {
    $state = stateCode($state);

    // Use the member_number_sequence auto-increment table for race-safe generation.
    // MySQL AUTO_INCREMENT is atomic — two concurrent inserts always get distinct IDs,
    // eliminating the race condition that existed with SELECT MAX(sequence_no) + 1.
    try {
        $db->prepare('INSERT INTO member_number_sequence (created_at) VALUES (UTC_TIMESTAMP())')->execute();
        $seqId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        // Fallback if sequence table is missing: read MAX — less safe under concurrent
        // load but acceptable for single-request environments.
        $row   = $db->query('SELECT COALESCE(MAX(sequence_no), 0) + 1 AS next_seq FROM snft_memberships')->fetch();
        $seqId = (int)($row['next_seq'] ?? 1);
    }

    if ($seqId > 999999999) {
        throw new RuntimeException('SNFT member sequence exhausted.');
    }
    $prefix = str_pad(substr(SNFT_MEMBER_PREFIX, 0, 6), 6, '0', STR_PAD_RIGHT);
    $base   = $prefix . str_pad((string)$seqId, 9, '0', STR_PAD_LEFT);
    return [
        'sequence_no'   => $seqId,
        'member_number' => $base . luhnCheckDigit($base),
        'state_code'    => $state,
    ];
}


function supportCodeSecret(): string {
    return (string)(env('SUPPORT_CODE_SECRET', '') ?: env('ADMIN_BOOTSTRAP_TOKEN', '') ?: env('SMTP_PASSWORD', '') ?: 'cogs-support-code');
}

function generateWalletSupportCode(string $role, string $identifier, string $email = ''): string {
    $seed = strtolower(trim($role)) . '|' . normalizeDigits($identifier) . '|' . strtolower(trim($email));
    $hex = hash_hmac('sha256', $seed, supportCodeSecret());
    $digits = '';
    for ($i = 0; $i < 12; $i += 2) {
        $digits .= (string)(hexdec(substr($hex, $i, 2)) % 10);
    }
    return str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_LEFT);
}

function formatWalletSupportCode(string $code): string {
    $digits = substr(normalizeDigits($code), 0, 6);
    if ($digits === '') {
        return '--- ---';
    }
    return substr(str_pad($digits, 6, '0', STR_PAD_LEFT), 0, 3) . ' ' . substr(str_pad($digits, 6, '0', STR_PAD_LEFT), 3, 3);
}

function validateWalletSupportCode(string $provided, string $role, string $identifier, string $email = ''): bool {
    $digits = substr(normalizeDigits($provided), 0, 6);
    if (strlen($digits) !== 6) {
        return false;
    }
    return hash_equals(generateWalletSupportCode($role, $identifier, $email), $digits);
}

function jsonBody(): array {
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    $body = is_array($decoded) ? $decoded : [];
    return $body;
}

function cookieOptions(bool $remember = false): array {
    // Session cookie (expires=0) — browser closes, cookie dies, Partner must log in again.
    // Server-side session record still has its own expires_at in the DB as a hard ceiling
    // (SESSION_HOURS / SESSION_REMEMBER_DAYS control that). The $remember flag is preserved
    // for backward compatibility but no longer sets a persistent client cookie.
    return [
        'expires' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function clearSessionCookie(): void {
    setcookie(SESSION_COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function nowUtc(): string {
    return gmdate('Y-m-d H:i:s');
}

function getCorsOrigin(): string {
    $configured = CORS_ORIGIN;

    // Empty = same-origin only; no CORS header will be sent.
    if ($configured === '') {
        return '';
    }

    // Wildcard is kept for legacy/open-API contexts but MUST NOT be combined
    // with credentials (browsers reject that). The index.php header block only
    // sends Access-Control-Allow-Credentials when the origin is NOT a wildcard.
    if ($configured === '*') {
        return '*';
    }

    // Specific origin configured — reflect it only if the incoming Origin matches.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    return ($origin !== '' && strcasecmp($origin, $configured) === 0) ? $origin : '';
}

function recordWalletEvent(PDO $db, string $subjectType, string $subjectRef, string $eventType, ?string $description = null): void {
    $stmt = $db->prepare('INSERT INTO wallet_events (subject_type, subject_ref, event_type, description) VALUES (?,?,?,?)');
    $stmt->execute([$subjectType, $subjectRef, $eventType, $description]);
}

function queueCrmSync(PDO $db, string $entity, int $entityId, array $payload): int {
    $stmt = $db->prepare('INSERT INTO crm_sync_queue (sync_entity, entity_id, payload_json, status, attempts) VALUES (?,?,?,?,0)');
    $stmt->execute([$entity, $entityId, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'pending']);
    return (int)$db->lastInsertId();
}

function subjectTypeForUserType(string $userType): string {
    return $userType === 'snft' ? 'snft_member' : 'bnft_business';
}

function audienceForUserType(string $userType): array {
    // wallet_polls and vote_proposals use audience_scope ENUM ('all','personal','business',...)
    // NOT the legacy 'snft'/'bnft' strings.
    return $userType === 'snft' ? ['all', 'personal'] : ['all', 'business'];
}

function decodeJsonArray($value): array {
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function cleanOptions(array $options): array {
    $clean = [];
    foreach ($options as $option) {
        $value = trim((string)$option);
        if ($value !== '' && !in_array($value, $clean, true)) {
            $clean[] = $value;
        }
    }
    return array_values($clean);
}

function serialiseOptions(array $options): string {
    return json_encode(array_values($options), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
}

function validateAudience(string $audience, bool $allowBnft = true): string {
    $audience = strtolower(trim($audience));
    $allowed = $allowBnft ? ['all', 'snft', 'bnft'] : ['all', 'snft'];
    if (!in_array($audience, $allowed, true)) {
        apiError('Invalid audience supplied.');
    }
    return $audience;
}



function evaluateStewardshipModule(array $module, bool $requiresLandholderQuestion = false): array {
    $answers = $module['answers'] ?? [];
    if (!is_array($answers)) {
        apiError('A valid Stewardship Awareness Module response is required.');
    }
    $expected = [
        'q11' => 'agree_understand',
        'q12' => 'long_term_stewardship',
        'q21' => 'fifty_fifty_split',
        'q31' => 'veto_extraction',
        'q41' => 'acknowledge_risk',
    ];
    if ($requiresLandholderQuestion) {
        $expected['q32'] = 'landholder_weight_understood';
    }

    $informationalKeys = ['q51'];

    $normalisedAnswers = [];
    $score = 0;
    foreach ($expected as $key => $correct) {
        $value = sanitize((string)($answers[$key] ?? ''));
        if ($value === '') {
            apiError('Every required stewardship question must be answered before continuing.');
        }
        $normalisedAnswers[$key] = $value;
        if ($value === $correct) {
            $score++;
        }
    }

    foreach ($informationalKeys as $key) {
        $value = sanitize((string)($answers[$key] ?? ''));
        if ($value !== '') {
            $normalisedAnswers[$key] = $value;
        }
    }

    if (($normalisedAnswers['q11'] ?? '') === 'disagree_consultation') {
        apiError('This application is paused for a 1:1 consultation before reservation can proceed.');
    }
    $total = count($expected);
    $passed = $score === $total;
    if (!$passed) {
        apiError('The Stewardship Awareness Module must be passed with a full score before reservation can continue.');
    }
    $completedAtRaw = sanitize((string)($module['completed_at'] ?? ''));
    $completedAt = $completedAtRaw !== '' ? gmdate('Y-m-d H:i:s', strtotime($completedAtRaw) ?: time()) : nowUtc();
    $attestationSeed = [
        'module_name' => sanitize((string)($module['module_name'] ?? 'stewardship_awareness')),
        'answers' => $normalisedAnswers,
        'completed_at' => $completedAt,
        'requires_landholder_question' => $requiresLandholderQuestion,
        'informational_keys' => $informationalKeys,
    ];
    return [
        'module_name' => 'stewardship_awareness',
        'score' => $score,
        'total_questions' => $total,
        'passed' => true,
        'completed_at' => $completedAt,
        'answers' => $normalisedAnswers,
        'attestation_hash' => hash('sha256', json_encode($attestationSeed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ];
}

function recordStewardshipAttestation(PDO $db, string $subjectType, int $subjectId, string $walletRef, array $module): void {
    // stewardship_attestations table may not yet exist — wrapped to prevent join-form crash.
    try {
        $stmt = $db->prepare('INSERT INTO stewardship_attestations (subject_type, subject_id, wallet_ref, module_name, score, total_questions, answers_json, attestation_hash, completed_at) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $subjectType,
            $subjectId,
            $walletRef,
            $module['module_name'],
            (int)$module['score'],
            (int)$module['total_questions'],
            json_encode($module['answers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $module['attestation_hash'],
            $module['completed_at'],
        ]);
    } catch (Throwable $e) {
        error_log('recordStewardshipAttestation skipped (table missing?): ' . $e->getMessage());
    }
}

function parseMoneyAmount($raw, float $step, float $minimum, ?float $maximum = null): float {
    if ($raw === null || $raw === '') {
        apiError('A reservation amount is required.');
    }
    $value = (float)$raw;
    if ($value < $minimum) {
        apiError('Reservation amount is below the minimum allowed.');
    }
    if ($maximum !== null && $value > $maximum) {
        apiError('Reservation amount exceeds the maximum allowed.');
    }
    $cents = (int)round($value * 100);
    $stepCents = (int)round($step * 100);
    if ($stepCents > 0 && $cents % $stepCents !== 0) {
        apiError('Reservation amount must match the required increment.');
    }
    return round($cents / 100, 2);
}

function calculateSnftUnitsFromValue(float $reservationValue): int {
    return max(1, (int)round($reservationValue / tokenPriceAsDollars()));
}

// Returns TOKEN_PRICE in dollars regardless of whether the env stores it as cents (e.g. 400)
// or dollars (e.g. 4). Matches token_classes.unit_price_cents / 100 convention.
function tokenPriceAsDollars(): float {
    return TOKEN_PRICE >= 10 ? TOKEN_PRICE / 100 : (float)TOKEN_PRICE;
}
function kidsTokenPriceAsDollars(): float {
    return KIDS_TOKEN_PRICE >= 10 ? KIDS_TOKEN_PRICE / 100 : (float)KIDS_TOKEN_PRICE;
}

function normalizeTokenCount($value, int $minimum = 0, ?int $maximum = 1000000): int {
    $int = max($minimum, (int)$value);
    if ($maximum !== null) {
        $int = min($maximum, $int);
    }
    return $int;
}

function totalTokenUnits(int ...$values): int {
    return array_sum(array_map(static fn(int $v): int => max(0, $v), $values));
}

function calculateLandholderTokensFromHectares(float $hectares): int {
    $value = max(0, $hectares);
    if ($value <= 0) {
        return 0;
    }
    return (int) (ceil($value) * 1000);
}

function normalizeLandholderTokensForHectares($value, float $hectares): int {
    $max = calculateLandholderTokensFromHectares($hectares);
    if ($max <= 0) {
        return 0;
    }
    return normalizeTokenCount($value, 1, $max);
}

function calculateReservationValueFromTokenMix(int $reservedTokens, int $investmentTokens, int $donationTokens, int $payItForwardTokens, bool $includeBnftFee = false, int $kidsTokens = 0, int $landholderTokens = 0): float {
    if ($includeBnftFee) {
        // BNFT display value: $40 fixed membership fee + additional tokens at $4 each.
        // The actual purchase price for ASX/RWA tokens is $40 (business_unit_price_cents),
        // but the reservation/display value uses the standard $4 unit price.
        $additionalUnits = totalTokenUnits($investmentTokens, $donationTokens, $payItForwardTokens, $kidsTokens, $landholderTokens);
        return round(BNFT_FIXED_FEE + ($additionalUnits * tokenPriceAsDollars()), 2);
    }
    $totalTokens = totalTokenUnits($reservedTokens, $investmentTokens, $donationTokens, $payItForwardTokens, $kidsTokens, $landholderTokens);
    return round($totalTokens * tokenPriceAsDollars(), 2);
}

function calculateApprovedSnftTokenTotal(int $reservedTokens, int $kidsTokens): int {
    return max(0, $reservedTokens) + max(0, $kidsTokens);
}

function calculateApprovedSnftReservationValue(int $reservedTokens, int $kidsTokens): float {
    return round((max(0, $reservedTokens) * tokenPriceAsDollars()) + (max(0, $kidsTokens) * kidsTokenPriceAsDollars()), 2);
}

function calculateReservedClassValue(int $investmentTokens, int $donationTokens, int $payItForwardTokens, int $landholderTokens): float {
    $otherUnits = max(0, $investmentTokens) + max(0, $donationTokens) + max(0, $payItForwardTokens) + max(0, $landholderTokens);
    return round($otherUnits * tokenPriceAsDollars(), 2);
}

function calculateReservedClassTokenTotal(int $investmentTokens, int $donationTokens, int $payItForwardTokens, int $landholderTokens): int {
    return max(0, $investmentTokens) + max(0, $donationTokens) + max(0, $payItForwardTokens) + max(0, $landholderTokens);
}

/**
 * Calculate a member's vote weight: 1 (own vote) + number of active Kids S-NFT proxy votes.
 *
 * Proxy votes are counted from kids_token_registrations where:
 *   - status = 'issued'                  (token has been formally issued by admin)
 *   - proxy_vote_active = 1              (proxy not yet released by age-18 conversion)
 *   - conversion_due_date > CURDATE()    (child is still under 18)
 *
 * Declaration cl.11.1, cl.25.3: proxy vote held by guardian until child turns 18.
 * kids_token_registrations is the authoritative record — member_applications are
 * unverified vault submissions and must not confer governance weight.
 */
function calculateVoteWeight(PDO $db, int $memberId): int {
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM kids_token_registrations
             WHERE guardian_member_id = ?
               AND status = 'issued'
               AND proxy_vote_active = 1
               AND conversion_due_date > CURDATE()"
        );
        $stmt->execute([$memberId]);
        $proxyCount = (int)$stmt->fetchColumn();
        return 1 + $proxyCount;
    } catch (Throwable $e) {
        // Table may not exist yet — default to 1 (own vote only)
        return 1;
    }
}

/**
 * Fetch registered kids for a member (for vault display and form pre-fill).
 *
 * Source of truth priority:
 *   1. kids_token_registrations (status = verified/issued/converted) — authoritative post-issue
 *   2. member_applications (submitted)                               — pre-issue vault submissions
 *
 * This ensures the guardian's wallet reflects actual token state, not just
 * submission state, after admin has verified and issued the kS-NFT.
 * Declaration cl.11.1, cl.25.3 — proxy vote only active on issued tokens.
 */
function fetchRegisteredKids(PDO $db, int $memberId): array {
    try {
        // Step 1: fetch authoritative registry records (verified/issued/converted)
        $issued = [];
        try {
            $stmt = $db->prepare(
                "SELECT ktr.id, ktr.child_full_name, ktr.child_dob,
                        ktr.status AS token_status,
                        ktr.proxy_vote_active,
                        ktr.conversion_due_date AS converts_at,
                        CASE WHEN ktr.conversion_due_date IS NOT NULL
                                  AND ktr.conversion_due_date <= CURDATE()
                             THEN 1 ELSE 0 END AS is_adult
                 FROM kids_token_registrations ktr
                 WHERE ktr.guardian_member_id = ?
                   AND ktr.status IN ('verified','issued','converted')
                 ORDER BY ktr.child_dob ASC, ktr.id ASC"
            );
            $stmt->execute([$memberId]);
            foreach ($stmt->fetchAll() as $row) {
                $key = trim((string)$row['child_full_name']) . '|' . (string)($row['child_dob'] ?? '');
                $issued[$key] = [
                    'id'          => (int)$row['id'],
                    'name'        => (string)$row['child_full_name'],
                    'dob'         => (string)($row['child_dob'] ?? ''),
                    'status'      => (string)$row['token_status'],
                    'converts_at' => (string)($row['converts_at'] ?? ''),
                    'is_adult'    => (bool)$row['is_adult'],
                    'proxy_active'=> (bool)$row['proxy_vote_active'],
                    'source'      => 'registry',
                ];
            }
        } catch (Throwable $e) {
            // kids_token_registrations may not exist yet — continue to applications
        }

        // Step 2: fetch unprocessed vault submissions not yet in the registry
        $stmt = $db->prepare(
            "SELECT ma.id, ma.child_full_name, ma.child_dob, ma.application_status,
                    DATE_ADD(ma.child_dob, INTERVAL 18 YEAR) AS converts_at,
                    CASE WHEN ma.child_dob IS NOT NULL
                              AND DATE_ADD(ma.child_dob, INTERVAL 18 YEAR) <= CURDATE()
                         THEN 1 ELSE 0 END AS is_adult
             FROM member_applications ma
             WHERE ma.guardian_member_id = ?
               AND ma.application_type = 'kids_snft'
               AND ma.application_status NOT IN ('processed','rejected')
             ORDER BY ma.child_dob ASC, ma.id ASC"
        );
        $stmt->execute([$memberId]);

        $kids = array_values($issued);
        $issuedKeys = array_keys($issued);

        foreach ($stmt->fetchAll() as $row) {
            $key = trim((string)$row['child_full_name']) . '|' . (string)($row['child_dob'] ?? '');
            if (in_array($key, $issuedKeys, true)) {
                continue; // already represented by registry record
            }
            $kids[] = [
                'id'          => (int)$row['id'],
                'name'        => (string)$row['child_full_name'],
                'dob'         => (string)($row['child_dob'] ?? ''),
                'status'      => (string)$row['application_status'],
                'converts_at' => (string)($row['converts_at'] ?? ''),
                'is_adult'    => (bool)$row['is_adult'],
                'proxy_active'=> false,
                'source'      => 'application',
            ];
        }

        return $kids;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Generate a deterministic blockchain-style wallet address for a member + token class.
 * Format: COGS-{CLASS}-{12-char hex}
 * Uses HMAC-SHA256 with APP_SECRET so addresses are reproducible but not reversible.
 */
function generateWalletAddress(string $memberNumber, string $tokenClass): string {
    $secret = (string)env('APP_SECRET', 'cogs_vault_default_secret');
    $payload = $memberNumber . ':' . $tokenClass . ':' . $secret;
    $hash = hash_hmac('sha256', $payload, $secret);
    $prefixMap = [
        'investment_tokens' => 'ASX',
        'rwa_tokens'        => 'RWA',
        'community_tokens'  => 'CC',
        'landholder_tokens' => 'LH',
        'bus_prop_tokens'   => 'BP',
        'lr_tokens'         => 'LR',
    ];
    $prefix = $prefixMap[$tokenClass] ?? strtoupper(substr($tokenClass, 0, 3));
    // 12-char hex = 48 bits of entropy (sufficient for internal ledger addresses)
    return 'COGS-' . $prefix . '-' . strtolower(substr($hash, 0, 12));
}

/**
 * Get or create wallet addresses for a member (one per transferable class).
 * Stores in wallet_addresses table for reverse lookup.
 * Returns: ['rwa_tokens' => 'COGS-RWA-...', 'investment_tokens' => 'COGS-ASX-...']
 */
function getOrCreateWalletAddresses(PDO $db, string $memberNumber, int $memberId): array {
    $classes = ['investment_tokens', 'rwa_tokens', 'community_tokens'];
    $addresses = [];

    foreach ($classes as $cls) {
        $addr = generateWalletAddress($memberNumber, $cls);
        $addresses[$cls] = $addr;

        // Ensure stored in lookup table
        try {
            $db->prepare(
                "INSERT IGNORE INTO wallet_addresses (member_id, member_number, token_class, address, created_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$memberId, $memberNumber, $cls, $addr]);
        } catch (Throwable $e) {
            // Table may not exist yet — addresses still work, just no reverse lookup
        }
    }

    return $addresses;
}

/**
 * Resolve a wallet address to a member_number.
 * Returns member_number or null if not found.
 */
function resolveWalletAddress(PDO $db, string $address): ?array {
    try {
        $stmt = $db->prepare('SELECT member_id, member_number, token_class FROM wallet_addresses WHERE address = ? LIMIT 1');
        $stmt->execute([$address]);
        $row = $stmt->fetch();
        if ($row) return $row;
    } catch (Throwable $e) {}

    // Fallback: brute-force search across all members (slow but works without table)
    try {
        $stmt = $db->query('SELECT id, member_number FROM snft_memberships LIMIT 5000');
        foreach ($stmt->fetchAll() as $m) {
            foreach (['investment_tokens', 'rwa_tokens', 'community_tokens', 'landholder_tokens', 'lr_tokens'] as $cls) {
                if (generateWalletAddress((string)$m['member_number'], $cls) === $address) {
                    return ['member_id' => (int)$m['id'], 'member_number' => (string)$m['member_number'], 'token_class' => $cls];
                }
            }
        }
    } catch (Throwable $e) {}

    // Fallback: brute-force search across all businesses
    try {
        $stmt = $db->query('SELECT id, abn FROM bnft_memberships LIMIT 5000');
        foreach ($stmt->fetchAll() as $b) {
            foreach (['investment_tokens', 'rwa_tokens', 'community_tokens', 'bus_prop_tokens'] as $cls) {
                if (generateWalletAddress((string)$b['abn'], $cls) === $address) {
                    return ['member_id' => (int)$b['id'], 'member_number' => (string)$b['abn'], 'token_class' => $cls];
                }
            }
        }
    } catch (Throwable $e) {}

    return null;
}

function tokenBreakdownFromRow(array $row, string $userType): array {
    // Integer-only classes (S-NFT, kS-NFT, B-NFT, Donation, PIF, LR)
    $reserved     = (int)($row['reserved_tokens']      ?? 0);
    $donation     = (int)($row['donation_tokens']      ?? 0);
    $payItForward = (int)($row['pay_it_forward_tokens'] ?? 0);
    $kidsTokens   = $userType === 'snft' ? (int)($row['kids_tokens'] ?? $row['kids_count'] ?? 0) : 0;
    $lrTokens     = (int)($row['lr_tokens'] ?? 0); // address-bound, always integer

    // Decimal classes (ASX, RWA, Landholder, Business Property, Community)
    $investment      = (float)(($userType === 'bnft' ? ($row['invest_tokens'] ?? 0) : ($row['investment_tokens'] ?? 0)) ?? 0);
    $landholderHectares = (float)($row['landholder_hectares'] ?? $row['hectares_interest'] ?? 0);
    $landholderTokens   = (float)($row['landholder_tokens'] ?? calculateLandholderTokensFromHectares($landholderHectares));
    $rwaTokens       = (float)($row['rwa_tokens']       ?? 0);
    $communityTokens = (float)($row['community_tokens'] ?? 0);
    $busPropTokens   = (float)($row['bus_prop_tokens']  ?? 0);

    $total = isset($row['tokens_total'])
        ? (int)$row['tokens_total']
        : totalTokenUnits($reserved, (int)$investment, $donation, $payItForward, $kidsTokens, (int)$landholderTokens);
    return [
        'reserved_tokens'       => $reserved,
        'investment_tokens'     => $investment,
        'donation_tokens'       => $donation,
        'pay_it_forward_tokens' => $payItForward,
        'kids_tokens'           => $kidsTokens,
        'landholder_hectares'   => round($landholderHectares, 2),
        'landholder_tokens'     => $landholderTokens,
        'rwa_tokens'            => $rwaTokens,
        'lr_tokens'             => $lrTokens,
        'community_tokens'      => $communityTokens,
        'bus_prop_tokens'       => $busPropTokens,
        'total_tokens'          => $total,
    ];
}

function formatTokenBreakdownNote(array $breakdown): string {
    $parts = [];
    if ((int)($breakdown['reserved_tokens'] ?? 0) > 0) {
        $parts[] = 'Foundation S-NFT: ' . number_format((int)($breakdown['reserved_tokens'] ?? 0));
    }
    if ((float)($breakdown['investment_tokens'] ?? 0) > 0) {
        $parts[] = 'Investment COG$: ' . number_format((float)($breakdown['investment_tokens'] ?? 0), 4);
    }
    if ((int)($breakdown['donation_tokens'] ?? 0) > 0) {
        $parts[] = 'Donation COG$: ' . number_format((int)($breakdown['donation_tokens'] ?? 0));
    }
    if ((int)($breakdown['pay_it_forward_tokens'] ?? 0) > 0) {
        $parts[] = 'Pay It Forward COG$: ' . number_format((int)($breakdown['pay_it_forward_tokens'] ?? 0));
    }
    if ((int)($breakdown['kids_tokens'] ?? 0) > 0) {
        $parts[] = 'Kids S-NFT: ' . number_format((int)($breakdown['kids_tokens'] ?? 0));
    }
    if ((float)($breakdown['landholder_hectares'] ?? 0) > 0 || (float)($breakdown['landholder_tokens'] ?? 0) > 0) {
        $parts[] = 'Landholder COG$: ' . number_format((float)($breakdown['landholder_tokens'] ?? 0), 4)
            . ' (' . number_format((float)($breakdown['landholder_hectares'] ?? 0), 2) . ' ha)';
    }
    if ((float)($breakdown['rwa_tokens'] ?? 0) > 0) {
        $parts[] = 'RWA COG$: ' . number_format((float)($breakdown['rwa_tokens'] ?? 0), 4);
    }
    if ((float)($breakdown['bus_prop_tokens'] ?? 0) > 0) {
        $parts[] = 'Business Property COG$: ' . number_format((float)($breakdown['bus_prop_tokens'] ?? 0), 4);
    }
    if ((float)($breakdown['community_tokens'] ?? 0) > 0) {
        $parts[] = 'Community COG$: ' . number_format((float)($breakdown['community_tokens'] ?? 0), 4);
    }
    if ((int)($breakdown['lr_tokens'] ?? 0) > 0) {
        $parts[] = 'Local Resident COG$: ' . number_format((int)($breakdown['lr_tokens'] ?? 0));
    }
    return $parts ? implode(' · ', $parts) : 'No tokens';
}

function calculateBnftReservationValue(int $investTokens): float {
    return round(BNFT_FIXED_FEE + ($investTokens * BNFT_FIXED_FEE), 2);
}


function recordReservationUpdate(PDO $db, string $subjectType, string $subjectRef, int $previousUnits, int $newUnits, float $previousValue, float $newValue, ?string $note = null): void {
    // reservation_transactions has a NOT NULL subject_id — look it up from the correct membership table.
    try {
        $idTable = $subjectType === 'bnft_business' ? 'bnft_memberships' : 'snft_memberships';
        $idCol   = $subjectType === 'bnft_business' ? 'abn' : 'member_number';
        $idStmt  = $db->prepare("SELECT id FROM {$idTable} WHERE {$idCol} = ? LIMIT 1");
        $idStmt->execute([$subjectRef]);
        $idRow   = $idStmt->fetch();
        if (!$idRow) {
            // Subject not found — cannot record transaction without a valid subject_id (NOT NULL).
            error_log('recordReservationUpdate skipped: no matching ' . $idTable . ' row for ' . $subjectRef);
            return;
        }
        $subjectId = (int)$idRow['id'];

        // All values as ? — avoids PDO emulated-prepare misparse of literal strings in VALUES.
        $stmt = $db->prepare(
            'INSERT INTO reservation_transactions
                (subject_type, subject_id, subject_ref, action_type, source_context,
                 total_units_before, total_units_after, total_units_delta,
                 total_value_before, total_value_after, total_value_delta,
                 note, actor_type, created_at)
             VALUES (?,?,?,?,?, ?,?,?, ?,?,?, ?,?,UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $subjectType,
            $subjectId,
            $subjectRef,
            'wallet_update',
            'wallet',
            $previousUnits,
            $newUnits,
            $newUnits - $previousUnits,
            round($previousValue, 2),
            round($newValue, 2),
            round($newValue - $previousValue, 2),
            $note,
            'member',
        ]);
    } catch (Throwable $e) {
        // Non-fatal — log but never block the reservation update itself.
        error_log('recordReservationUpdate failed: ' . $e->getMessage());
    }
}

function reservationSnapshotFromBreakdown(array $breakdown): array {
    return [
        'reserved_tokens' => (int)($breakdown['reserved_tokens'] ?? 0),
        'investment_tokens' => (int)($breakdown['investment_tokens'] ?? 0),
        'donation_tokens' => (int)($breakdown['donation_tokens'] ?? 0),
        'pay_it_forward_tokens' => (int)($breakdown['pay_it_forward_tokens'] ?? 0),
        'kids_tokens' => (int)($breakdown['kids_tokens'] ?? 0),
        'landholder_hectares' => round((float)($breakdown['landholder_hectares'] ?? 0), 2),
        'landholder_tokens' => (int)($breakdown['landholder_tokens'] ?? 0),
        'total_tokens' => (int)($breakdown['total_tokens'] ?? totalTokenUnits(
            (int)($breakdown['reserved_tokens'] ?? 0),
            (int)($breakdown['investment_tokens'] ?? 0),
            (int)($breakdown['donation_tokens'] ?? 0),
            (int)($breakdown['pay_it_forward_tokens'] ?? 0),
            (int)($breakdown['kids_tokens'] ?? 0),
            (int)($breakdown['landholder_tokens'] ?? 0)
        )),
    ];
}

function recordReservationTransaction(PDO $db, string $subjectType, int $subjectId, string $subjectRef, array $before, array $after, float $beforeValue, float $afterValue, ?string $note = null, string $actionType = 'wallet_update', string $sourceContext = 'wallet', string $actorType = 'system', ?string $actorRef = null, array $metadata = []): void {
    $before = reservationSnapshotFromBreakdown($before);
    $after = reservationSnapshotFromBreakdown($after);
    $stmt = $db->prepare('INSERT INTO reservation_transactions (
        subject_type, subject_id, subject_ref, action_type, source_context,
        total_units_before, total_units_after, total_units_delta,
        total_value_before, total_value_after, total_value_delta,
        reserved_before, reserved_after, reserved_delta,
        investment_before, investment_after, investment_delta,
        donation_before, donation_after, donation_delta,
        pay_it_forward_before, pay_it_forward_after, pay_it_forward_delta,
        kids_before, kids_after, kids_delta,
        landholder_hectares_before, landholder_hectares_after, landholder_hectares_delta,
        landholder_before, landholder_after, landholder_delta,
        note, actor_type, actor_ref, metadata_json
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $subjectType,
        $subjectId,
        $subjectRef,
        $actionType,
        $sourceContext,
        $before['total_tokens'],
        $after['total_tokens'],
        $after['total_tokens'] - $before['total_tokens'],
        round($beforeValue, 2),
        round($afterValue, 2),
        round($afterValue - $beforeValue, 2),
        $before['reserved_tokens'],
        $after['reserved_tokens'],
        $after['reserved_tokens'] - $before['reserved_tokens'],
        $before['investment_tokens'],
        $after['investment_tokens'],
        $after['investment_tokens'] - $before['investment_tokens'],
        $before['donation_tokens'],
        $after['donation_tokens'],
        $after['donation_tokens'] - $before['donation_tokens'],
        $before['pay_it_forward_tokens'],
        $after['pay_it_forward_tokens'],
        $after['pay_it_forward_tokens'] - $before['pay_it_forward_tokens'],
        $before['kids_tokens'],
        $after['kids_tokens'],
        $after['kids_tokens'] - $before['kids_tokens'],
        $before['landholder_hectares'],
        $after['landholder_hectares'],
        round($after['landholder_hectares'] - $before['landholder_hectares'], 2),
        $before['landholder_tokens'],
        $after['landholder_tokens'],
        $after['landholder_tokens'] - $before['landholder_tokens'],
        $note,
        $actorType,
        $actorRef,
        $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function fetchReservationTransactions(PDO $db, string $subjectType, string $subjectRef, int $limit = 20): array {
    $stmt = $db->prepare('SELECT action_type, source_context, total_units_before, total_units_after, total_units_delta, total_value_before, total_value_after, total_value_delta, reserved_before, reserved_after, reserved_delta, investment_before, investment_after, investment_delta, donation_before, donation_after, donation_delta, pay_it_forward_before, pay_it_forward_after, pay_it_forward_delta, kids_before, kids_after, kids_delta, landholder_hectares_before, landholder_hectares_after, landholder_hectares_delta, landholder_before, landholder_after, landholder_delta, note, actor_type, actor_ref, created_at FROM reservation_transactions WHERE subject_type = ? AND subject_ref = ? ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $subjectType);
    $stmt->bindValue(2, $subjectRef);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function requireAnyUserType(array $allowedTypes): array {
    $principal = getAuthPrincipal();
    if (!$principal || !in_array((string)$principal['user_type'], $allowedTypes, true)) {
        apiError('Authentication required', 401);
    }
    return $principal;
}

function logRecoveryAttempt(PDO $db, string $requestType, string $roleName, string $authChannel, string $identifierValue, string $contactValue, string $outcome, ?string $subjectRef = null): void {
    try {
        $stmt = $db->prepare('INSERT INTO auth_recovery_requests (request_type, role_name, auth_channel, identifier_value, contact_value, outcome, subject_ref) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$requestType, $roleName, $authChannel, $identifierValue !== '' ? $identifierValue : null, $contactValue, $outcome, $subjectRef]);
    } catch (Throwable $e) {
        // Do not block auth flows if the audit table has not yet been created.
    }
}

function base32Alphabet(): string {
    return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
}

function base32Encode(string $binary): string {
    if ($binary === '') {
        return '';
    }
    $alphabet = base32Alphabet();
    $bits = '';
    for ($i = 0; $i < strlen($binary); $i++) {
        $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function base32Decode(string $base32): string {
    $alphabet = array_flip(str_split(base32Alphabet()));
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32));
    $bits = '';
    for ($i = 0; $i < strlen($clean); $i++) {
        $char = $clean[$i];
        if (!isset($alphabet[$char])) {
            continue;
        }
        $bits .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $output .= chr(bindec(substr($bits, $i, 8)));
    }
    return $output;
}

function generateBase32Secret(int $bytes = 20): string {
    return base32Encode(random_bytes($bytes));
}

function hotpValue(string $secret, int $counter, int $digits = 6): string {
    $key = base32Decode($secret);
    $binCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $chunk = substr($hash, $offset, 4);
    $value = unpack('N', $chunk)[1] & 0x7FFFFFFF;
    $mod = 10 ** $digits;
    return str_pad((string)($value % $mod), $digits, '0', STR_PAD_LEFT);
}

function verifyTotpCode(string $secret, string $code, int $window = 1, int $digits = 6): bool {
    $clean = preg_replace('/\D+/', '', $code);
    if ($clean === null || strlen($clean) !== $digits) {
        return false;
    }
    $timeSlice = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(hotpValue($secret, $timeSlice + $i, $digits), $clean)) {
            return true;
        }
    }
    return false;
}

function formatOtpauthUrl(string $issuer, string $label, string $secret): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

// =============================================================================
// Session guard functions
// These were called throughout routes but were never defined in the zip.
// getAuthPrincipal() reads the session cookie and validates it against the
// sessions table. requireAdminRole() and requireAuth() build on top of it.
// =============================================================================

/**
 * Read the session cookie and return the matching unexpired sessions row,
 * or null if there is no valid session.
 */
function getAuthPrincipal(): ?array {
    $sessionId = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if ($sessionId === '') {
        return null;
    }
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT id, user_type, principal_id, subject_ref,
                    COALESCE(otp_verified, 1) AS otp_verified
               FROM sessions
              WHERE id = ?
                AND expires_at > UTC_TIMESTAMP()
              LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Require an authenticated admin session.
 * Optionally restrict to specific role_name values (e.g. ['superadmin']).
 * Returns the admin_users row on success; calls apiError(401/403) on failure.
 */
function requireAdminRole(array $allowedRoles = []): array {
    $principal = getAuthPrincipal();
    if (!$principal || (string)($principal['user_type'] ?? '') !== 'admin') {
        apiError('Admin authentication required.', 401);
    }
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT id, username, email, display_name, role_name, is_active
               FROM admin_users
              WHERE id = ?
                AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([(int)$principal['principal_id']]);
        $admin = $stmt->fetch();
    } catch (Throwable $e) {
        apiError('Admin session lookup failed.', 500);
    }
    if (!$admin) {
        apiError('Admin account not found or inactive.', 401);
    }
    if ($allowedRoles && !in_array((string)$admin['role_name'], $allowedRoles, true)) {
        apiError('Insufficient admin privileges for this action.', 403);
    }
    // Attach session metadata for callers that need it.
    $admin['principal_id'] = $principal['principal_id'];
    $admin['subject_ref']  = $principal['subject_ref'];
    return $admin;
}

/**
 * Require an authenticated member session of a specific user_type.
 * e.g. requireAuth('snft') or requireAuth('bnft')
 * Returns the sessions row on success; calls apiError(401) on failure.
 */
function requireAuth(string $userType): array {
    $principal = getAuthPrincipal();
    if (!$principal || (string)($principal['user_type'] ?? '') !== $userType) {
        apiError('Authentication required.', 401);
    }
    // Enforce 2FA: member sessions require OTP verification
    // Admin sessions use TOTP handled separately; otp_verified defaults to 1 for admins
    if ($userType !== 'admin' && !(bool)($principal['otp_verified'] ?? true)) {
        apiError('Two-factor verification required.', 401);
    }
    return $principal;
}

// =============================================================================
// Rate limiting
// Protects login, password reset, and admin login against brute force.
// Uses the auth_rate_limits table (see database/2026_04_02_rate_limits.sql).
// All three functions fail open — a DB error never blocks a legitimate login.
// =============================================================================

/**
 * Return the best available client IP, respecting common proxy headers.
 */
function getClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        $val = (string)($_SERVER[$h] ?? '');
        if ($val !== '') {
            return trim(explode(',', $val)[0]);
        }
    }
    return '0.0.0.0';
}

/**
 * Check whether the current IP is rate-limited for $action.
 * Calls apiError(429) and exits if the IP is locked out.
 *
 * @param PDO    $db          Database connection
 * @param string $action      One of: login | setup-password | reset-password | admin-login
 * @param int    $maxAttempts Maximum failures before lockout  (default 5)
 * @param int    $windowSecs  Sliding window in seconds        (default 900 = 15 min)
 * @param int    $lockSecs    Lockout duration in seconds      (default 900 = 15 min)
 */
function enforceRateLimit(PDO $db, string $action, int $maxAttempts = 5, int $windowSecs = 900, int $lockSecs = 900): void {
    try {
        $key  = hash('sha256', getClientIp() . '|' . $action);
        $stmt = $db->prepare('SELECT attempts, window_start, locked_until FROM auth_rate_limits WHERE limit_key = ? AND action = ? LIMIT 1');
        $stmt->execute([$key, $action]);
        $row  = $stmt->fetch();
        if (!$row) {
            return; // No record — first attempt, allow through.
        }

        // Active lockout?
        if ($row['locked_until'] !== null) {
            $until = strtotime($row['locked_until'] . ' UTC');
            if (time() < $until) {
                $mins = max(1, (int)ceil(($until - time()) / 60));
                apiError('Too many failed attempts. Try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.', 429);
            }
            // Lockout expired — clean up and allow through.
            $db->prepare('DELETE FROM auth_rate_limits WHERE limit_key = ? AND action = ?')->execute([$key, $action]);
            return;
        }

        // Within sliding window?
        $windowExpires = strtotime($row['window_start'] . ' UTC') + $windowSecs;
        if (time() > $windowExpires) {
            // Window expired — clean up and allow through.
            $db->prepare('DELETE FROM auth_rate_limits WHERE limit_key = ? AND action = ?')->execute([$key, $action]);
            return;
        }

        // Window active — check attempt count.
        if ((int)$row['attempts'] >= $maxAttempts) {
            $lockedUntil = gmdate('Y-m-d H:i:s', time() + $lockSecs);
            $db->prepare('UPDATE auth_rate_limits SET locked_until = ? WHERE limit_key = ? AND action = ?')->execute([$lockedUntil, $key, $action]);
            $mins = max(1, (int)ceil($lockSecs / 60));
            apiError('Too many failed attempts. Try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.', 429);
        }
    } catch (Throwable $e) {
        // Fail open — a DB error must never lock out a legitimate user.
    }
}

/**
 * Increment the failure counter for the current IP + action.
 * Call this after each failed credential check.
 */
function recordAuthFailure(PDO $db, string $action): void {
    try {
        $key = hash('sha256', getClientIp() . '|' . $action);
        $now = gmdate('Y-m-d H:i:s');
        $db->prepare(
            'INSERT INTO auth_rate_limits (limit_key, action, attempts, window_start)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE attempts = attempts + 1'
        )->execute([$key, $action, $now]);
    } catch (Throwable $e) {
        // Fail open.
    }
}

/**
 * Clear the rate-limit record for the current IP + action on a successful login.
 * Resets the counter so a legitimate user who previously failed isn't penalised.
 */
function clearAuthRateLimit(PDO $db, string $action): void {
    try {
        $key = hash('sha256', getClientIp() . '|' . $action);
        $db->prepare('DELETE FROM auth_rate_limits WHERE limit_key = ? AND action = ?')->execute([$key, $action]);
    } catch (Throwable $e) {
        // Fail open.
    }
}


function api_view_exists(PDO $db, string $view): bool {
    try {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.views WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
        $stmt->execute([$view]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function api_fetch_foundation_asx_holdings(PDO $db): array {
    if (api_view_exists($db, 'v_foundation_asx_holdings_live')) {
        try {
            $rows = $db->query("SELECT asx_code, company_name, shares_held, average_price_per_share_cents, total_book_value_cents, cogs_backed, cogs_minted, cogs_available_to_back FROM v_foundation_asx_holdings_live ORDER BY asx_code ASC, company_name ASC")->fetchAll();
            return array_map(static function (array $row): array {
                return [
                    'asx_code' => (string)($row['asx_code'] ?? ''),
                    'company_name' => (string)($row['company_name'] ?? ''),
                    'shares_held' => (int)round((float)($row['shares_held'] ?? 0)),
                    'average_price_per_share_cents' => (float)($row['average_price_per_share_cents'] ?? 0),
                    'average_price_per_share' => round(((float)($row['average_price_per_share_cents'] ?? 0)) / 100, 4),
                    'total_book_value_cents' => (float)($row['total_book_value_cents'] ?? 0),
                    'total_book_value' => round(((float)($row['total_book_value_cents'] ?? 0)) / 100, 4),
                    'cogs_backed' => (int)floor((float)($row['cogs_backed'] ?? 0)),
                    'cogs_minted' => (int)floor((float)($row['cogs_minted'] ?? 0)),
                    'cogs_available_to_back' => (int)floor((float)($row['cogs_available_to_back'] ?? 0)),
                ];
            }, $rows ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }

    if (!api_table_exists($db, 'asx_holdings')) {
        return [];
    }

    try {
        $rows = $db->query("SELECT ticker AS asx_code, company_name, units_held AS shares_held, average_cost_cents AS average_price_per_share_cents, total_cost_cents AS total_book_value_cents FROM asx_holdings ORDER BY ticker ASC, company_name ASC")->fetchAll();
        return array_map(static function (array $row): array {
            return [
                'asx_code' => (string)($row['asx_code'] ?? ''),
                'company_name' => (string)($row['company_name'] ?? ''),
                'shares_held' => (int)round((float)($row['shares_held'] ?? 0)),
                'average_price_per_share_cents' => (float)($row['average_price_per_share_cents'] ?? 0),
                'average_price_per_share' => round(((float)($row['average_price_per_share_cents'] ?? 0)) / 100, 4),
                'total_book_value_cents' => (float)($row['total_book_value_cents'] ?? 0),
                'total_book_value' => round(((float)($row['total_book_value_cents'] ?? 0)) / 100, 4),
                'cogs_backed' => 0,
                'cogs_minted' => 0,
                'cogs_available_to_back' => (int)floor(((float)($row['total_book_value_cents'] ?? 0)) / 400),
            ];
        }, $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function api_fetch_foundation_rwa_holdings(PDO $db): array {
    if (api_view_exists($db, 'v_foundation_rwa_assets_live')) {
        try {
            $rows = $db->query("SELECT asset_code, pool_name, asset_type, verified_valuation_cents, cogs_backed, cogs_minted, cogs_available_to_back, location_summary, valuation_basis, valuation_date FROM v_foundation_rwa_assets_live ORDER BY asset_code ASC, pool_name ASC")->fetchAll();
            return array_map(static function (array $row): array {
                return [
                    'asset_code' => (string)($row['asset_code'] ?? ''),
                    'pool_name' => (string)($row['pool_name'] ?? ''),
                    'asset_type' => (string)($row['asset_type'] ?? ''),
                    'verified_valuation_cents' => (int)($row['verified_valuation_cents'] ?? 0),
                    'verified_valuation' => round(((int)($row['verified_valuation_cents'] ?? 0)) / 100, 2),
                    'cogs_backed' => (int)floor((float)($row['cogs_backed'] ?? 0)),
                    'cogs_minted' => (int)floor((float)($row['cogs_minted'] ?? 0)),
                    'cogs_available_to_back' => (int)floor((float)($row['cogs_available_to_back'] ?? 0)),
                    'location_summary' => (string)($row['location_summary'] ?? ''),
                    'valuation_basis' => (string)($row['valuation_basis'] ?? ''),
                    'valuation_date' => (string)($row['valuation_date'] ?? ''),
                ];
            }, $rows ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }

    if (!api_table_exists($db, 'rwa_asset_register')) {
        return [];
    }

    try {
        $rows = $db->query("SELECT COALESCE(asset_code, asset_key) AS asset_code, asset_name AS pool_name, asset_type, location_summary FROM rwa_asset_register WHERE status <> 'retired' ORDER BY COALESCE(asset_code, asset_key) ASC, asset_name ASC")->fetchAll();
        return array_map(static function (array $row): array {
            return [
                'asset_code' => (string)($row['asset_code'] ?? ''),
                'pool_name' => (string)($row['pool_name'] ?? ''),
                'asset_type' => (string)($row['asset_type'] ?? ''),
                'verified_valuation_cents' => 0,
                'verified_valuation' => 0.0,
                'cogs_backed' => 0,
                'cogs_minted' => 0,
                'cogs_available_to_back' => 0,
                'location_summary' => (string)($row['location_summary'] ?? ''),
                'valuation_basis' => '',
                'valuation_date' => '',
            ];
        }, $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function api_fetch_foundation_community_cog_totals(PDO $db): array {
    $minted = 0;
    $circulation = 0;

    // Minted total — sum of all COM_COG allocation records
    try {
        if (api_table_exists($db, 'community_cog_allocations')) {
            $stmt = $db->query("SELECT COALESCE(SUM(units),0) FROM community_cog_allocations WHERE token_class_code = 'COM_COG'");
            $minted = (float)($stmt->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        $minted = 0;
    }

    // Circulation — sum from snft_memberships and bnft_memberships (the authoritative token columns)
    try {
        if (api_table_exists($db, 'snft_memberships') && api_column_exists($db, 'snft_memberships', 'community_tokens')) {
            $stmt = $db->query("SELECT COALESCE(SUM(community_tokens),0) FROM snft_memberships");
            $circulation += (float)($stmt->fetchColumn() ?: 0);
        }
        if (api_table_exists($db, 'bnft_memberships') && api_column_exists($db, 'bnft_memberships', 'community_tokens')) {
            $stmt = $db->query("SELECT COALESCE(SUM(community_tokens),0) FROM bnft_memberships");
            $circulation += (float)($stmt->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        $circulation = 0;
    }

    return [
        'community_cogs_minted' => $minted,
        'community_cogs_circulation' => $circulation,
    ];
}

function api_fetch_foundation_live_assets(PDO $db): array {
    $community = api_fetch_foundation_community_cog_totals($db);
    return [
        'asx_holdings' => api_fetch_foundation_asx_holdings($db),
        'rwa_holdings' => api_fetch_foundation_rwa_holdings($db),
        'community_cogs_minted' => (int)($community['community_cogs_minted'] ?? 0),
        'community_cogs_circulation' => (int)($community['community_cogs_circulation'] ?? 0),
    ];
}

function api_table_exists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function api_column_exists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}


function api_token_class_id(PDO $db, string $classCode): int {
    try {
        $stmt = $db->prepare('SELECT id FROM token_classes WHERE class_code = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$classCode]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function api_record_community_cog_allocation(PDO $db, string $subjectType, int $subjectId, string $subjectRef, int $units, string $sourceAction = 'join_seed', string $programKey = 'opening_seed_cc'): bool {
    if (!api_table_exists($db, 'community_cog_allocations')) {
        return false;
    }
    try {
        $check = $db->prepare('SELECT id FROM community_cog_allocations WHERE program_key = ? AND subject_type = ? AND subject_ref = ? LIMIT 1');
        $check->execute([$programKey, $subjectType, $subjectRef]);
        if ($check->fetchColumn()) {
            return false;
        }
        $stmt = $db->prepare(
            'INSERT INTO community_cog_allocations (program_key, source_action, token_class_code, subject_type, subject_id, subject_ref, units, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $programKey,
            $sourceAction,
            'COM_COG',
            $subjectType,
            $subjectId,
            $subjectRef,
            $units,
            'Opening Community COG$ allocation seed',
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[community-cog] allocation audit insert failed: ' . $e->getMessage());
        return false;
    }
}

function api_seed_personal_community_cog(PDO $db, int $memberId, int $snftId, string $memberNumber, int $units, string $sourceAction = 'join_seed'): bool {
    $insertedAudit = api_record_community_cog_allocation($db, 'snft_member', $snftId, $memberNumber, $units, $sourceAction);

    if (api_column_exists($db, 'snft_memberships', 'community_tokens')) {
        try {
            $stmt = $db->prepare('UPDATE snft_memberships SET community_tokens = GREATEST(COALESCE(community_tokens, 0), ?), updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$units, $snftId]);
        } catch (Throwable $e) {
            error_log('[community-cog] snft_memberships sync failed: ' . $e->getMessage());
        }
    }

    if (api_table_exists($db, 'member_reservation_lines')) {
        $tokenClassId = api_token_class_id($db, 'COM_COG');
        if ($tokenClassId > 0) {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO member_reservation_lines (member_id, token_class_id, requested_units, approved_units, paid_units, approval_status, payment_status, approved_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'approved', 'not_required', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE requested_units = GREATEST(requested_units, VALUES(requested_units)), approved_units = GREATEST(approved_units, VALUES(approved_units)), paid_units = GREATEST(paid_units, VALUES(paid_units)), approval_status = 'approved', payment_status = 'not_required', approved_at = COALESCE(approved_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP()"
                );
                $stmt->execute([$memberId, $tokenClassId, $units, $units, $units]);
            } catch (Throwable $e) {
                error_log('[community-cog] member_reservation_lines sync failed: ' . $e->getMessage());
            }
        }
    }

    return $insertedAudit;
}

function api_seed_business_community_cog(PDO $db, int $businessId, string $abn, int $units, string $sourceAction = 'join_seed'): bool {
    $insertedAudit = api_record_community_cog_allocation($db, 'bnft_business', $businessId, $abn, $units, $sourceAction);
    if (api_column_exists($db, 'bnft_memberships', 'community_tokens')) {
        try {
            $stmt = $db->prepare('UPDATE bnft_memberships SET community_tokens = GREATEST(COALESCE(community_tokens, 0), ?), updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$units, $businessId]);
        } catch (Throwable $e) {
            error_log('[community-cog] bnft_memberships sync failed: ' . $e->getMessage());
        }
    }
    return $insertedAudit;
}
