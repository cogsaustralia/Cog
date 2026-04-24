<?php
declare(strict_types=1);

function mailerEnabled(): bool {
    return MAIL_PROVIDER === 'smtp' && MAIL_FROM_EMAIL !== '' && SMTP_HOST !== '';
}

function queueEmail(PDO $db, string $entityType, int $entityId, string $recipient, string $templateKey, string $subject, array $payload): int {
    try {
        // Only reuse an existing PENDING row.
        // If a prior row is SENT or FAILED, create a fresh resend row so the queue and UI update correctly.
        $stmt = $db->prepare('SELECT id, status FROM email_queue WHERE entity_type = ? AND entity_id = ? AND recipient = ? AND template_key = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$entityType, $entityId, $recipient, $templateKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && strtolower((string)($existing['status'] ?? '')) === 'pending') {
            return (int)$existing['id'];
        }

        $stmt = $db->prepare('INSERT INTO email_queue (entity_type, entity_id, recipient, template_key, subject, payload_json, status, attempt_count, last_error, sent_at) VALUES (?,?,?,?,?,?,"pending",0,NULL,NULL)');
        $stmt->execute([
            $entityType,
            $entityId,
            $recipient,
            $templateKey,
            $subject,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        // email_queue table missing or DB error — never crash the join flow.
        error_log('queueEmail failed: ' . $e->getMessage());
        return 0;
    }
}

function enqueueReservationEmails(PDO $db, string $entityType, int $entityId, array $payload): void {
    if (!mailerEnabled()) {
        return;
    }
    $adminEmail = trim(MAIL_ADMIN_EMAIL);
    // Skip admin alert if admin address is same as FROM (prevents self-send loop
    // that silently drops BOTH the user AND admin emails on some SMTP servers)
    $skipAdmin = ($adminEmail === '' || strtolower($adminEmail) === strtolower(MAIL_FROM_EMAIL));
    if ($entityType === 'snft_member') {
        $subjectUser = 'Welcome to COG$ — your COG$ registration is confirmed';
        $subjectAdmin = 'New COG\$ Partner — SNFT registration recorded';
        queueEmail($db, $entityType, $entityId, (string)$payload['email'], 'snft_user_confirmation', $subjectUser, $payload);
        if (!$skipAdmin) {
            queueEmail($db, $entityType, $entityId, $adminEmail, 'snft_admin_alert', $subjectAdmin, $payload);
        }
        return;
    }
    $subjectUser = 'Welcome to COG$ — your COG$ registration is confirmed';
    $subjectAdmin = 'New COG\$ Business Partner — BNFT registration recorded';
    queueEmail($db, $entityType, $entityId, (string)$payload['email'], 'bnft_user_confirmation', $subjectUser, $payload);
    if (!$skipAdmin) {
        queueEmail($db, $entityType, $entityId, $adminEmail, 'bnft_admin_alert', $subjectAdmin, $payload);
    }
}



function activationTokenSecret(): string {
    $base = (string)(env('ACTIVATION_TOKEN_SECRET', '') ?: env('SMTP_PASSWORD', '') ?: env('ADMIN_BOOTSTRAP_TOKEN', '') ?: 'cogs-activation-fallback');
    return hash('sha256', $base . '|' . SITE_URL . '|' . MAIL_FROM_EMAIL);
}

function buildActivationToken(string $role, string $memberRef, string $email, int $ttlSeconds = 604800): string {
    $payload = [
        'r' => $role,
        'm' => $memberRef,
        'e' => strtolower(trim($email)),
        'x' => time() + max(900, $ttlSeconds),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, activationTokenSecret());
    return $b64 . '.' . $sig;
}

function verifyActivationToken(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !str_contains($token, '.')) return null;
    [$b64, $sig] = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $b64, activationTokenSecret());
    if (!hash_equals($expected, $sig)) return null;
    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($json === false) return null;
    $payload = json_decode($json, true);
    if (!is_array($payload)) return null;
    if (empty($payload['r']) || empty($payload['m']) || empty($payload['e']) || empty($payload['x'])) return null;
    if ((int)$payload['x'] < time()) return null;
    return [
        'role' => strtolower(trim((string)$payload['r'])),
        'member_ref' => trim((string)$payload['m']),
        'email' => strtolower(trim((string)$payload['e'])),
        'expires_at' => (int)$payload['x'],
    ];
}

