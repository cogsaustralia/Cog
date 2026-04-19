<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
if (file_exists(__DIR__ . '/includes/LedgerEmitter.php')) require_once __DIR__ . '/includes/LedgerEmitter.php';
if (file_exists(__DIR__ . '/includes/AccountingHooks.php')) require_once __DIR__ . '/includes/AccountingHooks.php';

ops_require_admin();
$pdo     = ops_db();
$adminId = ops_admin_id();

function stb_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function stb_dollars(int $cents): string { return '$' . number_format($cents / 100, 2); }
function stb_ok(PDO $p, string $q, array $b = []): mixed { try { $s=$p->prepare($q);$s->execute($b);return $s->fetchColumn(); } catch(Throwable $e){ return null; } }
function stb_rows(PDO $p, string $q, array $b = []): array { try { $s=$p->prepare($q);$s->execute($b);return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; } catch(Throwable $e){ return []; } }

$hasDistRuns   = ops_has_table($pdo, 'distribution_runs');
$hasBenefitFlow= ops_has_table($pdo, 'benefit_flow_records');
$hasLedger     = ops_has_table($pdo, 'ledger_entries');

$flash = null; $flashType = 'ok';
if (isset($_GET['flash'])) { $flash = (string)$_GET['flash']; $flashType = (string)($_GET['type'] ?? 'ok'); }

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    $action = trim((string)($_POST['action'] ?? ''));
    try {

        // ── Calculate a new distribution run ─────────────────────────────
        if ($action === 'calculate_run') {
            if (!$hasDistRuns) throw new RuntimeException('distribution_runs table not found.');

            $runDate   = trim((string)($_POST['run_date'] ?? ''));
            $stbPoolStr= trim((string)($_POST['stb_pool_amount'] ?? ''));
            $bdsStr    = trim((string)($_POST['bds_component'] ?? '0'));
            $ddsStr    = trim((string)($_POST['dds_component'] ?? '0'));
            $incomeDate= trim((string)($_POST['income_received_date'] ?? ''));
            $notes     = trim((string)($_POST['notes'] ?? ''));

            if ($runDate === '') throw new RuntimeException('Distribution date is required.');
            if (!is_numeric($stbPoolStr) || (float)$stbPoolStr <= 0) throw new RuntimeException('STB pool amount is required.');
            if ($incomeDate === '') throw new RuntimeException('Income received date is required.');

            $poolCents = (int)round((float)$stbPoolStr * 100);
            $bdsCents  = (int)round((float)$bdsStr * 100);
            $ddsCents  = (int)round((float)$ddsStr * 100);
            if ($bdsCents + $ddsCents > $poolCents) throw new RuntimeException('BDS + DDS components cannot exceed pool total.');

            // Count beneficial units: all approved units excluding LR_COG
            // Each S-NFT = 1 unit. Sub-Trust C = 1 unit per D-class token issued.
            $memberUnits = (int)(stb_ok($pdo,
                "SELECT COALESCE(SUM(mrl.approved_units),0)
                 FROM member_reservation_lines mrl
                 JOIN token_classes tc ON tc.id = mrl.token_class_id
                 WHERE mrl.approval_status = 'approved'
                   AND tc.class_code NOT IN ('LR_COG','COM_COG','PAY_IT_FORWARD_COG')"
            ) ?? 0);

            $stcUnits = (int)(stb_ok($pdo,
                "SELECT COALESCE(COUNT(*),0) FROM donation_ledger WHERE transfer_to_c_status = 'completed'"
            ) ?? 0);

            $totalUnits = $memberUnits + $stcUnits;
            if ($totalUnits < 1) throw new RuntimeException('No eligible beneficial units found.');

            $centsPerUnit = intdiv($poolCents, $totalUnits);
            $remainder    = $poolCents - ($centsPerUnit * $totalUnits);

            if ($centsPerUnit < 1) throw new RuntimeException('Pool too small to distribute — less than 1 cent per unit.');

            // Generate run ref
            $fy    = date('Y', strtotime($runDate));
            $fySuf = substr($fy, 2) . '-' . (substr((string)((int)$fy + 1), 2));
            $runRef = 'DIST-' . $fySuf . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            $dueBy  = date('Y-m-d H:i:s', strtotime($incomeDate . ' +60 days'));

            $pdo->prepare("
                INSERT INTO distribution_runs
                  (run_ref, distribution_date, total_pool_cents, bds_component_cents, dds_component_cents,
                   total_beneficial_units, cents_per_unit, remainder_cents,
                   income_received_at, distribution_due_by, status, notes, created_by_admin_id)
                VALUES (?,?,?,?,?, ?,?,?, ?,?,?,?,?)
            ")->execute([
                $runRef, $runDate, $poolCents, $bdsCents, $ddsCents,
                $totalUnits, $centsPerUnit, $remainder,
                $incomeDate, $dueBy, 'calculated', $notes ?: null, $adminId,
            ]);

            $flash = "Distribution run {$runRef} calculated: {$totalUnits} units × " . stb_dollars($centsPerUnit) . "/unit = " . stb_dollars($poolCents - $remainder) . " distributable. Remainder: " . stb_dollars($remainder) . ". Due by: " . substr($dueBy, 0, 10) . ".";
        }

        // ── Approve a run ─────────────────────────────────────────────────
        if ($action === 'approve_run') {
            $runId = (int)($_POST['run_id'] ?? 0);
            if (!$runId) throw new RuntimeException('Run ID missing.');
            $run = stb_rows($pdo, "SELECT * FROM distribution_runs WHERE id = ? LIMIT 1", [$runId]);
            if (empty($run)) throw new RuntimeException('Distribution run not found.');
            $run = $run[0];
            if ($run['status'] !== 'calculated') throw new RuntimeException("Run must be in 'calculated' status to approve.");
            $pdo->prepare("UPDATE distribution_runs SET status='approved', approved_by_admin_id=?, approved_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([$adminId, $runId]);
            $flash = "Run {$run['run_ref']} approved.";
        }

        // ── Mark run complete and emit Godley entries ─────────────────────
        if ($action === 'complete_run') {
            $runId = (int)($_POST['run_id'] ?? 0);
            if (!$runId) throw new RuntimeException('Run ID missing.');
            $run = stb_rows($pdo, "SELECT * FROM distribution_runs WHERE id = ? LIMIT 1", [$runId]);
            if (empty($run)) throw new RuntimeException('Distribution run not found.');
            $run = $run[0];
            if ($run['status'] !== 'approved') throw new RuntimeException("Run must be approved before marking complete.");

            $centsPerUnit = (int)$run['cents_per_unit'];
            $runRef       = $run['run_ref'];
            $runDate      = $run['distribution_date'];

            // Build per-holder distribution array
            // Eligible members: all with approved units (non-LR, non-COM, non-PIF)
            $memberRows = stb_rows($pdo,
                "SELECT m.id AS member_id, SUM(mrl.approved_units) AS units
                 FROM member_reservation_lines mrl
                 JOIN members m ON m.id = mrl.member_id
                 JOIN token_classes tc ON tc.id = mrl.token_class_id
                 WHERE mrl.approval_status = 'approved'
                   AND tc.class_code NOT IN ('LR_COG','COM_COG','PAY_IT_FORWARD_COG')
                 GROUP BY m.id"
            );

            $distributions = [];
            foreach ($memberRows as $mr) {
                $units  = (int)$mr['units'];
                $amount = $units * $centsPerUnit;
                if ($amount > 0) {
                    $distributions[] = [
                        'account_key'  => 'MEMBER-' . $mr['member_id'],
                        'amount_cents' => $amount,
                    ];
                }
            }

            // STC gets 1 unit per Donation token issued (Sub-Trust C is D-class beneficiary)
            $stcUnits = (int)(stb_ok($pdo,
                "SELECT COALESCE(COUNT(*),0) FROM donation_ledger WHERE transfer_to_c_status = 'completed'"
            ) ?? 0);
            if ($stcUnits > 0) {
                $distributions[] = [
                    'account_key'  => 'STC-OPERATING',
                    'amount_cents' => $stcUnits * $centsPerUnit,
                ];
            }

            if (empty($distributions)) throw new RuntimeException('No eligible distributions calculated.');

            $pdo->beginTransaction();
            try {
                // Emit Godley STB distribution entries
                if (class_exists('LedgerEmitter')) {
                    $godleyRef = "GDLY-DIST-{$runRef}";
                    $res = LedgerEmitter::emitTransaction(
                        $pdo, $godleyRef, 'distribution_runs', $runId,
                        LedgerEmitter::buildSTBDistributionEntries($distributions),
                        $runDate
                    );
                    if ($res['status'] === 'error') throw new RuntimeException('Godley emission failed: ' . $res['message']);
                }

                // Record benefit_flow_records for each holder
                if ($hasBenefitFlow) {
                    foreach ($distributions as $i => $d) {
                        $flowRef = $runRef . '-' . ($i + 1);
                        $flowType = str_starts_with($d['account_key'], 'STC') ? 'direct_sub_trust_c' : 'beneficiary_distribution';
                        $pdo->prepare("INSERT INTO benefit_flow_records (flow_ref, distribution_run_id, flow_type, amount_cents, notes, created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())")
                            ->execute([$flowRef, $runId, $flowType, $d['amount_cents'], $d['account_key']]);
                    }
                }

                $pdo->prepare("UPDATE distribution_runs SET status='completed', completed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=?")
                    ->execute([$runId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $totalPaid = array_sum(array_column($distributions, 'amount_cents'));
            $flash = "Run {$runRef} completed. Distributed: " . stb_dollars($totalPaid) . " across " . count($distributions) . " holders. Godley entries emitted.";
        }

    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }
    header('Location: ' . admin_url('stb_distributions.php') . '?flash=' . urlencode((string)$flash) . '&type=' . $flashType);
    exit;
}

// ── Load data ──────────────────────────────────────────────────────────────
$runs = $hasDistRuns
    ? stb_rows($pdo, "SELECT * FROM distribution_runs ORDER BY id DESC LIMIT 20")
    : [];

$stbBalance = $hasLedger ? (int)(stb_ok($pdo,
    "SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount_cents WHEN entry_type='credit' THEN -amount_cents END),0)
     FROM ledger_entries le JOIN v_godley_accounts ga ON ga.id = le.stewardship_account_id
     WHERE ga.account_key = 'STB-OPERATING'"
) ?? 0) : 0;

$totalUnitsCalc = (int)(stb_ok($pdo,
    "SELECT COALESCE(SUM(mrl.approved_units),0)
     FROM member_reservation_lines mrl
     JOIN token_classes tc ON tc.id = mrl.token_class_id
     WHERE mrl.approval_status='approved' AND tc.class_code NOT IN ('LR_COG','COM_COG','PAY_IT_FORWARD_COG')"
) ?? 0);

$stcUnitsCalc = (int)(stb_ok($pdo,
    "SELECT COALESCE(COUNT(*),0) FROM donation_ledger WHERE transfer_to_c_status='completed'"
) ?? 0);

$csrfToken = function_exists('admin_csrf_token') ? admin_csrf_token() : '';

ob_start();
?>
<div class="card"><div class="card-head"><h1 style="margin:0">Sub-Trust B Distributions</h1></div><div class="card-body" style="padding-top:6px"><p class="muted small" style="margin:0">Sub-Trust B distribution runs — 100% of STB inflows must be distributed within 60 days.</p></div></div>

<?php if ($flash): ?><div class="alert <?php echo $flashType === 'ok' ? 'alert-ok' : 'alert-err'; ?>"><?php echo stb_h($flash); ?></div><?php endif; ?>

<div class="stat-grid">
  <div class="card"><div class="card-body"><div class="stat-value" style="color:var(--ok)"><?php echo stb_dollars($stbBalance); ?></div><div class="stat-label">STB balance (Godley)</div></div></div>
  <div class="card"><div class="card-body"><div class="stat-value" style="color:var(--blue)"><?php echo number_format($totalUnitsCalc + $stcUnitsCalc); ?></div><div class="stat-label">Eligible beneficial units</div></div></div>
  <div class="card"><div class="card-body"><div class="stat-value" style="color:var(--text)"><?php echo number_format($totalUnitsCalc); ?></div><div class="stat-label">Member units</div></div></div>
  <div class="card"><div class="card-body"><div class="stat-value" style="color:var(--gold)"><?php echo number_format($stcUnitsCalc); ?></div><div class="stat-label">STC units (D-class)</div></div></div>
</div>

<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Sub-Trust B distribution','What this page does','This page calculates and records proportional distributions from Sub-Trust B to all Beneficial Unit Holders. The 60-day rule (Declaration cl.31.2, SubTrustB cl.9.1) requires 100% of STB inflows to be distributed within 60 days of receipt. Completing a run emits the Godley ledger entries that clear the I4 invariant.',[
    'Step 1 — Calculate: enter the STB pool amount and date. The system counts eligible units and calculates cents-per-unit.',
    'Step 2 — Approve: review the calculation, then approve the run for payment.',
    'Step 3 — Complete: once funds are paid out, mark complete. This emits the Godley STB distribution entries and records benefit_flow_records.',
    'STC receives 1 unit per Donation COG$ token issued — Sub-Trust C is the D-class Beneficial Unit Holder.',
  ]),
]) ?>
<?php if (!$hasDistRuns): ?>
  <div class="alert alert-err">distribution_runs table not found. Run accounting schema SQL first.</div>
