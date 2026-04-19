<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo     = ops_db();
$adminId = ops_admin_id();

function ti_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function ti_dollars(int $cents): string { return '$' . number_format($cents / 100, 2); }

$hasTable        = function_exists('ops_has_table') && ops_has_table($pdo, 'trust_income');
$hasDividendEvt  = function_exists('ops_has_table') && ops_has_table($pdo, 'dividend_events');
$flash    = null;
$error    = null;

$incomeTypes = [
    'interest'    => 'Interest on accounts',
    'rwa_yield'   => 'RWA yield',
    'other'       => 'Other income',
];

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        // ── General income (interest / rwa_yield / other) ──────────────────
        if ($action === 'add_income') {
            $type        = trim((string)($_POST['income_type'] ?? ''));
            $desc        = trim((string)($_POST['source_description'] ?? ''));
            $grossStr    = trim((string)($_POST['gross_amount'] ?? ''));
            $withStr     = trim((string)($_POST['withholding'] ?? '0'));
            $incDate     = trim((string)($_POST['income_date'] ?? ''));
            $chessRef    = trim((string)($_POST['chess_holding_ref'] ?? ''));
            $rwaRef      = trim((string)($_POST['rwa_asset_ref'] ?? ''));
            $notes       = trim((string)($_POST['notes'] ?? ''));

            if ($desc === '') throw new RuntimeException('Source description is required.');
            if (!array_key_exists($type, $incomeTypes)) throw new RuntimeException('Invalid income type.');
            if (!is_numeric($grossStr) || (float)$grossStr <= 0) throw new RuntimeException('Valid gross amount is required.');
            if ($incDate === '') throw new RuntimeException('Income date is required.');

            $grossCents = (int)round((float)$grossStr * 100);
            $withCents  = (int)round((float)$withStr * 100);
            $netCents   = $grossCents - $withCents;
            if ($netCents < 0) throw new RuntimeException('Withholding cannot exceed gross amount.');

            $refDate = str_replace('-', '', $incDate);
            $incRef  = 'INC-' . $refDate . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

            $stmt = $pdo->prepare("
                INSERT INTO trust_income
                  (income_ref, income_type, source_description,
                   gross_amount_cents, franking_credits_cents, withholding_cents, net_amount_cents,
                   currency_code, income_date, chess_holding_ref, rwa_asset_ref,
                   trust_account_id, notes, created_by_admin_id)
                VALUES (?, ?, ?, ?, 0, ?, ?, 'AUD', ?, ?, ?, 1, ?, ?)
            ");
            $stmt->execute([
                $incRef, $type, $desc,
                $grossCents, $withCents, $netCents,
                $incDate,
                $chessRef ?: null, $rwaRef ?: null,
                $notes ?: null, $adminId,
            ]);
            $newIncomeId = (int)$pdo->lastInsertId();

            // Godley ledger emission — interest gets interest_income flow; all others general
            $ledgerEmitter = __DIR__ . '/includes/LedgerEmitter.php';
            if (file_exists($ledgerEmitter)) {
                require_once $ledgerEmitter;
                if (class_exists('LedgerEmitter')) {
                    $godleyRef = "GDLY-{$incRef}";
                    $entries = match ($type) {
                        'interest' => LedgerEmitter::buildInterestIncomeEntries($netCents, $withCents),
                        default    => LedgerEmitter::buildGeneralIncomeEntries($netCents, $withCents),
                    };
                    $res = LedgerEmitter::emitTransaction(
                        $pdo, $godleyRef, 'trust_income', $newIncomeId, $entries, $incDate
                    );
                    if ($res['status'] === 'error') {
                        $flash = 'Income recorded but Godley emission failed: ' . $res['message'];
                    }
                }
            }
            $flash = $flash ?: ('Income recorded: ' . $incRef . ' — $' . number_format($grossCents / 100, 2) . ' gross');
        }

        // ── ASX dividend — BDS / DDS split ─────────────────────────────────
        if ($action === 'record_dividend') {
            if (!$hasDividendEvt) throw new RuntimeException('dividend_events table not found. Run schema SQL first.');

            $streamType  = trim((string)($_POST['stream_type'] ?? ''));
            $grossStr    = trim((string)($_POST['dividend_gross'] ?? ''));
            $frankStr    = trim((string)($_POST['dividend_franking'] ?? '0'));
            $divDate     = trim((string)($_POST['dividend_date'] ?? ''));
            $chessRef    = trim((string)($_POST['chess_ref'] ?? ''));
            $notes       = trim((string)($_POST['div_notes'] ?? ''));

            if (!in_array($streamType, ['beneficiary','donation','mixed'], true))
                throw new RuntimeException('Invalid stream type.');
            if (!is_numeric($grossStr) || (float)$grossStr <= 0)
                throw new RuntimeException('Gross dividend amount is required.');
            if ($divDate === '') throw new RuntimeException('Dividend date is required.');

            $grossCents   = (int)round((float)$grossStr * 100);
            $frankCents   = (int)round((float)$frankStr * 100);
            $totalIncome  = $grossCents; // net received; franking credits are ATO-side

            // Calculate splits according to Declaration cl.31.1
            // BDS: 50% STA-PARTNERS-POOL reinvest, 50% STB
            // DDS: 25% STA-PARTNERS-POOL reinvest, 25% TRUSTEE-ADMIN, 50% STB
            // Mixed: split total proportionally by Donation Ledger vs total units ratio
            // For UI simplicity: operator selects stream; mixed is entered as two separate events.

            if ($streamType === 'beneficiary') {
                $bdsIncome     = $totalIncome;
                $ddsIncome     = 0;
                $bdsReinvest   = intdiv($bdsIncome, 2);
                $bdsToB        = $bdsIncome - $bdsReinvest;
                $ddsReinvest   = 0;
                $ddsAdmin      = 0;
                $ddsToB        = 0;
            } elseif ($streamType === 'donation') {
                $bdsIncome     = 0;
                $ddsIncome     = $totalIncome;
                $bdsReinvest   = 0;
                $bdsToB        = 0;
                $ddsToB        = intdiv($ddsIncome, 2);
                $ddsAdmin      = intdiv($ddsIncome, 4);
                $ddsReinvest   = $ddsIncome - $ddsToB - $ddsAdmin;
            } else {
                // mixed — operator supplies BDS/DDS split manually
                $bdsStr      = trim((string)($_POST['bds_portion'] ?? '0'));
                $ddsStr      = trim((string)($_POST['dds_portion'] ?? '0'));
                $bdsIncome   = (int)round((float)$bdsStr * 100);
                $ddsIncome   = (int)round((float)$ddsStr * 100);
                if ($bdsIncome + $ddsIncome !== $totalIncome)
                    throw new RuntimeException('BDS + DDS portions must equal gross dividend total.');
                $bdsReinvest = intdiv($bdsIncome, 2);
                $bdsToB      = $bdsIncome - $bdsReinvest;
                $ddsToB      = intdiv($ddsIncome, 2);
                $ddsAdmin    = intdiv($ddsIncome, 4);
                $ddsReinvest = $ddsIncome - $ddsToB - $ddsAdmin;
            }

            $totalToB      = $bdsToB + $ddsToB;
            $totalReinvest = $bdsReinvest + $ddsReinvest;
            $totalAdmin    = $ddsAdmin;

            $refDate  = str_replace('-', '', $divDate);
            $evtRef   = 'DREV-' . $refDate . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    INSERT INTO dividend_events
                      (event_ref, event_date, stream_type,
                       total_income_cents, bds_income_cents, bds_reinvest_cents, bds_to_trust_b_cents,
                       dds_income_cents, dds_reinvest_cents, dds_admin_cents, dds_to_trust_b_cents,
                       total_to_trust_b_cents, total_reinvest_cents, total_admin_cents,
                       chess_holding_ref, notes, created_by_admin_id, created_at, updated_at)
                    VALUES (?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                ")->execute([
                    $evtRef, $divDate, $streamType,
                    $totalIncome, $bdsIncome, $bdsReinvest, $bdsToB,
                    $ddsIncome, $ddsReinvest, $ddsAdmin, $ddsToB,
                    $totalToB, $totalReinvest, $totalAdmin,
                    $chessRef ?: null, $notes ?: null, $adminId,
                ]);
                $evtId = (int)$pdo->lastInsertId();

                // Emit Godley entries — BDS and/or DDS builders
                $ledgerEmitter = __DIR__ . '/includes/LedgerEmitter.php';
                if (file_exists($ledgerEmitter)) {
                    require_once $ledgerEmitter;
                    if (class_exists('LedgerEmitter')) {
                        if ($bdsIncome > 0) {
                            $res = LedgerEmitter::emitTransaction(
                                $pdo, "GDLY-BDS-{$evtRef}", 'dividend_events', $evtId,
                                LedgerEmitter::buildBDSDividendEntries($bdsIncome), $divDate
                            );
                            if ($res['status'] === 'error')
                                throw new RuntimeException('BDS Godley emission failed: ' . $res['message']);
                        }
                        if ($ddsIncome > 0) {
                            $res = LedgerEmitter::emitTransaction(
                                $pdo, "GDLY-DDS-{$evtRef}", 'dividend_events', $evtId,
                                LedgerEmitter::buildDDSDividendEntries($ddsIncome), $divDate
                            );
                            if ($res['status'] === 'error')
                                throw new RuntimeException('DDS Godley emission failed: ' . $res['message']);
                        }
                        // Franking credits — separate emission if present (SubTrustA cl.16.2)
                        if ($frankCents > 0) {
                            $res = LedgerEmitter::emitTransaction(
                                $pdo, "GDLY-FRANK-{$evtRef}", 'dividend_events', $evtId,
                                LedgerEmitter::buildFrankingCreditEntries($frankCents), $divDate
                            );
                            if ($res['status'] === 'error')
                                throw new RuntimeException('Franking credit Godley emission failed: ' . $res['message']);
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $flash = "Dividend recorded: {$evtRef} — " . ti_dollars($totalIncome) . " gross. "
                   . "STB allocation: " . ti_dollars($totalToB) . ". "
                   . "Reinvest: " . ti_dollars($totalReinvest) . "."
                   . ($totalAdmin > 0 ? " Trustee admin: " . ti_dollars($totalAdmin) . "." : '');
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Load data ──────────────────────────────────────────────────────────────────
$allIncome = [];
$totalGross = $totalNet = $totalWith = 0;
if ($hasTable) {
    try {
        $rows = $pdo->query("SELECT * FROM trust_income ORDER BY income_date DESC, id DESC LIMIT 50");
        $allIncome = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($allIncome as $r) {
            $totalGross += (int)$r['gross_amount_cents'];
            $totalNet   += (int)$r['net_amount_cents'];
            $totalWith  += (int)$r['withholding_cents'];
        }
    } catch (Throwable $e) {}
}

$allDividends = [];
$divTotalIncome = $divTotalToB = $divTotalReinvest = 0;
if ($hasDividendEvt) {
    try {
        $rows = $pdo->query("SELECT * FROM dividend_events ORDER BY event_date DESC, id DESC LIMIT 30");
        $allDividends = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($allDividends as $d) {
            $divTotalIncome   += (int)$d['total_income_cents'];
            $divTotalToB      += (int)$d['total_to_trust_b_cents'];
            $divTotalReinvest += (int)$d['total_reinvest_cents'];
        }
    } catch (Throwable $e) {}
}

function ti_type_label(string $t): string {
    return match ($t) {
        'interest'     => 'Interest',
        'asx_dividend' => 'ASX Dividend',
        'rwa_yield'    => 'RWA Yield',
        default        => ucwords(str_replace('_', ' ', $t)),
    };
}
function ti_stream_label(string $s): string {
    return match ($s) {
        'beneficiary' => 'BDS',
        'donation'    => 'DDS',
        'mixed'       => 'BDS + DDS',
        default       => $s,
    };
}


ob_start();
?>
<div class="card">
  <div class="card-head">
    <h1 style="margin:0">Trust Income <?php echo ops_admin_help_button('Trust income', 'Record all income received by Sub-Trust A — interest, RWA yield, and other receipts.'); ?></h1>
    <div style="display:flex;gap:8px">
      <a class="btn-secondary" href="<?php echo ti_h(admin_url('expenses.php')); ?>">Expenses</a>
      <a class="btn-secondary" href="<?php echo ti_h(admin_url('accounting.php')); ?>">Accounting</a>
    </div>
  </div>
  <div class="card-body" style="padding-top:6px"><p class="muted small" style="margin:0">Record interest, RWA yield, and other income. ASX dividends are recorded through the dividend workflow.</p></div>
</div>

<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel(
    'Trust income recording',
    'What this page does',
    'Use this page to record income received by Sub-Trust A. Each entry writes to trust_income and automatically emits the matching Godley ledger entries — debiting STA-OPERATING for cash received and crediting STA-PARTNERS-POOL as retained pool equity.',
    [
        'Record interest income from bank or investment accounts.',
        'Record RWA yield payments when received.',
        'Record any other income that does not come through the ASX dividend workflow.',
        'ASX dividends trigger the BDS/DDS split workflow separately — do not record them here.',
    ]
]),
  ops_admin_workflow_panel(
    'Typical workflow',
    'Record income promptly so the Partners Pool balance stays accurate.',
    [
        ['title' => 'Select the income type', 'body' => 'Choose Interest, RWA Yield, or Other to determine which Godley emission path fires.'],
        ['title' => 'Enter gross amount and any withholding', 'body' => 'Net amount is calculated automatically. Most bank interest will have zero withholding.'],
        ['title' => 'Set the income date', 'body' => 'Use the actual date the income was received or credited, not today\'s date.'],
        ['title' => 'Check the Accounting page', 'body' => 'After recording, visit Accounting to confirm the Godley matrix reflects the new Partners Pool balance.'],
    ]
]),
  ops_admin_guide_panel(
    'Income types',
    'Each type routes to a different ledger flow.',
    [
        ['title' => 'Interest', 'body' => 'Bank or investment account interest. Debits STA-OPERATING, credits STA-PARTNERS-POOL.'],
        ['title' => 'RWA Yield', 'body' => 'Yield from real-world asset holdings. Same double-entry as interest.'],
        ['title' => 'Other', 'body' => 'Any other income into Sub-Trust A not covered by the above categories.'],
        ['title' => 'ASX Dividends', 'body' => 'Not recorded here. ASX dividends trigger the BDS/DDS split workflow which distributes across Sub-Trusts A and B.'],
    ]
]),
]) ?>
<div class="notice">
  ⚠ <strong>ASX dividends are not recorded here.</strong> They trigger the BDS / DDS split workflow and are entered through the dividend management pathway.
