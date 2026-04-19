<?php
declare(strict_types=1);

/**
 * Partner Invitation Pathway — API Route
 *
 * Handles all invitation code operations for the CJVM join flow.
 * Sub-actions dispatched via $id (the path segment after 'invitations/').
 *
 * Routes:
 *   POST  invitations/validate  — validate a code before join form submission
 *   POST  invitations/create    — admin: issue a new code to an active Partner
 *   POST  invitations/revoke    — admin: immediately revoke a code
 *   GET   invitations/report    — admin: referral conversion report
 *
 * All public-facing messages use Partner / partnership language per the
 * Website JV Alignment Brief. Internal DB columns retain legacy names.
 *
 * Data model (tables created by migration — see handover notes):
 *   partner_invite_codes  — master code registry
 *   partner_invitations   — per-invitation event log
 *
 * Reason codes returned by validate:
 *   valid | missing | invalid | expired | revoked |
 *   inactive_inviter | wrong_entry_type | override_approved | error
 */

$db     = getDB();
$action = trim((string)($id ?? ''), '/');

// ── Helper: check whether an invitation table exists ──────────────────────────
function inv_table_exists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// ── Helper: get columns of a table ───────────────────────────────────────────
function inv_cols(PDO $db, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[$row['Field']] = true;
        }
        return $cache[$table] = $cols;
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

// ── Helper: safe insert using only columns that exist ────────────────────────
function inv_insert(PDO $db, string $table, array $data): ?int {
    $cols  = inv_cols($db, $table);
    $row   = array_filter($data, fn($k) => isset($cols[$k]), ARRAY_FILTER_USE_KEY);
    if (!$row) return null;
    $names = array_keys($row);
    $marks = implode(',', array_fill(0, count($names), '?'));
    $sql   = 'INSERT INTO `' . $table . '` ('
           . implode(',', array_map(fn($n) => "`{$n}`", $names))
           . ') VALUES (' . $marks . ')';
    $db->prepare($sql)->execute(array_values($row));
    return (int)$db->lastInsertId() ?: null;
}

// ── Helper: increment use_count safely ───────────────────────────────────────
function inv_increment_use(PDO $db, int $codeId): void {
    try {
        $db->prepare(
            'UPDATE partner_invite_codes SET use_count = use_count + 1, updated_at = NOW()
             WHERE id = ?'
        )->execute([$codeId]);
    } catch (Throwable $e) {
        // Non-fatal — log and continue
        error_log('[invitations] use_count increment failed: ' . $e->getMessage());
    }
}

// ── Helper: generate a short human-readable public code ──────────────────────
function inv_generate_public_code(PDO $db, string $prefix = ''): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // omit O,0,I,1 for readability
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) $suffix .= $chars[random_int(0, strlen($chars) - 1)];
        $candidate = $prefix !== '' ? strtoupper($prefix) . '-' . $suffix : $suffix;
        // Ensure uniqueness
        $stmt = $db->prepare('SELECT COUNT(*) FROM partner_invite_codes WHERE public_code = ?');
        $stmt->execute([$candidate]);
        if ((int)$stmt->fetchColumn() === 0) return $candidate;
    }
    // Fallback: uuid-fragment (extremely unlikely to collide)
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
}