<?php else: ?>

<!-- Calculate new run -->
<div class="card">
  <div class="card-head"><h2>Calculate new distribution run<?php echo ops_admin_help_button('Calculate run','Enter the total amount available in STB-OPERATING, the date of distribution, and the date Trust B received the funds from Trust A. The system will calculate cents-per-unit and the 60-day deadline automatically.'); ?></h2></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?php echo stb_h($csrfToken); ?>">
      <input type="hidden" name="action" value="calculate_run">
      <div class="form-grid">
        <div class="field"><label>Distribution date</label><input type="date" name="run_date" value="<?php echo date('Y-m-d'); ?>" required></div>
        <div class="field"><label>Income received date (for 60-day deadline)</label><input type="date" name="income_received_date" value="<?php echo date('Y-m-d'); ?>" required></div>
        <div class="field"><label>Total STB pool to distribute (AUD)<?php echo ops_admin_help_button('STB pool','Total AUD available in Trust B for this run. Check the STB balance above.'); ?></label><input type="number" name="stb_pool_amount" min="0.01" step="0.01" placeholder="0.00" required oninput="previewCalc()"></div>
        <div class="field"><label>BDS component (AUD)</label><input type="number" name="bds_component" min="0" step="0.01" value="0.00" oninput="previewCalc()"></div>
        <div class="field"><label>DDS component (AUD)</label><input type="number" name="dds_component" min="0" step="0.01" value="0.00" oninput="previewCalc()"></div>
        <div class="field field-full"><label>Notes</label><input type="text" name="notes" placeholder="e.g. Q3 2026 distribution — LGM interim dividend proceeds"></div>
      </div>
      <div class="preview-box" id="preview">
        <strong>Preview</strong><br>
        Units: <?php echo number_format($totalUnitsCalc + $stcUnitsCalc); ?> total<br>
        <span id="prev-cpu">Rate: calculating…</span><br>
        <span id="prev-total">Total distributable: —</span><br>
        <span id="prev-rem">Remainder held in STB: —</span>
      </div>
      <div style="margin-top:14px"><button type="submit" class="btn btn-gold">Calculate distribution run</button></div>
    </form>
  </div>
