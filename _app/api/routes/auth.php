<?php
declare(strict_types=1);

$action = $id ?? 'login';

// Admin login hardening: if the host falls through to auth/login but the payload
// is clearly an admin login body, upgrade it before member validation runs.
if ($action === 'login') {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $hasAdminFields = isset($decoded['username']) || isset($decoded['otp']);
        $hasMemberFields = isset($decoded['member_number']) || isset($decoded['role']) || isset($decoded['auth_channel']);
        if ($hasAdminFields && !$hasMemberFields) {
            $action = 'admin-login';
        }
    }
}
match ($action) {
    'login' => handleLogin(),
    'verify-otp' => handleVerifyOtp(),
    'setup' => handleSetupPassword(),          // alias for older/front-end pages
    'setup-password' => handleSetupPassword(),
    'reset-password' => handleResetPassword(),
    'recover-number' => handleRecoverNumber(),
    'logout' => handleLogout(),
    'admin-bootstrap' => handleAdminBootstrap(),
    'admin-login' => handleAdminLogin(),
    'admin-status' => handleAdminStatus(),
    default => apiError('Unknown auth route', 404),
};

function handleLogin(): void {
    requireMethod('POST');
    $db = getDB();
    $body = jsonBody();
    enforceRateLimit($db, 'login');

    $role = strtolower(sanitize($body['role'] ?? ''));
    $activationToken = sanitize($body['activation_token'] ?? '');
    $tokenClaims = $activationToken !== '' ? verifyActivationToken($activationToken) : null;
    if ($tokenClaims && $role === '') {
        $role = $tokenClaims['role'];
    }

    $password = (string)($body['password'] ?? '');

    // SNFT login: mobile + password, optionally with member_number cross-check.
    $mobile       = normalizePhone((string)($body['mobile'] ?? ''));
    $memberNumber = normalizeMemberNumber((string)($body['member_number'] ?? ''));
    $email        = strtolower(sanitize($body['email'] ?? ''));

    // BNFT login: ABN + email + password (unchanged)
    $abn = $role === 'bnft' ? normalizeAbn((string)($body['member_number'] ?? '')) : '';

    if ($role === 'snft') {
        // Accept either mobile number OR email address for SNFT login.
        // The wallet form sends mobile; the community page may send email.
        if ($mobile === '' && $email === '') {
            recordAuthFailure($db, 'login');
            apiError('Please enter your mobile number or email address.');
        }

        $row = null;
        if ($mobile !== '') {
            // Build both mobile formats: 0410xxxxxx and +61410xxxxxx
            // The DB may store either depending on how the member joined.
            $mobileAlt = $mobile;
            if (substr($mobile, 0, 1) === '0') {
                $mobileAlt = '+61' . substr($mobile, 1); // 04xx → +614xx
            } elseif (substr($mobile, 0, 3) === '+61') {
                $mobileAlt = '0' . substr($mobile, 3);   // +614xx → 04xx
            }
            $stmt = $db->prepare('SELECT id, member_number, full_name, email, mobile, password_hash, wallet_status FROM snft_memberships WHERE mobile = ? OR mobile = ? LIMIT 1');
            $stmt->execute([$mobile, $mobileAlt]);
            $row = $stmt->fetch() ?: null;
        }
        if (!$row && $email !== '') {
            // Fallback to email lookup
            $stmt = $db->prepare('SELECT id, member_number, full_name, email, mobile, password_hash, wallet_status FROM snft_memberships WHERE LOWER(email) = ? LIMIT 1');
            $stmt->execute([$email]);
            $row = $stmt->fetch() ?: null;
        }

        if (!$row) {
            recordAuthFailure($db, 'login');
            apiError($mobile !== '' ? 'Mobile number not found.' : 'Email address not found.');
        }

        if ($memberNumber !== '' && (string)$row['member_number'] !== $memberNumber) {
            recordAuthFailure($db, 'login');
            apiError('Member number and mobile do not match.');
        }

        if ($tokenClaims && ((string)$row['member_number'] !== (string)$tokenClaims['member_ref'])) {
            apiError('This setup link is not valid for the member record supplied.');
        }

        if (!(string)$row['password_hash']) {
            apiSuccess([
                'setup_required' => true,
                'message' => 'First-time setup required.',
                'member_number' => (string)$row['member_number'],
                'mobile' => normalizePhone((string)($row['mobile'] ?? '')),
                'email' => strtolower((string)($row['email'] ?? '')),
            ]);
        }

        if (!password_verify($password, (string)$row['password_hash'])) {
            recordAuthFailure($db, 'login');
            apiError('Incorrect password.');
        }
        clearAuthRateLimit($db, 'login');

        // ── 12-hour OTP window ──────────────────────────────────────────────────
        // If this member completed OTP within the last 12 hours, skip OTP and
        // create a new session directly. Queries otp_verifications (a persistent
        // log that survives logout/session deletion) rather than sessions.
        // Covers: inactivity lockouts, tab-close re-logins, browser refreshes.
        try {
            $windowCutoff = gmdate('Y-m-d H:i:s', time() - (12 * 3600));
            $recentStmt = $db->prepare(
                "SELECT id FROM otp_verifications
                  WHERE principal_id = ?
                    AND user_type    = 'snft'
                    AND verified_at  >= ?
                  LIMIT 1"
            );
            $recentStmt->execute([(int)$row['id'], $windowCutoff]);
            if ($recentStmt->fetch()) {
                // Within window — skip OTP, create fresh fully-authenticated session
                createSession($db, 'snft', (int)$row['id'], (string)$row['member_number'], true);
                apiSuccess(['authenticated' => true, 'otp_skipped' => true]);
            }
        } catch (Throwable $windowErr) {
            // Fail open — proceed to standard OTP if table missing or query fails
            error_log('[2FA] 12h window check failed: ' . $windowErr->getMessage());
        }

        // 2FA: generate 6-digit OTP
        $otp         = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash     = password_hash($otp, PASSWORD_DEFAULT);
        $challengeId = bin2hex(random_bytes(32));
        $otpExpires  = gmdate('Y-m-d H:i:s', time() + 600); // 10 min

        $otpTableOk = false;
        try {
            $db->prepare('DELETE FROM member_otp_challenges WHERE member_number = ? AND expires_at < UTC_TIMESTAMP()')
               ->execute([(string)$row['member_number']]);
            $db->prepare('INSERT INTO member_otp_challenges (id, member_id, member_number, otp_hash, purpose, expires_at) VALUES (?,?,?,?,\'login\',?)')
               ->execute([$challengeId, (int)$row['id'], (string)$row['member_number'], $otpHash, $otpExpires]);
            $otpTableOk = true;
        } catch (Throwable $e) {
            // Table not yet created — skip 2FA, create session directly
            error_log('[2FA] WARN member_otp_challenges unavailable — skipping 2FA: ' . $e->getMessage());
        }

        if (!$otpTableOk) {
            createSession($db, 'snft', (int)$row['id'], (string)$row['member_number'], true);
            apiSuccess(['authenticated' => true]);
        }

        $memberEmail  = strtolower(trim((string)($row['email'] ?? '')));
        $memberMobile = (string)($row['mobile'] ?? '');
        $memberName   = (string)($row['full_name'] ?? 'Member');

        // ── Deliver OTP: respect auth_channel preference, SMS default, email on request or fallback ──
        $otpSent    = false;
        $otpChannel = 'none';
        $preferredChannel = strtolower(sanitize($body['auth_channel'] ?? ''));
        if (!in_array($preferredChannel, ['sms', 'email'], true)) {
            $preferredChannel = 'sms'; // default: try SMS first
        }

        // 1a. SMS — if preferred (default) and available
        if ($preferredChannel === 'sms' && $memberMobile !== '' && function_exists('smsEnabled') && smsEnabled()) {
            if (smsSendOtp($memberMobile, $otp, $memberName)) {
                $otpSent    = true;
                $otpChannel = 'sms';
            }
        }

        // 1b. Email — if explicitly preferred by the Partner
        if (!$otpSent && $preferredChannel === 'email' && $memberEmail !== '' && mailerEnabled()) {
            $otpHtml = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto">'
                . '<h2 style="color:#c8973e;margin-bottom:4px">COG$ Independence Vault</h2>'
                . '<p style="color:#555">Hello ' . htmlspecialchars($memberName, ENT_QUOTES) . ',</p>'
                . '<p style="color:#333">Your vault sign-in code:</p>'
                . '<div style="font-size:42px;font-weight:700;letter-spacing:14px;text-align:center;'
                . 'background:#0f1720;color:#f0d98a;padding:24px;border-radius:12px;margin:20px 0">'
                . htmlspecialchars($otp, ENT_QUOTES) . '</div>'
                . '<p style="color:#888;font-size:13px">Expires in <strong>10 minutes</strong>. One use only.</p>'
                . '<p style="color:#c00;font-size:12px">If you did not attempt this sign-in, change your password immediately.</p>'
                . '</div>';
            $otpText = "COG\$ Vault sign-in code: {$otp}\nExpires in 10 minutes. Do not share.";
            try {
                smtpSendEmail($memberEmail, 'COG$ Vault — your sign-in code', $otpHtml, $otpText);
                $otpSent    = true;
                $otpChannel = 'email';
            } catch (Throwable $mailErr) {
                error_log('[2FA] preferred-email send failed: ' . $mailErr->getMessage());
            }
        }

        // 2. Email — automatic fallback if SMS was preferred but failed
        if (!$otpSent && $memberEmail !== '' && mailerEnabled()) {
            $otpHtml = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto">'
                . '<h2 style="color:#c8973e;margin-bottom:4px">COG$ Independence Vault</h2>'
                . '<p style="color:#555">Hello ' . htmlspecialchars($memberName, ENT_QUOTES) . ',</p>'
                . '<p style="color:#333">Your vault sign-in code:</p>'
                . '<div style="font-size:42px;font-weight:700;letter-spacing:14px;text-align:center;'
                . 'background:#0f1720;color:#f0d98a;padding:24px;border-radius:12px;margin:20px 0">'
                . htmlspecialchars($otp, ENT_QUOTES) . '</div>'
                . '<p style="color:#888;font-size:13px">Expires in <strong>10 minutes</strong>. One use only.</p>'
                . '<p style="color:#c00;font-size:12px">If you did not attempt this sign-in, change your password immediately.</p>'
                . '</div>';
            $otpText = "COG\$ Vault sign-in code: {$otp}\nExpires in 10 minutes. Do not share.";
            try {
                smtpSendEmail($memberEmail, 'COG$ Vault — your sign-in code', $otpHtml, $otpText);
                $otpSent    = true;
                $otpChannel = 'email';
            } catch (Throwable $mailErr) {
                error_log('[2FA] email send failed: ' . $mailErr->getMessage());
                try {
                    queueEmail($db, 'snft_member', (int)$row['id'], $memberEmail, 'otp_login',
                        'COG$ Vault sign-in code', ['otp' => $otp, 'name' => $memberName]);
                    $otpSent    = true;
                    $otpChannel = 'email_queued';
                } catch (Throwable $qe) {
                    error_log('[2FA] queue also failed: ' . $qe->getMessage());
                }
            }
        }

        // 3. Neither channel delivered — skip 2FA, log in directly
        if (!$otpSent) {
            try { $db->prepare('DELETE FROM member_otp_challenges WHERE id = ?')->execute([$challengeId]); }
            catch (Throwable $ignored) {}
            createSession($db, 'snft', (int)$row['id'], (string)$row['member_number'], true);
            apiSuccess(['authenticated' => true]);
        }

        // Build masked hint for the UI — show which channel was used        // Build masked hint for the UI — show which channel was used
        $deliveryHint = '';
        if ($otpChannel === 'sms') {
            // Mask mobile: show first 4 + last 2 digits
            $digitsOnly = preg_replace('/\D/', '', $memberMobile);
            $deliveryHint = substr($digitsOnly, 0, 4) . str_repeat('*', max(2, strlen($digitsOnly) - 6)) . substr($digitsOnly, -2);
            $deliveryChannel = 'sms';
        } elseif ($memberEmail !== '') {
            [$local, $domain] = explode('@', $memberEmail, 2) + ['', ''];
            $deliveryHint = (strlen($local) > 2 ? substr($local, 0, 2) . str_repeat('*', max(2, strlen($local) - 2)) : '**')
                          . '@' . $domain;
            $deliveryChannel = 'email';
        } else {
            $deliveryChannel = 'unknown';
        }

        apiSuccess([
            'otp_required'     => true,
            'challenge_token'  => $challengeId,
            'delivery_channel' => $deliveryChannel,  // 'sms' or 'email'
            'delivery_hint'    => $deliveryHint,      // masked mobile or email
            // Legacy field — wallet uses this to show hint text
            'email_hint'       => $deliveryHint,
        ]);
    }

    if ($role === 'bnft') {
        $stmt = $db->prepare('SELECT id, abn, legal_name, email, password_hash, wallet_status FROM bnft_memberships WHERE abn = ? LIMIT 1');
        $stmt->execute([$abn]);
        $row = $stmt->fetch();
        if (!$row || strtolower((string)$row['email']) !== $email) {
            recordAuthFailure($db, 'login');
            apiError('ABN and email do not match.');
        }
        if ($tokenClaims && ((string)$row['abn'] !== (string)$tokenClaims['member_ref'] || strtolower((string)$row['email']) !== (string)$tokenClaims['email'])) {
            apiError('This setup link is not valid for the business record supplied.');
        }
        if (!(string)$row['password_hash']) {
            apiSuccess([
                'setup_required' => true,
                'message' => 'First-time setup required.',
                'member_number' => $row['abn'],
            ]);
        }
        if (!password_verify($password, (string)$row['password_hash'])) {
            recordAuthFailure($db, 'login');
            apiError('Incorrect password.');
        }
        clearAuthRateLimit($db, 'login');
        createSession($db, 'bnft', (int)$row['id'], (string)$row['abn']);
        apiSuccess(['authenticated' => true]);
    }

    apiError('Unknown role.');
}


