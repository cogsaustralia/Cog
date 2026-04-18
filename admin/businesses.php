<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once __DIR__ . '/../_app/api/config/bootstrap.php';

ops_require_admin();
$pdo = ops_db();

if (!function_exists('h')) { function h($v): string { return ops_h($v); } }
if (!function_exists('rows')) { function rows(PDO $pdo, string $sql, array $p=[]): array { $st=$pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC)?:[]; } }
if (!function_exists('one'))  { function one(PDO $pdo, string $sql, array $p=[]): ?array { $st=$pdo->prepare($sql); $st->execute($p); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?:null; } }

/* ── POST actions ── */
$flash = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    $act = (string)($_POST['action'] ?? '');

    // Approve business
    if ($act === 'approve' && !empty($_POST['biz_id'])) {
        $bizId = (int)$_POST['biz_id'];
        $pdo->prepare("UPDATE bnft_memberships SET wallet_status='active', updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$bizId]);
        $flash = "Business #$bizId approved.";
    }

    // Mark payment received
    if ($act === 'mark_paid' && !empty($_POST['biz_id'])) {
        $bizId = (int)$_POST['biz_id'];
        $pdo->prepare("UPDATE bnft_memberships SET signup_payment_status='paid', updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$bizId]);
        $flash = "Payment marked as received for business #$bizId.";
    }
}

/* ── Data ── */
$sql = "
SELECT
    b.*,
    m.full_name AS responsible_name,
    m.member_number AS responsible_member_number,
    m.email AS responsible_email,
    sa.attestation_hash AS stewardship_hash,
    sa.score AS stewardship_score,
    sa.total_questions AS stewardship_total,
    sa.completed_at AS stewardship_at,
    sa.answers_json AS stewardship_answers
FROM bnft_memberships b
LEFT JOIN snft_memberships s ON s.id = b.responsible_member_id
LEFT JOIN members m ON m.member_number = s.member_number
LEFT JOIN stewardship_attestations sa
    ON sa.subject_type = 'bnft_business' AND sa.subject_id = b.id
ORDER BY b.id DESC
LIMIT 50";
$rows = rows($pdo, $sql);

// Deduplicate — keep latest stewardship row per business (last row wins)
$seen = [];
$deduped = [];
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $seen[$id] = $r;
}
$rows = array_values($seen);

