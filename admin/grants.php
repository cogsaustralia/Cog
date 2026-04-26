<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
if (file_exists(__DIR__ . '/includes/LedgerEmitter.php')) require_once __DIR__ . '/includes/LedgerEmitter.php';

ops_require_admin();
$pdo     = ops_db();
$adminId = ops_admin_id();

function gr_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function gr_dollars(int $cents): string { return '$' . number_format($cents / 100, 2); }
function gr_val(PDO $p, string $q, array $b=[]): mixed { try{$s=$p->prepare($q);$s->execute($b);return $s->fetchColumn();}catch(Throwable $e){return null;} }
function gr_rows(PDO $p, string $q, array $b=[]): array { try{$s=$p->prepare($q);$s->execute($b);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){return[];} }

$hasGrants  = ops_has_table($pdo, 'grants');
$hasLedger  = ops_has_table($pdo, 'ledger_entries');

$flash = null; $flashType = 'ok';
if (isset($_GET['flash'])) { $flash=(string)$_GET['flash']; $flashType=(string)($_GET['type']??'ok'); }

$grantTypes = ['community_project'=>'Community project','first_nations'=>'First Nations','environment'=>'Environment','education'=>'Education','other'=>'Other'];

// ── POST ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    $action = trim((string)($_POST['action'] ?? ''));
    try {

        // ── Create grant ──────────────────────────────────────────────────
        if ($action === 'create_grant') {
            if (!$hasGrants) throw new RuntimeException('grants table not found.');
            $grantType  = trim((string)($_POST['grant_type'] ?? ''));
            $title      = trim((string)($_POST['title'] ?? ''));
            $grantee    = trim((string)($_POST['grantee_name'] ?? ''));
            $granteeAbn = trim((string)($_POST['grantee_abn'] ?? ''));
            $acnc       = !empty($_POST['grantee_acnc_registered']) ? 1 : 0;
            $amountStr  = trim((string)($_POST['amount'] ?? ''));
            $fy         = trim((string)($_POST['financial_year'] ?? ''));
            $isFN       = !empty($_POST['is_first_nations']) ? 1 : 0;
            $fnacReq    = !empty($_POST['fnac_approval_required']) ? 1 : 0;
            $desc       = trim((string)($_POST['description'] ?? ''));
            $notes      = trim((string)($_POST['notes'] ?? ''));

            if (!array_key_exists($grantType, $grantTypes)) throw new RuntimeException('Invalid grant type.');
            if ($title === '') throw new RuntimeException('Title is required.');
            if ($grantee === '') throw new RuntimeException('Grantee name is required.');
            if (!is_numeric($amountStr) || (float)$amountStr <= 0) throw new RuntimeException('Valid amount is required.');
            if ($fy === '') throw new RuntimeException('Financial year is required.');

            $amountCents = (int)round((float)$amountStr * 100);
            $fy_suffix   = str_replace('-', '', substr($fy, 0, 7));
            $grantRef    = 'GRT-' . $fy_suffix . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));

            $pdo->prepare("
                INSERT INTO grants
                  (grant_ref, grant_type, is_first_nations, title, description, grantee_name,
                   grantee_abn, grantee_acnc_registered, amount_cents, currency_code,
                   trust_account_id, financial_year, fnac_approval_required,
                   status, notes, created_by_admin_id, created_at, updated_at)
                VALUES (?,?,?,?,?,?, ?,?,?,'AUD', 6,?,?, 'proposed',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ")->execute([
                $grantRef, $grantType, $isFN, $title, $desc?:null, $grantee,
                $granteeAbn?:null, $acnc, $amountCents,
                $fy, $fnacReq,
                $notes?:null, $adminId,
            ]);
            $flash = "Grant {$grantRef} created for " . gr_dollars($amountCents) . " to {$grantee}.";
        }

        // ── FNAC approve ──────────────────────────────────────────────────
        if ($action === 'fnac_approve') {
            $id = (int)($_POST['grant_id'] ?? 0);
            $fnacRef = trim((string)($_POST['fnac_approval_ref'] ?? ''));
            if (!$id) throw new RuntimeException('Grant ID missing.');
            $pdo->prepare("UPDATE grants SET fnac_approved=1, fnac_approved_at=UTC_TIMESTAMP(), fnac_approval_ref=?, status=CASE WHEN status='fnac_review' THEN 'approved' ELSE status END, updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([$fnacRef?:null, $id]);
            $flash = 'FNAC approval recorded.';
        }

        // ── Board approve ─────────────────────────────────────────────────
        if ($action === 'board_approve') {
            $id = (int)($_POST['grant_id'] ?? 0);
            if (!$id) throw new RuntimeException('Grant ID missing.');
            $g = gr_rows($pdo, "SELECT * FROM grants WHERE id=? LIMIT 1", [$id]);
            if (empty($g)) throw new RuntimeException('Grant not found.');
            $g = $g[0];
            if ($g['fnac_approval_required'] && !$g['fnac_approved'])
                throw new RuntimeException('FNAC approval required before Board approval for this grant.');
            $pdo->prepare("UPDATE grants SET status='approved', approved_by_admin_id=?, approved_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('proposed','fnac_review')")
                ->execute([$adminId, $id]);
            $flash = 'Grant approved by Board.';
        }

        // ── Disburse + emit Godley ────────────────────────────────────────
        if ($action === 'disburse') {
            $id     = (int)($_POST['grant_id'] ?? 0);
            $bankRef= trim((string)($_POST['bank_reference'] ?? ''));
            if (!$id) throw new RuntimeException('Grant ID missing.');
            $g = gr_rows($pdo, "SELECT * FROM grants WHERE id=? LIMIT 1", [$id]);
            if (empty($g)) throw new RuntimeException('Grant not found.');
            $g = $g[0];
            if ($g['status'] !== 'approved') throw new RuntimeException('Grant must be approved before disbursement.');

            $amountCents = (int)$g['amount_cents'];
            $godleyRef   = 'GDLY-GRANT-' . $g['grant_ref'];

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE grants SET status='disbursed', disbursed_at=UTC_TIMESTAMP(), bank_reference=?, updated_at=UTC_TIMESTAMP() WHERE id=?")
                    ->execute([$bankRef?:null, $id]);

                if (class_exists('LedgerEmitter')) {
                    $res = LedgerEmitter::emitTransaction(
                        $pdo, $godleyRef, 'grants', $id,
                        LedgerEmitter::buildGrantEntries($amountCents),
                        date('Y-m-d')
                    );
                    if ($res['status'] === 'error') throw new RuntimeException('Godley emission failed: ' . $res['message']);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $flash = "Grant {$g['grant_ref']} disbursed — " . gr_dollars($amountCents) . " to {$g['grantee_name']}. Godley entries emitted.";
        }

        // ── Record DGR gift received ──────────────────────────────────────
        if ($action === 'acquit') {
            $id           = (int)($_POST['grant_id'] ?? 0);
            $acquittalRef = trim((string)($_POST['acquittal_ref'] ?? ''));
            $acquittalNote= trim((string)($_POST['acquittal_note'] ?? ''));
            if (!$id) throw new RuntimeException('Grant ID missing.');
            $g = gr_rows($pdo, "SELECT * FROM grants WHERE id=? LIMIT 1", [$id]);
            if (empty($g)) throw new RuntimeException('Grant not found.');
            if ($g[0]['status'] !== 'disbursed') throw new RuntimeException('Only disbursed grants can be acquitted.');
            $pdo->prepare(
                "UPDATE grants SET status='acquitted', acquittal_received_at=UTC_TIMESTAMP(),
                 bank_reference=COALESCE(?,bank_reference), notes=CONCAT(COALESCE(notes,''),?),
                 updated_at=UTC_TIMESTAMP() WHERE id=?"
            )->execute([
                $acquittalRef ?: null,
                $acquittalNote ? "\nACQUITTAL: {$acquittalNote}" : '',
                $id,
            ]);
            $flash = "Grant {$g[0]['grant_ref']} acquitted — charitable purpose verified.";
        }

        // ── Record DGR gift received ──────────────────────────────────────
        if ($action === 'record_gift') {
            $donorName  = trim((string)($_POST['donor_name'] ?? ''));
            $amountStr  = trim((string)($_POST['gift_amount'] ?? ''));
            $giftDate   = trim((string)($_POST['gift_date'] ?? ''));
            $giftNotes  = trim((string)($_POST['gift_notes'] ?? ''));

            if ($donorName === '') throw new RuntimeException('Donor name is required.');
            if (!is_numeric($amountStr) || (float)$amountStr <= 0) throw new RuntimeException('Valid gift amount is required.');
            if ($giftDate === '') throw new RuntimeException('Gift date is required.');

            $giftCents = (int)round((float)$amountStr * 100);
            $giftRef   = 'GIFT-' . str_replace('-','', $giftDate) . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
            $godleyRef = 'GDLY-' . $giftRef;

            if (class_exists('LedgerEmitter')) {
                $res = LedgerEmitter::emitTransaction(
                    $pdo, $godleyRef, 'ledger_entries', 0,
                    LedgerEmitter::buildGiftReceivedEntries($giftCents),
                    $giftDate
                );
                if ($res['status'] === 'error') throw new RuntimeException('Godley emission failed: ' . $res['message']);
            }
            $flash = "DGR gift recorded: {$giftRef} — " . gr_dollars($giftCents) . " from {$donorName}. STC-GIFT-FUND credited.";
        }

    } catch (Throwable $e) {
        $flash = $e->getMessage(); $flashType = 'err';
    }
    header('Location: ' . admin_url('grants.php') . '?flash=' . urlencode((string)$flash) . '&type=' . $flashType);
    exit;
}

