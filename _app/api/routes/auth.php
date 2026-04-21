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
    'verify-login-token' => handleVerifyLoginToken(),
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

        // ── 2FA: magic link via email (no SMS, no 6-digit code) ────────────────
        $memberEmail  = strtolower(trim((string)($row['email'] ?? '')));
        $memberName   = (string)($row['full_name'] ?? 'Member');
        $memberNumber = (string)$row['member_number'];

        // No email on file — cannot send link, log in directly as fallback
        if ($memberEmail === '') {
            createSession($db, 'snft', (int)$row['id'], $memberNumber, true);
            apiSuccess(['authenticated' => true]);
        }

        // Generate a secure 32-byte raw token; store only its SHA-256 hash
        $rawToken   = bin2hex(random_bytes(32));          // 64-char hex
        $tokenHash  = hash('sha256', $rawToken);
        $purpose    = 'member_login:' . $memberNumber;   // encodes member identity without extra column
        $expires    = gmdate('Y-m-d H:i:s', time() + 600); // 10 minutes

        $tokenStored = false;
        try {
            // Clear any unused previous login tokens for this member
            $db->prepare("DELETE FROM one_time_tokens WHERE purpose = ? AND used_at IS NULL")
               ->execute([$purpose]);
            $db->prepare("INSERT INTO one_time_tokens (token_hash, purpose, expires_at, created_at) VALUES (?,?,?,UTC_TIMESTAMP())")
               ->execute([$tokenHash, $purpose, $expires]);
            $tokenStored = true;
        } catch (Throwable $e) {
            error_log('[magic-link] one_time_tokens unavailable: ' . $e->getMessage());
        }

        if (!$tokenStored) {
            // Table unavailable — skip 2FA, create session directly
            createSession($db, 'snft', (int)$row['id'], $memberNumber, true);
            apiSuccess(['authenticated' => true]);
        }

        // Build the clickable magic link
        $baseUrl  = 'https://cogsaustralia.org';
        $magicUrl = $baseUrl . '/partners/?login_token=' . urlencode($rawToken);

        // Build masked email hint for the UI
        [$local, $domain] = array_pad(explode('@', $memberEmail, 2), 2, '');
        $maskedEmail = (strlen($local) > 2
            ? substr($local, 0, 2) . str_repeat('*', max(2, strlen($local) - 2))
            : '**') . '@' . $domain;

        // Email HTML — large, clear, single CTA button
        $firstName = explode(' ', trim($memberName))[0] ?: 'Member';
        $htmlBody =
            '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;background:#0d0f14;border-radius:16px;padding:40px 36px;color:#fff8e8">'
          . '<div style="text-align:center;margin-bottom:32px">'
          . '<span style="font-size:28px;font-weight:700;color:#f0d18a;letter-spacing:-.5px">COG$ Independence Vault</span>'
          . '</div>'
          . '<p style="font-size:18px;line-height:1.6;margin:0 0 16px">Hello ' . htmlspecialchars($firstName, ENT_QUOTES) . ',</p>'
          . '<p style="font-size:16px;line-height:1.7;color:rgba(255,248,232,.85);margin:0 0 32px">Tap the button below to open your Independence Vault. This link expires in <strong style="color:#f0d18a">10 minutes</strong> and can only be used once.</p>'
          . '<div style="text-align:center;margin:0 0 32px">'
          . '<a href="' . htmlspecialchars($magicUrl, ENT_QUOTES) . '" '
          . 'style="display:inline-block;background:#f0d18a;color:#0a0804;font-size:18px;font-weight:700;'
          . 'padding:18px 44px;border-radius:12px;text-decoration:none;letter-spacing:.01em">'
          . 'Open my Independence Vault →'
          . '</a>'
          . '</div>'
          . '<p style="font-size:13px;color:rgba(255,248,232,.45);text-align:center;margin:0 0 8px">Or copy this link into your browser:</p>'
          . '<p style="font-size:12px;color:rgba(255,248,232,.35);text-align:center;word-break:break-all;margin:0 0 32px">' . htmlspecialchars($magicUrl, ENT_QUOTES) . '</p>'
          . '<hr style="border:none;border-top:1px solid rgba(255,255,255,.08);margin:0 0 24px">'
          . '<p style="font-size:12px;color:rgba(255,248,232,.35);margin:0">If you did not request this sign-in link, you can safely ignore this email. Your vault remains secure. Check your spam folder if the link does not arrive within a minute.</p>'
          . '</div>';

        $textBody = "COG\$ Independence Vault — sign-in link\n\nHello {$firstName},\n\nClick the link below to open your Independence Vault. Expires in 10 minutes, one use only.\n\n{$magicUrl}\n\nIf you did not request this, ignore this email. Check your spam folder if it doesn't arrive within a minute.\n\n— COG\$ of Australia Foundation";

        $linkSent = false;
        try {
            smtpSendEmail($memberEmail, 'Sign in to your COG$ Independence Vault', $htmlBody, $textBody);
            $linkSent = true;
        } catch (Throwable $mailErr) {
            error_log('[magic-link] smtpSendEmail failed: ' . $mailErr->getMessage());
            // Queue fallback
            try {
                queueEmail($db, 'snft_member', (int)$row['id'], $memberEmail, 'login_magic_link',
                    'Sign in to your COG$ Independence Vault',
                    ['magic_url' => $magicUrl, 'name' => $memberName, 'first_name' => $firstName]);
                $linkSent = true;
            } catch (Throwable $qe) {
                error_log('[magic-link] queue also failed: ' . $qe->getMessage());
            }
        }

        if (!$linkSent) {
            // Both email paths failed — log in directly rather than leave member stuck
            createSession($db, 'snft', (int)$row['id'], $memberNumber, true);
            apiSuccess(['authenticated' => true]);
        }

        apiSuccess([
            'otp_required'     => true,
            'delivery_channel' => 'email_link',
            'delivery_hint'    => $maskedEmail,
            'email_hint'       => $maskedEmail,
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

// ── Magic link login token verification ────────────────────────────────────
// Called by the frontend when ?login_token= is found in the URL.
// Validates the token against one_time_tokens, creates a full session.
function handleVerifyLoginToken(): void {
    requireMethod('POST');
    $db   = getDB();
    $body = jsonBody();
    enforceRateLimit($db, 'login');

    $rawToken = trim(sanitize((string)($body['login_token'] ?? '')));
    if (strlen($rawToken) < 32) {
        apiError('Invalid or missing login token.');
    }

    $tokenHash = hash('sha256', $rawToken);

    try {
        $stmt = $db->prepare(
            "SELECT id, purpose FROM one_time_tokens
              WHERE token_hash = ?
                AND used_at   IS NULL
                AND expires_at > UTC_TIMESTAMP()
              LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $token = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[magic-link] verify query failed: ' . $e->getMessage());
        apiError('Verification service unavailable.', 503);
    }

    if (!$token) {
        recordAuthFailure($db, 'login');
        apiError('This sign-in link has expired or already been used. Please sign in again.');
    }

    // Purpose encodes member_number: 'member_login:{member_number}'
    $purposeParts  = explode(':', (string)$token['purpose'], 2);
    if (($purposeParts[0] ?? '') !== 'member_login' || empty($purposeParts[1])) {
        apiError('Invalid token purpose.');
    }
    $memberNumber = $purposeParts[1];

    // Fetch member
    try {
        $mStmt = $db->prepare(
            "SELECT id, member_number FROM snft_memberships
              WHERE member_number = ? LIMIT 1"
        );
        $mStmt->execute([$memberNumber]);
        $member = $mStmt->fetch();
    } catch (Throwable $e) {
        apiError('Member lookup failed.', 503);
    }

    if (!$member) {
        apiError('Member not found.', 404);
    }

    // Mark token used
    try {
        $db->prepare("UPDATE one_time_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?")
           ->execute([(int)$token['id']]);
    } catch (Throwable $e) {
        error_log('[magic-link] could not mark token used: ' . $e->getMessage());
    }

    clearAuthRateLimit($db, 'login');
    createSession($db, 'snft', (int)$member['id'], (string)$member['member_number'], true);
    apiSuccess(['authenticated' => true]);
}