</div>

<?php if (!$hasTable): ?>
  <div class="alert alert-err">trust_income table not found. Run the accounting schema SQL first.</div>
<?php else: ?>

<?php if ($flash): ?><div class="alert alert-ok"><?php echo ti_h($flash); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-err"><?php echo ti_h($error); ?></div><?php endif; ?>

<div class="stat-grid">
  <div class="card"><div class="card-body"><div class="stat-value" style="color:var(--ok)"><?php echo ti_dollars($totalGross); ?></div><div class="stat-label">Total gross received</div></div></div>
  <div class="card"><div class="card-body"><div class="stat-value" style="color:var(--blue)"><?php echo ti_dollars($totalNet); ?></div><div class="stat-label">Total net received</div></div></div>
  <div class="card"><div class="card-body"><div class="stat-value" style="color:var(--warn)"><?php echo ti_dollars($totalWith); ?></div><div class="stat-label">Total withholding</div></div></div>
  <div class="card"><div class="card-body"><div class="stat-value" style="color:var(--text)"><?php echo count($allIncome); ?></div><div class="stat-label">Total entries</div></div></div>
</div>

<!-- Record income form -->
<div class="card">
  <div class="card-head"><h2>Record new income<?php echo ops_admin_help_button('Record new income', 'Enter the income details as they appear on your bank statement or income advice. Withholding is uncommon for interest income unless a TFN has not been supplied.'); ?></h2></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?php echo ti_h(admin_csrf_token()); ?>">
      <input type="hidden" name="action" value="add_income">
      <div class="form-grid">
        <div class="field">
          <label>Income type</label>
          <select name="income_type" required>
            <?php foreach ($incomeTypes as $k => $v): ?>
              <option value="<?php echo ti_h($k); ?>"><?php echo ti_h($v); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Income date</label>
          <input type="date" name="income_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="field field-full">
          <label>Source description<?php echo ops_admin_help_button('Source description', 'Describe the source clearly — e.g. "Interest on Sub-Trust A Operating Account — March 2026" or "Bank West savings account interest Q1 2026".'); ?></label>
          <input type="text" name="source_description" placeholder="e.g. Interest on Sub-Trust A Investment Pool — March 2026" required>
        </div>
        <div class="field">
          <label>Gross amount (AUD)</label>
          <input type="number" name="gross_amount" min="0.01" step="0.01" placeholder="0.00" required
                 oninput="calcNet(this)">
        </div>
        <div class="field">
          <label>Withholding (AUD)<?php echo ops_admin_help_button('Withholding', 'TFN withholding deducted at source. Leave at 0.00 for most bank interest accounts.'); ?></label>
          <input type="number" name="withholding" min="0" step="0.01" value="0.00" id="withholding"
                 oninput="calcNet(this)">
        </div>
        <div class="field">
          <label>Net amount received (calculated)</label>
          <input type="text" id="net_display" value="$0.00" readonly
                 style="opacity:.6;cursor:default">
        </div>
        <div class="field">
          <label>CHESS holding ref<?php echo ops_admin_help_button('CHESS holding ref', 'Only required for ASX-related income. Leave blank for interest and most other income.'); ?></label>
          <input type="text" name="chess_holding_ref" placeholder="Optional — ASX only">
        </div>
        <div class="field">
          <label>RWA asset ref</label>
          <input type="text" name="rwa_asset_ref" placeholder="Optional — RWA yield only">
        </div>
        <div class="field field-full">
          <label>Notes</label>
          <textarea name="notes" placeholder="Optional notes"></textarea>
        </div>
        <div class="field-full" style="padding-top:4px">
          <button type="submit" class="btn btn-gold">Record income</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Income history -->