// ── Load data ──────────────────────────────────────────────────────────────
$allGrants = $hasGrants ? gr_rows($pdo, "SELECT * FROM grants ORDER BY id DESC LIMIT 30") : [];

$stcBalance = $hasLedger ? (int)(gr_val($pdo,
    "SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount_cents WHEN entry_type='credit' THEN -amount_cents END),0)
     FROM ledger_entries le JOIN v_godley_accounts ga ON ga.id=le.stewardship_account_id
     WHERE ga.account_key='STC-OPERATING'"
) ?? 0) : 0;

$giftFundBalance = $hasLedger ? (int)(gr_val($pdo,
    "SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount_cents WHEN entry_type='credit' THEN -amount_cents END),0)
     FROM ledger_entries le JOIN v_godley_accounts ga ON ga.id=le.stewardship_account_id
     WHERE ga.account_key='STC-GIFT-FUND'"
) ?? 0) : 0;

$fnGrantsCents = $hasGrants ? (int)(gr_val($pdo,
    "SELECT COALESCE(SUM(amount_cents),0) FROM grants WHERE is_first_nations=1 AND status IN ('disbursed','acquitted')"
) ?? 0) : 0;
$totalGrantsCents = $hasGrants ? (int)(gr_val($pdo,
    "SELECT COALESCE(SUM(amount_cents),0) FROM grants WHERE status IN ('disbursed','acquitted')"
) ?? 0) : 0;
$fnPct = $totalGrantsCents > 0 ? round($fnGrantsCents / $totalGrantsCents * 100, 1) : 0;
$csrfToken = function_exists('admin_csrf_token') ? admin_csrf_token() : '';

