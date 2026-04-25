<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once dirname(__DIR__) . '/_app/api/config/bootstrap.php';
require_once dirname(__DIR__) . '/_app/api/integrations/mailer.php';

ops_require_admin();
$pdo = ops_db();

function ucv_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

// ── Resolve certificate ───────────────────────────────────────────────────────
$certRef    = trim((string)($_GET['cert_ref']    ?? ''));
$issuanceId = (int)($_GET['issuance_id']         ?? 0);

$row = null;
if ($certRef !== '') {
    $st = $pdo->prepare("
        SELECT uc.cert_ref, uc.unit_class_code, uc.cert_type, uc.units, uc.issue_date,
               uc.email_sent_to, uc.email_sent_at,
               uir.register_ref, uir.unit_class_name, uir.sha256_hash, uir.consideration_cents,
               uir.issue_trigger, uir.gate, uir.issued_by_admin_id,
               m.full_name, m.first_name, m.email, m.member_number, m.member_type
        FROM unitholder_certificates uc
        INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
        INNER JOIN members m ON m.id = uc.member_id
        WHERE uc.cert_ref = ? LIMIT 1");
    $st->execute([$certRef]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($issuanceId > 0) {
    $st = $pdo->prepare("
        SELECT uc.cert_ref, uc.unit_class_code, uc.cert_type, uc.units, uc.issue_date,
               uc.email_sent_to, uc.email_sent_at,
               uir.register_ref, uir.unit_class_name, uir.sha256_hash, uir.consideration_cents,
               uir.issue_trigger, uir.gate, uir.issued_by_admin_id,
               m.full_name, m.first_name, m.email, m.member_number, m.member_type
        FROM unitholder_certificates uc
        INNER JOIN unit_issuance_register uir ON uir.id = uc.issuance_id
        INNER JOIN members m ON m.id = uc.member_id
        WHERE uir.id = ? LIMIT 1");
    $st->execute([$issuanceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$row) {
    http_response_code(404);
    echo '<!doctype html><html><head><title>Certificate not found</title>'
       . '<style>body{font-family:sans-serif;padding:60px;background:#f8f8f6;color:#333}</style></head><body>'
       . '<h2>Certificate not found</h2>'
       . '<p><a href="' . ucv_h(admin_url('unit_issuance.php')) . '?tab=certs">← Back to certificates</a></p>'
       . '</body></html>';
    exit;
}

// ── Build display values ──────────────────────────────────────────────────────
$classCode   = (string)($row['unit_class_code'] ?? '');
$className   = (string)($row['unit_class_name'] ?? '');
$certType    = (string)($row['cert_type']        ?? 'financial');
$memberType  = (string)($row['member_type']      ?? 'personal');
$gate        = (int)($row['gate']                ?? 1);
$unitsRaw    = (float)($row['units']             ?? 0);
$units       = $unitsRaw == floor($unitsRaw) ? number_format((int)$unitsRaw) : number_format($unitsRaw, 4);
$gateLabel   = $gate === 1 ? 'Gate 1 — Declaration Executed'
             : ($gate === 2 ? 'Gate 2 — Governance Foundation Day'
             : 'Gate 3 — Expansion Day');

// Format consideration
$consCents   = (int)($row['consideration_cents'] ?? 0);
$consDisplay = $consCents > 0 ? '$' . number_format($consCents / 100, 2) . ' AUD' : 'Nil — standing allocation';

// Email status
$emailSentAt = !empty($row['email_sent_at']) ? ucv_h(date('j M Y', strtotime($row['email_sent_at']))) : null;

// Class accent colour
$accentHex = match($classCode) {
    'S','kS'         => '#b8860b',
    'B'              => '#1a5f8a',
    'C'              => '#2e7d52',
    'P'              => '#7b4f8a',
    'D'              => '#8a4a2e',
    'Lr'             => '#4a6b8a',
    default          => '#8b6914',
};

// ── Rights data per class — document format ───────────────────────────────────
function ucv_rights(string $code, string $memberType): array {
    $rows = match($code) {
        'S' => [
            ['Governance right',           '1 national vote on all Foundation matters',                            'Entrenched — JVPA cl.35(e)'],
            ['Beneficial Unit',            '1 Sub-Trust B income unit — proportional share of Beneficiary Distribution Stream', '50% of Members Asset Pool dividends distributed via Sub-Trust B — Declaration cl.21.1A'],
            ['Token type',                 'Soulbound — non-transferable during lifetime; passes to nominated heir on death only', 'Declaration cl.21.3, 35(i)'],
            ['Consideration',              '$4.00 AUD (permanently fixed)',                                        'JVPA cl.35(x) — $3.00 Administration · $1.00 Sub-Trust A'],
            ['Smart contract attributes',  "Soulbound transfer lock\nOne-per-person cap enforcement\n3-of-Board multisig minting\nAnti-capture cap (1,000,000 combined units)\nHeir nomination and inheritance flow", ''],
        ],
        'B' => [
            ['Governance right',           '1 limited vote — Trustee appointment, removal, or replacement only', 'Exercised by KYC-verified authorised representative — Declaration cl.26A.2(a)'],
            ['Beneficial Unit',            '1 Sub-Trust B income unit — proportional share on same per-unit terms as Class S', 'Declaration cl.26A.2(b) — Sub-Trust B Deed cl.6.2'],
            ['Token type',                 'Entity-bound — non-transferable; cancelled on entity dissolution',     'Declaration cl.35(v)'],
            ['Consideration',              '$40.00 AUD',                                                           'JVPA cl.35(y) — $30.00 Administration · $10.00 Sub-Trust A'],
            ['Smart contract attributes',  "Entity-bound transfer lock\nBNFT compliance stack (ABN verified, authorised rep KYC)\n3-of-Board multisig minting\nAnti-capture cap check\nEntity dissolution cancellation trigger", ''],
        ],
        'kS' => [
            ['Governance right',           '1 national vote via parent/guardian proxy until age 18; auto-activates on 18th birthday', 'Declaration cl.25.3'],
            ['Beneficial Unit',            '1 Sub-Trust B income unit — held in trust until age 18; auto-converts to Class S', 'Declaration cl.25.1, 25.4'],
            ['Token type',                 'Soulbound — non-transferable in all circumstances',                    'Irrevocable; no further consideration payable on conversion'],
            ['Smart contract attributes',  "Soulbound transfer lock — permanent\nAuto-conversion to Class S at age 18 (date-triggered)\nProxy governance flag (parent/guardian until conversion)\n3-of-Board multisig minting", ''],
        ],
        'C' => [
            ['Unit type',                  'Community exchange record — not a financial investment',               'No Sub-Trust B income unit. No yield.'],
            ['Governance right',           'None by Class C alone — no national governance vote',                  'Declaration cl.23D.1 — Schedule 9 Part A'],
            ['Beneficial Unit',            'None — Class C carries no income unit and no yield',                   ''],
            ['Function',                   'Immutable barter and service exchange record between Members. P2P exchange where class rules permit. No fiat sale.',  'Declaration cl.35(w)'],
            ['Allocation basis',           'Standing Members Poll direction — ' . ($memberType === 'business' ? '10,000 units for Business Members' : '1,000 units for Individual Members'), 'Declaration cl.23D.3 — no purchase fee'],
            ['Smart contract attributes',  "P2P transfer enforcement (class-rule-gated)\nNo-fiat-sale rule (entrenched cl.35(w))\nSHA-256 hash record (authoritative pre-Expansion Day)\nOn-chain migration at Expansion Day (cl.23D.5)", ''],
        ],
        'P' => [
            ['Purpose',                    'Funds a free Class S membership for a nominated recipient who cannot afford the $4.00 joining fee', 'Donor receives no Beneficial Unit'],
            ['Governance right (donor)',    'None — Pay It Forward units carry no vote and no Beneficial Unit for the donor', ''],
            ['Smart contract attributes',  "Non-transferable (donor record)\nRecipient activation triggers Class S issuance\n3-of-Board multisig for recipient Class S minting", ''],
        ],
        'D' => [
            ['Unit type',                  'Donation record — no personal Beneficial Unit for donor',              'Sub-Trust C registered as D Class Beneficial Unit Holder'],
            ['Funds flow',                 '$4.00 AUD — $2.00 to Sub-Trust C (charitable) · $2.00 to Sub-Trust A asset acquisition', 'Transfer to Sub-Trust C within 2 business days'],
            ['Beneficial Unit Holder',     'Sub-Trust C — 1 D Class income unit per validly issued Donation COG$', 'Proportional share of Donation Dividend Stream via Sub-Trust B'],
            ['Smart contract attributes',  "Sub-Trust C auto-registered as Beneficial Unit Holder\n\$2.00 transfer to Sub-Trust C triggered on issuance\nDonation Ledger entry required\n3-of-Board multisig minting", ''],
        ],
        'Lr' => [
            ['Unit type',                  'Governance allocation — not a financial instrument',                   'No Beneficial Unit. No yield. No national vote.'],
            ['Local governance right',     '1,000 weighted local votes per declared Affected Zone',               'Effective 1,001:1 weighting — Declaration cl.23B.2, 35(s)'],
            ['Auto-lapse conditions',      'Lapses if: Affected Zone declaration expires or is revoked; or residency eligibility ceases to be verified', 'Declaration cl.23B.4'],
            ['Smart contract attributes',  "Non-transferable\nExcluded from anti-capture cap\nAuto-lapse trigger on zone expiry or residency loss\nWeighted vote enforced at vote time", ''],
        ],
        default => [
            ['Rights and attributes',      'Defined in CJVM Hybrid Trust Declaration Schedule 9, Part A', 'Refer to the governing instruments for full details of this unit class.'],
        ],
    };
    return $rows;
}

$rights  = ucv_rights($classCode, $memberType);
$isFinancial = $certType === 'financial';
$isCommunity = $certType === 'community';
$isGovAlloc  = $certType === 'governance_allocation';

$certTypeLabel = $isFinancial ? 'Financial Certificate' : ($isCommunity ? 'Community Exchange Record' : 'Governance Allocation Notice');
$backUrl  = ucv_h(admin_url('unit_issuance.php')) . '?tab=certs';
$regUrl   = ucv_h(admin_url('unit_issuance.php')) . '?tab=register';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certificate <?= ucv_h($row['cert_ref'] ?? '') ?> — COG$ of Australia Foundation</title>
<style>
/* ── Screen reset ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; }
body {
  background: #e8e4de;
  font-family: Georgia, "Times New Roman", serif;
  color: #1a1208;
  min-height: 100vh;
}

/* ── Admin toolbar — screen only ─────────────────────────── */
.admin-bar {
  position: sticky; top: 0; z-index: 200;
  background: #0f0d09;
  border-bottom: 2px solid #c8901a;
  padding: 9px 24px;
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.admin-bar .bar-info { flex: 1; min-width: 180px; }
.admin-bar .bar-ref { font-size: 13px; font-weight: 700; color: #f0b429; font-family: monospace; }
.admin-bar .bar-meta { font-size: 11px; color: #9a8a74; margin-top: 2px; }
.admin-bar a, .admin-bar button {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 14px; border-radius: 7px;
  font-size: 12px; font-weight: 600; font-family: -apple-system, sans-serif;
  text-decoration: none; cursor: pointer;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.07); color: #f0e8d6;
  transition: background .15s;
}
.admin-bar a:hover, .admin-bar button:hover { background: rgba(255,255,255,.13); }
.admin-bar .print-btn { background: rgba(212,178,92,.18); border-color: rgba(212,178,92,.4); color: #f0b429; }
.admin-bar .print-btn:hover { background: rgba(212,178,92,.3); }
.badge-sent { padding: 2px 9px; border-radius: 99px; font-size: 10px; font-weight: 700;
  background: rgba(82,184,122,.15); border: 1px solid rgba(82,184,122,.3); color: #7ee0a0;
  font-family: -apple-system, sans-serif; }
.badge-pending { padding: 2px 9px; border-radius: 99px; font-size: 10px; font-weight: 700;
  background: rgba(200,144,26,.15); border: 1px solid rgba(200,144,26,.3); color: #e8cc80;
  font-family: -apple-system, sans-serif; }

/* ── Page wrapper (A4 proportions on screen) ──────────────── */
.page-wrap {
  max-width: 794px; /* A4 at 96dpi */
  margin: 28px auto 60px;
  background: #fdfbf8;
  box-shadow: 0 4px 32px rgba(0,0,0,.18);
}

/* ── Document header ──────────────────────────────────────── */
.doc-header {
  background: #1a1208;
  padding: 24px 44px 20px;
  border-bottom: 4px solid <?= $accentHex ?>;
  display: flex; justify-content: space-between; align-items: flex-start; gap: 24px;
}
.doc-header-left { flex: 1; }
.org-name { font-size: 22px; font-weight: 700; color: #f0b429; letter-spacing: .02em;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
.org-sub  { font-size: 11px; color: #9a8a74; margin-top: 3px; letter-spacing: .04em;
  font-family: -apple-system, sans-serif; }
.cert-type-badge {
  display: inline-block; margin-top: 14px;
  padding: 4px 12px; border-radius: 4px; font-size: 10px; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  background: rgba(<?= implode(',', sscanf($accentHex, '#%02x%02x%02x') ?? [139,105,20]) ?>,.25);
  border: 1px solid <?= $accentHex ?>50;
  color: <?= $accentHex ?>;
  font-family: -apple-system, sans-serif;
}
.doc-header-right { text-align: right; }
.cert-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em;
  color: #9a8a74; font-family: -apple-system, sans-serif; margin-bottom: 6px; }
.cert-ref-display { font-size: 20px; font-weight: 700; color: #f0b429;
  font-family: "Courier New", monospace; letter-spacing: .04em; }
.class-pill {
  display: inline-block; margin-top: 8px;
  padding: 3px 14px; border-radius: 99px; font-size: 13px; font-weight: 700;
  background: <?= $accentHex ?>22; border: 1.5px solid <?= $accentHex ?>;
  color: <?= $accentHex ?>;
  font-family: -apple-system, sans-serif;
}

/* ── Document body ────────────────────────────────────────── */
.doc-body { padding: 28px 44px 20px; }

/* Intro text */
.intro-text { font-size: 12px; line-height: 1.65; color: #3a2e1a; margin-bottom: 20px;
  border-bottom: 1px solid #ddd8ce; padding-bottom: 16px; }
.intro-text strong { color: #1a1208; }

/* Two-column holding details grid */
.details-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 0; border: 1.5px solid #c8b99a; border-radius: 4px;
  overflow: hidden; margin-bottom: 20px;
}
.detail-cell {
  padding: 11px 16px; border-bottom: 1px solid #ddd8ce;
}
.detail-cell:nth-child(odd) { border-right: 1px solid #ddd8ce; background: #fdfbf8; }
.detail-cell:nth-child(even) { background: #faf7f2; }
.detail-cell:nth-last-child(-n+2) { border-bottom: none; }
/* If odd total cells, last one spans */
.detail-cell.span2 { grid-column: 1 / -1; border-right: none; }
.detail-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: #8b7a5a; margin-bottom: 3px;
  font-family: -apple-system, sans-serif; }
.detail-value { font-size: 13.5px; font-weight: 600; color: #1a1208; line-height: 1.35; }
.detail-value.mono { font-family: "Courier New", monospace; font-size: 12.5px; }
.detail-value.large { font-size: 16px; color: <?= $accentHex ?>; font-weight: 700; }

/* Hash row */
.hash-row {
  border: 1px solid #ddd8ce; border-radius: 4px;
  padding: 10px 16px; margin-bottom: 20px; background: #f5f2ec;
}
.hash-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: #8b7a5a; margin-bottom: 4px;
  font-family: -apple-system, sans-serif; }
.hash-value { font-size: 10.5px; font-family: "Courier New", monospace;
  color: #3a2e1a; word-break: break-all; line-height: 1.5; }

/* Section headings */
.section-heading {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .09em;
  color: <?= $accentHex ?>; margin: 0 0 10px;
  padding-bottom: 5px; border-bottom: 2px solid <?= $accentHex ?>40;
  font-family: -apple-system, sans-serif;
}

/* Rights table */
.rights-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.rights-table tr { border-bottom: 1px solid #e8e2d8; }
.rights-table tr:last-child { border-bottom: none; }
.rights-table td { padding: 10px 14px; vertical-align: top; font-size: 12px; }
.rights-table td:first-child {
  width: 28%; font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: #8b7a5a; padding-top: 12px;
  font-family: -apple-system, sans-serif;
}
.rights-table td:nth-child(2) { font-size: 12.5px; color: #1a1208; font-weight: 600; line-height: 1.4; }
.rights-table td:last-child {
  width: 30%; font-size: 10.5px; color: #7a6a4e; line-height: 1.5;
  border-left: 1px solid #e8e2d8; padding-left: 14px;
}
.rights-table tr:nth-child(even) { background: #faf7f2; }

.smart-contract-list { margin: 0; padding: 0; list-style: none; }
.smart-contract-list li { font-size: 12px; color: #1a1208; line-height: 1.55;
  padding: 1px 0 1px 14px; position: relative; }
.smart-contract-list li::before { content: "›"; position: absolute; left: 0; color: <?= $accentHex ?>; font-weight: 700; }

/* Notice box for community/gov-alloc types */
.notice-box {
  border-left: 3px solid <?= $accentHex ?>; background: <?= $accentHex ?>0d;
  padding: 12px 16px; margin-bottom: 20px; border-radius: 0 4px 4px 0;
}
.notice-box p { font-size: 12px; line-height: 1.65; color: #3a2e1a; }
.notice-box strong { color: #1a1208; }

/* ── Governing instruments ─────────────────────────────────── */
.instruments-section {
  border-top: 1.5px solid #c8b99a; padding-top: 22px; margin-top: 6px;
}
.instruments-grid {
  display: grid; grid-template-columns: 1fr 1fr 1fr;
  gap: 12px; margin-bottom: 14px;
}
.instrument-card {
  border: 1px solid #ddd8ce; border-radius: 4px; padding: 10px 14px;
  background: #faf7f2;
}
.instrument-title { font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: <?= $accentHex ?>; margin-bottom: 4px;
  font-family: -apple-system, sans-serif; }
.instrument-name  { font-size: 11px; color: #1a1208; font-weight: 600; line-height: 1.4; }

/* ── Document footer ──────────────────────────────────────── */
.doc-footer {
  background: #1a1208;
  padding: 18px 44px;
  border-top: 3px solid <?= $accentHex ?>;
  display: flex; justify-content: space-between; align-items: flex-end; gap: 24px;
}
.footer-trustee { flex: 1; }
.footer-trustee-name { font-size: 13px; font-weight: 700; color: #f0e8d6;
  font-family: -apple-system, sans-serif; }
.footer-trustee-role { font-size: 10.5px; color: #9a8a74; margin-top: 2px;
  font-family: -apple-system, sans-serif; }
.footer-trustee-org  { font-size: 10px; color: #6b5c44; margin-top: 6px; line-height: 1.6;
  font-family: -apple-system, sans-serif; }
.footer-seal { text-align: right; }
.seal-circle {
  width: 64px; height: 64px; border-radius: 50%;
  border: 2.5px solid <?= $accentHex ?>80;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  margin-left: auto;
}
.seal-text { font-size: 7px; text-transform: uppercase; letter-spacing: .06em;
  color: <?= $accentHex ?>; text-align: center; line-height: 1.4;
  font-family: -apple-system, sans-serif; font-weight: 700; }

/* ── Print styles ──────────────────────────────────────────── */
@page {
  size: A4 portrait;
  margin: 0;
}
@media print {
  html, body { background: #fff !important; font-size: 14px; }
  .admin-bar { display: none !important; }
  .page-wrap { max-width: 100%; margin: 0; box-shadow: none; background: #fff !important; }

  /* Force colour on dark sections */
  .doc-header, .doc-footer {
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .rights-table tr:nth-child(even),
  .hash-row, .instrument-card, .notice-box, .detail-cell {
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }

  /* Compress header */
  .doc-header       { padding: 18px 32px 16px; }
  .org-name         { font-size: 18px; }
  .cert-ref-display { font-size: 16px; }
  .cert-type-badge  { margin-top: 8px; padding: 3px 9px; font-size: 9px; }
  .class-pill       { font-size: 11px; padding: 2px 10px; margin-top: 5px; }

  /* Compress body */
  .doc-body         { padding: 18px 32px 12px; }
  .intro-text       { font-size: 11px; line-height: 1.55; margin-bottom: 14px;
                      padding-bottom: 12px; }
  .section-heading  { font-size: 9.5px; margin: 0 0 8px; padding-bottom: 4px; }

  /* Compress details grid */
  .details-grid     { margin-bottom: 14px; }
  .detail-cell      { padding: 7px 12px; }
  .detail-label     { font-size: 8px; margin-bottom: 1px; }
  .detail-value     { font-size: 12px; }
  .detail-value.large { font-size: 13px; }
  .detail-value.mono  { font-size: 11px; }

  /* Compress hash */
  .hash-row         { padding: 7px 12px; margin-bottom: 14px; }
  .hash-label       { font-size: 8px; margin-bottom: 2px; }
  .hash-value       { font-size: 9.5px; line-height: 1.4; }

  /* Compress rights table */
  .rights-table     { margin-bottom: 14px; }
  .rights-table td  { padding: 7px 10px; font-size: 10.5px; }
  .rights-table td:first-child  { font-size: 8px; padding-top: 9px; }
  .rights-table td:nth-child(2) { font-size: 11px; line-height: 1.3; }
  .rights-table td:last-child   { font-size: 9.5px; line-height: 1.4; }
  .smart-contract-list li { font-size: 10.5px; line-height: 1.4; }

  /* Compress notice */
  .notice-box { padding: 8px 12px; margin-bottom: 12px; }
  .notice-box p { font-size: 10.5px; line-height: 1.5; }

  /* Compress instruments */
  .instruments-section { padding-top: 12px; }
  .instruments-grid    { gap: 10px; margin-bottom: 12px; }
  .instrument-card     { padding: 7px 10px; }
  .instrument-title    { font-size: 8px; margin-bottom: 2px; }
  .instrument-name     { font-size: 10px; }

  /* Compress footer */
  .doc-footer           { padding: 14px 32px; }
  .footer-trustee-name  { font-size: 11px; }
  .footer-trustee-role  { font-size: 9.5px; }
  .footer-trustee-org   { font-size: 9px; margin-top: 3px; }
  .seal-circle          { width: 50px; height: 50px; }
  .seal-text            { font-size: 6px; }

  a { text-decoration: none; color: inherit; }
}
</style>
</head>
<body>

<!-- Admin toolbar (screen only) -->
<div class="admin-bar">
  <div class="bar-info">
    <div class="bar-ref"><?= ucv_h($row['cert_ref'] ?? '') ?></div>
    <div class="bar-meta">
      <?= ucv_h($row['full_name'] ?? '') ?>
      &nbsp;·&nbsp;
      <?= ucv_h($className) ?> (Class <?= ucv_h($classCode) ?>)
      <?php if ($emailSentAt): ?>
        &nbsp;·&nbsp; <span class="badge-sent">✅ Emailed <?= $emailSentAt ?></span>
      <?php else: ?>
        &nbsp;·&nbsp; <span class="badge-pending">⏳ Email pending</span>
      <?php endif; ?>
    </div>
  </div>
  <button class="print-btn" onclick="window.print()">🖨 Print / Save PDF</button>
  <a href="<?= $regUrl ?>">📚 Register</a>
  <a href="<?= $backUrl ?>">← Certs</a>
</div>

<!-- A4 Document -->
<div class="page-wrap">

  <!-- Document header -->
  <div class="doc-header">
    <div class="doc-header-left">
      <div class="org-name">COG$ of Australia Foundation</div>
      <div class="org-sub">Community Joint Venture — Wahlubal Country, Bundjalung Nation</div>
      <div class="cert-type-badge"><?= ucv_h($certTypeLabel) ?></div>
    </div>
    <div class="doc-header-right">
      <div class="cert-title">Certificate of Unit Holding</div>
      <div class="cert-ref-display"><?= ucv_h($row['cert_ref'] ?? '') ?></div>
      <div class="class-pill">Class <?= ucv_h($classCode) ?></div>
    </div>
  </div>

  <!-- Document body -->
  <div class="doc-body">

    <!-- Intro -->
    <p class="intro-text">
      This Certificate of Unit Holding is issued under the authority of the
      <strong>CJVM Hybrid Trust Declaration</strong> and
      <strong>Sub-Trust A Deed</strong> by the Caretaker Trustee of the
      <strong>COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust</strong>
      (ABN 61 734 327 831). It constitutes the formal legal record of unit holding for the
      unitholder named below.
    </p>

    <!-- Holding details -->
    <p class="section-heading">Unit Holding Details</p>
    <div class="details-grid">
      <div class="detail-cell">
        <div class="detail-label">Certificate Reference</div>
        <div class="detail-value large mono"><?= ucv_h($row['cert_ref'] ?? '') ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Register Reference</div>
        <div class="detail-value mono"><?= ucv_h($row['register_ref'] ?? '') ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Unitholder</div>
        <div class="detail-value"><?= ucv_h($row['full_name'] ?? '') ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Member Number</div>
        <div class="detail-value mono"><?= ucv_h($row['member_number'] ?? '') ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Unit Class</div>
        <div class="detail-value"><?= ucv_h($className) ?> <span style="font-size:11px;font-weight:400;color:#7a6a4e;">(Class <?= ucv_h($classCode) ?>)</span></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Units Issued</div>
        <div class="detail-value large"><?= ucv_h($units) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Issue Date</div>
        <div class="detail-value"><?= ucv_h(date('j F Y', strtotime((string)($row['issue_date'] ?? 'today')))) ?></div>
      </div>
      <div class="detail-cell">
        <div class="detail-label">Consideration</div>
        <div class="detail-value"><?= ucv_h($consDisplay) ?></div>
      </div>
      <div class="detail-cell span2">
        <div class="detail-label">Issuance Gate</div>
        <div class="detail-value"><?= ucv_h($gateLabel) ?></div>
      </div>
    </div>

    <!-- Cryptographic record -->
    <div class="hash-row">
      <div class="hash-label">Cryptographic Record — SHA-256 Hash</div>
      <div class="hash-value"><?= ucv_h($row['sha256_hash'] ?? '') ?></div>
    </div>

    <!-- Notice for community / governance alloc types -->
    <?php if ($isCommunity): ?>
    <div class="notice-box">
      <p><strong>Important:</strong> Class C Community COG$ units carry <strong>no Sub-Trust B income unit and no yield</strong>. They are not a financial investment. They function as an immutable record of barter and service exchange between Members within the Foundation ecosystem.</p>
    </div>
    <?php elseif ($isGovAlloc): ?>
    <div class="notice-box">
      <p><strong>Important:</strong> Class Lr Resident COG$ units carry <strong>no Beneficial Unit, no yield, and no national governance vote</strong>. This is a local governance allocation only.</p>
    </div>
    <?php endif; ?>

    <!-- Rights and attributes -->
    <p class="section-heading">Rights and Attributes — Class <?= ucv_h($classCode) ?></p>
    <p style="font-size:11px;color:#7a6a4e;margin-bottom:14px;line-height:1.55;">
      The following rights and smart contract attributes apply exclusively to this unit class
      as defined in the governing instruments. No rights from other unit classes apply to this certificate.
    </p>
    <table class="rights-table">
      <tbody>
        <?php foreach ($rights as [$label, $value, $note]): ?>
        <tr>
          <td><?= ucv_h($label) ?></td>
          <td>
            <?php if ($label === 'Smart contract attributes'): ?>
              <ul class="smart-contract-list">
                <?php foreach (array_filter(explode("\n", $value)) as $item): ?>
                <li><?= ucv_h(trim($item)) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <?= nl2br(ucv_h($value)) ?>
            <?php endif; ?>
          </td>
          <td><?= ucv_h($note) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Governing instruments -->
    <div class="instruments-section">
      <p class="section-heading">Governing Instruments</p>
      <div class="instruments-grid">
        <div class="instrument-card">
          <div class="instrument-title">Supreme Instrument</div>
          <div class="instrument-name">Joint Venture Participation Agreement (JVPA)</div>
        </div>
        <div class="instrument-card">
          <div class="instrument-title">Trust Declaration</div>
          <div class="instrument-name">CJVM Hybrid Trust Declaration</div>
        </div>
        <div class="instrument-card">
          <div class="instrument-title">Sub-Trust Deed</div>
          <div class="instrument-name">Sub-Trust A Deed (cl. 7, 11, 13)</div>
        </div>
      </div>
      <p style="font-size:10.5px;color:#7a6a4e;line-height:1.6;">
        <strong style="color:#3a2e1a;">Governing law:</strong> South Australia, Australia &nbsp;·&nbsp;
        <strong style="color:#3a2e1a;">ABN:</strong> 61 734 327 831 &nbsp;·&nbsp;
        <strong style="color:#3a2e1a;">Registered office:</strong> Drake Village Resource Centre, Drake Village NSW 2469 &nbsp;·&nbsp;
        Wahlubal Country, Bundjalung Nation
      </p>
    </div>

  </div><!-- /doc-body -->

  <!-- Document footer -->
  <div class="doc-footer">
    <div class="footer-trustee">
      <div class="footer-trustee-name">Thomas Boyd Cunliffe</div>
      <div class="footer-trustee-role">Caretaker Trustee</div>
      <div class="footer-trustee-org">
        COGS of Australia Foundation<br>
        Drake Village NSW 2469 &nbsp;·&nbsp; members@cogsaustralia.org
      </div>
    </div>
    <div class="footer-seal">
      <div class="seal-circle">
        <div class="seal-text">COG$<br>FOUNDATION<br>ISSUED<br><?= ucv_h(date('Y', strtotime((string)($row['issue_date'] ?? 'today')))) ?></div>
      </div>
    </div>
  </div>

</div><!-- /page-wrap -->

</body>
</html>