// ── Helper: generate a long non-guessable invite token ───────────────────────
function inv_generate_token(): string {
    return bin2hex(random_bytes(24)); // 48-char hex
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: validate
// POST invitations/validate
// Body: { code: string, entry_type: 'personal'|'business' }
// Public endpoint — no auth required (rate-limit at nginx/WAF level)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'validate') {
    requireMethod('POST');
    $body      = jsonBody();
    $rawCode   = strtoupper(trim(sanitize($body['code'] ?? '')));
    $entryType = strtolower(trim(sanitize($body['entry_type'] ?? 'personal')));

    if ($rawCode === '') {
        apiSuccess([
            'valid'   => false,
            'reason'  => 'missing',
            'message' => 'A valid Partner invitation code is required to join the partnership.',
        ]);
    }

    // Tables must exist — if not, fail open with an error reason so the
    // front-end can display the "temporarily unavailable" message.
    if (!inv_table_exists($db, 'partner_invite_codes')) {
        error_log('[invitations/validate] partner_invite_codes table missing');
        apiSuccess([
            'valid'   => false,
            'reason'  => 'error',
            'message' => 'Invitation verification is temporarily unavailable. Please try again or contact administration.',
        ]);
    }

    try {
        // Accept either the public_code or the invite_token in the same field
        $stmt = $db->prepare(
            'SELECT pic.*, m.first_name, m.last_name, m.is_active AS partner_active,
                    m.member_number AS inviter_member_number
             FROM partner_invite_codes pic
             LEFT JOIN members m ON m.id = pic.inviter_partner_id
             WHERE pic.public_code = ? OR pic.invite_token = ?
             LIMIT 1'
        );
        $stmt->execute([$rawCode, $rawCode]);
        $code = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$code) {
            apiSuccess([
                'valid'   => false,
                'reason'  => 'invalid',
                'message' => 'This invitation code could not be verified. Please check the code, use the original invitation link, or contact administration.',
            ]);
        }

        // Rule 1 — code must be active
        if (($code['status'] ?? '') !== 'active') {
            $reason = ($code['status'] === 'revoked') ? 'revoked' : 'invalid';
            apiSuccess([
                'valid'   => false,
                'reason'  => $reason,
                'message' => $reason === 'revoked'
                    ? 'This invitation code has been revoked. Please contact the Partner who invited you or administration.'
                    : 'This invitation code is no longer active.',
            ]);
        }

        // Rule 2 — inviting Partner must be active
        if (empty($code['partner_active'])) {
            apiSuccess([
                'valid'   => false,
                'reason'  => 'inactive_inviter',
                'message' => 'The Partner who issued this code is not currently active. Please contact administration.',
            ]);
        }

        // Rule 3 — not expired
        if (!empty($code['expires_at'])) {
            $expiresTs = strtotime((string)$code['expires_at']);
            if ($expiresTs !== false && $expiresTs < time()) {
                apiSuccess([
                    'valid'   => false,
                    'reason'  => 'expired',
                    'message' => 'This invitation code has expired. Please request a new one from the Partner who invited you.',
                ]);
            }
        }

        // Rule 4 — use cap not exceeded
        $maxUses = isset($code['max_uses']) ? (int)$code['max_uses'] : 0;
        $useCount = (int)($code['use_count'] ?? 0);
        if ($maxUses > 0 && $useCount >= $maxUses) {
            apiSuccess([
                'valid'   => false,
                'reason'  => 'exhausted',
                'message' => 'This invitation code has reached its usage limit. Please contact the Partner who invited you for a new code.',
            ]);
        }

        // Rule 5 — entry type permitted (if code restricts type)
        $allowedType = strtolower(trim((string)($code['allowed_entry_type'] ?? '')));
        if ($allowedType !== '' && $allowedType !== 'both' && $allowedType !== $entryType) {
            apiSuccess([
                'valid'   => false,
                'reason'  => 'wrong_entry_type',
                'message' => 'This invitation code is not valid for this type of partnership entry. Please contact the Partner who invited you.',
            ]);
        }

        // All rules passed — return inviter details
        $inviterName = trim(
            ($code['first_name'] ?? '') . ' ' . ($code['last_name'] ?? '')
        );
        if ($inviterName === '') {
            $inviterName = 'Partner ' . ($code['inviter_member_number'] ?? '');
        }

        apiSuccess([
            'valid'              => true,
            'reason'             => 'valid',
            'inviter_name'       => $inviterName,
            'inviter_partner_id' => (int)$code['inviter_partner_id'],
            'invite_code_id'     => (int)$code['id'],
            'public_code'        => (string)$code['public_code'],
        ]);

    } catch (Throwable $e) {
        error_log('[invitations/validate] ' . $e->getMessage());
        apiSuccess([
            'valid'   => false,
            'reason'  => 'error',
            'message' => 'Invitation verification is temporarily unavailable. Please try again or contact administration.',
        ]);
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// ACTION: create
// POST invitations/create   — Admin authenticated
// Body: { inviter_partner_id: int, prefix?: string, max_uses?: int,
//         expires_at?: string, allowed_entry_type?: 'personal'|'business'|'both',
//         notes?: string }
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'create') {
    requireMethod('POST');
    $admin = requireAdmin();  // calls apiError(401/403) if not authenticated

    $body             = jsonBody();
    $inviterPartnerId = (int)($body['inviter_partner_id'] ?? 0);
    $prefix           = strtoupper(trim(sanitize($body['prefix'] ?? '')));
    $maxUses          = max(0, (int)($body['max_uses'] ?? 0));
    $expiresAt        = sanitize($body['expires_at'] ?? '');
    $allowedType      = strtolower(sanitize($body['allowed_entry_type'] ?? 'both'));
    $notes            = sanitize($body['notes'] ?? '');

    if ($inviterPartnerId <= 0) apiError('A valid inviter_partner_id is required.');
    if (!in_array($allowedType, ['personal','business','both'], true)) {
        apiError('allowed_entry_type must be personal, business, or both.');
    }

    // Verify the inviting Partner exists and is active
    try {
        $stmt = $db->prepare('SELECT id, member_number, first_name, last_name, is_active FROM members WHERE id = ? LIMIT 1');
        $stmt->execute([$inviterPartnerId]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        apiError('Could not verify inviter Partner record.', 500);
    }

    if (!$partner) apiError('Inviting Partner not found.');
    if (empty($partner['is_active'])) apiError('Inviting Partner account is not active. Only active Partners may issue invitation codes.');

    if (!inv_table_exists($db, 'partner_invite_codes')) {
        apiError('Invitation codes table has not been created. Please run the migration.', 500);
    }

    try {
        $publicCode  = inv_generate_public_code($db, $prefix);
        $inviteToken = inv_generate_token();
        $now         = date('Y-m-d H:i:s');
        $expiresAtVal = ($expiresAt !== '' && strtotime($expiresAt) !== false)
            ? date('Y-m-d H:i:s', strtotime($expiresAt))
            : null;

        $codeId = inv_insert($db, 'partner_invite_codes', [
            'inviter_partner_id'   => $inviterPartnerId,
            'public_code'          => $publicCode,
            'invite_token'         => $inviteToken,
            'status'               => 'active',
            'allowed_entry_type'   => $allowedType,
            'max_uses'             => $maxUses > 0 ? $maxUses : null,
            'use_count'            => 0,
            'expires_at'           => $expiresAtVal,
            'notes'                => $notes !== '' ? $notes : null,
            'created_by_admin_id'  => (int)($admin['id'] ?? 0),
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        if (!$codeId) apiError('Code could not be created. Check that the migration has been run.', 500);

        // Build the shareable invitation link
        $baseUrl = defined('SITE_BASE_URL') ? rtrim((string)SITE_BASE_URL, '/') : '';
        $inviteLink = $baseUrl . '/join/?invite=' . urlencode($inviteToken);

        apiSuccess([
            'id'                   => $codeId,
            'public_code'          => $publicCode,
            'invite_token'         => $inviteToken,
            'invite_link'          => $inviteLink,
            'inviter_partner_id'   => $inviterPartnerId,
            'inviter_member_number'=> (string)($partner['member_number'] ?? ''),
            'inviter_name'         => trim(($partner['first_name'] ?? '') . ' ' . ($partner['last_name'] ?? '')),
            'status'               => 'active',
            'allowed_entry_type'   => $allowedType,
            'max_uses'             => $maxUses > 0 ? $maxUses : null,
            'expires_at'           => $expiresAtVal,
            'created_at'           => $now,
        ], 201);

    } catch (Throwable $e) {
        error_log('[invitations/create] ' . $e->getMessage());
        apiError('Code could not be created: ' . $e->getMessage(), 500);
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// ACTION: revoke
// POST invitations/revoke   — Admin authenticated
// Body: { code_id?: int, public_code?: string }
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'revoke') {
    requireMethod('POST');
    $admin  = requireAdmin();
    $body   = jsonBody();
    $codeId = (int)($body['code_id'] ?? 0);
    $pubCode = strtoupper(trim(sanitize($body['public_code'] ?? '')));

    if ($codeId <= 0 && $pubCode === '') {
        apiError('Provide either code_id or public_code to revoke.');
    }
    if (!inv_table_exists($db, 'partner_invite_codes')) {
        apiError('Invitation codes table has not been created.', 500);
    }

    try {
        if ($codeId > 0) {
            $stmt = $db->prepare('SELECT id, status FROM partner_invite_codes WHERE id = ? LIMIT 1');
            $stmt->execute([$codeId]);
        } else {
            $stmt = $db->prepare('SELECT id, status FROM partner_invite_codes WHERE public_code = ? LIMIT 1');
            $stmt->execute([$pubCode]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) apiError('Invitation code not found.');
        if ($row['status'] === 'revoked') apiError('This code is already revoked.');

        $db->prepare(
            'UPDATE partner_invite_codes
             SET status = ?, revoked_at = NOW(), revoked_by_admin_id = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute(['revoked', (int)($admin['id'] ?? 0), (int)$row['id']]);

        apiSuccess([
            'id'         => (int)$row['id'],
            'status'     => 'revoked',
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);

    } catch (Throwable $e) {
        error_log('[invitations/revoke] ' . $e->getMessage());
        apiError('Revocation failed: ' . $e->getMessage(), 500);
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// ACTION: report
// GET invitations/report    — Admin authenticated
// Query params: inviter_partner_id (optional), limit (default 100), offset (0)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'report') {
    requireMethod('GET');
    $admin           = requireAdmin();
    $filterPartnerId = (int)($_GET['inviter_partner_id'] ?? 0);
    $limit           = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $offset          = max(0, (int)($_GET['offset'] ?? 0));

    if (!inv_table_exists($db, 'partner_invitations')) {
        apiSuccess(['rows' => [], 'total' => 0, 'note' => 'partner_invitations table not yet created.']);
    }

    try {
        $where  = $filterPartnerId > 0 ? 'WHERE pi.inviter_partner_id = ?' : '';
        $params = $filterPartnerId > 0 ? [$filterPartnerId] : [];

        $countStmt = $db->prepare("SELECT COUNT(*) FROM partner_invitations pi {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $rowStmt = $db->prepare("
            SELECT
                pi.id,
                pi.entry_type,
                pi.inviter_partner_id,
                CONCAT(im.first_name, ' ', im.last_name) AS inviter_name,
                im.member_number                          AS inviter_member_number,
                pi.invite_code_id,
                pic.public_code,
                pi.invitee_email_nullable                 AS invitee_email,
                pi.accepted_at,
                pi.accepted_partner_id,
                CONCAT(am.first_name, ' ', am.last_name)  AS accepted_partner_name,
                am.member_number                          AS accepted_member_number,
                pi.invite_link_sent_at,
                CASE WHEN pi.accepted_at IS NOT NULL THEN 'converted' ELSE 'pending' END AS conversion_status
            FROM partner_invitations pi
            LEFT JOIN members im  ON im.id  = pi.inviter_partner_id
            LEFT JOIN members am  ON am.id  = pi.accepted_partner_id
            LEFT JOIN partner_invite_codes pic ON pic.id = pi.invite_code_id
            {$where}
            ORDER BY pi.id DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $rowStmt->execute($params);
        $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

        apiSuccess([
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
            'rows'   => $rows,
        ]);

    } catch (Throwable $e) {
        error_log('[invitations/report] ' . $e->getMessage());
        apiError('Report query failed: ' . $e->getMessage(), 500);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: my-code
// GET invitations/my-code
// Partner-authenticated — returns the calling Partner's active invite code
// and shareable link. No admin auth required. Creates no new code — codes
// are seeded by the SQL migration or created by admin via the create action.
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'my-code') {
    requireMethod('GET');
    $principal = requireAuth('snft');

    $memberId = (int)($principal['principal_id'] ?? 0);
    if ($memberId <= 0) apiError('Could not resolve Partner identity.', 401);

    if (!inv_table_exists($db, 'partner_invite_codes')) {
        apiSuccess([
            'has_code'    => false,
            'public_code' => null,
            'invite_link' => null,
            'use_count'   => 0,
            'recent'      => [],
        ]);
        return;
    }

    try {
        // Fetch the Partner's active code
        $stmt = $db->prepare(
            'SELECT id, public_code, invite_token, use_count, allowed_entry_type,
                    max_uses, expires_at, created_at
             FROM   partner_invite_codes
             WHERE  inviter_partner_id = ?
               AND  status = ?
             ORDER  BY id DESC
             LIMIT  1'
        );
        $stmt->execute([$memberId, 'active']);
        $code = $stmt->fetch(PDO::FETCH_ASSOC);

        $baseUrl    = defined('SITE_BASE_URL') ? rtrim((string)SITE_BASE_URL, '/') : 'https://cogsaustralia.org';
        $inviteLink = null;
        $publicCode = null;
        $useCount   = 0;
        $recent     = [];

        if ($code) {
            $publicCode = (string)$code['public_code'];
            $inviteLink = $baseUrl . '/?invite=' . urlencode((string)$code['public_code']);
            $useCount   = (int)($code['use_count'] ?? 0);

            // Fetch recent accepted invitations (last 10)
            if (inv_table_exists($db, 'partner_invitations')) {
                $recStmt = $db->prepare(
                    'SELECT pi.entry_type, pi.accepted_at,
                            COALESCE(m.first_name, \'\') AS first_name
                     FROM   partner_invitations pi
                     LEFT   JOIN members m ON m.id = pi.accepted_partner_id
                     WHERE  pi.inviter_partner_id = ?
                       AND  pi.accepted_at IS NOT NULL
                     ORDER  BY pi.accepted_at DESC
                     LIMIT  10'
                );
                $recStmt->execute([$memberId]);
                foreach ($recStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $recent[] = [
                        'entry_type'  => $row['entry_type'],
                        'accepted_at' => $row['accepted_at'],
                        // Only show first name for privacy — full name not exposed
                        'first_name'  => $row['first_name'] !== '' ? $row['first_name'] : 'Partner',
                    ];
                }
            }
        }

        apiSuccess([
            'has_code'    => $code !== false,
            'public_code' => $publicCode,
            'invite_link' => $inviteLink,
            'use_count'   => $useCount,
            'recent'      => $recent,
        ]);

    } catch (Throwable $e) {
        error_log('[invitations/my-code] ' . $e->getMessage());
        apiError('Could not retrieve invitation code: ' . $e->getMessage(), 500);
    }
}

// ── Unknown sub-action ────────────────────────────────────────────────────────
apiError("Unknown invitations action: {$action}. Valid actions: validate, create, revoke, report, my-code.", 404);

