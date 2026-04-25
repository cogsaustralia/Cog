<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once dirname(__DIR__) . '/_app/api/integrations/mailer.php';

ops_require_admin();
$pdo = ops_db();

function ucv_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// ── Resolve certificate from cert_ref or issuance_id ──────────────────────────
$certRef    = trim((string)($_GET['cert_ref']    ?? ''));
$issuanceId = (int)($_GET['issuance_id']         ?? 0);

$row = null;
if ($certRef !== '') {
    $row = $pdo->prepare("
        SELECT uc.cert_ref, uc.unit_class_code, uc.cert_type, uc.units, uc.issue_date,
               uc.email_sent_to, uc.email_sent_at,
               uir.register_ref, uir.unit_class_name, uir.sha256_hash, uir.consideration_cents,
               uir.issue_trigger, uir.gate, uir.issued_by_admin_id,
               m.full_name, m.first_name, m.email, m.member_number, m.member_type
        FROM unitholder_certificates uc
        INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
        INNER JOIN members m ON m.id = uc.member_id
        WHERE uc.cert_ref = ?
        LIMIT 1");
    $row->execute([$certRef]);
    $row = $row->fetch(\PDO::FETCH_ASSOC) ?: null;
} elseif ($issuanceId > 0) {
    $row = $pdo->prepare("
        SELECT uc.cert_ref, uc.unit_class_code, uc.cert_type, uc.units, uc.issue_date,
               uc.email_sent_to, uc.email_sent_at,
               uir.register_ref, uir.unit_class_name, uir.sha256_hash, uir.consideration_cents,
               uir.issue_trigger, uir.gate, uir.issued_by_admin_id,
               m.full_name, m.first_name, m.email, m.member_number, m.member_type
        FROM unitholder_certificates uc
        INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
        INNER JOIN members m ON m.id = uc.member_id
        WHERE uir.id = ?
        LIMIT 1");
    $row->execute([$issuanceId]);
    $row = $row->fetch(\PDO::FETCH_ASSOC) ?: null;
}

if (!$row) {
    http_response_code(404);
    echo '<!doctype html><html><head><title>Certificate not found</title></head><body>'
       . '<p style="font-family:sans-serif;padding:40px;color:#c00;">Certificate not found. '
       . '<a href="' . ucv_h(admin_url('unit_issuance.php')) . '?tab=certs">← Back to certificates</a></p>'
       . '</body></html>';
    exit;
}

// ── Build payload — same structure as the email ────────────────────────────────
$payload = [
    'full_name'           => (string)($row['full_name']       ?? ''),
    'first_name'          => (string)($row['first_name']      ?? ''),
    'email'               => (string)($row['email']           ?? ''),
    'member_number'       => (string)($row['member_number']   ?? ''),
    'unit_class_code'     => (string)($row['unit_class_code'] ?? ''),
    'unit_class_name'     => (string)($row['unit_class_name'] ?? ''),
    'cert_type'           => (string)($row['cert_type']       ?? 'financial'),
    'units_issued'        => (string)($row['units']           ?? '0'),
    'issue_date'          => (string)($row['issue_date']      ?? ''),
    'register_ref'        => (string)($row['register_ref']    ?? ''),
    'cert_ref'            => (string)($row['cert_ref']        ?? ''),
    'sha256_hash'         => (string)($row['sha256_hash']     ?? ''),
    'consideration_cents' => (int)($row['consideration_cents'] ?? 0),
    'issue_trigger'       => (string)($row['issue_trigger']   ?? ''),
    'gate'                => (int)($row['gate']               ?? 1),
    'member_type'         => (string)($row['member_type']     ?? 'personal'),
];

// ── Render certificate HTML via the same template the email uses ───────────────
[$certHtml, $certPlain] = renderEmailTemplate('unitholder_certificate', $payload);

$certRefSafe  = ucv_h((string)($row['cert_ref']     ?? ''));
$regRefSafe   = ucv_h((string)($row['register_ref'] ?? ''));
$memberSafe   = ucv_h((string)($row['full_name']    ?? ''));
$emailSentAt  = !empty($row['email_sent_at']) ? ucv_h(substr($row['email_sent_at'], 0, 16)) : null;
$emailSentTo  = !empty($row['email_sent_to']) ? ucv_h((string)$row['email_sent_to']) : null;
$backUrl      = ucv_h(admin_url('unit_issuance.php')) . '?tab=certs';
$issueUrl     = ucv_h(admin_url('unit_issuance.php')) . '?tab=register';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certificate <?= $certRefSafe ?> — COG$ Admin</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #1a1208; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }

  /* Admin toolbar — hidden on print */
  .admin-bar {
    background: #0f0d09;
    border-bottom: 1px solid rgba(255,255,255,.08);
    padding: 10px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    position: sticky;
    top: 0;
    z-index: 100;
  }
  .admin-bar .bar-title {
    font-size: 13px;
    font-weight: 700;
    color: #f0b429;
    flex: 1;
    min-width: 200px;
  }
  .admin-bar .bar-meta {
    font-size: 11px;
    color: #9a8a74;
  }
  .admin-bar a, .admin-bar button {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: 1px solid rgba(255,255,255,.12);
    background: rgba(255,255,255,.06);
    color: #f0e8d6;
  }
  .admin-bar button.print-btn {
    background: rgba(212,178,92,.15);
    border-color: rgba(212,178,92,.3);
    color: #f0b429;
  }
  .admin-bar button.print-btn:hover { background: rgba(212,178,92,.25); }
  .admin-bar a:hover { background: rgba(255,255,255,.1); }
  .sent-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: rgba(82,184,122,.12);
    border: 1px solid rgba(82,184,122,.25);
    color: #7ee0a0;
  }
  .pending-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: rgba(200,144,26,.12);
    border: 1px solid rgba(200,144,26,.25);
    color: #e8cc80;
  }

  /* Certificate wrapper */
  .cert-wrap {
    max-width: 640px;
    margin: 24px auto 48px;
    /* The certificate HTML uses inline styles from mailer — just let it render */
  }

  @media print {
    .admin-bar { display: none !important; }
    body { background: #fff; }
    .cert-wrap { margin: 0; max-width: 100%; }
  }
</style>
</head>
<body>

<!-- Admin toolbar -->
<div class="admin-bar">
  <div class="bar-title">📋 Certificate <?= $certRefSafe ?> — <?= $memberSafe ?></div>
  <div class="bar-meta">
    Register: <?= $regRefSafe ?>
    <?php if ($emailSentAt): ?>
      &nbsp;·&nbsp; <span class="sent-badge">✅ Emailed <?= $emailSentAt ?><?= $emailSentTo ? " → {$emailSentTo}" : '' ?></span>
    <?php else: ?>
      &nbsp;·&nbsp; <span class="pending-badge">⏳ Email pending</span>
    <?php endif; ?>
  </div>
  <button class="print-btn" onclick="window.print()">🖨 Print / Save PDF</button>
  <a href="<?= $issueUrl ?>">📚 Register</a>
  <a href="<?= $backUrl ?>">← Certificates</a>
</div>

<!-- Certificate rendered from same template as email -->
<div class="cert-wrap">
  <?= $certHtml ?>
</div>

</body>
</html>
