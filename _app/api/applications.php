<?php
declare(strict_types=1);

/**
 * Application intake backend
 * Backend-only pipeline for public join forms.
 *
 * Supported application types:
 * - personal
 * - kids_snft
 * - business
 * - landholder
 * - donation
 * - pay_it_forward
 *
 * Behaviour:
 * - creates intake record
 * - creates active member wallet immediately
 * - creates pending reservation/approval workflow
 * - does NOT auto-approve tokens
 */

function applications_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function applications_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $candidates = [
        dirname(__DIR__) . '/config/bootstrap.php',
        dirname(__DIR__) . '/config/app.php',
        dirname(__DIR__) . '/config/database.php',
        dirname(__DIR__, 2) . '/config/app.php',
        dirname(__DIR__, 2) . '/config/database.php',
        dirname(__DIR__, 2) . '/../config/database.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            if (function_exists('getDB')) {
                $pdo = getDB();
                if ($pdo instanceof PDO) {
                    return $pdo;
                }
            }
            if (isset($config) && is_array($config)) {
                $host = $config['db_host'] ?? $config['host'] ?? null;
                $dbname = $config['db_name'] ?? $config['dbname'] ?? null;
                $user = $config['db_user'] ?? $config['user'] ?? null;
                $pass = $config['db_pass'] ?? $config['pass'] ?? null;
                if ($host && $dbname && $user !== null) {
                    $pdo = new PDO(
                        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                        (string)$user,
                        (string)$pass,
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        ]
                    );
                    return $pdo;
                }
            }
        }
    }

    throw new RuntimeException('Database configuration not found.');
}

function applications_now(): string
{
    return date('Y-m-d H:i:s');
}

function applications_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function applications_col_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}


function applications_is_public_snft_join_payload(array $data): bool
{
    // A public join payload arrives with reservation_notice_accepted (or tokens array)
    // and does NOT include application_type (the join form never sends it).
    // We detect it by the presence of join-form-specific fields.
    $hasTokens = isset($data['tokens']) && is_array($data['tokens']);
    $hasNotice = !empty($data['reservation_notice_accepted']) || !empty($data['reservation_notice_version']);
    $hasMobile = isset($data['mobile']) && (string)$data['mobile'] !== '';
    $hasStreet = isset($data['street']) && (string)$data['street'] !== '';
    $hasNoAppType = !isset($data['application_type']) || $data['application_type'] === '' || $data['application_type'] === 'personal';

    return $hasNoAppType && ($hasTokens || $hasNotice || ($hasMobile && $hasStreet));
}

function applications_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return $_POST ?: [];
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    return $_POST ?: [];
}

