<?php
declare(strict_types=1);
/**
 * cert.php — Public Certificate of Unit Holding
 *
 * Token-gated public page. The email contains a signed 30-day URL:
 *   https://cogsaustralia.org/cert.php?t=<signed_token>
 *
 * Token is HMAC-signed via buildCertToken() / verifyCertToken() in mailer.php.
 * No login required — the signed token IS the authentication.
 */

require_once __DIR__ . '/_app/api/config/bootstrap.php';
require_once __DIR__ . '/_app/api/config/database.php';
require_once __DIR__ . '/_app/api/integrations/mailer.php';

function c_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function c_abort(int $code, string $msg): never {
    http_response_code($code);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Certificate — COG$ of Australia Foundation</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#0f0d09;color:#f0e8d6;'
       . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.b{max-width:480px;text-align:center;padding:40px}'
       . 'h1{color:#c8901a;font-size:1.1rem}p{color:#9a8a74;font-size:.88rem;line-height:1.6}'
       . 'a{color:#c8901a}</style></head>'
       . '<body><div class="b"><h1>' . c_h($msg) . '</h1>'
       . '<p>If you believe this is an error, contact <a href="mailto:members@cogsaustralia.org">members@cogsaustralia.org</a> with your certificate reference.</p>'
       . '</div></body></html>';
    exit;
}

// ── Token verification ──────────────────────────────────────────────────────
$rawToken = trim((string)($_GET['t'] ?? ''));
if ($rawToken === '') c_abort(403, 'No certificate token provided.');
$certRef = verifyCertToken($rawToken);
if ($certRef === null) c_abort(403, 'Certificate link is invalid or has expired. Links are valid for 30 days from issue.');

// ── Load certificate data ───────────────────────────────────────────────────
try { $db = getDB(); } catch (Throwable $e) { c_abort(500, 'Unable to load certificate — database unavailable.'); }

$stmt = $db->prepare(
    "SELECT uc.cert_ref, uc.unit_class_code, uc.cert_type, uc.units, uc.issue_date,
            m.full_name, m.member_number,
            uir.unit_class_name, uir.register_ref, uir.sha256_hash, uir.issue_trigger
     FROM unitholder_certificates uc
     INNER JOIN members m ON m.id = uc.member_id
     INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
     WHERE uc.cert_ref = ? LIMIT 1"
);
$stmt->execute([$certRef]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) c_abort(404, 'Certificate not found.');

// ── Derived values ──────────────────────────────────────────────────────────
$classCode  = (string)($row['unit_class_code'] ?? '');
$className  = (string)($row['unit_class_name'] ?? '');
$certType   = (string)($row['cert_type']       ?? 'financial');
$units      = (float)$row['units'];
$unitsStr   = $units == floor($units) ? number_format((int)$units) : number_format($units, 4);
$issueDate  = (string)($row['issue_date']   ?? '');
$hashVal    = (string)($row['sha256_hash']  ?? '');
$registerRef= (string)($row['register_ref'] ?? '');
$fullName   = (string)($row['full_name']    ?? '');
$memberNum  = (string)($row['member_number']?? '');

$certTypeLabel = $certType === 'financial'
    ? 'Financial Certificate — Class ' . $classCode
    : ($certType === 'community' ? 'Community Exchange Record' : 'Governance Allocation Notice');

// ── Gate label ──────────────────────────────────────────────────────────────
$issueGate = (string)($row['issue_trigger'] ?? '');
// Gate derived from class defs stored in the issuance row trigger field
$gateLabel = match(true) {
    str_contains($issueGate, 'gate_2'), str_contains($issueGate, 'foundation') => 'Gate 2 — Governance Foundation Day',
    str_contains($issueGate, 'gate_3'), str_contains($issueGate, 'expansion')  => 'Gate 3 — Expansion Day',
    default                                                                     => 'Gate 1 — Declaration Executed',
};