ob_start();
require_once __DIR__ . '/includes/tdr_gate.php';
tdr_gate($pdo, [
    'TDR-20260425-009', // Sub-Trust C bank account
    'TDR-20260425-011', // ACNC registration resolution
], 'Sub-Trust C Grants');
?>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Sub-Trust C grants', 'What this page does', 'Use this page to create, review, and manage community grants paid from Sub-Trust C. At least 30% of all grants must go to First Nations beneficiaries per the trust deed.', [
    'Create a grant when a community benefit payment is to be made from Sub-Trust C.',
    'Mark as disbursed only when the payment has actually been made.',
    'Monitor the First Nations compliance percentage — must remain at or above 30%.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Follow the grant lifecycle from approval to acquittal.', [
    ['title' => 'Create the grant', 'body' => 'Enter grantee, amount, purpose, financial year, and First Nations flag.'],
    ['title' => 'Approve', 'body' => 'Governance approval before funds are disbursed.'],
    ['title' => 'Disburse', 'body' => 'Mark as disbursed once payment has been made.'],
    ['title' => 'Acquit', 'body' => 'Record acquittal once the grantee has reported on use of funds.'],
  ]),
  ops_admin_status_panel('Status guide', 'These statuses track the grant lifecycle.', [
    ['label' => 'Proposed', 'body' => 'Grant has been submitted but not yet approved.'],
    ['label' => 'Approved', 'body' => 'Approved by governance — ready to disburse.'],
    ['label' => 'Disbursed', 'body' => 'Payment made to the grantee.'],
    ['label' => 'Acquitted', 'body' => 'Grantee has reported on use of funds.'],
    ['label' => 'Declined / cancelled', 'body' => 'Grant did not proceed.'],
  ]),
]) ?>

<div class="card">
  <div class="card-head"><h1 style="margin:0">Sub-Trust C Grants</h1></div>
  <div class="card-body" style="padding-top:6px"><p class="muted small" style="margin:0">Community benefit grants from Sub-Trust C. Minimum 30% to First Nations beneficiaries.</p></div>
</div>