function processEmailQueue(PDO $db, int $limit = 10): array {
    if (!mailerEnabled()) {
        return ['processed' => 0, 'enabled' => false, 'provider' => MAIL_PROVIDER];
    }
    // Guard: table may not yet exist on fresh installs or before migration.
    try {
        $db->query('SELECT 1 FROM email_queue LIMIT 1');
    } catch (Throwable $e) {
        return ['processed' => 0, 'enabled' => false, 'provider' => MAIL_PROVIDER, 'error' => 'email_queue table missing'];
    }
    $stmt = $db->prepare('SELECT id, entity_type, entity_id, recipient, template_key, subject, payload_json, attempt_count FROM email_queue WHERE status IN ("pending", "failed") ORDER BY id ASC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $processed = 0;
    foreach ($rows as $row) {
        $payload = json_decode((string)$row['payload_json'], true) ?: [];
        try {
            [$html, $text] = renderEmailTemplate((string)$row['template_key'], $payload);
            smtpSendEmail((string)$row['recipient'], (string)$row['subject'], $html, $text);
            $upd = $db->prepare('UPDATE email_queue SET status = "sent", attempt_count = attempt_count + 1, last_error = NULL, sent_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $upd->execute([(int)$row['id']]);
        } catch (Throwable $e) {
            $upd = $db->prepare('UPDATE email_queue SET status = "failed", attempt_count = attempt_count + 1, last_error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $upd->execute([$e->getMessage(), (int)$row['id']]);
        }
        $processed++;
    }
    return ['processed' => $processed, 'enabled' => true, 'provider' => MAIL_PROVIDER];
}

function reservationSummaryLines(array $p): array {
    $lines = [];
    foreach ([
        'reserved_tokens' => 'Reserved COG$',
        'investment_tokens' => 'ASX (investment) COG$',
        'invest_tokens' => 'Investment COG$',
        'donation_tokens' => 'Donation COG$',
        'pay_it_forward_tokens' => 'Pay It Forward COG$',
        'kids_tokens' => 'Kids S-NFT COG$',
        'landholder_tokens' => 'Landholder COG$',
    ] as $k => $label) {
        if (array_key_exists($k, $p) && is_numeric($p[$k]) && (float)$p[$k] > 0) {
            $lines[] = $label . ': ' . number_format((float)$p[$k], 0, '.', ',');
        }
    }
    if (!empty($p['landholder_hectares'])) {
        $lines[] = 'Landholder hectares: ' . rtrim(rtrim(number_format((float)$p['landholder_hectares'], 2, '.', ''), '0'), '.');
    }
    return $lines;
}

function renderEmailTemplate(string $templateKey, array $p): array {
    $site = SITE_URL !== '' ? SITE_URL : 'https://cogsaustralia.org';
    $summary = implode("\n", reservationSummaryLines($p));
    $summaryHtml = nl2br(htmlspecialchars($summary !== '' ? $summary : 'No additional interests recorded.'));
    $walletPath = !empty($p['wallet_path']) ? trim((string)$p['wallet_path'], '/') : '';
    $walletUrl = $walletPath !== '' ? $site . '/' . $walletPath : $site;
    $activationToken = '';
    if ($templateKey === 'snft_user_confirmation' && !empty($p['member_number']) && !empty($p['email'])) {
        $activationToken = buildActivationToken('snft', (string)$p['member_number'], (string)$p['email']);
    }
    if ($templateKey === 'bnft_user_confirmation' && !empty($p['abn']) && !empty($p['email'])) {
        $activationToken = buildActivationToken('bnft', (string)$p['abn'], (string)$p['email']);
    }
    $setupUrl = $walletUrl;
    if ($activationToken !== '') {
        $separator = str_contains($walletUrl, '?') ? '&' : '?';
        $setupUrl .= $separator . 'mode=setup&activation_token=' . rawurlencode($activationToken);
    }
    $foundingNotice = 'This is a founding phase confirmation only. Nothing has been offered or issued. All recorded interests activate on Expansion Day, subject to any regulatory requirements determined by applicable law.';

    // Shared HTML email wrapper — clean, readable in all email clients
    $css = 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';
    $wrapOpen  = '<div style="' . $css . 'background:#0f0d09;color:#f0e8d6;max-width:600px;margin:0 auto;padding:0;">';
    $headerBar = '<div style="background:#181108;border-bottom:3px solid #c8901a;padding:1.25rem 1.5rem;">'
               . '<div style="font-size:18px;font-weight:700;color:#f0b429;letter-spacing:.01em;">COG$ Australia</div>'
               . '<div style="font-size:11px;color:#9a8a74;margin-top:2px;">Community stewardship of Australia\'s resources</div>'
               . '</div>';
    $body      = '<div style="padding:1.5rem 1.5rem .5rem;">';
    $footerBar = '</div><div style="background:#0a0806;border-top:1px solid rgba(255,255,255,.08);padding:1rem 1.5rem;">'
               . '<div style="font-size:10px;color:#6b5c44;line-height:1.8;">'
               . 'The Trustee for COGS of Australia Foundation Hybrid Trust &nbsp;·&nbsp; ABN: 61 734 327 831<br>'
               . 'C/- Drake Village Resource Centre, Drake Village NSW 2469 &nbsp;·&nbsp; members@cogsaustralia.org<br>'
               . $foundingNotice
               . '</div></div>';
    $wrapClose = '</div>';

    $h2Style   = 'font-size:20px;font-weight:700;color:#f0e8d6;margin:0 0 1rem;';
    $h3Style   = 'font-size:15px;font-weight:600;color:#f0e8d6;margin:1.25rem 0 .5rem;';
    $pStyle    = 'font-size:14px;color:#d4c9b8;line-height:1.65;margin:.5rem 0;';
    $labelStyle= 'font-size:11px;color:#9a8a74;text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px;';
    $valStyle  = 'font-size:15px;font-weight:600;color:#f0e8d6;';
    $boxStyle  = 'background:#181108;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:1rem 1.1rem;margin:.75rem 0;';
    $payStyle  = 'background:rgba(200,144,26,.08);border:1px solid rgba(200,144,26,.28);border-radius:10px;padding:1rem 1.1rem;margin:.75rem 0;';
    $btnStyle  = 'display:inline-block;background:#c8901a;color:#0f0d09;font-weight:700;text-decoration:none;padding:.75rem 1.5rem;border-radius:8px;font-size:14px;margin:.75rem 0;';
    $urlStyle  = 'font-size:11px;color:#9a8a74;word-break:break-all;margin:.5rem 0;';
    $noticeStyle='font-size:11px;color:#9a8a74;line-height:1.7;border-top:1px solid rgba(255,255,255,.06);margin-top:1.25rem;padding-top:.75rem;';

    // Payment details (SNFT)
    $snftPayBlock = '<div style="' . $payStyle . '">'
        . '<div style="' . $labelStyle . '">Complete your $4 partnership contribution</div>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:.5rem;font-size:13px;">'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">PayID</td><td style="color:#f0e8d6;font-weight:600;text-align:right;">0494 578 706</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Bank</td><td style="color:#f0e8d6;text-align:right;">Macquarie Bank</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Account name</td><td style="color:#f0e8d6;text-align:right;font-size:12px;">The Trustee for COGS of Australia Foundation Hybrid Trust</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">BSB</td><td style="color:#f0e8d6;font-weight:600;text-align:right;">182-182</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Account</td><td style="color:#f0e8d6;font-weight:600;text-align:right;">035 249 275</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Amount</td><td style="color:#f0b429;font-weight:700;text-align:right;">$4.00</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Reference</td><td style="color:#f0e8d6;font-weight:600;text-align:right;font-family:monospace;">' . htmlspecialchars((string)($p['member_number'] ?? 'your Partner number')) . '</td></tr>'
        . '</table>'
        . '<div style="font-size:11px;color:#9a8a74;margin-top:.65rem;line-height:1.6;">Use your Partner number as the payment reference. Once received, your vault becomes fully active.</div>'
        . '</div>';

    // Payment details (BNFT)
    $bnftPayBlock = '<div style="' . $payStyle . '">'
        . '<div style="' . $labelStyle . '">Complete your $40 business partnership contribution</div>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:.5rem;font-size:13px;">'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">PayID</td><td style="color:#f0e8d6;font-weight:600;text-align:right;">0494 578 706</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Bank</td><td style="color:#f0e8d6;text-align:right;">Macquarie Bank</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Account name</td><td style="color:#f0e8d6;text-align:right;font-size:12px;">The Trustee for COGS of Australia Foundation Hybrid Trust</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">BSB</td><td style="color:#f0e8d6;font-weight:600;text-align:right;">182-182</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Account</td><td style="color:#f0e8d6;font-weight:600;text-align:right;">035 249 275</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Amount</td><td style="color:#f0b429;font-weight:700;text-align:right;">$40.00</td></tr>'
        . '<tr><td style="color:#9a8a74;padding:.2rem 0;">Reference</td><td style="color:#f0e8d6;font-weight:600;text-align:right;font-family:monospace;">' . htmlspecialchars((string)($p['abn'] ?? 'your ABN')) . '</td></tr>'
        . '</table>'
        . '<div style="font-size:11px;color:#9a8a74;margin-top:.65rem;line-height:1.6;">Use your ABN as the payment reference. Once received, your Business Vault becomes fully active.</div>'
        . '</div>';

    return match ($templateKey) {
        'snft_user_confirmation' => (function() use ($p, $setupUrl, $foundingNotice, $site, $activationToken) {
// ── NEW snft_user_confirmation template ──────────────────────────────────────
// Table-based HTML, light background, warm copy, get-involved section
// All styles inline for Gmail/Outlook compatibility

$firstName = htmlspecialchars(explode(' ', trim((string)($p['full_name'] ?? 'there')))[0]);
$memberNum = htmlspecialchars((string)($p['member_number'] ?? ''));
$memberNumDisplay = implode(' ', str_split($memberNum, 4));
$resValue  = number_format((float)($p['reservation_value'] ?? 0), 2);
$kidsTokens = (int)($p['kids_tokens'] ?? 0);
$dueToday = 4 + $kidsTokens;
$dueTodayDisplay = number_format((float)$dueToday, 2);
$dueTodayNote = $kidsTokens > 0 ? ' and $1 for each Kids SNFT selected.' : '.';

// Colour palette
$gold   = '#b07d1a';
$goldLt = '#c8901a';
$dark   = '#1a1208';
$cream  = '#fdf8f0';
$sand   = '#f5edd8';
$muted  = '#4a3828';
$body   = '#1e1208';
$green  = '#2d6e45';
$greenBg= '#edf7f1';
$greenBd= '#a8d5b8';

// Interest summary rows
$interestRows = '';
foreach ([
    'investment_tokens'     => 'ASX (investment) COG$',
    'donation_tokens'       => 'Community donation COG$',
    'pay_it_forward_tokens' => 'Pay It Forward COG$',
    'landholder_tokens'     => 'Land-linked voice COG$',
] as $k => $label) {
    $val = (int)($p[$k] ?? 0);
    if ($val > 0) {
        $interestRows .= '<tr>'
            . '<td style="padding:5px 0;font-size:13px;font-weight:500;color:' . $muted . '">' . $label . '</td>'
            . '<td style="padding:5px 0;font-size:13px;color:' . $body . ';font-weight:600;text-align:right">' . number_format($val) . '</td>'
            . '</tr>';
    }
}
if (!empty($p['landholder_hectares']) && (float)$p['landholder_hectares'] > 0) {
    $ha = rtrim(rtrim(number_format((float)$p['landholder_hectares'], 2), '0'), '.');
    $interestRows .= '<tr>'
        . '<td style="padding:5px 0;font-size:13px;font-weight:500;color:' . $muted . '">Landholder hectares</td>'
        . '<td style="padding:5px 0;font-size:13px;color:' . $body . ';font-weight:600;text-align:right">' . $ha . ' ha</td>'
        . '</tr>';
}

$html = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Welcome to COG$</title></head>
<body style="margin:0;padding:0;background:#ede8df;font-family:Georgia,serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#ede8df">
<tr><td align="center" style="padding:32px 16px">

  <!-- Outer card -->
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)">

    <!-- Gold header bar -->
    <tr><td style="background:' . $dark . ';padding:28px 32px 24px;border-bottom:3px solid ' . $goldLt . '">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td>
            <div style="font-size:22px;font-weight:bold;color:' . $goldLt . ';letter-spacing:.02em;font-family:Georgia,serif">COG$ Australia</div>
            <div style="font-size:12px;color:#6a5a48;margin-top:4px;font-family:Arial,sans-serif">Community stewardship of Australia&#39;s resources</div>
          </td>
          <td align="right" style="vertical-align:top">
            <div style="display:inline-block;background:rgba(200,144,26,.15);border:1px solid rgba(200,144,26,.4);border-radius:6px;padding:4px 10px;font-size:10px;font-weight:bold;color:' . $goldLt . ';letter-spacing:.08em;font-family:Arial,sans-serif;text-transform:uppercase">Founding Partner</div>
          </td>
        </tr>
      </table>
    </td></tr>

    <!-- Warm welcome -->
    <tr><td style="padding:36px 32px 0;background:#ffffff">
      <div style="font-size:28px;font-weight:bold;color:' . $dark . ';line-height:1.2;font-family:Georgia,serif">
        You&#39;re in,<br>' . $firstName . '.
      </div>
      <p style="font-size:15px;color:' . $body . ';line-height:1.75;margin:16px 0 0;font-family:Arial,sans-serif">
        Your COG$ partnership registration has been recorded. You are now a Partner in a community joint venture that is working to give ordinary Australians a real, legal, enforceable voice in how this country&#39;s natural resources are managed and who shares in the wealth they create.
      </p>
      <p style="font-size:15px;color:' . $body . ';line-height:1.75;margin:12px 0 0;font-family:Arial,sans-serif">
        This is the beginning of something that is built to last for generations &#8212; and you are one of the people who started it.
      </p>
    </td></tr>

    <!-- Member number block -->
    <tr><td style="padding:24px 32px 0">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:' . $dark . ';border-radius:10px;overflow:hidden">
        <tr><td style="padding:20px 24px">
          <div style="font-size:11px;font-weight:bold;color:#9a8a74;text-transform:uppercase;letter-spacing:.1em;font-family:Arial,sans-serif">Your permanent Partner number</div>
          <div style="font-size:24px;font-weight:bold;color:' . $goldLt . ';letter-spacing:.12em;margin:8px 0 4px;font-family:Courier New,monospace">' . $memberNumDisplay . '</div>
          <div style="font-size:12px;color:#4a3828;font-family:Arial,sans-serif">Keep this safe &#8212; it is your permanent identity in the COG$ community</div>
        </td></tr>
      </table>
    </td></tr>

    <!-- Due today + interests side by side -->
    <tr><td style="padding:16px 32px 0">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr valign="top">
          <!-- Due today -->
          <td width="48%" style="background:#fff8ed;border:1px solid #e8c97a;border-radius:10px;padding:16px 18px">
            <div style="font-size:11px;font-weight:bold;color:' . $goldLt . ';text-transform:uppercase;letter-spacing:.08em;font-family:Arial,sans-serif">Due today</div>
            <div style="font-size:32px;font-weight:bold;color:' . $goldLt . ';margin:6px 0 4px;font-family:Georgia,serif">$' . $dueTodayDisplay . '</div>
            <div style="font-size:12px;color:' . $muted . ';line-height:1.5;font-family:Arial,sans-serif">Partnership contribution plus $1 for each Kids SNFT selected in this join flow. Bank transfer details below.</div>
          </td>
          <td width="4%"></td>
          <!-- Interests -->
          <td width="48%" style="background:#f8f6f2;border:1px solid #ddd5c5;border-radius:10px;padding:16px 18px">
            <div style="font-size:11px;font-weight:700;color:' . $gold . ';text-transform:uppercase;letter-spacing:.08em;font-family:Arial,sans-serif">Your recorded interests</div>
            <div style="font-size:11px;color:' . $muted . ';font-style:italic;margin:2px 0 10px;font-family:Arial,sans-serif">No-obligation &#8212; not payable today</div>
            <table width="100%" cellpadding="0" cellspacing="0">' . ($interestRows ?: '<tr><td style="font-size:13px;color:' . $muted . ';padding:4px 0;font-family:Arial,sans-serif">Personal partnership only</td></tr>') . '
            </table>
          </td>
        </tr>
      </table>
    </td></tr>

    <!-- Divider -->
    <tr><td style="padding:24px 32px 0">
      <hr style="border:none;border-top:1px solid #e8e0d0;margin:0">
    </td></tr>


    <!-- Payment: Stripe primary -->
    <tr><td style="padding:20px 32px 0">
      <div style="font-size:16px;font-weight:bold;color:' . $dark . ';margin-bottom:4px;font-family:Georgia,serif">Complete your joining payment</div>
      <p style="font-size:13px;color:' . $muted . ';margin:0 0 14px;font-family:Arial,sans-serif">The fastest way to activate your vault is to pay securely online. Click below to set up your vault and pay via Stripe — your partnership is confirmed within seconds.</p>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr><td align="center" style="padding:8px 0 16px">
          <a href="' . htmlspecialchars($setupUrl) . '" style="display:inline-block;background:#635bff;color:#ffffff;font-weight:bold;text-decoration:none;padding:14px 36px;border-radius:8px;font-size:15px;font-family:Arial,sans-serif;letter-spacing:.01em">Pay $' . $dueTodayDisplay . ' via Stripe &#8594;</a>
        </td></tr>
      </table>
      <p style="font-size:12px;color:' . $muted . ';text-align:center;margin:0 0 16px;font-family:Arial,sans-serif">You&#8217;ll set your vault password first, then pay securely inside your vault.</p>

      <!-- Bank transfer fallback -->
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f6f2;border-radius:8px;overflow:hidden;border:1px solid #e8e0d0">
        <tr style="background:#f7f3ec"><td colspan="2" style="padding:10px 16px;font-size:12px;font-weight:bold;color:' . $goldLt . ';text-transform:uppercase;letter-spacing:.06em;font-family:Arial,sans-serif">Already paid or prefer bank transfer?</td></tr>
        <tr><td colspan="2" style="padding:6px 16px 10px;font-size:12px;color:' . $muted . ';font-family:Arial,sans-serif;line-height:1.5">If you&#8217;ve already completed payment via Stripe, you can skip this section. Otherwise, use the details below to pay by direct bank transfer.</td></tr>
        <tr><td style="padding:6px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif;width:40%">PayID</td><td style="padding:6px 16px;font-size:13px;font-weight:bold;color:' . $body . ';font-family:Arial,sans-serif">0494 578 706</td></tr>
        <tr style="background:#f7f3ec"><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Bank</td><td style="padding:6px 16px;font-size:13px;color:' . $body . ';font-family:Arial,sans-serif">Macquarie Bank</td></tr>
        <tr><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Account name</td><td style="padding:6px 16px;font-size:12px;color:' . $body . ';font-family:Arial,sans-serif">The Trustee for COGS of Australia Foundation Hybrid Trust</td></tr>
        <tr style="background:#f7f3ec"><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">BSB</td><td style="padding:6px 16px;font-size:13px;font-weight:bold;color:' . $body . ';font-family:Arial,sans-serif">182-182</td></tr>
        <tr><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Account</td><td style="padding:6px 16px;font-size:13px;font-weight:bold;color:' . $body . ';font-family:Arial,sans-serif">035 249 275</td></tr>
        <tr style="background:#f7f3ec"><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Amount</td><td style="padding:6px 16px;font-size:14px;font-weight:bold;color:' . $goldLt . ';font-family:Georgia,serif">$' . $dueTodayDisplay . '</td></tr>
        <tr><td style="padding:6px 16px 10px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Reference</td><td style="padding:6px 16px 10px;font-size:13px;font-weight:bold;color:' . $body . ';font-family:Courier New,monospace">' . $memberNum . '</td></tr>
      </table>
    </td></tr>

    <!-- Vault access: setup + login buttons -->
    <tr><td style="padding:24px 32px 0">
      <div style="font-size:16px;font-weight:bold;color:' . $dark . ';margin-bottom:6px;font-family:Georgia,serif">Your Independence Vault</div>
      <p style="font-size:13px;color:' . $muted . ';margin:0 0 14px;line-height:1.6;font-family:Arial,sans-serif">Your vault is where you manage your partnership access, review COG$ reservations, vote on governance proposals, and see live announcements. Set your password to activate it &#8212; or sign in if you&#8217;ve already set up.</p>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td align="center" style="padding:4px 0 8px">
            <a href="' . htmlspecialchars($setupUrl) . '" style="display:inline-block;background:' . $goldLt . ';color:#ffffff;font-weight:bold;text-decoration:none;padding:14px 28px;border-radius:8px;font-size:14px;font-family:Arial,sans-serif">First time? Set up your vault &#8594;</a>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:0 0 8px">
            <a href="' . htmlspecialchars($site . '/wallets/member.html') . '" style="display:inline-block;background:#f8f6f2;color:' . $dark . ';font-weight:600;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:13px;font-family:Arial,sans-serif;border:1px solid #ddd5c5">Already set up? Member Login &#8594;</a>
          </td>
        </tr>
      </table>
      <p style="font-size:11px;color:#aaa;line-height:1.5;margin:4px 0 0;font-family:Arial,sans-serif">Setup link works for 7 days. If the buttons above do not work, visit <strong>cogsaustralia.org</strong> and click <em>Member Login</em>.</p>
    </td></tr>

    <!-- Explore links -->
    <tr><td style="padding:24px 32px 0">
      <div style="font-size:15px;font-weight:bold;color:' . $dark . ';margin-bottom:12px;font-family:Georgia,serif">Explore COG$</div>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td width="50%" style="padding:6px 0;font-family:Arial,sans-serif;font-size:13px">
            <a href="' . htmlspecialchars($site) . '/partners/" style="color:' . $goldLt . ';text-decoration:none;font-weight:600">&#9679; Community</a>
          </td>
          <td width="50%" style="padding:6px 0;font-family:Arial,sans-serif;font-size:13px">
            <a href="' . htmlspecialchars($site) . '/faq/" style="color:' . $goldLt . ';text-decoration:none;font-weight:600">&#9679; FAQ</a>
          </td>
        </tr>
        <tr>
          <td style="padding:6px 0;font-family:Arial,sans-serif;font-size:13px">
            <a href="' . htmlspecialchars($site) . '/vision/" style="color:' . $goldLt . ';text-decoration:none;font-weight:600">&#9679; Our Vision</a>
          </td>
          <td style="padding:6px 0;font-family:Arial,sans-serif;font-size:13px">
            <a href="' . htmlspecialchars($site) . '/how-it-works/" style="color:' . $goldLt . ';text-decoration:none;font-weight:600">&#9679; How It Works</a>
          </td>
        </tr>
        <tr>
          <td style="padding:6px 0;font-family:Arial,sans-serif;font-size:13px">
            <a href="' . htmlspecialchars($site) . '/bw_paper/" style="color:' . $goldLt . ';text-decoration:none;font-weight:600">&#9679; Black &amp; White Paper</a>
          </td>
          <td style="padding:6px 0;font-family:Arial,sans-serif;font-size:13px">
            <a href="' . htmlspecialchars($site) . '/tell-me-more/" style="color:' . $goldLt . ';text-decoration:none;font-weight:600">&#9679; Tell Me More</a>
          </td>
        </tr>
      </table>
    </td></tr>

    <!-- Get involved -->
    <tr><td style="padding:24px 32px 0">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:' . $greenBg . ';border:1px solid ' . $greenBd . ';border-radius:10px">
        <tr><td style="padding:20px 22px">
          <div style="font-size:15px;font-weight:bold;color:' . $green . ';margin-bottom:10px;font-family:Georgia,serif">&#127775; You are building this &#8212; not just joining it</div>
          <p style="font-size:13px;color:#2d4a38;line-height:1.7;margin:0 0 12px;font-family:Arial,sans-serif">
            COG$ is a community joint venture business partnership founded in equity law. The trust deed, the source code, the financials, and every governance proposal are open to every Partner at all times. If you have a skill to contribute &#8212; legal, technical, financial, creative, or community &#8212; there is a place for you behind the scenes.
          </p>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td width="50%" style="font-size:12px;color:#2d4a38;padding:4px 0;font-family:Arial,sans-serif">&#9679; Read and review the trust deed</td>
              <td width="50%" style="font-size:12px;color:#2d4a38;padding:4px 0;font-family:Arial,sans-serif">&#9679; Comment on governance proposals</td>
            </tr>
            <tr>
              <td style="font-size:12px;color:#2d4a38;padding:4px 0;font-family:Arial,sans-serif">&#9679; Inspect the source code</td>
              <td style="font-size:12px;color:#2d4a38;padding:4px 0;font-family:Arial,sans-serif">&#9679; Propose improvements</td>
            </tr>
            <tr>
              <td style="font-size:12px;color:#2d4a38;padding:4px 0;font-family:Arial,sans-serif">&#9679; Contribute your skills directly</td>
              <td style="font-size:12px;color:#2d4a38;padding:4px 0;font-family:Arial,sans-serif">&#9679; Vote on what matters at AGMs</td>
            </tr>
          </table>
          <p style="font-size:13px;color:#2d4a38;margin:12px 0 0;font-family:Arial,sans-serif">Everything is in the Partner library. Nothing is hidden. This is your joint venture &#8212; help us build it well.</p>
        </td></tr>
      </table>
    </td></tr>

    <!-- Founding phase notice -->
    <tr><td style="padding:20px 32px 28px">
      <p style="font-size:11px;color:#b0a090;line-height:1.7;margin:0;border-top:1px solid #e8e0d0;padding-top:16px;font-family:Arial,sans-serif">
        <strong style="color:#8a7a6a">Founding phase notice:</strong> This is a founding phase confirmation only. Your personal partnership and Partner number are real. Any Kids SNFT selected are included in today&#8217;s joining amount. All recorded COG$ interests remain no-obligation future intentions and will activate on Expansion Day, subject to any regulatory requirements determined by applicable law. Nothing else has been offered or issued.
      </p>
    </td></tr>

    <!-- Footer -->
    <tr><td style="background:' . $dark . ';padding:20px 32px;border-top:2px solid ' . $goldLt . '">
      <div style="font-size:11px;color:#6b5c44;line-height:1.9;font-family:Arial,sans-serif">
        <strong style="color:#9a8a74">COGS of Australia Foundation Hybrid Trust</strong><br>
        ABN: 61 734 327 831 &nbsp;&#183;&nbsp; Drake Village NSW 2469<br>
        members@cogsaustralia.org &nbsp;&#183;&nbsp; cogsaustralia.org<br>
        Community joint venture business partnership &#183; Partner operated &#183; Trustee administered
      </div>
    </td></tr>

  </table>
</td></tr>
</table>
</body></html>';

// Plain text version
$plain = "You're in, {$firstName}.\n\n"
    . "Your COG\$ partnership registration has been recorded. You are now a Partner in the COGS of Australia Foundation community joint venture built to give ordinary Australians a real, legal voice in how Australia's natural resources are managed.\n\n"
    . "YOUR MEMBER NUMBER\n"
    . str_repeat("-", 40) . "\n"
    . $memberNumDisplay . "\n"
    . "Keep this safe -- your permanent identity in the COG\$ community.\n\n"
    . "PAY NOW VIA STRIPE (FASTEST)\n"
    . str_repeat("-", 40) . "\n"
    . "Set up your vault and pay securely online:\n"
    . $site . "/wallets/member.html\n"
    . "Your partnership is confirmed within seconds.\n\n"
    . "ALREADY PAID? PREFER BANK TRANSFER?\n"
    . str_repeat("-", 40) . "\n"
    . "If you have already paid via Stripe, skip this section.\n"
    . "Otherwise, use the details below for bank transfer:\n"
    . "PayID: 0494 578 706 (fastest)\n"
    . "Bank: Macquarie Bank\n"
    . "Account name: The Trustee for COGS of Australia Foundation Hybrid Trust\n"
    . "BSB: 182-182\n"
    . "Account: 035 249 275\n"
    . "Amount: $" . $dueTodayDisplay . "\n"
    . "Reference: " . (string)($p['member_number'] ?? '') . "\n\n"
    . "YOUR INDEPENDENCE VAULT\n"
    . str_repeat("-", 40) . "\n"
    . "Set up or sign in:\n"
    . $site . "/wallets/member.html\n\n"
    . "EXPLORE COG$\n"
    . str_repeat("-", 40) . "\n"
    . "Community:         " . $site . "/partners/\n"
    . "FAQ:               " . $site . "/faq/\n"
    . "Our Vision:        " . $site . "/vision/\n"
    . "How It Works:      " . $site . "/how-it-works/\n"
    . "Black & White Paper: " . $site . "/bw_paper/\n"
    . "Tell Me More:      " . $site . "/tell-me-more/\n\n"
    . "GET INVOLVED\n"
    . str_repeat("-", 40) . "\n"
    . "COG\$ is a community joint venture business partnership founded in equity law. The trust deed,\n"
    . "source code, financials and governance proposals are open to every Partner.\n"
    . "Read, critique, propose improvements, or contribute your skills.\n"
    . "Everything is in the Partner library -- nothing is hidden.\n\n"
    . "FOUNDING PHASE NOTICE\n"
    . str_repeat("-", 40) . "\n"
    . "This is a founding phase confirmation only. Your \$4 partnership contribution and Partner number\n"
    . "are real. All other interests recorded are no-obligation and will not activate\n"
    . "subject to any regulatory requirements determined by applicable law.\n\n"
    . "COGS of Australia Foundation Hybrid Trust | ABN: 61 734 327 831\n"
    . "Drake Village NSW 2469 | members@cogsaustralia.org\n";

return [$html, $plain];

        })(),
        'snft_admin_alert' => [
            // HTML email
            $wrapOpen . $headerBar . $body
            . '<h2 style="' . $h2Style . '">New COG$ Partner — SNFT registration</h2>'
            . '<div style="' . $boxStyle . '">'
            . '<div style="' . $labelStyle . '">Partner details</div>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:.5rem;">'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;width:38%;">Full name</td><td style="color:#f0e8d6;font-weight:600;">' . htmlspecialchars((string)($p['full_name'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Member number</td><td style="color:#f0b429;font-weight:700;font-family:monospace;">' . htmlspecialchars((string)($p['member_number'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Email</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['email'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Mobile</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['mobile'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Date of birth</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['date_of_birth'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Address</td><td style="color:#f0e8d6;">' . htmlspecialchars(trim((string)($p['street_address'] ?? '') . ' ' . (string)($p['suburb'] ?? '') . ' ' . (string)($p['state'] ?? '') . ' ' . (string)($p['postcode'] ?? ''))) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">State</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['state'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Referral code</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['referral_code'] ?? '—')) . '</td></tr>'
            . '</table></div>'
            . '<div style="' . $boxStyle . '">'
            . '<div style="' . $labelStyle . '">Reservation</div>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:.5rem;">'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;width:38%;">Value</td><td style="color:#f0b429;font-weight:700;">$' . number_format((float)($p['reservation_value'] ?? 0), 2) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Notice version</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['reservation_notice_version'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Accepted at</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['reservation_notice_accepted_at'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Due today</td><td style="color:#f0b429;font-weight:700;">' . htmlspecialchars((string)($p['joining_fee_due_now'] ?? '$4.00')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">PayID</td><td style="color:#f0e8d6;">0494 578 706</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Bank</td><td style="color:#f0e8d6;">Macquarie Bank</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">BSB</td><td style="color:#f0e8d6;">182-182</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Account</td><td style="color:#f0e8d6;">035 249 275</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Payment reference</td><td style="color:#f0e8d6;font-family:monospace;">' . htmlspecialchars((string)($p['member_number'] ?? '')) . '</td></tr>'
            . '</table>'
            . ($summary !== '' ? '<div style="' . $labelStyle . 'margin-top:.75rem;">Token breakdown</div><div style="font-size:13px;color:#d4c9b8;line-height:1.8;">' . $summaryHtml . '</div>' : '')
            . '</div>'
            . (!empty($p['additional_info']) ? '<div style="' . $boxStyle . '"><div style="' . $labelStyle . '">Additional information from member</div><div style="font-size:13px;color:#d4c9b8;line-height:1.65;margin-top:.4rem;">' . nl2br(htmlspecialchars((string)$p['additional_info'])) . '</div></div>' : '')
            . '<div style="font-size:11px;color:#6b5c44;margin-top:1rem;font-family:monospace;">' . htmlspecialchars((string)($p['trace_line'] ?? '')) . '</div>'
            . $footerBar . $wrapClose,
            // Plain text
            "New COG\$ Partner — SNFT registration\n\n"
            . "Full name: " . (($p['full_name'] ?? '')) . "\n"
            . "Member number: " . (($p['member_number'] ?? '')) . "\n"
            . "Email: " . (($p['email'] ?? '')) . "\n"
            . "Mobile: " . (($p['mobile'] ?? '')) . "\n"
            . "Date of birth: " . (($p['date_of_birth'] ?? '')) . "\n"
            . "Address: " . trim(((string)($p['street_address'] ?? '')) . ' ' . ((string)($p['suburb'] ?? '')) . ' ' . ((string)($p['state'] ?? '')) . ' ' . ((string)($p['postcode'] ?? ''))) . "\n"
            . "Referral code: " . (($p['referral_code'] ?? '')) . "\n"
            . "Additional info: " . (($p['additional_info'] ?? '')) . "\n\n"
            . "Reservation value: \$" . number_format((float)($p['reservation_value'] ?? 0), 2) . "\n"
            . ($summary !== '' ? $summary . "\n" : '')
            . "Notice: " . (($p['reservation_notice_version'] ?? '')) . "\n"
            . "Accepted at: " . (($p['reservation_notice_accepted_at'] ?? '')) . "\n"
            . "Due today: " . (($p['joining_fee_due_now'] ?? '$4.00')) . "\n"
            . "PayID: 0494 578 706\n"
            . "Bank: Macquarie Bank\n"
            . "BSB: 182-182\n"
            . "Account: 035 249 275\n"
            . "Payment reference: " . (($p['member_number'] ?? '')) . "\n\n"
            . (($p['trace_line'] ?? '')),
        ],
        'bnft_user_confirmation' => (function() use ($p, $setupUrl, $foundingNotice) {
// Business email — mirrors the SNFT template, adapted for ABN/business context
$contactName = htmlspecialchars(explode(' ', trim((string)($p['contact_name'] ?? 'there')))[0]);
$legalName   = htmlspecialchars((string)($p['legal_name'] ?? 'your business'));
$tradingName = htmlspecialchars((string)($p['trading_name'] ?? ''));
$displayName = $tradingName !== '' ? $tradingName : $legalName;
$abn         = htmlspecialchars((string)($p['abn'] ?? ''));
$abnDisplay  = preg_replace('/(\d{2})(\d{3})(\d{3})(\d{3})/', '$1 $2 $3 $4', $abn);
$resValue    = number_format((float)($p['reservation_value'] ?? 40), 2);

$gold    = '#b07d1a'; $goldLt = '#c8901a'; $dark = '#1a1208';
$body_c  = '#1e1208'; $muted  = '#4a3828'; $green = '#2d6e45';
$greenBg = '#edf7f1'; $greenBd = '#a8d5b8';

$interestRows = '';
foreach ([
    'reserved_tokens'        => 'Business partnership COG$',
    'invest_tokens'          => 'Investment stake COG$',
    'investment_tokens'      => 'Investment stake COG$',
    'donation_tokens'        => 'Community donation COG$',
    'pay_it_forward_tokens'  => 'Pay It Forward COG$',
] as $k => $label) {
    $val = (int)($p[$k] ?? 0);
    if ($val > 0) {
        $interestRows .= '<tr>'
            . '<td style="padding:5px 0;font-size:13px;font-weight:500;color:' . $muted . '">' . $label . '</td>'
            . '<td style="padding:5px 0;font-size:13px;color:' . $body_c . ';font-weight:600;text-align:right">' . number_format($val) . '</td>'
            . '</tr>';
    }
}

$html = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Welcome to COG$</title></head>
<body style="margin:0;padding:0;background:#ede8df;font-family:Georgia,serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#ede8df">
<tr><td align="center" style="padding:32px 16px">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)">

    <tr><td style="background:' . $dark . ';padding:28px 32px 24px;border-bottom:3px solid ' . $goldLt . '">
      <table width="100%" cellpadding="0" cellspacing="0"><tr>
        <td><div style="font-size:22px;font-weight:bold;color:' . $goldLt . ';font-family:Georgia,serif">COG$ Australia</div>
        <div style="font-size:12px;color:#6a5a48;margin-top:4px;font-family:Arial,sans-serif">Community stewardship of Australia&#39;s resources</div></td>
        <td align="right" style="vertical-align:top">
          <div style="display:inline-block;background:rgba(200,144,26,.15);border:1px solid rgba(200,144,26,.4);border-radius:6px;padding:4px 10px;font-size:10px;font-weight:bold;color:' . $goldLt . ';letter-spacing:.08em;font-family:Arial,sans-serif;text-transform:uppercase">Business Partner</div>
        </td>
      </tr></table>
    </td></tr>

    <tr><td style="padding:36px 32px 0;background:#ffffff">
      <div style="font-size:28px;font-weight:bold;color:' . $dark . ';line-height:1.2;font-family:Georgia,serif">Your business is in,<br>' . $contactName . '.</div>
      <p style="font-size:15px;color:' . $body_c . ';line-height:1.75;margin:16px 0 0;font-family:Arial,sans-serif">
        <strong>' . $displayName . '</strong> is now permanently recorded as a COG$ Business Partner. Your ABN is your business Partner identifier &#8212; clean, verifiable, and linked for the life of the structure.
      </p>
      <p style="font-size:15px;color:' . $body_c . ';line-height:1.75;margin:12px 0 0;font-family:Arial,sans-serif">
        Your business joins a growing network of Australian enterprises that believe the wealth created from this country&#39;s natural resources should serve the communities above it.
      </p>
    </td></tr>

    <tr><td style="padding:24px 32px 0">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:' . $dark . ';border-radius:10px;overflow:hidden">
        <tr><td style="padding:20px 24px">
          <div style="font-size:11px;font-weight:bold;color:#9a8a74;text-transform:uppercase;letter-spacing:.1em;font-family:Arial,sans-serif">Your Business Partner identifier (ABN)</div>
          <div style="font-size:22px;font-weight:bold;color:' . $goldLt . ';letter-spacing:.06em;margin:8px 0 4px;font-family:Courier New,monospace">' . $abnDisplay . '</div>
          <div style="font-size:11px;color:#4a3828;font-family:Arial,sans-serif;margin-bottom:8px">Use this with your business email to log in and recover vault access</div>
          <div style="display:inline-block;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:3px 8px;font-size:10px;color:#9a8a74;font-family:Arial,sans-serif">BNFT &#183; No governance vote &#183; ABN-linked</div>
        </td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:16px 32px 0">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr valign="top">
          <td width="48%" style="background:#fff8ed;border:1px solid #e8c97a;border-radius:10px;padding:16px 18px">
            <div style="font-size:11px;font-weight:bold;color:' . $goldLt . ';text-transform:uppercase;letter-spacing:.08em;font-family:Arial,sans-serif">Due today</div>
            <div style="font-size:32px;font-weight:bold;color:' . $goldLt . ';margin:6px 0 4px;font-family:Georgia,serif">$40.00</div>
            <div style="font-size:12px;color:' . $muted . ';line-height:1.5;font-family:Arial,sans-serif">Business partnership contribution only. Your selected business COG$ are recorded separately and are not payable today.</div>
          </td>
          <td width="4%"></td>
          <td width="48%" style="background:#f8f6f2;border:1px solid #ddd5c5;border-radius:10px;padding:16px 18px">
            <div style="font-size:11px;font-weight:700;color:' . $gold . ';text-transform:uppercase;letter-spacing:.08em;font-family:Arial,sans-serif">Your recorded interests</div>
            <div style="font-size:11px;color:' . $muted . ';font-style:italic;margin:2px 0 10px;font-family:Arial,sans-serif">No-obligation &#8212; not payable today</div>
            <table width="100%" cellpadding="0" cellspacing="0">' . ($interestRows ?: '<tr><td style="font-size:13px;color:' . $muted . ';padding:4px 0;font-family:Arial,sans-serif">Business partnership only</td></tr>') . '</table>
          </td>
        </tr>
      </table>
    </td></tr>

    <tr><td style="padding:24px 32px 0"><hr style="border:none;border-top:1px solid #e8e0d0;margin:0"></td></tr>

    <tr><td style="padding:20px 32px 0">
      <div style="font-size:16px;font-weight:bold;color:' . $dark . ';margin-bottom:4px;font-family:Georgia,serif">Complete your $40 bank transfer</div>
      <p style="font-size:13px;color:' . $muted . ';margin:0 0 14px;font-family:Arial,sans-serif">Use PayID for the fastest activation, or BSB + account if you prefer. Use your ABN as the payment reference.</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f6f2;border-radius:8px;overflow:hidden">
        <tr style="background:#f7f3ec"><td colspan="2" style="padding:10px 16px;font-size:12px;font-weight:bold;color:' . $goldLt . ';text-transform:uppercase;letter-spacing:.06em;font-family:Arial,sans-serif">Bank transfer details</td></tr>
        <tr><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-weight:600;font-family:Arial,sans-serif;width:40%">PayID</td><td style="padding:8px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:Arial,sans-serif">0494 578 706</td></tr>
        <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Bank</td><td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif">Macquarie Bank</td></tr>
        <tr><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Account name</td><td style="padding:8px 16px;font-size:12px;color:' . $body_c . ';font-family:Arial,sans-serif">The Trustee for COGS of Australia Foundation Hybrid Trust</td></tr>
        <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">BSB</td><td style="padding:8px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:Arial,sans-serif">182-182</td></tr>
        <tr><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Account</td><td style="padding:8px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:Arial,sans-serif">035 249 275</td></tr>
        <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Amount</td><td style="padding:8px 16px;font-size:15px;font-weight:bold;color:' . $goldLt . ';font-family:Georgia,serif">$40.00</td></tr>
        <tr><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Reference</td><td style="padding:8px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:Courier New,monospace">' . $abn . '</td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:24px 32px 0">
      <div style="font-size:16px;font-weight:bold;color:' . $dark . ';margin-bottom:6px;font-family:Georgia,serif">Open your Business Vault</div>
      <p style="font-size:13px;color:' . $muted . ';margin:0 0 14px;line-height:1.6;font-family:Arial,sans-serif">Your Business Vault is open in setup mode now. Set your password and you can review your recorded business COG$ reservations, see governance notices, and confirm the details captured on your join form. The link is secure and pre-loaded with your business identity &#8212; it works for 7 days.</p>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr><td align="center" style="padding:4px 0 12px">
          <a href="' . htmlspecialchars($setupUrl) . '" style="display:inline-block;background:' . $goldLt . ';color:#ffffff;font-weight:bold;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;font-family:Arial,sans-serif;letter-spacing:.01em">Set your Business Vault password &#8594;</a>
        </td></tr>
      </table>
      <p style="font-size:11px;color:#aaa;line-height:1.5;margin:0;font-family:Arial,sans-serif">Setup link works for 7 days. If the button does not work, visit <strong>cogsaustralia.org</strong> and click <em>Member Login</em>.</p>
    </td></tr>

    <tr><td style="padding:24px 32px 0">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:' . $greenBg . ';border:1px solid ' . $greenBd . ';border-radius:10px">
        <tr><td style="padding:20px 22px">
          <div style="font-size:15px;font-weight:bold;color:' . $green . ';margin-bottom:10px;font-family:Georgia,serif">&#127775; Your business is building this &#8212; not just joining it</div>
          <p style="font-size:13px;color:#2d4a38;line-height:1.7;margin:0 0 12px;font-family:Arial,sans-serif">
            COG$ is a community joint venture business partnership founded in equity law. The trust deed, source code, financials, and governance proposals are open to every Partner. As a business participant, you have full access to the Partner library &#8212; read, critique, and help improve the structure your business is now part of.
          </p>
          <p style="font-size:13px;color:#2d4a38;line-height:1.7;margin:0 0 10px;font-family:Arial,sans-serif"><strong>Note on governance:</strong> Business partnerships carry one governance vote on Trustee appointment or removal only. National governance voting rights sit with personal Partners (S-NFT). Your business participates through the circular economy network, income units, and open partnership in the joint venture structure.</p>
          <p style="font-size:13px;color:#2d4a38;margin:0;font-family:Arial,sans-serif">Everything is in the Partner library. Nothing is hidden. This is your joint venture &#8212; help us build it well.</p>
        </td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:20px 32px 28px">
      <p style="font-size:11px;color:#b0a090;line-height:1.7;margin:0;border-top:1px solid #e8e0d0;padding-top:16px;font-family:Arial,sans-serif">
        <strong style="color:#8a7a6a">Founding phase notice:</strong> This is a founding phase confirmation only. Your $40 business partnership contribution and ABN-linked Partner record are real. Your selected business COG$ remain no-obligation future intentions and are not payable today. They will activate on Expansion Day, subject to any regulatory requirements determined by applicable law. Nothing else has been offered or issued.
      </p>
    </td></tr>

    <tr><td style="background:' . $dark . ';padding:20px 32px;border-top:2px solid ' . $goldLt . '">
      <div style="font-size:11px;color:#6b5c44;line-height:1.9;font-family:Arial,sans-serif">
        <strong style="color:#9a8a74">COGS of Australia Foundation Hybrid Trust</strong><br>
        ABN: 61 734 327 831 &#183; Drake Village NSW 2469<br>
        members@cogsaustralia.org &#183; cogsaustralia.org<br>
        Community joint venture business partnership &#183; Partner operated &#183; Trustee administered
      </div>
    </td></tr>

  </table>
</td></tr>
</table>
</body></html>';

$plain = "Your business is in, {$contactName}.

"
    . "{$displayName} is now permanently recorded as a COG\$ community participant.

"
    . "YOUR BUSINESS PARTNER IDENTIFIER (ABN)
"
    . str_repeat("-", 40) . "
"
    . $abnDisplay . "
"
    . "Use this with your business email to log in to your Business Vault.

"
    . "DUE TODAY: \$40.00
"
    . str_repeat("-", 40) . "
"
    . "PayID: 0494 578 706 (fastest)
"
    . "Bank: Macquarie Bank
"
    . "Account name: The Trustee for COGS of Australia Foundation Hybrid Trust
"
    . "BSB: 182-182
"
    . "Account: 035 249 275
"
    . "Amount: \$40.00
"
    . "Reference: " . (string)($p['abn'] ?? '') . "

"
    . "OPEN YOUR BUSINESS VAULT
"
    . str_repeat("-", 40) . "
"
    . $setupUrl . "
"
    . "This link pre-loads your identity and works for 7 days.

"
    . "Your selected COG\$ remain recorded only and are not payable today.

"
    . "Note: Business partnerships: one vote on Trustee appointment or removal only. National governance rights sit with personal Partners.
"
    . "Voting is held by individual personal members (SNFT).

"
    . "GET INVOLVED
"
    . str_repeat("-", 40) . "
"
    . "COG\$ is a community joint venture business partnership founded in equity law.
"
    . "The trust deed, source code, financials and governance proposals
"
    . "are open to every Partner. Everything is in the Partner library.

"
    . "FOUNDING PHASE NOTICE
"
    . str_repeat("-", 40) . "
"
    . "This is a founding phase confirmation only. Your \$40 business
"
    . "partnership and ABN-linked Partner record are real. All other interests
"
    . "are no-obligation and will not activate until full regulatory
"
    . "subject to any regulatory requirements determined by applicable law.

"
    . "COGS of Australia Foundation Hybrid Trust | ABN: 61 734 327 831
"
    . "Drake Village NSW 2469 | members@cogsaustralia.org
";

return [$html, $plain];

        })(),
        'bnft_admin_alert' => [
            // HTML email
            $wrapOpen . $headerBar . $body
            . '<h2 style="' . $h2Style . '">New COG$ Business Partner — BNFT registration</h2>'
            . '<div style="' . $boxStyle . '">'
            . '<div style="' . $labelStyle . '">Business details</div>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:.5rem;">'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;width:38%;">Legal name</td><td style="color:#f0e8d6;font-weight:600;">' . htmlspecialchars((string)($p['legal_name'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Trading name</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['trading_name'] ?? '—')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">ABN</td><td style="color:#f0b429;font-weight:700;font-family:monospace;">' . htmlspecialchars((string)($p['abn'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Entity type</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['entity_type'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Contact</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['contact_name'] ?? '')) . ' — ' . htmlspecialchars((string)($p['position_title'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Email</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['email'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Mobile</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['mobile'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">State</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['state'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Industry</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['industry'] ?? '—')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Website</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['website'] ?? '—')) . '</td></tr>'
            . '</table></div>'
            . '<div style="' . $boxStyle . '">'
            . '<div style="' . $labelStyle . '">Reservation</div>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:.5rem;">'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;width:38%;">Value</td><td style="color:#f0b429;font-weight:700;">$' . number_format((float)($p['reservation_value'] ?? 0), 2) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Notice version</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['reservation_notice_version'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Accepted at</td><td style="color:#f0e8d6;">' . htmlspecialchars((string)($p['reservation_notice_accepted_at'] ?? '')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Due today</td><td style="color:#f0b429;font-weight:700;">' . htmlspecialchars((string)($p['joining_fee_due_now'] ?? '$4.00')) . '</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">PayID</td><td style="color:#f0e8d6;">0494 578 706</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Bank</td><td style="color:#f0e8d6;">Macquarie Bank</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">BSB</td><td style="color:#f0e8d6;">182-182</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Account</td><td style="color:#f0e8d6;">035 249 275</td></tr>'
            . '<tr><td style="color:#9a8a74;padding:.25rem 0;">Payment reference</td><td style="color:#f0e8d6;font-family:monospace;">' . htmlspecialchars((string)($p['member_number'] ?? '')) . '</td></tr>'
            . '</table>'
            . ($summary !== '' ? '<div style="' . $labelStyle . 'margin-top:.75rem;">Token breakdown</div><div style="font-size:13px;color:#d4c9b8;line-height:1.8;">' . $summaryHtml . '</div>' : '')
            . '</div>'
            . (!empty($p['use_case']) ? '<div style="' . $boxStyle . '"><div style="' . $labelStyle . '">How the business expects to participate</div><div style="font-size:13px;color:#d4c9b8;line-height:1.65;margin-top:.4rem;">' . nl2br(htmlspecialchars((string)$p['use_case'])) . '</div></div>' : '')
            . '<div style="font-size:11px;color:#6b5c44;margin-top:1rem;font-family:monospace;">' . htmlspecialchars((string)($p['trace_line'] ?? '')) . '</div>'
            . $footerBar . $wrapClose,
            // Plain text
            "New COG\$ Business Partner — BNFT registration\n\n"
            . "Legal name: " . (($p['legal_name'] ?? '')) . "\n"
            . "Trading name: " . (($p['trading_name'] ?? '')) . "\n"
            . "ABN: " . (($p['abn'] ?? '')) . "\n"
            . "Entity type: " . (($p['entity_type'] ?? '')) . "\n"
            . "Contact: " . (($p['contact_name'] ?? '')) . " — " . (($p['position_title'] ?? '')) . "\n"
            . "Email: " . (($p['email'] ?? '')) . "\n"
            . "Mobile: " . (($p['mobile'] ?? '')) . "\n"
            . "State: " . (($p['state'] ?? '')) . "\n"
            . "Industry: " . (($p['industry'] ?? '')) . "\n"
            . "Website: " . (($p['website'] ?? '')) . "\n"
            . "Use case: " . (($p['use_case'] ?? '')) . "\n\n"
            . "Reservation value: \$" . number_format((float)($p['reservation_value'] ?? 0), 2) . "\n"
            . ($summary !== '' ? $summary . "\n" : '')
            . "Notice: " . (($p['reservation_notice_version'] ?? '')) . "\n"
            . "Accepted at: " . (($p['reservation_notice_accepted_at'] ?? '')) . "\n"
            . "Due today: " . (($p['joining_fee_due_now'] ?? '$4.00')) . "\n"
            . "PayID: 0494 578 706\n"
            . "Bank: Macquarie Bank\n"
            . "BSB: 182-182\n"
            . "Account: 035 249 275\n"
            . "Payment reference: " . (($p['member_number'] ?? '')) . "\n\n"
            . (($p['trace_line'] ?? '')),
        ],
        'business_interest_confirmation' => (function() use ($p) {
            $name  = htmlspecialchars((string)($p['personal_name'] ?? 'there'));
            $fname = htmlspecialchars(explode(' ', trim($name))[0]);
            $goods = htmlspecialchars((string)($p['goods_services'] ?? ''));
            $pct   = (int)($p['acceptance_percent'] ?? 20);
            $ref   = htmlspecialchars((string)($p['reference'] ?? ''));
            $gold = '#c8901a'; $dark = '#1a1208'; $body_c = '#1e1208'; $muted = '#4a3828';
            $greenBg = '#edf7f1'; $greenBd = '#a8d5b8';
            $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Business Interest Received</title></head>
<body style="margin:0;padding:0;background:#ede8df;font-family:Georgia,serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#ede8df">
<tr><td align="center" style="padding:32px 16px">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)">
<tr><td style="background:' . $dark . ';padding:28px 32px 24px;border-bottom:3px solid ' . $gold . '">
  <div style="font-size:20px;font-weight:bold;color:' . $gold . ';font-family:Georgia,serif">COG$ of Australia Foundation</div>
  <div style="font-size:12px;color:#9a8a74;margin-top:4px;font-family:Arial,sans-serif">Community stewardship of Australia\'s resources</div>
</td></tr>
<tr><td style="padding:36px 32px 0;background:#ffffff">
  <div style="font-size:26px;font-weight:bold;color:' . $dark . ';font-family:Georgia,serif">Thank you, ' . $fname . '.</div>
  <p style="font-size:15px;color:' . $body_c . ';line-height:1.75;margin:16px 0 0;font-family:Arial,sans-serif">
    Your business interest has been received. We will review what you have shared and be in touch to discuss a practical pathway at a time that suits you.</p>
</td></tr>
<tr><td style="padding:20px 32px 0">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f6f2;border-radius:8px;overflow:hidden">
    <tr style="background:#f7f3ec"><td colspan="2" style="padding:10px 16px;font-size:11px;font-weight:700;color:' . $gold . ';text-transform:uppercase;letter-spacing:.06em;font-family:Arial,sans-serif">What you submitted</td></tr>
    <tr><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif;width:38%">Reference</td><td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:monospace">' . $ref . '</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif">Name</td><td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif">' . $name . '</td></tr>
    <tr><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif">Acceptance %</td><td style="padding:8px 16px;font-size:15px;font-weight:bold;color:' . $gold . ';font-family:Georgia,serif">' . $pct . '%</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif;vertical-align:top">What you offer</td>
      <td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif;line-height:1.6">' . $goods . '</td></tr>
  </table>
</td></tr>
<tr><td style="padding:22px 32px 0">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:' . $greenBg . ';border:1px solid ' . $greenBd . ';border-radius:10px">
    <tr><td style="padding:18px 22px">
      <div style="font-size:14px;font-weight:bold;color:#2d6e45;margin-bottom:8px;font-family:Georgia,serif">What happens next</div>
      <p style="font-size:13px;color:#2d4a38;line-height:1.7;margin:0;font-family:Arial,sans-serif">We will review your submission and contact you to discuss your business, what a sensible acceptance pathway could look like, and any questions you have. Nothing activates automatically — the next step is a conversation.</p>
    </td></tr>
  </table>
</td></tr>
<tr><td style="padding:20px 32px 28px">
  <p style="font-size:11px;color:#b0a090;line-height:1.7;margin:0;border-top:1px solid #e8e0d0;padding-top:16px;font-family:Arial,sans-serif">
    Expression of interest only. Nothing has been offered or issued. All recorded interests activate on Expansion Day, subject to any regulatory requirements determined by applicable law.
  </p>
</td></tr>
<tr><td style="background:' . $dark . ';padding:18px 32px;border-top:2px solid ' . $gold . '">
  <div style="font-size:11px;color:#6b5c44;line-height:1.9;font-family:Arial,sans-serif">
    <strong style="color:#9a8a74">COG$ of Australia Foundation</strong><br>
    ABN: 61 734 327 831 &nbsp;&#183;&nbsp; Drake Village NSW 2469<br>
    members@cogsaustralia.org &nbsp;&#183;&nbsp; cogsaustralia.org
  </div>
</td></tr>
</table></td></tr></table></body></html>';
            $plain = "Thank you, {$fname}.\n\n"
                . "Your business interest has been received. Reference: {$ref}\n\n"
                . "WHAT YOU SUBMITTED\n" . str_repeat('-', 36) . "\n"
                . "Name:              {$name}\n"
                . "Acceptance range:  {$pct}%\n"
                . "What you offer:    {$goods}\n\n"
                . "We will be in touch to discuss a practical pathway.\n\n"
                . "COG\$ of Australia Foundation | ABN: 61 734 327 831\n"
                . "members@cogsaustralia.org\n";
            return [$html, $plain];
        })(),

        'business_interest_admin' => (function() use ($p) {
            $name   = htmlspecialchars((string)($p['personal_name'] ?? ''));
            $email  = htmlspecialchars((string)($p['email'] ?? ''));
            $mobile = htmlspecialchars((string)($p['mobile'] ?? '—'));
            $goods  = htmlspecialchars((string)($p['goods_services'] ?? ''));
            $pct    = (int)($p['acceptance_percent'] ?? 20);
            $member = htmlspecialchars(ucfirst((string)($p['existing_member'] ?? 'no')));
            $ref    = htmlspecialchars((string)($p['reference'] ?? ''));
            $subAt  = htmlspecialchars((string)($p['submitted_at'] ?? ''));
            $gold = '#c8901a'; $dark = '#1a1208'; $body_c = '#1e1208'; $muted = '#4a3828';
            $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>New Business Interest</title></head>
<body style="margin:0;padding:0;background:#ede8df;font-family:Georgia,serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#ede8df">
<tr><td align="center" style="padding:32px 16px">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden">
<tr><td style="background:' . $dark . ';padding:22px 32px;border-bottom:3px solid ' . $gold . '">
  <div style="font-size:18px;font-weight:bold;color:' . $gold . ';font-family:Georgia,serif">New Business Interest</div>
  <div style="font-size:11px;color:#9a8a74;margin-top:4px;font-family:monospace">Ref: ' . $ref . ' &nbsp;·&nbsp; ' . $subAt . ' UTC</div>
</td></tr>
<tr><td style="padding:24px 32px">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f6f2;border-radius:8px;overflow:hidden">
    <tr style="background:#f7f3ec"><td colspan="2" style="padding:10px 16px;font-size:11px;font-weight:700;color:' . $gold . ';text-transform:uppercase;letter-spacing:.06em;font-family:Arial,sans-serif">Submission</td></tr>
    <tr><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif;width:36%">Name</td><td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif">' . $name . '</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif">Email</td><td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif">' . $email . '</td></tr>
    <tr><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif">Mobile</td><td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif">' . $mobile . '</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif">Acceptance %</td><td style="padding:8px 16px;font-size:15px;font-weight:bold;color:' . $gold . ';font-family:Georgia,serif">' . $pct . '%</td></tr>
    <tr><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif">Existing Partner</td><td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif">' . $member . '</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $muted . ';font-family:Arial,sans-serif;vertical-align:top">Goods &amp; services</td>
      <td style="padding:8px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif;line-height:1.6">' . $goods . '</td></tr>
  </table>
</td></tr>
</table></td></tr></table></body></html>';
            $plain = "NEW BUSINESS INTEREST\n" . str_repeat('=', 38) . "\n"
                . "Ref: {$ref} | {$subAt} UTC\n\n"
                . "Name:             {$name}\n"
                . "Email:            {$email}\n"
                . "Mobile:           {$mobile}\n"
                . "Acceptance range: {$pct}%\n"
                . "Existing Partner:  {$member}\n"
                . "Goods & services: {$goods}\n";
            return [$html, $plain];
        })(),

        'payment_intent_member' => (function() use ($p, $site) {
            $name = htmlspecialchars((string)($p['full_name'] ?? 'Member'));
            $firstName = htmlspecialchars(explode(' ', trim((string)($p['full_name'] ?? 'there')))[0]);
            $ref = htmlspecialchars((string)($p['reference'] ?? ''));
            $cls = htmlspecialchars((string)($p['token_class'] ?? ''));
            $units = (int)($p['units'] ?? 0);
            $amount = htmlspecialchars((string)($p['amount'] ?? '0.00'));
            $payId = htmlspecialchars((string)($p['pay_id'] ?? ''));
            $bankName = htmlspecialchars((string)($p['bank_name'] ?? ''));
            $bankBsb = htmlspecialchars((string)($p['bank_bsb'] ?? ''));
            $bankAcct = htmlspecialchars((string)($p['bank_account'] ?? ''));
            $memberNum = htmlspecialchars((string)($p['member_number'] ?? ''));
            $siteUrl = htmlspecialchars((string)($site ?? 'https://cogsaustralia.org'));
            $gold = '#c8901a'; $dark = '#1a1208'; $muted = '#4a3828'; $body_c = '#1e1208';

            $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>COG$ Payment Instructions</title></head>
<body style="margin:0;padding:0;background:#ede8df;font-family:Georgia,serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#ede8df"><tr><td align="center" style="padding:32px 16px">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)">
<tr><td style="background:' . $dark . ';padding:24px 32px;border-bottom:3px solid ' . $gold . '">
  <div style="font-size:20px;font-weight:bold;color:' . $gold . ';font-family:Georgia,serif">COG$ Australia</div>
  <div style="font-size:12px;color:#9a8a74;margin-top:4px;font-family:Arial,sans-serif">Payment Instructions</div>
</td></tr>
<tr><td style="padding:32px 32px 0">
  <div style="font-size:24px;font-weight:bold;color:' . $dark . ';font-family:Georgia,serif">Payment instructions</div>
  <p style="font-size:14px;color:' . $body_c . ';line-height:1.7;margin:14px 0;font-family:Arial,sans-serif">Hi ' . $firstName . ', your gift pool purchase has been recorded. Complete payment to activate your COG$.</p>
</td></tr>
<tr><td style="padding:0 32px">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f6f2;border-radius:8px;overflow:hidden;margin:12px 0">
    <tr style="background:#f7f3ec"><td colspan="2" style="padding:10px 16px;font-size:12px;font-weight:bold;color:' . $gold . ';text-transform:uppercase;letter-spacing:.06em;font-family:Arial,sans-serif">Order details</td></tr>
    <tr><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif;width:40%">Token class</td><td style="padding:8px 16px;font-size:13px;font-weight:600;color:' . $body_c . ';font-family:Arial,sans-serif">' . $cls . '</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Units</td><td style="padding:8px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:Arial,sans-serif">' . $units . '</td></tr>
    <tr><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Amount</td><td style="padding:8px 16px;font-size:15px;font-weight:bold;color:' . $gold . ';font-family:Georgia,serif">$' . $amount . ' AUD</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:8px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Reference</td><td style="padding:8px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:monospace">' . $ref . '</td></tr>
  </table>
</td></tr>
<tr><td style="padding:16px 32px" align="center">
  <a href="' . $siteUrl . '/wallets/member.html" style="display:inline-block;background:#635bff;color:#fff;font-weight:bold;text-decoration:none;padding:14px 32px;border-radius:8px;font-size:15px;font-family:Arial,sans-serif">Pay now in your vault &#8594;</a>
</td></tr>
<tr><td style="padding:0 32px">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f6f2;border-radius:8px;overflow:hidden;margin:12px 0;border:1px solid #e8e0d0">
    <tr style="background:#f7f3ec"><td colspan="2" style="padding:10px 16px;font-size:12px;font-weight:bold;color:' . $gold . ';text-transform:uppercase;letter-spacing:.06em;font-family:Arial,sans-serif">Or pay by bank transfer</td></tr>
    <tr><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif;width:40%">PayID</td><td style="padding:6px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:Arial,sans-serif">' . $payId . '</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Bank</td><td style="padding:6px 16px;font-size:13px;color:' . $body_c . ';font-family:Arial,sans-serif">' . $bankName . '</td></tr>
    <tr><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">BSB</td><td style="padding:6px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:Arial,sans-serif">' . $bankBsb . '</td></tr>
    <tr style="background:#f7f3ec"><td style="padding:6px 16px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Account</td><td style="padding:6px 16px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:Arial,sans-serif">' . $bankAcct . '</td></tr>
    <tr><td style="padding:6px 16px 10px;font-size:13px;color:' . $muted . ';font-family:Arial,sans-serif">Reference</td><td style="padding:6px 16px 10px;font-size:13px;font-weight:bold;color:' . $body_c . ';font-family:monospace">' . $memberNum . '</td></tr>
  </table>
</td></tr>
<tr><td style="padding:20px 32px;background:' . $dark . ';border-top:2px solid ' . $gold . '">
  <div style="font-size:11px;color:#6b5c44;line-height:1.9;font-family:Arial,sans-serif">COG$ of Australia Foundation · ABN: 61 734 327 831 · members@cogsaustralia.org</div>
</td></tr>
</table></td></tr></table></body></html>';

            $plain = "COG\$ Payment Instructions\n\nHi {$firstName},\n\nYour gift pool purchase has been recorded.\n\n"
                . "Token class: {$cls}\nUnits: {$units}\nAmount: \${$amount} AUD\nReference: {$ref}\n\n"
                . "Pay now: {$siteUrl}/wallets/member.html\n\n"
                . "Or bank transfer:\nPayID: {$payId}\nBank: {$bankName}\nBSB: {$bankBsb}\nAccount: {$bankAcct}\nReference: {$memberNum}\n";
            return [$html, $plain];
        })(),

        'payment_intent_admin' => (function() use ($p) {
            $name = (string)($p['full_name'] ?? '');
            $memberNum = (string)($p['member_number'] ?? '');
            $cls = (string)($p['token_class'] ?? '');
            $units = (int)($p['units'] ?? 0);
            $amount = (string)($p['amount'] ?? '0.00');
            $ref = (string)($p['reference'] ?? '');
            $html = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;background:#0f0d09;color:#f0e8d6;padding:24px;border-radius:10px">'
                . '<h2 style="color:#c8901a;margin:0 0 12px">Payment Intent Received</h2>'
                . '<table style="width:100%;font-size:13px;border-collapse:collapse">'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Member</td><td style="font-weight:600">' . htmlspecialchars($name) . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Number</td><td style="font-family:monospace">' . htmlspecialchars($memberNum) . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Class</td><td>' . htmlspecialchars($cls) . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Units</td><td style="font-weight:600">' . $units . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Amount</td><td style="color:#c8901a;font-weight:700">$' . htmlspecialchars($amount) . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Reference</td><td style="font-family:monospace">' . htmlspecialchars($ref) . '</td></tr>'
                . '</table></div>';
            $plain = "Payment Intent Received\nMember: {$name} ({$memberNum})\nClass: {$cls}\nUnits: {$units}\nAmount: \${$amount}\nRef: {$ref}\n";
            return [$html, $plain];
        })(),

        'kids_submitted_admin' => (function() use ($p) {
            $name = htmlspecialchars((string)($p['full_name'] ?? ''));
            $memberNum = htmlspecialchars((string)($p['member_number'] ?? ''));
            $email = htmlspecialchars((string)($p['email'] ?? ''));
            $count = (int)($p['children_count'] ?? 0);
            $names = htmlspecialchars((string)($p['children_names'] ?? ''));
            $html = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;background:#0f0d09;color:#f0e8d6;padding:24px;border-radius:10px">'
                . '<h2 style="color:#c8901a;margin:0 0 12px">Kids S-NFT — ID Verification Needed</h2>'
                . '<p style="font-size:13px;color:#d4c9b8;line-height:1.6;margin:0 0 16px">A Partner has submitted child details for Kids S-NFT registration. Admin verification is required before tokens can be issued.</p>'
                . '<table style="width:100%;font-size:13px;border-collapse:collapse">'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Guardian</td><td style="font-weight:600">' . $name . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Number</td><td style="font-family:monospace">' . $memberNum . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Email</td><td>' . $email . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Children</td><td style="font-weight:600">' . $count . ' submitted</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Names</td><td>' . $names . '</td></tr>'
                . '</table>'
                . '<div style="margin-top:16px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08)">'
                . '<p style="font-size:12px;color:#9a8a74;margin:0">Review in <strong style="color:#f0e8d6">Admin → Kids S-NFT</strong> to verify identity and issue tokens.</p>'
                . '</div></div>';
            $plain = "Kids S-NFT — ID Verification Needed\nGuardian: {$p['full_name']} ({$p['member_number']})\nEmail: {$p['email']}\nChildren submitted: {$count}\nNames: {$p['children_names']}\n\nReview in Admin → Kids S-NFT to verify and issue tokens.\n";
            return [$html, $plain];
        })(),

        'gift_order_cancelled_admin' => (function() use ($p) {
            $name = htmlspecialchars((string)($p['full_name'] ?? ''));
            $memberNum = htmlspecialchars((string)($p['member_number'] ?? ''));
            $items = htmlspecialchars((string)($p['cancelled_items'] ?? ''));
            $totalUnits = (int)($p['total_units'] ?? 0);
            $html = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;background:#0f0d09;color:#f0e8d6;padding:24px;border-radius:10px">'
                . '<h2 style="color:#c46060;margin:0 0 12px">Gift Pool Order Cancelled</h2>'
                . '<p style="font-size:13px;color:#d4c9b8;line-height:1.6;margin:0 0 16px">A Partner has cancelled unpaid gift pool order(s). If a bank transfer was expected, it should no longer be reconciled.</p>'
                . '<table style="width:100%;font-size:13px;border-collapse:collapse">'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Member</td><td style="font-weight:600">' . $name . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Number</td><td style="font-family:monospace">' . $memberNum . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Cancelled</td><td style="color:#c46060;font-weight:600">' . $items . '</td></tr>'
                . '<tr><td style="color:#9a8a74;padding:4px 0">Total units</td><td>' . $totalUnits . '</td></tr>'
                . '</table>'
                . '<div style="margin-top:16px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08)">'
                . '<p style="font-size:12px;color:#9a8a74;margin:0">Related pending payments have been auto-cancelled. Check <strong style="color:#f0e8d6">Reconciliation</strong> if a bank transfer was in progress.</p>'
                . '</div></div>';
            $plain = "Gift Pool Order Cancelled\nMember: {$p['full_name']} ({$p['member_number']})\nCancelled: {$p['cancelled_items']}\nTotal units: {$totalUnits}\n\nRelated pending payments auto-cancelled. Check Reconciliation if needed.\n";
            return [$html, $plain];
        })(),

        'hub_weekly_digest' => (function() use ($p, $wrapOpen, $headerBar, $body, $footerBar, $wrapClose, $h2Style, $h3Style, $pStyle, $boxStyle, $btnStyle, $urlStyle, $noticeStyle, $site): array {
            $firstName    = htmlspecialchars((string)($p['member_first_name'] ?? 'Member'));
            $weekEnding   = htmlspecialchars((string)($p['week_ending']       ?? date('Y-m-d')));
            $mainspringUrl = htmlspecialchars((string)($p['mainspring_url']   ?? $site . '/hubs/mainspring/'));
            $hubs          = is_array($p['hubs'] ?? null) ? $p['hubs'] : [];

            // Build per-hub rows
            $hubRows = '';
            $plainHubs = '';
            foreach ($hubs as $hub) {
                $label   = htmlspecialchars((string)($hub['area_label'] ?? ''));
                $inInput = (int)($hub['entered_input']          ?? 0);
                $inDelib = (int)($hub['entered_deliberation']   ?? 0);
                $inVote  = (int)($hub['entered_vote']           ?? 0);
                $inAcct  = (int)($hub['entered_accountability'] ?? 0);
                $done    = (int)($hub['completed']              ?? 0);
                $titles  = is_array($hub['highlight_titles'] ?? null) ? $hub['highlight_titles'] : [];
                $highlight = $titles ? '<div style="font-size:12px;color:#9a8a74;margin-top:4px">Notable: ' . htmlspecialchars(implode(', ', $titles)) . '</div>' : '';

                $stats = [];
                if ($inInput > 0)  $stats[] = "{$inInput} opened for input";
                if ($inDelib > 0)  $stats[] = "{$inDelib} in deliberation";
                if ($inVote  > 0)  $stats[] = "{$inVote} open for vote";
                if ($inAcct  > 0)  $stats[] = "{$inAcct} adopted";
                if ($done    > 0)  $stats[] = "{$done} completed";
                $statsStr = $stats ? implode(' · ', $stats) : 'No phase changes this week';

                $hubRows .= '<div style="' . $boxStyle . 'margin-bottom:.5rem;">'
                    . '<div style="font-size:13px;font-weight:700;color:#f0b429;margin-bottom:4px;">' . $label . '</div>'
                    . '<div style="font-size:13px;color:#d4c9b8;">' . $statsStr . '</div>'
                    . $highlight
                    . '</div>';

                $plainHubs .= "{$label}\n  {$statsStr}\n";
                if ($titles) $plainHubs .= "  Notable: " . implode(', ', $titles) . "\n";
                $plainHubs .= "\n";
            }

            if (!$hubRows) {
                $hubRows = '<p style="' . $pStyle . '">No hub activity this week.</p>';
            }

            $html = $wrapOpen . $headerBar . $body
                . '<h2 style="' . $h2Style . '">Your weekly hub digest</h2>'
                . '<p style="' . $pStyle . '">G\'day ' . $firstName . ', here\'s what moved in your hubs this week (ending ' . $weekEnding . ').</p>'
                . $hubRows
                . '<div style="margin:1.25rem 0;">'
                . '<a href="' . $mainspringUrl . '" style="' . $btnStyle . '">Open Mainspring ›</a>'
                . '</div>'
                . '<p style="' . $noticeStyle . '">You\'re receiving this because you joined one or more Management Hubs in your Independence Vault. '
                . 'Digest frequency: weekly (Friday). To stop receiving this email, visit your Vault settings.</p>'
                . '</div>' . $footerBar . $wrapClose;

            $plain = "COG$ Australia — Weekly Hub Digest\n"
                . "Week ending: {$weekEnding}\n\n"
                . "G'day {$firstName},\n\n"
                . $plainHubs
                . "Open Mainspring: {$mainspringUrl}\n\n"
                . "To unsubscribe, visit your Vault settings.\n";

            return [$html, $plain];
        })(),

        default => throw new RuntimeException('Unknown email template: ' . $templateKey),
    };
}