<div class="card">
  <div class="card-head"><h2>Income history</h2></div>
  <?php if (empty($allIncome)): ?>
    <div class="card-body"><p class="empty">No income recorded yet.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Ref</th>
            <th>Date</th>
            <th>Type</th>
            <th>Source</th>
            <th>Gross</th>
            <th>Withholding</th>
            <th>Net</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($allIncome as $r): ?>
          <tr>
            <td class="mono"><?php echo ti_h($r['income_ref']); ?></td>
            <td style="white-space:nowrap"><?php echo ti_h($r['income_date']); ?></td>
            <td><span class="type-pill type-<?php echo ti_h($r['income_type']); ?>"><?php echo ti_h(ti_type_label($r['income_type'])); ?></span></td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--sub)"><?php echo ti_h($r['source_description']); ?></td>
            <td style="font-weight:600"><?php echo ti_dollars((int)$r['gross_amount_cents']); ?></td>
            <td style="color:var(--warn)"><?php echo (int)$r['withholding_cents'] > 0 ? ti_dollars((int)$r['withholding_cents']) : '<span style="color:var(--dim)">—</span>'; ?></td>
            <td style="font-weight:600;color:var(--ok)"><?php echo ti_dollars((int)$r['net_amount_cents']); ?></td>
            <td style="color:var(--dim);font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo ti_h($r['notes'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

</main>
</div>

<script>
function calcNet(el) {
    var gross = parseFloat(document.querySelector('[name=gross_amount]').value) || 0;
    var with_ = parseFloat(document.getElementById('withholding').value) || 0;
    var net = Math.max(0, gross - with_);
    document.getElementById('net_display').value = '$' + net.toFixed(2);
}
</script>
</body>

<?php
$body = ob_get_clean();
ops_render_page('Trust Income', 'trust_income', $body, $error ?? $flash ?? null, $error ? 'error' : 'ok');