function handleVerifyOtp(): void {
    requireMethod('POST');
    $db   = getDB();
    $body = jsonBody();
    enforceRateLimit($db, 'login');

    $challengeToken = sanitize((string)($body['challenge_token'] ?? ''));
    $otp            = preg_replace('/\D/', '', (string)($body['otp'] ?? ''));

    if ($challengeToken === '' || strlen($otp) !== 6) {
        apiError('Invalid request — please re-enter your sign-in code.');
    }

    try {
        $stmt = $db->prepare('SELECT * FROM member_otp_challenges WHERE id = ? AND expires_at > UTC_TIMESTAMP() AND verified_at IS NULL LIMIT 1');
        $stmt->execute([$challengeToken]);
        $challenge = $stmt->fetch();
    } catch (Throwable $e) {
        apiError('Verification service unavailable.', 503);
    }

    if (!$challenge) {
        recordAuthFailure($db, 'login');
        apiError('This code has expired or already been used. Please sign in again.');
    }

    if ((int)($challenge['attempts'] ?? 0) >= 5) {
        $db->prepare('DELETE FROM member_otp_challenges WHERE id = ?')->execute([$challengeToken]);
        recordAuthFailure($db, 'login');
        apiError('Too many incorrect attempts. Please start the sign-in process again.');
    }

    $db->prepare('UPDATE member_otp_challenges SET attempts = attempts + 1 WHERE id = ?')
       ->execute([$challengeToken]);

    if (!password_verify($otp, (string)$challenge['otp_hash'])) {
        recordAuthFailure($db, 'login');
        $remaining = max(0, 4 - (int)($challenge['attempts'] ?? 0));
        apiError('Incorrect code.' . ($remaining > 0 ? " {$remaining} attempt(s) remaining." : ' Please sign in again.'));
    }

    // Correct — mark used and create full session
    $db->prepare('UPDATE member_otp_challenges SET verified_at = UTC_TIMESTAMP() WHERE id = ?')
       ->execute([$challengeToken]);

    $mStmt = $db->prepare('SELECT id, member_number FROM snft_memberships WHERE member_number = ? LIMIT 1');
    $mStmt->execute([(string)$challenge['member_number']]);
    $member = $mStmt->fetch();
    if (!$member) { apiError('Member not found.', 404); }

    clearAuthRateLimit($db, 'login');
    createSession($db, 'snft', (int)$member['id'], (string)$member['member_number'], true);
    apiSuccess(['authenticated' => true]);
}