</div>

<!-- Distribution runs list -->
<?php if (!empty($runs)): ?>
<div class="card">
  <div class="card-head"><h2>Distribution runs</h2><span style="font-size:12px;color:var(--dim)"><?php echo count($runs); ?></span></div>
  <div style="overflow-x:auto"><table>
    <thead><tr><th>Ref</th><th>Date</th><th>Pool</th><th>Units</th><th>¢/unit</th><th>Due by</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r):
      $stCls = match($r['status']) {
        'calculated' => 'st-calc', 'approved' => 'st-approved',
        'completed' => 'st-completed', 'overdue' => 'st-overdue', default => 'st-draft'
      };
    ?>
    <tr>
      <td class="mono"><?php echo stb_h($r['run_ref']); ?></td>
      <td style="white-space:nowrap"><?php echo stb_h($r['distribution_date']); ?></td>
      <td style="font-weight:700"><?php echo stb_dollars((int)$r['total_pool_cents']); ?></td>
      <td><?php echo number_format((int)$r['total_beneficial_units']); ?></td>
      <td class="mono"><?php echo number_format((int)$r['cents_per_unit'] / 100, 4); ?></td>
      <td style="font-size:12px;color:<?php echo strtotime($r['distribution_due_by']) < time() && $r['status'] !== 'completed' ? 'var(--err)' : 'var(--sub)'; ?>"><?php echo stb_h(substr($r['distribution_due_by'],0,10)); ?></td>
      <td><span class="st <?php echo $stCls; ?>"><?php echo stb_h($r['status']); ?></span></td>
      <td>
        <?php if ($r['status'] === 'calculated'): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_csrf" value="<?php echo stb_h($csrfToken); ?>">
            <input type="hidden" name="action" value="approve_run">
            <input type="hidden" name="run_id" value="<?php echo (int)$r['id']; ?>">
            <button type="submit" class="btn btn-sm btn-ok">Approve</button>
          </form>
        <?php elseif ($r['status'] === 'approved'): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_csrf" value="<?php echo stb_h($csrfToken); ?>">
            <input type="hidden" name="action" value="complete_run">
            <input type="hidden" name="run_id" value="<?php echo (int)$r['id']; ?>">
            <button type="submit" class="btn btn-sm btn-gold" onclick="return confirm('Complete run <?php echo stb_h($r['run_ref']); ?>? This emits Godley entries and cannot be undone.')">Complete + emit Godley</button>
          </form>
        <?php else: ?><span style="color:var(--dim);font-size:12px">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php else: ?>
<div class="card"><div class="card-body"><p class="empty">No distribution runs yet.</p></div></div>
<?php endif; ?>

<?php endif; ?>

<script>
var totalUnits = <?php echo $totalUnitsCalc + $stcUnitsCalc; ?>;
function previewCalc() {
    var pool = Math.round((parseFloat(document.querySelector('[name=stb_pool_amount]')?.value)||0)*100);
    if (pool <= 0 || totalUnits < 1) { document.getElementById('preview').style.display='none'; return; }
    var cpu = Math.floor(pool / totalUnits);
    var total = cpu * totalUnits;
    var rem = pool - total;
    document.getElementById('preview').style.display='block';
    document.getElementById('prev-cpu').textContent = 'Rate: $' + (cpu/100).toFixed(4) + ' per unit';
    document.getElementById('prev-total').textContent = 'Total distributable: $' + (total/100).toFixed(2);
    document.getElementById('prev-rem').textContent = 'Remainder held in STB: $' + (rem/100).toFixed(2);
}
</script>

<?php
$body = ob_get_clean();
ops_render_page('STB Distributions', 'stb_distributions', $body, $flash ?? null, 'ok');