<?php if ($flash): ?><div class="alert <?php echo $flashType==='ok'?'alert-ok':'alert-err'; ?>"><?php echo gr_h($flash); ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><div class="stat-val" style="color:var(--ok)"><?php echo gr_dollars($stcBalance); ?></div><div class="stat-label">STC-OPERATING balance</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--blue)"><?php echo gr_dollars($giftFundBalance); ?></div><div class="stat-label">STC Gift Fund (DGR)</div></div>
  <div class="stat"><div class="stat-val" style="color:<?php echo $fnPct >= 30 ? 'var(--ok)' : 'var(--err)'; ?>"><?php echo $fnPct; ?>%</div><div class="stat-label">First Nations grants</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--text)"><?php echo count($allGrants); ?></div><div class="stat-label">Total grants</div></div>
</div>

<?php if ($fnPct < 30 && $totalGrantsCents > 0): ?>
<div class="alert alert-err">⚠ First Nations grant allocation is <?php echo $fnPct; ?>% — minimum 30% required by SubTrustC cl.9. Consider prioritising First Nations grantees.</div>
<?php endif; ?>

<!-- Create grant -->
<div class="card">
  <div class="card-head"><h2>Create new grant<?php echo ops_admin_help_button('Create grant','Creates a grant record in proposed status. FNAC approval is required before Board approval for any First Nations grant. Board approval required before disbursement.'); ?></h2></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?php echo gr_h($csrfToken); ?>">
      <input type="hidden" name="action" value="create_grant">
      <div class="form-grid">
        <div class="field"><label>Grant type</label><select name="grant_type" required><?php foreach($grantTypes as $k=>$v): ?><option value="<?php echo gr_h($k); ?>"><?php echo gr_h($v); ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Financial year (e.g. 2026-27)</label><input type="text" name="financial_year" placeholder="2026-27" pattern="\d{4}-\d{2}" required></div>
        <div class="field field-full"><label>Grant title</label><input type="text" name="title" required placeholder="e.g. Drake School Community Garden Project"></div>
        <div class="field field-full"><label>Grantee name</label><input type="text" name="grantee_name" required placeholder="Organisation name"></div>
        <div class="field"><label>Grantee ABN</label><input type="text" name="grantee_abn" placeholder="xx xxx xxx xxx"></div>
        <div class="field"><label>Amount (AUD)</label><input type="number" name="amount" min="1" step="0.01" placeholder="0.00" required></div>
        <div class="field field-full">
          <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_first_nations" value="1"> First Nations beneficiary (counts to 30% minimum)</label>
          <label style="display:flex;align-items:center;gap:8px;margin-top:6px"><input type="checkbox" name="fnac_approval_required" value="1"> FNAC approval required</label>
          <label style="display:flex;align-items:center;gap:8px;margin-top:6px"><input type="checkbox" name="grantee_acnc_registered" value="1"> Grantee is ACNC-registered</label>
        </div>
        <div class="field field-full"><label>Description</label><textarea name="description" placeholder="Brief description of community benefit"></textarea></div>
        <div class="field field-full"><label>Notes</label><input type="text" name="notes" placeholder="Optional admin notes"></div>
        <div class="field-full"><button type="submit" class="btn btn-gold">Create grant</button></div>
      </div>
    </form>
  </div>
</div>