function handleSetupPassword(): void {
    requireMethod('POST');
    $db = getDB();
    $body = jsonBody();
    enforceRateLimit($db, 'setup-password', 10, 1800, 1800);

    $role = strtolower(sanitize($body['role'] ?? ''));
    $activationToken = sanitize($body['activation_token'] ?? '');
    $tokenClaims = $activationToken !== '' ? verifyActivationToken($activationToken) : null;
    if ($tokenClaims && $role === '') {
        $role = $tokenClaims['role'];
    }
    $rawMemberNumber = (string)($body['member_number'] ?? ($tokenClaims['member_ref'] ?? ''));
    $memberNumber = $role === 'bnft'
        ? normalizeAbn($rawMemberNumber)
        : normalizeMemberNumber($rawMemberNumber);

    // SNFT setup verifies with member number + mobile only.
    // BNFT setup still verifies with ABN + email.
    $mobile = normalizePhone((string)($body['mobile'] ?? ''));
    $email  = strtolower(sanitize($body['email'] ?? ($tokenClaims['email'] ?? '')));

    $password = (string)($body['password'] ?? '');
    $confirm  = (string)($body['confirm_password'] ?? '');
    $supportCode = (string)($body['support_code'] ?? '');

    if ($tokenClaims && $tokenClaims['role'] !== $role) apiError('This setup link does not match the selected wallet type.');
    if ($memberNumber === '') apiError('Member number is required.');
    if ($role === 'snft' && $mobile === '') apiError('A valid mobile number is required.');
    if ($role === 'bnft' && !validateEmail($email)) apiError('A valid email is required.');
    if (strlen($password) < 8) apiError('Password must be at least 8 characters.');
    if ($password !== $confirm) apiError('Passwords do not match.');

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    if ($role === 'snft') {
        $stmt = $db->prepare('SELECT id, member_number, email, mobile, password_hash FROM snft_memberships WHERE member_number = ? LIMIT 1');
        $stmt->execute([$memberNumber]);
        $row = $stmt->fetch();
        if (!$row) {
            apiError('Member number not found.');
        }
        // Verify identity: mobile only for SNFT setup.
        $mobileMatch = normalizePhone((string)($row['mobile'] ?? '')) === $mobile;
        if (!$mobileMatch) {
            apiError('Mobile number does not match the member record.');
        }
        if ($tokenClaims && ((string)$row['member_number'] !== (string)$tokenClaims['member_ref'] || strtolower((string)$row['email']) !== (string)$tokenClaims['email'])) {
            apiError('This setup link is not valid for the member record supplied.');
        }
        if ((string)($row['password_hash'] ?? '') !== '') {
            apiError('This wallet already has a password. Use password reset instead.');
        }
        $db->prepare('UPDATE snft_memberships SET password_hash = ?, wallet_status = "active", updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$hash, (int)$row['id']]);
        $db->prepare('UPDATE vault_wallets SET wallet_status = "active", updated_at = UTC_TIMESTAMP() WHERE subject_type = "snft_member" AND subject_id = ?')->execute([(int)$row['id']]);
        recordWalletEvent($db, 'snft_member', (string)$row['member_number'], 'wallet_activated', 'SNFT wallet password was set.');
        // otp_verified = true: identity was confirmed via mobile match during setup,
        // no separate OTP step required for first-time vault access.
        createSession($db, 'snft', (int)$row['id'], (string)$row['member_number'], true);
        apiSuccess(['authenticated' => true]);
    }

    if ($role === 'bnft') {
        $stmt = $db->prepare('SELECT id, abn, email, password_hash FROM bnft_memberships WHERE abn = ? LIMIT 1');
        $stmt->execute([$memberNumber]);
        $row = $stmt->fetch();
        if (!$row || strtolower((string)$row['email']) !== $email) {
            apiError('ABN and email do not match.');
        }
        if ($tokenClaims && ((string)$row['abn'] !== (string)$tokenClaims['member_ref'] || strtolower((string)$row['email']) !== (string)$tokenClaims['email'])) {
            apiError('This setup link is not valid for the business record supplied.');
        }
        if ((string)($row['password_hash'] ?? '') !== '') {
            apiError('This business wallet already has a password. Use password reset instead.');
        }
        $db->prepare('UPDATE bnft_memberships SET password_hash = ?, wallet_status = "active", updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$hash, (int)$row['id']]);
        $db->prepare('UPDATE vault_wallets SET wallet_status = "active", updated_at = UTC_TIMESTAMP() WHERE subject_type = "bnft_business" AND subject_id = ?')->execute([(int)$row['id']]);
        recordWalletEvent($db, 'bnft_business', (string)$row['abn'], 'wallet_activated', 'Business wallet password was set.');
        createSession($db, 'bnft', (int)$row['id'], (string)$row['abn']);
        apiSuccess(['authenticated' => true]);
    }

    apiError('Unknown role.');
}

function handleResetPassword(): void {
    requireMethod('POST');
    $db   = getDB();
    $body = jsonBody();
    enforceRateLimit($db, 'reset-password');

    // Phase 1 (request):  member_number + mobile + preferred_channel  → issues OTP → challenge_token
    // Phase 2 (confirm):  challenge_token + otp + password + confirm  → verifies OTP → sets password
    $phase = isset($body['challenge_token']) ? 'confirm' : 'request';

    // ── Phase 1: verify identity, issue OTP ──────────────────────────────────
    if ($phase === 'request') {
        $memberNumber      = normalizeMemberNumber((string)($body['member_number'] ?? ''));
        $mobile            = normalizePhone((string)($body['mobile'] ?? ''));
        // preferred_channel: 'sms' (default) or 'email' — email is always the fallback
        $preferredChannel  = in_array(strtolower((string)($body['preferred_channel'] ?? '')), ['sms','email'], true)
                             ? strtolower((string)$body['preferred_channel'])
                             : 'sms';

        if ($memberNumber === '') apiError('Member number is required.');
        if (strlen($mobile) < 8)  apiError('A valid mobile number is required.');

        $stmt = $db->prepare('SELECT id, member_number, full_name, mobile, email FROM snft_memberships WHERE member_number = ? LIMIT 1');
        $stmt->execute([$memberNumber]);
        $row = $stmt->fetch();

        // Same error whether member number or mobile wrong — prevents enumeration
        if (!$row || normalizePhone((string)($row['mobile'] ?? '')) !== $mobile) {
            recordAuthFailure($db, 'reset-password');
            logRecoveryAttempt($db, 'password_reset', 'snft', 'mobile', $memberNumber, $mobile, 'rejected');
            apiError('Member number and mobile do not match our records.');
        }

        $memberName   = (string)($row['full_name'] ?? 'Member');
        $memberEmail  = strtolower(trim((string)($row['email'] ?? '')));
        $memberMobile = normalizePhone((string)$row['mobile']);

        // Generate OTP
        $otpCode      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash      = password_hash($otpCode, PASSWORD_DEFAULT);
        $challengeId  = bin2hex(random_bytes(32));
        $otpExpires   = gmdate('Y-m-d H:i:s', time() + 600); // 10 min

        try {
            $db->prepare('DELETE FROM member_otp_challenges WHERE member_number = ? AND expires_at < UTC_TIMESTAMP()')
               ->execute([$memberNumber]);
            $db->prepare('INSERT INTO member_otp_challenges (id, member_id, member_number, otp_hash, purpose, expires_at) VALUES (?,?,?,?,?,?)')
               ->execute([$challengeId, (int)$row['id'], $memberNumber, $otpHash, 'password_reset', $otpExpires]);
        } catch (Throwable $e) {
            error_log('[reset-pw] OTP table error: ' . $e->getMessage());
            apiError('Reset service temporarily unavailable. Please try again shortly.', 503);
        }

        // Deliver OTP — preferred channel first, email fallback
        $otpSent    = false;
        $otpChannel = 'none';

        $smsText = "COG\$ password reset code: {$otpCode}
Expires in 10 minutes. Do not share this code.";
        $emailHtml = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto">'
            . '<h2 style="color:#c8973e;margin-bottom:4px">COG$ Independence Vault</h2>'
            . '<p style="color:#555">Hello ' . htmlspecialchars($memberName, ENT_QUOTES) . ',</p>'
            . '<p style="color:#333">Your password reset code:</p>'
            . '<div style="font-size:42px;font-weight:700;letter-spacing:14px;text-align:center;'
            . 'background:#0f1720;color:#f0d98a;padding:24px;border-radius:12px;margin:20px 0">'
            . htmlspecialchars($otpCode, ENT_QUOTES) . '</div>'
            . '<p style="color:#888;font-size:13px">Expires in <strong>10 minutes</strong>. One use only.</p>'
            . '<p style="color:#c00;font-size:12px">If you did not request a password reset, contact us immediately at members@cogsaustralia.org</p>'
            . '</div>';
        $emailText = "COG\$ password reset code: {$otpCode}
Expires in 10 minutes. Do not share.
If you did not request this, contact members@cogsaustralia.org immediately.";

        // Try preferred channel first
        if ($preferredChannel === 'sms' && $memberMobile !== '' && function_exists('smsEnabled') && smsEnabled()) {
            if (smsSendOtp($memberMobile, $otpCode, $memberName)) {
                $otpSent    = true;
                $otpChannel = 'sms';
            }
        }
        if ($preferredChannel === 'email' && $memberEmail !== '' && mailerEnabled()) {
            try {
                smtpSendEmail($memberEmail, 'COG$ Vault — password reset code', $emailHtml, $emailText);
                $otpSent    = true;
                $otpChannel = 'email';
            } catch (Throwable $ignored) {}
        }

        // Email fallback — if SMS was preferred but failed, or SMS not available
        if (!$otpSent && $memberEmail !== '' && mailerEnabled()) {
            try {
                smtpSendEmail($memberEmail, 'COG$ Vault — password reset code', $emailHtml, $emailText);
                $otpSent    = true;
                $otpChannel = 'email';
            } catch (Throwable $ignored) {
                try {
                    queueEmail($db, 'snft_member', (int)$row['id'], $memberEmail, 'otp_login',
                        'COG$ Vault — password reset code', ['otp' => $otpCode, 'name' => $memberName]);
                    $otpSent    = true;
                    $otpChannel = 'email_queued';
                } catch (Throwable $ignored2) {}
            }
        }

        if (!$otpSent) {
            // Can't deliver OTP — clean up and refuse
            try { $db->prepare('DELETE FROM member_otp_challenges WHERE id = ?')->execute([$challengeId]); } catch (Throwable $ignored) {}
            apiError('Unable to deliver a verification code to your registered contact details. Please contact members@cogsaustralia.org for assistance.');
        }

        // Build masked delivery hint
        if ($otpChannel === 'sms' || ($otpChannel !== 'email' && $otpChannel !== 'email_queued')) {
            $digits       = preg_replace('/\D/', '', $memberMobile);
            $deliveryHint = substr($digits, 0, 4) . str_repeat('*', max(2, strlen($digits) - 6)) . substr($digits, -2);
            $deliveryType = 'sms';
        } else {
            [$local, $domain] = explode('@', $memberEmail, 2) + ['', ''];
            $deliveryHint = (strlen($local) > 2 ? substr($local, 0, 2) . str_repeat('*', max(2, strlen($local) - 2)) : '**') . '@' . $domain;
            $deliveryType = 'email';
        }

        logRecoveryAttempt($db, 'password_reset_otp_sent', 'snft', $otpChannel, $memberNumber, $mobile, 'otp_issued');
        apiSuccess([
            'otp_required'     => true,
            'challenge_token'  => $challengeId,
            'delivery_channel' => $deliveryType,
            'delivery_hint'    => $deliveryHint,
        ]);
    }

    // ── Phase 2: verify OTP, set new password ────────────────────────────────
    if ($phase === 'confirm') {
        $challengeToken = sanitize((string)($body['challenge_token'] ?? ''));
        $otpInput       = preg_replace('/\D/', '', (string)($body['otp'] ?? ''));
        $password       = (string)($body['password'] ?? '');
        $confirm        = (string)($body['confirm_password'] ?? '');

        if ($challengeToken === '')  apiError('Reset session missing. Please start again.');
        if (strlen($otpInput) !== 6) apiError('Please enter the 6-digit code.');
        if (strlen($password) < 8)   apiError('Password must be at least 8 characters.');
        if ($password !== $confirm)  apiError('Passwords do not match.');

        try {
            $stmt = $db->prepare('SELECT * FROM member_otp_challenges WHERE id = ? AND purpose = ? AND expires_at > UTC_TIMESTAMP() AND verified_at IS NULL LIMIT 1');
            $stmt->execute([$challengeToken, 'password_reset']);
            $challenge = $stmt->fetch();
        } catch (Throwable $e) {
            apiError('Verification service unavailable.', 503);
        }

        if (!$challenge) {
            recordAuthFailure($db, 'reset-password');
            apiError('This code has expired or already been used. Please start the reset process again.');
        }

        if ((int)($challenge['attempts'] ?? 0) >= 5) {
            $db->prepare('DELETE FROM member_otp_challenges WHERE id = ?')->execute([$challengeToken]);
            recordAuthFailure($db, 'reset-password');
            apiError('Too many incorrect attempts. Please start the reset process again.');
        }

        $db->prepare('UPDATE member_otp_challenges SET attempts = attempts + 1 WHERE id = ?')->execute([$challengeToken]);

        if (!password_verify($otpInput, (string)$challenge['otp_hash'])) {
            recordAuthFailure($db, 'reset-password');
            $remaining = max(0, 4 - (int)($challenge['attempts'] ?? 0));
            apiError('Incorrect code.' . ($remaining > 0 ? " {$remaining} attempt(s) remaining." : ' Please start again.'));
        }

        // OTP verified — mark used, set new password
        $db->prepare('UPDATE member_otp_challenges SET verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$challengeToken]);

        $mStmt = $db->prepare('SELECT id, member_number FROM snft_memberships WHERE member_number = ? LIMIT 1');
        $mStmt->execute([(string)$challenge['member_number']]);
        $member = $mStmt->fetch();
        if (!$member) apiError('Member record not found.', 404);

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE snft_memberships SET password_hash = ?, wallet_status = "active", updated_at = UTC_TIMESTAMP() WHERE id = ?')
           ->execute([$hash, (int)$member['id']]);
        $db->prepare('UPDATE vault_wallets SET wallet_status = "active", updated_at = UTC_TIMESTAMP() WHERE subject_type = "snft_member" AND subject_id = ?')
           ->execute([(int)$member['id']]);

        clearAuthRateLimit($db, 'reset-password');
        recordWalletEvent($db, 'snft_member', (string)$member['member_number'], 'password_reset', 'Password reset via OTP — verified.');
        logRecoveryAttempt($db, 'password_reset', 'snft', 'otp', (string)$member['member_number'], '', 'matched');
        createSession($db, 'snft', (int)$member['id'], (string)$member['member_number'], true);
        apiSuccess(['authenticated' => true, 'member_number' => (string)$member['member_number']]);
    }

    apiError('Unknown reset phase.');
}

function handleRecoverNumber(): void {
    requireMethod('POST');
    $db = getDB();
    $body = jsonBody();

    $role = strtolower(sanitize($body['role'] ?? ''));
    $authChannelInput = strtolower(sanitize($body['auth_channel'] ?? ''));
    $authChannel = $authChannelInput !== ''
        ? $authChannelInput
        : (($role === 'snft' && normalizePhone((string)($body['mobile'] ?? '')) !== '') ? 'mobile' : 'email');
    $identityName = normalizeIdentityString((string)($body['identity_name'] ?? $body['full_name'] ?? $body['business_name'] ?? ''));
    $authValue = $authChannel === 'mobile'
        ? normalizePhone((string)($body['auth_value'] ?? $body['mobile'] ?? ''))
        : strtolower(sanitize($body['auth_value'] ?? $body['email'] ?? ''));
    $supportCode = (string)($body['support_code'] ?? '');

    if (!in_array($authChannel, ['email', 'mobile'], true)) apiError('Choose email or mobile authentication.');
    if ($identityName === '') apiError($role === 'bnft' ? 'Business or contact name is required.' : 'Full name is required.');
    if ($authChannel === 'email' && !validateEmail($authValue)) apiError('A valid email is required.');
    if ($authChannel === 'mobile' && strlen($authValue) < 8) apiError('A valid mobile number is required.');

    if ($role === 'snft') {
        if ($authChannel === 'email') {
            $stmt = $db->prepare('SELECT id, member_number, full_name, email, mobile FROM snft_memberships WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 5');
            $stmt->execute([$authValue]);
        } else {
            // Query by normalised mobile — requires idx_snft_mobile index (see migration
            // 2026_04_02_mobile_index_and_sequence.sql). LIMIT 10 caps the result set.
            $stmt = $db->prepare('SELECT id, member_number, full_name, email, mobile FROM snft_memberships WHERE mobile = ? ORDER BY id DESC LIMIT 10');
            $stmt->execute([normalizePhone($authValue)]);
        }
        $matches = $stmt->fetchAll();
        foreach ($matches as $row) {
            if (normalizeIdentityString((string)$row['full_name']) === $identityName && ($authChannel === 'email' ? strtolower((string)$row['email']) === $authValue : normalizePhone((string)$row['mobile']) === $authValue)) {
                if ($supportCode !== '' && !validateWalletSupportCode($supportCode, 'snft', (string)$row['member_number'], (string)$row['email'])) {
                    continue;
                }
                recordWalletEvent($db, 'snft_member', (string)$row['member_number'], 'member_number_recovered', 'SNFT member number was recovered using on-file ' . $authChannel . ' authentication.');
                logRecoveryAttempt($db, 'member_number', 'snft', $authChannel, $identityName, $authValue, 'matched', (string)$row['member_number']);
                apiSuccess([
                    'member_number' => (string)$row['member_number'],
                    'member_number_display' => (string)$row['member_number'],
                    'message' => 'Member number recovered.',
                ]);
            }
        }
        logRecoveryAttempt($db, 'member_number', 'snft', $authChannel, $identityName, $authValue, 'rejected');
        apiError('We could not match that name and contact combination.');
    }

    if ($role === 'bnft') {
        if ($authChannel === 'email') {
            $stmt = $db->prepare('SELECT id, abn, legal_name, trading_name, contact_name, email, mobile FROM bnft_memberships WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 5');
            $stmt->execute([$authValue]);
        } else {
            // Query by normalised mobile — requires idx_bnft_mobile index (see migration
            // 2026_04_02_mobile_index_and_sequence.sql). LIMIT 10 caps the result set.
            $stmt = $db->prepare('SELECT id, abn, legal_name, trading_name, contact_name, email, mobile FROM bnft_memberships WHERE mobile = ? ORDER BY id DESC LIMIT 10');
            $stmt->execute([normalizePhone($authValue)]);
        }
        $matches = $stmt->fetchAll();
        foreach ($matches as $row) {
            $names = [
                normalizeIdentityString((string)$row['legal_name']),
                normalizeIdentityString((string)($row['trading_name'] ?? '')),
                normalizeIdentityString((string)$row['contact_name']),
            ];
            if (in_array($identityName, $names, true) && ($authChannel === 'email' ? strtolower((string)$row['email']) === $authValue : normalizePhone((string)$row['mobile']) === $authValue)) {
                if ($supportCode !== '' && !validateWalletSupportCode($supportCode, 'bnft', (string)$row['abn'], (string)$row['email'])) {
                    continue;
                }
                recordWalletEvent($db, 'bnft_business', (string)$row['abn'], 'member_number_recovered', 'BNFT business number was recovered using on-file ' . $authChannel . ' authentication.');
                logRecoveryAttempt($db, 'member_number', 'bnft', $authChannel, $identityName, $authValue, 'matched', (string)$row['abn']);
                apiSuccess([
                    'member_number' => (string)$row['abn'],
                    'member_number_display' => (string)$row['abn'],
                    'message' => 'Business number recovered.',
                ]);
            }
        }
        logRecoveryAttempt($db, 'member_number', 'bnft', $authChannel, $identityName, $authValue, 'rejected');
        apiError('We could not match that business/contact name and contact combination.');
    }

    apiError('Unknown role.');
}

function handleAdminBootstrap(): void {
    requireMethod('POST');
    $db = getDB();
    $body = jsonBody();

    if (ADMIN_BOOTSTRAP_TOKEN === '') {
        apiError('Set ADMIN_BOOTSTRAP_TOKEN in .env before bootstrapping admin access.', 500);
    }
    $token = (string)($body['bootstrap_token'] ?? '');
    if (!hash_equals(ADMIN_BOOTSTRAP_TOKEN, $token)) {
        apiError('Bootstrap token is invalid.', 401);
    }

    $username = strtolower(preg_replace('/[^a-z0-9._-]+/i', '', sanitize($body['username'] ?? '')) ?: '');
    $displayName = sanitize($body['display_name'] ?? 'Admin');
    $email = strtolower(sanitize($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $confirm = (string)($body['confirm_password'] ?? '');
    $supportCode = (string)($body['support_code'] ?? '');
    $role = strtolower(sanitize($body['role_name'] ?? 'superadmin'));
    $allowedRoles = ['superadmin', 'governance_admin', 'content_admin', 'operations_admin'];

    if ($username === '' || strlen($username) < 3) apiError('Choose a username of at least 3 characters.');
    if (!validateEmail($email)) apiError('A valid admin email is required.');
    if (strlen($password) < 12) apiError('Admin password must be at least 12 characters.');
    if ($password !== $confirm) apiError('Passwords do not match.');
    if (!in_array($role, $allowedRoles, true)) apiError('Invalid admin role.');

    $check = $db->prepare('SELECT id FROM admin_users WHERE username = ? OR email = ? LIMIT 1');
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        apiError('An admin account with that username or email already exists.');
    }

    $secret = generateBase32Secret(20);
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $insert = $db->prepare('INSERT INTO admin_users (username, email, display_name, role_name, password_hash, two_factor_secret, two_factor_enabled, is_active) VALUES (?,?,?,?,?,?,1,1)');
    $insert->execute([$username, $email, $displayName, $role, $hash, $secret]);

    apiSuccess([
        'bootstrapped' => true,
        'username' => $username,
        'display_name' => $displayName,
        'role_name' => $role,
        'totp_secret' => $secret,
        'otpauth_url' => formatOtpauthUrl(ADMIN_TOTP_ISSUER, $username, $secret),
        'message' => 'Admin created. Add the secret to your authenticator app, then sign in with username, password, and the 6-digit 2FA code.',
    ], 201);
}

function handleAdminLogin(): void {
    requireMethod('POST');
    $db = getDB();
    $body = jsonBody();
    enforceRateLimit($db, 'admin-login', 5, 1800, 1800); // 5 attempts per 30 min

    $username = strtolower(sanitize($body['username'] ?? $body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $otp = (string)($body['otp'] ?? '');
    if ($username === '' || $password === '' || $otp === '') {
        apiError('Username, password, and 2FA code are required.');
    }

    $stmt = $db->prepare('SELECT id, username, email, display_name, role_name, password_hash, two_factor_secret, two_factor_enabled, is_active FROM admin_users WHERE (username = ? OR email = ?) LIMIT 1');
    $stmt->execute([$username, $username]);
    $admin = $stmt->fetch();
    if (!$admin || (int)$admin['is_active'] !== 1) {
        apiError('Admin account not found.', 404);
    }
    if (!password_verify($password, (string)$admin['password_hash'])) {
        recordAuthFailure($db, 'admin-login');
        apiError('Incorrect password.');
    }
    if ((int)$admin['two_factor_enabled'] !== 1 || !verifyTotpCode((string)$admin['two_factor_secret'], $otp)) {
        recordAuthFailure($db, 'admin-login');
        apiError('The 2FA code is invalid.');
    }
    clearAuthRateLimit($db, 'admin-login');
    createSession($db, 'admin', (int)$admin['id'], (string)$admin['username']);
    $db->prepare('UPDATE admin_users SET last_login_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int)$admin['id']]);
    apiSuccess([
        'authenticated' => true,
        'display_name' => $admin['display_name'],
        'role_name' => $admin['role_name'],
        'username' => $admin['username'],
    ]);
}

function handleAdminStatus(): void {
    requireMethod('GET');
    $admin = requireAdminRole();
    apiSuccess([
        'authenticated' => true,
        'username' => $admin['username'],
        'display_name' => $admin['display_name'],
        'role_name' => $admin['role_name'],
        'two_factor_enabled' => (bool)$admin['two_factor_enabled'],
    ]);
}

function handleLogout(): void {
    requireMethod('POST');
    $sessionId = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if ($sessionId !== '') {
        $db = getDB();
        $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$sessionId]);
    }
    clearSessionCookie();
    apiSuccess(['logged_out' => true]);
}

function createSession(PDO $db, string $userType, int $principalId, string $subjectRef, bool $otpVerified = false): void {
    $sessionId = bin2hex(random_bytes(32));
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (SESSION_HOURS * 3600));

    // Gracefully handle presence/absence of otp_verified column
    $hasOtpCol = false;
    try {
        $hasOtpCol = (bool)$db->query("SHOW COLUMNS FROM `sessions` LIKE 'otp_verified'")->fetch();
    } catch (Throwable $ignored) {}

    if ($hasOtpCol) {
        $otpVerifiedAt = $otpVerified ? gmdate('Y-m-d H:i:s') : null;
        $db->prepare('INSERT INTO sessions (id, user_type, principal_id, subject_ref, otp_verified, otp_verified_at, expires_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([$sessionId, $userType, $principalId, $subjectRef, $otpVerified ? 1 : 0, $otpVerifiedAt, $expiresAt]);
    } else {
        $db->prepare('INSERT INTO sessions (id, user_type, principal_id, subject_ref, expires_at) VALUES (?,?,?,?,?)')
           ->execute([$sessionId, $userType, $principalId, $subjectRef, $expiresAt]);
    }

    // Write persistent OTP verification log entry when OTP was verified.
    // This record is never deleted on logout and powers the 12-hour re-login skip window.
    if ($otpVerified) {
        try {
            $db->prepare(
                'INSERT INTO otp_verifications (principal_id, user_type, verified_at, ip_address) VALUES (?,?,UTC_TIMESTAMP(),?)'
            )->execute([$principalId, $userType, getClientIp()]);
        } catch (Throwable $logErr) {
            // Non-fatal — table may not exist yet on first deploy; window check will fail open
            error_log('[2FA] otp_verifications insert failed: ' . $logErr->getMessage());
        }
    }

    setcookie(SESSION_COOKIE_NAME, $sessionId, cookieOptions());
}