function smtpSendEmail(string $to, string $subject, string $htmlBody, string $textBody): void {
    $encryption = SMTP_ENCRYPTION;
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $transport = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($transport . ':' . $port, $errno, $errstr, SMTP_TIMEOUT, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('SMTP connect failed: ' . $errstr);
    }
    stream_set_timeout($socket, SMTP_TIMEOUT);
    smtpExpect($socket, [220]);
    smtpCommand($socket, 'EHLO ' . smtpClientName(), [250]);
    if ($encryption === 'tls') {
        smtpCommand($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Unable to start TLS session.');
        }
        smtpCommand($socket, 'EHLO ' . smtpClientName(), [250]);
    }
    if (SMTP_USERNAME !== '') {
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode(SMTP_USERNAME), [334]);
        smtpCommand($socket, base64_encode(SMTP_PASSWORD), [235]);
    }
    smtpCommand($socket, 'MAIL FROM:<' . MAIL_FROM_EMAIL . '>', [250]);
    smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    smtpCommand($socket, 'DATA', [354]);
    $boundary = 'b_' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . encodeHeaderName(MAIL_FROM_NAME) . ' <' . MAIL_FROM_EMAIL . '>',
        'To: <' . $to . '>',
        'Subject: ' . encodeHeaderName($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    if (MAIL_REPLY_TO !== '') {
        $headers[] = 'Reply-To: ' . MAIL_REPLY_TO;
    }
    // Base64 encoding: fixed 76-char lines, no mid-word breaks, universally supported
    $plainEncoded = chunk_split(base64_encode($textBody), 76, "\r\n");
    $htmlEncoded  = chunk_split(base64_encode($htmlBody),  76, "\r\n");

    $message = implode("\r\n", $headers) . "\r\n\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $plainEncoded
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $htmlEncoded
        . '--' . $boundary . "--\r\n.";
    fwrite($socket, $message . "\r\n");
    smtpExpect($socket, [250]);
    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
}

function smtpClientName(): string {
    $host = parse_url(SITE_URL, PHP_URL_HOST);
    return $host ?: 'localhost';
}

function smtpCommand($socket, string $command, array $expected): string {
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expected);
}

function smtpExpect($socket, array $expectedCodes): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    if ($response === '') {
        throw new RuntimeException('SMTP server returned an empty response.');
    }
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error [' . $code . ']: ' . trim($response));
    }
    return $response;
}

function normalizeSmtpBody(string $body): string {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body) ?? $body;
    return str_replace("\n", "\r\n", $body);
}

function normalizeQpBody(string $body): string {
    // quoted-printable keeps every line ≤ 76 chars — satisfies RFC 5321 998-char limit
    $qp = quoted_printable_encode($body);
    // Dot-stuff any line starting with '.' for SMTP DATA transparency
    $qp = preg_replace('/^\./m', '..', $qp) ?? $qp;
    // Normalise line endings to CRLF
    return str_replace(["\r\n", "\r", "\n"], "\r\n", $qp);
}


function encodeHeaderName(string $value): string {
    if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}