<!-- Grants list -->
<?php if (!empty($allGrants)): ?>
<div class="card">
  <div class="card-head"><h2>Grants register</h2><span style="font-size:12px;color:var(--dim)"><?php echo count($allGrants); ?></span></div>
  <div style="overflow-x:auto"><table>
    <thead><tr><th>Ref</th><th>Grantee</th><th>Type</th><th>Amount</th><th>FY</th><th>Status</th><th>FN</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($allGrants as $g):
      $stCls = 'st-' . str_replace(' ','_',$g['status']);
    ?>
    <tr>
      <td class="mono"><?php echo gr_h($g['grant_ref']); ?></td>
      <td style="font-size:12px"><?php echo gr_h($g['grantee_name']); ?></td>
      <td style="font-size:11px;color:var(--sub)"><?php echo gr_h(ucwords(str_replace('_',' ',$g['grant_type']))); ?></td>
      <td style="font-weight:700"><?php echo gr_dollars((int)$g['amount_cents']); ?></td>
      <td style="font-size:11px;color:var(--sub)"><?php echo gr_h($g['financial_year']); ?></td>
      <td><span class="st <?php echo gr_h($stCls); ?>"><?php echo gr_h($g['status']); ?></span></td>
      <td><?php echo $g['is_first_nations'] ? '<span class="fn-badge">FN</span>' : '<span style="color:var(--dim)">—</span>'; ?></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($g['status']==='proposed' && $g['fnac_approval_required'] && !$g['fnac_approved']): ?>
          <form method="post" style="display:flex;gap:4px;align-items:center">
            <input type="hidden" name="_csrf" value="<?php echo gr_h($csrfToken); ?>">
            <input type="hidden" name="action" value="fnac_approve">
            <input type="hidden" name="grant_id" value="<?php echo (int)$g['id']; ?>">
            <input type="text" name="fnac_approval_ref" placeholder="FNAC ref" style="width:100px;padding:4px 7px;font-size:11px;border-radius:6px;background:var(--panel2);border:1px solid var(--line);color:var(--text)">
            <button type="submit" class="btn btn-sm" style="background:rgba(155,125,212,.12);border-color:rgba(155,125,212,.3);color:var(--purple)">FNAC approve</button>
          </form>
        <?php endif; ?>
        <?php if (in_array($g['status'],['proposed','fnac_review'],true)): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_csrf" value="<?php echo gr_h($csrfToken); ?>">
            <input type="hidden" name="action" value="board_approve">
            <input type="hidden" name="grant_id" value="<?php echo (int)$g['id']; ?>">
            <button type="submit" class="btn btn-sm btn-ok">Board approve</button>
          </form>
        <?php endif; ?>
        <?php if ($g['status']==='approved'): ?>
          <form method="post" style="display:flex;gap:4px;align-items:center">
            <input type="hidden" name="_csrf" value="<?php echo gr_h($csrfToken); ?>">
            <input type="hidden" name="action" value="disburse">
            <input type="hidden" name="grant_id" value="<?php echo (int)$g['id']; ?>">
            <input type="text" name="bank_reference" placeholder="Bank ref" style="width:100px;padding:4px 7px;font-size:11px;border-radius:6px;background:var(--panel2);border:1px solid var(--line);color:var(--text)">
            <button type="submit" class="btn btn-sm btn-gold" onclick="return confirm('Disburse <?php echo gr_h($g['grant_ref']); ?>? This emits Godley entries.')">Disburse + Godley</button>
          </form>
        <?php endif; ?>
        <?php if ($g['status']==='disbursed'): ?>
          <form method="post" style="display:flex;gap:4px;align-items:center">
            <input type="hidden" name="_csrf" value="<?php echo gr_h($csrfToken); ?>">
            <input type="hidden" name="action" value="acquit">
            <input type="hidden" name="grant_id" value="<?php echo (int)$g['id']; ?>">
            <input type="text" name="acquittal_ref" placeholder="Acquittal ref" style="width:100px;padding:4px 7px;font-size:11px;border-radius:6px;background:var(--panel2);border:1px solid var(--line);color:var(--text)">
            <button type="submit" class="btn btn-sm btn-ok" onclick="return confirm('Mark <?php echo gr_h($g['grant_ref']); ?> as acquitted?')">Acquit</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<hr class="divider">

<!-- DGR Gift received -->
<div style="margin-bottom:18px">
  <div class="card-head"><h2>DGR gift received<?php echo ops_admin_help_button('DGR gift','Records a tax-deductible gift into STC-GIFT-FUND (SubTrustC cl.11). Requires DGR endorsement by the ATO before accepting gifts. Emits gift_received Godley entries.'); ?></h2></div>
  <div class="card-body">

  <p style="color:var(--sub);font-size:13px;margin-top:6px">Record gifts received into the Sub-Trust C DGR gift fund. DGR endorsement must be in place.</p>
</div>
<div class="card">
  <div class="card-head"><h2>Record DGR gift</h2></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?php echo gr_h($csrfToken); ?>">
      <input type="hidden" name="action" value="record_gift">
      <div class="form-grid">
        <div class="field field-full"><label>Donor name</label><input type="text" name="donor_name" required placeholder="Name of donor (individual or organisation)"></div>
        <div class="field"><label>Gift amount (AUD)</label><input type="number" name="gift_amount" min="2.00" step="0.01" placeholder="0.00" required></div>
        <div class="field"><label>Gift date</label><input type="date" name="gift_date" value="<?php echo date('Y-m-d'); ?>" required></div>
        <div class="field field-full"><label>Notes</label><input type="text" name="gift_notes" placeholder="Optional — receipt number, acknowledgement reference"></div>
        <div class="field-full"><button type="submit" class="btn btn-gold">Record gift + emit Godley</button></div>
      </div>
    </form>
  </div>
</div>

<?php
$body = ob_get_clean();
ops_render_page('Grants', 'grants', $body, $flash ?? null, 'ok');
