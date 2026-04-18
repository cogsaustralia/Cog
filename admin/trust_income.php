<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo     = ops_db();
$adminId = ops_admin_id();

function ti_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$hasTable = function_exists('ops_has_table') && ops_has_table($pdo, 'trust_income');
$flash    = null;
$error    = null;

$incomeTypes = [
    'interest'    => 'Interest on accounts',
    'rwa_yield'   => 'RWA yield',
    'other'       => 'Other income',
];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTable) {
    admin_csrf_verify();
    try {
        $action = trim((string)($_POST['action'] ?? ''));

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

            // Generate ref: INC-YYYYMMDD-RANDOM
            $refDate  = str_replace('-', '', $incDate);
            $refRand  = strtoupper(substr(md5(uniqid('', true)), 0, 6));
            $incRef   = 'INC-' . $refDate . '-' . $refRand;

            $stmt = $pdo->prepare("
                INSERT INTO trust_income
                  (income_ref, income_type, source_description,
                   gross_amount_cents, franking_credits_cents, withholding_cents, net_amount_cents,
                   currency_code, income_date, chess_holding_ref, rwa_asset_ref,
                   trust_account_id, notes, created_by_admin_id)
                VALUES
                  (?, ?, ?, ?, 0, ?, ?, 'AUD', ?, ?, ?, 1, ?, ?)
            ");
            $stmt->execute([
                $incRef, $type, $desc,
                $grossCents, $withCents, $netCents,
                $incDate,
                $chessRef ?: null, $rwaRef ?: null,
                $notes ?: null, $adminId,
            ]);

            $newIncomeId = (int)$pdo->lastInsertId();

            // Godley ledger emission
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

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Load income records
$allIncome = [];
$totalGross = 0;
$totalNet   = 0;
$totalWith  = 0;
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

function ti_dollars(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

function ti_type_label(string $t): string {
    return match ($t) {
        'interest'    => 'Interest',
        'asx_dividend'=> 'ASX Dividend',
        'rwa_yield'   => 'RWA Yield',
        default       => ucwords(str_replace('_', ' ', $t)),
    };
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trust Income | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>
:root {
  --bg:#0c1319; --panel:#17212b; --panel2:#1f2c38;
  --text:#eef2f7; --sub:#9fb0c1; --dim:#6b7f8f;
  --line:rgba(255,255,255,.08); --line2:rgba(255,255,255,.14);
  --gold:#d4b25c; --ok:#52b87a; --okb:rgba(82,184,122,.12);
  --warn:#c8901a; --warnb:rgba(200,144,26,.12);
  --err:#c46060; --errb:rgba(196,96,96,.12);
  --blue:#5a9ed4; --purple:#9b7dd4; --r:18px; --r2:12px;
}
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:Inter,Arial,sans-serif; background:linear-gradient(160deg,var(--bg),#101b25 60%,var(--bg)); color:var(--text); min-height:100vh; }
a { color:inherit; text-decoration:none; }
.main { padding:24px 28px; min-width:0; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:26px; flex-wrap:wrap; }
.topbar h1 { font-size:1.9rem; font-weight:700; margin-bottom:6px; }
.topbar p { color:var(--sub); font-size:13px; }
.btn { display:inline-block; padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700; border:1px solid var(--line2); background:var(--panel2); color:var(--text); cursor:pointer; }
.btn-gold { background:var(--gold); color:#201507; border-color:rgba(212,178,92,.3); }

.card { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-bottom:18px; }
.card-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--line); }
.card-head h2 { font-size:1rem; font-weight:700; }
.card-body { padding:16px 20px; }

.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:22px; }
.stat { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r2); padding:16px 18px; text-align:center; }
.stat-val { font-size:1.5rem; font-weight:800; margin-bottom:4px; }
.stat-label { font-size:.72rem; color:var(--sub); text-transform:uppercase; letter-spacing:.06em; }

.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:700px) { .form-grid { grid-template-columns:1fr; } }
.field { display:flex; flex-direction:column; gap:5px; }
.field label { font-size:.82rem; color:var(--sub); }
.field input, .field select, .field textarea { background:#0f1720; border:1px solid var(--line); color:var(--text); padding:9px 11px; border-radius:10px; font:inherit; font-size:.9rem; }
.field textarea { min-height:60px; resize:vertical; }
.field-full { grid-column:1/-1; }

table { width:100%; border-collapse:collapse; }
th, td { text-align:left; padding:9px 10px; font-size:13px; border-top:1px solid var(--line); vertical-align:top; }
th { color:var(--dim); font-weight:600; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; border-top:none; }
.mono { font-family:monospace; font-size:12px; }
.type-pill { display:inline-block; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700; }
.type-interest { background:rgba(90,158,212,.12); color:var(--blue); }
.type-asx_dividend { background:rgba(212,178,92,.12); color:var(--gold); }
.type-rwa_yield { background:rgba(155,125,212,.12); color:var(--purple); }
.type-other { background:rgba(255,255,255,.04); color:var(--dim); }

.msg { padding:12px 14px; border-radius:12px; margin-bottom:16px; }
.msg.ok { background:rgba(47,143,87,.12); border:1px solid rgba(47,143,87,.35); color:#b8efc8; }
.msg.err { background:rgba(200,61,75,.12); border:1px solid rgba(200,61,75,.35); color:#ffb4be; }
.empty { color:var(--dim); font-size:13px; padding:20px 0; text-align:center; }
.notice { background:var(--warnb); border:1px solid rgba(200,144,26,.3); color:#e8cc80; border-radius:10px; padding:12px 16px; font-size:13px; margin-bottom:16px; }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('trust_income'); ?>
<main class="main">

<div class="topbar">
  <div>
    <h1>Trust income<?php echo ops_admin_help_button('Trust income', 'Use this page to record all income received by Sub-Trust A — interest, RWA yield, and other receipts. Each entry automatically emits Godley ledger entries to Sub-Trust A Operating and Partners Pool accounts.'); ?></h1>
    <p>Record interest, RWA yield, and other income received by Sub-Trust A. ASX dividends are recorded through the dividend workflow.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn" href="<?php echo ti_h(admin_url('expenses.php')); ?>">Expenses</a>
    <a class="btn" href="<?php echo ti_h(admin_url('accounting.php')); ?>">Accounting</a>
    <a class="btn" href="<?php echo ti_h(admin_url('dashboard.php')); ?>">Dashboard</a>
  </div>
</div>

<?php echo ops_admin_info_panel(
    'Trust income recording',
    'What this page does',
    'Use this page to record income received by Sub-Trust A. Each entry writes to trust_income and automatically emits the matching Godley ledger entries — debiting STA-OPERATING for cash received and crediting STA-PARTNERS-POOL as retained pool equity.',
    [
        'Record interest income from bank or investment accounts.',
        'Record RWA yield payments when received.',
        'Record any other income that does not come through the ASX dividend workflow.',
        'ASX dividends trigger the BDS/DDS split workflow separately — do not record them here.',
    ]
); ?>

<?php echo ops_admin_workflow_panel(
    'Typical workflow',
    'Record income promptly so the Partners Pool balance stays accurate.',
    [
        ['title' => 'Select the income type', 'body' => 'Choose Interest, RWA Yield, or Other to determine which Godley emission path fires.'],
        ['title' => 'Enter gross amount and any withholding', 'body' => 'Net amount is calculated automatically. Most bank interest will have zero withholding.'],
        ['title' => 'Set the income date', 'body' => 'Use the actual date the income was received or credited, not today\'s date.'],
        ['title' => 'Check the Accounting page', 'body' => 'After recording, visit Accounting to confirm the Godley matrix reflects the new Partners Pool balance.'],
    ]
); ?>

<?php echo ops_admin_guide_panel(
    'Income types',
    'Each type routes to a different ledger flow.',
    [
        ['title' => 'Interest', 'body' => 'Bank or investment account interest. Debits STA-OPERATING, credits STA-PARTNERS-POOL.'],
        ['title' => 'RWA Yield', 'body' => 'Yield from real-world asset holdings. Same double-entry as interest.'],
        ['title' => 'Other', 'body' => 'Any other income into Sub-Trust A not covered by the above categories.'],
        ['title' => 'ASX Dividends', 'body' => 'Not recorded here. ASX dividends trigger the BDS/DDS split workflow which distributes across Sub-Trusts A and B.'],
    ]
); ?>

<div class="notice">
  ⚠ <strong>ASX dividends are not recorded here.</strong> They trigger the BDS / DDS split workflow and are entered through the dividend management pathway.
</div>

<?php if (!$hasTable): ?>
  <div class="msg err">trust_income table not found. Run the accounting schema SQL first.</div>
<?php else: ?>

<?php if ($flash): ?><div class="msg ok"><?php echo ti_h($flash); ?></div><?php endif; ?>
<?php if ($error): ?><div class="msg err"><?php echo ti_h($error); ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><div class="stat-val" style="color:var(--ok)"><?php echo ti_dollars($totalGross); ?></div><div class="stat-label">Total gross received</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--blue)"><?php echo ti_dollars($totalNet); ?></div><div class="stat-label">Total net received</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--warn)"><?php echo ti_dollars($totalWith); ?></div><div class="stat-label">Total withholding</div></div>
  <div class="stat"><div class="stat-val" style="color:var(--text)"><?php echo count($allIncome); ?></div><div class="stat-label">Total entries</div></div>
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
</html>