function applications_generate_temp_password(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function applications_generate_personal_member_number(PDO $pdo): string
{
    do {
        $candidate = 'M-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM members WHERE member_number = ?');
        $stmt->execute([$candidate]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $candidate;
}

function applications_fetch_token_class(PDO $pdo, array $codes): ?array
{
    if (!$codes || !applications_table_exists($pdo, 'token_classes')) {
        return null;
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $pdo->prepare("SELECT * FROM token_classes WHERE UPPER(class_code) IN ($placeholders) ORDER BY is_active DESC, display_order ASC, id ASC LIMIT 1");
    $stmt->execute(array_map('strtoupper', $codes));
    $row = $stmt->fetch();
    return $row ?: null;
}

function applications_determine_class(PDO $pdo, string $applicationType): ?array
{
    return match ($applicationType) {
        'personal' => applications_fetch_token_class($pdo, ['PERSONAL_SNFT', 'SNFT', 'PERSONAL']),
        'kids_snft' => applications_fetch_token_class($pdo, ['KIDS_SNFT', 'KIDS', 'CHILD_SNFT']),
        'business' => applications_fetch_token_class($pdo, ['BUSINESS_BNFT', 'BNFT', 'BUSINESS']),
        'landholder' => applications_fetch_token_class($pdo, ['LANDHOLDER_COG', 'LANDHOLDER']),
        'donation' => applications_fetch_token_class($pdo, ['DONATION_COG', 'DONATION']),
        'pay_it_forward' => applications_fetch_token_class($pdo, ['PAY_IT_FORWARD', 'PAYITFORWARD']),
        default => null,
    };
}

function applications_pending_payment_status(string $applicationType): string
{
    return match ($applicationType) {
        'personal', 'kids_snft', 'business' => 'pending',
        default => 'not_required',
    };
}

function applications_pending_approval_status(string $applicationType): string
{
    return match ($applicationType) {
        'donation', 'pay_it_forward' => 'pending',
        default => 'pending',
    };
}

function applications_kyc_status(string $applicationType): string
{
    return match ($applicationType) {
        'business' => 'pending_abn_review',
        'kids_snft' => 'pending_guardian_review',
        'landholder' => 'pending_landholder_review',
        default => 'pending',
    };
}

function applications_validate_payload(array $data): array
{
    $type = strtolower(trim((string)($data['application_type'] ?? 'personal')));
    $allowed = ['personal', 'kids_snft', 'business', 'landholder', 'donation', 'pay_it_forward'];
    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported application_type.');
    }

    $fullName = trim((string)($data['full_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($fullName === '') {
        throw new InvalidArgumentException('full_name is required.');
    }
    if ($email === '') {
        throw new InvalidArgumentException('email is required.');
    }

    $normalized = [
        'application_type' => $type,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'password' => $password,
        'abn' => preg_replace('/\D+/', '', (string)($data['abn'] ?? '')),
        'notes' => trim((string)($data['notes'] ?? '')),
        'source_page' => trim((string)($data['source_page'] ?? 'website')),
        'stewardship_awareness_completed' => !empty($data['stewardship_awareness_completed']) ? 1 : 0,
        'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : [],
        'guardian_member_id' => (int)($data['guardian_member_id'] ?? 0),
        'guardian_full_name' => trim((string)($data['guardian_full_name'] ?? '')),
        'guardian_email' => trim((string)($data['guardian_email'] ?? '')),
        'guardian_phone' => trim((string)($data['guardian_phone'] ?? '')),
        'child_full_name' => trim((string)($data['child_full_name'] ?? '')),
        'child_dob' => trim((string)($data['child_dob'] ?? '')),
        'guardian_relationship' => trim((string)($data['guardian_relationship'] ?? '')),
        'guardian_authority_confirmed' => !empty($data['guardian_authority_confirmed']) ? 1 : 0,
    ];

    if ($type === 'business' && $normalized['abn'] === '') {
        throw new InvalidArgumentException('abn is required for business applications.');
    }

    if ($type === 'kids_snft') {
        if ($normalized['child_full_name'] === '') {
            throw new InvalidArgumentException('child_full_name is required for kids_snft.');
        }
        if ($normalized['guardian_full_name'] === '' && $normalized['guardian_member_id'] <= 0) {
            throw new InvalidArgumentException('guardian_full_name or guardian_member_id is required for kids_snft.');
        }
        if ($normalized['guardian_authority_confirmed'] !== 1) {
            throw new InvalidArgumentException('guardian_authority_confirmed is required for kids_snft.');
        }
    }

    return $normalized;
}

function applications_insert_application(PDO $pdo, array $payload, int $memberId, ?int $tokenClassId): int
{
    if (!applications_table_exists($pdo, 'member_applications')) {
        throw new RuntimeException('member_applications table is missing.');
    }

    $meta = $payload['meta'];
    if ($payload['application_type'] === 'kids_snft') {
        $meta = array_merge($meta, [
            'guardian_member_id' => $payload['guardian_member_id'],
            'guardian_full_name' => $payload['guardian_full_name'],
            'guardian_email' => $payload['guardian_email'],
            'guardian_phone' => $payload['guardian_phone'],
            'child_full_name' => $payload['child_full_name'],
            'child_dob' => $payload['child_dob'],
            'guardian_relationship' => $payload['guardian_relationship'],
            'guardian_authority_confirmed' => $payload['guardian_authority_confirmed'],
        ]);
    }

    $fields = [
        'application_type', 'member_id', 'token_class_id', 'full_name', 'email',
        'phone', 'abn', 'application_status', 'source_page',
        'stewardship_awareness_completed', 'notes', 'meta_json', 'created_at', 'updated_at'
    ];
    $values = [
        $payload['application_type'], $memberId, $tokenClassId, $payload['full_name'], $payload['email'],
        $payload['phone'] ?: null, $payload['abn'] ?: null, 'submitted', $payload['source_page'] ?: 'website',
        $payload['stewardship_awareness_completed'], $payload['notes'] ?: null, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        applications_now(), applications_now()
    ];

    if (applications_col_exists($pdo, 'member_applications', 'guardian_member_id')) {
        $fields[] = 'guardian_member_id';
        $values[] = $payload['guardian_member_id'] ?: null;
    }
    if (applications_col_exists($pdo, 'member_applications', 'guardian_full_name')) {
        $fields[] = 'guardian_full_name';
        $values[] = $payload['guardian_full_name'] ?: null;
    }
    if (applications_col_exists($pdo, 'member_applications', 'guardian_email')) {
        $fields[] = 'guardian_email';
        $values[] = $payload['guardian_email'] ?: null;
    }
    if (applications_col_exists($pdo, 'member_applications', 'child_full_name')) {
        $fields[] = 'child_full_name';
        $values[] = $payload['child_full_name'] ?: null;
    }
    if (applications_col_exists($pdo, 'member_applications', 'child_dob')) {
        $fields[] = 'child_dob';
        $values[] = $payload['child_dob'] ?: null;
    }
    if (applications_col_exists($pdo, 'member_applications', 'guardian_relationship')) {
        $fields[] = 'guardian_relationship';
        $values[] = $payload['guardian_relationship'] ?: null;
    }
    if (applications_col_exists($pdo, 'member_applications', 'guardian_authority_confirmed')) {
        $fields[] = 'guardian_authority_confirmed';
        $values[] = $payload['guardian_authority_confirmed'];
    }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO member_applications (' . implode(',', $fields) . ') VALUES (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function applications_create_member(PDO $pdo, array $payload): array
{
    $existing = $pdo->prepare('SELECT * FROM members WHERE email = ? ORDER BY id DESC LIMIT 1');
    $existing->execute([$payload['email']]);
    $member = $existing->fetch();
    if ($member) {
        return [$member, null];
    }

    $tempPassword = null;
    $passwordHash = '';
    if ($payload['password'] !== '') {
        $passwordHash = password_hash($payload['password'], PASSWORD_DEFAULT);
    } else {
        $tempPassword = applications_generate_temp_password();
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
    }

    $memberType = $payload['application_type'] === 'business' ? 'business' : 'personal';
    $memberNumber = $memberType === 'business'
        ? $payload['abn']
        : applications_generate_personal_member_number($pdo);

    $meta = [];
    if ($payload['application_type'] === 'kids_snft') {
        $meta['kids_snft'] = [
            'guardian_member_id' => $payload['guardian_member_id'],
            'guardian_full_name' => $payload['guardian_full_name'],
            'guardian_email' => $payload['guardian_email'],
            'guardian_phone' => $payload['guardian_phone'],
            'child_full_name' => $payload['child_full_name'],
            'child_dob' => $payload['child_dob'],
            'guardian_relationship' => $payload['guardian_relationship'],
            'guardian_authority_confirmed' => $payload['guardian_authority_confirmed'],
            'proxy_governance_status' => 'pending',
            'conversion_status' => 'pending_age_18_conversion',
        ];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO members
        (member_number, abn, member_type, full_name, email, phone, password_hash, wallet_status, signup_payment_status, is_active, meta_json, created_at, updated_at, password_set_at, kyc_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $memberNumber,
        $payload['abn'] ?: null,
        $memberType,
        $payload['full_name'],
        $payload['email'],
        $payload['phone'] ?: null,
        $passwordHash,
        'active',
        applications_pending_payment_status($payload['application_type']),
        1,
        $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        applications_now(),
        applications_now(),
        $payload['password'] !== '' ? applications_now() : null,
        applications_kyc_status($payload['application_type']),
    ]);

    $memberId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    return [$stmt->fetch(), $tempPassword];
}

function applications_create_reservation_and_approval(PDO $pdo, array $payload, int $memberId, array $tokenClass): array
{
    $tokenClassId = (int)$tokenClass['id'];
    $requestedUnits = 1;

    $stmt = $pdo->prepare('SELECT * FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ? LIMIT 1');
    $stmt->execute([$memberId, $tokenClassId]);
    $line = $stmt->fetch();

    if (!$line) {
        $pdo->prepare(
            'INSERT INTO member_reservation_lines
            (member_id, token_class_id, requested_units, approved_units, paid_units, payment_status, approval_status, created_at, updated_at)
            VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            $tokenClassId,
            $requestedUnits,
            applications_pending_payment_status($payload['application_type']),
            applications_pending_approval_status($payload['application_type']),
            applications_now(),
            applications_now(),
        ]);
    }

    $approvalId = null;
    if (applications_table_exists($pdo, 'approval_requests')) {
        $stmt = $pdo->prepare('SELECT id FROM approval_requests WHERE member_id = ? AND token_class_id = ? AND request_status = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$memberId, $tokenClassId, 'pending']);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $approvalId = (int)$existing;
        } else {
            $requestType = match ($payload['application_type']) {
                'business' => 'manual_approval',
                'kids_snft' => 'manual_approval',
                default => 'signup_payment',
            };
            $requestedValue = (int)($requestedUnits * ((int)($tokenClass['unit_price_cents'] ?? 0)));

            $pdo->prepare(
                'INSERT INTO approval_requests
                (member_id, token_class_id, request_type, requested_units, requested_value_cents, request_status, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $memberId,
                $tokenClassId,
                $requestType,
                $requestedUnits,
                $requestedValue,
                'pending',
                'Created from application intake backend.',
                applications_now(),
                applications_now(),
            ]);
            $approvalId = (int)$pdo->lastInsertId();
        }
    }

    return [$tokenClassId, $approvalId];
}

function applications_log_email_event(PDO $pdo, int $memberId, string $eventType, string $recipientEmail, string $subjectLine, string $details): void
{
    if (!applications_table_exists($pdo, 'email_access_log')) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO email_access_log
        (member_id, admin_id, event_type, recipient_email, subject_line, event_details, created_at)
        VALUES (?, NULL, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $memberId,
        $eventType,
        $recipientEmail ?: null,
        $subjectLine ?: null,
        $details ?: null,
        applications_now(),
    ]);
}


/**
 * Forward a public join form payload to the snft-reserve route internally.
 * Called when applications.php receives a payload that belongs to snft-reserve.
 * This prevents a 409 if a client hits applications.php directly (e.g. retry loop,
 * misconfigured proxy, or stale cached URL).
 */
function applications_forward_to_snft_reserve(array $rawPayload): void
{
    // Locate the snft-reserve route file relative to this file's directory
    $candidates = [
        __DIR__ . '/routes/snft-reserve.php',
        dirname(__DIR__) . '/routes/snft-reserve.php',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            // Bootstrap the shared helpers the route expects (getDB, apiError, etc.)
            // These are loaded by index.php normally; load them here if not already loaded.
            $configCandidates = [
                __DIR__ . '/config/bootstrap.php',
                __DIR__ . '/config/database.php',
                dirname(__DIR__, 2) . '/config/database.php',
            ];
            foreach ($configCandidates as $cfg) {
                if (is_file($cfg) && !function_exists('getDB')) {
                    require_once $cfg;
                    break;
                }
            }
            $helpers = __DIR__ . '/helpers.php';
            if (is_file($helpers) && !function_exists('apiError')) {
                require_once $helpers;
            }

            // Expose the raw payload as the JSON body the route will read via jsonBody()
            // The route calls jsonBody() which reads php://input — we override it by
            // putting the decoded array into a well-known global the helpers check first.
            if (!isset($GLOBALS['_applications_forwarded_body'])) {
                $GLOBALS['_applications_forwarded_body'] = $rawPayload;
            }

            // Patch jsonBody() if it hasn't been defined yet, so the route reads our data
            if (!function_exists('jsonBody')) {
                function jsonBody(): array {
                    return $GLOBALS['_applications_forwarded_body'] ?? [];
                }
            }

            require $candidate;
            exit; // snft-reserve.php calls apiSuccess/apiError which exit; belt-and-suspenders
        }
    }

    // Route file not found — return a clear error rather than a confusing 409
    applications_json_response([
        'ok'      => false,
        'success' => false,
        'message' => 'Registration handler not found. Please try again or contact support.',
        '_debug'  => 'snft-reserve route file missing from expected locations',
    ], 503);
}

function applications_submit(array $rawPayload): void
{
    // If the payload looks like a public join form submission, forward it internally
    // to the snft-reserve route rather than rejecting it. This makes applications.php
    // safe as a fallback endpoint even if the client hits it directly.
    if (applications_is_public_snft_join_payload($rawPayload)) {
        applications_forward_to_snft_reserve($rawPayload);
        return; // forward exits via applications_json_response; return is safety only
    }

    $pdo = applications_db();
    $payload = applications_validate_payload($rawPayload);
    $tokenClass = applications_determine_class($pdo, $payload['application_type']);
    if (!$tokenClass) {
        throw new RuntimeException('Matching token class not found for application_type.');
    }

    $pdo->beginTransaction();
    try {
        [$member, $tempPassword] = applications_create_member($pdo, $payload);
        $memberId = (int)$member['id'];

        [$tokenClassId, $approvalId] = applications_create_reservation_and_approval($pdo, $payload, $memberId, $tokenClass);
        $applicationId = applications_insert_application($pdo, $payload, $memberId, $tokenClassId);

        applications_log_email_event(
            $pdo,
            $memberId,
            'access_setup',
            $payload['email'],
            'Vault access active',
            'Application submitted and wallet access activated. Token approval remains pending.'
        );

        $pdo->commit();

        applications_json_response([
            'ok' => true,
            'application_id' => $applicationId,
            'member_id' => $memberId,
            'member_number' => (string)$member['member_number'],
            'wallet_status' => (string)$member['wallet_status'],
            'kyc_status' => (string)($member['kyc_status'] ?? applications_kyc_status($payload['application_type'])),
            'application_type' => $payload['application_type'],
            'token_class_id' => $tokenClassId,
            'approval_request_id' => $approvalId,
            'approval_state' => 'pending',
            'temporary_password' => $tempPassword,
            'kids_snft' => $payload['application_type'] === 'kids_snft' ? [
                'child_full_name' => $payload['child_full_name'],
                'guardian_member_id' => $payload['guardian_member_id'] ?: null,
                'guardian_full_name' => $payload['guardian_full_name'] ?: null,
                'proxy_governance_status' => 'pending',
            ] : null,
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function applications_status(string $email): void
{
    $pdo = applications_db();
    if ($email === '') {
        throw new InvalidArgumentException('email is required.');
    }

    $stmt = $pdo->prepare(
        'SELECT ma.*, m.member_number, m.wallet_status, m.kyc_status
         FROM member_applications ma
         LEFT JOIN members m ON m.id = ma.member_id
         WHERE ma.email = ?
         ORDER BY ma.id DESC
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        applications_json_response(['ok' => false, 'message' => 'Application not found.'], 404);
    }

    $meta = [];
    if (!empty($row['meta_json'])) {
        $decoded = json_decode((string)$row['meta_json'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    applications_json_response([
        'ok' => true,
        'application_id' => (int)$row['id'],
        'member_id' => (int)($row['member_id'] ?? 0),
        'member_number' => (string)($row['member_number'] ?? ''),
        'application_type' => (string)$row['application_type'],
        'application_status' => (string)$row['application_status'],
        'wallet_status' => (string)($row['wallet_status'] ?? ''),
        'kyc_status' => (string)($row['kyc_status'] ?? ''),
        'stewardship_awareness_completed' => (int)($row['stewardship_awareness_completed'] ?? 0),
        'meta' => $meta,
    ]);
}

function applications_dispatch(): void
{
    try {
        $action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));
        if ($action === '') {
            $action = $_SERVER['REQUEST_METHOD'] === 'GET' ? 'status' : 'submit';
        }

        if ($action === 'submit') {
            applications_submit(applications_read_json_body());
        }

        if ($action === 'status') {
            $email = trim((string)($_GET['email'] ?? $_POST['email'] ?? ''));
            applications_status($email);
        }

        applications_json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
    } catch (Throwable $e) {
        applications_json_response([
            'ok' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}