// ── Rights HTML by class (mirrors admin view) ───────────────────────────────
$rightsRows = '';
if ($classCode === 'S') {
    $rights = [
        ['Governance right', '1 national vote — all Foundation matters', 'Entrenched — Declaration cl.35(e)'],
        ['Beneficial unit', '1 income unit — proportional share of Beneficiary Distribution Stream', '50% of Members Asset Pool dividends distributed via Sub-Trust B — Declaration cl.21.1A'],
        ['Token type', 'Soulbound — non-transferable during lifetime', 'Cannot be sold, transferred, pledged, or dealt with. Passes to nominated heir on death only — Declaration cl.21.3, 35(i)'],
        ['Consideration', '$4.00 AUD (permanently fixed — Declaration cl.35(x))', '$3.00 → Administration costs · $1.00 → Sub-Trust A investment component'],
    ];
} elseif ($classCode === 'B') {
    $rights = [
        ['Governance right', '1 limited vote — Trustee appointment, removal, or replacement only', 'Exercised by named KYC-verified authorised representative only — Declaration cl.26A.2(a)'],
        ['Beneficial unit', '1 income unit — proportional share on same per-unit terms as Class S', 'Sub-Trust B distribution — Declaration cl.21.1A'],
        ['Token type', 'Soulbound — non-transferable during the lifetime of the business entity', 'Cannot be sold, transferred, pledged, or dealt with while the ABN is active — Declaration cl.35(i)'],
        ['Consideration', '$40.00 AUD (permanently fixed — Declaration cl.35(x))', '$38.00 → Administration costs · $2.00 → Sub-Trust A investment component'],
    ];
} else {
    $rights = [];
}
foreach ($rights as [$label, $val, $note]) {
    $rightsRows .= '<tr>'
        . '<td class="r-label">' . c_h($label) . '</td>'
        . '<td><div class="r-val">' . c_h($val) . '</div>'
        . ($note ? '<div class="r-note">' . c_h($note) . '</div>' : '')
        . '</td></tr>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certificate <?= c_h($certRef) ?> — COG$ of Australia Foundation</title>
<style>
/* ── Screen styles ────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
body {
  font-family: 'Segoe UI', system-ui, Arial, sans-serif;
  background: #f5f3ef;
  color: #1a1a1a;
  margin: 0;
  padding: 0;
}
.screen-bar {
  background: #1a1a2e;
  border-bottom: 3px solid #8b6914;
  padding: 14px 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.screen-bar .org { color: #d4b25c; font-weight: 700; font-size: 1rem; }
.screen-bar .sub { color: #9a8a74; font-size: .75rem; margin-top: 2px; }
.print-btn {
  background: #8b6914;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 10px 22px;
  font-size: .9rem;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  text-decoration: none;
  display: inline-block;
}
.print-btn:hover { background: #a07820; }
.page-outer {
  max-width: 860px;
  margin: 32px auto;
  padding: 0 16px 48px;
}
/* ── Certificate document ─────────────────────────────────────────────────── */
.cert-doc {
  background: #ffffff;
  border: 1px solid #d4c9b8;
  border-radius: 4px;
  box-shadow: 0 4px 24px rgba(0,0,0,.12);
  overflow: hidden;
}
.cert-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 28px 32px 20px;
  background: #fff;
  border-bottom: 2px solid #8b6914;
}
.cert-header .org-name { font-size: 1.15rem; font-weight: 800; color: #1a1a2e; letter-spacing: .02em; }
.cert-header .org-sub  { font-size: .72rem; color: #666; margin-top: 3px; }
.cert-header .badge    { display: inline-block; font-size: .65rem; font-weight: 700; text-transform: uppercase;
                         letter-spacing: .08em; color: #8b6914; border: 1px solid #d4b25c;
                         border-radius: 4px; padding: 2px 8px; margin-top: 8px; }
.cert-header-right     { text-align: right; }
.cert-header-right .cert-title { font-size: .7rem; font-weight: 700; text-transform: uppercase;
                                  letter-spacing: .12em; color: #8b6914; }
.cert-header-right .cert-ref   { font-size: 1.35rem; font-weight: 800; color: #1a1a2e;
                                  font-family: 'Courier New', monospace; margin-top: 4px; }
.cert-header-right .class-pill { display: inline-block; background: #1a1a2e; color: #d4b25c;
                                   font-size: .72rem; font-weight: 700; padding: 3px 12px;
                                   border-radius: 20px; margin-top: 6px; }
.cert-body { padding: 28px 32px; }
.intro-text { font-size: .85rem; color: #444; line-height: 1.65; margin: 0 0 24px; }
.section-heading {
  font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .12em;
  color: #8b6914; border-bottom: 1px solid #e8e0d0; padding-bottom: 6px; margin: 24px 0 14px;
}
.details-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 8px;
}
.detail-cell .detail-label { font-size: .68rem; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: .06em; }
.detail-cell .detail-value { font-size: .88rem; color: #1a1a2e; margin-top: 3px; }
.detail-cell .detail-value.large { font-size: 1.05rem; font-weight: 700; }
.detail-cell .detail-value.mono  { font-family: 'Courier New', monospace; }
.hash-row { background: #f8f5f0; border: 1px solid #e8e0d0; border-radius: 4px;
             padding: 10px 14px; margin: 16px 0; }
.hash-label { font-size: .65rem; font-weight: 700; text-transform: uppercase;
               letter-spacing: .08em; color: #888; margin-bottom: 4px; }
.hash-val   { font-family: 'Courier New', monospace; font-size: .72rem; color: #555;
               word-break: break-all; }
/* Rights table */
.rights-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.rights-table tr { border-bottom: 1px solid #f0ede8; }
.rights-table tr:last-child { border-bottom: none; }
.r-label { font-weight: 600; color: #555; width: 160px; padding: 10px 12px 10px 0;
            vertical-align: top; white-space: nowrap; }
.r-val   { color: #1a1a2e; line-height: 1.5; }
.r-note  { font-size: .72rem; color: #888; margin-top: 3px; line-height: 1.4; }
/* Footer */
.cert-footer {
  background: #f8f5f0;
  border-top: 1px solid #e8e0d0;
  padding: 18px 32px;
  font-size: .72rem;
  color: #888;
  line-height: 1.6;
}
.cert-footer strong { color: #555; }
/* ── Print styles ─────────────────────────────────────────────────────────── */
@media print {
  .screen-bar, .print-btn-wrap { display: none !important; }
  body { background: #fff; }
  .page-outer { margin: 0; padding: 0; max-width: 100%; }
  .cert-doc { box-shadow: none; border: none; }
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
</style>
</head>
<body>

<div class="screen-bar">
  <div>
    <div class="org">COG$ of Australia Foundation</div>
    <div class="sub">ABN 91 341 497 529 &nbsp;·&nbsp; Drake Village NSW 2469</div>
  </div>
  <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
</div>

<div class="page-outer">
<div class="cert-doc">

  <!-- Header -->
  <div class="cert-header">
    <div>
      <div class="org-name">COG$ of Australia Foundation</div>
      <div class="org-sub">Community Joint Venture — Wahlubal Country, Bundjalung Nation</div>
      <div class="badge"><?= c_h($certTypeLabel) ?></div>
    </div>
    <div class="cert-header-right">
      <div class="cert-title">Certificate of Unit Holding</div>
      <div class="cert-ref"><?= c_h($certRef) ?></div>
      <div class="class-pill">Class <?= c_h($classCode) ?></div>
    </div>
  </div>

  <!-- Body -->
  <div class="cert-body">

    <p class="intro-text">
      This Certificate of Unit Holding is issued under the authority of the
      <strong>CJVM Hybrid Trust Declaration</strong> and <strong>Sub-Trust A Deed</strong>
      by the Caretaker Trustee of the
      <strong>COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust</strong>
      (ABN 91 341 497 529). It constitutes the formal legal record of unit holding for the
      unitholder named below.
    </p>

    <p class="section-heading">Unit Holding Details</p>
    <div class="details-grid">
      <div class="detail-cell">
        <div class="detail-label">Certificate Reference</div>
        <div class="detail-value large mono"><?= c_h($certRef) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Register Reference</div>
        <div class="detail-value mono"><?= c_h($registerRef) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Issue Date</div>
        <div class="detail-value"><?= c_h($issueDate) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Unitholder</div>
        <div class="detail-value"><?= c_h($fullName) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Member Number</div>
        <div class="detail-value mono"><?= c_h($memberNum) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Unit Class</div>
        <div class="detail-value"><?= c_h($className) ?> (Class <?= c_h($classCode) ?>)</div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Units Issued</div>
        <div class="detail-value large"><?= c_h($unitsStr) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Issuance Gate</div>
        <div class="detail-value"><?= c_h($gateLabel) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Certificate Type</div>
        <div class="detail-value"><?= c_h(ucfirst($certType)) ?></div>
      </div>
    </div>

    <div class="hash-row">
      <div class="hash-label">Cryptographic Integrity Record (SHA-256)</div>
      <div class="hash-val"><?= c_h($hashVal) ?></div>
    </div>

    <?php if ($rightsRows !== ''): ?>
    <p class="section-heading">Rights and Attributes — Class <?= c_h($classCode) ?></p>
    <table class="rights-table">
      <tbody><?= $rightsRows ?></tbody>
    </table>
    <?php endif; ?>

  </div><!-- /.cert-body -->

  <!-- Footer -->
  <div class="cert-footer">
    <strong>Issued by:</strong> Thomas Boyd Cunliffe, Caretaker Trustee &nbsp;·&nbsp;
    COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust (ABN 91 341 497 529) &nbsp;·&nbsp;
    C/- Drake Village Resource Centre, Drake Village NSW 2469 &nbsp;·&nbsp;
    Wahlubal Country, Bundjalung Nation<br>
    <strong>Governing instruments:</strong> JVPA &nbsp;·&nbsp; CJVM Hybrid Trust Declaration &nbsp;·&nbsp; Sub-Trust A Deed &nbsp;&nbsp;
    <strong>Governing law:</strong> South Australia, Australia<br>
    <strong>Contact:</strong> members@cogsaustralia.org &nbsp;·&nbsp; cogsaustralia.org
  </div>

</div><!-- /.cert-doc -->

<div class="print-btn-wrap" style="text-align:center;margin-top:20px;">
  <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
  <p style="font-size:.75rem;color:#888;margin-top:8px;">
    In your browser print dialog, select <strong>Save as PDF</strong> or your printer.
    Enable "Background graphics" for best results.
  </p>
</div>

</div><!-- /.page-outer -->
</body>
</html>