$totalBiz = count($rows);
$paidCount = count(array_filter($rows, fn($r) => ($r['signup_payment_status'] ?? '') === 'paid'));
$gnafCount = count(array_filter($rows, fn($r) => !empty($r['gnaf_pid'])));
$stewardCount = count(array_filter($rows, fn($r) => !empty($r['stewardship_hash'])));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Business Members | COGs Admin</title>
<style>
:root{--line:rgba(255,255,255,.08);--muted:#9fb0c1}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',system-ui,sans-serif;background:#0c1018;color:#eef2f7;min-height:100vh;font-size:14px}
.main{padding:20px}
.card{background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:14px}
.msg{padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:10px}
.msg.ok{background:rgba(82,184,122,.08);border:1px solid rgba(82,184,122,.25);color:#7ee0a0}
.msg.err{background:rgba(200,60,60,.08);border:1px solid rgba(200,60,60,.25);color:#ffb4be}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;padding:8px 10px;border-bottom:2px solid var(--line)}
td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.kpi{padding:14px;border-radius:16px;border:1px solid var(--line);background:rgba(255,255,255,.03)}
.kpi .label{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.05em}
.kpi .value{font-size:24px;font-weight:800;margin-top:6px}
.chip{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600}
.chip-ok{background:rgba(82,184,122,.12);color:#7ee0a0}
.chip-warn{background:rgba(212,160,60,.12);color:#d4b25c}
.chip-dim{background:rgba(255,255,255,.04);color:#9fb0c1}
.chip-teal{background:rgba(96,212,184,.12);color:#60d4b8}
.btn{font-size:12px;padding:5px 12px;border-radius:8px;border:none;cursor:pointer;font-family:inherit}
.btn-ok{background:#2d7a4f;color:#e0fce8}.btn-ok:hover{background:#348f5c}
.btn-gold{background:#b98b2f;color:#201507}.btn-gold:hover{background:#d4a03c}
.secondary{font-size:12px;padding:5px 12px;border-radius:8px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:#eef2f7;cursor:pointer}
.secondary:hover{background:rgba(255,255,255,.08)}
.detail-panel{padding:16px;background:rgba(255,255,255,.02);border-top:1px solid rgba(255,255,255,.06)}
.detail-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px}
.detail-section{margin-top:10px;padding:12px 14px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.08);border-radius:12px}
.dlabel{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px}
@media(max-width:980px){.shell{grid-template-columns:1fr}.kpi-grid{grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php ops_admin_help_assets_once(); ?>
<div class="shell">
<?php admin_sidebar_render('businesses'); ?>
<main class="main">
  <div class="card">
    <h1 style="margin:0 0 8px">Business Registry (B-NFT) <?= ops_admin_help_button('Business Registry', 'Use this page to understand each business partner record, its responsible person, payment state, address verification, stewardship evidence, and whether the business wallet can be activated. This page explains the record; later pages still handle approvals and execution.') ?></h1>
    <p style="color:#9fb0c1;margin:0 0 14px">All registered B-NFT business memberships from <code>bnft_memberships</code>. Linked to their responsible person's S-NFT membership.</p>
  </div>

  <?= ops_admin_info_panel('Stage 4 · Business entities', 'What this page does', 'The Business Registry is the operator view of BNFT-linked business records. It shows business identity, responsible-person linkage, entry contribution state, address verification, stewardship evidence, and whether the business wallet is active.', [
    'Use this page to understand the record before taking support actions such as marking the entry contribution as received or activating the wallet.',
    'Do not use this page to perform later approval or execution tasks; those remain on the Approvals and Execution pages.',
    'Responsible-person linkage matters because a business record depends on a valid personal Partner pathway behind it.'
  ]) ?>

  <?= ops_admin_workflow_panel('Typical workflow', 'Use this page as the explanatory and support surface for business records.', [
    ['title' => 'Review identity', 'body' => 'Check ABN, legal name, trading name, entity type, and the responsible person linkage.'],
    ['title' => 'Check payment and evidence', 'body' => 'Confirm the $40 entry contribution, G-NAF status, and any stewardship attestation or wallet prerequisites.'],
    ['title' => 'Use support actions', 'body' => 'Mark paid only when the contribution is actually received. Approve only when the record is ready to be active in the wallet.'],
    ['title' => 'Move to later operations', 'body' => 'After the record is sound, later stages such as approvals, governance, or execution happen on their dedicated pages.']
  ]) ?>

  <?= ops_admin_guide_panel('How to read the business registry', 'Each visible field answers a different operator question about the business record.', [
    ['title' => 'Business identity', 'body' => 'Tells you what entity the record actually belongs to and how it presents publicly.'],
    ['title' => 'Responsible person', 'body' => 'Shows which personal Partner is linked to the business record and acts as the responsible human pathway.'],
    ['title' => 'Payment', 'body' => 'Shows whether the entry contribution has been received; it does not by itself approve all other classes.'],
    ['title' => 'Stewardship', 'body' => 'Shows whether the stewardship questionnaire/attestation trail is present for this business.']
  ]) ?>

  <?= ops_admin_status_panel('Field and status guide', 'These are the main statuses operators need to read correctly on this page.', [
    ['label' => 'Paid', 'body' => 'Shows whether the business entry contribution has been recorded as received.'],
    ['label' => 'G-NAF verified', 'body' => 'Shows whether the business address has been normalised or linked to a verified address reference.'],
    ['label' => 'Stewardship done', 'body' => 'Shows whether stewardship attestation data exists for the business.'],
    ['label' => 'Wallet active', 'body' => 'Shows whether the business vault is active. It is a wallet-state indicator, not a substitute for broader operational approval.']
  ]) ?>


  <?php if($flash): ?><div class="msg ok"><?=h($flash)?></div><?php endif; ?>
  <?php if($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>

  <div class="card">
    <div class="kpi-grid">
      <div class="kpi"><div class="label">Total Businesses</div><div class="value"><?=$totalBiz?></div></div>
      <div class="kpi"><div class="label">Paid ($40)</div><div class="value" style="color:#7ee0a0"><?=$paidCount?></div></div>
      <div class="kpi"><div class="label">G-NAF Verified</div><div class="value" style="color:#60d4b8"><?=$gnafCount?></div></div>
      <div class="kpi"><div class="label">Stewardship Done</div><div class="value" style="color:#d4b25c"><?=$stewardCount?></div></div>
    </div>
  </div>

  <div class="card" style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>Business <?= ops_admin_help_button('Business', 'The legal entity record being reviewed. Open the row to see the full identity, trading, and use-case details.') ?></th>
          <th>ABN</th>
          <th>Responsible Person <?= ops_admin_help_button('Responsible person', 'The personal Partner linked to this business record. This is the human pathway behind the BNFT record.') ?></th>
          <th>Payment <?= ops_admin_help_button('Payment', 'Shows whether the business entry contribution has been recorded. Use Mark Paid only after money is actually received.') ?></th>
          <th>G-NAF</th>
          <th>Stewardship <?= ops_admin_help_button('Stewardship', 'Shows whether stewardship attestation data exists for this business and how complete it is.') ?></th>
          <th style="width:28px"></th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="7" style="text-align:center;color:#9fb0c1;padding:24px">No business registrations yet.</td></tr>
      <?php endif; ?>
      <?php foreach($rows as $r):
        $rowId = 'brow-' . (int)$r['id'];
        $payStatus = ($r['signup_payment_status'] ?? 'pending');
        $hasGnaf = !empty($r['gnaf_pid']);
        $hasSteward = !empty($r['stewardship_hash']);
      ?>
        <tr onclick="toggleBiz('<?=$rowId?>')" style="cursor:pointer" id="<?=$rowId?>-hdr">
          <td>
            <strong style="font-size:13px"><?=h($r['legal_name'] ?? '')?></strong>
            <?php if(!empty($r['trading_name'])): ?><div style="font-size:11px;color:#9fb0c1">t/a <?=h($r['trading_name'])?></div><?php endif; ?>
            <div style="font-size:11px;color:#60d4b8"><?=h($r['entity_type'] ?? '')?></div>
          </td>
          <td style="font-size:12px;font-family:monospace;color:#9fb0c1"><?=h($r['abn'] ?? '')?></td>
          <td>
            <div style="font-size:12px"><?=h($r['responsible_name'] ?? $r['contact_name'] ?? '—')?></div>
            <div style="font-size:11px;color:#9fb0c1"><?=h($r['responsible_member_number'] ?? '')?></div>
          </td>
          <td>
            <?php if($payStatus === 'paid'): ?>
              <span class="chip chip-ok">Paid</span>
            <?php else: ?>
              <span class="chip chip-warn"><?=h($payStatus)?></span>
            <?php endif; ?>
          </td>
          <td><?php if($hasGnaf): ?><span style="color:#7ee0a0;font-size:11px">✓</span><?php else: ?><span style="color:#9fb0c1;font-size:11px">—</span><?php endif; ?></td>
          <td><?php if($hasSteward): ?><span style="color:#d4b25c;font-size:11px">✓ <?=(int)$r['stewardship_score']?>/<?=(int)$r['stewardship_total']?></span><?php else: ?><span style="color:#9fb0c1;font-size:11px">—</span><?php endif; ?></td>
          <td style="text-align:center;color:#9fb0c1;font-size:12px" id="<?=$rowId?>-chev">▼</td>
        </tr>
        <tr id="<?=$rowId?>" style="display:none">
          <td colspan="7" style="padding:0">
            <div class="detail-panel">
              <div class="detail-grid">
                <!-- Col 1: Business identity -->
                <div>
                  <div class="dlabel">Business Identity</div>
                  <div style="font-size:13px;font-weight:700"><?=h($r['legal_name']??'')?></div>
                  <?php if(!empty($r['trading_name'])): ?><div style="font-size:12px;color:#9fb0c1">Trading as: <?=h($r['trading_name'])?></div><?php endif; ?>
                  <div style="font-size:12px;color:#9fb0c1">ABN: <?=h($r['abn']??'')?></div>
                  <div style="font-size:12px;color:#9fb0c1">Entity: <?=h($r['entity_type']??'')?></div>
                  <div style="font-size:12px;color:#9fb0c1"><?=h($r['industry']??'')?></div>
                  <?php if(!empty($r['website'])): ?><div style="font-size:12px;color:#60d4b8"><?=h($r['website'])?></div><?php endif; ?>
                  <?php if(!empty($r['use_case'])): ?><div style="font-size:12px;color:#9fb0c1;margin-top:4px;font-style:italic">"<?=h($r['use_case'])?>"</div><?php endif; ?>
                  <div style="margin-top:8px"><span class="chip chip-<?=($r['wallet_status']??'')==='active'?'ok':'dim'?>"><?=h($r['wallet_status']??'pending')?></span></div>
                </div>
                <!-- Col 2: Responsible Person -->
                <div>
                  <div class="dlabel">Responsible Person</div>
                  <div style="font-size:13px;font-weight:700"><?=h($r['responsible_name'] ?? $r['contact_name'] ?? '—')?></div>
                  <div style="font-size:12px;color:#9fb0c1"><?=h($r['position_title']??'')?></div>
                  <div style="font-size:12px;color:#9fb0c1">S-NFT: <?=h($r['responsible_member_number']??'Not linked')?></div>
                  <div style="font-size:12px;color:#9fb0c1"><?=h($r['email']??'')?></div>
                  <div style="font-size:12px;color:#9fb0c1"><?=h($r['mobile']??'')?></div>
                  <div style="font-size:12px;color:#9fb0c1"><?=h($r['state_code']??'')?></div>
                </div>
                <!-- Col 3: Payment & Tokens -->
                <div>
                  <div class="dlabel">Payment &amp; Reservation</div>
                  <div style="font-size:13px;font-weight:700;margin-bottom:4px">
                    <?php if($payStatus==='paid'): ?><span style="color:#7ee0a0">✓ $40.00 Paid</span>
                    <?php else: ?><span style="color:#ffb4be">● $40.00 Outstanding</span><?php endif; ?>
                  </div>
                  <div style="font-size:12px;color:#9fb0c1">ASX: <?=number_format((int)($r['invest_tokens']??0))?> · BP: <?=number_format((int)($r['reserved_tokens']??0))?></div>
                  <div style="font-size:12px;color:#9fb0c1">RWA: <?=number_format((int)($r['rwa_tokens']??0))?> · Don: <?=number_format((int)($r['donation_tokens']??0))?></div>
                  <div style="font-size:12px;color:#9fb0c1">PIF: <?=number_format((int)($r['pay_it_forward_tokens']??0))?></div>
                  <div style="font-size:12px;color:#d4b25c;margin-top:4px">Value: $<?=number_format((float)($r['reservation_value']??0),2)?></div>
                  <?php if($payStatus!=='paid'): ?>
                    <form method="post" style="margin-top:8px;display:inline">
                      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                      <input type="hidden" name="action" value="mark_paid">
                      <input type="hidden" name="biz_id" value="<?=(int)$r['id']?>">
                      <button type="submit" class="btn btn-ok" onclick="return confirm('Mark payment as received?')">Mark Paid</button><?= ops_admin_help_button('Mark Paid', 'Use this only when the $40 business entry contribution has actually been received. This records payment; it does not itself perform broader approval or execution.') ?>
                    </form>
                  <?php endif; ?>
                  <?php if(($r['wallet_status']??'')!=='active'): ?>
                    <form method="post" style="margin-top:4px;display:inline">
                      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="biz_id" value="<?=(int)$r['id']?>">
                      <button type="submit" class="btn btn-gold" onclick="return confirm('Approve this business?')">Approve</button><?= ops_admin_help_button('Approve business', 'Use this when the business record is complete enough to activate the wallet pathway. It is a support activation step, not a governance or execution action.') ?>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Verification panels -->
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <!-- G-NAF Address Verification -->
                <?php if(!empty($r['street_address'])): ?>
                <div class="detail-section">
                  <div class="dlabel">Address Verification (G-NAF)</div>
                  <div style="font-size:13px;margin-bottom:8px">
                    <?=h($r['street_address']??'')?>, <?=h($r['suburb']??'')?> <?=h($r['state_code']??'')?> <?=h($r['postcode']??'')?>
                  </div>
                  <?php if(!empty($r['gnaf_pid'])): ?>
                    <div style="font-size:12px;color:#7ee0a0;margin-bottom:6px">✓ Verified — PID: <?=h($r['gnaf_pid'])?></div>
                  <?php else: ?>
                    <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Not yet verified</div>
                  <?php endif; ?>
                  <button class="secondary" style="margin-top:8px;font-size:12px;padding:6px 14px"
                    onclick="gnafVerify(<?=(int)$r['id']?>, this)" type="button"
                  ><?=empty($r['gnaf_pid']) ? 'Run G-NAF Verification' : 'Re-verify Address'?></button>
                  <div id="gnaf-result-<?=(int)$r['id']?>" style="margin-top:8px;font-size:12px;display:none"></div>
                </div>
                <?php else: ?>
                <div class="detail-section">
                  <div class="dlabel">Address Verification</div>
                  <div style="font-size:12px;color:var(--muted)">No address on file.</div>
                </div>
                <?php endif; ?>

                <!-- Stewardship -->
                <div class="detail-section">
                  <div class="dlabel">Stewardship Attestation</div>
                  <?php if($hasSteward): ?>
                    <div style="font-size:12px;color:#d4b25c;margin-bottom:6px">
                      ✓ Completed <?=(int)$r['stewardship_score']?>/<?=(int)$r['stewardship_total']?> —
                      <?=h(substr($r['stewardship_at']??'',0,16))?>
                    </div>
                    <div style="font-size:11px;color:var(--muted);word-break:break-all;margin-bottom:4px">
                      Hash: <?=h(substr($r['stewardship_hash']??'',0,32))?>…
                    </div>
                    <?php
                      $answers = json_decode($r['stewardship_answers'] ?? '{}', true) ?: [];
                      if($answers):
                    ?>
                    <div style="font-size:12px;margin-top:6px">
                      <?php foreach($answers as $k=>$v): ?>
                        <div style="color:#9fb0c1;margin-bottom:2px"><strong style="color:#eef2f7"><?=h($k)?></strong>: <?=h($v)?></div>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div style="font-size:12px;color:var(--muted)">Not yet completed.</div>
                  <?php endif; ?>
                  <?php if(!empty($r['attestation_hash'])): ?>
                    <div style="font-size:11px;color:var(--muted);margin-top:6px;word-break:break-all">
                      Join attestation: <?=h(substr($r['attestation_hash'],0,32))?>…
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div style="font-size:11px;color:var(--muted);margin-top:10px">
                Created: <?=h($r['created_at']??'')?> · Updated: <?=h($r['updated_at']??'')?>
                · ID: <?=(int)$r['id']?>
                · Responsible member ID: <?=(int)($r['responsible_member_id']??0)?>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</div>

<script>
function toggleBiz(id) {
  var row = document.getElementById(id);
  var chev = document.getElementById(id + '-chev');
  if (!row) return;
  var open = row.style.display !== 'none';
  row.style.display = open ? 'none' : '';
  if (chev) chev.textContent = open ? '▼' : '▲';
}

function gnafVerify(bizId, btn) {
  var resultEl = document.getElementById('gnaf-result-' + bizId);
  if (!resultEl) return;
  btn.disabled = true;
  btn.textContent = 'Verifying…';
  resultEl.style.display = 'none';

  fetch('../_app/api/index.php?route=address-verify', {
    method: 'POST',
    credentials: 'include',
    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
    body: JSON.stringify({action: 'business', business_id: bizId})
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    resultEl.style.display = 'block';
    if (d.success === false || d.error) {
      resultEl.innerHTML = '<span style="color:#ffb4be">Error: ' + (d.error || 'Verification failed') + '</span>';
    } else {
      var data = d.data || d;
      resultEl.innerHTML =
        '<span style="color:#7ee0a0">✓ Verified</span>' +
        (data.gnaf_pid ? '<br>PID: ' + data.gnaf_pid : '') +
        (data.gnaf_address ? '<br>Address: ' + data.gnaf_address : '') +
        (data.confidence ? '<br>Confidence: ' + data.confidence + '%' : '');
    }
    btn.disabled = false;
    btn.textContent = 'Re-verify Address';
  })
  .catch(function(e) {
    resultEl.style.display = 'block';
    resultEl.innerHTML = '<span style="color:#ffb4be">Network error: ' + e.message + '</span>';
    btn.disabled = false;
    btn.textContent = 'Re-verify Address';
  });
}
</script>
</body>
</html>
